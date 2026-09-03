<?php

declare(strict_types=1);

namespace Amid\Sms\Drivers;

use Amid\Sms\Contracts\Driver;
use Amid\Sms\Drivers\Concerns\InteractsWithHttp;
use Amid\Sms\Enums\Capability;
use Amid\Sms\Enums\DeliveryMode;
use Amid\Sms\Enums\FailureKind;
use Amid\Sms\Gateways\GatewayConfig;
use Amid\Sms\Results\SendResult;
use Amid\Sms\Sending\OutboundMessage;
use Illuminate\Http\Client\Response;

/**
 * Melipayamak, against the REST API its own SDKs implement:
 * `https://rest.payamak-panel.com/api/SendSMS/{method}`.
 *
 * ⚠️ **There are two Melipayamak APIs and this is the documented one.** The
 * official SDKs in every language the vendor publishes — python, node, php, ruby,
 * Go — all target this host with a username and password in the request body.
 * There is also a newer console API at `console.melipayamak.com` that takes an API
 * key in the URL path; that one is what this workspace's legacy application used,
 * but no public specification for it could be found, so implementing against it
 * would be reverse-engineering rather than integration. See the handoff.
 *
 * Two endpoints:
 *
 *   - `SendSMS` — free text, with `to`, `from`, `text`;
 *   - `BaseServiceNumber` — an approved body identified by a numeric `bodyId`.
 *
 * ⚠️ **The pattern endpoint takes its values as ONE delimited string**, not an
 * array and not an object. There is no named-parameter concept here at all: the
 * approved body has numbered placeholders and the values are matched to them by
 * position, so the order of the template/gateway mapping is the entire contract.
 * That makes this the second positional provider in the package, and the strictest
 * — a delimiter appearing inside a value would silently split it into two.
 *
 * ⚠️ **Success is read from two fields, because neither alone says it.**
 * `RetStatus` is the envelope's own verdict — the documented success example is
 * `{"Value": "...", "RetStatus": 1, "StrRetStatus": "Ok"}` — and it reports that
 * the CALL worked, not that the message was accepted. The method's own answer is
 * in `Value`, which is the recId of a successful send, one of an enumerated set of
 * error numbers, or — on `SendSMS` alone — the bare acknowledgement `1`, which
 * reports success and is not an id. **Many of those error numbers are POSITIVE** —
 * 2, 3, 6, 10, 17, 18, 35 and more — so `{"RetStatus": 1, "Value": 18}` is a
 * well-formed response meaning "the recipient number is invalid". Reading either
 * field alone records a message as sent that never left.
 *
 * ⚠️ **The two operations have DIFFERENT documented return codes, and this driver
 * treats them separately.** `SendSMS` documents 3, 4, 5, 9, 14 and 15, which
 * `BaseServiceNumber` does not; `BaseServiceNumber` documents -10, -7, -6, -5, -4,
 * -3, -2, -1 and 19, which `SendSMS` does not. Applying one table to the other
 * endpoint would attach the wrong meaning to a real code. The two lists below are
 * the vendor's per-method tables, read from `melipayamak.com/api/sendsimplesms2/`
 * and `melipayamak.com/api/sendbybasenumber2/`, and corrected against the vendor's
 * own PDF guides (`webservice-rest`, `webservice-SharedNumber`).
 *
 * ⚠️ **Separate does not mean disjoint**, and reading it that way is what those
 * guides caught. The four address codes -111, -110, -109 and -108 are documented
 * for BOTH endpoints; this driver had -111 on the pattern side only, so a text
 * send refused for an unauthorised IP was treated as an outcome the provider does
 * not document — and an undocumented outcome is never failed over.
 *
 * ⚠️ **Only the pattern endpoint documents a recId shape**: "در صورت دریافت
 * (recId) یک عدد بیش از 15 رقم به معنای ارسال موفق بوده" — more than 15 digits
 * means the send succeeded. That rule is used for `BaseServiceNumber` and
 * deliberately NOT for `SendSMS`, whose page says no such thing; inventing it
 * there would reject genuine sends if that endpoint's ids are shorter.
 *
 * Classification is by documented numeric code only. `StrRetStatus` is quoted into
 * an error message and never branched on. See the handoff, section 22, for the
 * full tables, the reasoning behind each classification, and what this still does
 * not cover.
 */
final class MelipayamakDriver implements Driver
{
    use InteractsWithHttp;

    private const DEFAULT_URL = 'https://rest.payamak-panel.com/api/SendSMS';

    /** The envelope's own "Ok". Necessary for success, and not sufficient. */
    private const OK = 1;

    /**
     * What separates one pattern value from the next inside `text`.
     *
     * Configurable because the vendor's own samples are not consistent about it
     * and an account's approved body decides what it was registered with. A wrong
     * delimiter does not error - it delivers a message with the values run
     * together - so it has to be settable without a release.
     */
    private const DEFAULT_SEPARATOR = ';';

    /**
     * What each documented return code means, and what this package is allowed to
     * do about it.
     *
     * `[FailureKind, retryableOnSameGateway, safeToFailover, meaning]`.
     *
     * ⚠️ **`safeToFailover` follows one rule**: true where the documented meaning
     * is about THIS account, gateway or line — something the next gateway does not
     * share — and false where it is about the RECIPIENT or the MESSAGE, which the
     * next gateway would read identically, or where the meaning is not specific
     * enough to tell. Nothing here is decided by whether a code looks serious.
     *
     * ⚠️ **`retryableOnSameGateway` is true for exactly one code.** The retry
     * backoff in this package is measured in seconds and minutes; a daily quota or
     * an hourly limit will not have cleared inside it, and retrying anyway just
     * spends the attempt budget before failing over. Only "the system is being
     * updated" is transient on that timescale.
     *
     * Meanings are the vendor's own, translated. Codes shared by both endpoints
     * carry identical meanings on both pages, which is why one meanings table
     * serves two operations while the MEMBERSHIP of each operation stays separate.
     */
    private const MEANINGS = [
        // ---- account, credentials, IP: this gateway's problem, not the message's
        -111 => [FailureKind::GatewayConfiguration, false, true, 'the requesting IP is not valid'],
        -110 => [FailureKind::GatewayConfiguration, false, true, 'an API key must be used instead of a password'],
        -109 => [FailureKind::GatewayConfiguration, false, true, 'an allowed IP must be configured for API access'],
        -108 => [FailureKind::GatewayConfiguration, false, true, 'the IP is blocked after failed API attempts'],
        -1 => [FailureKind::GatewayConfiguration, false, true, 'access to this web service is disabled for this account'],
        0 => [FailureKind::GatewayConfiguration, false, true, 'the username or password is not correct'],
        2 => [FailureKind::GatewayConfiguration, false, true, 'insufficient credit'],
        10 => [FailureKind::GatewayConfiguration, false, true, 'this account is not active'],
        12 => [FailureKind::GatewayConfiguration, false, true, 'the account documents are incomplete'],

        // ---- the sending line: also this gateway's, and another gateway has its own
        -7 => [FailureKind::GatewayConfiguration, false, true, 'there is a fault on the sender number; the provider says to contact support'],
        -3 => [FailureKind::GatewayConfiguration, false, true, 'the sender line is not defined in the provider system'],
        5 => [FailureKind::GatewayConfiguration, false, true, 'the sender number is not valid'],
        9 => [FailureKind::GatewayConfiguration, false, true, 'a public line cannot send through the web service'],

        // ---- this gateway's pattern binding, not the logical message
        -5 => [FailureKind::GatewayRejected, false, true, 'the values do not match the variables of the approved body'],
        -4 => [FailureKind::GatewayRejected, false, true, 'the body id is unknown or has not been approved on this account'],
        -2 => [FailureKind::GatewayRejected, false, true, 'this endpoint accepts only one recipient per request'],

        // ---- quotas and availability
        3 => [FailureKind::ProviderUnavailable, false, true, 'the daily send limit has been reached'],
        4 => [FailureKind::ProviderUnavailable, false, true, 'the send volume limit has been reached'],
        19 => [FailureKind::ProviderUnavailable, false, true, 'the hourly limit has been exceeded'],
        6 => [FailureKind::ProviderUnavailable, true, true, 'the provider system is being updated'],

        /*
         * ---- the message itself
         *
         * ⚠️ Not failable over. Iranian content filtering is largely a regulatory
         * matter rather than one provider's house style, so a filtered word or a
         * link is a refusal the next gateway would very probably repeat — and a
         * loop of identical refusals across every configured gateway helps nobody.
         */
        -10 => [FailureKind::InvalidMessage, false, false, 'the pattern values contain a link'],
        7 => [FailureKind::InvalidMessage, false, false, 'the text contains a filtered word'],
        14 => [FailureKind::InvalidMessage, false, false, 'the text contains a link'],
        15 => [FailureKind::InvalidMessage, false, false, 'sending to more than one number requires the opt-out marker in the text'],
        17 => [FailureKind::InvalidMessage, false, false, 'the message text is empty'],

        /*
         * ---- the recipient
         *
         * ⚠️ Not failable over either, and for the opposite reason: the number is
         * the same number at every gateway, so no other provider is going to find
         * it valid. See BLACKLIST for the one recipient-shaped code that IS worth
         * failing over.
         */
        16 => [FailureKind::InvalidRecipient, false, false, 'no recipient number was found'],
        18 => [FailureKind::InvalidRecipient, false, false, 'the recipient number is not valid'],

        /*
         * ---- the operator blacklist
         *
         * ⚠️ Deliberately NOT InvalidRecipient. The number is perfectly valid; it
         * is registered on the telecom's opt-out list, which restricts advertising
         * traffic on ordinary lines while service lines are exempt. This package
         * lets one logical template be free text on one gateway and an approved
         * service pattern on another, so refusing to try the next gateway would
         * block exactly the delivery route that exists for this case.
         *
         * So: a gateway-level refusal, safe to fail over. Worst case the chain
         * ends in the same refusal at each gateway, once each, every one recorded
         * with its reason.
         */
        35 => [FailureKind::GatewayRejected, false, true, 'the recipient number is on the operator blacklist, which restricts advertising traffic on ordinary lines'],

        /*
         * ---- "not sent", with no reason given
         *
         * The provider states only that it did not send. Conservative, because a
         * refusal whose cause is unstated could as easily be about this message as
         * about this account.
         */
        11 => [FailureKind::GatewayRejected, false, false, 'the provider reports the message was not sent, without saying why'],
    ];

    /**
     * The codes `SendSMS` documents. Nothing outside this list is a known text
     * error.
     *
     * ⚠️ **`-111` belongs here and was missing.** The vendor's REST guide lists it
     * for this endpoint — "IP درخواست کننده نامعتبر است" — and an earlier pass had
     * it in the pattern list only. The cost was not a missing translation: an
     * undocumented code falls through to `unknown()`, which is deliberately
     * conservative and never fails over, so a text send refused because this
     * server's address is not on the account's API allowlist stopped dead at the
     * first gateway. An allowlist is per-ACCOUNT, which makes it exactly the kind
     * of condition the next gateway does not share.
     */
    private const TEXT_CODES = [-111, -110, -109, -108, 0, 2, 3, 4, 5, 6, 7, 9, 10, 11, 12, 14, 15, 16, 17, 18, 35];

    /**
     * The codes `BaseServiceNumber` documents. Note what is absent as much as what
     * is present: no 3, 4, 5, 9, 14 or 15 here, and no -10, -7, -6, -5, -4, -3, -2,
     * -1 or 19 in the text list.
     */
    private const PATTERN_CODES = [-111, -110, -109, -108, -10, -7, -6, -5, -4, -3, -2, -1, 0, 2, 6, 7, 10, 11, 12, 16, 17, 18, 19, 35];

    /**
     * The one documented code this driver refuses to call a rejection.
     *
     * ⚠️ "خطای داخلی رخ داده است" — an internal error at the provider, with no
     * statement about what happened to the message. A provider can fail internally
     * after it has already taken responsibility for a send, so treating this as a
     * definite non-delivery is the assumption that turns one order confirmation
     * into two. Same reasoning as an HTTP 5xx, which this package also refuses to
     * read optimistically.
     */
    private const UNCERTAIN_CODES = [-6];

    /**
     * ⚠️ Documented for `BaseServiceNumber` ONLY: "در صورت دریافت (recId) یک عدد
     * بیش از 15 رقم به معنای ارسال موفق بوده".
     *
     * Not applied to the text endpoint, whose page states nothing of the kind.
     * Borrowing it there would be inventing a threshold, and a threshold the
     * provider never promised is one it is free to fall below — at which point
     * every message it genuinely accepted is recorded as refused and sent a second
     * time by another gateway.
     */
    private const PATTERN_RECORD_ID_DIGITS = 15;

    /**
     * `SendSMS` documents `1` as an outcome in its own right — "درخواست با موفقیت
     * انجام شد", the request was carried out successfully — listed among the recIds
     * and the error numbers rather than beside them.
     *
     * ⚠️ **It is an acknowledgement, not an identifier**, and the difference is the
     * whole reason this constant exists. `isRecordId()` accepts any positive number
     * this endpoint does not document as an error, so `1` was previously accepted
     * AS a recId and stored as the message id. The send outcome was right and the
     * id was fiction: `GetDeliveries2` takes a recID, and asking it about message
     * number 1 answers about somebody else's message or about nothing.
     *
     * So the message is accepted, as the provider says, and carries no provider id
     * at all — which is the truthful record of what this response contains.
     *
     * ⚠️ Text only. `BaseServiceNumber` publishes no such sentinel, and its
     * successful values are longer than fifteen digits; a bare `1` from the pattern
     * endpoint is an outcome the vendor does not document and stays a refusal.
     */
    private const TEXT_ACKNOWLEDGEMENT = '1';

    public function __construct(private readonly GatewayConfig $config) {}

    public function capabilities(): array
    {
        return [Capability::Text, Capability::Pattern];
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
            fn (): Response => $this->http()->asForm()->post($this->url('SendSMS'), [
                ...$this->credentials(),
                'to' => $message->to->national,
                'from' => $message->sender ?? $this->config->sender,
                'text' => (string) $message->body,
                'isFlash' => false,
            ]),
            fn (Response $response): SendResult => $this->interpret($response, DeliveryMode::Text),
        );
    }

    private function sendPattern(OutboundMessage $message): SendResult
    {
        $separator = $this->separator();

        if (($unsendable = $this->separatorCollision($message, $separator)) !== null) {
            return $unsendable;
        }

        return $this->perform(
            fn (): Response => $this->http()->asForm()->post($this->url('BaseServiceNumber'), [
                ...$this->credentials(),
                'to' => $message->to->national,
                'bodyId' => (int) $message->patternCode,
                // ⚠️ Positional, delimited, in mapping order. See the class comment.
                'text' => implode($separator, $message->parameterValues()),
            ]),
            fn (Response $response): SendResult => $this->interpret($response, DeliveryMode::Pattern),
        );
    }

    /**
     * Refuse, before contacting anybody, a message whose own values contain the
     * character that separates them.
     *
     * ⚠️ This is the one failure this provider's wire format cannot report. A value
     * of `Tehran, Iran` sent with a comma separator does not produce an error; it
     * produces a message with the city where the customer's name belongs and
     * everything after it shifted by one - delivered, billed, and looking entirely
     * successful in this package's own records. Nothing downstream can detect it,
     * so it has to be refused before it is sent.
     *
     * The value is NOT escaped or altered. No escaping mechanism is documented, so
     * any this package invented would be a guess about how the provider's parser
     * splits the string - and quietly rewriting somebody's data to make a send
     * succeed is worse than not sending it.
     *
     * Classified as this GATEWAY refusing this encoding rather than as a bad
     * message: every other provider here passes pattern values as discrete fields
     * and would carry it unchanged. Hence safe to fail over, and pointless to
     * retry on this one.
     */
    private function separatorCollision(OutboundMessage $message, string $separator): ?SendResult
    {
        $offending = [];

        foreach ($message->parameters as $parameter) {
            if (str_contains($parameter->value, $separator)) {
                // The variable NAME only. The value is somebody's data, and this
                // string is written to the attempts table.
                $offending[] = '['.$parameter->variable.']';
            }
        }

        if ($offending === []) {
            return null;
        }

        return SendResult::rejected(
            FailureKind::GatewayRejected,
            sprintf(
                'Melipayamak joins pattern values into one string separated by [%s], and the value for '
                .'%s contains that separator, which would silently split it into two parameters. Send '
                .'this message through a gateway that passes parameters separately, or configure this '
                .'gateway with a separator the values cannot contain.',
                $separator,
                implode(', ', $offending),
            ),
            retryableOnSameGateway: false,
            safeToFailover: true,
        );
    }

    /**
     * ⚠️ A username and password, in the request BODY, on every call.
     *
     * Unusual and worth stating: this provider has no header authentication and no
     * API key in this API. Both values are ordinary credentials of the gateway and
     * are redacted out of everything this driver reports.
     *
     * @return array<string, string>
     */
    private function credentials(): array
    {
        return [
            'username' => $this->config->requireCredential('username'),
            'password' => $this->config->requireCredential('password'),
        ];
    }

    private function separator(): string
    {
        $separator = $this->config->option('parameter_separator', self::DEFAULT_SEPARATOR);

        return is_string($separator) && $separator !== '' ? $separator : self::DEFAULT_SEPARATOR;
    }

    private function url(string $method): string
    {
        return rtrim((string) $this->config->option('url', self::DEFAULT_URL), '/').'/'.$method;
    }

    /**
     * Read the provider's answer, for the operation that asked the question.
     *
     * Two gates, in order: the envelope has to say Ok, and `Value` has to be
     * evidence of a send for THIS endpoint rather than one of the numbers it
     * documents as errors.
     *
     * That evidence comes in two shapes, and only one of them is an identifier.
     * See TEXT_ACKNOWLEDGEMENT for the other.
     */
    private function interpret(Response $response, DeliveryMode $mode): SendResult
    {
        // Raw for every decision below; the sanitized copy is what gets stored.
        $payload = $this->decode($response);

        $status = data_get($payload, 'RetStatus') ?? data_get($payload, 'retStatus');
        $code = $this->code(data_get($payload, 'Value') ?? data_get($payload, 'value'));

        if ((int) $status === self::OK && $code !== null) {
            if ($mode === DeliveryMode::Text && $code === self::TEXT_ACKNOWLEDGEMENT) {
                // Accepted, and deliberately with no id. Inventing one out of a
                // success sentinel is how a delivery lookup ends up asking about a
                // message that is not this one.
                return SendResult::accepted(null, $this->sanitized($payload));
            }

            if ($this->isRecordId($code, $mode)) {
                // As a string. It is an identifier a later GetDeliveries2 lookup
                // quotes back, not a quantity, and casting a 19-digit id to an int
                // is how it becomes a different id.
                return SendResult::accepted($code, $this->sanitized($payload));
            }
        }

        return $this->refusal($code, $status, $payload, $mode);
    }

    /**
     * `Value` as a canonical numeric string, or null if it is not a number at all.
     *
     * Leading zeros are stripped so that `018` and `18` are the same documented
     * code, and the sign is kept because most of the documented codes are
     * negative. Never cast: the successful values are longer than an int is
     * guaranteed to hold.
     */
    private function code(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match('/^(-?)0*(\\d+)$/', $value, $matches) !== 1) {
            return null;
        }

        $digits = ltrim($matches[2], '0');

        return $digits === '' ? '0' : $matches[1].$digits;
    }

    /**
     * Whether this value is a successful send id for this endpoint.
     *
     * ⚠️ The two endpoints answer this differently because their documentation
     * does.
     *
     * `BaseServiceNumber` publishes the shape of a successful recId — more than
     * fifteen digits — so that is the test, and it excludes every documented error
     * code by construction.
     *
     * `SendSMS` publishes no such shape, so the only documented evidence available
     * is ITS OWN error table: a positive number that page does not list. That is
     * weaker, and knowingly so — see the class comment and the handoff. It is not
     * made stronger by inventing a size rule the vendor never stated, nor by
     * borrowing the other endpoint's table.
     */
    private function isRecordId(string $code, DeliveryMode $mode): bool
    {
        if (str_starts_with($code, '-') || $code === '0') {
            return false;
        }

        if ($mode === DeliveryMode::Pattern) {
            return strlen($code) > self::PATTERN_RECORD_ID_DIGITS;
        }

        /*
         * ⚠️ `1` never arrives here: `interpret()` has already answered it as an
         * acknowledgement. It is the one value this endpoint's page lists that is
         * neither an error nor an id, so it is handled before the question "is this
         * an id?" is asked rather than inside it.
         *
         * ⚠️ This endpoint's OWN table, and nothing else.
         *
         * An earlier pass excluded the union of both tables here, reasoning that a
         * number this provider documents as an error anywhere is unlikely to be a
         * message id. That was a guess wearing evidence's clothing. The text page
         * publishes no shape for a recId — no length, no minimum — so there is
         * nothing to say a small positive recId is impossible, and refusing 19
         * because the OTHER endpoint calls it an hourly limit invents a rule out
         * of a different method's documentation.
         *
         * The failure it would cause is the one this driver has been corrected for
         * twice already, only inverted: a genuinely accepted message recorded as
         * refused, then sent a second time through another gateway. Operation-
         * specific research means operation-specific acceptance.
         */
        return ! $this->documents($code, self::TEXT_CODES);
    }

    /**
     * A refusal, classified from the documented meaning of the code where this
     * endpoint documents one.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function refusal(?string $code, mixed $status, ?array $payload, DeliveryMode $mode): SendResult
    {
        $documented = $mode === DeliveryMode::Pattern ? self::PATTERN_CODES : self::TEXT_CODES;
        $known = $code !== null && $this->documents($code, $documented);

        if ($known && $this->documents((string) $code, self::UNCERTAIN_CODES)) {
            // Not a rejection. See UNCERTAIN_CODES.
            return SendResult::uncertain(
                FailureKind::ProviderUnavailable,
                $this->describe($code, $status, $payload, 'an internal provider error, which does not say whether the message was sent'),
                $this->sanitized($payload),
            );
        }

        [$kind, $retryable, $failover, $meaning] = $known
            ? self::MEANINGS[(int) $code]
            : $this->unknown($code, $mode);

        return SendResult::rejected(
            $kind,
            $this->describe($code, $status, $payload, $meaning),
            $this->sanitized($payload),
            retryableOnSameGateway: $retryable,
            safeToFailover: $failover,
        );
    }

    /**
     * What to do with a code this endpoint does not document.
     *
     * ⚠️ Conservative in both cases, and never failed over. An undocumented code
     * could equally be an account problem the next gateway would not have or a
     * refusal of this exact message that every gateway would repeat, and guessing
     * the optimistic way turns one refusal into one refusal per gateway.
     *
     * A code the OTHER endpoint documents is reported as exactly that. It is
     * evidence that the value is not a message id, which is what matters most; it
     * is not evidence of what the code means here, so no meaning is claimed.
     *
     * @return array{0: FailureKind, 1: bool, 2: bool, 3: string}
     */
    private function unknown(?string $code, DeliveryMode $mode): array
    {
        $sibling = $mode === DeliveryMode::Pattern ? self::TEXT_CODES : self::PATTERN_CODES;

        $meaning = $code !== null && $this->documents($code, $sibling)
            ? 'an error number this provider documents for its other send method, with no documented meaning for this one'
            : 'an outcome this provider does not document';

        return [FailureKind::GatewayRejected, false, false, $meaning];
    }

    /**
     * Whether a code appears in one of the documented lists.
     *
     * Compared as STRINGS. The lists are written as integers because that is how
     * the vendor writes them, but a recId is longer than an integer is guaranteed
     * to hold, and casting one to compare it is how a large id silently becomes
     * PHP_INT_MAX.
     *
     * @param  list<int>  $codes
     */
    private function documents(string $code, array $codes): bool
    {
        foreach ($codes as $documented) {
            if ((string) $documented === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * The refusal in one line an operator can act on.
     *
     * `StrRetStatus` is appended only where nothing documented applies, and even
     * then it is quoted, never read: this package does not decide anything by
     * parsing a human sentence.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function describe(?string $code, mixed $status, ?array $payload, string $meaning): string
    {
        $said = (string) (data_get($payload, 'StrRetStatus') ?? data_get($payload, 'strRetStatus') ?? '');

        return $this->config->redact(rtrim(sprintf(
            'melipayamak refused this message: %s [RetStatus=%s, Value=%s]. %s',
            $meaning,
            is_scalar($status) ? (string) $status : '?',
            $code ?? '?',
            $said === '' ? '' : 'The provider said: '.$said,
        )));
    }
}
