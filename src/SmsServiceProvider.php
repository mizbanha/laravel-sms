<?php

declare(strict_types=1);

namespace Amid\Sms;

use Amid\Sms\Contracts\OtpCodeGenerator;
use Amid\Sms\Contracts\PhoneNormalizer;
use Amid\Sms\Delivery\DeliveryTracker;
use Amid\Sms\Gateways\GatewayRegistry;
use Amid\Sms\Gateways\GatewayRouter;
use Amid\Sms\Health\CircuitBreaker;
use Amid\Sms\Otp\OtpManager;
use Amid\Sms\Otp\RandomOtpCodeGenerator;
use Amid\Sms\Phone\LibPhoneNumberNormalizer;
use Amid\Sms\Sending\MessageDispatcher;
use Amid\Sms\Templates\ParameterMapper;
use Amid\Sms\Templates\TemplateRenderer;
use Illuminate\Support\ServiceProvider;

class SmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sms.php', 'sms');

        // Bound to the contract, not the class, so an application that needs a
        // different parsing strategy replaces one binding and nothing else.
        $this->app->singleton(PhoneNormalizer::class, fn ($app): PhoneNormalizer => new LibPhoneNumberNormalizer(
            defaultRegion: (string) $app['config']->get('sms.phone.default_region', 'IR'),
            requireMobile: (bool) $app['config']->get('sms.phone.require_mobile', false),
        ));

        // Singletons because they memoise driver instances: one worker sending ten
        // thousand messages should build one driver, not ten thousand.
        $this->app->singleton(GatewayRegistry::class, fn ($app): GatewayRegistry => new GatewayRegistry(
            (array) $app['config']->get('sms.drivers', []),
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
        $this->app->singleton(TemplateRenderer::class);
        $this->app->singleton(ParameterMapper::class);
        $this->app->singleton(MessageDispatcher::class);
        $this->app->singleton(DeliveryTracker::class);
        $this->app->singleton(SmsManager::class);
        $this->app->singleton(OtpManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sms.php' => $this->app->configPath('sms.php'),
            ], 'sms-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'sms-migrations');
        }
    }
}
