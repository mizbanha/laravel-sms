<?php

declare(strict_types=1);

namespace Amid\Sms\Contracts;

/**
 * Produces the digits of a one-time code.
 *
 * ⚠️ A contract for exactly one reason: a test cannot assert that the code which
 * reached Kavenegar is the code which reached SMS.ir, or that it verifies against
 * the stored hash, unless it knows what the code was — and the public API
 * deliberately never returns one. Binding a deterministic implementation in a test
 * is the only way to see it without adding a way for production code to leak it.
 *
 * This is not a random-number framework and must not become one. One method, one
 * secure default.
 */
interface OtpCodeGenerator
{
    /**
     * @param  int  $length  number of digits
     * @return string  the code, as a string — leading zeros are significant and an
     *                 integer would eat them
     */
    public function generate(int $length): string;
}
