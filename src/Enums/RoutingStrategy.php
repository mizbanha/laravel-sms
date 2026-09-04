<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Enums;

/**
 * How the FIRST gateway is chosen for one logical message.
 *
 * ⚠️ **Selection, never failover.** This enum decides where a message starts and
 * in what order the remaining gateways are offered it. What happens once a gateway
 * has answered is settled entirely by `MessageDispatcher` under rules that have not
 * changed since M2: an acceptance stops, an uncertain result stops permanently, an
 * unsafe refusal stops, and only a safe refusal moves on. No routing strategy can
 * weaken any of those, and none of them is inferred from a strategy.
 *
 * ⚠️ **A property of the logical message**, stored on `sms_templates`, because
 * different messages want different policies for good reasons: a login code should
 * start at the most reliable line every time, while order notifications are
 * ordinary traffic worth spreading across the accounts that are paid for.
 *
 * Three, deliberately, and no plugin point. `cheapest`, `fastest` and `healthiest`
 * are not routing strategies but pricing, latency and reputation models, each
 * needing data this package does not have and should not invent.
 */
enum RoutingStrategy: string
{
    /**
     * The configured order, every time. The default, and the behaviour the package
     * had before routing strategies existed.
     *
     * ⚠️ It needs no shared state at all: the order comes from the gateway rows in
     * one query, so it is the strategy that keeps working when the cache does not.
     */
    case Priority = 'priority';

    /**
     * Each new logical message starts one place further along the eligible
     * gateways, wrapping at the end.
     *
     * Equal shares by definition, and a cursor in the cache rather than a counter
     * in a process: an application with four queue workers has four processes, and
     * four counters that each begin at zero are four copies of the same first
     * gateway.
     */
    case RoundRobin = 'round_robin';

    /**
     * Round-robin over unequal shares.
     *
     * ⚠️ Deterministic, not random. Weights are ratios - 5, 3 and 2 mean five
     * messages, then three, then two, then round again - and nothing has to add up
     * to a hundred. A random draw weighted the same way would produce the same
     * ratio only in the long run, and "eventually, on average" is not a property
     * anybody can hold a provider contract to.
     */
    case WeightedRoundRobin = 'weighted_round_robin';

    /**
     * Whether this strategy needs the shared routing cursor.
     *
     * ⚠️ The question `RoutingPlanner` asks before touching the cache at all, so
     * that a `priority` template is unaffected by the cache store, by a lock
     * timeout, or by anything else the other two have to care about.
     */
    public function needsSharedState(): bool
    {
        return $this !== self::Priority;
    }
}
