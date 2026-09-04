<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Phone;

use Stringable;

/**
 * One destination number, already canonical.
 *
 * Both forms are computed once, at normalisation, and carried together because
 * they are both needed and deriving one from the other later would put a parser
 * back inside the drivers.
 *
 * ⚠️ This is deliberately a plain value object. No parsing library type reaches
 * this class or anything above it, so the library behind the normaliser can be
 * replaced without touching a driver, a model, or the public API.
 */
final readonly class PhoneNumber implements Stringable
{
    /**
     * @param  string  $e164  the stored, canonical form: +989121234567
     * @param  string  $national  digits as the number is dialled inside its own
     *                            country: 09121234567. Iranian gateways want this
     *                            form and nothing else.
     * @param  string|null  $region  ISO 3166-1 alpha-2, e.g. IR.
     *
     * ⚠️ Null is a real answer, not a failure. A valid non-geographic number - a
     * satellite or international-network range - belongs to no country, and this
     * package does not invent one for it. Country-aware gateway routing reads this,
     * and a guessed country there means a message offered to a gateway that was
     * never meant to carry it.
     */
    public function __construct(
        public string $e164,
        public string $national,
        public ?string $region,
    ) {}

    public function __toString(): string
    {
        return $this->e164;
    }
}
