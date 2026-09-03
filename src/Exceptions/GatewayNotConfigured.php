<?php

declare(strict_types=1);

namespace Amid\Sms\Exceptions;

/**
 * A gateway that cannot be used at all as it currently stands.
 *
 * ⚠️ Names the missing setting, never its value, and never the values of the
 * settings that ARE present.
 */
final class GatewayNotConfigured extends SmsException
{
    public static function missingCredential(string $gateway, string $name): self
    {
        return new self(sprintf('SMS gateway [%s] is missing the [%s] credential.', $gateway, $name));
    }

    public static function unknownDriver(string $gateway, string $driver): self
    {
        return new self(sprintf('SMS gateway [%s] names driver [%s], which is not registered.', $gateway, $driver));
    }

    public static function notADriver(string $gateway, string $class): self
    {
        return new self(sprintf('SMS gateway [%s] resolves to [%s], which is not a Driver.', $gateway, $class));
    }

    /**
     * The same two problems, asked about a driver NAME with no gateway behind it.
     *
     * A management layer asks what a driver can do before any gateway names it, so
     * there is nothing to put in the messages above. Separate factories rather than
     * a nullable gateway argument, because "gateway [] names driver [x]" is the
     * kind of message that gets read out during an incident.
     */
    public static function unregisteredDriver(string $driver): self
    {
        return new self(sprintf("SMS driver [%s] is not registered in config('laravel-sms.drivers').", $driver));
    }

    public static function driverClassNotADriver(string $driver, string $class): self
    {
        return new self(sprintf('SMS driver [%s] resolves to [%s], which is not a Driver.', $driver, $class));
    }

    /**
     * A send pinned to a gateway that does not exist.
     *
     * ⚠️ Named rather than a silent fallback to ordinary routing. `viaGateway()`
     * means "prove THIS gateway"; quietly sending through a different one would
     * answer a question nobody asked, and would look like a success.
     */
    public static function unknownGateway(string $key): self
    {
        return new self(sprintf('SMS gateway [%s] does not exist.', $key));
    }

    /**
     * A send pinned to a gateway model that was never saved.
     */
    public static function unsavedGateway(): self
    {
        return new self('A send can only be pinned to a gateway that exists in the database.');
    }
}
