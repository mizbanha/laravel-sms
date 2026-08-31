<?php

declare(strict_types=1);

namespace Amid\Sms\Sending;

use Amid\Sms\Contracts\ReportsDeliveryStatus;
use Amid\Sms\Enums\DeliveryMode;
use Amid\Sms\Enums\DeliveryStatus;
use Amid\Sms\Enums\FailureKind;
use Amid\Sms\Enums\MessageStatus;
use Amid\Sms\Enums\SendOutcome;
use Amid\Sms\Contracts\PhoneNormalizer;
use Amid\Sms\Exceptions\GatewayNotConfigured;
use Amid\Sms\Exceptions\InvalidParameterMap;
use Amid\Sms\Exceptions\MissingVariables;
use Amid\Sms\Gateways\GatewayCandidate;
use Amid\Sms\Gateways\GatewayRouter;
use Amid\Sms\Health\CircuitBreaker;
use Amid\Sms\Models\SmsAttempt;
use Amid\Sms\Models\SmsMessage;
use Amid\Sms\Phone\PhoneNumber;
use Amid\Sms\Results\SendResult;
use Amid\Sms\Templates\ParameterMapper;
use Amid\Sms\Templates\TemplateRenderer;
use Throwable;

/**
 * Hands one recorded message to one gateway and writes down what happened.
 *
 * The single owner of the act of sending: both the synchronous path and the queued
 * job come through here, so there is one place where an attempt is recorded and one
 * place where a message's status is decided.
 *
 * Two rules govern everything below.
 *
 * ⚠️ **It does not throw.** Whatever happens - an unconfigured gateway, a driver
 * that misbehaves and throws when it should not - is recorded as an attempt and
 * settled on the message. Sending is almost always a side effect of something more
 * important, and an exception escaping here would roll back the order that the
 * message was merely announcing.
 *
 * ⚠️ **It never decides retry policy from an exception or a status code.** The
 * driver already answered that on the result, and this class only reads the two
 * flags.
 */
final class MessageDispatcher
{
    public function __construct(
        private readonly GatewayRouter $router,
        private readonly ParameterMapper $mapper,
        private readonly PhoneNormalizer $normalizer,
        private readonly TemplateRenderer $renderer,
        private readonly CircuitBreaker $breaker,
    ) {}

    /**
     * Try to deliver a message once.
     *
     * @param  array<string, string|int|float|null>  $variables  passed in rather
     *         than read from the row, so that a future sensitive message - which
     *         persists no variables at all - can still be sent from a queue payload
     *         that is deleted the moment the job succeeds
     * @param  bool  $mayRetry  whether the caller is able to try again on this same
     *                          gateway; false for a synchronous send, which has
     *                          nowhere to retry from
     * @return SendResult|null  null when nothing was attempted at all
     */
    public function attempt(SmsMessage $message, array $variables, bool $mayRetry = false): ?SendResult
    {
        if ($message->isSettled()) {
            // Somebody else finished this message while this call was in flight.
            return null;
        }

        $template = $message->template;

        if ($template === null) {
            // The wording was deleted after the message was recorded. The row keeps
            // what was said, but there is nothing left to route on.
            $message->transitionTo(MessageStatus::Failed, 'The template this message was built from no longer exists.');

            return null;
        }

        /*
         * Routed on the country recorded WITH the message, not on one re-derived
         * here. A routing input recomputed in several places is one that can differ
         * between them - most visibly when a released job runs days later - and the
         * snapshot is also what makes the decision explicable afterwards, since it
         * sits in the log beside the attempts it produced.
         */
        $candidates = $this->eligible(
            $this->router->candidatesFor($template, $message->country_code),
            $message,
        );

        if ($candidates === []) {
            // A runtime configuration state, not a caller error: every gateway that
            // could carry this message is disabled, unbound, incapable, not
            // configured for this destination's country, or has already refused it
            // definitively. Recorded on the row where an operator can see it rather
            // than thrown at whoever happened to trigger it.
            $message->transitionTo(MessageStatus::Failed, 'No eligible gateway can carry this message.');

            return null;
        }

        $message->transitionTo(MessageStatus::Sending);

        $result = null;
        $retryable = false;
        $attempted = false;

        /*
         * The failover chain.
         *
         * Each eligible gateway is called at most once, in the router's order. A
         * gateway is never immediately retried inside this loop: a retry is a
         * decision for the next run, taken with a delay, not something to hammer
         * out here.
         */
        foreach ($candidates as $candidate) {
            if (! $this->breaker->allows($candidate->gateway)) {
                /*
                 * ⚠️ Skipped, not failed. This gateway answered the last several
                 * requests with a timeout or an outage, so this message does not
                 * spend fifteen seconds discovering that again before reaching the
                 * gateway behind it.
                 *
                 * Nothing is recorded: no attempt row, no provider error, no
                 * sequence number consumed. An attempt row is evidence about a
                 * PROVIDER, and this is a decision taken entirely on this side of
                 * the wire - inventing a rejection the provider never made would
                 * put fiction into the one table that exists to be trusted during
                 * an incident.
                 */
                continue;
            }

            $attempted = true;

            $result = $this->deliver($candidate, $message, $variables);

            $this->record($message, $candidate, $result);

            /*
             * ⚠️ Health is recorded AFTER the attempt and affects only later
             * messages. It cannot reach back into the decisions below: an uncertain
             * result still stops this message permanently, whatever this call
             * concludes about the gateway. Opening a circuit and then continuing
             * the same message elsewhere is the duplicate send this package is
             * built to prevent.
             */
            $this->breaker->record($candidate->gateway, $result);

            $retryable = $retryable || $result->retryableOnSameGateway;

            if ($result->outcome === SendOutcome::Accepted) {
                $message->transitionTo(MessageStatus::Accepted);

                return $result;
            }

            if ($result->outcome === SendOutcome::Uncertain) {
                /*
                 * ⚠️ The chain stops here, permanently.
                 *
                 * This gateway may already have the message. Handing the same
                 * message to a second gateway now is precisely how one person
                 * receives it twice, and no amount of remaining candidates makes
                 * that acceptable — this is the single most important line in the
                 * failover implementation.
                 */
                $message->transitionTo(MessageStatus::Unknown, $this->reason($message, $result));

                return $result;
            }

            if (! $result->safeToFailover) {
                // A refusal the next gateway would repeat: the recipient, or the
                // message itself. Moving on would be a loop with a known ending.
                $message->transitionTo(MessageStatus::Failed, $this->reason($message, $result));

                return $result;
            }
        }

        if (! $attempted) {
            /*
             * Every gateway that could have carried this message is currently
             * circuit-open. Nothing was called and nothing was recorded.
             *
             * ⚠️ For a QUEUED send this is not a failure yet - an open circuit is
             * temporary by definition, and the job that owns retry can come back
             * when it is not. The message is left unsettled, which is the existing
             * signal the job already reads; no second retry mechanism, no delayed
             * job aimed at the cooldown expiry, no scheduler. If the last allowed
             * attempt still finds nothing to call, the branch below settles it.
             *
             * A synchronous send has no future attempt, so it settles now - with
             * our own words, because no provider said anything.
             */
            if ($mayRetry) {
                return null;
            }

            $message->transitionTo(
                MessageStatus::Failed,
                'Every gateway that could carry this message is temporarily unavailable.',
            );

            return null;
        }

        /*
         * Every eligible gateway refused, and each refusal was safe to move on
         * from.
         *
         * If one of them said it was worth trying again on that same gateway, and
         * the caller has a future attempt to try it in, the message is left
         * unsettled for that attempt. A synchronous send has no such future, so it
         * settles here rather than pretending something will pick it up.
         */
        if ($mayRetry && $retryable) {
            $message->transitionTo(MessageStatus::Sending, $this->reason($message, $result));

            return $result;
        }

        $message->transitionTo(MessageStatus::Failed, $this->reason($message, $result));

        return $result;
    }

    /**
     * The provider's explanation, for the message row - or nothing.
     *
     * ⚠️ **A sensitive message stores no provider prose anywhere**, and this is one
     * of the two places it would otherwise arrive. Providers quote the request back
     * inside a refusal: "the text «Your code is 482193» was rejected". M5 scrubbed
     * that text by substituting the values it knew about, which was a partial
     * defence dressed as a guarantee - it could not protect a one-character value
     * without destroying every diagnostic that happened to contain that character,
     * so it silently exempted them. A sensitive value is not safe to persist
     * because it is short.
     *
     * So the prose is not stored at all. What is lost is the provider's wording;
     * what remains is every structured fact - the outcome, the failure kind, both
     * policy flags, the gateway and driver, the sequence, the provider message id -
     * which is the audit trail, and which does not need a sentence to be readable.
     */
    private function reason(SmsMessage $message, ?SendResult $result): ?string
    {
        return $message->is_sensitive ? null : $result?->error;
    }

    /**
     * The candidates this run is actually allowed to call.
     *
     * Ordinarily every candidate the router returned. It matters on a SECOND run —
     * a released job coming back — where the previous run's attempts are evidence
     * rather than something to replay: a gateway that already gave a definitive
     * refusal must not be called again just because Laravel restarted the job.
     *
     * A gateway whose last result explicitly said it was worth retrying stays
     * eligible, and a gateway that has been enabled since the last run is eligible
     * because the router offered it and it has no history to exclude it.
     *
     * @param  list<GatewayCandidate>  $candidates
     * @return list<GatewayCandidate>
     */
    private function eligible(array $candidates, SmsMessage $message): array
    {
        if (! $message->exists || $message->attempts()->doesntExist()) {
            return $candidates;
        }

        // A gateway is spent when it has an attempt that did not ask to be retried.
        $spent = $message->attempts()
            ->where('retryable_on_same_gateway', false)
            ->pluck('sms_gateway_id')
            ->filter()
            ->all();

        return array_values(array_filter(
            $candidates,
            static fn (GatewayCandidate $candidate): bool => ! in_array(
                $candidate->gateway->getKey(),
                $spent,
                true,
            ),
        ));
    }

    /**
     * Build the outbound message for this candidate and hand it over.
     *
     * Everything a driver could get wrong about our data - the number, the wording,
     * the parameter names and their order - is settled before the driver is
     * involved.
     */
    private function deliver(GatewayCandidate $candidate, SmsMessage $message, array $variables): SendResult
    {
        $binding = $candidate->binding;

        try {
            $parameters = $binding->mode === DeliveryMode::Pattern
                ? $this->mapper->map(
                    $binding->parameter_map,
                    $message->template?->variables() ?? [],
                    $variables,
                    sprintf('Pattern [%s]', (string) $message->template?->key),
                )
                : [];
        } catch (MissingVariables $exception) {
            // The binding asks for a value this send does not have. Nothing to
            // retry and nothing another gateway would fix on its own, but the
            // logical message may well be sendable elsewhere with a different
            // mapping, so it is a gateway-level refusal.
            return SendResult::rejected(FailureKind::GatewayRejected, $exception->getMessage());
        } catch (InvalidParameterMap $exception) {
            // This binding's mapping cannot be read, so this gateway cannot carry
            // the message until somebody fixes it. Another gateway's mapping may be
            // perfectly good, and nothing was sent, so the chain continues.
            return SendResult::rejected(FailureKind::GatewayConfiguration, $exception->getMessage());
        }

        // The stored value is already canonical E.164, so this cannot fail; the
        // fallback exists only so a hand-edited row cannot produce a null here.
        $to = $this->normalizer->normalize((string) $message->to)
            ?? new PhoneNumber((string) $message->to, (string) $message->to, $message->country_code);

        $outbound = new OutboundMessage(
            to: $to,
            mode: $binding->mode,
            body: $this->body($message, $variables),
            patternCode: $binding->pattern_code,
            parameters: $parameters,
            sender: $candidate->gateway->sender,
            sensitive: (bool) $message->is_sensitive,
        );

        try {
            return $candidate->driver->send($outbound);
        } catch (GatewayNotConfigured $exception) {
            // The gateway is unusable as it stands. A legitimate thing for a driver
            // to throw, and a legitimate thing to record: another gateway can carry
            // this message, but repeating it here will fail identically until
            // somebody edits the configuration.
            return SendResult::rejected(FailureKind::GatewayConfiguration, $exception->getMessage());
        } catch (Throwable $exception) {
            /*
             * A driver that threw where the contract says it must not.
             *
             * Treated as uncertain rather than failed, and this is the conservative
             * choice on purpose: the exception may have been raised after the
             * provider was already given the message. Assuming otherwise would make
             * a driver bug into duplicate messages for real people.
             */
            return SendResult::uncertain(
                FailureKind::Network,
                $candidate->gateway->config()->redact($exception::class.': '.$exception->getMessage()),
            );
        }
    }

    /**
     * The wording to send.
     *
     * ⚠️ A sensitive message has no stored body — that is the point of it — so the
     * text is rendered again here from the template and the variables that came in
     * with this run. The values live in memory and in the (encrypted) job payload
     * for the seconds a send takes; the row keeps neither.
     *
     * For every other message the stored body is authoritative and used unchanged:
     * it is the record of what was actually said, and re-rendering it would let an
     * edited template rewrite history.
     *
     * @param  array<string, string|int|float|null>  $variables
     */
    private function body(SmsMessage $message, array $variables): ?string
    {
        if (! $message->is_sensitive || $message->template === null) {
            return $message->body;
        }

        return $this->renderer->render(
            (string) $message->template->body,
            $variables,
            sprintf('Template [%s]', (string) $message->template->key),
        );
    }

    private function record(SmsMessage $message, GatewayCandidate $candidate, SendResult $result): void
    {
        $sensitive = (bool) $message->is_sensitive;

        $attempt = SmsAttempt::query()->create([
            'sms_message_id' => $message->getKey(),
            'sms_gateway_id' => $candidate->gateway->getKey(),
            // Snapshotted, so the evidence survives the gateway being renamed or
            // deleted.
            'gateway_key' => $candidate->gateway->key,
            'driver' => $candidate->gateway->driver,
            'sequence' => $message->attempts()->count() + 1,
            'mode' => $candidate->binding->mode,
            'pattern_code' => $candidate->binding->pattern_code,
            'outcome' => $result->outcome,
            'failure_kind' => $result->failureKind,
            'retryable_on_same_gateway' => $result->retryableOnSameGateway,
            'safe_to_failover' => $result->safeToFailover,
            // The provider's identifier, byte for byte as it arrived. Never
            // scrubbed: it is an opaque id, not content, and a later delivery
            // lookup quotes it back.
            'provider_message_id' => $result->providerMessageId,
            /*
             * ⚠️ Null for a sensitive message - absent, not scrubbed.
             *
             * Providers quote the request back inside a refusal, and some echo the
             * parameters in a response body. A partial scrub cannot be a guarantee:
             * it has to know every value to remove, and it cannot remove a
             * one-character one without destroying the diagnostic it is trying to
             * preserve. Confidentiality is not traded for a provider's prose, so
             * the prose goes. Everything above this line is the audit trail, and
             * all of it is structured.
             */
            'error' => $sensitive ? null : $result->error,
            /*
             * Null for a sensitive message. A provider-neutral subset of a payload
             * that no two providers shape alike would be guesswork about which keys
             * are safe, and the cost of being wrong once is a stored secret. Null is
             * the honest answer and costs a diagnostic nobody should have anyway.
             */
            'provider_payload' => $sensitive ? null : $result->providerPayload,
            /*
             * Where delivery tracking begins, and it begins without an extra
             * request.
             *
             * An acceptance through a driver that can report delivery is `pending`
             * by definition: the provider has the message and has not yet said what
             * became of it. Asking immediately would spend a request to be told
             * `queued`, which we already know. Everything else - a rejection, or an
             * acceptance by a driver with no report API - stays null, meaning not
             * tracked rather than not delivered.
             */
            'delivery_status' => $result->outcome === SendOutcome::Accepted
                && $candidate->driver instanceof ReportsDeliveryStatus
                    ? DeliveryStatus::Pending
                    : null,
        ]);

        if ($attempt->delivery_status !== null) {
            $message->summariseDelivery($attempt);
        }
    }

}
