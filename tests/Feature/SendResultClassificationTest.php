<?php

declare(strict_types=1);

use Amid\Sms\Enums\DeliveryMode;
use Amid\Sms\Enums\FailureKind;
use Amid\Sms\Enums\SendOutcome;
use Amid\Sms\Facades\Sms;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * What the orchestrator is told, and therefore what it is allowed to do next.
 *
 * These are the tests that stand between an unlucky afternoon and a customer
 * receiving the same message twice.
 */
beforeEach(function () {
    $this->configureGateway(driver: 'smsir', mode: DeliveryMode::Text, body: 'Hello {customer_name}');
});

function attemptFor(string $name = 'Amid')
{
    return Sms::to('09121234567')->template('order-created')->with(['customer_name' => $name])->send()
        ->attempts()->first();
}

it('records an acceptance with the provider id', function () {
    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [4242]]])]);

    $attempt = attemptFor();

    expect($attempt->outcome)->toBe(SendOutcome::Accepted)
        ->and($attempt->failure_kind)->toBeNull()
        ->and($attempt->provider_message_id)->toBe('4242')
        // Nothing to retry and nothing to fail over: it was taken.
        ->and($attempt->retryable_on_same_gateway)->toBeFalse()
        ->and($attempt->safe_to_failover)->toBeFalse();
});

it('classifies an unexplained provider refusal conservatively', function () {
    // The provider understood us and said no, without saying what about. With no
    // verified code catalogue we cannot tell an account problem from a refusal of
    // this exact message, so neither a retry here nor a move to another gateway is
    // justified.
    Http::fake(['*' => Http::response(['status' => 0, 'message' => 'Template not found'])]);

    $attempt = attemptFor();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->failure_kind)->toBe(FailureKind::GatewayRejected)
        ->and($attempt->retryable_on_same_gateway)->toBeFalse()
        ->and($attempt->safe_to_failover)->toBeFalse()
        ->and($attempt->message->status->value)->toBe('failed');
});

it('classifies rejected credentials as a gateway configuration failure', function () {
    Http::fake(['*' => Http::response(['message' => 'unauthorized'], 401)]);

    $attempt = attemptFor();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->failure_kind)->toBe(FailureKind::GatewayConfiguration)
        // Retrying here is pointless until a human edits something, but the message
        // itself is fine and another gateway should have it.
        ->and($attempt->retryable_on_same_gateway)->toBeFalse()
        ->and($attempt->safe_to_failover)->toBeTrue();
});

it('classifies rate limiting as retryable and known not-sent', function () {
    // The one failure that is genuinely worth trying again on the same gateway: the
    // provider told us it did not process the request.
    Http::fake(['*' => Http::response(['message' => 'slow down'], 429)]);

    $attempt = attemptFor();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->failure_kind)->toBe(FailureKind::ProviderUnavailable)
        ->and($attempt->retryable_on_same_gateway)->toBeTrue()
        ->and($attempt->safe_to_failover)->toBeTrue();
});

it('treats a provider server error as uncertain rather than failed', function () {
    // The request arrived. Whether it was processed before things went wrong is not
    // knowable, and assuming it was not is how one message becomes two.
    Http::fake(['*' => Http::response('gateway blew up', 503)]);

    $attempt = attemptFor();

    expect($attempt->outcome)->toBe(SendOutcome::Uncertain)
        ->and($attempt->failure_kind)->toBe(FailureKind::ProviderUnavailable)
        ->and($attempt->retryable_on_same_gateway)->toBeFalse()
        ->and($attempt->safe_to_failover)->toBeFalse()
        // Terminal, and deliberately not "failed": we do not know that it failed.
        ->and($attempt->message->status->value)->toBe('unknown');
});

it('treats a connection failure as uncertain and never re-sends it', function () {
    // Laravel reports a refused connection and a read timeout as the same
    // exception, and only the message text tells them apart - which the design
    // forbids reading. Guessing optimistically sends the message twice, so the
    // answer is "unknown".
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    $attempt = attemptFor();

    expect($attempt->outcome)->toBe(SendOutcome::Uncertain)
        ->and($attempt->failure_kind)->toBe(FailureKind::Network)
        ->and($attempt->retryable_on_same_gateway)->toBeFalse()
        ->and($attempt->safe_to_failover)->toBeFalse()
        ->and($attempt->message->status->value)->toBe('unknown');
});

it('does not put the request URL into the error of a connection failure', function () {
    // Some providers carry the API key in the request URL, and a transport error
    // quotes the URL. The recorded reason is our own wording for that reason.
    Http::fake(fn () => throw new ConnectionException(
        'cURL error 28 for https://api.sms.ir/v1/send/bulk?key=test-key',
    ));

    expect(attemptFor()->error)->not->toContain('test-key');
});

it('refuses a message the caller cannot fill in, before recording anything', function () {
    Http::fake();

    // A caller mistake, thrown rather than recorded: it is a bug in the calling
    // code, and it is caught before a row exists or a provider is contacted.
    expect(fn () => Sms::to('09121234567')->template('order-created')->with([])->send())
        ->toThrow(\Amid\Sms\Exceptions\MissingVariables::class);

    expect(\Amid\Sms\Models\SmsMessage::query()->count())->toBe(0);
    Http::assertNothingSent();
});
