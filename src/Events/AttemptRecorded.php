<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Events;

use Mizbanha\Sms\Models\SmsAttempt;
use Mizbanha\Sms\Models\SmsMessage;

/**
 * One handover of one message to one gateway has just been written down.
 *
 * Dispatched immediately after the `sms_attempts` row is created, before the
 * circuit breaker records the result and before the message settles — so a
 * listener sees attempts in the order they happened, each followed by whatever it
 * caused.
 *
 * ⚠️ **Observational.** Nothing a listener does can change what happens next to
 * this message; see `Mizbanha\Sms\Support\Events`. A listener that throws is
 * logged and ignored.
 *
 * ⚠️ **The models are the application's own rows, as they are.** For a sensitive
 * message `body`, `variables`, `error` and `provider_payload` are already null, as
 * everywhere else. A listener that forwards anything off this machine is
 * responsible for choosing what — `mizbanha/sms-cloud-client`, for instance,
 * forwards counts, outcomes, failure kinds, driver names and durations, and never
 * the recipient, the wording or the provider's prose.
 */
final class AttemptRecorded
{
    /**
     * @param  int  $durationMs  wall-clock milliseconds this application spent
     *                           handing the message to the gateway, measured with a
     *                           monotonic clock around the driver call
     * @param  SmsAttempt|null  $previous  the attempt made immediately before this
     *                                     one IN THE SAME failover walk, or null when
     *                                     this is the first gateway tried in this
     *                                     run. Non-null means this attempt is a
     *                                     failover from `$previous`'s gateway. A
     *                                     later run of a released job starts a new
     *                                     walk, so its first attempt has a sequence
     *                                     above one and no `$previous`: that is a
     *                                     retry, not a failover.
     */
    public function __construct(
        public readonly SmsAttempt $attempt,
        public readonly SmsMessage $message,
        public readonly int $durationMs,
        public readonly ?SmsAttempt $previous = null,
    ) {}

    /** Whether this attempt exists because the gateway before it declined. */
    public function isFailover(): bool
    {
        return $this->previous !== null;
    }

    /** Whether this attempt began a later run of the same message (a queue retry). */
    public function isRetry(): bool
    {
        return $this->previous === null && (int) $this->attempt->sequence > 1;
    }
}
