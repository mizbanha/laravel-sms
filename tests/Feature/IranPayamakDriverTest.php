<?php

declare(strict_types=1);

use Amid\Sms\Enums\DeliveryMode;
use Amid\Sms\Enums\FailureKind;
use Amid\Sms\Enums\SendOutcome;
use Amid\Sms\Facades\Sms;
use Amid\Sms\Models\SmsGateway;
use Amid\Sms\Support\TableNames;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * IranPayamak, against the OpenAPI specification published at docs.iranpayamak.com.
 *
 * Response shapes here are the ones that specification publishes: the
 * `{status, data, messages}` envelope with `status` an enum of `success` or
 * `error`, a 201 for an accepted send, and `messages` as the four-shaped
 * `ApiMessage` — null, a sentence, a list of sentences, or a bag keyed by the
 * request field that failed.
 */
function iranPayamakOk(int $id = 407328): array
{
    return ['status' => 'success', 'data' => $id, 'messages' => null];
}

/**
 * A validation bag, which is the only shape of `messages` this driver branches on.
 *
 * @param  array<string, list<string>>  $fields
 */
function iranPayamakInvalid(array $fields): array
{
    return ['status' => 'error', 'data' => null, 'messages' => $fields];
}

it('sends a text message in the documented simple shape', function () {
    $this->configureGateway(driver: 'iranpayamak', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake(['*' => Http::response(iranPayamakOk(), 201)]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    expect($message->status->value)->toBe('accepted');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.iranpayamak.com/ws/v1/sms/simple'
            && $request['text'] === 'Hello Amid'
            && $request['line_number'] === '30001234'
            // A list at this endpoint, and the national form: the published schema
            // constrains a recipient to ^09\d{9}$.
            && $request['recipients'] === ['09121234567']
            && $request['number_format'] === 'english'
            // Required AND nullable, so "send it now" is the key with no value.
            && array_key_exists('schedule', $request->data())
            && $request['schedule'] === null;
    });
});

it('sends a pattern with a singular recipient and named attributes', function () {
    // Plural on one endpoint and singular on the other is the thing most easily got
    // wrong about this API.
    $this->configureGateway(
        driver: 'iranpayamak',
        mode: DeliveryMode::Pattern,
        body: 'Hi {customer_name}, order {order_number}.',
        patternCode: 'SJ3FgPrE0C',
        parameterMap: [
            ['provider' => 'var1', 'variable' => 'customer_name'],
            ['provider' => 'var2', 'variable' => 'order_number'],
        ],
    );

    Http::fake(['*' => Http::response(iranPayamakOk(), 201)]);

    Sms::to('09121234567')->template('order-created')
        ->with(['customer_name' => 'Amid', 'order_number' => 'CF-1204'])
        ->send();

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.iranpayamak.com/ws/v1/sms/pattern'
            && $request['code'] === 'SJ3FgPrE0C'
            // Singular, and a bare string rather than a list.
            && $request['recipient'] === '09121234567'
            && $request['attributes'] === ['var1' => 'Amid', 'var2' => 'CF-1204']
            && $request['line_number'] === '30001234'
            // Optional here, and the published example omits it.
            && ! array_key_exists('schedule', $request->data());
    });
});

it('carries a logical template through IranPayamak without the caller knowing its parameter names', function () {
    // The same call an application already makes for every other provider. This
    // provider's vocabulary lives entirely in the binding row.
    $this->configureGateway(
        driver: 'iranpayamak',
        mode: DeliveryMode::Pattern,
        body: 'Hi {customer_name}, order {order_number}.',
        patternCode: 'SJ3FgPrE0C',
        parameterMap: [
            ['provider' => 'ip_customer', 'variable' => 'customer_name'],
            ['provider' => 'ip_order', 'variable' => 'order_number'],
        ],
    );

    Http::fake(['*' => Http::response(iranPayamakOk(), 201)]);

    Sms::to('09121234567')->template('order-created')
        ->with(['customer_name' => 'Amid', 'order_number' => 'CF-1204'])
        ->send();

    Http::assertSent(fn (Request $r): bool => $r['attributes'] === [
        'ip_customer' => 'Amid',
        'ip_order' => 'CF-1204',
    ]);
});

it('omits attributes entirely for a pattern that takes no values', function () {
    // PHP encodes an empty associative array as [], a JSON array where every other
    // send puts an object. Not sending it avoids asking the provider's validator a
    // question its documentation does not answer.
    $this->configureGateway(
        driver: 'iranpayamak',
        mode: DeliveryMode::Pattern,
        body: 'Your order has shipped.',
        patternCode: 'SJ3FgPrE0C',
        parameterMap: [],
    );

    Http::fake(['*' => Http::response(iranPayamakOk(), 201)]);

    Sms::to('09121234567')->template('order-created')->send();

    Http::assertSent(fn (Request $r): bool => ! array_key_exists('attributes', $r->data()));
});

it('sends one recipient even though the simple endpoint accepts a list', function () {
    // This package's contract, not the provider's limit: a batch answers with one
    // status for everybody in it, and our log has a row per destination.
    $this->configureGateway(driver: 'iranpayamak', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake(['*' => Http::response(iranPayamakOk(), 201)]);

    Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    Http::assertSent(fn (Request $r): bool => count($r['recipients']) === 1);
});

it('authenticates with the Api-Key header and nothing else', function () {
    // Its own scheme, named exactly this. Not Authorization, not Bearer.
    $this->configureGateway(
        driver: 'iranpayamak',
        mode: DeliveryMode::Text,
        body: 'Hello {customer_name}',
        credentials: ['api_key' => 'iranpayamak-secret-token'],
    );

    Http::fake(['*' => Http::response(iranPayamakOk(), 201)]);

    Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    Http::assertSent(fn (Request $r): bool => $r->header('Api-Key') === ['iranpayamak-secret-token']
        && $r->header('Authorization') === []);
});

it('lets a gateway override the number format the specification contradicts itself about', function () {
    // english|persian on one endpoint, en|fa on the other, "english" in both
    // examples. A wrong value is a validation failure on every send, so it has to be
    // correctable without a release.
    [$gateway] = $this->configureGateway(driver: 'iranpayamak', mode: DeliveryMode::Text, body: 'Hello {customer_name}');
    $gateway->forceFill(['options' => ['number_format' => 'fa']])->save();

    Http::fake(['*' => Http::response(iranPayamakOk(), 201)]);

    Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    Http::assertSent(fn (Request $r): bool => $r['number_format'] === 'fa');
});

it('honours a base URL override', function () {
    [$gateway] = $this->configureGateway(driver: 'iranpayamak', mode: DeliveryMode::Text, body: 'Hello {customer_name}');
    $gateway->forceFill(['options' => ['url' => 'https://api.staging.iranpayamak.com']])->save();

    Http::fake(['*' => Http::response(iranPayamakOk(), 201)]);

    Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.staging.iranpayamak.com/ws/v1/sms/simple');
});

it('stores the send request id the provider answered with', function () {
    $this->configureGateway(driver: 'iranpayamak', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake(['*' => Http::response(iranPayamakOk(998877), 201)]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    // The id /ws/v1/send_request/{send_request_id} takes, stored as a string.
    expect($message->attempts()->first()->provider_message_id)->toBe('998877');
});

it('refuses a destination this line cannot reach, and fails over rather than calling it invalid', function () {
    // ⚠️ The point of the pre-flight check. The provider would answer a foreign
    // number with a `recipients` validation error, which this driver reads as
    // InvalidRecipient and never fails over — correct for a malformed number, and
    // exactly wrong for a well-formed German one that a Twilio gateway can carry.
    $this->configureGateway(driver: 'iranpayamak', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake();

    $attempt = Sms::to('+4915112345678')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->failure_kind)->toBe(FailureKind::GatewayRejected)
        ->and($attempt->safe_to_failover)->toBeTrue()
        // The country this gateway was asked to reach, not the number: whoever reads
        // the attempt row already has the recipient beside it.
        ->and($attempt->error)->toContain('DE');

    // Nothing was sent: the refusal is this driver's, made from the provider's own
    // published recipient pattern.
    Http::assertNothingSent();
});

it('refuses to send at all when the gateway has no sending line', function () {
    // line_number is required by both endpoints and constrained to ^[0-9]+$.
    [$gateway] = $this->configureGateway(driver: 'iranpayamak', mode: DeliveryMode::Text, body: 'Hello {customer_name}');
    $gateway->forceFill(['sender' => null])->save();

    Http::fake();

    $attempt = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->failure_kind)->toBe(FailureKind::GatewayConfiguration)
        // Another gateway has its own line.
        ->and($attempt->safe_to_failover)->toBeTrue();

    Http::assertNothingSent();
});

it('classifies an authentication failure as a gateway configuration problem', function () {
    // Not a bad message: the logical SMS is fine and another gateway should have it,
    // but repeating it here will fail identically until somebody edits the key.
    $this->configureGateway(driver: 'iranpayamak', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake(['*' => Http::response(['status' => 'error', 'data' => null, 'message' => 'Unauthorized'], 401)]);

    $attempt = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->failure_kind)->toBe(FailureKind::GatewayConfiguration)
        ->and($attempt->retryable_on_same_gateway)->toBeFalse()
        ->and($attempt->safe_to_failover)->toBeTrue();
});

it('classifies a recipient validation error as an invalid recipient and refuses to fail over', function () {
    // Read from the FIELD NAME, never from the Persian sentence beside it. A number
    // this provider will not take is a number the next one will not take either.
    $this->configureGateway(driver: 'iranpayamak', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake(['*' => Http::response(
        iranPayamakInvalid(['recipients.0' => ['شماره گیرنده معتبر نیست']]),
        422,
    )]);

    $attempt = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->failure_kind)->toBe(FailureKind::InvalidRecipient)
        ->and($attempt->safe_to_failover)->toBeFalse()
        ->and($attempt->retryable_on_same_gateway)->toBeFalse()
        // The reason names the field, not the prose. Dotted keys report the request
        // field, not the index inside it.
        ->and($attempt->error)->toContain('recipients');
});

it('classifies an unregistered pattern code as this gateway refusing, and allows failover', function () {
    // A pattern is registered per account. Another gateway may hold a perfectly good
    // registration for the same logical message.
    $this->configureGateway(
        driver: 'iranpayamak',
        mode: DeliveryMode::Pattern,
        body: 'Hi {customer_name}.',
        patternCode: 'wrong-code',
        parameterMap: [['provider' => 'var1', 'variable' => 'customer_name']],
    );

    Http::fake(['*' => Http::response(iranPayamakInvalid(['code' => ['الگوی مورد نظر یافت نشد']]), 422)]);

    $attempt = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->failure_kind)->toBe(FailureKind::GatewayRejected)
        ->and($attempt->safe_to_failover)->toBeTrue();
});

it('reads the most severe field when a bag names several', function () {
    // A bag can object to the recipient and the line at once. The safest reading has
    // to win, or a number no gateway can accept is offered to all of them.
    $this->configureGateway(driver: 'iranpayamak', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake(['*' => Http::response(iranPayamakInvalid([
        'line_number' => ['خط ارسال معتبر نیست'],
        'recipients' => ['شماره گیرنده معتبر نیست'],
    ]), 422)]);

    $attempt = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    expect($attempt->failure_kind)->toBe(FailureKind::InvalidRecipient)
        ->and($attempt->safe_to_failover)->toBeFalse();
});

it('refuses to fail over on a field this API does not document', function () {
    $this->configureGateway(driver: 'iranpayamak', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake(['*' => Http::response(iranPayamakInvalid(['some_new_field' => ['خطا']]), 422)]);

    $attempt = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    // We cannot say whose fault it is, so the message does not move on.
    expect($attempt->failure_kind)->toBe(FailureKind::GatewayRejected)
        ->and($attempt->safe_to_failover)->toBeFalse();
});

it('refuses to fail over on an error envelope with no field detail', function () {
    // ⚠️ This API publishes no catalogue mapping refusals to causes, so an
    // unexplained `error` could be an account problem another gateway would not
    // have, or a refusal of this exact message that every gateway would repeat.
    $this->configureGateway(driver: 'iranpayamak', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake(['*' => Http::response([
        'status' => 'error',
        'data' => null,
        'messages' => 'اعتبار کافی نیست',
    ], 200)]);

    $attempt = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->failure_kind)->toBe(FailureKind::GatewayRejected)
        ->and($attempt->safe_to_failover)->toBeFalse()
        // The provider's own sentence reaches the log, and decides nothing.
        ->and($attempt->error)->toContain('اعتبار کافی نیست');
});

it('does not read a list of sentences as a field called zero', function () {
    // `messages` is four shapes in one field and two of them are arrays. Reading keys
    // without checking would turn a list of sentences into a field named "0" — an
    // undocumented field, which happens to classify the same way, by accident rather
    // than on purpose.
    $this->configureGateway(driver: 'iranpayamak', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake(['*' => Http::response([
        'status' => 'error',
        'data' => null,
        'messages' => ['اعتبار کافی نیست', 'ارسال انجام نشد'],
    ], 422)]);

    $attempt = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    expect($attempt->failure_kind)->toBe(FailureKind::GatewayRejected)
        ->and($attempt->safe_to_failover)->toBeFalse()
        ->and($attempt->error)->not->toContain('invalid: 0');
});

it('refuses an error envelope that arrives inside a success status code', function () {
    // Reading the HTTP code alone, or the status field alone, is how a refusal gets
    // recorded as a send.
    $this->configureGateway(driver: 'iranpayamak', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake(['*' => Http::response(['status' => 'error', 'data' => null, 'messages' => null], 201)]);

    $attempt = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->failure_kind)->toBe(FailureKind::GatewayRejected);
});

it('treats a server error as uncertain and never re-sends it', function () {
    $this->configureGateway(driver: 'iranpayamak', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake(['*' => Http::response('upstream failure', 503)]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();
    $attempt = $message->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Uncertain)
        ->and($attempt->safe_to_failover)->toBeFalse()
        ->and($message->status->value)->toBe('unknown');
});

it('treats a dead connection as uncertain', function () {
    $this->configureGateway(driver: 'iranpayamak', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    $attempt = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Uncertain)
        ->and($attempt->failure_kind)->toBe(FailureKind::Network)
        ->and($attempt->safe_to_failover)->toBeFalse();
});

it('never lets the API key reach the stored error or the stored payload', function () {
    // A provider that echoes the request back would otherwise write a live key into
    // the attempt log, which is kept for as long as the log is.
    $this->configureGateway(
        driver: 'iranpayamak',
        mode: DeliveryMode::Text,
        body: 'Hello {customer_name}',
        credentials: ['api_key' => 'iranpayamak-secret-token'],
    );

    Http::fake(['*' => Http::response([
        'status' => 'error',
        'data' => null,
        'messages' => 'rejected request authorised with iranpayamak-secret-token',
        'echo' => ['headers' => ['Api-Key' => 'iranpayamak-secret-token']],
    ], 422)]);

    $attempt = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    expect($attempt->error)->not->toContain('iranpayamak-secret-token')
        ->and($attempt->error)->toContain('[redacted]')
        // The raw payload is persisted too, and is redacted just as thoroughly.
        ->and(json_encode($attempt->provider_payload))->not->toContain('iranpayamak-secret-token');
});

it('gives the API key every protection the other gateways get', function () {
    [$gateway] = $this->configureGateway(driver: 'iranpayamak', credentials: ['api_key' => 'iranpayamak-secret-token']);

    expect(SmsGateway::query()->getConnection()->table(TableNames::gateways())
        ->where('id', $gateway->getKey())->value('credentials'))
        ->not->toContain('iranpayamak-secret-token')
        ->and($gateway->toArray())->not->toHaveKey('credentials')
        ->and(print_r($gateway->config(), true))->not->toContain('iranpayamak-secret-token');
});
