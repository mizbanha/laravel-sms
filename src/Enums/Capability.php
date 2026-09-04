<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Enums;

/**
 * What a driver can actually do.
 *
 * A driver declares its capabilities and the router filters candidates by what a
 * message needs. This is what keeps provider knowledge inside drivers instead of
 * accumulating as provider-specific branches in the orchestrator.
 */
enum Capability: string
{
    /** Free text from a rented line. */
    case Text = 'text';

    /** Wording registered with the operator in advance; only values are supplied. */
    case Pattern = 'pattern';

    /**
     * The provider can report actual delivery to the handset, later, out of band.
     *
     * ⚠️ A driver declaring this MUST implement `ReportsDeliveryStatus`. The
     * capability is what a management screen reads to know the question is worth
     * asking; the interface is what makes the answer possible. A driver that
     * advertises one without the other is lying to everything above it, so the two
     * are added together or not at all.
     *
     * Unlike Text and Pattern this is not part of routing: a message does not
     * "need" a delivery report, and a gateway is never skipped for lacking one.
     */
    case DeliveryReport = 'delivery_report';
}
