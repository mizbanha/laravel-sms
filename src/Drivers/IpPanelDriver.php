<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Drivers;

use Mizbanha\Sms\Contracts\Driver;
use Mizbanha\Sms\Contracts\ReportsDeliveryStatus;
use Mizbanha\Sms\Drivers\Concerns\InteractsWithHttp;
use Mizbanha\Sms\Enums\Capability;
use Mizbanha\Sms\Enums\DeliveryMode;
use Mizbanha\Sms\Enums\DeliveryStatus;
use Mizbanha\Sms\Enums\FailureKind;
use Mizbanha\Sms\Exceptions\DeliveryLookupFailed;
use Mizbanha\Sms\Gateways\GatewayConfig;
use Mizbanha\Sms\Phone\PhoneNumber;
use Mizbanha\Sms\Results\DeliveryResult;
use Mizbanha\Sms\Results\SendResult;
use Mizbanha\Sms\Sending\OutboundMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * IPPanel, against the current Edge API documented at docs.ippanel.com.
 *
 * The most agreeable of the Iranian providers so far, in two ways that matter to
 * this package:
 *
 *   - **it wants E.164 with the leading plus**, which is exactly what we store, so
 *     this is the one driver that does not convert the number at all;
 *   - **its pattern parameters are named**, supplied as a plain object, so the
 *     template/gateway parameter map translates straight into the request.
 *
 * One endpoint serves both modes - `POST {base_url}/api/send` - discriminated by
 * `sending_type`. The two bodies are not symmetrical, and the asymmetry is easy to
 * get wrong: a webservice send carries its recipients at `params.recipients`,
 * while a pattern send carries them at the TOP level in `recipients` and uses
 * `params` for the pattern's named values.
 *
 * ⚠️ One recipient per call, as everywhere in this package. The webservice
 * endpoint accepts a list and the pattern endpoint documents a limit of one; we
 * send exactly the one recipient Core gave us in both cases, because a batch
 * answers with one status for everybody in it and our message log has a row per
 * destination.
 *
 * ⚠️ The token goes in a bare `Authorization` header with **no scheme** - no
 * "Bearer" in front of it. Adding one produces a 401 that reads like a wrong key.
 *
 * **On white-label providers.** Several Iranian SMS businesses run on IPPanel
 * infrastructure, and `base_url` is configurable so one of them can be pointed at
 * its own host. That is the whole of the support offered: this driver serves
 * another gateway only when that gateway genuinely exposes this same documented
 * contract at a different address. There is deliberately no brand list, no URL
 * guessing and no per-provider branch here - a provider that has diverged from
 * this API needs its own driver, not a special case in this one.
 */
final class IpPanelDriver implements Driver, ReportsDeliveryStatus
{
    use InteractsWithHttp;

    /**
     * The documented Edge base URL. Overridable per gateway via the `url` option.
     */
    private const DEFAULT_URL = 'https://edge.ippanel.com/v1';

    /** The provider's own "this request succeeded", inside `meta`. */
    private const OK = '200-1';

    /**
     * What each documented validation field tells us, as
     * `field => [FailureKind, safeToFailover]`.
     *
     * ⚠️ Only fields whose meaning the documentation actually establishes appear
     * here. Everything else falls through to the conservative branch, because once
     * failover acts on this flag, guessing "probably gateway-specific" is how a
     * message the provider correctly refused gets pushed at every gateway in turn.
     *
     * The split is between failures that belong to the MESSAGE — its recipient or
     * its body, which no other gateway would accept either — and failures that
     * belong to THIS ACCOUNT — a sender line not approved here, a pattern not
     * registered here — which another gateway may well be able to carry.
     */
    private const VALIDATION_FIELDS = [
        'recipients' => [FailureKind::InvalidRecipient, false],
        'recipient' => [FailureKind::InvalidRecipient, false],
        'message' => [FailureKind::InvalidMessage, false],
        'from_number' => [FailureKind::GatewayConfiguration, true],
        'code' => [FailureKind::GatewayRejected, true],
    ];

    public function __construct(private readonly GatewayConfig $config) {}

    public function capabilities(): array
    {
        /*
         * Text, pattern, and - since M6 - delivery reporting, which is declared
         * because deliveryStatus() below genuinely implements it.
         *
         * The API also documents VOTP, scheduled sending, peer-to-peer, keyword,
         * postal-code and country sends, plus remote pattern management, phonebooks
         * and campaigns. None of that is exposed here and none of it should be
         * pretended to.
         */
        return [Capability::Text, Capability::Pattern, Capability::DeliveryReport];
    }

    public function send(OutboundMessage $message): SendResult
    {
        return $message->mode === DeliveryMode::Pattern
            ? $this->sendPattern($message)
            : $this->sendText($message);
    }

    private function sendText(OutboundMessage $message): SendResult
    {
        return $this->perform(
            fn (): Response => $this->request()->post($this->url(), [
                'sending_type' => 'webservice',
                'from_number' => $message->sender ?? $this->config->sender,
                'message' => (string) $message->body,
                // ⚠️ Nested under params for this sending type, unlike the pattern
                // body below. Sending one recipient, never the batch the endpoint
                // would accept.
                'params' => ['recipients' => [$message->to->e164]],
            ]),
            $this->interpret(...),
        );
    }

    private function sendPattern(OutboundMessage $message): SendResult
    {
        return $this->perform(
            fn (): Response => $this->request()->post($this->url(), [
                'sending_type' => 'pattern',
                'from_number' => $message->sender ?? $this->config->sender,
                'code' => (string) $message->patternCode,
                // ⚠️ Top level here, not under params. The provider documents one
                // recipient per pattern request.
                'recipients' => [$message->to->e164],
                // The named values, already translated into this provider's own
                // parameter names by the template/gateway mapping.
                'params' => $message->namedParameters(),
            ]),
            $this->interpret(...),
        );
    }

    private function request(): PendingRequest
    {
        return $this->http()->withHeaders([
            // ⚠️ No "Bearer". The documented header is the bare token.
            'Authorization' => $this->config->requireCredential('api_key'),
        ]);
    }

    private function url(): string
    {
        return rtrim((string) $this->config->option('url', self::DEFAULT_URL), '/').'/api/send';
    }

    /**
     * Read the provider's answer.
     *
     * Transport-level verdicts (401/403, 429, 5xx, a dead connection) have already
     * been settled by the shared classifier before this is reached, so what is left
     * is the provider actually speaking: a success envelope, or a 422 carrying
     * field-level validation errors.
     */
    private function interpret(Response $response): SendResult
    {
        // Raw for every decision below; the sanitized copy is what gets stored.
        $payload = $this->decode($response);

        if ($response->status() === 422) {
            return $this->rejectValidation($payload);
        }

        if ((string) data_get($payload, 'meta.message_code') !== self::OK
            || data_get($payload, 'meta.status') !== true) {
            /*
             * A refusal with no field-level detail: the provider says no and does
             * not say what about.
             *
             * ⚠️ NOT failable over. There is no published catalogue mapping this
             * provider's codes to causes, so "not 200-1" could equally be an
             * account problem another gateway would not have or a refusal of this
             * exact message that every gateway would repeat. Guessing the
             * optimistic way turns one refusal into one refusal per gateway.
             */
            return SendResult::rejected(
                FailureKind::GatewayRejected,
                $this->describe($payload),
                $this->sanitized($payload),
                safeToFailover: false,
            );
        }

        /*
         * One recipient in, one outbox id out.
         *
         * This id is the provider's own tracking identifier and is exactly the key
         * its report API takes: GET {base_url}/api/report/by_bulk with
         * messages_outbox_id. Storing it now is what will make delivery lookup
         * possible without changing anything here.
         */
        $id = data_get($payload, 'data.message_outbox_ids.0');

        return SendResult::accepted($id === null ? null : (string) $id, $this->sanitized($payload));
    }

    /**
     * A 422, classified by WHICH FIELD the provider objected to.
     *
     * ⚠️ The field names are read; the human-language messages beside them never
     * are. The provider answers a validation failure with a documented object
     * keyed by field name, so which field failed is structured evidence, while the
     * sentence explaining it is Persian prose that changes without warning.
     *
     * The distinction that matters is the recipient. A number this provider will
     * not accept is a number the next provider will not accept either, so failing
     * over would be a pointless loop that ends in the same refusal at every
     * gateway. Anything else - an unregistered pattern code, a sender line not
     * approved on this account - is specific to this gateway, and another one may
     * well carry the same logical message.
     */
    private function rejectValidation(?array $payload): SendResult
    {
        $fields = $this->invalidFields($payload);
        [$kind, $safeToFailover] = $this->classifyFields($fields);

        return SendResult::rejected(
            $kind,
            $this->describe($payload, $fields),
            $this->sanitized($payload),
            safeToFailover: $safeToFailover,
        );
    }

    /**
     * Turn the objected-to field names into one verdict.
     *
     * Read in order of severity, because a response may name several fields and
     * the safest reading has to win:
     *
     *   1. anything about the recipient — the next gateway will refuse the same
     *      number, so failing over is a loop with a known ending;
     *   2. anything about the body — likewise, the message itself is the problem;
     *   3. only then, fields that are genuinely about this account, which another
     *      gateway may not share;
     *   4. anything undocumented — conservative, because we do not know.
     *
     * @param  list<string>  $fields
     * @return array{0: FailureKind, 1: bool}
     */
    private function classifyFields(array $fields): array
    {
        $known = [];

        foreach ($fields as $field) {
            if (! isset(self::VALIDATION_FIELDS[$field])) {
                // An undocumented field. We cannot say whose fault this is, so we
                // do not let the message move on.
                return [FailureKind::GatewayRejected, false];
            }

            $known[] = self::VALIDATION_FIELDS[$field];
        }

        if ($known === []) {
            // A 422 with no field detail at all.
            return [FailureKind::GatewayRejected, false];
        }

        foreach ([FailureKind::InvalidRecipient, FailureKind::InvalidMessage] as $blocking) {
            foreach ($known as [$kind, $safe]) {
                if ($kind === $blocking) {
                    return [$kind, false];
                }
            }
        }

        // What is left is account-specific, and every entry agrees it is failable
        // over. The first is as good as any.
        return $known[0];
    }

    /**
     * The field names a validation error names, unqualified.
     *
     * The provider reports them either bare (`recipients`) or dotted
     * (`params.recipients`), so only the last segment is compared.
     *
     * @return list<string>
     */
    private function invalidFields(?array $payload): array
    {
        $errors = data_get($payload, 'meta.message');

        if (! is_array($errors)) {
            return [];
        }

        $fields = [];

        foreach (array_keys($errors) as $key) {
            if (is_string($key)) {
                $segments = explode('.', $key);
                $fields[] = (string) end($segments);
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * The documented recipient-level delivery codes, mapped onto this package's
     * five states.
     *
     * From the current Edge reporting documentation for the recipients endpoint,
     * which publishes `message_status` as one of exactly these five values:
     *
     *   0  sent to the operator
     *   1  the operator received the message
     *   2  the message was delivered to the recipient
     *   3  the message was not delivered to the recipient
     *   4  the number is blacklisted
     *
     * ⚠️ 0 and 1 are both `sent`, not `delivered`. Handing a message to an operator
     * and an operator acknowledging receipt are both events on the way to a handset,
     * and neither is the handset. Reading either as delivery is how a report claims
     * a switched-off phone received its message.
     *
     * ⚠️ 4 (blacklisted) is `failed` here, and that is a DELIVERY verdict rather
     * than the send-side judgement this package makes elsewhere. Melipayamak's
     * blacklist code 35 is deliberately treated as failable-over at send time,
     * because an approved service line may still reach a number on the operator's
     * advertising opt-out list (22.5). This is a different phase: the send already
     * happened, this message was not delivered, and saying so is simply reporting
     * what occurred. It does not cause a resend and cannot.
     *
     * The values arrive as JSON strings in the documented examples, so they are
     * compared as strings; an integer would silently miss.
     */
    private const DELIVERY = [
        '0' => DeliveryStatus::Sent,
        '1' => DeliveryStatus::Sent,
        '2' => DeliveryStatus::Delivered,
        '3' => DeliveryStatus::Failed,
        '4' => DeliveryStatus::Failed,
    ];

    /** What each documented code means, for the operator reading the row. */
    private const DELIVERY_MEANINGS = [
        '0' => 'sent to the operator',
        '1' => 'the operator received the message',
        '2' => 'delivered to the recipient',
        '3' => 'not delivered to the recipient',
        '4' => 'the recipient number is blacklisted',
    ];

    /**
     * What became of a message this provider accepted.
     *
     *   GET {base_url}/api/report/recipients?bulk_id={messages_outbox_id}
     *
     * ⚠️ **Recipient level, deliberately, and not the bulk report.** The provider
     * also publishes `/api/report/by_bulk`, which answers with a bulk `state` such
     * as `finish` - and "the bulk finished" is not "the handset received it". The
     * recipients endpoint is the only one that carries a per-destination delivery
     * code, which is the only thing worth writing into a delivery column.
     *
     * ⚠️ **This response contains the original message text.** The documented shape
     * is `{recipient, message, is_readable, msg_parts, message_status}` - the body
     * of the SMS, sitting beside the status. That is precisely the leak this
     * milestone exists to close: a delivery lookup for an OTP would otherwise walk
     * the code back into the database, days after the send deliberately refused to
     * store it. Only `message_status` is read; `message` is never read, never
     * returned and never persisted, and no part of this response is stored raw.
     *
     * ⚠️ No pagination. Core sends one recipient per attempt, so the outbox id
     * names one recipient and the first page holds it. If an id somehow names a
     * larger batch and our recipient is not on that page, this reports nothing
     * rather than guessing at somebody else's row.
     */
    public function deliveryStatus(string $providerMessageId, PhoneNumber $recipient): DeliveryResult
    {
        try {
            $response = $this->request()->get($this->reportUrl(), ['bulk_id' => $providerMessageId]);
        } catch (ConnectionException $exception) {
            // ⚠️ The exception message is not used: it contains the request URL.
            throw DeliveryLookupFailed::forGateway($this->config->key, 'the report request did not complete');
        }

        if (! $response->successful()) {
            /*
             * 401 for a token that has since expired, 422 for an id this account
             * cannot see. Neither says anything about whether the message arrived,
             * so neither may become a delivery verdict.
             */
            throw DeliveryLookupFailed::forGateway(
                $this->config->key,
                sprintf('the report endpoint answered %d', $response->status()),
            );
        }

        // ⚠️ Raw, as everywhere: a status read out of a redacted copy is a status a
        // security transform has been allowed to edit.
        $payload = $this->decode($response);

        if ((string) data_get($payload, 'meta.message_code') !== self::OK) {
            throw DeliveryLookupFailed::forGateway($this->config->key, 'the report endpoint refused the request');
        }

        $status = $this->recipientStatus($payload, $recipient);

        if ($status === null) {
            /*
             * Either our recipient is not in the report, or the report carries no
             * status for it yet - the documentation says the per-recipient delivery
             * status appears once the message is finalised. Both mean the same
             * thing: nothing new was learned, so nothing is written and whatever the
             * attempt already recorded stands.
             */
            throw DeliveryLookupFailed::notReported($this->config->key, $providerMessageId);
        }

        return new DeliveryResult(
            status: self::DELIVERY[$status] ?? DeliveryStatus::Unknown,
            providerStatus: $status,
            // No structured error code exists at recipient level: the status IS the
            // reason. Inventing one from the status would be duplication dressed as
            // detail.
            providerErrorCode: null,
            error: sprintf(
                'ippanel reports this recipient as %s [message_status=%s]',
                self::DELIVERY_MEANINGS[$status] ?? 'a status this package does not recognise',
                $status,
            ),
        );
    }

    /**
     * The delivery code for OUR recipient, out of a report that is a list.
     *
     * Matched on digits rather than on the exact string: we hold `+989121234567`
     * and the provider echoes what it was given, which the documentation shows in
     * E.164 but which no contract guarantees will be spelled identically.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function recipientStatus(?array $payload, PhoneNumber $recipient): ?string
    {
        $rows = data_get($payload, 'data');

        if (! is_array($rows)) {
            return null;
        }

        $wanted = $this->digits($recipient->e164);

        foreach ($rows as $row) {
            // ⚠️ Two keys are read from this row and no others. `message` - the
            // body of the SMS - is right beside them and is deliberately untouched.
            if (! is_array($row) || $this->digits((string) ($row['recipient'] ?? '')) !== $wanted) {
                continue;
            }

            $status = $row['message_status'] ?? null;

            return is_scalar($status) && (string) $status !== '' ? (string) $status : null;
        }

        return null;
    }

    /**
     * A number reduced to something two spellings of it can be compared on.
     *
     * Digits only, with leading zeros dropped, so that the international `00`
     * prefix and a bare `+` agree. ⚠️ Deliberately no cleverer than that: matching
     * on the last N digits would eventually attach one subscriber's delivery result
     * to another subscriber's message, and reporting nothing is a far better
     * failure than reporting somebody else's.
     */
    private function digits(string $number): string
    {
        return ltrim((string) preg_replace('/\D/', '', $number), '0');
    }

    private function reportUrl(): string
    {
        return rtrim((string) $this->config->option('url', self::DEFAULT_URL), '/').'/api/report/recipients';
    }

    /**
     * A readable reason for the attempt log.
     *
     * Built from the provider's own code and, where the failure was a validation
     * error, the names of the fields it objected to. The provider's message is
     * included when it is a plain string, for the operator reading the log — but
     * nothing anywhere reads it to make a decision.
     *
     * @param  list<string>  $fields
     */
    private function describe(?array $payload, array $fields = []): string
    {
        $code = (string) data_get($payload, 'meta.message_code', 'unknown');
        $message = data_get($payload, 'meta.message');

        $reason = $fields !== []
            ? 'invalid: '.implode(', ', $fields)
            : (is_string($message) && $message !== '' ? $message : 'no reason given');

        return $this->config->redact(sprintf('ippanel %s: %s', $code, $reason));
    }
}
