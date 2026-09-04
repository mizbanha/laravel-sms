<?php

declare(strict_types=1);

use Mizbanha\Sms\Exceptions\InvalidTableConfiguration;
use Mizbanha\Sms\Models\SmsAttempt;
use Mizbanha\Sms\Models\SmsGateway;
use Mizbanha\Sms\Models\SmsMessage;
use Mizbanha\Sms\Models\SmsTemplate;
use Mizbanha\Sms\Models\SmsTemplateGateway;
use Mizbanha\Sms\Support\TableNames;
use Mizbanha\Sms\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where this package's tables live, and the promise that configuring them changes
 * everything at once.
 *
 * ⚠️ These tests are the *narrow* half of the proof. The wide half is running the
 * ENTIRE suite under `SMS_TEST_TABLES=custom` — 491 tests against `pkg_routes`,
 * `pkg_wordings`, `pkg_wording_routes`, `pkg_dispatches` and `pkg_tries` — because a
 * missed literal would not surface in a migration assertion. It surfaces in a send.
 */

// ---------------------------------------------------------------------------
// Defaults
// ---------------------------------------------------------------------------

it('keeps the original names for an application that configures nothing', function () {
    /*
     * ⚠️ The compatibility promise, asserted literally. Every installation that
     * existed before table names were configurable must keep the tables it has, so
     * this list is not a convenience default — changing a value here is a breaking
     * change for somebody's production database.
     */
    config()->set('laravel-sms.tables', null);

    expect(TableNames::gateways())->toBe('sms_gateways')
        ->and(TableNames::templates())->toBe('sms_templates')
        ->and(TableNames::templateGateways())->toBe('sms_template_gateways')
        ->and(TableNames::messages())->toBe('sms_messages')
        ->and(TableNames::attempts())->toBe('sms_attempts');
});

it('ships the same defaults in the config file as the class falls back to', function () {
    // Two places state the default map — the published config and the constant a
    // missing key falls back to. They must agree, or publishing the config would
    // silently change which tables an installation uses.
    $published = require __DIR__.'/../../config/laravel-sms.php';

    expect($published['tables'])->toBe(TableNames::DEFAULTS);
});

it('names every table the package owns', function () {
    // ⚠️ A guard against the quiet failure mode of this feature: a sixth table added
    // later, with a hardcoded name, that nobody can rename. The count comes from the
    // migrations directory rather than being retyped.
    $migrations = glob(__DIR__.'/../../database/migrations/*.php');

    expect($migrations)->toHaveCount(count(TableNames::DEFAULTS));
});

// ---------------------------------------------------------------------------
// Models
// ---------------------------------------------------------------------------

it('points every model at the configured table', function () {
    config()->set('laravel-sms.tables', [
        'gateways' => 'x_gateways',
        'templates' => 'x_templates',
        'template_gateways' => 'x_bindings',
        'messages' => 'x_messages',
        'attempts' => 'x_attempts',
    ]);

    expect((new SmsGateway)->getTable())->toBe('x_gateways')
        ->and((new SmsTemplate)->getTable())->toBe('x_templates')
        ->and((new SmsTemplateGateway)->getTable())->toBe('x_bindings')
        ->and((new SmsMessage)->getTable())->toBe('x_messages')
        ->and((new SmsAttempt)->getTable())->toBe('x_attempts');
});

it('resolves the table for a reloaded record and not only a new instance', function () {
    /*
     * ⚠️ A model hydrated from the database, and one pulled back through a relation,
     * take different paths through Eloquent than `new Model`. A `getTable()` that
     * worked only for the second would pass a naive test and fail in production the
     * first time anything read a row back.
     */
    [$gateway, $template, $binding] = $this->configureGateway();

    $expected = TableNames::gateways();

    expect($gateway->fresh()->getTable())->toBe($expected)
        ->and(SmsGateway::query()->first()->getTable())->toBe($expected)
        ->and($binding->fresh()->gateway->getTable())->toBe($expected)
        ->and($template->fresh()->gatewayBindings()->first()->getTable())
        ->toBe(TableNames::templateGateways());
});

it('survives being serialized onto a queue and woken up again', function () {
    /*
     * ⚠️ The reason `getTable()` is a method rather than a property assigned in the
     * constructor. Laravel serializes a model to its class and key; a table name
     * baked into instance state at construction would travel with it and be wrong
     * the moment the worker's configuration differed — or right for the wrong
     * reason, which is worse to debug.
     */
    [$gateway] = $this->configureGateway();

    $revived = unserialize(serialize($gateway));

    expect($revived->getTable())->toBe(TableNames::gateways())
        ->and(unserialize(serialize(new SmsMessage))->getTable())->toBe(TableNames::messages());
});

it('still honours a table set explicitly, the way Eloquent documents', function () {
    // Not a feature this package needs, but `setTable()` is public Eloquent API and
    // silently ignoring it would be a surprise in somebody else's subclass.
    $gateway = new SmsGateway;
    $gateway->setTable('somewhere_else');

    expect($gateway->getTable())->toBe('somewhere_else')
        ->and((new SmsGateway)->getTable())->toBe(TableNames::gateways());
});

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------

it('creates exactly the configured tables and nothing under the other map', function () {
    /*
     * ⚠️ Both halves matter, and the test is written so both are asserted on either
     * run rather than half of it being skipped when the names are the defaults.
     *
     * Creating the configured tables is the easy half. The half this feature exists
     * for is that the OTHER names are untouched: on the custom run that means the
     * application's own `sms_messages` is still its own, and on the default run it
     * means nothing has quietly started creating `pkg_*` tables as well.
     */
    $expected = TableNames::all();
    $absent = array_diff(
        array_merge(array_values(TableNames::DEFAULTS), array_values(TestCase::customTables())),
        array_values($expected),
    );

    expect($absent)->not->toBeEmpty();

    foreach ($expected as $name) {
        expect(Schema::hasTable($name))->toBeTrue();
    }

    foreach ($absent as $name) {
        expect(Schema::hasTable($name))->toBeFalse();
    }
});

it('writes and reads a whole message chain through the configured tables', function () {
    /*
     * End to end, through raw queries against the configured names, so this test
     * fails if a model writes anywhere other than where it says it does.
     */
    [$gateway, $template] = $this->configureGateway();

    $message = new SmsMessage;
    $message->forceFill([
        'sms_template_id' => $template->getKey(),
        'to' => '+989121234567',
        'status' => 'accepted',
        'body' => 'Hello',
    ])->save();

    $attempt = new SmsAttempt;
    $attempt->forceFill([
        'sms_message_id' => $message->getKey(),
        'gateway_key' => $gateway->key,
        'driver' => 'log',
        'sequence' => 1,
        'mode' => 'text',
        'outcome' => 'accepted',
    ])->save();

    expect(DB::table(TableNames::gateways())->count())->toBe(1)
        ->and(DB::table(TableNames::templates())->count())->toBe(1)
        ->and(DB::table(TableNames::templateGateways())->count())->toBe(1)
        ->and(DB::table(TableNames::messages())->count())->toBe(1)
        ->and(DB::table(TableNames::attempts())->count())->toBe(1)
        // The relation, which is what proves the foreign key landed on the right table.
        ->and($message->fresh()->attempts()->count())->toBe(1)
        ->and($attempt->fresh()->message->getKey())->toBe($message->getKey());
});

it('points its foreign keys at the configured tables', function () {
    /*
     * ⚠️ The check that `constrained()` was not left to infer a table from a column
     * name. `sms_template_id` conventionally implies `sms_templates`, so an
     * installation with custom names would get a constraint pointing at a table that
     * does not exist — and SQLite would accept the CREATE and fail at insert time.
     */
    $foreign = collect(Schema::getForeignKeys(TableNames::attempts()));

    expect($foreign->pluck('foreign_table')->all())
        ->toContain(TableNames::messages())
        ->toContain(TableNames::gateways());

    $bindings = collect(Schema::getForeignKeys(TableNames::templateGateways()));

    expect($bindings->pluck('foreign_table')->all())
        ->toContain(TableNames::templates())
        ->toContain(TableNames::gateways());
});

it('names every index and constraint after the table it belongs to', function () {
    /*
     * ⚠️ Laravel builds index names out of table names, and this package writes one
     * of them by hand. An index called `sms_template_gateway_unique` on a table
     * called `pkg_wording_routes` is not broken — but it is a reference to a table
     * that is not there, and the next person to read the schema has to work out
     * which of the two names is the lie.
     */
    /*
     * ⚠️ Asserted as "every index name begins with the table it is on", not as
     * "no index name contains a foreign table name". The weaker version was written
     * first and could not fail: the hand-written index was called
     * `sms_template_gateway_unique` — singular — which does not contain the string
     * `sms_template_gateways`, so the sabotage that reinstated it passed. A test
     * that cannot fail is not a test.
     *
     * This version is also the property actually worth having: it is Laravel's own
     * naming convention, and following it is what keeps a schema readable.
     */
    foreach (TableNames::all() as $table) {
        foreach (Schema::getIndexes($table) as $index) {
            $name = (string) $index['name'];

            if (strtolower($name) === 'primary') {
                continue;
            }

            expect($name)->toStartWith($table);
        }
    }

    foreach (TableNames::all() as $table) {
        foreach (Schema::getForeignKeys($table) as $key) {
            $name = (string) $key['name'];

            /*
             * ⚠️ SQLite does not name foreign-key constraints and reports them as
             * an empty string, so this half of the assertion has something to say
             * only on MySQL. Skipped per-key rather than skipping the test on
             * SQLite: the index loop above still runs on both engines and still
             * fails on the sabotage, so neither engine runs a vacuous test.
             */
            if ($name === '') {
                continue;
            }

            expect($name)->toStartWith($table);
        }
    }
});

it('keeps every generated identifier inside the 64-character limit', function () {
    /*
     * ⚠️ The empirical half of the length rule. `TableNames::MAX_LENGTH` is 30
     * because the longest index suffix this package generates is 34 — but that is
     * arithmetic done by hand, and this asserts it against a schema the database
     * actually built. It matters most on MySQL, which refuses an identifier over 64
     * outright where SQLite does not care.
     */
    foreach (TableNames::all() as $table) {
        expect(strlen($table))->toBeLessThanOrEqual(30);

        foreach (Schema::getIndexes($table) as $index) {
            expect(strlen((string) $index['name']))->toBeLessThanOrEqual(64);
        }

        foreach (Schema::getForeignKeys($table) as $key) {
            expect(strlen((string) $key['name']))->toBeLessThanOrEqual(64);
        }
    }
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

it('refuses a name that cannot be a table', function (mixed $value, string $expected) {
    config()->set('laravel-sms.tables.messages', $value);

    expect(fn (): string => TableNames::messages())
        ->toThrow(InvalidTableConfiguration::class, $expected);
})->with([
    'empty' => ['', 'empty'],
    'whitespace only' => ['   ', 'whitespace'],
    'padded' => [' sms_messages ', 'whitespace'],
    // ⚠️ Laravel reads a dot as a schema separator, so this one would not fail — it
    // would silently address a different schema.
    'a dot' => ['app.sms_messages', 'cannot appear'],
    'a backtick' => ['sms_`messages', 'cannot appear'],
    'a quote' => ["sms_'messages", 'cannot appear'],
    'a semicolon' => ['sms_messages; drop table users', 'cannot appear'],
    'a newline' => ["sms\nmessages", 'cannot appear'],
    'too long' => ['sms_messages_with_a_really_long_name', 'limit is 30'],
    'not a string' => [['sms_messages'], 'must be a table name'],
]);

it('refuses two tables sharing one name', function () {
    /*
     * ⚠️ The mistake a per-name check cannot catch, and the most damaging one on
     * this list: attempts and messages writing into the same table produces rows
     * that half-satisfy both schemas and a foreign key pointing at itself.
     */
    config()->set('laravel-sms.tables.attempts', config('laravel-sms.tables.messages'));

    expect(fn (): array => TableNames::all())
        ->toThrow(InvalidTableConfiguration::class, 'another SMS table already uses');
});

it('refuses a table this package does not own', function () {
    expect(fn (): string => TableNames::get('campaigns'))
        ->toThrow(InvalidTableConfiguration::class, 'This package owns exactly');
});

it('names the configuration key and the default in what it throws', function () {
    // The error is read by somebody who has just edited a config file. It should
    // say which line, and what to put back.
    config()->set('laravel-sms.tables.templates', '');

    expect(fn (): string => TableNames::templates())
        ->toThrow(InvalidTableConfiguration::class, "config('laravel-sms.tables.templates')");

    try {
        TableNames::templates();
    } catch (InvalidTableConfiguration $exception) {
        expect($exception->getMessage())->toContain('sms_templates');
    }
});

it('never falls back to a default when a name is present but wrong', function () {
    /*
     * ⚠️ The single most important assertion in this file.
     *
     * An application configures table names precisely because it already owns tables
     * called `sms_messages`. Falling back on a typo would point this package at the
     * application's own data and write to it — so a bad value throws, and a default
     * applies only when the key is genuinely absent.
     */
    config()->set('laravel-sms.tables.messages', '  ');

    expect(fn (): string => TableNames::messages())->toThrow(InvalidTableConfiguration::class);

    config()->set('laravel-sms.tables', ['messages' => null]);

    expect(fn (): string => TableNames::messages())->toThrow(InvalidTableConfiguration::class);
});

it('accepts a name the whitelist a lazier implementation would use would refuse', function () {
    // ⚠️ The rule is a blacklist of what breaks the query grammar, not an ASCII
    // whitelist. A hyphen and a non-Latin name are both legal table names in MySQL
    // and PostgreSQL, and refusing them would be this package inventing a limit.
    config()->set('laravel-sms.tables.messages', 'sms-messages');
    expect(TableNames::messages())->toBe('sms-messages');

    config()->set('laravel-sms.tables.messages', 'پیامک_ها');
    expect(TableNames::messages())->toBe('پیامک_ها');
});

it('checks the whole map when the application boots', function () {
    /*
     * Per-name validation happens on every read, which catches a bad name at the
     * moment it is used. Duplicates are only visible across the whole map, so the
     * service provider checks it once at boot — an application with a broken map
     * finds out when it starts rather than when it first sends.
     */
    config()->set('laravel-sms.tables.attempts', config('laravel-sms.tables.gateways'));

    expect(fn () => (new Mizbanha\Sms\SmsServiceProvider(app()))->boot())
        ->toThrow(InvalidTableConfiguration::class);
});
