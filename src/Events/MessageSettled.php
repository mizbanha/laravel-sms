<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Events;

use Mizbanha\Sms\Enums\MessageStatus;
use Mizbanha\Sms\Models\SmsMessage;

/**
 * A message has reached a state from which nothing further happens on its own:
 * accepted, failed, unknown or suppressed.
 *
 * Dispatched once per message, at the moment it first becomes settled — a message
 * that is written again in a settled state does not announce itself again.
 * `delivered` is reached only through delivery tracking, which has its own event.
 *
 * ⚠️ Observational; see `AttemptRecorded`.
 */
final class MessageSettled
{
    /**
     * @param  MessageStatus|null  $previous  the unsettled state it left, or null for
     *                                        a message recorded already settled
     *                                        (suppressed by the master switch)
     */
    public function __construct(
        public readonly SmsMessage $message,
        public readonly MessageStatus $status,
        public readonly ?MessageStatus $previous = null,
    ) {}
}
