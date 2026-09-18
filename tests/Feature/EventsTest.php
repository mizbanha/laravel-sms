<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mizbanha\Sms\Enums\DeliveryMode;
use Mizbanha\Sms\Enums\DeliveryStatus;
use Mizbanha\Sms\Enums\FailureKind;
use Mizbanha\Sms\Enums\MessageStatus;
use Mizbanha\Sms\Enums\SendOutcome;
use Mizbanha\Sms\Events\AttemptRecorded;
use Mizbanha\Sms\Events\CircuitStateChanged;
use Mizbanha\Sms\Events\DeliveryChecked;
use Mizbanha\Sms\Events\MessageSettled;
use Mizbanha\Sms\Facades\Sms;
use Mizbanha\Sms\Health\CircuitBreaker;
use Mizbanha\Sms\Health\CircuitState;
use Mizbanha\Sms\Jobs\SendSmsMessage;
use Mizbanha\Sms\Models\SmsGateway;
use Mizbanha\Sms\Models\SmsTemplate;
use Mizbanha\Sms\Models\SmsTemplateGateway;
use Mizbanha\Sms\Results\SendResult;
use Mizbanha\Sms\Sending\MessageDispatcher;

/**
 * The events Core announces, and the one property that matters more than any of
 * them: nothing a listener does can change a send.
 *
 * ⚠️ These events exist so that observation - a dashboard, a metrics exporter,
 * SMS Cloud - needs no code inside Core. They are only acceptable because they
 * are inert: every test at the bottom of this file installs a listener that
 * throws and proves the send it observed ended exactly as it would have with
 * nobody listening.
 */

/**
 * @param  list<array{0: string, 1: string}>  $gateways  [driver, key], priority ascending
 */
function eventsChain(array $gateways): SmsTemplate
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
                'api_key' => 'provider-key',
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

function eventsSend(string $to = '09121234567')
{
    return Sms::to($to)->template('order-created')->with(['customer_name' => 'Amid'])->send();
}

/** Every listener this test installs throws. */
function throwingListeners(): void
{
    foreach ([AttemptRecorded::class, MessageSettled::class, CircuitStateChanged::class, DeliveryChecked::class] as $event) {
        Event::listen($event, function (): never {
            throw new RuntimeException('listener exploded with +989121234567 in the message');
        });
    }
}

it('announces an accepted attempt with its duration and then the settled message', function () {
    Event::fake([AttemptRecorded::class, MessageSettled::class]);
    eventsChain([['smsir', 'primary']]);
    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [42]]])]);

    $message = eventsSend();

    Event::assertDispatchedTimes(AttemptRecorded::class, 1);
    Event::assertDispatched(AttemptRecorded::class, function (AttemptRecorded $event) use ($message): bool {
        return $event->message->is($message)
            && $event->attempt->outcome === SendOutcome::Accepted
            && $event->attempt->gateway_key === 'primary'
            && $event->durationMs >= 0
            && $event->previous === null
            && ! $event->isFailover()
            && ! $event->isRetry();
    });

    Event::assertDispatchedTimes(MessageSettled::class, 1);
    Event::assertDispatched(MessageSettled::class, fn (MessageSettled $event): bool => $event->status === MessageStatus::Accepted
        && $event->previous === MessageStatus::Sending
        && $event->message->is($message));
});

it('marks the second attempt of a failover walk with the attempt it failed over from', function () {
    Event::fake([AttemptRecorded::class]);
    eventsChain([['smsir', 'first'], ['kavenegar', 'second']]);
    Http::fake([
        'api.sms.ir/*' => Http::response(['message' => 'unauthorized'], 401),
        'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 77]]]),
    ]);

    eventsSend();

    $events = Event::dispatched(AttemptRecorded::class)->map(fn (array $args) => $args[0])->values();

    expect($events)->toHaveCount(2)
        ->and($events[0]->attempt->gateway_key)->toBe('first')
        ->and($events[0]->attempt->failure_kind)->toBe(FailureKind::GatewayConfiguration)
        ->and($events[0]->isFailover())->toBeFalse()
        ->and($events[1]->attempt->gateway_key)->toBe('second')
        ->and($events[1]->isFailover())->toBeTrue()
        ->and($events[1]->previous->is($events[0]->attempt))->toBeTrue()
        ->and($events[1]->attempt->outcome)->toBe(SendOutcome::Accepted);
});

it('announces an uncertain end as an unknown settlement', function () {
    Event::fake([MessageSettled::class]);
    eventsChain([['smsir', 'primary']]);
    Http::fake(['*' => Http::response('upstream exploded', 500)]);

    eventsSend();

    Event::assertDispatched(MessageSettled::class, fn (MessageSettled $event): bool => $event->status === MessageStatus::Unknown);
});

it('announces a suppressed message as settled with no previous state', function () {
    config()->set('laravel-sms.enabled', false);
    Event::fake([MessageSettled::class, AttemptRecorded::class]);
    eventsChain([['smsir', 'primary']]);

    eventsSend();

    Event::assertNotDispatched(AttemptRecorded::class);
    Event::assertDispatched(MessageSettled::class, fn (MessageSettled $event): bool => $event->status === MessageStatus::Suppressed
        && $event->previous === null);
});

it('does not announce a settled message a second time', function () {
    Event::fake([MessageSettled::class]);
    eventsChain([['smsir', 'primary']]);
    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [42]]])]);

    $message = eventsSend();
    $message->transitionTo(MessageStatus::Accepted);

    Event::assertDispatchedTimes(MessageSettled::class, 1);
});

it('tells a queue retry apart from a failover', function () {
    Event::fake([AttemptRecorded::class]);
    eventsChain([['smsir', 'primary']]);
    Queue::fake();

    Http::fakeSequence()
        ->push(['message' => 'slow down'], 429)
        ->push(['status' => 1, 'data' => ['messageIds' => [42]]]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->queue();

    foreach ([1, 2] as $run) {
        $job = new SendSmsMessage($message->getKey(), ['customer_name' => 'Amid']);
        $underlying = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $underlying->shouldReceive('attempts')->andReturn($run);
        $underlying->shouldReceive('release')->andReturnNull();
        $underlying->shouldReceive('hasFailed')->andReturn(false);
        $underlying->shouldReceive('isReleased')->andReturn(false);
        $underlying->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $underlying->shouldReceive('getJobId')->andReturn((string) $message->getKey());
        $job->setJob($underlying);
        $job->handle(app(MessageDispatcher::class));
    }

    $events = Event::dispatched(AttemptRecorded::class)->map(fn (array $args) => $args[0])->values();

    expect($events)->toHaveCount(2)
        ->and($events[0]->isRetry())->toBeFalse()
        ->and($events[1]->isRetry())->toBeTrue()
        ->and($events[1]->isFailover())->toBeFalse()
        ->and((int) $events[1]->attempt->sequence)->toBe(2);
});

it('announces every circuit transition with its reason', function () {
    Event::fake([CircuitStateChanged::class]);
    [$gateway] = $this->configureGateway(driver: 'smsir');
    $breaker = app(CircuitBreaker::class);
    $failure = SendResult::uncertain(FailureKind::Network, 'unreachable');

    foreach (range(1, 3) as $ignored) {
        $breaker->record($gateway, $failure);
    }

    $this->travel(61)->seconds();
    expect($breaker->allows($gateway))->toBeTrue();
    $breaker->record($gateway, $failure);

    $this->travel(61)->seconds();
    expect($breaker->allows($gateway))->toBeTrue();
    $breaker->record($gateway, SendResult::accepted('id-1'));

    $transitions = Event::dispatched(CircuitStateChanged::class)
        ->map(fn (array $args) => [$args[0]->from, $args[0]->to, $args[0]->reason, $args[0]->failures])
        ->values()
        ->all();

    expect($transitions)->toBe([
        [CircuitState::Closed, CircuitState::Open, CircuitStateChanged::REASON_FAILURE_THRESHOLD, 3],
        [CircuitState::Open, CircuitState::HalfOpen, CircuitStateChanged::REASON_COOLDOWN_ELAPSED, 0],
        [CircuitState::HalfOpen, CircuitState::Open, CircuitStateChanged::REASON_PROBE_FAILED, 1],
        [CircuitState::Open, CircuitState::HalfOpen, CircuitStateChanged::REASON_COOLDOWN_ELAPSED, 0],
        [CircuitState::HalfOpen, CircuitState::Closed, CircuitStateChanged::REASON_PROBE_SUCCEEDED, 0],
    ]);

    $opening = Event::dispatched(CircuitStateChanged::class)->first()[0];
    expect($opening->openUntil)->not->toBeNull()
        ->and($opening->gateway->is($gateway))->toBeTrue();
});

it('announces a reset only when the circuit was not already closed', function () {
    Event::fake([CircuitStateChanged::class]);
    [$gateway] = $this->configureGateway(driver: 'smsir');
    $breaker = app(CircuitBreaker::class);

    $breaker->reset($gateway);
    Event::assertNotDispatched(CircuitStateChanged::class);

    foreach (range(1, 3) as $ignored) {
        $breaker->record($gateway, SendResult::uncertain(FailureKind::Network, 'unreachable'));
    }
    $breaker->reset($gateway);

    Event::assertDispatched(CircuitStateChanged::class, fn (CircuitStateChanged $event): bool => $event->from === CircuitState::Open
        && $event->to === CircuitState::Closed
        && $event->reason === CircuitStateChanged::REASON_RESET);
});

it('does not announce anything for neutral failures that never touch health', function () {
    Event::fake([CircuitStateChanged::class]);
    [$gateway] = $this->configureGateway(driver: 'smsir');

    foreach (range(1, 5) as $ignored) {
        app(CircuitBreaker::class)->record($gateway, SendResult::rejected(FailureKind::InvalidRecipient, 'bad number'));
    }

    Event::assertNotDispatched(CircuitStateChanged::class);
});

it('announces a successful delivery lookup with the verdict it moved to', function () {
    eventsChain([['twilio', 'primary']]);
    Http::fake([
        'api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201),
        'api.twilio.com/2010-04-01/Accounts/*/Messages/*.json' => Http::response(['sid' => 'SM1', 'status' => 'delivered']),
    ]);
    $message = eventsSend('+14155552671');

    Event::fake([DeliveryChecked::class]);
    Sms::refreshDelivery($message);

    Event::assertDispatched(DeliveryChecked::class, fn (DeliveryChecked $event): bool => $event->succeeded()
        && $event->previous === DeliveryStatus::Pending
        && $event->current() === DeliveryStatus::Delivered
        && $event->changed());
});

it('announces a failed delivery lookup as a lookup that learned nothing', function () {
    eventsChain([['twilio', 'primary']]);
    Http::fake([
        'api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201),
        'api.twilio.com/2010-04-01/Accounts/*/Messages/*.json' => Http::response(['message' => 'Authenticate'], 401),
    ]);
    $message = eventsSend('+14155552671');

    Event::fake([DeliveryChecked::class]);
    Sms::refreshDelivery($message);

    Event::assertDispatched(DeliveryChecked::class, fn (DeliveryChecked $event): bool => ! $event->succeeded()
        && ! $event->changed()
        && $event->current() === DeliveryStatus::Pending);
});

it('does not announce a lookup that was never made', function () {
    Event::fake([DeliveryChecked::class]);
    eventsChain([['smsir', 'primary']]);
    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [42]]])]);

    Sms::refreshDelivery(eventsSend());

    Event::assertNotDispatched(DeliveryChecked::class);
});

it('sends exactly as it would with nobody listening when every listener throws', function () {
    throwingListeners();
    Log::spy();
    eventsChain([['smsir', 'first'], ['kavenegar', 'second']]);
    Http::fake([
        'api.sms.ir/*' => Http::response(['message' => 'unauthorized'], 401),
        'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 77]]]),
    ]);

    $message = eventsSend();

    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and($message->attempts()->count())->toBe(2);

    // Named by class only: the listener's own message quoted a phone number.
    Log::shouldHaveReceived('warning')->withArgs(function (string $line, array $context): bool {
        return str_contains($line, 'event listener threw')
            && $context['exception'] === RuntimeException::class
            && ! str_contains(json_encode($context), '989121234567');
    })->atLeast()->once();
});

it('keeps the circuit breaker working when its listeners throw', function () {
    throwingListeners();
    [$gateway] = $this->configureGateway(driver: 'smsir');
    $breaker = app(CircuitBreaker::class);

    foreach (range(1, 3) as $ignored) {
        $breaker->record($gateway, SendResult::uncertain(FailureKind::Network, 'unreachable'));
    }

    expect($breaker->status($gateway)->state)->toBe(CircuitState::Open);

    $this->travel(61)->seconds();
    expect($breaker->allows($gateway))->toBeTrue();
    $breaker->record($gateway, SendResult::accepted('id-1'));

    expect($breaker->status($gateway)->state)->toBe(CircuitState::Closed);
});

it('keeps a queued send and its retry decision intact when listeners throw', function () {
    throwingListeners();
    eventsChain([['smsir', 'primary']]);
    Queue::fake();
    Http::fake(['*' => Http::response(['message' => 'slow down'], 429)]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->queue();

    $job = new SendSmsMessage($message->getKey(), ['customer_name' => 'Amid']);
    $underlying = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $underlying->shouldReceive('attempts')->andReturn(1);
    $underlying->shouldReceive('release')->once()->andReturnNull();
    $underlying->shouldReceive('hasFailed')->andReturn(false);
    $underlying->shouldReceive('isReleased')->andReturn(false);
    $underlying->shouldReceive('isDeletedOrReleased')->andReturn(false);
    $underlying->shouldReceive('getJobId')->andReturn((string) $message->getKey());
    $job->setJob($underlying);
    $job->handle(app(MessageDispatcher::class));

    expect($message->fresh()->status)->toBe(MessageStatus::Sending);
});

it('keeps delivery tracking silent-on-failure when its listeners throw', function () {
    throwingListeners();
    eventsChain([['twilio', 'primary']]);
    Http::fake([
        'api.twilio.com/2010-04-01/Accounts/*/Messages.json' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201),
        'api.twilio.com/2010-04-01/Accounts/*/Messages/*.json' => Http::response(['sid' => 'SM1', 'status' => 'delivered']),
    ]);
    $message = eventsSend('+14155552671');

    $result = Sms::refreshDelivery($message);

    expect($result->status)->toBe(DeliveryStatus::Delivered)
        ->and($message->fresh()->delivery_status)->toBe(DeliveryStatus::Delivered);
});

it('keeps a suppressed message suppressed when its listener throws', function () {
    throwingListeners();
    config()->set('laravel-sms.enabled', false);
    eventsChain([['smsir', 'primary']]);

    expect(eventsSend()->status)->toBe(MessageStatus::Suppressed);
});
