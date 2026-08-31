<?php

declare(strict_types=1);

namespace Amid\Sms\Exceptions;

/**
 * The provider could not be asked what happened to a message, or did not answer
 * with a report.
 *
 * ⚠️ **This says nothing whatsoever about the message.** It is a fact about the
 * reporting endpoint: a timeout, an expired token, a report API that is down, a
 * body that is not the documented shape. The message was accepted — that happened,
 * it is recorded, and no failure to ask about it afterwards can un-accept it.
 *
 * So this exception never reaches the send path. It is caught by the delivery
 * refresh, which leaves every delivery column exactly as it found them and changes
 * no message status at all. Nothing here triggers failover or a resend.
 */
final class DeliveryLookupFailed extends SmsException
{
    public static function forGateway(string $gateway, string $reason): self
    {
        return new self(sprintf('Delivery status could not be read from gateway [%s]: %s', $gateway, $reason));
    }

    /**
     * The provider answered, but with something that is not a usable report — a
     * message id it does not recognise, or a report with no recipient in it.
     */
    public static function notReported(string $gateway, string $providerMessageId): self
    {
        return new self(sprintf(
            'Gateway [%s] returned no delivery information for message id [%s].',
            $gateway,
            $providerMessageId,
        ));
    }
}
