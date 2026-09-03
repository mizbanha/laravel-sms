<?php

declare(strict_types=1);

namespace Amid\Sms;

use Amid\Sms\Contracts\PhoneNormalizer;
use Amid\Sms\Delivery\DeliveryTracker;
use Amid\Sms\Models\SmsAttempt;
use Amid\Sms\Models\SmsMessage;
use Amid\Sms\Results\DeliveryResult;
use Amid\Sms\Sending\MessageDispatcher;
use Amid\Sms\Sending\PendingMessage;
use Amid\Sms\Templates\TemplateRenderer;

/**
 * The package's front door.
 *
 * Deliberately thin. It owns no state and makes no decisions; it exists so that
 * every send starts from a fresh PendingMessage rather than a shared one, which is
 * the difference between two call sites building two messages and two call sites
 * corrupting each other.
 */
class SmsManager
{
    public function __construct(
        private readonly PhoneNormalizer $normalizer,
        private readonly TemplateRenderer $renderer,
        private readonly MessageDispatcher $dispatcher,
        private readonly DeliveryTracker $delivery,
    ) {}

    public function to(string $recipient): PendingMessage
    {
        return $this->message()->to($recipient);
    }

    public function message(): PendingMessage
    {
        return new PendingMessage($this->normalizer, $this->renderer, $this->dispatcher);
    }

    /**
     * Ask the provider what became of a message it accepted, and record the answer.
     *
     *     Sms::refreshDelivery($message);
     *     Sms::refreshDelivery($attempt);   // when you know which handover
     *
     * One method rather than a second facade: delivery is a question about
     * something this manager already sent, and a caller that has an `SmsMessage`
     * should not have to learn a second entry point to ask about it.
     *
     * ⚠️ **Explicit, and the only way delivery information is ever refreshed.**
     * Nothing polls in the background, nothing is scheduled, and reading
     * `$message->delivery_status` contacts nobody. Deciding WHICH messages are
     * worth asking about, and how often, is a management concern with real cost and
     * real provider rate limits attached - it belongs to whatever runs above this
     * package, not inside a send library.
     *
     * ⚠️ **It cannot change a send.** A message that was accepted stays accepted,
     * whatever a report endpoint says or fails to say. Null means nothing was
     * learned - not accepted, no provider id, a driver that cannot report, a report
     * API that was down - and in every one of those cases not one column changes.
     */
    public function refreshDelivery(SmsMessage|SmsAttempt $target): ?DeliveryResult
    {
        return $target instanceof SmsAttempt
            ? $this->delivery->refresh($target)
            : $this->delivery->refreshMessage($target);
    }

    /**
     * Whether this environment sends at all. See config/sms.php.
     */
    public function enabled(): bool
    {
        return (bool) config('laravel-sms.enabled', false);
    }
}
