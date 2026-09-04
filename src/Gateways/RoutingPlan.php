<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Gateways;

/**
 * One message's routing decision: the order to try, and where it was pointed
 * first.
 *
 * The two are not the same thing, which is why both are here. The ORDER is
 * consumed immediately and forgotten; the PRIMARY is worth recording on the
 * message, because a queued job that Laravel releases and runs again is the same
 * logical message and must not be re-pointed by other people's traffic in the
 * meantime.
 */
final readonly class RoutingPlan
{
    /**
     * @param  list<GatewayCandidate>  $candidates  every eligible gateway, in the
     *         order this message should be offered to them. ⚠️ Never a narrowed
     *         list: a strategy decides ORDER, and a gateway removed here would be
     *         a gateway silently dropped from failover.
     * @param  int|null  $primaryGatewayId  the gateway a strategy deliberately
     *         chose to lead with, or null when nothing chose - a `priority`
     *         template, a single candidate, or a moment when no gateway was in a
     *         state to receive traffic at all
     */
    public function __construct(
        public array $candidates,
        public ?int $primaryGatewayId = null,
    ) {}
}
