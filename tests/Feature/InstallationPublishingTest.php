<?php

declare(strict_types=1);

use Amid\Sms\SmsServiceProvider;
use Amid\Sms\Support\TableNames;
use Amid\Sms\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

function publishedCoreMigrationPaths(): array
{
    $suffixes = [
        '_create_sms_gateways_table.php',
        '_create_sms_templates_table.php',
        '_create_sms_template_gateways_table.php',
        '_create_sms_messages_table.php',
        '_create_sms_attempts_table.php',
    ];

    return collect(File::files(database_path('migrations')))
        ->filter(fn (SplFileInfo $file): bool => collect($suffixes)
            ->contains(fn (string $suffix): bool => str_ends_with($file->getFilename(), $suffix)))
        ->map(fn (SplFileInfo $file): string => $file->getPathname())
        ->all();
}

function clearPublishedCoreFiles(): void
{
    File::delete(config_path('laravel-sms.php'));
    File::delete(publishedCoreMigrationPaths());
}

function useFreshInstallationDatabase(string $name): string
{
    $database = storage_path('framework/testing/'.$name.'.sqlite');

    File::ensureDirectoryExists(dirname($database));
    File::delete($database);
    File::put($database, '');

    config()->set('database.connections.'.$name, [
        'driver' => 'sqlite',
        'database' => $database,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    DB::purge($name);

    return $database;
}

afterEach(function (): void {
    foreach (['sms_install_default', 'sms_install_custom'] as $connection) {
        DB::purge($connection);
        File::delete(storage_path('framework/testing/'.$connection.'.sqlite'));
    }

    clearPublishedCoreFiles();
    config()->set('laravel-sms.tables', TableNames::DEFAULTS);
});

it('publishes every Core installation file when selected by provider', function (): void {
    clearPublishedCoreFiles();

    $this->artisan('vendor:publish', [
        '--provider' => SmsServiceProvider::class,
    ])->assertSuccessful();

    expect(File::exists(config_path('laravel-sms.php')))->toBeTrue();

    expect(publishedCoreMigrationPaths())->toHaveCount(5);

    foreach (publishedCoreMigrationPaths() as $migration) {
        expect(File::exists($migration))->toBeTrue();
    }
});

it('installs all five default tables from published config and migrations', function (): void {
    clearPublishedCoreFiles();
    useFreshInstallationDatabase('sms_install_default');

    $this->artisan('vendor:publish', ['--tag' => 'laravel-sms-config'])->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => 'laravel-sms-migrations'])->assertSuccessful();
    expect(publishedCoreMigrationPaths())->toHaveCount(5);
    config()->set('laravel-sms', require config_path('laravel-sms.php'));
    $this->artisan('migrate', ['--database' => 'sms_install_default'])->assertSuccessful();

    expect(File::exists(config_path('laravel-sms.php')))->toBeTrue();

    foreach (TableNames::DEFAULTS as $table) {
        expect(Schema::connection('sms_install_default')->hasTable($table))->toBeTrue();
    }
});

it('uses table names edited in the published config before migration', function (): void {
    clearPublishedCoreFiles();
    useFreshInstallationDatabase('sms_install_custom');

    $this->artisan('vendor:publish', ['--tag' => 'laravel-sms-config'])->assertSuccessful();
    $this->artisan('vendor:publish', ['--tag' => 'laravel-sms-migrations'])->assertSuccessful();
    expect(publishedCoreMigrationPaths())->toHaveCount(5);

    $published = require config_path('laravel-sms.php');
    $published['tables'] = TestCase::customTables();

    File::put(
        config_path('laravel-sms.php'),
        "<?php\n\nreturn ".var_export($published, true).";\n",
    );
    config()->set('laravel-sms', require config_path('laravel-sms.php'));

    $this->artisan('migrate', ['--database' => 'sms_install_custom'])->assertSuccessful();

    foreach (TestCase::customTables() as $table) {
        expect(Schema::connection('sms_install_custom')->hasTable($table))->toBeTrue();
    }

    foreach (TableNames::DEFAULTS as $table) {
        expect(Schema::connection('sms_install_custom')->hasTable($table))->toBeFalse();
    }
});
