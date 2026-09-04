<?php

declare(strict_types=1);

use Mizbanha\Sms\Contracts\OtpCodeGenerator;
use Mizbanha\Sms\Enums\DeliveryMode;
use Mizbanha\Sms\Exceptions\SmsException;
use Mizbanha\Sms\Facades\Otp;
use Mizbanha\Sms\Models\SmsGateway;
use Mizbanha\Sms\Models\SmsTemplate;
use Mizbanha\Sms\Models\SmsTemplateGateway;
use Mizbanha\Sms\Otp\OtpStatus;
use Mizbanha\Sms\Otp\RandomOtpCodeGenerator;
use Mizbanha\Sms\Support\TableNames;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One-time codes owned by this package.
 *
 * ⚠️ The point of package-owned OTP is that the code is ours, so it can be carried
 * by whichever gateway happens to be working — a provider-generated code exists
 * only inside that provider and cannot fail over at all. The test that proves it
 * is "delivers the same code through two different gateways".
 *
 * The public API never returns a code. Tests that need to see one bind a
 * deterministic generator, which is a seam for verification, not a way in.
 */
const OTP_CODE = '482193';

function fixedCode(string $code = OTP_CODE): void
{
    app()->bind(OtpCodeGenerator::class, fn (): OtpCodeGenerator => new class($code) implements OtpCodeGenerator
    {
        public function __construct(private readonly string $code) {}

        public function generate(int $length): string
        {
            return $this->code;
        }
    });

    // The manager is a singleton and takes the generator by constructor, so a
    // rebind mid-test has to drop the built instance to take effect.
    app()->forgetInstance(\Mizbanha\Sms\Otp\OtpManager::class);
    \Mizbanha\Sms\Facades\Otp::clearResolvedInstances();
}

/** An OTP template bound to one gateway, not marked sensitive on purpose. */
function otpTemplate(string $driver = 'smsir'): void
{
    test()->configureGateway(
        driver: $driver,
        mode: DeliveryMode::Text,
        body: 'Your login code is {code}.',
        templateKey: 'login-otp',
    );
}

function otpOk(): array
{
    return ['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [7]]])];
}

beforeEach(function (): void {
    fixedCode();
});

/*
|--------------------------------------------------------------------------
| Generation and storage
|--------------------------------------------------------------------------
*/

it('generates a numeric code of the configured length using secure randomness', function () {
    // The real generator, not the fixture. Leading zeros must survive, which is the
    // reason it builds a string digit by digit rather than padding an integer.
    $generator = new RandomOtpCodeGenerator;

    $codes = array_map(fn (): string => $generator->generate(6), range(1, 50));

    foreach ($codes as $code) {
        expect($code)->toMatch('/^\d{6}$/');
    }

    // Not proof of randomness — nothing in a test is — but it would catch a
    // generator that had quietly become a constant.
    expect(count(array_unique($codes)))->toBeGreaterThan(40);
});

it('never stores the code itself in the cache', function () {
    /*
     * ⚠️ The cache is shared infrastructure — a database table or a Redis instance
     * — and anything able to read it would otherwise be able to read live codes.
     */
    otpTemplate();
    Http::fake(otpOk());

    Otp::send('09121234567', 'login-otp');

    // Reach for the challenge by the key the manager computes.
    $key = 'sms:otp:challenge:'.hash('sha256', '+989121234567|login-otp');
    $found = Cache::get($key);

    expect($found)->toBeArray()
        ->and(json_encode($found))->not->toContain(OTP_CODE)
        ->and($found['hash'])->toBeString()
        ->and($found['attempts'])->toBe(0)
        // A one-way hash that verifies, so nothing is lost by not keeping digits.
        ->and(\Illuminate\Support\Facades\Hash::check(OTP_CODE, $found['hash']))->toBeTrue();
});

it('keeps the phone number out of the cache key', function () {
    /*
     * A key containing the number would make the cache an enumerable list of
     * everybody currently being sent a code — visible in `KEYS` output, in a
     * database table, and in monitoring tools.
     */
    otpTemplate();
    Http::fake(otpOk());

    Otp::send('09121234567', 'login-otp');

    $key = 'sms:otp:challenge:'.hash('sha256', '+989121234567|login-otp');

    expect(Cache::get($key))->not->toBeNull()
        ->and($key)->not->toContain('9121234567')
        ->and($key)->not->toContain('+98');
});

it('reaches the same challenge however the number was spelled', function () {
    // The canonical E.164 form is hashed, never the caller's spelling.
    otpTemplate();
    Http::fake(otpOk());

    Otp::send('09121234567', 'login-otp');

    expect(Otp::verify('+98 912 123 4567', OTP_CODE, 'login-otp'))->toBeTrue();
});

it('keeps separate challenges for separate purposes on one number', function () {
    /*
     * One person can be logging in and confirming a withdrawal at the same time.
     * Keyed by number alone, the second code would silently destroy the first.
     */
    otpTemplate();
    Http::fake(otpOk());

    Otp::send('09121234567', 'login-otp', purpose: 'login');

    fixedCode('111111');
    Otp::send('09121234567', 'login-otp', purpose: 'withdrawal');

    expect(Otp::verify('09121234567', OTP_CODE, 'login'))->toBeTrue()
        ->and(Otp::verify('09121234567', '111111', 'withdrawal'))->toBeTrue();
});

it('defaults the purpose to the template key', function () {
    otpTemplate();
    Http::fake(otpOk());

    Otp::send('09121234567', 'login-otp');

    expect(Otp::verify('09121234567', OTP_CODE, 'login-otp'))->toBeTrue();
});

it('refuses a caller that tries to supply the code', function () {
    /*
     * Either the caller believes it is choosing the code — making it as guessable
     * as whatever chose it — or it has a name collision that would send a
     * working-looking message containing no code at all. Both are invisible if
     * this quietly wins.
     */
    otpTemplate();

    expect(fn () => Otp::send('09121234567', 'login-otp', ['code' => '000000']))
        ->toThrow(SmsException::class, 'cannot be supplied by the caller');
});

it('allows other logical variables alongside the code', function () {
    otpTemplate();
    SmsTemplate::query()->where('key', 'login-otp')->update(['body' => 'Hi {customer_name}, code {code}.']);
    Http::fake(otpOk());

    Otp::send('09121234567', 'login-otp', ['customer_name' => 'Amid']);

    Http::assertSent(fn ($r): bool => $r['messageText'] === 'Hi Amid, code '.OTP_CODE.'.');
});

/*
|--------------------------------------------------------------------------
| Verification
|--------------------------------------------------------------------------
*/

it('accepts the correct code exactly once', function () {
    // ⚠️ Single use. A code that still works after it has been used is a code that
    // works for whoever else read the message.
    otpTemplate();
    Http::fake(otpOk());
    Otp::send('09121234567', 'login-otp');

    expect(Otp::verify('09121234567', OTP_CODE, 'login-otp'))->toBeTrue()
        ->and(Otp::verify('09121234567', OTP_CODE, 'login-otp'))->toBeFalse();
});

it('rejects a wrong code', function () {
    otpTemplate();
    Http::fake(otpOk());
    Otp::send('09121234567', 'login-otp');

    expect(Otp::verify('09121234567', '000000', 'login-otp'))->toBeFalse()
        // The real one still works: a wrong guess costs an attempt, not the code.
        ->and(Otp::verify('09121234567', OTP_CODE, 'login-otp'))->toBeTrue();
});

it('destroys the challenge after the attempt budget is spent', function () {
    otpTemplate();
    Http::fake(otpOk());
    Otp::send('09121234567', 'login-otp');

    foreach (range(1, 5) as $ignored) {
        expect(Otp::verify('09121234567', '000000', 'login-otp'))->toBeFalse();
    }

    // ⚠️ The correct code fails too, now. An attempt limit that the right answer
    // can walk past is not a limit.
    expect(Otp::verify('09121234567', OTP_CODE, 'login-otp'))->toBeFalse();
});

it('does not let a wrong guess extend the challenge', function () {
    /*
     * ⚠️ Re-storing the attempt counter with a fresh TTL would turn a three-minute
     * window into an unbounded one: guess, refresh, guess, refresh. The expiry is
     * kept as an absolute timestamp inside the challenge for exactly this.
     */
    otpTemplate();
    Http::fake(otpOk());
    Otp::send('09121234567', 'login-otp');

    $key = 'sms:otp:challenge:'.hash('sha256', '+989121234567|login-otp');
    $expiry = Cache::get($key)['expires_at'];

    $this->travel(60)->seconds();
    Otp::verify('09121234567', '000000', 'login-otp');

    expect(Cache::get($key)['expires_at'])->toBe($expiry)
        ->and(Cache::get($key)['attempts'])->toBe(1);
});

it('rejects an expired code', function () {
    otpTemplate();
    Http::fake(otpOk());
    Otp::send('09121234567', 'login-otp');

    $this->travel(181)->seconds();

    expect(Otp::verify('09121234567', OTP_CODE, 'login-otp'))->toBeFalse();
});

it('rejects a code for a challenge that never existed', function () {
    // Deliberately indistinguishable from a wrong code: a different answer here
    // would tell an attacker which numbers have been challenged.
    expect(Otp::verify('09121234567', OTP_CODE, 'login-otp'))->toBeFalse();
});

it('refuses a second consumer while one holds the verification lock', function () {
    /*
     * Two simultaneous submissions of the correct code must not both succeed. The
     * one that loses the lock is refused rather than made to wait.
     */
    otpTemplate();
    Http::fake(otpOk());
    Otp::send('09121234567', 'login-otp');

    $held = Cache::lock('sms:otp:lock:'.hash('sha256', '+989121234567|login-otp'), 10);
    expect($held->get())->toBeTrue();

    expect(Otp::verify('09121234567', OTP_CODE, 'login-otp'))->toBeFalse();

    $held->release();

    // And it is still valid once the other consumer is done.
    expect(Otp::verify('09121234567', OTP_CODE, 'login-otp'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Cooldown
|--------------------------------------------------------------------------
*/

it('refuses a resend inside the cooldown without generating anything', function () {
    /*
     * ⚠️ The cooldown is claimed before a code is generated. Generating first and
     * checking after would mint a code nobody could ever receive — and would
     * invalidate the one the person is currently looking at.
     */
    otpTemplate();
    Http::fake(otpOk());

    expect(Otp::send('09121234567', 'login-otp')->status)->toBe(OtpStatus::Sent);

    $second = Otp::send('09121234567', 'login-otp');

    expect($second->status)->toBe(OtpStatus::Cooldown)
        // The configured interval, not the true age of the challenge: a countdown
        // that varied would say when a code was last sent to this number.
        ->and($second->retryAfter)->toBe(90)
        ->and($second->message)->toBeNull();

    Http::assertSentCount(1);

    // The first code is untouched and still valid.
    expect(Otp::verify('09121234567', OTP_CODE, 'login-otp'))->toBeTrue();
});

it('lets only one of two concurrent requests issue a code', function () {
    // Proven through the atomic cooldown claim rather than by racing threads: the
    // second caller loses because the key already exists, not because of timing.
    otpTemplate();
    Http::fake(otpOk());

    $first = Otp::send('09121234567', 'login-otp');

    $held = Cache::lock('sms:otp:lock:'.hash('sha256', '+989121234567|login-otp'), 10);
    expect($held->get())->toBeTrue();

    $second = Otp::send('09121234567', 'login-otp');
    $held->release();

    expect($first->status)->toBe(OtpStatus::Sent)
        ->and($second->status)->toBe(OtpStatus::Cooldown);
    Http::assertSentCount(1);
});

it('issues a new code after the cooldown and invalidates the old one', function () {
    otpTemplate();
    Http::fake(otpOk());

    Otp::send('09121234567', 'login-otp');

    $this->travel(91)->seconds();
    fixedCode('777777');

    expect(Otp::send('09121234567', 'login-otp')->status)->toBe(OtpStatus::Sent)
        // ⚠️ Exactly one live code per number and purpose. The previous one dies
        // the moment its replacement is issued.
        ->and(Otp::verify('09121234567', OTP_CODE, 'login-otp'))->toBeFalse()
        ->and(Otp::verify('09121234567', '777777', 'login-otp'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Delivery lifecycle
|--------------------------------------------------------------------------
*/

it('keeps the challenge when a gateway accepts', function () {
    otpTemplate();
    Http::fake(otpOk());

    $result = Otp::send('09121234567', 'login-otp');

    expect($result->status)->toBe(OtpStatus::Sent)
        ->and($result->message->status->value)->toBe('accepted')
        ->and(Otp::verify('09121234567', OTP_CODE, 'login-otp'))->toBeTrue();
});

it('keeps the challenge when delivery is uncertain', function () {
    /*
     * ⚠️ The message may well have reached the handset. Destroying the challenge
     * would leave somebody holding a code this package has decided to forget, which
     * is worse than a code that goes unused.
     */
    otpTemplate();
    Http::fake(['*' => fn () => throw new ConnectionException('timed out')]);

    $result = Otp::send('09121234567', 'login-otp');

    expect($result->status)->toBe(OtpStatus::Unknown)
        ->and($result->message->status->value)->toBe('unknown')
        ->and(Otp::verify('09121234567', OTP_CODE, 'login-otp'))->toBeTrue();
});

it('destroys the challenge and releases the cooldown when delivery definitively fails', function () {
    /*
     * Nothing was delivered, provably. Making somebody wait ninety seconds for a
     * code that never left would be punishing them for the provider's failure.
     */
    otpTemplate();

    // A sequence rather than two fakes: a second wildcard stub does not replace the
    // first, and the whole scenario is one refusal followed by one success.
    Http::fakeSequence()
        ->push(['status' => 0, 'message' => 'refused'])
        ->push(['status' => 1, 'data' => ['messageIds' => [7]]]);

    $result = Otp::send('09121234567', 'login-otp');

    expect($result->status)->toBe(OtpStatus::Failed)
        ->and(Otp::verify('09121234567', OTP_CODE, 'login-otp'))->toBeFalse();

    // And the caller may try again at once rather than waiting out a cooldown.
    expect(Otp::send('09121234567', 'login-otp')->status)->toBe(OtpStatus::Sent);
});

it('leaves no usable challenge when the master switch is off', function () {
    // Nothing reached a phone, so nothing should be verifiable. A live challenge
    // here would be a code nobody was ever sent.
    config()->set('laravel-sms.enabled', false);
    otpTemplate();
    Http::fake();

    $result = Otp::send('09121234567', 'login-otp');

    expect($result->status)->toBe(OtpStatus::Suppressed)
        ->and($result->message->status->value)->toBe('suppressed')
        ->and(Otp::verify('09121234567', OTP_CODE, 'login-otp'))->toBeFalse();
    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Failover, and the reason this package owns the code
|--------------------------------------------------------------------------
*/

it('delivers the same code through two different gateways', function () {
    /*
     * ⚠️ The test that justifies package-owned OTP.
     *
     * The first gateway refuses in a way that is safe to move on from, and the
     * second carries the message. Both requests contain the SAME code, because the
     * code belongs to this package rather than to a provider — a provider-generated
     * one would exist only inside gateway A and could not be delivered by gateway B
     * at all. Nothing is regenerated on failover, and the code that went out still
     * verifies afterwards.
     */
    $template = SmsTemplate::query()->create([
        'key' => 'login-otp', 'name' => 'Login', 'body' => 'Your login code is {code}.',
    ]);

    foreach ([['kavenegar', 'first', 10], ['smsir', 'second', 20]] as [$driver, $key, $priority]) {
        $gateway = new SmsGateway;
        $gateway->forceFill([
            'key' => $key, 'label' => $key, 'driver' => $driver, 'sender' => '30001234',
            'credentials' => ['api_key' => 'a-real-looking-key'],
            'is_enabled' => true, 'priority' => $priority,
        ])->save();

        SmsTemplateGateway::query()->create([
            'sms_template_id' => $template->getKey(),
            'sms_gateway_id' => $gateway->getKey(),
            'mode' => DeliveryMode::Text,
            'is_enabled' => true,
        ]);
    }

    Http::fake([
        // 401: this account's credentials, not this message. Safe to move on.
        'api.kavenegar.com/*' => Http::response([], 401),
        'api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageIds' => [7]]]),
    ]);

    $result = Otp::send('09121234567', 'login-otp');

    expect($result->status)->toBe(OtpStatus::Sent)
        ->and($result->message->attempts()->count())->toBe(2);

    // ⚠️ The same digits reached both providers, in each one's own request shape.
    Http::assertSent(fn ($r): bool => str_contains($r->url(), 'kavenegar')
        && str_contains((string) $r->body(), OTP_CODE));
    Http::assertSent(fn ($r): bool => str_contains($r->url(), 'sms.ir')
        && $r['messageText'] === 'Your login code is '.OTP_CODE.'.');

    expect(Otp::verify('09121234567', OTP_CODE, 'login-otp'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| An OTP is always sensitive
|--------------------------------------------------------------------------
*/

it('forces sensitivity even when the template is not marked', function () {
    /*
     * ⚠️ OTP safety must not depend on somebody remembering to tick a box on a
     * template row. The template here is deliberately NOT marked sensitive.
     */
    otpTemplate();
    expect(SmsTemplate::query()->where('key', 'login-otp')->value('is_sensitive'))->toBeFalsy();

    Http::fake(['*' => Http::response([
        'status' => 1,
        'data' => ['messageIds' => [7]],
        'echo' => ['messageText' => 'Your login code is '.OTP_CODE.'.'],
    ])]);

    $message = Otp::send('09121234567', 'login-otp')->message;
    $attempt = $message->attempts()->first();

    expect($message->is_sensitive)->toBeTrue()
        ->and($message->body)->toBeNull()
        ->and($message->variables)->toBeNull()
        ->and($attempt->provider_payload)->toBeNull();

    // And nothing anywhere in either row.
    expect(json_encode(DB::table(TableNames::messages())->get()))->not->toContain(OTP_CODE)
        ->and(json_encode(DB::table(TableNames::attempts())->get()))->not->toContain(OTP_CODE);
});

it('keeps the code out of a provider error that quoted it', function () {
    otpTemplate();

    Http::fake(['*' => Http::response([
        'status' => 0,
        'message' => 'rejected: Your login code is '.OTP_CODE.'.',
    ])]);

    Otp::send('09121234567', 'login-otp');

    expect(json_encode(DB::table(TableNames::attempts())->get()))->not->toContain(OTP_CODE);
});

it('keeps the code out of the log driver output', function () {
    otpTemplate(driver: 'log');

    $written = [];
    Log::listen(function ($event) use (&$written): void {
        $written[] = json_encode($event->context);
    });

    Otp::send('09121234567', 'login-otp');

    expect($written)->not->toBeEmpty()
        ->and(implode('', $written))->not->toContain(OTP_CODE)
        ->and(implode('', $written))->toContain('sensitive content omitted');
});

it('never returns the code from the public result', function () {
    /*
     * ⚠️ A result object carrying the code would put it in every stack trace,
     * every dd(), and every exception reporter's context payload — which is exactly
     * where the rest of this milestone works to keep it out of.
     */
    otpTemplate();
    Http::fake(otpOk());

    $result = Otp::send('09121234567', 'login-otp');

    expect(json_encode($result))->not->toContain(OTP_CODE)
        ->and(print_r($result, true))->not->toContain(OTP_CODE);
});
