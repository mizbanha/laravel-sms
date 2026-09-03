<?php

declare(strict_types=1);

namespace Amid\Sms\Tests;

use Amid\Sms\Enums\DeliveryMode;
use Amid\Sms\Models\SmsGateway;
use Amid\Sms\Models\SmsTemplate;
use Amid\Sms\Models\SmsTemplateGateway;
use Amid\Sms\SmsServiceProvider;
use Illuminate\Foundation\Console\VendorPublishCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * The package's own isolated environment.
 *
 * By default an in-memory SQLite database belonging to this test run and nothing
 * else. No consuming application's database is ever touched, and none of these
 * migrations runs anywhere but here.
 *
 * ⚠️ **The connection can be supplied from outside**, and that is not a
 * convenience. Every migration and every model in this package was developed
 * against SQLite, which is the one engine that could never have caught the M2.1
 * defect: SQLite preserves JSON object key order and MySQL does not, so a
 * positional parameter map could reorder in production while the whole suite
 * stayed green. Running the same behavioural tests against a real MySQL is the
 * only way to know.
 *
 *     SMS_TEST_DB=mysql SMS_TEST_DB_PORT=33306 vendor/bin/pest
 *
 * Nothing about production behaviour changes with it: the same code runs, against
 * a different engine. The variables are read only here, and a run with none of
 * them set is the ordinary SQLite run.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [SmsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Gateway credentials use the encrypted cast, so the package genuinely
        // cannot work in an application with no APP_KEY. Every real Laravel app has
        // one; the test environment has to be given one deliberately.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->testConnection());

        // On unless a test says otherwise. The default is off (that is the point of
        // the switch), so most tests have to turn it on to exercise anything.
        $app['config']->set('laravel-sms.enabled', true);

        // The array store supports atomic locks, which is what the send job needs.
        $app['config']->set('cache.default', 'array');

        if (self::usingCustomTables()) {
            $app['config']->set('laravel-sms.tables', self::customTables());
        }
    }

    /**
     * Exercise the same installation contract as a consuming application: the
     * suite migrates published application copies, never a runtime vendor path.
     */
    protected function defineDatabaseMigrations(): void
    {
        VendorPublishCommand::dontUpdateMigrationDates();

        $this->artisan('vendor:publish', [
            '--provider' => SmsServiceProvider::class,
            '--tag' => 'laravel-sms-migrations',
            '--force' => true,
        ])->assertSuccessful();
    }

    /**
     * ⚠️ Whether this run maps every table to a non-default name.
     *
     * Set `SMS_TEST_TABLES=custom` and **the entire suite** runs against
     * `pkg_sms_*` instead of `sms_*`. That is the point: a handful of dedicated
     * tests could prove the migrations create differently-named tables, and would
     * prove nothing about whether a send, a failover, a routing cursor, an OTP
     * verification or a delivery refresh still works — which is where a missed
     * hardcoded name would actually surface.
     */
    public static function usingCustomTables(): bool
    {
        return env('SMS_TEST_TABLES') === 'custom';
    }

    /**
     * ⚠️ Deliberately not a prefix of the defaults.
     *
     * `sms_gateways` → `pkg_sms_gateways` would still contain the default name as a
     * substring, so a `str_contains($sql, 'sms_gateways')` assertion — or a stray
     * hardcoded literal — could pass by accident. These names share no substring
     * with the ones they replace beyond the underscore-separated words.
     *
     * @return array<string, string>
     */
    public static function customTables(): array
    {
        return [
            'gateways' => 'pkg_routes',
            'templates' => 'pkg_wordings',
            'template_gateways' => 'pkg_wording_routes',
            'messages' => 'pkg_dispatches',
            'attempts' => 'pkg_tries',
        ];
    }

    /**
     * The database this run uses.
     *
     * SQLite in memory unless SMS_TEST_DB says otherwise. ⚠️ A disposable,
     * package-only database in every case: this suite creates and drops the five
     * package tables, and pointing it at anything shared would be pointing a
     * migration at somebody else's data.
     *
     * @return array<string, mixed>
     */
    protected function testConnection(): array
    {
        if (env('SMS_TEST_DB') !== 'mysql') {
            return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
        }

        return [
            'driver' => 'mysql',
            'host' => env('SMS_TEST_DB_HOST', '127.0.0.1'),
            'port' => env('SMS_TEST_DB_PORT', '3306'),
            'database' => env('SMS_TEST_DB_DATABASE', 'sms_package_test'),
            'username' => env('SMS_TEST_DB_USERNAME', 'sms_pkg'),
            'password' => env('SMS_TEST_DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ];
    }

    /**
     * A gateway, a template and the binding between them: the smallest complete
     * configuration a send needs.
     *
     * @param  list<array{provider?: string|null, variable: string}>|null  $parameterMap
     *                                                                                    written exactly as it is stored: an ordered list, because that is
     *                                                                                    the representation under test
     */
    protected function configureGateway(
        string $driver = 'log',
        DeliveryMode $mode = DeliveryMode::Text,
        string $body = 'Hello {customer_name}',
        ?string $patternCode = null,
        ?array $parameterMap = null,
        array $credentials = ['api_key' => 'test-key'],
        int $priority = 100,
        bool $enabled = true,
        string $key = 'primary',
        string $templateKey = 'order-created',
    ): array {
        $gateway = new SmsGateway;
        $gateway->forceFill([
            'key' => $key,
            'label' => ucfirst($key),
            'driver' => $driver,
            'sender' => '30001234',
            'credentials' => $credentials,
            'is_enabled' => $enabled,
            'priority' => $priority,
        ])->save();

        $template = SmsTemplate::query()->firstOrCreate(
            ['key' => $templateKey],
            ['name' => 'Order created', 'body' => $body],
        );

        $binding = SmsTemplateGateway::query()->create([
            'sms_template_id' => $template->getKey(),
            'sms_gateway_id' => $gateway->getKey(),
            'mode' => $mode,
            'pattern_code' => $patternCode,
            'parameter_map' => $parameterMap,
            'is_enabled' => true,
        ]);

        return [$gateway, $template, $binding];
    }
}
