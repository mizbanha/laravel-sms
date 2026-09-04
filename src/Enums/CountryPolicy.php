<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Enums;

/**
 * How a gateway's configured country list is read.
 *
 * Geographic coverage is a commercial fact about one account, not a property of a
 * provider's API — the same driver serves an account permitted to message thirty
 * countries and an account permitted to message one. So it lives on the gateway
 * row, editable at runtime, and no driver knows this feature exists.
 *
 * ⚠️ Three modes and a list, deliberately. Not a rule engine: no per-country
 * priorities, no cost or carrier routing, no MCC/MNC tables. Those are real
 * problems and none of them are this one, which is "should this gateway be offered
 * this destination at all".
 */
enum CountryPolicy: string
{
    /** Every destination. The default, and what an unconfigured gateway does. */
    case All = 'all';

    /** Only the listed countries. */
    case Allow = 'allow';

    /** Every country except the listed ones. */
    case Deny = 'deny';

    /**
     * Whether a gateway with this policy and this list may carry a message to this
     * destination.
     *
     * ⚠️ The unknown case is the one worth being deliberate about. A valid
     * non-geographic number — a satellite or international-network range — belongs
     * to no ISO country, and this package does not invent one for it. So:
     *
     *   - `all` carries it, because `all` means all;
     *   - `allow` does NOT, because an allow-list is a statement of where a gateway
     *     is known to work, and a destination that matches nothing on it has not
     *     been vouched for;
     *   - `deny` DOES, because a deny-list is a statement of where a gateway is
     *     known NOT to work, and an unknown destination is not on it.
     *
     * Both unknown cases resolve the same way the policy itself leans, which is the
     * behaviour an administrator would predict from the mode they chose.
     *
     * @param  string|null  $region  ISO 3166-1 alpha-2, or null for a destination
     *                               with no country
     * @param  list<string>  $countries  already normalised and validated
     */
    public function covers(?string $region, array $countries): bool
    {
        return match ($this) {
            self::All => true,
            self::Allow => $region !== null && in_array($region, $countries, true),
            self::Deny => $region === null || ! in_array($region, $countries, true),
        };
    }

    /**
     * Whether the country list is meaningful for this policy.
     *
     * `all` ignores it, which is why an empty list is the only sensible thing to
     * store beside it.
     */
    public function usesCountries(): bool
    {
        return $this !== self::All;
    }
}
