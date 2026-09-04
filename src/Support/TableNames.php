<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Support;

use Mizbanha\Sms\Exceptions\InvalidTableConfiguration;

/**
 * The names of this package's five tables, and the only place they are decided.
 *
 * An application that already owns a table called `sms_messages` cannot install
 * this package, because `Schema::create()` refuses a name that exists and the
 * migration fails outright. That is not a hypothetical: it is exactly what the
 * first consumer of this package hit. So every table this package creates is
 * configurable, and the default map reproduces the original names byte for byte.
 *
 * ⚠️ **This is a schema mapping, not a runtime rename.** Configuring a name is a
 * decision taken once, before the first migration. Changing it afterwards does not
 * move a single row: the package simply starts looking at a table that is not
 * there. See the README section "Table names" and section 12 of the handoff.
 *
 * ⚠️ **Resolved from configuration on every call, never memoised.** A cached map
 * would be read once and then be wrong for the rest of the process the moment a
 * test — or an application booting a second container — changed the configuration
 * underneath it. Five array reads are not worth a staleness bug in the one value
 * that decides which table a write lands in.
 *
 * ⚠️ **Deliberately not read from the environment.** Every other setting in
 * `config/sms.php` takes an `env()` default because it is genuinely a per-deployment
 * choice; a table name is not. Two environments disagreeing about the master switch
 * is a configuration difference, two environments disagreeing about which table
 * holds the messages is two different schemas, and the second one is discovered by
 * a migration that runs against the wrong table in production.
 */
final class TableNames
{
    public const GATEWAYS = 'gateways';

    public const TEMPLATES = 'templates';

    public const TEMPLATE_GATEWAYS = 'template_gateways';

    public const MESSAGES = 'messages';

    public const ATTEMPTS = 'attempts';

    /**
     * ⚠️ The names this package has always used. An application that configures
     * nothing must keep exactly these, so this array is also the compatibility
     * promise: changing a value here is a breaking change for every existing
     * installation, not a default tweak.
     *
     * @var array<string, string>
     */
    public const DEFAULTS = [
        self::GATEWAYS => 'sms_gateways',
        self::TEMPLATES => 'sms_templates',
        self::TEMPLATE_GATEWAYS => 'sms_template_gateways',
        self::MESSAGES => 'sms_messages',
        self::ATTEMPTS => 'sms_attempts',
    ];

    /**
     * ⚠️ MySQL allows 64 characters for a table name and the same 64 for an INDEX
     * name — and Laravel builds index names out of the table name. The longest one
     * this package generates is the morph index on the messages table,
     * `{table}_reference_type_reference_id_index`, whose suffix is 34 characters.
     * A 64-character table name would therefore migrate on SQLite and fail on MySQL
     * with a truncated-identifier error, which is the worst place to find out.
     *
     * 30 leaves that longest index at exactly 64. `SchemaIdentifierTest` proves it
     * empirically against a real MySQL server rather than trusting this arithmetic.
     */
    private const MAX_LENGTH = 30;

    /**
     * Characters that break Laravel's query grammar or are an injection vector.
     *
     * ⚠️ A blacklist rather than an `^[a-z_][a-z0-9_]*$` whitelist, on purpose. The
     * whitelist would be simpler and would refuse table names that MySQL and
     * PostgreSQL both accept — anything non-ASCII, anything hyphenated — for no
     * reason this package can defend. What is listed here is what actually goes
     * wrong: a dot is read by Laravel's `wrap()` as a schema separator and would
     * silently split the name in two; a quote, a backtick or a backslash escapes
     * the identifier the grammar is building; whitespace and control characters
     * produce a name nothing can refer to again.
     */
    private const FORBIDDEN = ['.', '`', '"', "'", '\\', ';', "\0", "\n", "\r", "\t"];

    /**
     * @return array<string, string> the configured map, validated
     */
    public static function all(): array
    {
        $names = [];

        foreach (array_keys(self::DEFAULTS) as $key) {
            $names[$key] = self::get($key);
        }

        self::assertDistinct($names);

        return $names;
    }

    public static function gateways(): string
    {
        return self::get(self::GATEWAYS);
    }

    public static function templates(): string
    {
        return self::get(self::TEMPLATES);
    }

    public static function templateGateways(): string
    {
        return self::get(self::TEMPLATE_GATEWAYS);
    }

    public static function messages(): string
    {
        return self::get(self::MESSAGES);
    }

    public static function attempts(): string
    {
        return self::get(self::ATTEMPTS);
    }

    /**
     * One configured name, validated.
     *
     * ⚠️ Validated on every read rather than once at boot, because a name that
     * reaches a query unchecked is a name that reaches the database. The whole-map
     * check — which additionally catches duplicates — runs once at boot in
     * `SmsServiceProvider`, so an application with a broken map finds out when it
     * starts rather than when it first sends.
     */
    public static function get(string $key): string
    {
        if (! array_key_exists($key, self::DEFAULTS)) {
            throw InvalidTableConfiguration::unknownKey($key, array_keys(self::DEFAULTS));
        }

        /*
         * ⚠️ The default applies only when the key is ABSENT. A key that is present
         * and empty is a mistake somebody made — a blank line in a published config,
         * an env() that resolved to nothing — and falling back to `sms_messages`
         * there would silently write to a table the application may already own,
         * which is the exact accident this class exists to prevent.
         */
        $configured = config('laravel-sms.tables.'.$key, self::DEFAULTS[$key]);

        if (! is_string($configured)) {
            throw InvalidTableConfiguration::notAString($key, $configured);
        }

        return self::validated($key, $configured);
    }

    /**
     * The whole map, checked once. Called from the service provider's boot.
     */
    public static function validate(): void
    {
        self::all();
    }

    private static function validated(string $key, string $name): string
    {
        // Not trimmed and accepted: a name with a space at the end is a typo, and
        // quietly repairing it would mean the config file and the database disagree
        // about what the table is called.
        if ($name === '' || trim($name) !== $name || trim($name) === '') {
            throw InvalidTableConfiguration::blank($key, $name);
        }

        foreach (self::FORBIDDEN as $character) {
            if (str_contains($name, $character)) {
                throw InvalidTableConfiguration::forbiddenCharacter($key, $name);
            }
        }

        if (mb_strlen($name) > self::MAX_LENGTH) {
            throw InvalidTableConfiguration::tooLong($key, $name, self::MAX_LENGTH);
        }

        return $name;
    }

    /**
     * @param  array<string, string>  $names
     */
    private static function assertDistinct(array $names): void
    {
        if (count(array_unique($names)) === count($names)) {
            return;
        }

        $duplicates = array_keys(array_diff_key($names, array_unique($names)));

        throw InvalidTableConfiguration::duplicate($duplicates, $names);
    }
}
