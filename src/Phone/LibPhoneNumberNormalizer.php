<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Phone;

use Mizbanha\Sms\Contracts\PhoneNormalizer;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

/**
 * The one implementation of PhoneNormalizer, over libphonenumber.
 *
 * Written rather than hand-rolled because "which prefixes are mobile in which
 * country" is a dataset that changes, not a rule that can be encoded once. The
 * library owns that; this class owns only the two things it does not do for us.
 *
 * ⚠️ Nothing here leaks upward. The library's own types stay inside this file and
 * a plain PhoneNumber comes out, so the dependency is one binding away from being
 * replaced.
 */
final class LibPhoneNumberNormalizer implements PhoneNormalizer
{
    private readonly PhoneNumberUtil $util;

    public function __construct(
        private readonly string $defaultRegion = 'IR',
        private readonly bool $requireMobile = false,
    ) {
        $this->util = PhoneNumberUtil::getInstance();
    }

    public function normalize(?string $value, ?string $defaultRegion = null): ?PhoneNumber
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $candidate = $this->toLatinDigits($value);
        $region = strtoupper($defaultRegion ?? $this->defaultRegion);

        try {
            $parsed = $this->util->parse($candidate, $region);
        } catch (NumberParseException) {
            // A rejection, not an error: the caller handed us something that is not
            // a number, which is answered with null like every other unusable input.
            return null;
        }

        if (! $this->util->isValidNumber($parsed)) {
            return null;
        }

        if ($this->requireMobile && ! $this->isMobile($parsed)) {
            // Opt-in only, and off by default. A library classifies a number, but
            // that classification is not a universal statement about whether the
            // number can receive an SMS - the relationship varies by country and
            // carrier - so refusing on line type alone would reject valid
            // international destinations. An application that knows its own
            // destinations are mobile-only can switch this on.
            return null;
        }

        return new PhoneNumber(
            e164: $this->util->format($parsed, PhoneNumberFormat::E164),
            national: $this->digitsOnly($this->util->format($parsed, PhoneNumberFormat::NATIONAL)),
            /*
             * ⚠️ Null rather than a fallback, and this is a correction.
             *
             * This used to fall back to the DEFAULT region when the library could
             * not place a number, which meant a valid non-geographic number - a
             * satellite or international-network range, +882 and +883 - was
             * labelled Iranian because Iran happened to be the configured default.
             * Country-aware routing turns that from a cosmetic wrong answer into a
             * message offered to gateways that were never meant to see it, so the
             * honest answer is that some numbers have no country.
             */
            region: $this->region($parsed),
        );
    }

    /**
     * The destination's country, or null when it genuinely has none.
     *
     * ⚠️ Two answers are folded into null here, and both used to be wrong.
     *
     * libphonenumber returns its own pseudo-region `001` for a valid
     * NON-GEOGRAPHIC number - a satellite or international-network range such as
     * +883 - which is not an ISO 3166-1 country and never will be. And where it can
     * place nothing at all, this class used to substitute the configured DEFAULT
     * region, which meant such a number was labelled Iranian because Iran happened
     * to be the default.
     *
     * Either would now be a message routed to gateways chosen for a country it is
     * not in. Some numbers have no country; saying so is the only honest answer.
     */
    private function region(\libphonenumber\PhoneNumber $parsed): ?string
    {
        $region = $this->util->getRegionCodeForNumber($parsed);

        return $region === null || $region === PhoneNumberUtil::REGION_CODE_FOR_NON_GEO_ENTITY
            ? null
            : $region;
    }

    /**
     * The regions this numbering data can actually place a number into.
     *
     * `getSupportedRegions()` is libphonenumber's own list, so a gateway can only
     * be configured for countries a destination could genuinely be classified as -
     * which is the point. `UK` fails here; `GB` passes.
     */
    public function supportsRegion(string $region): bool
    {
        return in_array(strtoupper(trim($region)), $this->util->getSupportedRegions(), true);
    }

    private function isMobile(\libphonenumber\PhoneNumber $parsed): bool
    {
        return in_array(
            $this->util->getNumberType($parsed),
            [PhoneNumberType::MOBILE, PhoneNumberType::FIXED_LINE_OR_MOBILE],
            true,
        );
    }

    /**
     * Persian and Arabic-Indic digits to Latin.
     *
     * ⚠️ Not a nicety. A number pasted from an Iranian phone's contacts arrives as
     * ۰۹۱۲۱۲۳۴۵۶۷, and without this it is either refused as unparseable or, worse,
     * stored in a form that will never match the Latin spelling of the same phone.
     */
    private function toLatinDigits(string $value): string
    {
        return strtr($value, [
            // Extended Arabic-Indic (Persian)
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            // Arabic-Indic
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    private function digitsOnly(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
