<?php

declare(strict_types=1);

namespace Amid\Sms\Exceptions;

/**
 * The caller supplied something that is not a sendable number.
 *
 * Thrown rather than recorded, and it is the only recipient problem that is: the
 * message row stores the canonical destination in a non-null column, so there is
 * no row to record this against. It is also a mistake in the calling code or in
 * the data behind it, which is a bug, not an event in the world.
 */
final class InvalidRecipient extends SmsException
{
    public static function for(?string $value): self
    {
        return new self(sprintf('[%s] is not a sendable phone number.', $value ?? 'null'));
    }
}
