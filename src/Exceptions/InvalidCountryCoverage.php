<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Exceptions;

/**
 * A gateway whose country configuration cannot be read.
 *
 * ⚠️ Thrown when the coverage is WRITTEN, not when a message is sent. A country
 * list is small, rarely edited configuration, and the moment somebody types it is
 * the only moment there is anybody around to be told they typed it wrong. Half an
 * hour later it is a gateway that silently never routes anything, which is
 * indistinguishable from a gateway nobody has any traffic for.
 *
 * `UK` is the case that motivates validating against real region codes rather than
 * just the shape: it is a plausible thing to type, it is not an ISO 3166-1 code —
 * the United Kingdom is `GB` — and an allow-list containing it would simply never
 * match anything, forever, without an error anywhere.
 */
final class InvalidCountryCoverage extends SmsException
{
    public static function malformed(string $value): self
    {
        return new self(sprintf(
            'Gateway country [%s] is not an ISO 3166-1 alpha-2 code. Use two letters, such as IR, US or DE.',
            $value,
        ));
    }

    public static function unknownRegion(string $code): self
    {
        return new self(sprintf(
            'Gateway country [%s] is not a region this package can route to. Note that the United Kingdom '
            .'is [GB] rather than [UK].',
            $code,
        ));
    }

    public static function unknownPolicy(string $value): self
    {
        return new self(sprintf(
            'Gateway country policy [%s] is not one of: all, allow, deny.',
            $value,
        ));
    }
}
