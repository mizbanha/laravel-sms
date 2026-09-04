<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Drivers;

use Mizbanha\Sms\Contracts\Driver;
use Mizbanha\Sms\Drivers\Concerns\InteractsWithHttp;
use Mizbanha\Sms\Enums\Capability;
use Mizbanha\Sms\Enums\DeliveryMode;
use Mizbanha\Sms\Enums\FailureKind;
use Mizbanha\Sms\Gateways\GatewayConfig;
use Mizbanha\Sms\Phone\PhoneNumber;
use Mizbanha\Sms\Results\SendResult;
use Mizbanha\Sms\Sending\OutboundMessage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * IranPayamak, against the public OpenAPI specification published at
 * docs.iranpayamak.com and served from `https://api.iranpayamak.com`.
 *
 * The friendliest envelope of the Iranian providers in this package, and the only
 * one that reports failures the way Laravel does:
 *
 *   - **success is a single documented word.** Every response is
 *     `{status, data, messages}` with `status` an enum of exactly `success` or
 *     `error`, so there is no numeric status catalogue to translate and no
 *     provider-specific code table below — the two other Iranian drivers here both
 *     need one;
 *   - **failures name the FIELD they are about.** `messages` is documented (schema
 *     `ApiMessage`) as null, a string, a list of strings, or an OBJECT keyed by
 *     field name with string or list values. That last shape is a validation bag,
 *     and a field name is structured evidence in a way a Persian sentence is not.
 *
 * Two endpoints, and unlike IPPanel they are genuinely two:
 *
 *   - `POST {base_url}/ws/v1/sms/simple` — free text, `recipients` as a LIST;
 *   - `POST {base_url}/ws/v1/sms/pattern` — an approved pattern by `code`, with
 *     `recipient` SINGULAR and a string.
 *
 * ⚠️ **Plural on one, singular on the other**, for both the recipient key and its
 * type. Sending a list to the pattern endpoint, or a bare string to the simple one,
 * is a validation failure that reads like a bad number.
 *
 * ⚠️ **The recipient is the national form, `09121234567`.** The published schema
 * constrains it to `^09\d{9}$`, which is an Iranian mobile and nothing else — see
 * `sendableTo()` for what this driver does about a destination that cannot match
 * it, and why refusing here rather than at the provider matters.
 *
 * ⚠️ **The key travels in an `Api-Key` header**, its own scheme, named exactly
 * that. Not `Authorization`, not `Bearer`, not a query parameter. The specification
 * also declares an unused `bearer` scheme alongside it, which belongs to the panel
 * login flow (`/ws/v1/auth/login`) rather than to sending; this driver holds one
 * long-lived API key and never logs in, so no token is ever fetched, cached or
 * refreshed.
 *
 * ⚠️ **`number_format` is required on both endpoints and the specification
 * contradicts itself about its values.** The simple endpoint enumerates
 * `english | persian`; `PatternSendRequestDto` enumerates `en | fa` while
 * describing them as "english | persian" — and the pattern endpoint's own example
 * body sends `"english"`. Both published examples therefore use the long spelling,
 * so that is the default here, and it is a per-gateway option because an operator
 * who finds otherwise against the live API must be able to correct it without a
 * release. See `numberFormat()`.
 *
 * **What this driver does not do.** The API also publishes voice messages,
 * peer-to-peer, keyword and Excel-driven sends, postal-code, LBS and number-bank
 * targeting, bulk geographic sends, phonebooks, remote pattern management, tickets
 * and orders. None of it is exposed here and none of it should be pretended to.
 *
 * ⚠️ **Delivery reporting is deliberately NOT implemented, and the reason is the
 * documentation rather than the provider.** `GET /ws/v1/send_request/{id}/items`
 * publishes a `status` QUERY FILTER whose vocabulary is spelled out in full —
 * `not-started | in-queue | sent | send-failure | delivered | delivery-failure |
 * delivery-undetermined | system-error | blacklist` — which is precisely the
 * per-recipient verdict this package's `DeliveryStatus` exists to record. But the
 * RESPONSE schema for that endpoint, and for the two beside it, is
 * `ApiResultPagedPhonebook`: a paged list of phonebooks, with `id`, `title`,
 * `records` and `attributes`. It is a copy-paste from the phonebook endpoints —
 * their summaries still read "Get paged list of phonebooks" — so the two fields a
 * report actually needs, the recipient and its status, are not documented at all.
 * Reading a delivery verdict out of field names guessed from a schema known to
 * describe something else is how a dashboard ends up claiming delivery to numbers
 * that were never reached. `Capability::DeliveryReport` is therefore not declared
 * and `ReportsDeliveryStatus` is not implemented — the honest position until the
 * item shape is published. The vocabulary is recorded above so that adding it later
 * is a mapping exercise rather than a rediscovery.
 */
final class IranPayamakDriver implements Driver
{
    use InteractsWithHttp;

    /** The documented production host. Overridable per gateway via the `url` option. */
    private const DEFAULT_URL = 'https://api.iranpayamak.com';

    /** The `status` enum's own "this request succeeded". The only other value is `error`. */
    private const OK = 'success';

    /**
     * The recipient format the provider publishes, verbatim from `SimpleRequestDto`.
     *
     * An Iranian mobile in national form and nothing else. This is a line that
     * cannot carry an international destination at all, which is a fact worth acting
     * on before the request rather than after it — see `sendableTo()`.
     */
    private const RECIPIENT_PATTERN = '/^09\d{9}$/';

    /**
     * What `number_format` is sent as when a gateway does not say otherwise.
     *
     * The long spelling, because both published examples use it. See the class
     * comment for the contradiction this resolves.
     */
    private const DEFAULT_NUMBER_FORMAT = 'english';

    /**
     * What each documented request field tells us when the provider objects to it,
     * as `field => [FailureKind, safeToFailover]`.
     *
     * ⚠️ Only fields this API actually documents appear here. Everything else falls
     * through to the conservative branch in `classifyFields()`, because once
     * failover acts on this flag, guessing "probably just this gateway" is how a
     * message the provider correctly refused gets offered to every gateway in turn.
     *
     * The split is between failures that belong to the MESSAGE — its recipient or
     * its text, which the next provider would read identically — and failures that
     * belong to THIS GATEWAY — a line this account does not own, a pattern not
     * registered on it — which another gateway may well not share.
     */
    private const VALIDATION_FIELDS = [
        // ---- the message itself: no other gateway would accept it either
        'recipients' => [FailureKind::InvalidRecipient, false],
        'recipient' => [FailureKind::InvalidRecipient, false],
        'text' => [FailureKind::InvalidMessage, false],

        // ---- this account's own settings: another gateway has its own
        'line_number' => [FailureKind::GatewayConfiguration, true],
        'number_format' => [FailureKind::GatewayConfiguration, true],

        /*
         * ---- this gateway's pattern binding, not the logical message
         *
         * A pattern is registered per account, and `attributes` failing validation
         * means the values do not match the variables THIS account's approved
         * pattern declares. Both are properties of the template/gateway binding —
         * the pattern code and the parameter map live on that row — so another
         * gateway may hold a perfectly good registration for the same logical
         * message. Melipayamak's -5 is classified the same way, for the same reason.
         */
        'code' => [FailureKind::GatewayRejected, true],
        'attributes' => [FailureKind::GatewayRejected, true],
    ];

    public function __construct(private readonly GatewayConfig $config) {}

    public function capabilities(): array
    {
        // Delivery reporting is absent on purpose, and the class comment says why at
        // length: the endpoint exists, its status vocabulary is published, and its
        // response schema documents somebody else's object.
        return [Capability::Text, Capability::Pattern];
    }

    public function send(OutboundMessage $message): SendResult
    {
        $line = $this->line($message);

        return match (true) {
            ! $this->sendableTo($message->to) => $this->unreachable($message->to),
            $line === null => $this->unlined(),
            $message->mode === DeliveryMode::Pattern => $this->sendPattern($message, $line),
            default => $this->sendText($message, $line),
        };
    }

    private function sendText(OutboundMessage $message, string $line): SendResult
    {
        return $this->perform(
            fn (): Response => $this->request()->post($this->url('/ws/v1/sms/simple'), [
                'text' => (string) $message->body,
                'line_number' => $line,
                // ⚠️ A LIST here, and a bare string at the pattern endpoint below.
                // One recipient in it, never the batch this endpoint would accept:
                // a batch answers with one status for everybody in it, and this
                // package's log has a row per destination.
                'recipients' => [$message->to->national],
                'number_format' => $this->numberFormat(),
                /*
                 * ⚠️ Present and null, deliberately.
                 *
                 * This endpoint lists `schedule` in its `required` array AND marks it
                 * nullable, so the documented way to say "send it now" is to send the
                 * key with no value rather than to omit the key. This package has no
                 * scheduling concept — a message it has accepted is a message it is
                 * sending — so this is always null and there is nothing to configure.
                 */
                'schedule' => null,
            ]),
            $this->interpret(...),
        );
    }

    private function sendPattern(OutboundMessage $message, string $line): SendResult
    {
        /*
         * ⚠️ Omitted entirely when there are none, rather than sent empty.
         *
         * `attributes` is optional, and PHP encodes an empty associative array as
         * `[]` — a JSON array where every non-empty send puts an object. Not sending
         * it at all avoids asking the provider's validator a question its
         * documentation does not answer.
         */
        $attributes = $message->namedParameters();

        return $this->perform(
            fn (): Response => $this->request()->post($this->url('/ws/v1/sms/pattern'), [
                // The pattern UID, created in and read from the provider's own panel.
                'code' => (string) $message->patternCode,
                // ⚠️ Singular, and a string. The plural list belongs to the other
                // endpoint; this one documents a single destination.
                'recipient' => $message->to->national,
                // The named values, already translated into this provider's own
                // parameter names by the template/gateway mapping, as the
                // name => value object the published example shows.
                ...($attributes === [] ? [] : ['attributes' => $attributes]),
                'line_number' => $line,
                'number_format' => $this->numberFormat(),
                // ⚠️ `schedule` is omitted here rather than sent null, unlike the
                // simple endpoint above: it is optional in this schema, whose own
                // note reads "Maybe it must be null for patterns", and the published
                // example leaves it out.
            ]),
            $this->interpret(...),
        );
    }

    /**
     * Whether this provider could accept this destination at all.
     *
     * ⚠️ **Checked here rather than left to the provider, and that is a failover
     * decision rather than a saved request.** The provider answers an unacceptable
     * number with a validation error on `recipients`, which this driver — correctly
     * — reads as `InvalidRecipient` and refuses to fail over, because a number one
     * provider calls invalid is a number the next one calls invalid too.
     *
     * That reasoning holds for a malformed number and breaks completely for a
     * well-formed FOREIGN one. `^09\d{9}$` is an Iranian mobile line; a British or
     * German destination is not invalid, it is simply outside what this gateway
     * sells, and a Twilio gateway in the same chain can carry it. Letting the
     * provider answer would classify it as a bad number and stop the chain at the
     * one gateway that was never able to help.
     *
     * So: a refusal by THIS GATEWAY, safe to fail over. Nothing is sent.
     *
     * The published pattern is applied to the national form rather than the region
     * being tested, because the pattern is what the provider actually documents.
     */
    private function sendableTo(PhoneNumber $to): bool
    {
        return preg_match(self::RECIPIENT_PATTERN, $to->national) === 1;
    }

    private function unreachable(PhoneNumber $to): SendResult
    {
        return SendResult::rejected(
            FailureKind::GatewayRejected,
            sprintf(
                'IranPayamak accepts Iranian mobile recipients in the form 09XXXXXXXXX; %s is not one.',
                // ⚠️ The region, not the number. An attempt row is read by people who
                // already have the message's recipient beside it, and the useful fact
                // here is which country this gateway was asked to reach.
                $to->region ?? 'this destination',
            ),
            safeToFailover: true,
        );
    }

    /**
     * The sending line for this message, or null when neither the message nor the
     * gateway names one.
     *
     * `line_number` is required by both endpoints and constrained to `^[0-9]+$`, so
     * a gateway with no sender configured cannot send anything at all.
     */
    private function line(OutboundMessage $message): ?string
    {
        $line = trim((string) ($message->sender ?? $this->config->sender ?? ''));

        return $line === '' ? null : $line;
    }

    private function unlined(): SendResult
    {
        /*
         * ⚠️ A rejection rather than a thrown GatewayNotConfigured, even though it is
         * the same kind of problem as a missing credential. That exception is raised
         * by GatewayConfig for CREDENTIALS, which are its own; the sender is an
         * ordinary column a management screen can leave blank, and this package's own
         * `log` driver sends perfectly well without one. Recording this as a
         * configuration failure that fails over tells an operator the same thing and
         * keeps the message moving.
         */
        return SendResult::rejected(
            FailureKind::GatewayConfiguration,
            'IranPayamak requires a numeric sending line; this gateway has no sender configured.',
            safeToFailover: true,
        );
    }

    private function request(): PendingRequest
    {
        return $this->http()->withHeaders([
            // ⚠️ Its own scheme, named exactly this. Not Authorization, not Bearer.
            'Api-Key' => $this->config->requireCredential('api_key'),
        ]);
    }

    /**
     * The value sent as `number_format`, which decides whether digits inside the
     * message are rewritten as English or Persian characters.
     *
     * Configurable per gateway because the specification disagrees with itself about
     * the enum — `english | persian` on one endpoint, `en | fa` on the other, with
     * `english` in both examples — and because the field is required, so a wrong
     * value is a validation failure on every send rather than a cosmetic difference.
     * An operator who finds the short spelling is the live one sets
     * `options.number_format` and is sending again a minute later.
     */
    private function numberFormat(): string
    {
        $format = $this->config->option('number_format', self::DEFAULT_NUMBER_FORMAT);

        return is_string($format) && trim($format) !== '' ? trim($format) : self::DEFAULT_NUMBER_FORMAT;
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->config->option('url', self::DEFAULT_URL), '/').$path;
    }

    /**
     * Read the provider's answer.
     *
     * Transport-level verdicts — 401/403, 429, 5xx, a dead connection — have already
     * been settled by the shared classifier before this is reached, so what is left
     * is the provider actually speaking.
     *
     * ⚠️ **Both halves are required for a success, and neither alone is enough.**
     * The specification publishes 201 for an accepted send and an envelope whose
     * `status` is an enum of `success` or `error`; an `error` envelope inside a 2xx
     * is a well-formed way of saying no, and a 4xx carrying a body is still the
     * provider explaining itself. Reading the code alone, or the field alone, is how
     * a refusal gets recorded as a send.
     */
    private function interpret(Response $response): SendResult
    {
        // Raw for every decision below; the sanitized copy is what gets stored.
        $payload = $this->decode($response);

        if (! $response->successful() || (string) data_get($payload, 'status') !== self::OK) {
            return $this->reject($payload);
        }

        /*
         * One send request in, one numeric id out — `data` in the `ApiResultNumber`
         * envelope, which is the id the send-request endpoints take in their path
         * (`/ws/v1/send_request/{send_request_id}`). Storing it now costs nothing and
         * is what would make a report implementation possible later without touching
         * the send path; nothing in this package reads it yet.
         */
        return SendResult::accepted(
            $this->identifier(data_get($payload, 'data')),
            $this->sanitized($payload),
        );
    }

    /**
     * The provider said no. Work out whose fault it is from the fields it named.
     *
     * ⚠️ The field NAMES are read; the human-language messages beside them never
     * are. Which field failed is structured evidence that the schema publishes, while
     * the sentence explaining it is Persian prose written for a person, which changes
     * without warning.
     */
    private function reject(?array $payload): SendResult
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
     * Read in order of severity, because a validation bag may name several fields at
     * once and the safest reading has to win:
     *
     *   1. anything about the recipient — the next gateway will refuse the same
     *      number, so failing over is a loop with a known ending;
     *   2. anything about the text — likewise, the message itself is the problem;
     *   3. only then, fields that are genuinely about this gateway, which another one
     *      may not share;
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
                // A field this API does not document. We cannot say whose fault it
                // is, so the message does not move on.
                return [FailureKind::GatewayRejected, false];
            }

            $known[] = self::VALIDATION_FIELDS[$field];
        }

        if ($known === []) {
            /*
             * A refusal with no field-level detail: `messages` was null, a bare
             * sentence, or a list of them.
             *
             * ⚠️ NOT failable over. This API publishes no catalogue mapping refusals
             * to causes — there is no numeric code to look up — so an unexplained
             * `error` could equally be an account problem another gateway would not
             * have, or a refusal of this exact message that every gateway would
             * repeat. Guessing the optimistic way turns one refusal into one refusal
             * per gateway.
             */
            return [FailureKind::GatewayRejected, false];
        }

        foreach ([FailureKind::InvalidRecipient, FailureKind::InvalidMessage] as $blocking) {
            foreach ($known as [$kind, $safe]) {
                if ($kind === $blocking) {
                    return [$kind, false];
                }
            }
        }

        // What is left is gateway-specific, and every entry agrees it is failable
        // over. The first is as good as any.
        return $known[0];
    }

    /**
     * The field names a validation bag names, unqualified.
     *
     * ⚠️ **Only STRING keys count.** `ApiMessage` is four shapes in one field and two
     * of them are arrays: a list of sentences (`["..."]`, integer keys) and a
     * validation bag (`{"recipients": ["..."]}`, string keys). Reading the keys
     * without checking would turn the first into a field called `0`.
     *
     * A bag may report a nested field either bare (`attributes`) or dotted
     * (`attributes.var1`), the way Laravel's own validator does, so only the FIRST
     * segment is compared: that is the request field the schema documents, and the
     * rest is whichever key inside it was at fault.
     *
     * @return list<string>
     */
    private function invalidFields(?array $payload): array
    {
        $messages = $this->messages($payload);

        if (! is_array($messages)) {
            return [];
        }

        $fields = [];

        foreach (array_keys($messages) as $key) {
            if (is_string($key)) {
                $fields[] = (string) strtok($key, '.');
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * Whatever this response put its human-readable explanation in.
     *
     * ⚠️ **Two spellings, and both are documented.** The send envelope
     * (`ApiResultNumber`, `ApiResultString`, every paged result) publishes
     * `messages`; the account endpoints publish `message` — the balance endpoint's
     * own 401 example is `{"status": "error", "data": null, "message":
     * "Unauthorized"}`. Only the plural should ever reach this driver, but a provider
     * that answered a send with the account envelope would otherwise have its reason
     * silently dropped from the attempt log, so both are read.
     */
    private function messages(?array $payload): mixed
    {
        return data_get($payload, 'messages') ?? data_get($payload, 'message');
    }

    /**
     * The provider's own id for an accepted send, as a string.
     *
     * ⚠️ Scalar and non-boolean only. `data` is a number here and an object elsewhere
     * in the same API, and `(string)` over an array is an error and the word "Array"
     * in a column that is supposed to identify a send.
     */
    private function identifier(mixed $id): ?string
    {
        return is_scalar($id) && ! is_bool($id) && (string) $id !== '' ? (string) $id : null;
    }

    /**
     * A readable reason for the attempt log.
     *
     * Built from the envelope's own status and, where the failure was a validation
     * bag, the names of the fields it objected to. The provider's sentence is
     * included when it is a plain string, for the operator reading the log — but
     * nothing anywhere reads it to make a decision.
     *
     * @param  list<string>  $fields
     */
    private function describe(?array $payload, array $fields = []): string
    {
        $status = data_get($payload, 'status');
        $messages = $this->messages($payload);

        $reason = match (true) {
            $fields !== [] => 'invalid: '.implode(', ', $fields),
            is_string($messages) && trim($messages) !== '' => $messages,
            default => 'no reason given',
        };

        return $this->config->redact(sprintf(
            'iranpayamak %s: %s',
            is_scalar($status) && (string) $status !== '' ? (string) $status : 'unknown',
            $reason,
        ));
    }
}
