<?php

declare(strict_types=1);

use Amid\Sms\Gateways\GatewayConfig;
use Amid\Sms\Models\SmsGateway;
use Illuminate\Support\Facades\DB;

/**
 * Credentials moved into the database so they can be managed at runtime. These are
 * the tests that make that decision survivable.
 */
it('stores credentials encrypted rather than as readable text', function () {
    [$gateway] = $this->configureGateway(credentials: ['api_key' => 'super-secret-key']);

    $raw = DB::table('sms_gateways')->where('id', $gateway->getKey())->value('credentials');

    expect($raw)->not->toContain('super-secret-key')
        // Still readable through the model, which is the only way in.
        ->and($gateway->fresh()->credentials)->toBe(['api_key' => 'super-secret-key']);
});

it('keeps credentials out of every serialisation of the gateway', function () {
    // toArray and toJson are what a log line, a queue payload and a dumped model
    // all go through. One missing $hidden entry is a credential in a log file.
    [$gateway] = $this->configureGateway(credentials: ['api_key' => 'super-secret-key']);

    expect($gateway->toArray())->not->toHaveKey('credentials')
        ->and($gateway->toJson())->not->toContain('super-secret-key');
});

it('keeps credential values out of a dumped driver configuration', function () {
    $config = new GatewayConfig('primary', '3000', ['api_key' => 'super-secret-key']);

    $dumped = print_r($config, true);

    expect($dumped)->not->toContain('super-secret-key')
        ->and($dumped)->toContain('[redacted]');
});

it('names a missing credential without revealing the ones that are present', function () {
    $config = new GatewayConfig('primary', '3000', ['api_key' => 'super-secret-key']);

    expect(fn () => $config->requireCredential('password'))
        ->toThrow(
            \Amid\Sms\Exceptions\GatewayNotConfigured::class,
            'SMS gateway [primary] is missing the [password] credential.',
        );

    try {
        $config->requireCredential('password');
    } catch (\Throwable $exception) {
        expect($exception->getMessage())->not->toContain('super-secret-key');
    }
});

it('strips every configured secret out of arbitrary provider text', function () {
    // Providers echo requests, and transport errors quote URLs - and two of the
    // four Iranian providers put the key in the URL.
    $config = new GatewayConfig('primary', null, ['api_key' => 'key-123', 'password' => 'pw-456']);

    expect($config->redact('failed calling https://x/key-123/send with pw-456'))
        ->toBe('failed calling https://x/[redacted]/send with [redacted]');
});

it('creates gateways disabled unless they are switched on deliberately', function () {
    // A gateway arriving from a seeder, a restored database or a half-finished form
    // must not begin carrying real traffic because somebody forgot a step.
    $gateway = new SmsGateway;
    $gateway->forceFill(['key' => 'new', 'label' => 'New', 'driver' => 'log'])->save();

    expect($gateway->fresh()->is_enabled)->toBeFalse();
});
