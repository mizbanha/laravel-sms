<?php

declare(strict_types=1);

namespace Amid\Sms\Facades;

use Amid\Sms\Sending\PendingMessage;
use Amid\Sms\SmsManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static PendingMessage to(string $recipient)
 * @method static PendingMessage message()
 * @method static \Amid\Sms\Results\DeliveryResult|null refreshDelivery(\Amid\Sms\Models\SmsMessage|\Amid\Sms\Models\SmsAttempt $target)
 * @method static bool enabled()
 *
 * @see SmsManager
 */
class Sms extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SmsManager::class;
    }
}
