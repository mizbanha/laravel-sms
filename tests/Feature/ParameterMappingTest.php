<?php

declare(strict_types=1);

use Amid\Sms\Enums\DeliveryMode;
use Amid\Sms\Enums\FailureKind;
use Amid\Sms\Enums\SendOutcome;
use Amid\Sms\Facades\Sms;
use Amid\Sms\Models\SmsTemplateGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The order of pattern parameters, and the fact that nothing is allowed to change
 * it between being written and being sent.
 *
 * ⚠️ **Every mapping in this file is deliberately in the wrong alphabetical
 * order.** The names are `z_customer`, `a_code`, `m_amount`, in that positional
 * order, so that alphabetical order, reverse alphabetical order, key length order
 * and insertion order are all different answers. A test written as
 * `token1, token2, token3` proves nothing here: it passes under every ordering a
 * storage engine could impose, which is exactly why the defect this file exists
 * for survived M1 and M2.
 *
 * ⚠️ **Every test reloads the binding from the database before sending.** The
 * point is not what a PHP array does in memory — PHP array order was never in
 * doubt — but what survives a write and a read. `parameter_map` used to be a JSON
 * OBJECT keyed by provider parameter, and MySQL normalises the key order of a JSON
 * object when it stores one: sorted by key length, then bytewise. SQLite keeps the
 * text exactly as written, so the same code that silently swapped a customer's name
 * for their order number in production passed the whole suite locally.
 *
 * ⚠️ **Since M8 these tests run on both engines.** They were written on SQLite,
 * which is the one engine that could never have caught the original defect, and
 * the whole file is now executed against a real MySQL 8.4 as well:
 *
 *     SMS_TEST_DB=mysql SMS_TEST_DB_PORT=33306 vendor/bin/pest
 *
 * The last test in this file states the engine it ran on, so a passing run says
 * which claim was actually proved rather than leaving it to be inferred from the
 * column type.
 */

/**
 * A mapping whose positional order is nothing like its alphabetical order.
 *
 * @return list<array{provider?: string|null, variable: string}>
 */
function scrambled(bool $named = true): array
{
    return $named
        ? [
            ['provider' => 'z_first', 'variable' => 'z_customer'],
            ['provider' => 'a_second', 'variable' => 'a_code'],
            ['provider' => 'm_third', 'variable' => 'm_amount'],
        ]
        : [
            ['variable' => 'z_customer'],
            ['variable' => 'a_code'],
            ['variable' => 'm_amount'],
        ];
}

/** @return array<string, string> */
function scrambledValues(): array
{
    return ['z_customer' => 'Amid', 'a_code' => 'CF-1204', 'm_amount' => '250000'];
}

/**
 * Send after forcing the binding to come back out of the database.
 *
 * The reload is the entire point: an in-memory array would prove nothing about
 * what the column preserves.
 */
function sendReloaded()
{
    SmsTemplateGateway::query()->get()->each->refresh();

    return Sms::to('09121234567')->template('order-created')->with(scrambledValues())->send();
}

it('stores a mapping as an ordered list and reads it back in that order', function () {
    [, , $binding] = $this->configureGateway(
        driver: 'kavenegar',
        mode: DeliveryMode::Pattern,
        body: 'Hi {z_customer}, {a_code} for {m_amount}.',
        patternCode: 'order-created',
        parameterMap: scrambled(),
    );

    $stored = $binding->fresh()->parameter_map;

    // A JSON array, not an object: there are no provider parameters acting as keys
    // for a storage engine to sort.
    expect(array_is_list($stored))->toBeTrue()
        ->and(array_column($stored, 'variable'))->toBe(['z_customer', 'a_code', 'm_amount'])
        ->and(array_column($stored, 'provider'))->toBe(['z_first', 'a_second', 'm_third']);

    // And the raw column really is a JSON array, whatever the cast makes of it.
    // Straight off the connection, past the model cast, so what is asserted is the
    // bytes in the column.
    $raw = DB::table('sms_template_gateways')->where('id', $binding->getKey())->value('parameter_map');

    expect(json_decode($raw, true))->toBe($stored)
        ->and(str_starts_with(trim($raw), '['))->toBeTrue();
});

it('sends Kavenegar tokens in configured order, not alphabetical order', function () {
    // Kavenegar's parameters are positional in the strictest sense: token, token2,
    // token3 are decided by where a value sits, so a reordering here is a customer
    // being told their name is 250000.
    $this->configureGateway(
        driver: 'kavenegar',
        mode: DeliveryMode::Pattern,
        body: 'Hi {z_customer}, {a_code} for {m_amount}.',
        patternCode: 'order-created',
        parameterMap: scrambled(),
    );

    Http::fake(['*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 1]]])]);

    sendReloaded();

    Http::assertSent(function (Request $request): bool {
        return $request['token'] === 'Amid'
            && $request['token2'] === 'CF-1204'
            && $request['token3'] === '250000';
    });
});

it('sends Melipayamak values joined in configured order, not alphabetical order', function () {
    // The same hazard with no names at all to hide behind: one delimited string in
    // which position is the only thing identifying a value.
    $this->configureGateway(
        driver: 'melipayamak',
        mode: DeliveryMode::Pattern,
        body: 'Hi {z_customer}, {a_code} for {m_amount}.',
        patternCode: '48123',
        parameterMap: scrambled(named: false),
        credentials: ['username' => 'u', 'password' => 'p'],
    );

    // A recId of the documented successful shape: more than fifteen digits.
    Http::fake(['*' => Http::response(['Value' => '9006312345678901', 'RetStatus' => 1])]);

    sendReloaded();

    Http::assertSent(fn (Request $r): bool => $r['text'] === 'Amid;CF-1204;250000');
});

it('still names parameters correctly at a named provider after the reload', function () {
    // The representation change must not cost the named providers anything: the
    // provider's own registered names still arrive, attached to the right values.
    $this->configureGateway(
        driver: 'smsir',
        mode: DeliveryMode::Pattern,
        body: 'Hi {z_customer}, {a_code} for {m_amount}.',
        patternCode: '100200',
        parameterMap: scrambled(),
    );

    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageId' => 7]])]);

    sendReloaded();

    Http::assertSent(function (Request $request): bool {
        return $request['parameters'] === [
            ['name' => 'z_first', 'value' => 'Amid'],
            ['name' => 'a_second', 'value' => 'CF-1204'],
            ['name' => 'm_third', 'value' => '250000'],
        ];
    });
});

it('sends IPPanel named parameters as an object after the reload', function () {
    $this->configureGateway(
        driver: 'ippanel',
        mode: DeliveryMode::Pattern,
        body: 'Hi {z_customer}, {a_code} for {m_amount}.',
        patternCode: 'abc123',
        parameterMap: scrambled(),
    );

    Http::fake(['*' => Http::response([
        'data' => ['message_outbox_ids' => [3]],
        'meta' => ['status' => true, 'message_code' => '200-1'],
    ])]);

    sendReloaded();

    Http::assertSent(fn (Request $r): bool => $r['params'] === [
        'z_first' => 'Amid',
        'a_second' => 'CF-1204',
        'm_third' => '250000',
    ]);
});

it('falls back to the template body order when no mapping is configured', function () {
    // The default a gateway needs no mapping for. Body order is a real order and
    // is the one thing available when nobody has said otherwise.
    $this->configureGateway(
        driver: 'kavenegar',
        mode: DeliveryMode::Pattern,
        body: 'Hi {z_customer}, {a_code} for {m_amount}.',
        patternCode: 'order-created',
        parameterMap: null,
    );

    Http::fake(['*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 1]]])]);

    sendReloaded();

    Http::assertSent(fn (Request $r): bool => $r['token'] === 'Amid' && $r['token3'] === '250000');
});

it('uses our own variable name at a named provider where the mapping gives none', function () {
    // A positional mapping carried by a named provider. The provider still needs
    // some name, and our own is the documented default.
    $this->configureGateway(
        driver: 'smsir',
        mode: DeliveryMode::Pattern,
        body: 'Hi {z_customer}, {a_code} for {m_amount}.',
        patternCode: '100200',
        parameterMap: scrambled(named: false),
    );

    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageId' => 7]])]);

    sendReloaded();

    Http::assertSent(fn (Request $r): bool => $r['parameters'] === [
        ['name' => 'z_customer', 'value' => 'Amid'],
        ['name' => 'a_code', 'value' => 'CF-1204'],
        ['name' => 'm_amount', 'value' => '250000'],
    ]);
});

it('survives a real round trip through whichever database is under test', function () {
    /*
     * ⚠️ **The M2.1 defect, proved closed on the engine that caused it.**
     *
     * The old representation was a JSON OBJECT keyed by provider parameter. MySQL
     * normalises the key order of a JSON object on write - sorted by key length,
     * then bytewise - while SQLite stores the text verbatim. So a positional
     * parameter map could silently reorder in production, delivering a confident,
     * billed, well-formed message with the customer's name where the amount
     * belonged, while the entire test suite stayed green locally.
     *
     * This test does the whole journey on whatever engine is configured: write an
     * adversely-ordered map, read it back from the database, map it, and inspect
     * what actually reaches the provider. Under M8 it was run on SQLite and on
     * MySQL 8.4.
     */
    $engine = DB::connection()->getDriverName();
    $version = (string) DB::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

    test()->configureGateway(
        driver: 'kavenegar',
        mode: DeliveryMode::Pattern,
        body: 'Hi {z_customer}, code {a_code}, amount {m_amount}',
        patternCode: 'order-created',
        parameterMap: scrambled(named: false),
    );

    Http::fake(['*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 5]]])]);

    // The raw column, past the model cast: what the database actually holds.
    $stored = (string) DB::table('sms_template_gateways')->value('parameter_map');

    expect($stored)->toContain('z_customer')
        // A list, not an object - there are no keys left for an engine to
        // normalise. Positions 0, 1, 2 in the stored text, in that order.
        ->and(strpos($stored, 'z_customer'))->toBeLessThan(strpos($stored, 'a_code'))
        ->and(strpos($stored, 'a_code'))->toBeLessThan(strpos($stored, 'm_amount'));

    sendReloaded();

    Http::assertSent(function (Request $request) use ($engine, $version): bool {
        // Positional, at the strictest positional provider: token order IS the
        // meaning. If the engine had reordered anything, these three would be wrong
        // and no provider anywhere would notice.
        expect($request['token'])->toBe('Amid', "on {$engine} {$version}")
            ->and($request['token2'])->toBe('CF-1204', "on {$engine} {$version}")
            ->and($request['token3'])->toBe('250000', "on {$engine} {$version}");

        return true;
    });
});

it('refuses a mapping stored as an object rather than an ordered list', function () {
    /*
     * The old representation, and anything hand-written in its shape.
     *
     * Refused rather than interpreted. Reading it would mean deciding the order
     * from key order, which is the thing that cannot be relied on — and being
     * wrong here does not fail, it delivers a confident message with the values in
     * the wrong places.
     */
    [, , $binding] = $this->configureGateway(
        driver: 'kavenegar',
        mode: DeliveryMode::Pattern,
        body: 'Hi {z_customer}, {a_code} for {m_amount}.',
        patternCode: 'order-created',
    );

    $binding->forceFill(['parameter_map' => ['z_first' => 'z_customer', 'a_second' => 'a_code']])->save();

    Http::fake();

    $attempt = sendReloaded()->attempts()->first();

    Http::assertNothingSent();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        // This gateway's configuration, not a bad message: another gateway's
        // mapping may be perfectly good, and nothing was sent.
        ->and($attempt->failure_kind)->toBe(FailureKind::GatewayConfiguration)
        ->and($attempt->safe_to_failover)->toBeTrue()
        ->and($attempt->error)->toContain('ordered list');
});

it('refuses a mapping that puts two values on one provider parameter', function () {
    // Possible only now that the representation is a list. A named provider would
    // receive one of the two and nobody would be told which was dropped.
    [, , $binding] = $this->configureGateway(
        driver: 'smsir',
        mode: DeliveryMode::Pattern,
        body: 'Hi {z_customer}, {a_code} for {m_amount}.',
        patternCode: '100200',
    );

    $binding->forceFill(['parameter_map' => [
        ['provider' => 'SAME', 'variable' => 'z_customer'],
        ['provider' => 'SAME', 'variable' => 'a_code'],
    ]])->save();

    Http::fake();

    $attempt = sendReloaded()->attempts()->first();

    Http::assertNothingSent();

    expect($attempt->failure_kind)->toBe(FailureKind::GatewayConfiguration)
        ->and($attempt->error)->toContain('[SAME]');
});

it('refuses a malformed entry by naming its position', function () {
    [, , $binding] = $this->configureGateway(
        driver: 'kavenegar',
        mode: DeliveryMode::Pattern,
        body: 'Hi {z_customer}, {a_code} for {m_amount}.',
        patternCode: 'order-created',
    );

    $binding->forceFill(['parameter_map' => [
        ['provider' => 'token', 'variable' => 'z_customer'],
        ['provider' => 'token2'],
    ]])->save();

    Http::fake();

    $attempt = sendReloaded()->attempts()->first();

    Http::assertNothingSent();

    // The position, because in a list that is the only way to point at an entry.
    expect($attempt->error)->toContain('position 2');
});
