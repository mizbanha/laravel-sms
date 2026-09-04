<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Gateways;

use Mizbanha\Sms\Contracts\Driver;
use Mizbanha\Sms\Enums\Capability;
use Mizbanha\Sms\Exceptions\GatewayNotConfigured;
use Mizbanha\Sms\Models\SmsGateway;

/**
 * The one place a Driver is built.
 *
 * Drivers are registered in config by name, and gateways in the database name one
 * of those registrations. That split is the whole of decision "drivers are code":
 * an operator can add, enable and re-credential a gateway at runtime, but a new
 * PROVIDER is a class, and a class arrives by deployment. A class name stored in
 * the database is a class name a refactor renames and nothing notices.
 *
 * ⚠️ **Driver instances are NOT memoised, and that is a correctness decision.**
 *
 * They were, per gateway id, on the reasoning that one worker sending ten thousand
 * messages should build one driver rather than ten thousand. The reasoning was
 * wrong twice over. A driver is a configuration wrapper: every one in this package
 * holds nothing but class constants and a readonly GatewayConfig, opens no
 * connection, and builds its PendingRequest fresh inside each call - so there is no
 * state worth keeping and nothing expensive to rebuild. Meanwhile the cache was
 * keyed on the gateway's primary key, which does not change when its CREDENTIALS
 * do: a gateway re-credentialed inside one request handed the next caller a driver
 * still carrying the old key, silently, and the only cure would have been every
 * caller remembering to invalidate something. Constructing a small object per call
 * costs less than one line of that class of bug.
 */
final class GatewayRegistry
{
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

    /**
     * A driver built from this gateway's configuration AS IT STANDS NOW.
     *
     * ⚠️ Always a fresh instance. See the class comment: a gateway saved a moment
     * ago must be the gateway that sends.
     */
    public function driverFor(SmsGateway $gateway): Driver
    {
        $name = (string) $gateway->driver;
        $class = $this->resolve(
            $name,
            fn (): GatewayNotConfigured => GatewayNotConfigured::unknownDriver((string) $gateway->key, $name),
            fn (string $class): GatewayNotConfigured => GatewayNotConfigured::notADriver((string) $gateway->key, $class),
        );

        return new $class($gateway->config());
    }

    /**
     * What a registered driver can do, asked by NAME rather than by gateway.
     *
     * The question a management layer asks before any gateway exists: which modes
     * may a binding use, and can this provider report delivery at all. Answering it
     * by reading a table maintained somewhere else would be a second source of
     * truth for a fact the driver already states, and the two would eventually
     * disagree - which is the whole reason this method exists rather than a
     * published list.
     *
     * ⚠️ Capabilities are treated as a property of the CLASS, so the probe is built
     * with an empty configuration and no credential is needed or read. Every driver
     * in this package returns a fixed array, and a driver whose capabilities
     * genuinely varied by account would have to be asked through driverFor()
     * instead - it could not honestly answer this question anyway.
     *
     * ⚠️ Nothing is sent, nothing is contacted and no request is made. The driver is
     * constructed and asked one question.
     *
     * @return list<Capability>
     *
     * @throws GatewayNotConfigured when the name is not registered, or names a class that is not a Driver
     */
    public function capabilitiesFor(string $driver): array
    {
        $name = trim($driver);
        $class = $this->resolve(
            $name,
            fn (): GatewayNotConfigured => GatewayNotConfigured::unregisteredDriver($name),
            fn (string $class): GatewayNotConfigured => GatewayNotConfigured::driverClassNotADriver($name, $class),
        );

        return (new $class(new GatewayConfig(key: $name, sender: null)))->capabilities();
    }

    /**
     * Registered name to Driver class, or the caller's own named failure.
     *
     * The two callers describe the same two problems differently - one knows which
     * gateway asked, the other only knows a driver name - and the messages matter,
     * so the checks are shared and the wording is not.
     *
     * @param  callable(): GatewayNotConfigured  $unknown
     * @param  callable(string): GatewayNotConfigured  $notADriver
     * @return class-string<Driver>
     */
    private function resolve(string $name, callable $unknown, callable $notADriver): string
    {
        $class = $this->registrations[$name] ?? null;

        if (! is_string($class) || ! class_exists($class)) {
            throw $unknown();
        }

        if (! is_subclass_of($class, Driver::class)) {
            throw $notADriver($class);
        }

        return $class;
    }
}
