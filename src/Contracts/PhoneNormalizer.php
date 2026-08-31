<?php

declare(strict_types=1);

namespace Amid\Sms\Contracts;

use Amid\Sms\Phone\PhoneNumber;

/**
 * Turns whatever a caller has into one canonical number, or says it cannot.
 *
 * Small on purpose: it is the seam that keeps the phone-parsing library out of
 * the rest of the package, and swapping the implementation is a container binding.
 */
interface PhoneNormalizer
{
    /**
     * @param  string|null  $defaultRegion  ISO 3166-1 alpha-2 used only when the
     *                                      input carries no country code of its own
     * @return PhoneNumber|null  null when the input cannot be a sendable number
     */
    public function normalize(?string $value, ?string $defaultRegion = null): ?PhoneNumber;

    /**
     * Whether this is a region code the normaliser recognises.
     *
     * Here rather than in a separate country library because the numbering data
     * already behind this interface knows the answer, and a second source of truth
     * about which countries exist is a second thing to keep current. It also keeps
     * that knowledge on the same side of the seam as everything else about phone
     * numbers: a gateway's country list is validated against exactly the regions
     * messages can actually be classified into, so a code that would never match
     * anything cannot be saved.
     *
     * @param  string  $region  ISO 3166-1 alpha-2, uppercase
     */
    public function supportsRegion(string $region): bool;
}
