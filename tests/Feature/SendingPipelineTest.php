<?php

declare(strict_types=1);

use Mizbanha\Sms\Enums\DeliveryMode;
use Mizbanha\Sms\Enums\MessageStatus;
use Mizbanha\Sms\Facades\Sms;
use Mizbanha\Sms\Jobs\SendSmsMessage;
use Mizbanha\Sms\Models\SmsMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->configureGateway(driver: 'smsir', mode: DeliveryMode::Text, body: 'Hello {customer_name}');
});

it('persists the message before any provider is contacted', function () {
    // If the process dies mid-send there has to be a record that it was attempted.
    // The other order round loses the message entirely and nobody ever knows one
    // was meant.
    $seen = null;

    Http::fake(function () use (&$seen) {
        $seen = SmsMessage::query()->first();

        return Http::response(['status' => 1, 'data' => ['messageIds' => [1]]]);
    });

    Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    expect($seen)->not->toBeNull()
        ->and($seen->to)->toBe('+989121234567')
        // Already in flight by the time the provider is called, never still queued.
        ->and($seen->status)->toBe(MessageStatus::Sending);
});

it('stores the canonical destination and the rendered wording', function () {
    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [1]]])]);

    $message = Sms::to('0912 123 4567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    expect($message->to)->toBe('+989121234567')
        ->and($message->body)->toBe('Hello Amid')
        ->and($message->variables)->toBe(['customer_name' => 'Amid'])
        ->and($message->sent_at)->not->toBeNull();
});

it('records one attempt per handover, with the gateway snapshotted onto it', function () {
    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [77]]])]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();
    $attempt = $message->attempts()->first();

    expect($message->attempts)->toHaveCount(1)
        ->and($attempt->sequence)->toBe(1)
        // Text, not the foreign key alone: the evidence has to survive the gateway
        // being renamed or deleted.
        ->and($attempt->gateway_key)->toBe('primary')
        ->and($attempt->driver)->toBe('smsir')
        ->and($attempt->mode)->toBe(DeliveryMode::Text);
});

it('queues a job instead of sending during the request', function () {
    Queue::fake();
    Http::fake();

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->queue();

    expect($message->status)->toBe(MessageStatus::Queued);
    Http::assertNothingSent();

    Queue::assertPushed(SendSmsMessage::class, function (SendSmsMessage $job) use ($message): bool {
        // The variables travel in the payload, not read back from the row. That is
        // what will let a future sensitive message be sent while persisting none of
        // its values.
        return $job->messageId === $message->getKey()
            && $job->variables === ['customer_name' => 'Amid'];
    });
});

it('does not deliver again when a job runs a second time for a settled message', function () {
    // The idempotency guarantee. A worker killed mid-run, a deployment, a job
    // dispatched twice - Laravel re-runs jobs for reasons that have nothing to do
    // with the gateway, and every one of them is a chance to send twice.
    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [1]]])]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    expect($message->status)->toBe(MessageStatus::Accepted);
    Http::assertSentCount(1);

    // The same job, run again over the same settled message.
    (new SendSmsMessage($message->getKey(), ['customer_name' => 'Amid']))
        ->handle(app(\Mizbanha\Sms\Sending\MessageDispatcher::class));

    Http::assertSentCount(1);
    expect($message->fresh()->attempts)->toHaveCount(1);
});

it('does not deliver again after an uncertain result', function () {
    // The case the whole uncertain outcome exists for: the provider may already
    // have the message, so a re-run must not hand it over a second time.
    Http::fake(['*' => Http::response('boom', 500)]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    expect($message->status)->toBe(MessageStatus::Unknown);
    Http::assertSentCount(1);

    (new SendSmsMessage($message->getKey(), ['customer_name' => 'Amid']))
        ->handle(app(\Mizbanha\Sms\Sending\MessageDispatcher::class));

    Http::assertSentCount(1);
});

it('records the message and sends nothing when the master switch is off', function () {
    // The line that protects a staging machine running against a restored
    // production database: real customers, real numbers, no messages.
    config()->set('laravel-sms.enabled', false);
    Queue::fake();
    Http::fake();

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    expect($message->status)->toBe(MessageStatus::Suppressed)
        // Recorded in full: the row is the evidence that the code did the right
        // thing in an environment where sending is off.
        ->and($message->body)->toBe('Hello Amid')
        ->and($message->attempts)->toHaveCount(0);

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('is switched off by default', function () {
    // Gateways live in the database now, so a restored dump arrives complete with
    // working credentials. Sending has to be opted into by an environment rather
    // than inherited from a database.
    expect(config()->get('laravel-sms.enabled'))->toBeTrue();

    $default = require __DIR__.'/../../config/laravel-sms.php';

    expect($default['enabled'])->toBeFalse();
});

it('fails the message rather than throwing when no gateway can carry it', function () {
    // A runtime configuration state, not a caller error. Sending is a side effect
    // of something more important, and an exception here would roll back the order
    // that the message was merely announcing.
    \Mizbanha\Sms\Models\SmsGateway::query()->update(['is_enabled' => false]);
    Http::fake();

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->error)->toContain('No eligible gateway')
        ->and($message->attempts)->toHaveCount(0);

    Http::assertNothingSent();
});

it('drops a pattern binding that has no registered code rather than sending text', function () {
    // A pattern is chosen precisely because free text would not arrive - it is not
    // delivered at night and is withheld from numbers on the national opt-out list.
    // A silent downgrade would look like success and reach a fraction of people.
    \Mizbanha\Sms\Models\SmsTemplateGateway::query()->update([
        'mode' => DeliveryMode::Pattern->value,
        'pattern_code' => null,
    ]);
    Http::fake();

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    expect($message->status)->toBe(MessageStatus::Failed);
    Http::assertNothingSent();
});

it('refuses an unusable recipient before recording anything', function () {
    Http::fake();

    // A caller mistake, and the one recipient problem that throws: the row stores
    // the canonical destination in a non-null column, so there is no message to
    // record this against.
    expect(fn () => Sms::to('0912123')->template('order-created')->with(['customer_name' => 'Amid'])->send())
        ->toThrow(\Mizbanha\Sms\Exceptions\InvalidRecipient::class);

    expect(SmsMessage::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('refuses a template key that does not exist', function () {
    expect(fn () => Sms::to('09121234567')->template('no-such-template'))
        ->toThrow(\Mizbanha\Sms\Exceptions\TemplateNotFound::class);
});
