<?php

declare(strict_types=1);

use Mizbanha\Sms\Enums\Capability;
use Mizbanha\Sms\Exceptions\GatewayNotConfigured;
use Mizbanha\Sms\Gateways\GatewayRegistry;
use Mizbanha\Sms\Models\SmsGateway;
use Mizbanha\Sms\Tests\Support\ConfigSpyDriver;

/**
 * The registry: the one place a Driver is built.
 *
 * Two things are proved here, and the second is a correctness fix rather than a
 * feature.
 *
 * `capabilitiesFor()` answers what a driver can do when asked by NAME, before any
 * gateway names it — the question a management layer has to ask to know which modes
 * a binding may use. It must come from the driver itself, because a list maintained
 * anywhere else is a second source of truth that will eventually disagree with the
 * code that actually sends.
 *
 * ⚠️ And driver instances are no longer memoised. They were, keyed on the gateway's
 * primary key — which does not change when its credentials do, so a gateway
 * re-credentialed inside one request handed the next caller a driver still carrying
 * the old key. The tests below are the regression.
 */
function registry(): GatewayRegistry
{
    return app(GatewayRegistry::class);
}

/** A gateway using the spy driver, saved. */
function spyGateway(array $attributes = []): SmsGateway
{
    config()->set('laravel-sms.drivers.spy', ConfigSpyDriver::class);

    $gateway = new SmsGateway;
    $gateway->forceFill([
        'key' => 'spy-gateway',
        'label' => 'Spy',
        'driver' => 'spy',
        'sender' => '30001234',
        'credentials' => ['api_key' => 'first-key'],
        'options' => ['marker' => 'one'],
        'is_enabled' => true,
        ...$attributes,
    ])->save();

    return $gateway;
}

// ---------------------------------------------------------------------------
// capabilitiesFor()
// ---------------------------------------------------------------------------

it('reports a registered driver capabilities by name', function () {
    expect(registry()->capabilitiesFor('twilio'))
        ->toBe([Capability::Text, Capability::DeliveryReport])
        ->and(registry()->capabilitiesFor('kavenegar'))
        ->toBe([Capability::Text, Capability::Pattern])
        ->and(registry()->capabilitiesFor('ippanel'))
        ->toBe([Capability::Text, Capability::Pattern, Capability::DeliveryReport]);
});

it('answers for every registered driver without a gateway, a credential or a request', function () {
    Illuminate\Support\Facades\Http::fake();

    foreach (registry()->registered() as $driver) {
        expect(registry()->capabilitiesFor($driver))->toBeArray()->not->toBeEmpty();
    }

    // ⚠️ Constructing a driver to ask it a question must contact nobody.
    Illuminate\Support\Facades\Http::assertNothingSent();
    expect(SmsGateway::query()->count())->toBe(0);
});

it('agrees with the driver a real gateway resolves to', function () {
    $gateway = spyGateway();

    expect(registry()->capabilitiesFor('spy'))
        ->toBe(registry()->driverFor($gateway)->capabilities());
});

it('tolerates surrounding whitespace in a driver name', function () {
    expect(registry()->capabilitiesFor('  twilio  '))->toBe([Capability::Text, Capability::DeliveryReport]);
});

it('names an unregistered driver rather than guessing', function () {
    expect(fn () => registry()->capabilitiesFor('nonesuch'))
        ->toThrow(GatewayNotConfigured::class, "SMS driver [nonesuch] is not registered in config('laravel-sms.drivers').");
});

it('refuses a registration that is not a driver', function () {
    config()->set('laravel-sms.drivers.bogus', stdClass::class);

    expect(fn () => registry()->capabilitiesFor('bogus'))
        ->toThrow(GatewayNotConfigured::class, 'which is not a Driver');
});

it('keeps the gateway-shaped message for a gateway that names a missing driver', function () {
    $gateway = new SmsGateway;
    $gateway->forceFill(['key' => 'orphan', 'label' => 'Orphan', 'driver' => 'gone', 'is_enabled' => true])->save();

    expect(fn () => registry()->driverFor($gateway))
        ->toThrow(GatewayNotConfigured::class, 'SMS gateway [orphan] names driver [gone], which is not registered.');
});

// ---------------------------------------------------------------------------
// ⚠️ The stale-instance regression
// ---------------------------------------------------------------------------

it('builds a driver that observes a credential changed during the same request', function () {
    $gateway = spyGateway();

    $before = registry()->driverFor($gateway)->fingerprint();

    // The edit a management layer performs: save, then immediately resolve again
    // to test the gateway. Nothing is forgotten, invalidated or re-bound.
    $gateway->credentials = ['api_key' => 'rotated-key'];
    $gateway->save();

    $after = registry()->driverFor($gateway->refresh())->fingerprint();

    // ⚠️ Digests only. The credential itself never reaches an assertion, a failure
    // message or the test output.
    expect($after)->not->toBe($before);
});

it('builds a driver that observes a changed sender and options in the same request', function () {
    $gateway = spyGateway();

    expect(registry()->driverFor($gateway)->sender())->toBe('30001234')
        ->and(registry()->driverFor($gateway)->marker())->toBe('one');

    $gateway->forceFill(['sender' => '20009999', 'options' => ['marker' => 'two']])->save();

    expect(registry()->driverFor($gateway->refresh())->sender())->toBe('20009999')
        ->and(registry()->driverFor($gateway->refresh())->marker())->toBe('two');
});

it('does not hand one gateway the driver built for another with the same identity', function () {
    $first = spyGateway();
    $second = spyGateway(['key' => 'second-gateway', 'credentials' => ['api_key' => 'second-key']]);

    expect(registry()->driverFor($first)->gatewayKey())->toBe('spy-gateway')
        ->and(registry()->driverFor($second)->gatewayKey())->toBe('second-gateway')
        ->and(registry()->driverFor($first)->fingerprint())
        ->not->toBe(registry()->driverFor($second)->fingerprint());
});

it('returns a fresh instance every time, so nothing can hold state between sends', function () {
    $gateway = spyGateway();

    expect(registry()->driverFor($gateway))->not->toBe(registry()->driverFor($gateway));
});
