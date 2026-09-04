<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Drivers;

use Mizbanha\Sms\Contracts\Driver;
use Mizbanha\Sms\Contracts\ReportsDeliveryStatus;
use Mizbanha\Sms\Drivers\Concerns\InteractsWithHttp;
use Mizbanha\Sms\Enums\Capability;
use Mizbanha\Sms\Enums\DeliveryStatus;
use Mizbanha\Sms\Enums\FailureKind;
use Mizbanha\Sms\Exceptions\DeliveryLookupFailed;
use Mizbanha\Sms\Exceptions\GatewayNotConfigured;
use Mizbanha\Sms\Gateways\GatewayConfig;
use Mizbanha\Sms\Phone\PhoneNumber;
use Mizbanha\Sms\Results\DeliveryResult;
use Mizbanha\Sms\Results\SendResult;
use Mizbanha\Sms\Sending\OutboundMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * Twilio Programmable Messaging — and the proof that this package's provider
 * abstraction was not quietly shaped around Iranian providers.
 *
 * Everything about this provider is different from the other four. It
 * authenticates with HTTP Basic rather than a key in a header, body or URL; it
 * wants the canonical E.164 number rather than a national one; it answers 201
 * with a resource rather than 200 with a verdict; its identifiers are prefixed
 * strings (`SM…`) rather than numbers; and it reports failure as a numeric code in
 * a documented dictionary. None of that reached the orchestrator, and no file
 * above this one changed to accommodate it.
 *
 *   POST https://api.twilio.com/2010-04-01/Accounts/{AccountSid}/Messages.json
 *   Basic auth: Account SID as the username, Auth Token as the password
 *   Body: application/x-www-form-urlencoded, with To, From and Body
 *
 * ⚠️ **Twilio does not deliver to Iran.** Its own error documentation for 21408
 * says so in as many words: "Do not retry traffic to recipients in Iran, Syria, or
 * Cuba expecting Geo Permissions to restore delivery." This driver exists to carry
 * INTERNATIONAL destinations that the Iranian providers cannot, in the same
 * failover chain as them, not to replace any of them. A gateway configured with
 * this driver and pointed at an Iranian number will refuse — and the refusal is
 * failable over, so an Iranian gateway behind it picks the message up.
 *
 * ⚠️ **Text only, deliberately.** Twilio's Content API (`ContentSid` /
 * `ContentVariables`) is its template system and it is NOT implemented here. See
 * the handoff: it maps onto this package's Template/Gateway model more neatly than
 * expected, but whether SMS content sends require a Messaging Service is not
 * settled by the current documentation, and Test Credentials cannot exercise
 * Messaging Services at all. Claiming pattern support on a route nothing can
 * verify would be feature parity for its own sake.
 *
 * ⚠️ **`queued` means accepted, not delivered.** The status in the creation
 * response is Twilio taking responsibility for processing; the handset has not
 * been reached and may never be. This package records the message as `accepted`
 * and stores the Message SID.
 *
 * Since M6 that SID is also the key to asking what actually happened:
 * `deliveryStatus()` below fetches the message resource and normalises its status.
 * ⚠️ **Polling only.** Twilio's push mechanism is `StatusCallback`, a webhook URL
 * per message or per Messaging Service, and it is deliberately not implemented: it
 * needs a route, a controller, request-signature validation and a publicly
 * reachable host, none of which belong in a Core send library and none of which
 * Test Credentials can exercise. Push can be added later without changing the
 * normalised delivery model, which is the point of having one.
 */
final class TwilioDriver implements Driver, ReportsDeliveryStatus
{
    use InteractsWithHttp {
        classify as private classifyTransport;
    }

    private const DEFAULT_URL = 'https://api.twilio.com/2010-04-01';

    /**
     * The documented error codes this driver acts on, and what it is allowed to do
     * about each.
     *
     * `[FailureKind, retryableOnSameGateway, safeToFailover, meaning]`.
     *
     * ⚠️ **Deliberately small.** Twilio publishes thousands of codes and warns that
     * their causes evolve; an exhaustive dictionary transcribed here would be stale
     * within a release and would read as authority it does not have. These ten are
     * the ones whose current documented meaning is unambiguous AND which Test
     * Credentials can actually produce, so every row is a claim that can be checked
     * against the real API the day credentials exist. Anything else is conservative.
     */
    private const ERRORS = [
        /*
         * The recipient. Not failable over: the same number reaches the next
         * gateway unchanged, so another provider would refuse it for the same
         * reason — this is a fact about the destination, not about Twilio.
         */
        21211 => [FailureKind::InvalidRecipient, false, false, 'the destination number is not a valid E.164 number'],
        21614 => [FailureKind::InvalidRecipient, false, false, 'the destination number is not a valid mobile number; Twilio reports it as a landline or otherwise unable to receive SMS'],

        /*
         * ⚠️ CONSENT. The one refusal in this package that is not an engineering
         * judgement. See the comment on optedOut() below — this row must not be
         * changed to allow failover.
         */
        21610 => [FailureKind::InvalidRecipient, false, false, 'the recipient has opted out of messages from this sender by replying STOP or another opt-out keyword; this package will not route around that'],

        /*
         * The sender, and the account behind it. Every one of these is a fact about
         * THIS gateway: another gateway has its own sender, its own account and its
         * own geographic permissions, so the same logical message may well go out
         * through it.
         */
        21212 => [FailureKind::GatewayConfiguration, false, true, 'the configured sender is not a valid phone number, alphanumeric sender id or approved sender'],
        21606 => [FailureKind::GatewayConfiguration, false, true, 'the configured sender is not a message-capable number on this Twilio account'],
        21408 => [FailureKind::GatewayConfiguration, false, true, 'this Twilio account does not have messaging permission for the destination region (Messaging Geo Permissions)'],

        /*
         * The pairing rather than either end of it. Twilio's own documented
         * remedy is "try sending again with a different To and From combination",
         * which is precisely what failing over to another gateway is.
         */
        21612 => [FailureKind::GatewayRejected, false, true, 'Twilio cannot route this destination and sender combination; its own guidance is to try a different sender'],

        /*
         * Capacity on this sender. The only Twilio code here worth trying again on
         * the same gateway: the queue drains, unlike an invalid number or a
         * disabled region. Another gateway has its own queue, so moving on is
         * better still.
         */
        21611 => [FailureKind::ProviderUnavailable, true, true, 'the queue for this sender is full'],

        /*
         * The message. Not failable over: an empty body is empty at every
         * provider, and a body over the limit is over most providers' limits.
         * Sending it round the chain produces the same refusal each time.
         */
        21602 => [FailureKind::InvalidMessage, false, false, 'the message has no body'],
        21617 => [FailureKind::InvalidMessage, false, false, 'the message body exceeds the 1600 character limit'],
    ];

    /**
     * ⚠️ Twilio's opt-out code. Named separately from the table because the table
     * is a set of engineering judgements and this one is not.
     */
    private const OPTED_OUT = 21610;

    public function __construct(private readonly GatewayConfig $config) {}

    /**
     * The delivery-report capability is declared because `deliveryStatus()` below
     * genuinely implements it. The two must not drift apart, or the capability
     * becomes a claim rather than a fact.
     */
    public function capabilities(): array
    {
        return [Capability::Text, Capability::DeliveryReport];
    }

    public function send(OutboundMessage $message): SendResult
    {
        return $this->perform(
            fn (): Response => $this->http()
                // ⚠️ Basic auth, which is unique to this driver in this package.
                // The credentials travel in an Authorization header built by the
                // HTTP client and never appear in a body this driver constructs.
                ->withBasicAuth(
                    $this->config->requireCredential('account_sid'),
                    $this->config->requireCredential('auth_token'),
                )
                ->asForm()
                ->post($this->url(), [
                    // ⚠️ The canonical E.164 value, with its plus. Every other
                    // driver here wants the Iranian national form; sending that to
                    // Twilio would be an unroutable number with no country code.
                    'To' => $message->to->e164,
                    ...$this->sender($message),
                    'Body' => (string) $message->body,
                ]),
            $this->interpret(...),
        );
    }

    /**
     * `From`, or a Messaging Service, and never both.
     *
     * Twilio treats these as alternatives: a Messaging Service carries its own
     * sender pool and its own settings, and supplying a `From` alongside it is
     * either ignored or an error depending on the service. So the gateway option
     * wins outright when it is present.
     *
     * ⚠️ Test Credentials cannot use a Messaging Service — Twilio documents that
     * explicitly — so a gateway configured this way is not exercisable by the
     * integration harness. That is a limitation of the harness, not of the driver.
     *
     * @return array<string, string>
     */
    private function sender(OutboundMessage $message): array
    {
        $service = $this->config->option('messaging_service_sid');

        if (is_string($service) && trim($service) !== '') {
            return ['MessagingServiceSid' => trim($service)];
        }

        $from = $message->sender ?? $this->config->sender;

        if ($from === null || trim($from) === '') {
            // Neither route configured. A gateway-level fault rather than a
            // message-level one, and the orchestrator records it and moves on.
            throw GatewayNotConfigured::missingCredential($this->config->key, 'sender or messaging_service_sid');
        }

        return ['From' => trim($from)];
    }

    private function url(): string
    {
        $base = rtrim((string) $this->config->option('url', self::DEFAULT_URL), '/');

        return sprintf('%s/Accounts/%s/Messages.json', $base, $this->config->requireCredential('account_sid'));
    }

    /**
     * Prefer Twilio's structured code over the HTTP status it arrived with.
     *
     * ⚠️ This override exists for one reason and it is worth stating plainly. The
     * shared classifier settles 401 and 403 from the status alone, as a credentials
     * problem that is safe to fail over. That is right for an authentication
     * failure and catastrophic for a consent refusal: if 21610 ever arrives on a
     * 403, the generic rule would hand an opted-out recipient's message to the next
     * gateway. A documented messaging code is better evidence than the status
     * carrying it, so when there is one, this driver reads it.
     *
     * Narrow on purpose: only codes this driver documents, and only below 500.
     * Rate limiting and server errors keep the shared transport treatment, which is
     * more conservative than anything a code lookup would produce.
     */
    protected function classify(Response $response): ?SendResult
    {
        // ⚠️ The RAW body. A code read out of a redacted payload is a code a
        // security transform has been allowed to edit, and this one decides
        // whether a consent refusal may fail over.
        $code = $this->errorCode($this->decode($response));

        if ($code !== null && $response->status() < 500 && array_key_exists($code, self::ERRORS)) {
            return null;
        }

        return $this->classifyTransport($response);
    }

    /**
     * Read the created message, or the refusal.
     *
     * Twilio answers a successful creation with 201 and the message resource. The
     * `sid` is the acceptance: it is the identifier the message now exists under,
     * and it is stored exactly as returned.
     */
    private function interpret(Response $response): SendResult
    {
        // Raw for every decision below; the sanitized copy is what gets stored.
        $payload = $this->decode($response);

        $sid = data_get($payload, 'sid');
        $code = $this->errorCode($payload);

        if ($response->successful() && is_string($sid) && $sid !== '' && $code === null) {
            /*
             * ⚠️ `status` here is `queued`, `accepted` or `scheduled` — Twilio
             * taking responsibility for processing the message. It is NOT delivery,
             * and this package does not pretend otherwise: the message becomes
             * `accepted`, and the SID is what a later delivery milestone will use to
             * ask what actually happened.
             */
            return SendResult::accepted($sid, $this->sanitized($payload));
        }

        return $this->refusal($code, $payload);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function errorCode(?array $payload): ?int
    {
        // `code` on an error response, `error_code` on a message resource that was
        // created but carries a failure.
        $code = data_get($payload, 'code') ?? data_get($payload, 'error_code');

        return is_int($code) || (is_string($code) && ctype_digit($code)) ? (int) $code : null;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function refusal(?int $code, ?array $payload): SendResult
    {
        [$kind, $retryable, $failover, $meaning] = self::ERRORS[$code] ?? [
            /*
             * ⚠️ Conservative, as everywhere else in this package. Twilio publishes
             * thousands of codes and warns that their causes change; an unrecognised
             * one could be an account problem the next gateway would not have, or a
             * refusal of this exact message that every gateway would repeat.
             * Guessing the optimistic way turns one refusal into one per gateway.
             */
            FailureKind::GatewayRejected,
            false,
            false,
            'a refusal this package does not recognise',
        ];

        return SendResult::rejected(
            $kind,
            $this->describe($code, $meaning, $payload),
            $this->sanitized($payload),
            retryableOnSameGateway: $retryable,
            safeToFailover: $this->optedOut($code) ? false : $failover,
        );
    }

    /**
     * ⚠️ **Consent, not routing. This must never be allowed to fail over.**
     *
     * 21610 means the person replied STOP. Twilio scopes an opt-out to the sender
     * it was sent to, so it would be technically defensible to argue that another
     * provider's number is not covered by it — and that argument is exactly the
     * problem. Failover exists so that a provider outage does not lose a message;
     * using it to reach somebody who asked to stop being reached would turn a
     * reliability mechanism into a way of ignoring them, one gateway at a time.
     *
     * So the decision is made twice: in the table above, and again here, so that a
     * later edit to a row cannot quietly re-enable it. Whoever removes this method
     * has to mean it.
     */
    private function optedOut(?int $code): bool
    {
        return $code === self::OPTED_OUT;
    }

    /**
     * Twilio's own message statuses, mapped onto this package's five.
     *
     * Read from the current Message resource documentation. ⚠️ The distinction
     * Twilio draws itself is the one that matters: `sent` is "the nearest upstream
     * carrier accepted the outbound message", while `delivered` is "Twilio has
     * received confirmation of outbound message delivery from the upstream
     * carrier". Collapsing those two into one produces a delivery report claiming
     * every message arrived, including the ones sent to a phone that has been
     * switched off for a year.
     *
     * ⚠️ `failed` and `undelivered` are both terminal non-delivery and both map to
     * `failed` here, although they mean different things at Twilio: `failed` never
     * left, `undelivered` came back with a negative receipt. The provider's own word
     * is kept beside our verdict so that distinction is not lost.
     *
     * `canceled` is a scheduled message that was cancelled. It will never be
     * delivered, which is a terminal non-delivery whatever caused it.
     *
     * `read` is documented for RCS and WhatsApp only and means the recipient opened
     * the message. Mapped to `delivered` rather than given a state of its own: a
     * message that was read was certainly delivered, and read receipts are not a
     * concept this package's model has any business acquiring for a channel it does
     * not currently send on.
     *
     * ⚠️ `partially_delivered` is documented as DEPRECATED and is deliberately NOT
     * mapped. It falls through to `unknown`, which is the honest answer for a
     * single-recipient SMS: there is no part of one message that can arrive without
     * the rest of it, and guessing either way would be inventing a fact.
     */
    private const DELIVERY = [
        'queued' => DeliveryStatus::Pending,
        'accepted' => DeliveryStatus::Pending,
        'scheduled' => DeliveryStatus::Pending,
        'sending' => DeliveryStatus::Pending,
        'sent' => DeliveryStatus::Sent,
        'delivered' => DeliveryStatus::Delivered,
        'read' => DeliveryStatus::Delivered,
        'undelivered' => DeliveryStatus::Failed,
        'failed' => DeliveryStatus::Failed,
        'canceled' => DeliveryStatus::Failed,
    ];

    /**
     * What became of a message Twilio accepted.
     *
     *   GET {base}/Accounts/{AccountSid}/Messages/{MessageSid}.json
     *
     * Same Basic auth as the send, same credentials, same base URL override. The
     * recipient is not needed: a Message SID names one message and one recipient,
     * unlike the per-recipient reports some providers publish.
     *
     * ⚠️ Nothing from the response is persisted except the normalised fields below.
     * The message resource carries the body, the sender, the price and the account
     * SID; a stored copy of it would put message content back into the database
     * through the reporting door.
     *
     * ⚠️ Twilio warns that `error_code` and `error_message` "are subject to change
     * as Twilio improves errors" and that they should not be used programmatically.
     * They are therefore recorded and never branched on: the code because it is what
     * a support ticket quotes, the sentence for an operator to read - and both the
     * sentence and this whole result are stripped of prose for a sensitive message,
     * upstream of here.
     */
    public function deliveryStatus(string $providerMessageId, PhoneNumber $recipient): DeliveryResult
    {
        try {
            $response = $this->http()
                ->withBasicAuth(
                    $this->config->requireCredential('account_sid'),
                    $this->config->requireCredential('auth_token'),
                )
                ->get($this->messageUrl($providerMessageId));
        } catch (ConnectionException $exception) {
            // ⚠️ The exception message is not used: it contains the request URL.
            throw DeliveryLookupFailed::forGateway($this->config->key, 'the report request did not complete');
        }

        if (! $response->successful()) {
            /*
             * Includes 404 for an id Twilio does not recognise and 401 for
             * credentials that have since been rotated. Neither says anything about
             * whether the message arrived, so neither is allowed to become a
             * delivery verdict.
             */
            throw DeliveryLookupFailed::forGateway(
                $this->config->key,
                sprintf('the report endpoint answered %d', $response->status()),
            );
        }

        // ⚠️ Raw, as everywhere: a status read out of a redacted copy is a status a
        // security transform has been allowed to edit.
        $payload = $this->decode($response);
        $status = data_get($payload, 'status');

        if (! is_string($status) || $status === '') {
            throw DeliveryLookupFailed::notReported($this->config->key, $providerMessageId);
        }

        $code = $this->errorCode($payload);
        $said = (string) (data_get($payload, 'error_message') ?? '');

        return new DeliveryResult(
            status: self::DELIVERY[$status] ?? DeliveryStatus::Unknown,
            providerStatus: $status,
            providerErrorCode: $code === null ? null : (string) $code,
            error: $this->config->redact(rtrim(sprintf(
                'twilio reports this message as %s.%s',
                $status,
                $said === '' ? '' : ' Twilio said: '.$said,
            ))),
        );
    }

    private function messageUrl(string $sid): string
    {
        $base = rtrim((string) $this->config->option('url', self::DEFAULT_URL), '/');

        return sprintf(
            '%s/Accounts/%s/Messages/%s.json',
            $base,
            $this->config->requireCredential('account_sid'),
            rawurlencode($sid),
        );
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function describe(?int $code, string $meaning, ?array $payload): string
    {
        // `message` is Twilio's own sentence. Quoted so an operator can search for
        // it; never read to decide anything.
        $said = (string) (data_get($payload, 'message') ?? data_get($payload, 'error_message') ?? '');

        return $this->config->redact(rtrim(sprintf(
            'twilio refused this message: %s [code=%s]. %s',
            $meaning,
            $code ?? '?',
            $said === '' ? '' : 'Twilio said: '.$said,
        )));
    }
}
