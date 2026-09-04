<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Facades;

use Mizbanha\Sms\Sending\PendingMessage;
use Mizbanha\Sms\SmsManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static PendingMessage to(string $recipient)
 * @method static PendingMessage message()
 * @method static \Mizbanha\Sms\Results\DeliveryResult|null refreshDelivery(\Mizbanha\Sms\Models\SmsMessage|\Mizbanha\Sms\Models\SmsAttempt $target)
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
