<?php

declare(strict_types=1);

use Amid\Sms\Enums\DeliveryMode;
use Amid\Sms\Enums\FailureKind;
use Amid\Sms\Enums\SendOutcome;
use Amid\Sms\Facades\Sms;
use Amid\Sms\Models\SmsGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * IPPanel, against the current Edge API.
 *
 * Response shapes here are the ones the official documentation publishes:
 * a success envelope of `data.message_outbox_ids` plus `meta.message_code` of
 * "200-1", and validation failures keyed by field name.
 */
function ippanelOk(int $id = 1123544244): array
{
    return [
        'data' => ['message_outbox_ids' => [$id]],
        'meta' => ['status' => true, 'message' => 'انجام شد', 'message_code' => '200-1'],
    ];
}

it('sends a text message in the documented webservice shape', function () {
    $this->configureGateway(driver: 'ippanel', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake(['*' => Http::response(ippanelOk())]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    expect($message->status->value)->toBe('accepted');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://edge.ippanel.com/v1/api/send'
            && $request['sending_type'] === 'webservice'
            && $request['message'] === 'Hello Amid'
            && $request['from_number'] === '30001234'
            // Nested under params for this sending type, and E.164 with the plus:
            // this is the one provider that takes our stored form unchanged.
            && $request['params'] === ['recipients' => ['+989121234567']];
    });
});

it('sends a pattern with recipients at the top level and named values in params', function () {
    // The asymmetry between the two bodies is the thing most easily got wrong.
    $this->configureGateway(
        driver: 'ippanel',
        mode: DeliveryMode::Pattern,
        body: 'Hi {customer_name}, order {order_number}.',
        patternCode: 'abc123xyz',
        parameterMap: [
            ['provider' => 'name', 'variable' => 'customer_name'],
            ['provider' => 'order', 'variable' => 'order_number'],
        ],
    );

    Http::fake(['*' => Http::response(ippanelOk())]);

    Sms::to('09121234567')->template('order-created')
        ->with(['customer_name' => 'Amid', 'order_number' => 'CF-1204'])
        ->send();

    Http::assertSent(function (Request $request): bool {
        return $request['sending_type'] === 'pattern'
            && $request['code'] === 'abc123xyz'
            && $request['recipients'] === ['+989121234567']
            && $request['params'] === ['name' => 'Amid', 'order' => 'CF-1204'];
    });
});

it('carries a logical template through IPPanel without the caller knowing its parameter names', function () {
    // The same call an application already makes for the other two providers. The
    // provider's vocabulary lives entirely in the binding row.
    $this->configureGateway(
        driver: 'ippanel',
        mode: DeliveryMode::Pattern,
        body: 'Hi {customer_name}, order {order_number}.',
        patternCode: 'abc123xyz',
        parameterMap: [
            ['provider' => 'ip_customer', 'variable' => 'customer_name'],
            ['provider' => 'ip_order', 'variable' => 'order_number'],
        ],
    );

    Http::fake(['*' => Http::response(ippanelOk())]);

    Sms::to('09121234567')->template('order-created')
        ->with(['customer_name' => 'Amid', 'order_number' => 'CF-1204'])
        ->send();

    Http::assertSent(fn (Request $r): bool => $r['params'] === [
        'ip_customer' => 'Amid',
        'ip_order' => 'CF-1204',
    ]);
});

it('sends one recipient even though the webservice endpoint accepts a list', function () {
    // Core's contract, not the provider's limit: a batch answers with one status
    // for everybody in it, and our log has a row per destination.
    $this->configureGateway(driver: 'ippanel', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake(['*' => Http::response(ippanelOk())]);

    Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    Http::assertSent(fn (Request $r): bool => count($r['params']['recipients']) === 1);
});

it('honours a base URL override for a gateway on the same API at another address', function () {
    // The only white-label support offered: another host, the same documented
    // contract. No brand list, no guessing.
    [$gateway] = $this->configureGateway(driver: 'ippanel', mode: DeliveryMode::Text, body: 'Hello {customer_name}');
    $gateway->forceFill(['options' => ['url' => 'https://api.example-reseller.ir/v1']])->save();

    Http::fake(['*' => Http::response(ippanelOk())]);

    Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.example-reseller.ir/v1/api/send');
});

it('authenticates with a bare Authorization header and no scheme', function () {
    // Adding "Bearer" produces a 401 that reads like a wrong key.
    $this->configureGateway(
        driver: 'ippanel',
        mode: DeliveryMode::Text,
        body: 'Hello {customer_name}',
        credentials: ['api_key' => 'ippanel-secret-token'],
    );

    Http::fake(['*' => Http::response(ippanelOk())]);

    Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    Http::assertSent(fn (Request $r): bool => $r->header('Authorization') === ['ippanel-secret-token']);
});

it('stores the outbox id that the report API will later be queried with', function () {
    $this->configureGateway(driver: 'ippanel', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake(['*' => Http::response(ippanelOk(5544778899))]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    // GET {base_url}/api/report/by_bulk?messages_outbox_id=... takes exactly this.
    expect($message->attempts()->first()->provider_message_id)->toBe('5544778899');
});

it('classifies an authentication failure as a gateway configuration problem', function () {
    // Not a bad message: the logical SMS is fine and another gateway should have
    // it, but repeating it here will fail identically until someone edits the key.
    $this->configureGateway(driver: 'ippanel', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake(['*' => Http::response([
        'meta' => ['status' => false, 'message' => 'اطلاعات وارد شده صحیح نمی باشد', 'message_code' => '400-1'],
    ], 401)]);

    $attempt = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->failure_kind)->toBe(FailureKind::GatewayConfiguration)
        ->and($attempt->retryable_on_same_gateway)->toBeFalse()
        ->and($attempt->safe_to_failover)->toBeTrue();
});

it('classifies a recipient validation error as an invalid recipient and refuses to fail over', function () {
    // Read from the FIELD NAME, never from the Persian sentence beside it. A number
    // this provider will not take is a number the next one will not take either, so
    // failing over would be a loop ending in the same refusal everywhere.
    $this->configureGateway(driver: 'ippanel', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake(['*' => Http::response([
        'meta' => [
            'status' => false,
            'message' => ['params.recipients' => ['شماره گیرنده معتبر نیست']],
            'message_code' => '400-2',
        ],
    ], 422)]);

    $attempt = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->failure_kind)->toBe(FailureKind::InvalidRecipient)
        ->and($attempt->safe_to_failover)->toBeFalse()
        ->and($attempt->retryable_on_same_gateway)->toBeFalse()
        // The reason names the field, not the prose.
        ->and($attempt->error)->toContain('recipients');
});

it('classifies an invalid pattern code as this gateway refusing, and allows failover', function () {
    // A pattern is registered per account. Another gateway may hold a perfectly
    // good registration for the same logical message.
    $this->configureGateway(
        driver: 'ippanel',
        mode: DeliveryMode::Pattern,
        body: 'Hi {customer_name}.',
        patternCode: 'wrong-code',
        parameterMap: [['provider' => 'name', 'variable' => 'customer_name']],
    );

    Http::fake(['*' => Http::response([
        'meta' => [
            'status' => false,
            'message' => ['code' => ['الگوی مورد نظر یافت نشد']],
            'message_code' => '400-2',
        ],
    ], 422)]);

    $attempt = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->failure_kind)->toBe(FailureKind::GatewayRejected)
        ->and($attempt->safe_to_failover)->toBeTrue();
});

it('refuses to fail over on a non-success body it cannot explain', function () {
    $this->configureGateway(driver: 'ippanel', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake(['*' => Http::response([
        'meta' => ['status' => false, 'message' => 'اعتبار کافی نیست', 'message_code' => '400-9'],
    ])]);

    $attempt = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    // There is no published catalogue mapping this provider's codes to causes, so
    // "not 200-1" could be an account problem another gateway would not have, or a
    // refusal of this exact message that every gateway would repeat. Guessing the
    // optimistic way turns one refusal into one refusal per gateway.
    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->failure_kind)->toBe(FailureKind::GatewayRejected)
        ->and($attempt->safe_to_failover)->toBeFalse();
});

it('treats a server error as uncertain and never re-sends it', function () {
    $this->configureGateway(driver: 'ippanel', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake(['*' => Http::response('upstream failure', 503)]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();
    $attempt = $message->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Uncertain)
        ->and($attempt->safe_to_failover)->toBeFalse()
        ->and($message->status->value)->toBe('unknown');
});

it('treats a dead connection as uncertain', function () {
    $this->configureGateway(driver: 'ippanel', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    $attempt = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Uncertain)
        ->and($attempt->failure_kind)->toBe(FailureKind::Network)
        ->and($attempt->safe_to_failover)->toBeFalse();
});

it('never lets the API token reach the stored error or the stored payload', function () {
    // A provider that echoes the request back would otherwise write a live token
    // into the attempt log, which is kept for as long as the log is.
    $this->configureGateway(
        driver: 'ippanel',
        mode: DeliveryMode::Text,
        body: 'Hello {customer_name}',
        credentials: ['api_key' => 'ippanel-secret-token'],
    );

    Http::fake(['*' => Http::response([
        'meta' => [
            'status' => false,
            'message' => 'rejected request authorised with ippanel-secret-token',
            'message_code' => '400-9',
            'echo' => ['headers' => ['Authorization' => 'ippanel-secret-token']],
        ],
    ])]);

    $attempt = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    expect($attempt->error)->not->toContain('ippanel-secret-token')
        ->and($attempt->error)->toContain('[redacted]')
        // The raw payload is persisted too, and is redacted just as thoroughly.
        ->and(json_encode($attempt->provider_payload))->not->toContain('ippanel-secret-token');
});

it('gives the token every protection the other gateways get', function () {
    [$gateway] = $this->configureGateway(driver: 'ippanel', credentials: ['api_key' => 'ippanel-secret-token']);

    expect(SmsGateway::query()->getConnection()->table('sms_gateways')
        ->where('id', $gateway->getKey())->value('credentials'))
        ->not->toContain('ippanel-secret-token')
        ->and($gateway->toArray())->not->toHaveKey('credentials')
        ->and(print_r($gateway->config(), true))->not->toContain('ippanel-secret-token');
});
