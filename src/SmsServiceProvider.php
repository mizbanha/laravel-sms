<?php

declare(strict_types=1);

namespace Mizbanha\Sms;

use Mizbanha\Sms\Contracts\OtpCodeGenerator;
use Mizbanha\Sms\Contracts\PhoneNormalizer;
use Mizbanha\Sms\Delivery\DeliveryTracker;
use Mizbanha\Sms\Gateways\GatewayRegistry;
use Mizbanha\Sms\Gateways\GatewayRouter;
use Mizbanha\Sms\Gateways\RoutingPlanner;
use Mizbanha\Sms\Health\CircuitBreaker;
use Mizbanha\Sms\Otp\OtpManager;
use Mizbanha\Sms\Otp\RandomOtpCodeGenerator;
use Mizbanha\Sms\Phone\LibPhoneNumberNormalizer;
use Mizbanha\Sms\Sending\MessageDispatcher;
use Mizbanha\Sms\Support\TableNames;
use Mizbanha\Sms\Templates\ParameterMapper;
use Mizbanha\Sms\Templates\TemplateRenderer;
use Illuminate\Support\ServiceProvider;

class SmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * ⚠️ **`laravel-sms`, deliberately not `sms`.**
         *
         * A package does not get to own a namespace as generic as `sms`. The first
         * real consumer proved it: an application that had owned `config/sms.php`
         * for eleven stages installed this package, and because `mergeConfigFrom()`
         * is a shallow `array_merge` with the application's file on top, the two
         * files silently fought over every key they shared. `drivers` was
         * `name => [driver => class, …]` on one side and `name => class` on the
         * other; `queue` was a boolean and an array. Neither system could see that
         * the other had won.
         *
         * Named for the package, so an application with its own SMS subsystem keeps
         * `config/sms.php` and this reads `config/laravel-sms.php`, and no bridge,
         * merge or precedence rule is needed between them.
         *
         * ⚠️ There is no fallback to `sms.*` and no way to switch namespaces from
         * the environment. One name, decided here. Corrected before the first tag,
         * so nothing is owed backwards compatibility.
         */
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-sms.php', 'laravel-sms');

        // Bound to the contract, not the class, so an application that needs a
        // different parsing strategy replaces one binding and nothing else.
        $this->app->singleton(PhoneNormalizer::class, fn ($app): PhoneNormalizer => new LibPhoneNumberNormalizer(
            defaultRegion: (string) $app['config']->get('laravel-sms.phone.default_region', 'IR'),
            requireMobile: (bool) $app['config']->get('laravel-sms.phone.require_mobile', false),
        ));

        // Singletons because they memoise driver instances: one worker sending ten
        // thousand messages should build one driver, not ten thousand.
        $this->app->singleton(GatewayRegistry::class, fn ($app): GatewayRegistry => new GatewayRegistry(
            (array) $app['config']->get('laravel-sms.drivers', []),
        ));

        /*
         * Bound to the contract so a test can substitute a deterministic code.
         *
         * ⚠️ That seam exists because the public OTP API never returns a code -
         * see OtpResult - so there would otherwise be no way to assert that the
         * code which reached one gateway is the code which reached the next.
         * Production resolves the CSPRNG-backed implementation.
         */
        $this->app->singleton(OtpCodeGenerator::class, RandomOtpCodeGenerator::class);

        /*
         * ⚠️ A singleton, and it matters. The breaker remembers in memory which
         * gateways it holds a half-open probe for, so that the call reporting a
         * result knows whether it was the probe. A fresh instance per resolution
         * would lose that between allows() and record().
         */
        $this->app->singleton(CircuitBreaker::class);

        $this->app->singleton(GatewayRouter::class);
        $this->app->singleton(RoutingPlanner::class);
        $this->app->singleton(TemplateRenderer::class);
        $this->app->singleton(ParameterMapper::class);
        $this->app->singleton(MessageDispatcher::class);
        $this->app->singleton(DeliveryTracker::class);
        $this->app->singleton(SmsManager::class);
        $this->app->singleton(OtpManager::class);
    }

    public function boot(): void
    {
        /*
         * ⚠️ The whole table map is checked once, here, so a broken one is found
         * when the application boots rather than when it first writes a message.
         *
         * `TableNames` validates each name again on every read, which catches the
         * individual mistakes; this call is what additionally catches two tables
         * configured with the SAME name — a map that is only wrong when read as a
         * whole, and whose symptom would otherwise be attempts quietly inserted
         * into the messages table.
         */
        TableNames::validate();

        if ($this->app->runningInConsole()) {
            /*
             * ⚠️ The tags carry the package's name for the same reason the config
             * key does. `--tag=sms-config` is a name any SMS package might claim,
             * and Laravel runs every provider that registered it — so one
             * `vendor:publish` could write two packages' files. `filament-sms`
             * already prefixes its own tags; these now match.
             */
            $this->publishes([
                __DIR__.'/../config/laravel-sms.php' => $this->app->configPath('laravel-sms.php'),
            ], 'laravel-sms-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'laravel-sms-migrations');
        }
    }
}
