<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Enums;

/**
 * How far a logical message got.
 *
 * "Accepted" is a statement about the gateway, not about the handset. Delivered is
 * a separate case reached only by a delivery report, which nothing in this package
 * produces yet.
 */
enum MessageStatus: string
{
    /** Recorded, not yet handed to a gateway. */
    case Queued = 'queued';

    /** In flight, or awaiting another attempt on the same gateway. */
    case Sending = 'sending';

    /** A gateway took responsibility for it. */
    case Accepted = 'accepted';

    /** A provider confirmed it reached the handset. Not produced yet. */
    case Delivered = 'delivered';

    /** No gateway took it, and none will without intervention. */
    case Failed = 'failed';

    /** Sending is switched off in this environment. A correct outcome, not a fault. */
    case Suppressed = 'suppressed';

    /**
     * An attempt ended without a knowable result.
     *
     * The terminal state of an uncertain send: it is never automatically retried,
     * because the provider may already have delivered it.
     */
    case Unknown = 'unknown';

    /**
     * Whether anything further will happen to this message on its own.
     *
     * The one question the queue asks before doing any work, and the reason a
     * retried job cannot re-send a message another worker already settled.
     */
    public function isSettled(): bool
    {
        return ! in_array($this, [self::Queued, self::Sending], true);
    }
}
