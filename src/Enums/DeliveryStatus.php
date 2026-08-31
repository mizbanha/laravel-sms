<?php

declare(strict_types=1);

namespace Amid\Sms\Enums;

/**
 * What happened to a message AFTER a gateway accepted it.
 *
 * ⚠️ **This is not `SendOutcome` and it is not `MessageStatus`.** Those two answer
 * "did the provider take the request", which is a question that is finished the
 * moment the HTTP call returns. This one answers "did a handset receive it", which
 * is a different question, answered later, by a different endpoint, and sometimes
 * with the opposite verdict: an attempt is legitimately `SendOutcome::Accepted`
 * and `DeliveryStatus::Failed` at the same time, because the provider did take the
 * message and the carrier then could not deliver it.
 *
 * Overloading the send vocabulary to carry this would destroy both meanings. A
 * message marked `failed` because a carrier rejected it would look, to every retry
 * and failover rule in the package, exactly like a message that was never sent —
 * and this package would then send it again, to somebody who may already have it.
 *
 * ⚠️ **Nothing here influences sending.** Delivery information is observational.
 * It never triggers failover, never causes a resend, never re-classifies an
 * attempt and never takes part in OTP verification. By the time any of it exists,
 * the sending decision has already been made and cannot be unmade.
 *
 * Five states, deliberately. Providers publish far more — RCS read receipts,
 * per-operator queue states, marketing engagement — and a Core vocabulary that
 * tried to hold all of them would be a provider dictionary with a neutral name on
 * it.
 *
 * **Null is a sixth answer and it is not in this enum**: a driver that cannot
 * report delivery leaves the columns null, meaning "not tracked". That is not a
 * failure and not an `unsupported` state, because a message sent through a
 * provider with no report API is a perfectly ordinary message.
 */
enum DeliveryStatus: string
{
    /**
     * The provider accepted it and there is no terminal result yet.
     *
     * The state an accepted attempt starts in when its driver can report delivery.
     */
    case Pending = 'pending';

    /**
     * The upstream carrier has it. **The handset has not been confirmed.**
     *
     * ⚠️ Twilio is explicit that its own `sent` means "the nearest upstream carrier
     * accepted the outbound message" and nothing more. Treating that as delivery is
     * the single easiest mistake to make here, and it is the one that produces a
     * dashboard claiming 100% delivery to numbers that are switched off.
     */
    case Sent = 'sent';

    /** Positive confirmation of delivery to the recipient. Terminal. */
    case Delivered = 'delivered';

    /** The provider or carrier confirmed it was NOT delivered. Terminal. */
    case Failed = 'failed';

    /**
     * A provider status exists but cannot be mapped to any of the above truthfully.
     *
     * ⚠️ Distinct from null. Null is "nobody asked"; this is "we asked, and the
     * answer is not one we are willing to translate". An unrecognised status
     * guessed into `delivered` is a lie in the direction that stops anybody
     * investigating.
     */
    case Unknown = 'unknown';

    /**
     * Whether this verdict is final.
     *
     * Only positive confirmations either way. `sent` is not terminal — the carrier
     * still owes an answer — and `unknown` least of all.
     */
    public function isTerminal(): bool
    {
        return $this === self::Delivered || $this === self::Failed;
    }

    /**
     * Whether a newly observed status may replace what is already recorded.
     *
     * ⚠️ **Terminal evidence is never downgraded.** Report endpoints are eventually
     * consistent, paginated and occasionally cached; a poll that arrives after a
     * delivery confirmation can easily answer with an older row. Letting that
     * rewrite `delivered` back to `pending` would make the column oscillate and
     * would make every alert built on it untrustworthy.
     *
     * A terminal state does not move to the other terminal state either. Neither
     * provider here documents a legitimate reversal, and inventing a rule for one
     * would be exactly the kind of guess this package refuses elsewhere. A genuine
     * documented reversal is a change to make with the documentation in hand.
     *
     * The same status arriving again is allowed through: nothing changes except the
     * timestamp that says when we last asked.
     */
    public static function mayReplace(?self $current, self $next): bool
    {
        if ($current === null) {
            return true;
        }

        return $current->isTerminal() ? $next === $current : true;
    }
}
