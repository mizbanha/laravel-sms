<?php

declare(strict_types=1);

use Amid\Sms\Enums\FailureKind;
use Amid\Sms\Exceptions\SmsException;
use Amid\Sms\Health\CircuitBreaker;
use Amid\Sms\Health\CircuitState;
use Amid\Sms\Models\SmsGateway;
use Amid\Sms\Results\SendResult;
use Illuminate\Support\Facades\Cache;

/**
 * The gateway circuit breaker, on its own.
 *
 * ⚠️ It answers one question — should this application temporarily avoid calling
 * this gateway, because recent TRANSPORT evidence is bad — and the tests that
 * matter most here are the ones proving what it refuses to answer. A refused
 * recipient, a rejected credential and an unregistered pattern are all failures,
 * and none of them is evidence that a gateway cannot be reached.
 *
 * ⚠️ It is local evidence about one account. Nothing here claims to know that a
 * provider is down; a rate-limited account looks identical from this side.
 *
 * Nothing sleeps. Time is travelled, and the array cache store honours travelled
 * time for its own TTLs, so expiry is exercised rather than assumed.
 */
function circuitBreaker(): CircuitBreaker
{
    return app(CircuitBreaker::class);
}

function circuitGateway(string $key = 'primary'): SmsGateway
{
    [$gateway] = test()->configureGateway(driver: 'smsir', key: $key);

    return $gateway;
}

/** A qualifying transport failure: the request did not complete. */
function healthFailure(): SendResult
{
    return SendResult::uncertain(FailureKind::Network, 'the gateway could not be reached');
}

/** The other qualifying one: the provider said it could not deal with this now. */
function providerBusy(): SendResult
{
    return SendResult::rejected(FailureKind::ProviderUnavailable, 'rate limited');
}

function circuitOk(): SendResult
{
    return SendResult::accepted('provider-id-1');
}

function tripCircuit(SmsGateway $gateway): void
{
    foreach (range(1, 3) as $ignored) {
        circuitBreaker()->record($gateway, healthFailure());
    }
}

/*
|--------------------------------------------------------------------------
| Counting
|--------------------------------------------------------------------------
*/

it('starts closed and lets everything through', function () {
    $gateway = circuitGateway();

    expect(circuitBreaker()->status($gateway)->state)->toBe(CircuitState::Closed)
        ->and(circuitBreaker()->status($gateway)->failures)->toBe(0)
        ->and(circuitBreaker()->status($gateway)->openUntil)->toBeNull()
        ->and(circuitBreaker()->allows($gateway))->toBeTrue();
});

it('does not open on one failure', function () {
    // A single timeout is weather. Opening on it would take a gateway out of
    // service for a minute every time a packet went missing.
    $gateway = circuitGateway();

    circuitBreaker()->record($gateway, healthFailure());

    expect(circuitBreaker()->status($gateway)->state)->toBe(CircuitState::Closed)
        ->and(circuitBreaker()->status($gateway)->failures)->toBe(1)
        ->and(circuitBreaker()->allows($gateway))->toBeTrue();
});

it('opens once the threshold is reached inside the window', function () {
    $gateway = circuitGateway();

    tripCircuit($gateway);

    $status = circuitBreaker()->status($gateway);

    expect($status->state)->toBe(CircuitState::Open)
        ->and($status->isAvailable())->toBeFalse()
        ->and($status->openUntil?->getTimestamp())->toBe(now()->getTimestamp() + 60)
        // The streak has done its work; the count is not carried into the open
        // state where it would only be confusing.
        ->and($status->failures)->toBe(0)
        ->and(circuitBreaker()->allows($gateway))->toBeFalse();
});

it('counts both qualifying kinds and nothing else', function (SendResult $result, bool $opens) {
    $gateway = circuitGateway();

    foreach (range(1, 3) as $ignored) {
        circuitBreaker()->record($gateway, $result);
    }

    expect(circuitBreaker()->status($gateway)->state)
        ->toBe($opens ? CircuitState::Open : CircuitState::Closed)
        // Zero either way, for two different reasons: a neutral result never
        // counted, and an opened circuit has already spent its streak.
        ->and(circuitBreaker()->status($gateway)->failures)->toBe(0);
})->with([
    /*
     * ⚠️ Evidence about the GATEWAY. The request did not complete, or the provider
     * said it could not deal with this now.
     */
    'network' => [fn () => healthFailure(), true],
    'provider unavailable' => [fn () => providerBusy(), true],

    /*
     * ⚠️ Evidence about the MESSAGE, or about configuration. Every one of these is
     * a real failure and none of them says the gateway cannot be reached — several
     * are decided locally before a request is even built. Counting them would take
     * a perfectly healthy gateway out of service because somebody sent three
     * messages to a landline.
     */
    'invalid recipient' => [fn () => SendResult::rejected(FailureKind::InvalidRecipient, 'not a mobile'), false],
    'invalid message' => [fn () => SendResult::rejected(FailureKind::InvalidMessage, 'body too long'), false],
    'gateway rejected' => [fn () => SendResult::rejected(FailureKind::GatewayRejected, 'pattern not registered'), false],
    'gateway configuration' => [fn () => SendResult::rejected(FailureKind::GatewayConfiguration, 'bad credential'), false],
]);

it('does not accumulate failures spread across separate windows', function () {
    /*
     * ⚠️ One outage on Monday, one on Wednesday, one on Friday is a gateway that
     * works. A counter with no window would open on the third of them, weeks apart,
     * having proved nothing at all.
     */
    $gateway = circuitGateway();

    foreach (range(1, 3) as $ignored) {
        circuitBreaker()->record($gateway, healthFailure());
        test()->travel(61)->seconds();
    }

    expect(circuitBreaker()->status($gateway)->state)->toBe(CircuitState::Closed);
});

it('resets a partial streak when the gateway accepts a message', function () {
    // Proof, and the only proof this class takes: the gateway was reached,
    // understood us and took the message.
    $gateway = circuitGateway();

    circuitBreaker()->record($gateway, healthFailure());
    circuitBreaker()->record($gateway, healthFailure());
    circuitBreaker()->record($gateway, circuitOk());
    circuitBreaker()->record($gateway, healthFailure());
    circuitBreaker()->record($gateway, healthFailure());

    expect(circuitBreaker()->status($gateway)->state)->toBe(CircuitState::Closed)
        ->and(circuitBreaker()->status($gateway)->failures)->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Half-open
|--------------------------------------------------------------------------
*/

it('becomes half-open once the cooldown has passed', function () {
    $gateway = circuitGateway();
    tripCircuit($gateway);

    test()->travel(61)->seconds();

    expect(circuitBreaker()->status($gateway)->state)->toBe(CircuitState::HalfOpen)
        // Half-open is available: somebody has to find out.
        ->and(circuitBreaker()->status($gateway)->isAvailable())->toBeTrue();
});

it('admits exactly one probe at a time', function () {
    /*
     * ⚠️ The reason half-open exists. "The cooldown expired, so everybody calls it
     * again" is how a provider that is just coming back is knocked over by the
     * traffic that was waiting for it — and by then this application has spent a
     * full timeout on every one of those messages.
     */
    $gateway = circuitGateway();
    tripCircuit($gateway);
    test()->travel(61)->seconds();

    expect(circuitBreaker()->allows($gateway))->toBeTrue()
        // A second caller, concurrent with the first, is sent to the gateway behind
        // this one instead of piling up.
        ->and(circuitBreaker()->allows($gateway))->toBeFalse()
        ->and(circuitBreaker()->allows($gateway))->toBeFalse();
});

it('closes when the probe succeeds', function () {
    $gateway = circuitGateway();
    tripCircuit($gateway);
    test()->travel(61)->seconds();

    circuitBreaker()->allows($gateway);
    circuitBreaker()->record($gateway, circuitOk());

    expect(circuitBreaker()->status($gateway)->state)->toBe(CircuitState::Closed)
        ->and(circuitBreaker()->status($gateway)->openUntil)->toBeNull()
        ->and(circuitBreaker()->allows($gateway))->toBeTrue();
});

it('reopens immediately when the probe fails the same way', function () {
    /*
     * ⚠️ Straight back to open, without waiting for the threshold again. The
     * gateway has now failed the same way three times and then once more when
     * asked politely; making two more messages prove it would be spending their
     * latency to learn nothing.
     */
    $gateway = circuitGateway();
    tripCircuit($gateway);
    test()->travel(61)->seconds();

    circuitBreaker()->allows($gateway);
    circuitBreaker()->record($gateway, healthFailure());

    $status = circuitBreaker()->status($gateway);

    expect($status->state)->toBe(CircuitState::Open)
        ->and($status->openUntil?->getTimestamp())->toBe(now()->getTimestamp() + 60)
        ->and(circuitBreaker()->allows($gateway))->toBeFalse();
});

it('releases the probe without claiming recovery when the result proves nothing', function () {
    /*
     * ⚠️ The subtle one. A probe that came back "invalid recipient" tells us
     * nothing about whether the provider recovered — the message was wrong, not the
     * gateway. Closing on it would declare a still-broken gateway healthy; counting
     * it as a health failure would punish the gateway for our bad number. So: the
     * reservation is released, the circuit stays half-open, and the next suitable
     * message becomes the probe.
     */
    $gateway = circuitGateway();
    tripCircuit($gateway);
    test()->travel(61)->seconds();

    circuitBreaker()->allows($gateway);
    circuitBreaker()->record($gateway, SendResult::rejected(FailureKind::InvalidRecipient, 'not a mobile'));

    expect(circuitBreaker()->status($gateway)->state)->toBe(CircuitState::HalfOpen)
        ->and(circuitBreaker()->allows($gateway))->toBeTrue();
});

it('recovers from a probe that was never reported', function () {
    /*
     * A worker killed mid-probe holds a reservation nobody will ever release. Its
     * TTL is what stops that becoming a permanent outage worse than the one that
     * opened the circuit.
     */
    $gateway = circuitGateway();
    tripCircuit($gateway);
    test()->travel(61)->seconds();

    expect(circuitBreaker()->allows($gateway))->toBeTrue();

    // The process dies here: no record() is ever called.
    app()->forgetInstance(CircuitBreaker::class);

    expect(circuitBreaker()->allows($gateway))->toBeFalse();

    test()->travel(31)->seconds();

    expect(circuitBreaker()->allows($gateway))->toBeTrue();
});

it('never lets a probe reservation expire while its own request is still running', function () {
    /*
     * ⚠️ `probe_ttl` is floored at the HTTP budget one call can actually take. A
     * reservation shorter than the request it protects would expire mid-flight,
     * admit a second probe, and produce exactly the pile-up on a recovering
     * provider that half-open exists to prevent.
     */
    config()->set('laravel-sms.circuit_breaker.probe_ttl', 1);
    config()->set('laravel-sms.http.timeout', 15);
    config()->set('laravel-sms.http.connect_timeout', 5);

    $gateway = circuitGateway();
    tripCircuit($gateway);
    test()->travel(61)->seconds();

    expect(circuitBreaker()->allows($gateway))->toBeTrue();

    app()->forgetInstance(CircuitBreaker::class);
    test()->travel(10)->seconds();

    // Still held, despite the configured 1 second.
    expect(circuitBreaker()->allows($gateway))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Identity, configuration and the public primitives
|--------------------------------------------------------------------------
*/

it('keeps one circuit for one persisted configuration', function () {
    /*
     * The identity has to be stable across model instances, or every process would
     * be measuring its own private circuit and none of them would ever open. What
     * makes it stable is that the material is the raw column values: two instances
     * loaded from the same row hash identically.
     */
    $gateway = circuitGateway();
    tripCircuit($gateway);

    expect(circuitBreaker()->allows($gateway->fresh()))->toBeFalse()
        ->and(circuitBreaker()->allows(SmsGateway::query()->where('key', 'primary')->firstOrFail()))->toBeFalse()
        // Reading the row again, or saving it with nothing changed but a
        // non-transport column, is not a configuration change.
        ->and(circuitBreaker()->status($gateway->fresh())->state)->toBe(CircuitState::Open);
});

it('gives a reconfigured gateway a fresh circuit, in the same second', function (string $column, mixed $value) {
    /*
     * ⚠️ The reason circuit identity is a fingerprint of the configuration rather
     * than its `updated_at`. A gateway whose credentials were wrong opens its
     * circuit; an administrator then fixes them — and must not have to find a
     * "reset health" button before the fix takes effect.
     *
     * ⚠️ **No time is travelled in this test, and that is the point.** A
     * timestamp with second resolution assumes two meaningful writes can never land
     * inside one tick, which is an assumption about a column's precision and about
     * how fast somebody clicks Save. Content decides identity now, so the question
     * does not arise.
     */
    $gateway = circuitGateway();
    tripCircuit($gateway);

    expect(circuitBreaker()->allows($gateway))->toBeFalse();

    $gateway->forceFill([$column => $value])->save();

    expect(circuitBreaker()->status($gateway->fresh())->state)->toBe(CircuitState::Closed)
        ->and(circuitBreaker()->allows($gateway->fresh()))->toBeTrue();
})->with([
    'credentials' => ['credentials', ['api_key' => 'the-corrected-key']],
    'driver' => ['driver', 'kavenegar'],
    'sender' => ['sender', '30009999'],
    'options' => ['options', ['url' => 'https://another.host']],
    'country policy' => ['country_policy', 'allow'],
    'countries' => ['countries', ['IR', 'AE']],
]);

it('keeps the circuit when identical credentials are saved again', function () {
    /*
     * Encryption is not deterministic — the same plaintext encrypts to different
     * ciphertext every time — so hashing the stored ciphertext could in principle
     * churn the identity on every save. It does not: Laravel compares encrypted
     * attributes by their decrypted values, finds nothing dirty, and writes
     * nothing, so the persisted material is untouched and the circuit survives.
     *
     * ⚠️ And if it were otherwise, the failure would be the harmless one: a gateway
     * measured again from scratch. The direction that must never happen is stale
     * evidence about old configuration blocking configuration that has since been
     * corrected, which is the test above.
     */
    $gateway = circuitGateway();
    tripCircuit($gateway);

    $gateway->forceFill(['credentials' => ['api_key' => 'test-key']])->save();

    expect(circuitBreaker()->status($gateway->fresh())->state)->toBe(CircuitState::Open);
});

it('leaves the circuit alone when a non-transport column changes', function () {
    // Priority and enabled state are routing, not transport. They say nothing about
    // whether the provider is answering, so they do not throw away evidence that it
    // is not.
    $gateway = circuitGateway();
    tripCircuit($gateway);

    $gateway->forceFill(['priority' => 999])->save();

    expect(circuitBreaker()->status($gateway->fresh())->state)->toBe(CircuitState::Open);
});

it('resets a circuit without touching any configuration', function () {
    $gateway = circuitGateway();
    $gateway->forceFill(['priority' => 42, 'country_policy' => 'allow', 'countries' => ['IR']])->save();

    tripCircuit($gateway);
    expect(circuitBreaker()->allows($gateway))->toBeFalse();

    circuitBreaker()->reset($gateway);

    $fresh = $gateway->fresh();

    expect(circuitBreaker()->status($fresh)->state)->toBe(CircuitState::Closed)
        ->and(circuitBreaker()->allows($fresh))->toBeTrue()
        // ⚠️ It clears an observation and nothing else. Resetting a circuit says
        // "try again now", not "I have fixed it" — and certainly not "enable this".
        ->and($fresh->is_enabled)->toBeTrue()
        ->and($fresh->priority)->toBe(42)
        ->and($fresh->credentials)->toBe(['api_key' => 'test-key'])
        ->and($fresh->country_policy->value)->toBe('allow')
        ->and($fresh->countries)->toBe(['IR']);
});

it('keeps nothing secret or personal in a cache key', function () {
    /*
     * ⚠️ Cache keys turn up in KEYS output, in a listable database table and in
     * monitoring tools. A gateway's numeric id and the second its row was last
     * saved are neither secret nor personal; a credential, a sender, a recipient or
     * a message is every one of those things.
     */
    $gateway = circuitGateway();
    $gateway->forceFill(['credentials' => ['api_key' => 'super-secret-key'], 'sender' => '+15005550006'])->save();

    tripCircuit($gateway);
    test()->travel(61)->seconds();
    circuitBreaker()->allows($gateway);

    $storage = new ReflectionProperty(Cache::store('array')->getStore(), 'storage');
    $keys = implode(' ', array_keys($storage->getValue(Cache::store('array')->getStore())));

    // The stored ciphertext must not appear either: the key carries a one-way
    // digest of it, never the material.
    $ciphertext = (string) $gateway->fresh()->getAttributes()['credentials'];

    expect($keys)->toContain('sms:circuit:')
        ->not->toContain($ciphertext)
        ->not->toContain(substr($ciphertext, 0, 24))
        ->not->toContain('super-secret-key')
        ->not->toContain('test-key')
        ->not->toContain('15005550006')
        ->not->toContain('09121234567')
        ->not->toContain('989121234567')
        ->not->toContain('order-created')
        ->not->toContain('Hello');
});

it('does nothing at all when it is switched off', function () {
    config()->set('laravel-sms.circuit_breaker.enabled', false);

    $gateway = circuitGateway();
    tripCircuit($gateway);

    expect(circuitBreaker()->allows($gateway))->toBeTrue()
        ->and(circuitBreaker()->status($gateway)->state)->toBe(CircuitState::Closed);
});

it('refuses a configuration that cannot mean anything', function (string $setting) {
    // A threshold of zero opens a circuit that was never closed; a window or
    // cooldown of zero is a breaker that remembers nothing. Both are worth naming
    // at the moment somebody makes the mistake.
    config()->set('laravel-sms.circuit_breaker.'.$setting, 0);

    tripCircuit(circuitGateway());
})->with(['failure_threshold', 'failure_window', 'cooldown'])->throws(SmsException::class);
