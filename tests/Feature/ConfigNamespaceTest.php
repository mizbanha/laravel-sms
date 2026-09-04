<?php

declare(strict_types=1);

use Mizbanha\Sms\Enums\Capability;
use Mizbanha\Sms\Enums\MessageStatus;
use Mizbanha\Sms\Facades\Sms;
use Mizbanha\Sms\Gateways\GatewayRegistry;
use Mizbanha\Sms\Models\SmsAttempt;
use Mizbanha\Sms\Models\SmsGateway;
use Mizbanha\Sms\Models\SmsMessage;
use Mizbanha\Sms\Models\SmsTemplate;
use Mizbanha\Sms\Models\SmsTemplateGateway;
use Mizbanha\Sms\Support\TableNames;
use Illuminate\Support\Facades\Schema;

/**
 * ⚠️ **The package answers to `laravel-sms`, and an application may keep `sms`.**
 *
 * This is not a naming preference. Laravel merges a package's configuration into
 * the application's with a shallow `array_merge`, application on top, so two files
 * claiming one key do not coexist: for every top-level key both define, one wins
 * outright and the other's value disappears — with no error, and with each side
 * still believing it is reading its own settings.
 *
 * The first application to install this package had owned `config/sms.php` since
 * its twelfth stage. Its `drivers` was `name => ['driver' => class, …]`; this
 * package's is `name => class`. Its `queue` was a boolean; this package's is an
 * array. Nothing threw. `GatewayRegistry` was simply handed a map of arrays where
 * it expected class strings, and every gateway screen failed with
 * `GatewayNotConfigured`.
 *
 * So the tests below do not assert that a key was renamed. They install a hostile
 * host configuration under `sms.*` — the real shape, from the real application —
 * and prove the package cannot see it.
 */

/**
 * The legacy application's `config/sms.php`, in the shape that actually broke
 * this package. Not a placeholder: `drivers` is a map of arrays and `queue` is a
 * boolean, which are the two collisions that matter.
 */
function hostLegacyConfig(): void
{
    config([
        'sms.drivers' => [
            'legacy' => ['some' => 'legacy-shape'],
            // ⚠️ The same NAME the package uses for its own log driver, so a
            // package that fell back to `sms.*` would find something plausible
            // here rather than nothing, and fail later and less clearly.
            'log' => ['driver' => 'App\\Services\\Sms\\Drivers\\LogDriver', 'sender' => 'log'],
        ],
        'sms.queue' => false,
        'sms.enabled' => false,
        'sms.tables' => [
            'gateways' => 'host_gateways',
            'templates' => 'host_templates',
            'template_gateways' => 'host_template_gateways',
            'messages' => 'host_messages',
            'attempts' => 'host_attempts',
        ],
        'sms.otp' => 'log',
        'sms.log_channel' => 'host-channel',
    ]);
}

// ---------------------------------------------------------------------------
// Coexistence
// ---------------------------------------------------------------------------

it('boots with a hostile host sms configuration present', function () {
    hostLegacyConfig();

    // ⚠️ The boot-time whole-map check. It reads `laravel-sms.tables`; if it read
    // `sms.tables` it would now find the host's five names and validate those.
    TableNames::validate();

    expect(TableNames::all())->toBe(config('laravel-sms.tables'))
        ->and(TableNames::all())->not->toBe(config('sms.tables'));
});

it('builds its gateway registry from its own namespace, not the host one', function () {
    hostLegacyConfig();

    /*
     * ⚠️ Resolved fresh rather than read off the container. The registry is a
     * singleton built during register(), so an instance made before this test
     * changed anything would pass without proving where it looked. Forgetting it
     * makes the provider's closure run again, now, with the host config in place.
     */
    app()->forgetInstance(GatewayRegistry::class);

    $registry = app(GatewayRegistry::class);

    // The package's own drivers, by name, unaffected by the host's `log` entry.
    expect($registry->capabilitiesFor('log'))->toBe([Capability::Text, Capability::Pattern]);

    // ...and the host's own driver name is not visible to the package at all.
    expect(fn () => $registry->capabilitiesFor('legacy'))
        ->toThrow(Mizbanha\Sms\Exceptions\GatewayNotConfigured::class);
});

it('resolves every model table from its own namespace', function () {
    hostLegacyConfig();

    $expected = config('laravel-sms.tables');

    expect((new SmsGateway)->getTable())->toBe($expected['gateways'])
        ->and((new SmsTemplate)->getTable())->toBe($expected['templates'])
        ->and((new SmsTemplateGateway)->getTable())->toBe($expected['template_gateways'])
        ->and((new SmsMessage)->getTable())->toBe($expected['messages'])
        ->and((new SmsAttempt)->getTable())->toBe($expected['attempts']);

    // ⚠️ None of them took a host name, and the host names are all still there to
    // be taken — this is the assertion that would fail if anything read `sms.*`.
    foreach (config('sms.tables') as $hostTable) {
        expect($expected)->not->toContain($hostTable);
    }
});

it('runs its migrations against its own tables while the host names go unused', function () {
    hostLegacyConfig();

    foreach (config('laravel-sms.tables') as $table) {
        expect(Schema::hasTable($table))->toBeTrue("expected {$table}");
    }

    foreach (config('sms.tables') as $table) {
        expect(Schema::hasTable($table))->toBeFalse("host table {$table} should never be created");
    }
});

it('⚠️ sends a real message with the host configuration switched against it', function () {
    hostLegacyConfig();

    [$gateway] = $this->configureGateway(driver: 'log');

    /*
     * ⚠️ The end-to-end proof, and the one that matters most. The host config says
     * `sms.enabled = false` — the package's own master switch is on. A package
     * reading the wrong namespace would record this message as suppressed and
     * send nothing, which is a silent failure in exactly the direction nobody
     * checks.
     */
    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    // ⚠️ Accepted, NOT suppressed. Suppressed is what the host's `sms.enabled`
    // would have produced, and it writes a row and looks like a working send.
    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and($message->attempts()->count())->toBe(1)
        ->and($message->attempts()->first()->gateway_key)->toBe($gateway->key)
        ->and($message->body)->toBe('Hello Amid');
});

it('is unmoved by anything the host does to sms afterwards', function () {
    [$gateway] = $this->configureGateway(driver: 'log');

    $before = TableNames::all();

    // The host rewrites every one of its own keys, to values that would break the
    // package outright if it were reading them.
    config([
        'sms.tables' => ['gateways' => '', 'templates' => '', 'template_gateways' => '', 'messages' => '', 'attempts' => ''],
        'sms.drivers' => [],
        'sms.enabled' => false,
    ]);

    expect(TableNames::all())->toBe($before);

    TableNames::validate();

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and($message->attempts()->first()->gateway_key)->toBe($gateway->key);
});

// ---------------------------------------------------------------------------
// Structural — the package's source, not its behaviour
// ---------------------------------------------------------------------------

it('⚠️ reads no config under the generic sms namespace anywhere in its source', function () {
    /*
     * ⚠️ The needles are assembled from fragments so this test does not match
     * itself. Written out whole, every one of them would appear in this file and
     * the audit would report its own source.
     */
    $open = 'config(';
    $needles = [
        $open."'sms.",
        $open.'"sms.',
        $open."['sms.",
        "->get('sms.",
        '->get("sms.',
        "->set('sms.",
        '->set("sms.',
    ];

    $offenders = [];

    foreach (sourceFiles() as $file) {
        $source = file_get_contents($file);

        foreach ($needles as $needle) {
            if (str_contains($source, $needle)) {
                $offenders[] = basename($file).' contains '.$needle;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('⚠️ names no config key under the generic sms namespace in a message it shows somebody', function () {
    /*
     * A message telling an operator to fix `sms.otp.length` sends them to a key
     * that no longer exists. These strings are documentation, and they go stale
     * exactly like documentation.
     *
     * ⚠️ `sms.ir` is the SMS.ir provider's HOSTNAME and is not a config key. It is
     * the reason this checks for a dot-separated key ending in a word rather than
     * for the four characters `sms.`.
     */
    $keys = ['sms.otp.', 'sms.tables.', 'sms.drivers', 'sms.circuit_breaker.', 'sms.routing.', 'sms.lock.', 'sms.http.', 'sms.queue.', 'sms.retry.', 'sms.phone.', 'sms.log.'];

    $offenders = [];

    foreach (sourceFiles() as $file) {
        $source = file_get_contents($file);

        foreach ($keys as $key) {
            // ⚠️ Only an OCCURRENCE NOT PRECEDED BY `laravel-`. Searching for
            // `sms.otp.` alone matches `laravel-sms.otp.` as a substring, which is
            // how a half-finished rename passes its own audit.
            $offset = 0;

            while (($at = strpos($source, $key, $offset)) !== false) {
                $prefix = substr($source, max(0, $at - 8), min(8, $at));

                if (! str_ends_with($prefix, 'laravel-')) {
                    $offenders[] = basename($file).': '.$key;
                }

                $offset = $at + 1;
            }
        }
    }

    expect(array_unique($offenders))->toBe([]);
});

it('⚠️ ships config/laravel-sms.php and does not ship config/sms.php', function () {
    $config = dirname(__DIR__, 2).'/config';

    expect(file_exists($config.'/laravel-sms.php'))->toBeTrue()
        // The whole point: an application's own `config/sms.php` must never be
        // something this package can overwrite on publish.
        ->and(file_exists($config.'/sms.php'))->toBeFalse()
        ->and(glob($config.'/*.php'))->toHaveCount(1);
});

it('merges its config file under its own key and contributes nothing to sms', function () {
    // Loaded, so the merge happened and the key is the package's.
    expect(config('laravel-sms.enabled'))->not->toBeNull()
        ->and(config('laravel-sms.drivers'))->toBeArray()
        ->and(config('laravel-sms.tables'))->toBeArray();

    // ⚠️ And nothing at all was contributed to `sms`, which is what leaves the
    // name free for an application that already uses it.
    expect(config('sms'))->toBeNull();
});

/**
 * Every PHP file this package ships. Tests are excluded on purpose: a test may
 * legitimately construct a hostile `sms.*` host configuration, and this file does.
 *
 * @return list<string>
 */
function sourceFiles(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];

    foreach (['src', 'database', 'config'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory));

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}
