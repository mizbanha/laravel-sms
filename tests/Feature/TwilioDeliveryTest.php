<?php

declare(strict_types=1);

use Amid\Sms\Drivers\TwilioDriver;
use Amid\Sms\Enums\Capability;
use Amid\Sms\Enums\DeliveryStatus;
use Amid\Sms\Exceptions\DeliveryLookupFailed;
use Amid\Sms\Gateways\GatewayConfig;
use Amid\Sms\Phone\PhoneNumber;
use Illuminate\Support\Facades\Http;

/**
 * Twilio delivery lookup, against the current Message resource documentation.
 *
 * ⚠️ The distinction this whole file protects is Twilio's own: `sent` means "the
 * nearest upstream carrier accepted the outbound message" and `delivered` means
 * "Twilio has received confirmation of outbound message delivery from the upstream
 * carrier". Collapsing them produces a dashboard claiming every message arrived,
 * including the ones sent to a phone that has been switched off for a year.
 *
 * Every request here is faked. No Twilio account exists and none was created.
 */
function twilio(): TwilioDriver
{
    return new TwilioDriver(new GatewayConfig(
        key: 'primary',
        sender: '+15005550006',
        credentials: ['account_sid' => 'ACtest0000000000000000000000000001', 'auth_token' => 'twilio-secret-token'],
    ));
}

function askTwilio(array $body, int $status = 200, string $sid = 'SM8f2a0000000000000000000000000001')
{
    Http::fake(['*' => Http::response($body, $status)]);

    return twilio()->deliveryStatus($sid, new PhoneNumber('+14155552671', '4155552671', 'US'));
}

it('advertises the delivery-report capability it actually implements', function () {
    // ⚠️ The capability and the interface must agree. One without the other is a
    // claim rather than a fact, and the router and any management screen believe it.
    expect(twilio()->capabilities())->toContain(Capability::DeliveryReport)
        ->and(twilio())->toBeInstanceOf(\Amid\Sms\Contracts\ReportsDeliveryStatus::class);
});

it('fetches the message resource by its SID with basic auth', function () {
    askTwilio(['sid' => 'SM8f2a0000000000000000000000000001', 'status' => 'delivered']);

    Http::assertSent(function ($request): bool {
        return $request->method() === 'GET'
            && $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/ACtest0000000000000000000000000001/Messages/SM8f2a0000000000000000000000000001.json'
            // The same Basic auth as the send: account SID as user, auth token as
            // password, built by the HTTP client and never in a body we construct.
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('ACtest0000000000000000000000000001:twilio-secret-token'));
    });
});

it('maps a documented twilio status onto a neutral one', function (string $twilio, DeliveryStatus $expected) {
    $result = askTwilio(['sid' => 'SM1', 'status' => $twilio]);

    expect($result->status)->toBe($expected)
        // The provider's own word is kept beside our verdict, because our five
        // states deliberately lose detail a support ticket will need.
        ->and($result->providerStatus)->toBe($twilio);
})->with([
    // Twilio has it and has not yet handed it anywhere final.
    ['queued', DeliveryStatus::Pending],
    ['accepted', DeliveryStatus::Pending],
    ['scheduled', DeliveryStatus::Pending],
    ['sending', DeliveryStatus::Pending],

    // ⚠️ Carrier acceptance. NOT the handset.
    ['sent', DeliveryStatus::Sent],

    ['delivered', DeliveryStatus::Delivered],

    // Documented for RCS and WhatsApp: the recipient opened it, which certainly
    // means it arrived. Not given a state of its own — read receipts are not a
    // concept this model has any business acquiring for a channel we do not send on.
    ['read', DeliveryStatus::Delivered],

    // Terminal non-delivery, for three different reasons. `failed` never left,
    // `undelivered` came back with a negative receipt, `canceled` was a scheduled
    // message that will now never be sent.
    ['undelivered', DeliveryStatus::Failed],
    ['failed', DeliveryStatus::Failed],
    ['canceled', DeliveryStatus::Failed],
]);

it('treats a status it does not recognise as unknown', function (string $twilio) {
    /*
     * ⚠️ Conservative, as everywhere in this package. `partially_delivered` is
     * documented as DEPRECATED and is deliberately not mapped: there is no part of
     * one single-recipient SMS that can arrive without the rest of it, and guessing
     * either way would be inventing a fact. An unrecognised status guessed into
     * `delivered` is a lie in the direction that stops anybody investigating.
     */
    expect(askTwilio(['sid' => 'SM1', 'status' => $twilio])->status)->toBe(DeliveryStatus::Unknown);
})->with(['partially_delivered', 'receiving', 'something-new-in-2027']);

it('keeps the structured error code from a failed delivery', function () {
    /*
     * ⚠️ Retained, never branched on. Twilio itself warns that `error_code` and
     * `error_message` "are subject to change as Twilio improves errors" and that
     * they should not be used programmatically — so the code is recorded because it
     * is what a support ticket quotes, and no behaviour in this package depends on
     * its value.
     *
     * ⚠️ It is also NOT run through the send-side FailureKind table. A delivery
     * failure and a submission rejection are different phases and the same number
     * does not mean the same thing in both.
     */
    $result = askTwilio([
        'sid' => 'SM1',
        'status' => 'undelivered',
        'error_code' => 30006,
        'error_message' => 'Landline or unreachable carrier',
    ]);

    expect($result->status)->toBe(DeliveryStatus::Failed)
        // A string: these are identifiers, and casting one is how a code with a
        // leading zero becomes a different code.
        ->and($result->providerErrorCode)->toBe('30006')
        ->and($result->error)->toContain('undelivered')
        ->and($result->error)->toContain('Landline or unreachable carrier');
});

it('refuses to guess when the report request fails', function (int $status) {
    // 404 for an id Twilio does not recognise, 401 for a rotated token, 500 for a
    // bad afternoon. None of them says anything about whether the message arrived.
    askTwilio(['message' => 'nope'], $status);
})->with([401, 404, 500])->throws(DeliveryLookupFailed::class);

it('refuses to guess when the response carries no status', function () {
    // A 200 that is not a report. Reporting `unknown` here would record a verdict
    // about the shape of a response rather than about a message.
    askTwilio(['sid' => 'SM1']);
})->throws(DeliveryLookupFailed::class);

it('never lets the auth token reach the reported error text', function () {
    // Same rule as the send path: a provider that echoes the request must not be
    // able to write a credential into anything we keep.
    $result = askTwilio([
        'sid' => 'SM1',
        'status' => 'failed',
        'error_message' => 'auth twilio-secret-token was used',
    ]);

    expect($result->error)->not->toContain('twilio-secret-token')
        ->and($result->error)->toContain('[redacted]');
});

it('carries no provider payload at all', function () {
    /*
     * ⚠️ The message resource returns the body, the sender, the price and the
     * account SID. There is nowhere on a DeliveryResult to put any of it, which is
     * the point: a field that could hold a report body is a field that eventually
     * holds one.
     */
    $result = askTwilio([
        'sid' => 'SM1',
        'status' => 'delivered',
        'body' => 'Your code is 482193',
        'account_sid' => 'ACtest0000000000000000000000000001',
    ]);

    expect(json_encode($result))->not->toContain('482193')
        ->not->toContain('ACtest0000000000000000000000000001');
});
