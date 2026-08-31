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
}
