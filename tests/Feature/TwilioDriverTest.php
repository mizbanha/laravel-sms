<?php

declare(strict_types=1);

use Mizbanha\Sms\Enums\DeliveryMode;
use Mizbanha\Sms\Enums\FailureKind;
use Mizbanha\Sms\Enums\SendOutcome;
use Mizbanha\Sms\Facades\Sms;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Twilio, against the current Programmable Messaging documentation.
 *
 * Response shapes here are the documented ones: 201 with the message resource on
 * creation, and `{code, message, more_info, status}` on a refusal. The error codes
 * are the ones Twilio's own Test Credentials magic numbers produce, so every
 * classification asserted below is one that can be checked against the real API on
 * the day credentials exist — see TwilioIntegrationTest.
 */
const TWILIO_SID = 'SM8f2a1c9e4b7d40f1a2c3d4e5f60718a9';

function twilioCredentials(): array
{
    return ['account_sid' => 'ACtest0000000000000000000000000001', 'auth_token' => 'twilio-secret-token'];
}

function twilioGateway(string $sender = '+15005550006', array $options = []): void
{
    [$gateway] = test()->configureGateway(
        driver: 'twilio',
        mode: DeliveryMode::Text,
        body: 'Hello {customer_name}',
        credentials: twilioCredentials(),
    );

    $gateway->forceFill(['sender' => $sender] + ($options === [] ? [] : ['options' => $options]))->save();
}

/** The documented creation response. */
function twilioCreated(string $status = 'queued'): array
{
    return [
        'sid' => TWILIO_SID,
        'status' => $status,
        'to' => '+14155552671',
        'from' => '+15005550006',
        'body' => 'Hello Amid',
        'num_segments' => '1',
        'error_code' => null,
        'error_message' => null,
    ];
}

/** The documented error response. */
function twilioError(int $code, string $message = 'Something is wrong', int $status = 400): array
{
    return ['code' => $code, 'message' => $message, 'more_info' => 'https://www.twilio.com/docs/errors/'.$code, 'status' => $status];
}

/** An international destination, because that is what this driver is for. */
function twilioSend(string $to = '+14155552671')
{
    return Sms::to($to)->template('order-created')->with(['customer_name' => 'Amid'])->send();
}

it('posts a form-encoded message to the account message resource', function () {
    twilioGateway();

    Http::fake(['*' => Http::response(twilioCreated(), 201)]);

    twilioSend();

    Http::assertSent(function (Request $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/ACtest0000000000000000000000000001/Messages.json'
            // Documented as application/x-www-form-urlencoded, not JSON.
            && str_contains($request->header('Content-Type')[0], 'application/x-www-form-urlencoded')
            && $request['To'] === '+14155552671'
            && $request['From'] === '+15005550006'
            && $request['Body'] === 'Hello Amid';
    });
});

it('authenticates with the account sid and auth token as HTTP basic', function () {
    // Unique to this driver: no key in a header, a body or a URL.
    twilioGateway();

    Http::fake(['*' => Http::response(twilioCreated(), 201)]);

    twilioSend();

    Http::assertSent(function (Request $request): bool {
        return $request->header('Authorization')[0]
            === 'Basic '.base64_encode('ACtest0000000000000000000000000001:twilio-secret-token');
    });
});

it('sends the canonical E.164 number rather than a national one', function () {
    /*
     * ⚠️ The opposite of every other driver in this package, all of which want the
     * Iranian national form. A national number reaching Twilio is a number with no
     * country code, which is unroutable.
     */
    twilioGateway();

    Http::fake(['*' => Http::response(twilioCreated(), 201)]);

    Sms::to('0912 123 4567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    Http::assertSent(fn (Request $r): bool => $r['To'] === '+989121234567');
});

it('stores the Message SID exactly as returned', function () {
    twilioGateway();

    Http::fake(['*' => Http::response(twilioCreated(), 201)]);

    $attempt = twilioSend()->attempts()->first();

    // A prefixed string, not a number. This is the key a delivery milestone will
    // later fetch or match a StatusCallback against.
    expect($attempt->provider_message_id)->toBe(TWILIO_SID);
});

it('treats the queued status as accepted, not as delivered', function (string $status) {
    /*
     * ⚠️ The distinction that matters most in this driver. Twilio answering
     * `queued` means it has taken responsibility for processing the message; the
     * handset has not been reached and may never be. The message becomes
     * `accepted`, and nothing here infers delivery.
     */
    twilioGateway();

    Http::fake(['*' => Http::response(twilioCreated($status), 201)]);

    $message = twilioSend();

    expect($message->status->value)->toBe('accepted')
        ->and($message->status->value)->not->toBe('delivered');
})->with(['queued', 'accepted', 'scheduled']);

it('uses a messaging service instead of a sender when one is configured', function () {
    // Twilio treats these as alternatives, so only one is ever sent.
    twilioGateway(options: ['messaging_service_sid' => 'MG9876543210abcdef9876543210abcdef']);

    Http::fake(['*' => Http::response(twilioCreated(), 201)]);

    twilioSend();

    Http::assertSent(function (Request $request): bool {
        return $request['MessagingServiceSid'] === 'MG9876543210abcdef9876543210abcdef'
            && ! array_key_exists('From', $request->data());
    });
});

it('records a gateway with neither sender nor messaging service as unusable', function () {
    twilioGateway(sender: '');

    Http::fake();

    $attempt = twilioSend()->attempts()->first();

    Http::assertNothingSent();

    expect($attempt->failure_kind)->toBe(FailureKind::GatewayConfiguration)
        ->and($attempt->safe_to_failover)->toBeTrue();
});

it('classifies the documented error codes by what they mean', function (
    int $code,
    FailureKind $kind,
    bool $retryable,
    bool $failover,
) {
    twilioGateway();

    Http::fake(['*' => Http::response(twilioError($code), 400)]);

    $attempt = twilioSend()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->failure_kind)->toBe($kind)
        ->and($attempt->retryable_on_same_gateway)->toBe($retryable)
        ->and($attempt->safe_to_failover)->toBe($failover)
        ->and($attempt->provider_message_id)->toBeNull();
})->with([
    // The destination itself: the same number reaches the next gateway unchanged.
    'invalid To' => [21211, FailureKind::InvalidRecipient, false, false],
    'To cannot receive SMS' => [21614, FailureKind::InvalidRecipient, false, false],

    // This gateway's sender and this Twilio account: another gateway has its own.
    'invalid From' => [21212, FailureKind::GatewayConfiguration, false, true],
    'From not SMS-capable on this account' => [21606, FailureKind::GatewayConfiguration, false, true],
    'region not permitted' => [21408, FailureKind::GatewayConfiguration, false, true],

    // The pairing rather than either end: Twilio's own remedy is a different sender.
    'unroutable To/From combination' => [21612, FailureKind::GatewayRejected, false, true],

    // Capacity, and the only Twilio code worth trying again here.
    'sender queue full' => [21611, FailureKind::ProviderUnavailable, true, true],

    // The message: an empty body is empty everywhere.
    'no body' => [21602, FailureKind::InvalidMessage, false, false],
    'body too long' => [21617, FailureKind::InvalidMessage, false, false],
]);

it('refuses to fail over an opted-out recipient', function () {
    /*
     * ⚠️ The most important test in this driver, and the one rule here that is not
     * an engineering judgement.
     *
     * 21610 means the person replied STOP. Twilio scopes an opt-out to the sender
     * it was sent to, so it would be technically arguable that another provider's
     * number is not covered — and that argument is exactly the danger. Failover
     * exists so an outage does not lose a message; using it to reach somebody who
     * asked not to be reached would turn a reliability mechanism into a way of
     * ignoring them, one gateway at a time.
     */
    twilioGateway();

    Http::fake(['*' => Http::response(twilioError(21610, 'Attempt to send to unsubscribed recipient'), 400)]);

    $attempt = twilioSend()->attempts()->first();

    expect($attempt->safe_to_failover)->toBeFalse()
        ->and($attempt->retryable_on_same_gateway)->toBeFalse()
        ->and($attempt->error)->toContain('opted out');
});

it('refuses to fail over an opt-out however the status arrives', function (int $status) {
    /*
     * ⚠️ Belt and braces, deliberately.
     *
     * The shared transport classifier settles 401 and 403 from the status alone, as
     * a credentials problem that IS safe to fail over. That is right for an
     * authentication failure and catastrophic here: were 21610 ever to arrive on a
     * 403, the generic rule would hand an opted-out person's message to the next
     * gateway. The driver reads the documented code in preference to the status it
     * came with.
     */
    twilioGateway();

    Http::fake(['*' => Http::response(twilioError(21610, 'unsubscribed', $status), $status)]);

    $attempt = twilioSend()->attempts()->first();

    expect($attempt->safe_to_failover)->toBeFalse()
        ->and($attempt->failure_kind)->toBe(FailureKind::InvalidRecipient);
})->with([400, 403]);

it('handles an unrecognised structured error conservatively', function () {
    // Twilio publishes thousands of codes and warns that their causes change. An
    // unrecognised one could be an account problem the next gateway would not have,
    // or a refusal of this exact message that every gateway would repeat.
    twilioGateway();

    Http::fake(['*' => Http::response(twilioError(30007, 'Message filtered'), 400)]);

    $attempt = twilioSend()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->failure_kind)->toBe(FailureKind::GatewayRejected)
        ->and($attempt->safe_to_failover)->toBeFalse()
        ->and($attempt->error)->toContain('does not recognise')
        // Twilio's own sentence is quoted for an operator; never read to decide.
        ->and($attempt->error)->toContain('Message filtered');
});

it('refuses a 201 that carries an error code', function () {
    // Defensive: a created resource that reports its own failure is not an
    // acceptance, whatever the status line said.
    twilioGateway();

    Http::fake(['*' => Http::response(
        ['sid' => TWILIO_SID, 'status' => 'failed', 'error_code' => 21612, 'error_message' => 'cannot route'],
        201,
    )]);

    $attempt = twilioSend()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->provider_message_id)->toBeNull();
});

it('treats rate limiting as retryable and failable over', function () {
    twilioGateway();

    Http::fake(['*' => Http::response(['code' => 20429, 'message' => 'Too Many Requests'], 429)]);

    $attempt = twilioSend()->attempts()->first();

    expect($attempt->failure_kind)->toBe(FailureKind::ProviderUnavailable)
        ->and($attempt->retryable_on_same_gateway)->toBeTrue()
        ->and($attempt->safe_to_failover)->toBeTrue();
});

it('treats a server error as uncertain and stops', function () {
    // Unchanged from every other driver, and not weakened for this one: Twilio may
    // have taken the message before it failed.
    twilioGateway();

    Http::fake(['*' => Http::response(['code' => 20500, 'message' => 'Internal error'], 500)]);

    $message = twilioSend();

    expect($message->attempts()->first()->outcome)->toBe(SendOutcome::Uncertain)
        ->and($message->status->value)->toBe('unknown');
});

it('treats a dead connection as uncertain', function () {
    twilioGateway();

    Http::fake(['*' => fn () => throw new ConnectionException('timed out')]);

    $message = twilioSend();

    expect($message->attempts()->first()->outcome)->toBe(SendOutcome::Uncertain)
        ->and($message->attempts()->first()->safe_to_failover)->toBeFalse()
        ->and($message->status->value)->toBe('unknown');
});

it('rejects bad credentials without claiming anything about the message', function () {
    twilioGateway();

    Http::fake(['*' => Http::response(['code' => 20003, 'message' => 'Authenticate'], 401)]);

    $attempt = twilioSend()->attempts()->first();

    expect($attempt->failure_kind)->toBe(FailureKind::GatewayConfiguration)
        ->and($attempt->safe_to_failover)->toBeTrue();
});

it('never lets the account sid or auth token reach the stored error or payload', function () {
    /*
     * ⚠️ Basic auth means both credentials are in an Authorization header on every
     * request, and this provider echoes request values back inside its error
     * messages. The attempts table is kept for months.
     */
    twilioGateway();

    Http::fake(['*' => Http::response([
        'code' => 21212,
        'message' => 'Account ACtest0000000000000000000000000001 with token twilio-secret-token is invalid',
        'echo' => ['auth_token' => 'twilio-secret-token'],
    ], 400)]);

    $attempt = twilioSend()->attempts()->first();

    expect($attempt->error)->not->toContain('twilio-secret-token')
        ->and($attempt->error)->not->toContain('ACtest0000000000000000000000000001')
        ->and(json_encode($attempt->provider_payload))->not->toContain('twilio-secret-token')
        // And the request's own Authorization header is never persisted at all.
        ->and(json_encode($attempt->provider_payload))->not->toContain('Basic ');
});
