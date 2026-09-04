<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Exceptions;

use Mizbanha\Sms\Support\TableNames;

/**
 * A `sms.tables` entry that cannot be used as a table name.
 *
 * ⚠️ **Thrown rather than defaulted, always.** Every other invalid setting in this
 * package could arguably fall back to something sensible; this one cannot. The
 * default names are `sms_gateways`, `sms_templates` and so on, and an application
 * configures its own precisely because it already owns tables with those names —
 * so falling back on a typo would point the package at somebody else's data and
 * write to it. A migration that refuses to run is recoverable in a minute; a
 * migration that ran against the wrong table is not.
 *
 * Every message below names the configuration key, so the fix is a file and a line
 * rather than a search.
 */
final class InvalidTableConfiguration extends SmsException
{
    /**
     * @param  list<string>  $known
     */
    public static function unknownKey(string $key, array $known): self
    {
        return new self(sprintf(
            'There is no SMS table named [%s]. This package owns exactly: %s.',
            $key,
            implode(', ', $known),
        ));
    }

    public static function notAString(string $key, mixed $value): self
    {
        return new self(sprintf(
            'config(\'laravel-sms.tables.%s\') must be a table name, and is %s.',
            $key,
            get_debug_type($value),
        ));
    }

    public static function blank(string $key, string $value): self
    {
        return new self(sprintf(
            'config(\'laravel-sms.tables.%s\') is %s. A table name cannot be empty or padded with whitespace; '
            .'remove the key entirely to use the default [%s].',
            $key,
            $value === '' ? 'empty' : sprintf('[%s], which has leading or trailing whitespace', $value),
            TableNames::DEFAULTS[$key] ?? $key,
        ));
    }

    public static function forbiddenCharacter(string $key, string $value): self
    {
        return new self(sprintf(
            'config(\'laravel-sms.tables.%s\') is [%s], which contains a character that cannot appear in a table '
            .'name: a dot, a quote, a backtick, a backslash, a semicolon or whitespace. A dot in '
            .'particular is read as a schema separator and would silently split the name in two.',
            $key,
            $value,
        ));
    }

    public static function tooLong(string $key, string $value, int $maximum): self
    {
        return new self(sprintf(
            'config(\'laravel-sms.tables.%s\') is [%s], which is %d characters. The limit is %d — not because a '
            .'table name may not be longer, but because Laravel builds index names out of it and MySQL '
            .'allows 64 characters for those too. The longest index this package generates adds 34.',
            $key,
            $value,
            mb_strlen($value),
            $maximum,
        ));
    }

    /**
     * @param  list<string>  $duplicated
     * @param  array<string, string>  $names
     */
    public static function duplicate(array $duplicated, array $names): self
    {
        return new self(sprintf(
            'The SMS tables [%s] are configured with a name another SMS table already uses. Each of the '
            .'five tables needs its own: %s.',
            implode(', ', $duplicated),
            implode(', ', array_map(
                static fn (string $key, string $name): string => $key.' => '.$name,
                array_keys($names),
                $names,
            )),
        ));
    }
}
