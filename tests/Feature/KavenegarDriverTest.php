<?php

declare(strict_types=1);

use Amid\Sms\Enums\DeliveryMode;
use Amid\Sms\Enums\FailureKind;
use Amid\Sms\Enums\SendOutcome;
use Amid\Sms\Facades\Sms;
use Amid\Sms\Models\SmsMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The POSITIONAL half of the parameter-mapping proof, plus the provider
 * restrictions that only exist at this gateway.
 */
beforeEach(function () {
    $this->configureGateway(
        driver: 'kavenegar',
        mode: DeliveryMode::Pattern,
        body: 'Hi {customer_name}, order {order_number} for {total}.',
        patternCode: 'order-created',
        parameterMap: [
            ['provider' => 'token', 'variable' => 'customer_name'],
            ['provider' => 'token2', 'variable' => 'order_number'],
            ['provider' => 'token3', 'variable' => 'total'],
        ],
    );

    $this->variables = [
        'customer_name' => 'Amid',
        'order_number' => 'CF-1204',
        'total' => '1850000',
    ];
});

it('sends pattern parameters positionally as token, token2, token3', function () {
    Http::fake([
        '*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 8899]]]),
    ]);

    $message = Sms::to('09121234567')->template('order-created')->with($this->variables)->send();

    expect($message->status->value)->toBe('accepted');

    Http::assertSent(function (Request $request): bool {
        // The mapping named them token/token2/token3 and the ORDER carries the
        // meaning: this provider matches them against wording it approved.
        return $request['token'] === 'Amid'
            && $request['token2'] === 'CF-1204'
            && $request['token3'] === '1850000'
            && $request['receptor'] === '09121234567'
            && str_contains($request->url(), '/verify/lookup.json');
    });
});

it('replaces spaces inside a parameter because the gateway rejects them', function () {
    Http::fake(['*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 1]]])]);

    Sms::to('09121234567')->template('order-created')->with([
        ...$this->variables,
        'customer_name' => 'Amid Esfahani',
    ])->send();

    // Kavenegar refuses the whole request when a token contains a space, with a
    // status that does not say which token was at fault. A hyphen is a worse name
    // and a delivered message; a space is a correct name and no message at all.
    Http::assertSent(fn (Request $request): bool => $request['token'] === 'Amid-Esfahani');
});

it('refuses more than three pattern parameters as a gateway-level rejection', function () {
    // The provider's hard ceiling. What matters is not that it is refused but HOW:
    // the logical message is fine, so this must stay failable-over rather than be
    // marked undeliverable everywhere.
    $this->configureGateway(
        driver: 'kavenegar',
        mode: DeliveryMode::Pattern,
        body: 'A {one} B {two} C {three} D {four}',
        patternCode: 'four-parameters',
        parameterMap: [
            ['provider' => 'token', 'variable' => 'one'],
            ['provider' => 'token2', 'variable' => 'two'],
            ['provider' => 'token3', 'variable' => 'three'],
            ['provider' => 'token4', 'variable' => 'four'],
        ],
        key: 'four',
        templateKey: 'four-parameters',
    );

    Http::fake();

    $message = Sms::to('09121234567')->template('four-parameters')
        ->with(['one' => '1', 'two' => '2', 'three' => '3', 'four' => '4'])
        ->send();

    $attempt = $message->attempts()->first();

    expect($message->status->value)->toBe('failed')
        ->and($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->failure_kind)->toBe(FailureKind::GatewayRejected)
        ->and($attempt->safe_to_failover)->toBeTrue()
        ->and($attempt->retryable_on_same_gateway)->toBeFalse();

    // Refused before anything left the machine.
    Http::assertNothingSent();
});

it('reads a refusal that arrives inside an HTTP 200', function () {
    // This gateway answers 200 even when it refused; the verdict is in the body.
    // A driver that trusted the status code would record a refusal as a success.
    Http::fake([
        '*' => Http::response(['return' => ['status' => 411, 'message' => 'Invalid receptor']]),
    ]);

    $message = Sms::to('09121234567')->template('order-created')->with($this->variables)->send();
    $attempt = $message->attempts()->first();

    expect($message->status->value)->toBe('failed')
        ->and($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->failure_kind)->toBe(FailureKind::GatewayRejected)
        ->and($attempt->error)->toContain('Invalid receptor')
        // Not failable over: this package has no verified mapping from this
        // provider's status numbers to causes, so it cannot tell an account problem
        // from a refusal every gateway would repeat.
        ->and($attempt->safe_to_failover)->toBeFalse();
});

it('never writes the API key into a stored error', function () {
    // This provider carries the key in the request URL, so any provider text that
    // echoes the request is a credential leak into the attempt log.
    Http::fake([
        '*' => Http::response([
            'return' => [
                'status' => 401,
                'message' => 'bad request to https://api.kavenegar.com/v1/test-key/verify/lookup.json',
            ],
        ]),
    ]);

    $message = Sms::to('09121234567')->template('order-created')->with($this->variables)->send();

    expect($message->attempts()->first()->error)
        ->not->toContain('test-key')
        ->toContain('[redacted]');
});
