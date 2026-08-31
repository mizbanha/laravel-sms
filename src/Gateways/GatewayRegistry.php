<?php

declare(strict_types=1);

namespace Amid\Sms\Gateways;

use Amid\Sms\Contracts\Driver;
use Amid\Sms\Exceptions\GatewayNotConfigured;
use Amid\Sms\Models\SmsGateway;

/**
 * The one place a Driver is built.
 *
 * Drivers are registered in config by name, and gateways in the database name one
 * of those registrations. That split is the whole of decision "drivers are code":
 * an operator can add, enable and re-credential a gateway at runtime, but a new
 * PROVIDER is a class, and a class arrives by deployment. A class name stored in
 * the database is a class name a refactor renames and nothing notices.
 *
 * Instances are memoised per gateway id, so a run of ten thousand messages through
 * one worker builds one driver rather than ten thousand.
 */
final class GatewayRegistry
{
    /** @var array<int|string, Driver> */
    private array $drivers = [];

    /**
     * @param  array<string, class-string>  $registrations  driver name => class
     */
    public function __construct(private readonly array $registrations) {}

    /**
     * @return list<string>
     */
    public function registered(): array
    {
        return array_keys($this->registrations);
    }

    public function driverFor(SmsGateway $gateway): Driver
    {
        $cacheKey = $gateway->getKey() ?? $gateway->key;

        return $this->drivers[$cacheKey] ??= $this->build($gateway);
    }

    private function build(SmsGateway $gateway): Driver
    {
        $name = (string) $gateway->driver;
        $class = $this->registrations[$name] ?? null;

        if (! is_string($class) || ! class_exists($class)) {
            throw GatewayNotConfigured::unknownDriver((string) $gateway->key, $name);
        }

        if (! is_subclass_of($class, Driver::class)) {
            throw GatewayNotConfigured::notADriver((string) $gateway->key, $class);
        }

        return new $class($gateway->config());
    }
}
