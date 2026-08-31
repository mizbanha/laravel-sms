<?php

declare(strict_types=1);

namespace Amid\Sms\Enums;

/**
 * Why an attempt did not succeed, in provider-neutral terms.
 *
 * A driver translates its provider's own vocabulary into one of these. Nothing
 * upstream reads a provider status code or an exception message to decide what to
 * do next.
 *
 * These are a classification, not a policy: what the orchestrator is allowed to do
 * next is carried explicitly on the result itself, because the safe answer is not
 * always derivable from the kind alone.
 */
enum FailureKind: string
{
    /** The destination number is not one this gateway will accept. */
    case InvalidRecipient = 'invalid_recipient';

    /** The message itself is unsendable anywhere — nothing to fail over to. */
    case InvalidMessage = 'invalid_message';

    /** Credentials, sender line, account state. This gateway is unusable as configured. */
    case GatewayConfiguration = 'gateway_configuration';

    /**
     * This gateway refused this message for a reason particular to it: an
     * unregistered pattern code, a parameter limit, an unapproved sender.
     *
     * The logical message is fine. Another gateway may well be able to send it.
     */
    case GatewayRejected = 'gateway_rejected';

    /** The provider is up but is not accepting work right now. */
    case ProviderUnavailable = 'provider_unavailable';

    /** The request did not complete. Whether it arrived is not knowable from here. */
    case Network = 'network';
}
