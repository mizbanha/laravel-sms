<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Events;

use Mizbanha\Sms\Enums\DeliveryStatus;
use Mizbanha\Sms\Models\SmsAttempt;
use Mizbanha\Sms\Results\DeliveryResult;

/**
 * A provider's report endpoint was asked about one accepted attempt.
 *
 * Dispatched only when a request was actually made — an attempt that was never
 * accepted, has no provider id, or whose driver cannot report is not "checked",
 * and nothing is announced for it.
 *
 * ⚠️ `$result` null means the lookup FAILED (the endpoint could not be asked, or
 * did not answer with a report). It never means "not delivered", and the attempt's
 * columns were left exactly as they were.
 *
 * ⚠️ Observational; see `AttemptRecorded`. For a sensitive message the result
 * carries no provider prose, as everywhere else.
 */
final class DeliveryChecked
{
    public function __construct(
        public readonly SmsAttempt $attempt,
        public readonly ?DeliveryStatus $previous,
        public readonly ?DeliveryResult $result,
    ) {}

    public function succeeded(): bool
    {
        return $this->result !== null;
    }

    /** The verdict now recorded on the attempt, which a stale report cannot downgrade. */
    public function current(): ?DeliveryStatus
    {
        return $this->attempt->delivery_status;
    }

    /** Whether the recorded verdict actually moved. */
    public function changed(): bool
    {
        return $this->result !== null && $this->current() !== $this->previous;
    }
}
