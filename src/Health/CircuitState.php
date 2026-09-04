<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Health;

/**
 * What this application currently believes about one gateway's availability.
 *
 * ⚠️ **Local evidence about one account, not a statement about a provider.** This
 * package has no global knowledge: an account that is rate limited, out of credit
 * or pointed at a wrong host looks exactly like this while the provider is
 * perfectly healthy for everybody else. The vocabulary is therefore "the gateway
 * circuit", never "Twilio is down" — a distinction that matters the moment
 * anything reports these states to a human.
 */
enum CircuitState: string
{
    /** Normal. Nothing recent suggests this gateway cannot be reached. */
    case Closed = 'closed';

    /**
     * Recent transport evidence is bad enough to stop spending requests on it.
     *
     * The gateway is skipped without being called, which is routing rather than
     * failure: no attempt row, no provider error, no failover budget spent.
     */
    case Open = 'open';

    /**
     * The cooldown has passed and the gateway is owed one careful try.
     *
     * ⚠️ Exactly one, at a time. "The cooldown expired, so everybody calls it
     * again" is how a recovering provider is knocked over by the traffic that was
     * waiting for it.
     */
    case HalfOpen = 'half_open';
}
