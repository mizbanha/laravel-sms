<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Exceptions;

use Mizbanha\Sms\Enums\RoutingStrategy;

/**
 * Routing configuration that cannot be read.
 *
 * ⚠️ Thrown when the value is WRITTEN, following the same rule as country
 * coverage: routing policy is small, rarely edited configuration, and the moment
 * somebody types it is the only moment there is anybody around to be told they got
 * it wrong.
 *
 * Both cases below fail quietly if they are allowed through. An unknown strategy
 * name would have to fall back to something, and a template silently routed by a
 * policy nobody chose is worse than a template that refused to save. A weight of
 * zero is worse still: it is a gateway an administrator has bound, enabled and
 * expects to see traffic on, which would receive none - and a whole binding set of
 * zeroes is a division by zero dressed up as configuration.
 */
final class InvalidRoutingConfiguration extends SmsException
{
    public static function unknownStrategy(string $value): self
    {
        return new self(sprintf(
            'Routing strategy [%s] is not one of: %s.',
            $value,
            implode(', ', array_column(RoutingStrategy::cases(), 'value')),
        ));
    }

    public static function weight(mixed $value, int $maximum): self
    {
        return new self(sprintf(
            'Gateway weight [%s] must be a whole number between 1 and %d. Weights are ratios, not '
            .'percentages: 5, 3 and 2 give the three gateways half, a third and a fifth of the traffic, '
            .'and so do 50, 30 and 20.',
            is_scalar($value) ? (string) $value : get_debug_type($value),
            $maximum,
        ));
    }
}
