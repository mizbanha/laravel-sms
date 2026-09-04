<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Exceptions;

/**
 * A template/gateway parameter map that cannot be read.
 *
 * Thrown rather than worked around, and this is the whole reason the exception
 * exists. A mapping is the order and the naming of the values inside a pattern
 * message; a half-understood one does not produce a slightly wrong SMS, it
 * produces a confident one with the amount where the order number should be. The
 * dispatcher records it as a gateway-level refusal, so the message can still go
 * out through a gateway whose mapping is intact.
 *
 * ⚠️ Names positions and parameter names only, never values: a map is
 * configuration, but the values flowing through it are somebody's data.
 */
final class InvalidParameterMap extends SmsException
{
    public static function notAList(string $context): self
    {
        return new self(sprintf(
            '%s has a parameter map that is not an ordered list. It must be a JSON array of '
            .'{"provider": ..., "variable": ...} entries, because array order is what carries '
            .'positional meaning.',
            $context,
        ));
    }

    public static function malformedEntry(string $context, int $position): self
    {
        return new self(sprintf(
            '%s has a malformed parameter map entry at position %d. Each entry needs a non-empty '
            .'[variable], and [provider] must be omitted, null, or a non-empty string.',
            $context,
            $position + 1,
        ));
    }

    public static function duplicateProvider(string $context, string $provider): self
    {
        return new self(sprintf(
            '%s maps two values onto the provider parameter [%s]. One of them would be silently '
            .'discarded.',
            $context,
            $provider,
        ));
    }
}
