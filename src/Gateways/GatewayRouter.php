<?php

declare(strict_types=1);

namespace Amid\Sms\Gateways;

use Amid\Sms\Exceptions\GatewayNotConfigured;
use Amid\Sms\Models\SmsTemplate;
use Illuminate\Support\Facades\Log;

/**
 * Which gateways could carry this message, in configured priority order.
 *
 * Returns an ordered list rather than a single choice. That was deliberate before
 * failover existed - the difference between "this returns a list" and "this returns
 * one gateway" is the difference between adding failover as a loop and rewriting
 * the send path - and it is what everything since has been built on.
 *
 * ⚠️ **Eligibility, never distribution.** This class answers a static question from
 * the database, in one query, with no side effects and no cache: which gateways
 * COULD carry this message. Which of them goes first is a separate question with a
 * separate answer, and it lives in `RoutingPlanner`, because it needs shared state
 * that a query builder has no business holding. Priority order is what this returns
 * and what the planner starts from.
 *
 * A candidate has to pass five tests, all of them cheap and none of them
 * provider-specific:
 *
 *   1. the gateway is enabled;
 *   2. the binding is enabled;
 *   3. the binding is complete - a pattern binding with no registered code is
 *      dropped, never downgraded to free text;
 *   4. the driver declares the capability the binding's mode requires;
 *   5. the gateway is configured to serve the destination's country.
 *
 * The last two are what keep provider knowledge out of this class. The router does
 * not know that one provider cannot do patterns, or that another cannot deliver to
 * Iran; it asks the driver about the first and the gateway row about the second.
 *
 * ⚠️ **Everything filtered here is routing, not failure.** A gateway that is
 * dropped is never called, records no attempt, is not a provider failure, and does
 * not consume the failover budget. An attempt row is evidence about a provider, and
 * a log full of "Twilio refused an Iranian number" from a gateway nobody expected
 * to carry Iranian numbers is worse evidence than no row at all.
 */
final class GatewayRouter
{
    public function __construct(private readonly GatewayRegistry $registry) {}

    /**
     * @param  string|null  $region  the destination's ISO 3166-1 alpha-2 country,
     *                               or null both for a destination that has none
     *                               and for a caller that is not routing by country
     * @return list<GatewayCandidate>
     */
    public function candidatesFor(SmsTemplate $template, ?string $region = null): array
    {
        $bindings = $template->gatewayBindings()
            // Joined rather than filtered through whereHas, because the ordering
            // below is by a column on the gateway. Every column is qualified: both
            // tables have is_enabled, and an unqualified one is ambiguous.
            ->join('sms_gateways', 'sms_gateways.id', '=', 'sms_template_gateways.sms_gateway_id')
            ->where('sms_template_gateways.is_enabled', true)
            ->where('sms_gateways.is_enabled', true)
            ->with('gateway')
            // Lower priority first; the id is the tie-break, so the order is stable
            // across runs rather than left to the database to decide.
            ->orderBy('sms_gateways.priority')
            ->orderBy('sms_gateways.id')
            ->select('sms_template_gateways.*')
            ->get();

        $candidates = [];

        foreach ($bindings as $binding) {
            if (! $binding->isUsable() || $binding->gateway === null) {
                continue;
            }

            try {
                $driver = $this->registry->driverFor($binding->gateway);
            } catch (GatewayNotConfigured $exception) {
                // A gateway naming a driver that no longer exists is a deployment
                // problem, not a reason to fail this message: the next candidate may
                // be perfectly able to carry it. Recorded so it is fixable, skipped
                // so it is survivable.
                Log::warning('SMS gateway skipped: '.$exception->getMessage());

                continue;
            }

            if (! in_array($binding->mode->requiredCapability(), $driver->capabilities(), true)) {
                continue;
            }

            if (! $binding->gateway->serves($region)) {
                /*
                 * Not for this destination.
                 *
                 * Silent, unlike the driver problem above, because this is a
                 * correct outcome rather than a fault: a gateway configured for one
                 * country is SUPPOSED to be skipped for another, and warning about
                 * it would mean a log line per message per gateway forever.
                 */
                continue;
            }

            $candidates[] = new GatewayCandidate($binding->gateway, $binding, $driver);
        }

        return $candidates;
    }
}
