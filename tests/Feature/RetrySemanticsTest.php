<?php

declare(strict_types=1);

use Amid\Sms\Enums\DeliveryMode;
use Amid\Sms\Enums\MessageStatus;
use Amid\Sms\Facades\Sms;
use Amid\Sms\Models\SmsMessage;
use Amid\Sms\Sending\MessageDispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Who is allowed to try again, and when.
 *
 * The dispatcher does not decide policy - the driver already did, on the result -
 * but it does have to leave the message in the right state for the caller that can
 * act on it.
 */
beforeEach(function () {
    $this->configureGateway(driver: 'smsir', mode: DeliveryMode::Text, body: 'Hello {customer_name}');
});

function queuedMessage(): SmsMessage
{
    Queue::fake();

    return Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->queue();
}

it('leaves a retryable failure unsettled so the caller can try again', function () {
    // Rate limiting: the provider told us it did not process the request, so trying
    // the same gateway again is both safe and sensible. The row must stay unsettled
    // or the retry would find it finished and do nothing.
    Http::fake(['*' => Http::response(['message' => 'slow down'], 429)]);

    $message = queuedMessage();

    app(MessageDispatcher::class)->attempt($message, ['customer_name' => 'Amid'], mayRetry: true);

    expect($message->fresh()->status)->toBe(MessageStatus::Sending)
        ->and($message->fresh()->isSettled())->toBeFalse();
});

it('settles the same failure when the caller has no retry left', function () {
    // The synchronous path, and the last queue attempt: identical result, but
    // nobody is going to try again, so leaving it unsettled would strand it.
    Http::fake(['*' => Http::response(['message' => 'slow down'], 429)]);

    $message = queuedMessage();

    app(MessageDispatcher::class)->attempt($message, ['customer_name' => 'Amid'], mayRetry: false);

    expect($message->fresh()->status)->toBe(MessageStatus::Failed)
        ->and($message->fresh()->isSettled())->toBeTrue();
});

it('never leaves an uncertain result open for retry, even when retries remain', function () {
    // The rule the whole design turns on: a message that may already have been
    // delivered is not eligible to be sent again, whatever the retry budget says.
    Http::fake(['*' => Http::response('boom', 500)]);

    $message = queuedMessage();

    app(MessageDispatcher::class)->attempt($message, ['customer_name' => 'Amid'], mayRetry: true);

    expect($message->fresh()->status)->toBe(MessageStatus::Unknown)
        ->and($message->fresh()->isSettled())->toBeTrue();
});

it('records a second attempt with the next sequence number', function () {
    // Two handovers, two rows. This is what makes "why did they get two messages"
    // answerable, and it is the shape failover will write into.
    Http::fake(['*' => Http::response(['message' => 'slow down'], 429)]);

    $message = queuedMessage();

    app(MessageDispatcher::class)->attempt($message, ['customer_name' => 'Amid'], mayRetry: true);
    app(MessageDispatcher::class)->attempt($message->fresh(), ['customer_name' => 'Amid'], mayRetry: true);

    expect($message->fresh()->attempts()->pluck('sequence')->all())->toBe([1, 2]);
});

it('does nothing at all when handed an already settled message', function () {
    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [1]]])]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    $result = app(MessageDispatcher::class)->attempt($message->fresh(), ['customer_name' => 'Amid'], mayRetry: true);

    expect($result)->toBeNull();
    Http::assertSentCount(1);
});
