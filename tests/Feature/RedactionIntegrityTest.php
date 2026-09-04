<?php

declare(strict_types=1);

use Mizbanha\Sms\Enums\DeliveryMode;
use Mizbanha\Sms\Enums\FailureKind;
use Mizbanha\Sms\Enums\SendOutcome;
use Mizbanha\Sms\Facades\Sms;
use Illuminate\Support\Facades\Http;

/**
 * Credential redaction must protect the stored copy without editing the data the
 * package makes decisions from.
 *
 * ⚠️ **The defect this file exists for**, found in M4 and fixed in M5. Drivers
 * read the provider message id, the success verdict and the structured error code
 * out of an already-redacted payload. Redaction is a substring replacement over
 * every configured credential value, so a gateway whose password happened to be
 * `u` turned the Twilio SID `SMcountryrouted0001` into
 * `SMco[redacted]ntryro[redacted]ted0001` — a stored identifier that matches
 * nothing at the provider, in a package that promises to store it exactly as
 * returned.
 *
 * The wrong fix is a minimum credential length. A two-character credential is
 * still a credential and must still be scrubbed aggressively. The right fix is
 * ordering: **parse from the raw response, redact the copy that gets persisted.**
 * So every test here uses a deliberately brutal one-character credential and
 * asserts both halves — the identifier survives intact, and the secret does not.
 */
function shortCredentialGateway(string $driver, array $credentials): void
{
    [$gateway] = test()->configureGateway(driver: $driver, mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    $gateway->forceFill(['credentials' => $credentials, 'sender' => '+15005550006'])->save();
}

function sendShort(string $to = '09121234567')
{
    return Sms::to($to)->template('order-created')->with(['customer_name' => 'Amid'])->send();
}

it('keeps a provider message id byte-identical despite a one-character credential', function () {
    /*
     * `1` appears in the SID nine times. Every one of them would have been replaced
     * before M5, because the id was read out of the redacted copy.
     */
    shortCredentialGateway('twilio', ['account_sid' => 'AC1', 'auth_token' => '1']);

    $sid = 'SM11111111111111111111111111111111';

    Http::fake(['*' => Http::response(['sid' => $sid, 'status' => 'queued'], 201)]);

    $attempt = sendShort('+14155552671')->attempts()->first();

    expect($attempt->provider_message_id)->toBe($sid)
        ->and($attempt->outcome)->toBe(SendOutcome::Accepted);
});

it('keeps an Iranian provider message id intact too', function () {
    // Not a Twilio quirk: every driver read its identifier from the redacted copy.
    shortCredentialGateway('smsir', ['api_key' => '9']);

    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [99009]]])]);

    expect(sendShort()->attempts()->first()->provider_message_id)->toBe('99009');
});

it('classifies from the raw structured response, not the redacted copy', function () {
    /*
     * ⚠️ The most dangerous half of the same defect.
     *
     * Twilio's `21610` is the consent refusal that must never fail over, and `1`
     * appears in it three times. Reading a control-flow value through a
     * transformation that may edit it means a security decision made on data
     * something else was allowed to rewrite.
     */
    shortCredentialGateway('twilio', ['account_sid' => 'AC1', 'auth_token' => '1']);

    Http::fake(['*' => Http::response(
        ['code' => 21610, 'message' => 'Attempt to send to unsubscribed recipient'],
        400,
    )]);

    $attempt = sendShort('+14155552671')->attempts()->first();

    expect($attempt->failure_kind)->toBe(FailureKind::InvalidRecipient)
        ->and($attempt->safe_to_failover)->toBeFalse();
});

it('still redacts the credential out of the persisted payload', function () {
    /*
     * The other half, and the reason "just do not redact short values" is not the
     * fix. This provider echoes the token back; aggressive replacement is exactly
     * what should happen to the copy that is written down.
     */
    shortCredentialGateway('smsir', ['api_key' => 'u']);

    Http::fake(['*' => Http::response([
        'status' => 1,
        'data' => ['messageIds' => [42]],
        'echo' => ['key' => 'u'],
    ])]);

    $attempt = sendShort()->attempts()->first();
    $payload = json_encode($attempt->provider_payload);

    expect($attempt->provider_message_id)->toBe('42')
        // Aggressive to the point of mangling the surrounding words, which is
        // correct behaviour for a persisted copy nothing reads to decide anything.
        ->and($payload)->toContain('[redacted]')
        ->and(data_get($attempt->provider_payload, 'echo.key'))->toBe('[redacted]');
});

it('still keeps a long credential out of both the error and the payload', function () {
    // The ordinary case, unchanged: redaction is not weakened anywhere by the fix.
    shortCredentialGateway('smsir', ['api_key' => 'a-real-looking-secret-key']);

    Http::fake(['*' => Http::response([
        'status' => 0,
        'message' => 'key a-real-looking-secret-key is not valid',
        'echo' => ['api_key' => 'a-real-looking-secret-key'],
    ])]);

    $attempt = sendShort()->attempts()->first();

    expect($attempt->error)->not->toContain('a-real-looking-secret-key')
        ->and(json_encode($attempt->provider_payload))->not->toContain('a-real-looking-secret-key');
});
