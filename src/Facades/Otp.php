<?php

declare(strict_types=1);

namespace Amid\Sms\Facades;

use Amid\Sms\Otp\OtpManager;
use Amid\Sms\Otp\OtpResult;
use Illuminate\Support\Facades\Facade;

/**
 * @method static OtpResult send(string $to, string $template, array $variables = [], ?string $purpose = null)
 * @method static bool verify(string $to, string $code, string $purpose)
 * @method static void forget(string $to, string $purpose)
 *
 * @see OtpManager
 */
class Otp extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return OtpManager::class;
    }
}
