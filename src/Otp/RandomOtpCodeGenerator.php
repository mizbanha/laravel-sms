<?php

declare(strict_types=1);

namespace Amid\Sms\Otp;

use Amid\Sms\Contracts\OtpCodeGenerator;

/**
 * The production generator.
 *
 * ⚠️ `random_int()`, not `rand()` or `mt_rand()`. This is the one place in the
 * package where predictability is a security failure rather than an inconvenience:
 * a code an attacker can guess is a code an attacker can use, and the seedable
 * generators are guessable by design. `random_int()` draws from the operating
 * system's CSPRNG and throws rather than silently degrading if it cannot.
 *
 * ⚠️ Digit by digit, so that leading zeros survive. Drawing one number in
 * `[0, 10^n)` and padding it would be equivalent, but `str_pad` on an integer is
 * exactly where a `042193` becomes `42193` when somebody later changes the type.
 */
final class RandomOtpCodeGenerator implements OtpCodeGenerator
{
    public function generate(int $length): string
    {
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= (string) random_int(0, 9);
        }

        return $code;
    }
}
