<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Templates;

/**
 * The placeholder syntax, in one place.
 *
 * ⚠️ Deliberately narrow: {name}, a lowercase identifier, nothing else. Allowing
 * dots or spaces would make {customer.tier} and {first name} look valid to whoever
 * typed them while matching nothing — and a dotted path is exactly the application
 * model coupling this package refuses to have.
 */
final class PlaceholderParser
{
    private const SYNTAX = '/\{([a-z][a-z0-9_]*)\}/';

    /**
     * The variables a body refers to, in the order they first appear, without
     * repeats.
     *
     * ⚠️ Order matters and is not incidental. It is the reading order of the
     * wording an operator approved, and it is what a positional provider's
     * parameters are matched against when no explicit mapping was configured.
     *
     * @return list<string>
     */
    public static function extract(string $body): array
    {
        preg_match_all(self::SYNTAX, $body, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    public static function placeholder(string $name): string
    {
        return '{'.$name.'}';
    }
}
