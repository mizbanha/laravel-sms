<?php

declare(strict_types=1);

namespace Amid\Sms\Exceptions;

/**
 * A placeholder the caller did not fill in.
 *
 * ⚠️ Thrown, not rendered around. "Your balance is  Toman" reads as a broken
 * system to the recipient and as nothing at all to whoever sent it, and by the
 * time anyone notices, the batch has gone. It is thrown before anything is
 * persisted or sent, so there is nothing to undo.
 *
 * A blank value counts as missing: an empty string is a lookup that found nothing,
 * not a deliberate blank.
 */
final class MissingVariables extends SmsException
{
    /**
     * @param  list<string>  $names
     */
    public static function forNames(array $names, string $context): self
    {
        return new self(sprintf('%s cannot be rendered without: %s.', $context, implode(', ', $names)));
    }
}
