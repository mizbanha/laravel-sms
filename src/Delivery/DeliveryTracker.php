<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Delivery;

use Mizbanha\Sms\Contracts\PhoneNormalizer;
use Mizbanha\Sms\Contracts\ReportsDeliveryStatus;
use Mizbanha\Sms\Exceptions\DeliveryLookupFailed;
use Mizbanha\Sms\Exceptions\GatewayNotConfigured;
use Mizbanha\Sms\Gateways\GatewayRegistry;
use Mizbanha\Sms\Models\SmsAttempt;
use Mizbanha\Sms\Models\SmsMessage;
use Mizbanha\Sms\Phone\PhoneNumber;
use Mizbanha\Sms\Results\DeliveryResult;

/**
 * Asks a provider what became of a message it accepted, and writes down the
 * answer.
 *
 * ⚠️ **Explicit only.** Nothing here runs on a schedule, on a queue, or from a
 * model accessor. Reading `$message->delivery_status` reads a column and contacts
 * nobody — a property that quietly makes an HTTP request is the kind of thing that
 * turns an admin table of fifty rows into fifty provider calls, and does it from
 * inside a Blade template where nobody thinks to look for it.
 *
 * ⚠️ **This is observational and cannot change a send.** It never re-classifies an
 * attempt, never settles a message differently, never triggers failover and never
 * causes a resend. By the time any of this runs the provider has already accepted
 * the message; there is nothing left to decide, and the worst thing this code could
 * do is make somebody's phone buzz twice because a report endpoint was down.
 *
 * Which is why every failure path here does the same thing: **nothing**. A timeout,
 * an expired token, a gateway that has since been deleted, a driver that cannot
 * report at all — each returns null and leaves every column exactly as it was.
 * "We could not find out" and "it was not delivered" are different facts and this
 * package will not confuse them.
 */
final class DeliveryTracker
{
    public function __construct(
        private readonly GatewayRegistry $registry,
        private readonly PhoneNormalizer $normalizer,
    ) {}

    /**
     * Refresh the logical message, through the attempt that actually carried it.
     *
     * @return DeliveryResult|null  null when nothing was learned
     */
    public function refreshMessage(SmsMessage $message): ?DeliveryResult
    {
        $attempt = $message->acceptedAttempt();

        return $attempt === null ? null : $this->refresh($attempt);
    }

    /**
     * Refresh one attempt.
     *
     * The preconditions are checked in order of cost, and each of them is a real
     * state rather than an error:
     *
     *   1. the attempt was accepted — a refusal has nothing to report, because the
     *      provider never had the message;
     *   2. it has a provider message id — the report endpoints take exactly that,
     *      and there is no other way to name the message;
     *   3. its gateway still exists and still runs the same driver;
     *   4. that driver can actually report delivery.
     *
     * @return DeliveryResult|null  null when nothing was learned, for any reason
     */
    public function refresh(SmsAttempt $attempt): ?DeliveryResult
    {
        if (! $attempt->isAccepted()) {
            return null;
        }

        $providerMessageId = trim((string) $attempt->provider_message_id);

        if ($providerMessageId === '') {
            return null;
        }

        $driver = $this->driverFor($attempt);

        if (! $driver instanceof ReportsDeliveryStatus) {
            return null;
        }

        $message = $attempt->message;

        try {
            $result = $driver->deliveryStatus($providerMessageId, $this->recipient($message));
        } catch (DeliveryLookupFailed|GatewayNotConfigured $exception) {
            /*
             * ⚠️ The single most important line in this class.
             *
             * The report API could not be asked, or did not answer with a report.
             * That is a fact about the endpoint and not about the message: the send
             * still happened, the message row still says `accepted`, and the
             * delivery snapshot keeps whatever it already held rather than being
             * overwritten with a verdict about our own inability to ask.
             */
            return null;
        }

        /*
         * ⚠️ A sensitive message keeps no provider prose, here as everywhere else.
         *
         * This is the route M6 exists to close: IPPanel's recipient report returns
         * the original message text beside the status, so a delivery lookup for an
         * OTP would otherwise walk the code back into the database through a
         * reporting endpoint, days after the send deliberately refused to store it.
         * The neutral status and the provider's structured tokens are kept - they
         * are identifiers, not content - and the sentence is dropped, from what is
         * persisted AND from what is handed back to the caller.
         */
        $sensitive = (bool) $message?->is_sensitive;

        if ($sensitive && $result->error !== null) {
            $result = new DeliveryResult($result->status, $result->providerStatus, $result->providerErrorCode);
        }

        $attempt->applyDelivery(
            $result->status,
            $result->providerStatus,
            $result->providerErrorCode,
            $result->error,
        );

        /*
         * The summary follows the winning attempt and only the winning attempt.
         *
         * Re-read rather than assumed: this method can be handed any attempt, and
         * an old rejected one - or a second acceptance that should not exist - must
         * never be able to rewrite what the message says about itself.
         */
        if ($message !== null && $message->acceptedAttempt()?->is($attempt) === true) {
            $message->summariseDelivery($attempt->refresh());
        }

        return $result;
    }

    /**
     * The driver that carried this attempt, if it is still the same one.
     *
     * ⚠️ The driver check is not paranoia. A gateway row is runtime configuration:
     * somebody can re-point `primary` from one provider to another between the send
     * and the lookup. The stored id then belongs to the old provider, and asking the
     * new one about it would at best return nothing and at worst return somebody
     * else's message. The snapshotted driver name on the attempt is what makes that
     * detectable.
     */
    private function driverFor(SmsAttempt $attempt): ?object
    {
        $gateway = $attempt->gateway;

        if ($gateway === null || (string) $gateway->driver !== (string) $attempt->driver) {
            return null;
        }

        try {
            return $this->registry->driverFor($gateway);
        } catch (GatewayNotConfigured $exception) {
            // The driver this gateway names is no longer registered. A deployment
            // problem, and not one worth throwing at somebody who asked a question
            // about delivery.
            return null;
        }
    }

    /**
     * The destination, for the providers whose reports are per recipient.
     *
     * The stored value is already canonical E.164; the fallback exists only so that
     * a hand-edited row cannot produce a null here.
     */
    private function recipient(?SmsMessage $message): PhoneNumber
    {
        $to = (string) $message?->to;

        return $this->normalizer->normalize($to) ?? new PhoneNumber($to, $to, $message?->country_code);
    }
}
