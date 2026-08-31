<?php

declare(strict_types=1);

use Amid\Sms\Enums\DeliveryMode;
use Amid\Sms\Enums\MessageStatus;
use Amid\Sms\Facades\Sms;
use Amid\Sms\Health\CircuitBreaker;
use Amid\Sms\Health\CircuitState;
use Amid\Sms\Jobs\SendSmsMessage;
use Amid\Sms\Models\SmsGateway;
use Amid\Sms\Models\SmsTemplate;
use Amid\Sms\Models\SmsTemplateGateway;
use Amid\Sms\Sending\MessageDispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * The circuit breaker where it actually runs: inside one send.
 *
 * ⚠️ The most important test in this file is the one that proves the breaker
 * CANNOT rescue the message that tripped it. An uncertain result means the
 * provider may already have the message; opening a circuit and then continuing
 * that same message to another gateway would be exactly the duplicate send this
 * package has been built to prevent since M2. The breaker's evidence is for the
 * NEXT message and nothing else.
 */

/**
 * A template bound to the given [driver, key] gateways, priority ascending.
 *
 * @param  list<array{0: string, 1: string}>  $gateways
 */
function circuitChain(array $gateways): SmsTemplate
{
    $template = SmsTemplate::query()->create([
        'key' => 'order-created',
        'name' => 'Order created',
        'body' => 'Hello {customer_name}',
    ]);

    foreach ($gateways as $index => [$driver, $key]) {
        $gateway = new SmsGateway;
        $gateway->forceFill([
            'key' => $key,
            'label' => $key,
            'driver' => $driver,
            'sender' => '+15005550006',
            'credentials' => [
                'api_key' => 'a-gateway-key',
                'account_sid' => 'ACtest0000000000000000000000000001',
                'auth_token' => 'twilio-secret-token',
            ],
            'is_enabled' => true,
            'priority' => ($index + 1) * 10,
        ])->save();

        SmsTemplateGateway::query()->create([
            'sms_template_id' => $template->getKey(),
            'sms_gateway_id' => $gateway->getKey(),
            'mode' => DeliveryMode::Text,
            'is_enabled' => true,
        ]);
    }

    return $template;
}

function sendOne(string $to = '09121234567')
{
    return Sms::to($to)->template('order-created')->with(['customer_name' => 'Amid'])->send();
}

function gateway(string $key): SmsGateway
{
    return SmsGateway::query()->where('key', $key)->firstOrFail();
}

/** Take a gateway's circuit out of service the way the pipeline would. */
function openCircuitFor(string $key): void
{
    foreach (range(1, 3) as $ignored) {
        app(CircuitBreaker::class)->record(
            gateway($key),
            \Amid\Sms\Results\SendResult::uncertain(\Amid\Sms\Enums\FailureKind::Network, 'unreachable'),
        );
    }
}

/** Drive the job the way the queue would, with a fake underlying job. */
function runCircuitJob(int $messageId, int $attempts = 1): void
{
    $job = new SendSmsMessage($messageId, ['customer_name' => 'Amid']);

    $underlying = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $underlying->shouldReceive('attempts')->andReturn($attempts);
    $underlying->shouldReceive('release')->andReturnNull();
    $underlying->shouldReceive('hasFailed')->andReturn(false);
    $underlying->shouldReceive('isReleased')->andReturn(false);
    $underlying->shouldReceive('isDeletedOrReleased')->andReturn(false);
    $underlying->shouldReceive('getJobId')->andReturn((string) $messageId);

    $job->setJob($underlying);
    $job->handle(app(MessageDispatcher::class));
}

/*
|--------------------------------------------------------------------------
| Skipping
|--------------------------------------------------------------------------
*/

it('never contacts an open gateway, and the fallback becomes sequence 1', function () {
    /*
     * ⚠️ Routing, not failure. The skipped gateway is not called, records no
     * attempt and produces no provider error - an attempt row is evidence about a
     * PROVIDER, and inventing a rejection nobody made would put fiction into the
     * one table that has to be trusted during an incident. Nor does it consume a
     * sequence number: the fallback is attempt 1.
     */
    circuitChain([['kavenegar', 'first'], ['smsir', 'second']]);
    openCircuitFor('first');

    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [7]]])]);

    $message = sendOne();
    $attempt = $message->attempts()->first();

    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and($message->attempts)->toHaveCount(1)
        ->and($attempt->sequence)->toBe(1)
        ->and($attempt->gateway_key)->toBe('second');

    // Exactly one request, and not to the open gateway.
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'kavenegar'));
});

it('keeps priority order among the gateways that are still available', function () {
    circuitChain([['kavenegar', 'first'], ['smsir', 'second'], ['ippanel', 'third']]);
    openCircuitFor('first');

    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [7]]])]);

    // Second, not third: opening one circuit reorders nothing.
    expect(sendOne()->attempts()->first()->gateway_key)->toBe('second');
});

/*
|--------------------------------------------------------------------------
| The uncertainty rule
|--------------------------------------------------------------------------
*/

it('never lets a tripped circuit rescue the message that tripped it', function () {
    /*
     * ⚠️ **The highest-value test in this milestone.**
     *
     * A gateway times out three times. The third message is the one that opens the
     * circuit — and that message must still stop as `unknown`, with the healthy
     * fallback never contacted, because a timeout may mean the provider already
     * has it. Only the FOURTH message, a new logical message, gets to benefit.
     */
    circuitChain([['kavenegar', 'first'], ['smsir', 'second']]);

    Http::fake([
        'api.kavenegar.com/*' => fn () => throw new ConnectionException('timed out'),
        'api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageIds' => [7]]]),
    ]);

    $messages = [sendOne(), sendOne(), sendOne()];

    foreach ($messages as $message) {
        expect($message->status)->toBe(MessageStatus::Unknown)
            // ⚠️ One attempt each. The chain stopped at the uncertain gateway and
            // the healthy one behind it was never asked - including on the send
            // that opened the circuit.
            ->and($message->attempts)->toHaveCount(1)
            ->and($message->attempts()->first()->gateway_key)->toBe('first');
    }

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'sms.ir'));

    // The circuit is now open, from those three timeouts.
    expect(app(CircuitBreaker::class)->status(gateway('first'))->state)->toBe(CircuitState::Open);

    // And a NEW logical message goes straight to the fallback.
    $next = sendOne();

    expect($next->status)->toBe(MessageStatus::Accepted)
        ->and($next->attempts)->toHaveCount(1)
        ->and($next->attempts()->first()->gateway_key)->toBe('second');
});

/*
|--------------------------------------------------------------------------
| What never reaches the circuit layer at all
|--------------------------------------------------------------------------
*/

it('never consults the circuit for a country-ineligible gateway', function () {
    /*
     * The router filters on static eligibility before the dispatcher exists. Proven
     * by leaving the gateway half-open and owed a probe: if it had reached the
     * circuit layer, that probe would have been taken.
     */
    circuitChain([['twilio', 'first'], ['smsir', 'second']]);
    gateway('first')->forceFill(['country_policy' => 'deny', 'countries' => ['IR']])->save();

    openCircuitFor('first');
    test()->travel(61)->seconds();
    expect(app(CircuitBreaker::class)->status(gateway('first'))->state)->toBe(CircuitState::HalfOpen);

    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [7]]])]);

    expect(sendOne('09121234567')->attempts()->first()->gateway_key)->toBe('second')
        // Still owed a probe: the circuit layer was never asked about this gateway.
        ->and(app(CircuitBreaker::class)->allows(gateway('first')))->toBeTrue();
});

it('never consults the circuit for a disabled gateway', function () {
    circuitChain([['kavenegar', 'first'], ['smsir', 'second']]);
    gateway('first')->forceFill(['is_enabled' => false])->save();

    openCircuitFor('first');
    test()->travel(61)->seconds();

    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [7]]])]);

    expect(sendOne()->attempts()->first()->gateway_key)->toBe('second')
        ->and(app(CircuitBreaker::class)->allows(gateway('first')))->toBeTrue();
});

it('records no circuit evidence for a suppressed message', function () {
    // ⚠️ The master switch means no provider call happened, so there is nothing to
    // observe. Suppression is neutral, not a failure.
    config()->set('sms.enabled', false);
    circuitChain([['kavenegar', 'first']]);

    $message = sendOne();

    expect($message->status)->toBe(MessageStatus::Suppressed)
        ->and(app(CircuitBreaker::class)->status(gateway('first'))->state)->toBe(CircuitState::Closed)
        ->and(app(CircuitBreaker::class)->status(gateway('first'))->failures)->toBe(0);

    Http::assertNothingSent();
});

it('never opens the circuit of the local log driver', function () {
    // It has no external transport, so it cannot produce transport evidence. It
    // always accepts, which keeps it exactly as deterministic as every environment
    // built on it needs it to be.
    circuitChain([['log', 'first']]);

    foreach (range(1, 5) as $ignored) {
        sendOne();
    }

    expect(app(CircuitBreaker::class)->status(gateway('first'))->state)->toBe(CircuitState::Closed);
});

it('never lets a delivery failure affect the circuit', function () {
    /*
     * ⚠️ A handset that was switched off, or a carrier that declined the message,
     * says nothing whatever about whether this gateway can be reached. Wiring
     * delivery results into health would take a perfectly good gateway out of
     * service because somebody's phone was off.
     */
    circuitChain([['twilio', 'first']]);

    Http::fake([
        'api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201),
        'api.twilio.com/2010-04-01/Accounts/*/Messages/*.json' => Http::response(['sid' => 'SM1', 'status' => 'undelivered', 'error_code' => 30006]),
    ]);

    $message = Sms::to('+14155552671')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    foreach (range(1, 3) as $ignored) {
        Sms::refreshDelivery($message->fresh());
    }

    expect($message->fresh()->delivery_status->value)->toBe('failed')
        ->and(app(CircuitBreaker::class)->status(gateway('first'))->state)->toBe(CircuitState::Closed);
});

/*
|--------------------------------------------------------------------------
| Every candidate open
|--------------------------------------------------------------------------
*/

it('fails a synchronous send immediately when nothing can be called', function () {
    /*
     * ⚠️ No request, no attempt row, no secret queueing. A synchronous send has
     * nowhere to retry from, so it settles now - and the reason is written in our
     * own words, because no provider said anything.
     */
    circuitChain([['kavenegar', 'first'], ['smsir', 'second']]);
    openCircuitFor('first');
    openCircuitFor('second');

    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [7]]])]);

    $message = sendOne();

    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->attempts)->toHaveCount(0)
        ->and($message->error)->toContain('temporarily unavailable');

    Http::assertNothingSent();
});

it('leaves a queued message unsettled while every circuit is open', function () {
    /*
     * An open circuit is temporary by definition, so a queued message is not failed
     * for it: the job that already owns retry comes back later. No second retry
     * subsystem, no delayed job aimed at the cooldown expiry, no scheduler.
     */
    circuitChain([['kavenegar', 'first'], ['smsir', 'second']]);
    openCircuitFor('first');
    openCircuitFor('second');

    Queue::fake();
    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [7]]])]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->queue();

    runCircuitJob($message->getKey(), attempts: 1);

    expect($message->fresh()->status)->toBe(MessageStatus::Sending)
        ->and($message->fresh()->isSettled())->toBeFalse()
        ->and($message->fresh()->attempts)->toHaveCount(0);

    Http::assertNothingSent();
});

it('sends normally on a later run once the circuit has recovered', function () {
    /*
     * One gateway, so what is under test is the recovery and nothing else: the job
     * comes back after the cooldown, the gateway is owed a probe, the probe becomes
     * the send.
     */
    circuitChain([['smsir', 'only']]);
    openCircuitFor('only');

    Queue::fake();
    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [7]]])]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->queue();

    runCircuitJob($message->getKey(), attempts: 1);

    expect($message->fresh()->status)->toBe(MessageStatus::Sending)
        ->and($message->fresh()->attempts)->toHaveCount(0);
    Http::assertNothingSent();

    // The cooldown passes; this message becomes the probe, and the probe succeeds.
    test()->travel(61)->seconds();
    runCircuitJob($message->getKey(), attempts: 2);

    expect($message->fresh()->status)->toBe(MessageStatus::Accepted)
        ->and($message->fresh()->attempts)->toHaveCount(1)
        ->and($message->fresh()->attempts()->first()->sequence)->toBe(1)
        // A successful send closes the circuit outright.
        ->and(app(CircuitBreaker::class)->status(gateway('only'))->state)->toBe(CircuitState::Closed);
});

it('settles failed when the last allowed attempt still has nothing to call', function () {
    circuitChain([['kavenegar', 'first'], ['smsir', 'second']]);
    openCircuitFor('first');
    openCircuitFor('second');

    Queue::fake();
    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [7]]])]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->queue();

    // tries defaults to 3, so this run has no future.
    runCircuitJob($message->getKey(), attempts: 3);

    expect($message->fresh()->status)->toBe(MessageStatus::Failed)
        ->and($message->fresh()->attempts)->toHaveCount(0)
        ->and($message->fresh()->error)->toContain('temporarily unavailable');

    Http::assertNothingSent();
});
