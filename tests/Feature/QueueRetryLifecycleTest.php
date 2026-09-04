<?php

declare(strict_types=1);

use Mizbanha\Sms\Enums\DeliveryMode;
use Mizbanha\Sms\Enums\MessageStatus;
use Mizbanha\Sms\Facades\Sms;
use Mizbanha\Sms\Jobs\SendSmsMessage;
use Mizbanha\Sms\Models\SmsGateway;
use Mizbanha\Sms\Sending\MessageDispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * The queue half of the retry story: what happens between one job run and the next.
 *
 * These tests drive the job object directly with a fake job instance rather than
 * standing up a worker process. That is deliberate — a real worker would make the
 * suite slow and flaky while testing Laravel rather than this package — but it does
 * mean what is verified is the job's own decisions: whether it releases, whether it
 * calls a provider, and what it does with the attempt history it finds. The
 * transport that carries a released job back is Laravel's, and is not re-tested here.
 */
function queuedFor(string $driver = 'smsir', DeliveryMode $mode = DeliveryMode::Text)
{
    Queue::fake();

    return Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->queue();
}

/**
 * Run the job the way the queue would, with a fake underlying job so that
 * release() and attempts() behave.
 */
function runJob(int $messageId, int $attempts = 1): SendSmsMessage
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

    return $job;
}

beforeEach(function () {
    $this->configureGateway(driver: 'smsir', mode: DeliveryMode::Text, body: 'Hello {customer_name}');
});

it('leaves a retryable message unsettled so a later attempt can resume it', function () {
    // 429 is the one refusal the provider tells us was definitely not processed and
    // is worth trying again on the same gateway.
    Http::fake(['*' => Http::response(['message' => 'slow down'], 429)]);

    $message = queuedFor();
    runJob($message->getKey(), attempts: 1);

    expect($message->fresh()->status)->toBe(MessageStatus::Sending)
        ->and($message->fresh()->isSettled())->toBeFalse()
        ->and($message->fresh()->attempts)->toHaveCount(1);
});

it('resumes on the next attempt and succeeds', function () {
    // A sequence rather than two fakes: the gateway is rate limited on the first
    // call and healthy on the second, which is the whole scenario.
    Http::fakeSequence()
        ->push(['message' => 'slow down'], 429)
        ->push(['status' => 1, 'data' => ['messageIds' => [99]]], 200);

    $message = queuedFor();

    runJob($message->getKey(), attempts: 1);
    runJob($message->getKey(), attempts: 2);

    $message->refresh();

    expect($message->status)->toBe(MessageStatus::Accepted)
        // Both handovers are recorded, in order.
        ->and($message->attempts()->orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2])
        ->and($message->attempts()->orderBy('sequence')->get()[1]->provider_message_id)->toBe('99');
});

it('does not call a gateway again whose previous refusal was definitive', function () {
    /*
     * The rule that makes a resumed job safe. A gateway that gave a definitive
     * answer is spent; Laravel restarting the job is not a reason to ask it again.
     */
    Http::fake(['*' => Http::response(['status' => 0, 'message' => 'refused'])]);

    $message = queuedFor();
    runJob($message->getKey(), attempts: 1);

    expect($message->fresh()->status)->toBe(MessageStatus::Failed);

    // Force it back to unsettled, as though something had resumed it, and run again.
    $message->forceFill(['status' => MessageStatus::Sending])->save();
    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [1]]])]);
    runJob($message->getKey(), attempts: 2);

    $message->refresh();

    // The spent gateway was not called a second time; with nothing else eligible,
    // the message fails rather than quietly going out through a gateway that had
    // already refused it.
    expect($message->attempts)->toHaveCount(1)
        ->and($message->status)->toBe(MessageStatus::Failed)
        ->and($message->error)->toContain('No eligible gateway');
    Http::assertNothingSent();
});

it('considers a gateway that was enabled after the previous run', function () {
    // New capacity added while a message was waiting is legitimately eligible: it
    // has no history to exclude it.
    // Call 1: the original gateway is rate limited. Call 2 (next run): still rate
    // limited. Call 3: the gateway added in between accepts it.
    Http::fakeSequence()
        ->push(['message' => 'slow down'], 429)
        ->push(['message' => 'slow down'], 429)
        ->push(['return' => ['status' => 200], 'entries' => [['messageid' => 8]]], 200);

    $message = queuedFor();
    runJob($message->getKey(), attempts: 1);

    $second = new SmsGateway;
    $second->forceFill([
        'key' => 'standby',
        'label' => 'Standby',
        'driver' => 'kavenegar',
        'sender' => '3000',
        'credentials' => ['api_key' => 'k'],
        'is_enabled' => true,
        'priority' => 200,
    ])->save();

    \Mizbanha\Sms\Models\SmsTemplateGateway::query()->create([
        'sms_template_id' => $message->sms_template_id,
        'sms_gateway_id' => $second->getKey(),
        'mode' => DeliveryMode::Text,
        'is_enabled' => true,
    ]);

    runJob($message->getKey(), attempts: 2);

    $message->refresh();

    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and($message->attempts()->orderBy('sequence')->pluck('gateway_key')->all())
        ->toBe(['primary', 'primary', 'standby']);
});

it('does nothing when the job runs again for an accepted message', function () {
    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [1]]])]);

    $message = queuedFor();
    runJob($message->getKey(), attempts: 1);

    expect($message->fresh()->status)->toBe(MessageStatus::Accepted);

    runJob($message->getKey(), attempts: 2);

    Http::assertSentCount(1);
    expect($message->fresh()->attempts)->toHaveCount(1);
});

it('does nothing when the job runs again for an uncertain message', function () {
    // The provider may already have delivered it. A restarted job must not be a
    // second delivery.
    Http::fake(['*' => Http::response('boom', 500)]);

    $message = queuedFor();
    runJob($message->getKey(), attempts: 1);

    expect($message->fresh()->status)->toBe(MessageStatus::Unknown);

    runJob($message->getKey(), attempts: 2);

    Http::assertSentCount(1);
    expect($message->fresh()->attempts)->toHaveCount(1);
});

it('sends nothing while another worker holds the message lock', function () {
    // Two workers can hold the same job at once — after a visibility timeout, or a
    // job dispatched twice — and both would read "not settled" before either wrote.
    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [1]]])]);

    $message = queuedFor();

    // Someone else is mid-chain with this message.
    $held = Cache::lock('sms:message:'.$message->getKey(), 120);
    expect($held->get())->toBeTrue();

    runJob($message->getKey(), attempts: 1);

    Http::assertNothingSent();
    expect($message->fresh()->attempts)->toHaveCount(0)
        // Untouched, and still eligible once the other worker is done.
        ->and($message->fresh()->status)->toBe(MessageStatus::Queued);

    $held->release();
});

it('settles an abandoned message as unknown rather than leaving it pending forever', function () {
    // The queue gave up somewhere the dispatcher never saw — a killed worker, a job
    // timeout. Unknown rather than failed: it is not known that nothing was sent.
    $message = queuedFor();

    (new SendSmsMessage($message->getKey(), []))->failed(new RuntimeException('worker died'));

    expect($message->fresh()->status)->toBe(MessageStatus::Unknown)
        ->and($message->fresh()->error)->toContain('worker died');
});
