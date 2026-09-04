<?php

declare(strict_types=1);

use Mizbanha\Sms\Enums\CountryPolicy;
use Mizbanha\Sms\Enums\DeliveryMode;
use Mizbanha\Sms\Enums\FailureKind;
use Mizbanha\Sms\Enums\MessageStatus;
use Mizbanha\Sms\Exceptions\InvalidCountryCoverage;
use Mizbanha\Sms\Facades\Sms;
use Mizbanha\Sms\Models\SmsGateway;
use Mizbanha\Sms\Models\SmsTemplate;
use Mizbanha\Sms\Models\SmsTemplateGateway;
use Illuminate\Support\Facades\Http;

/**
 * Country-aware routing: which gateways are even offered a destination.
 *
 * ⚠️ **Everything here is routing, not failure.** A gateway filtered out by
 * geography is never called, records no attempt, and is not a provider failure —
 * so these tests assert on what was NOT contacted and on the absence of attempt
 * rows, not on rejections. An attempt row is evidence about a provider, and a log
 * full of "Twilio refused an Iranian number" from a gateway nobody expected to
 * carry Iranian numbers would be worse than no row at all.
 *
 * The distinction that runs through the file: **configured coverage** is a fact
 * about what an account is for, decided by an administrator; **provider refusal**
 * is a fact discovered at runtime. Neither substitutes for the other, and the last
 * test proves the second still works after this milestone added the first.
 */

/**
 * A template carried by several gateways, each with its own country coverage.
 *
 * @param  list<array{0: string, 1: string, 2: CountryPolicy, 3: list<string>, 4: int}>  $gateways
 *         [driver, key, policy, countries, priority]
 */
function coverageChain(array $gateways): SmsTemplate
{
    $template = SmsTemplate::query()->create([
        'key' => 'order-created',
        'name' => 'Order created',
        'body' => 'Hello {customer_name}',
    ]);

    foreach ($gateways as [$driver, $key, $policy, $countries, $priority]) {
        $gateway = new SmsGateway;
        $gateway->forceFill([
            'key' => $key,
            'label' => $key,
            'driver' => $driver,
            'sender' => '+15005550006',
            // ⚠️ Deliberately not one-character values. Redaction is a substring
            // replacement, so a credential of 'u' would rewrite every 'u' in a
            // stored provider id. Real credentials are long; test ones should be
            // too, or the fixture invents a failure the package does not have.
            'credentials' => [
                'api_key' => 'kavenegar-key',
                'username' => 'meli-user',
                'password' => 'meli-pass',
                'account_sid' => 'ACtest0000000000000000000000000001',
                'auth_token' => 'twilio-secret-token',
            ],
            'is_enabled' => true,
            'priority' => $priority,
            'country_policy' => $policy,
        ]);
        // Through the mutator, because normalising and validating the list is part
        // of what is under test.
        $gateway->countries = $countries;
        $gateway->save();

        SmsTemplateGateway::query()->create([
            'sms_template_id' => $template->getKey(),
            'sms_gateway_id' => $gateway->getKey(),
            'mode' => DeliveryMode::Text,
            'is_enabled' => true,
        ]);
    }

    return $template;
}

function sendTo(string $to)
{
    return Sms::to($to)->template('order-created')->with(['customer_name' => 'Amid'])->send();
}

/** The shapes each provider answers with, so a chain can mix them. */
function providerResponses(): array
{
    return [
        'api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageIds' => [11]]]),
        'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 22]]]),
        'api.twilio.com/*' => Http::response(['sid' => 'SMcountryrouted0001', 'status' => 'queued'], 201),
    ];
}

/*
|--------------------------------------------------------------------------
| The policies
|--------------------------------------------------------------------------
*/

it('serves every country when no coverage is configured', function () {
    // The default, and the behaviour of every gateway written before this feature
    // existed. Adding the column must change nothing for anybody who ignores it.
    $gateway = new SmsGateway;

    expect($gateway->country_policy)->toBe(CountryPolicy::All)
        ->and($gateway->serves('IR'))->toBeTrue()
        ->and($gateway->serves('US'))->toBeTrue()
        ->and($gateway->serves(null))->toBeTrue();
});

it('serves only the listed countries under an allow policy', function () {
    $gateway = new SmsGateway;
    $gateway->country_policy = CountryPolicy::Allow;
    $gateway->countries = ['IR'];

    expect($gateway->serves('IR'))->toBeTrue()
        ->and($gateway->serves('US'))->toBeFalse()
        // ⚠️ An allow-list says where a gateway is KNOWN to work. A destination
        // with no country has not been vouched for by it.
        ->and($gateway->serves(null))->toBeFalse();
});

it('serves everywhere except the listed countries under a deny policy', function () {
    $gateway = new SmsGateway;
    $gateway->country_policy = CountryPolicy::Deny;
    $gateway->countries = ['IR', 'SY', 'CU'];

    expect($gateway->serves('US'))->toBeTrue()
        ->and($gateway->serves('IR'))->toBeFalse()
        ->and($gateway->serves('SY'))->toBeFalse()
        // ⚠️ A deny-list says where a gateway is known NOT to work. A destination
        // with no country is not on it.
        ->and($gateway->serves(null))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
*/

it('normalises a country list on the way in', function () {
    // Stored the way the router compares, so nothing has to normalise at match time.
    $gateway = new SmsGateway;
    $gateway->countries = [' ir ', 'IR', 'us', 'Us'];

    expect($gateway->countries)->toBe(['IR', 'US']);
});

it('refuses a malformed country code', function (mixed $code) {
    $gateway = new SmsGateway;

    expect(fn () => $gateway->countries = [$code])->toThrow(InvalidCountryCoverage::class);
})->with([
    'a country name' => ['IRAN'],
    'a lowercase country name' => ['iran'],
    'digits' => ['123'],
    'one letter' => ['I'],
    'empty' => [''],
    'the non-geographic pseudo-region' => ['001'],
]);

it('refuses a two-letter code that is not a real region', function () {
    /*
     * ⚠️ The check that earns its keep. `UK` is a plausible thing to type, passes
     * every shape test, and is not an ISO 3166-1 code — the United Kingdom is `GB`.
     * Accepted silently it becomes an allow-list that matches nothing, forever,
     * with no error anywhere to explain why that gateway never sends.
     */
    $gateway = new SmsGateway;

    expect(fn () => $gateway->countries = ['UK'])
        ->toThrow(InvalidCountryCoverage::class, 'United Kingdom is [GB]');

    $gateway->countries = ['GB'];
    expect($gateway->countries)->toBe(['GB']);
});

it('refuses an unknown country policy by name', function () {
    $gateway = new SmsGateway;

    expect(fn () => $gateway->country_policy = 'sometimes')
        ->toThrow(InvalidCountryCoverage::class, 'all, allow, deny');

    // Case is forgiven; the three words are not negotiable.
    $gateway->country_policy = 'DENY';
    expect($gateway->country_policy)->toBe(CountryPolicy::Deny);
});

/*
|--------------------------------------------------------------------------
| The snapshot
|--------------------------------------------------------------------------
*/

it('records the destination country on the message', function (string $to, ?string $expected) {
    $this->configureGateway(driver: 'log');

    expect(sendTo($to)->country_code)->toBe($expected);
})->with([
    'Iranian national' => ['09121234567', 'IR'],
    'Iranian E.164' => ['+989121234567', 'IR'],
    'US' => ['+14155552671', 'US'],
    'UAE' => ['+971501234567', 'AE'],
    'Persian digits' => ['۰۹۱۲۱۲۳۴۵۶۷', 'IR'],
    // ⚠️ A valid number belonging to no country. Not guessed, not defaulted to the
    // configured region — recorded as having none.
    'non-geographic' => ['+883510000000000', null],
]);

it('refuses an unusable number before any of this is reached', function () {
    // Country routing changes nothing about when a bad number is caught: still at
    // the caller, still before a row exists.
    $this->configureGateway(driver: 'log');

    expect(fn () => sendTo('nonsense'))->toThrow(\Mizbanha\Sms\Exceptions\InvalidRecipient::class);
});

it('routes on the recorded snapshot rather than re-deriving the country', function () {
    /*
     * The reason the snapshot exists. The router is given what was written when the
     * message was recorded, so a message released back onto the queue days later
     * routes exactly as it would have at the time — and the country that drove the
     * decision is visible in the log beside the attempts it produced.
     */
    coverageChain([
        ['smsir', 'iran-only', CountryPolicy::Allow, ['IR'], 10],
        ['twilio', 'international', CountryPolicy::Deny, ['IR'], 20],
    ]);

    Http::fake(providerResponses());

    $message = sendTo('+989121234567');

    expect($message->country_code)->toBe('IR')
        ->and($message->attempts()->first()->gateway_key)->toBe('iran-only');
});

/*
|--------------------------------------------------------------------------
| Routing
|--------------------------------------------------------------------------
*/

it('never contacts a gateway that denies the destination country', function () {
    /*
     * ⚠️ The case this milestone exists for.
     *
     * Twilio is first by priority and would be tried first. It cannot deliver to
     * Iran at all — its own documentation says not to retry expecting Geo
     * Permissions to fix it — so asking it and reading the refusal would be a
     * wasted round trip, a misleading attempt row, and a provider failure recorded
     * against a gateway that was never meant to carry this.
     */
    coverageChain([
        ['twilio', 'international', CountryPolicy::Deny, ['IR', 'SY', 'CU'], 1],
        ['smsir', 'iranian', CountryPolicy::Allow, ['IR'], 2],
    ]);

    Http::fake(providerResponses());

    $message = sendTo('+989121234567');
    $attempts = $message->attempts()->orderBy('sequence')->get();

    expect($message->status)->toBe(MessageStatus::Accepted)
        // Not first among the attempts — absent from them entirely.
        ->and($attempts)->toHaveCount(1)
        ->and($attempts[0]->gateway_key)->toBe('iranian')
        ->and($attempts[0]->sequence)->toBe(1);

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'twilio'));
    Http::assertSentCount(1);
});

it('never contacts an Iran-only gateway for an international destination', function () {
    // The same rule the other way round, and the reason coverage is configuration
    // rather than a special case about Iran.
    coverageChain([
        ['smsir', 'iranian', CountryPolicy::Allow, ['IR'], 1],
        ['twilio', 'international', CountryPolicy::Deny, ['IR', 'SY', 'CU'], 2],
    ]);

    Http::fake(providerResponses());

    $message = sendTo('+14155552671');
    $attempts = $message->attempts()->orderBy('sequence')->get();

    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and($attempts)->toHaveCount(1)
        ->and($attempts[0]->gateway_key)->toBe('international')
        ->and($attempts[0]->provider_message_id)->toBe('SMcountryrouted0001');

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'sms.ir'));
});

it('keeps priority order among the gateways that do serve the destination', function () {
    // Geography filters; it does not reorder. Priority still decides among what is
    // left, and the ineligible gateway in the middle is simply not there.
    coverageChain([
        ['twilio', 'not-for-iran', CountryPolicy::Deny, ['IR'], 1],
        ['smsir', 'second-choice', CountryPolicy::All, [], 30],
        ['kavenegar', 'first-choice', CountryPolicy::Allow, ['IR'], 20],
    ]);

    Http::fake(providerResponses());

    $message = sendTo('+989121234567');

    expect($message->attempts()->first()->gateway_key)->toBe('first-choice');
    Http::assertSentCount(1);
});

it('fails over only among gateways that serve the destination', function () {
    /*
     * The two mechanisms working together. The first eligible gateway refuses in a
     * way that is safe to move on from, so M2 failover does what it always did —
     * and the geographically ineligible gateway between them is not a step in that
     * chain. It never was a candidate.
     */
    coverageChain([
        ['smsir', 'eligible-first', CountryPolicy::Allow, ['IR'], 10],
        ['twilio', 'not-for-iran', CountryPolicy::Deny, ['IR'], 20],
        ['kavenegar', 'eligible-second', CountryPolicy::All, [], 30],
    ]);

    Http::fake([
        // A credentials problem: this account's, not this message's.
        'api.sms.ir/*' => Http::response([], 401),
        'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 22]]]),
        'api.twilio.com/*' => Http::response(['sid' => 'SMshouldnothappen', 'status' => 'queued'], 201),
    ]);

    $message = sendTo('+989121234567');
    $attempts = $message->attempts()->orderBy('sequence')->get();

    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and($attempts->pluck('gateway_key')->all())->toBe(['eligible-first', 'eligible-second']);

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'twilio'));
});

it('fails a message no gateway is configured to serve, without contacting anybody', function () {
    // A configuration state an operator can read off the row, not a provider
    // failure and not an exception thrown at whoever triggered the send.
    coverageChain([
        ['smsir', 'iran-only', CountryPolicy::Allow, ['IR'], 10],
        ['kavenegar', 'also-iran-only', CountryPolicy::Allow, ['IR'], 20],
    ]);

    Http::fake(providerResponses());

    $message = sendTo('+14155552671');

    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->attempts)->toHaveCount(0)
        ->and($message->error)->toContain('No eligible gateway');

    Http::assertNothingSent();
});

it('applies each policy to a destination with no country', function () {
    // The three modes against a valid non-geographic number, end to end.
    coverageChain([
        ['smsir', 'allow-ir', CountryPolicy::Allow, ['IR'], 10],
        ['kavenegar', 'deny-ir', CountryPolicy::Deny, ['IR'], 20],
    ]);

    Http::fake(providerResponses());

    $message = sendTo('+883510000000000');

    expect($message->country_code)->toBeNull()
        // The allow-list did not vouch for it; the deny-list did not exclude it.
        ->and($message->attempts()->first()->gateway_key)->toBe('deny-ir');
});

/*
|--------------------------------------------------------------------------
| Coverage is not permission
|--------------------------------------------------------------------------
*/

it('still lets a provider refuse a destination its gateway is configured for', function () {
    /*
     * ⚠️ The line this milestone must not blur.
     *
     * Configured coverage says what an account is FOR. It cannot say what a
     * provider will actually accept: a Twilio gateway configured for the UAE is
     * routed to correctly, and Twilio may still refuse with 21408 because that
     * account's Messaging Geo Permissions are off. That is a real attempt, a real
     * structured error, and it fails over exactly as it did in M3.
     *
     * If country routing had swallowed this, an administrator would be left with a
     * gateway that looks correctly configured and silently sends nothing.
     */
    coverageChain([
        ['twilio', 'international', CountryPolicy::Deny, ['IR'], 10],
        ['kavenegar', 'fallback', CountryPolicy::All, [], 20],
    ]);

    Http::fake([
        'api.twilio.com/*' => Http::response([
            'code' => 21408,
            'message' => 'Permission to send an SMS has not been enabled for the region indicated by the To number',
        ], 400),
        'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 22]]]),
    ]);

    $message = sendTo('+971501234567');
    $attempts = $message->attempts()->orderBy('sequence')->get();

    expect($message->country_code)->toBe('AE')
        // Routed to, contacted, refused, recorded — and then failed over.
        ->and($attempts)->toHaveCount(2)
        ->and($attempts[0]->gateway_key)->toBe('international')
        ->and($attempts[0]->failure_kind)->toBe(FailureKind::GatewayConfiguration)
        ->and($attempts[0]->safe_to_failover)->toBeTrue()
        ->and($message->status)->toBe(MessageStatus::Accepted);
});
