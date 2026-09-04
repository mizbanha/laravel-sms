<?php

declare(strict_types=1);

use Mizbanha\Sms\Enums\DeliveryMode;
use Mizbanha\Sms\Enums\FailureKind;
use Mizbanha\Sms\Enums\SendOutcome;
use Mizbanha\Sms\Facades\Sms;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Melipayamak, against the REST API its own SDKs implement.
 *
 * Response shapes are the `{Value, RetStatus, StrRetStatus}` envelope every
 * official SDK parses.
 */
function meliOk(string $value = '1234567890'): array
{
    return ['Value' => $value, 'RetStatus' => 1, 'StrRetStatus' => 'Ok'];
}

function meliCredentials(): array
{
    return ['username' => 'meli-user', 'password' => 'meli-pass'];
}

/**
 * A recId of the documented successful shape: more than fifteen digits.
 *
 * Also longer than PHP's integer range in the tests that use the longer one, which
 * is what proves nothing casts it.
 */
const MELI_REC_ID = '9006312345678901';

function meliText(): void
{
    test()->configureGateway(
        driver: 'melipayamak',
        mode: DeliveryMode::Text,
        body: 'Hello {customer_name}',
        credentials: meliCredentials(),
    );
}

function meliPattern(): void
{
    test()->configureGateway(
        driver: 'melipayamak',
        mode: DeliveryMode::Pattern,
        body: 'Hello {customer_name}',
        patternCode: '48123',
        parameterMap: [['variable' => 'customer_name']],
        credentials: meliCredentials(),
    );
}

function meliSend()
{
    return Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();
}

/**
 * Every return code the vendor documents for SendSMS.
 *
 * ⚠️ `1` is documented for this endpoint too and is deliberately absent: it means
 * the request succeeded, so it belongs in the acceptance tests, not here.
 */
function meliTextCodes(): array
{
    return [-111, -110, -109, -108, 0, 2, 3, 4, 5, 6, 7, 9, 10, 11, 12, 14, 15, 16, 17, 18, 35];
}

/** Every return code the vendor documents for BaseServiceNumber. */
function meliPatternCodes(): array
{
    return [-111, -110, -109, -108, -10, -7, -6, -5, -4, -3, -2, -1, 0, 2, 6, 7, 10, 11, 12, 16, 17, 18, 19, 35];
}

it('sends text with credentials in the body and the national number', function () {
    $this->configureGateway(
        driver: 'melipayamak',
        mode: DeliveryMode::Text,
        body: 'Hello {customer_name}',
        credentials: meliCredentials(),
    );

    Http::fake(['*' => Http::response(meliOk())]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    expect($message->status->value)->toBe('accepted');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://rest.payamak-panel.com/api/SendSMS/SendSMS'
            && $request['username'] === 'meli-user'
            && $request['password'] === 'meli-pass'
            // This provider wants the national form, not E.164.
            && $request['to'] === '09121234567'
            && $request['from'] === '30001234'
            && $request['text'] === 'Hello Amid';
    });
});

it('sends a pattern as a numeric bodyId with values joined into one string', function () {
    // No named parameters at all here: the approved body has numbered placeholders
    // and the values are matched by position, so the mapping order is the contract.
    $this->configureGateway(
        driver: 'melipayamak',
        mode: DeliveryMode::Pattern,
        body: 'Hi {customer_name}, order {order_number}.',
        patternCode: '48123',
        parameterMap: [
            ['variable' => 'customer_name'],
            ['variable' => 'order_number'],
        ],
        credentials: meliCredentials(),
    );

    Http::fake(['*' => Http::response(meliOk(MELI_REC_ID))]);

    Sms::to('09121234567')->template('order-created')
        ->with(['customer_name' => 'Amid', 'order_number' => 'CF-1204'])
        ->send();

    Http::assertSent(function (Request $request): bool {
        return str_ends_with($request->url(), '/BaseServiceNumber')
            && $request['bodyId'] === 48123
            && $request['text'] === 'Amid;CF-1204';
    });
});

it('lets a gateway configure the pattern value separator', function () {
    // A wrong delimiter does not error — it delivers a message with the values run
    // together — so it has to be changeable without a release.
    [$gateway] = $this->configureGateway(
        driver: 'melipayamak',
        mode: DeliveryMode::Pattern,
        body: 'Hi {customer_name}, order {order_number}.',
        patternCode: '48123',
        parameterMap: [
            ['variable' => 'customer_name'],
            ['variable' => 'order_number'],
        ],
        credentials: meliCredentials(),
    );
    $gateway->forceFill(['options' => ['parameter_separator' => ',']])->save();

    Http::fake(['*' => Http::response(meliOk(MELI_REC_ID))]);

    Sms::to('09121234567')->template('order-created')
        ->with(['customer_name' => 'Amid', 'order_number' => 'CF-1204'])
        ->send();

    Http::assertSent(fn (Request $r): bool => $r['text'] === 'Amid,CF-1204');
});

it('stores the tracking id the delivery lookup would use', function () {
    $this->configureGateway(
        driver: 'melipayamak',
        mode: DeliveryMode::Text,
        body: 'Hello {customer_name}',
        credentials: meliCredentials(),
    );

    Http::fake(['*' => Http::response(meliOk('9988776655'))]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    // GetDeliveries2 takes exactly this recId.
    expect($message->attempts()->first()->provider_message_id)->toBe('9988776655');
});

it('reads a documented error number as a refusal even when the envelope says Ok', function () {
    /*
     * The reason success needs both fields. `RetStatus: 1` means the CALL worked;
     * the method's own answer is in `Value`, and 2 is the documented code for
     * insufficient credit. A message recorded as accepted is never retried, so
     * reading the envelope alone loses it silently.
     */
    $this->configureGateway(
        driver: 'melipayamak',
        mode: DeliveryMode::Text,
        body: 'Hello {customer_name}',
        credentials: meliCredentials(),
    );

    Http::fake(['*' => Http::response(['Value' => '2', 'RetStatus' => 1, 'StrRetStatus' => 'Ok'])]);

    $attempt = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->provider_message_id)->toBeNull()
        // The documented meaning, not the envelope's cheerful "Ok".
        ->and($attempt->error)->toContain('insufficient credit');
});

it('rejects every documented text return code, and stores none of them as a message id', function (int $code) {
    /*
     * ⚠️ The regression this file exists for.
     *
     * Thirteen of these twenty are POSITIVE, and an earlier implementation
     * accepted any positive value that was not in a much shorter list — so
     * `{"RetStatus": 1, "Value": 18}`, which means "the recipient number is not
     * valid", was recorded as an accepted send with message id 18. A message
     * recorded as accepted is never retried and never failed over. It is simply
     * gone, and the records say it went out.
     */
    meliText();

    Http::fake(['*' => Http::response(['Value' => (string) $code, 'RetStatus' => 1, 'StrRetStatus' => 'Ok'])]);

    $attempt = meliSend()->attempts()->first();

    expect($attempt->outcome)->not->toBe(SendOutcome::Accepted)
        ->and($attempt->provider_message_id)->toBeNull();
})->with(meliTextCodes());

it('rejects every documented pattern return code, and stores none of them as a message id', function (int $code) {
    meliPattern();

    Http::fake(['*' => Http::response(['Value' => (string) $code, 'RetStatus' => 1, 'StrRetStatus' => 'Ok'])]);

    $attempt = meliSend()->attempts()->first();

    expect($attempt->outcome)->not->toBe(SendOutcome::Accepted)
        ->and($attempt->provider_message_id)->toBeNull();
})->with(meliPatternCodes());

it('reports the documented meaning of a code its own endpoint documents', function (string $code, string $expected) {
    meliText();

    Http::fake(['*' => Http::response(['Value' => $code, 'RetStatus' => 1])]);

    expect(meliSend()->attempts()->first()->error)->toContain($expected);
})->with([
    ['0', 'username or password'],
    ['2', 'insufficient credit'],
    ['5', 'sender number is not valid'],
    ['6', 'being updated'],
    ['17', 'text is empty'],
    ['18', 'recipient number is not valid'],
    ['35', 'blacklist'],
    ['-110', 'API key'],
]);

it('does not lend one endpoint the other endpoint documentation', function () {
    /*
     * `-4` is "the body id is unknown or not approved", documented for
     * BaseServiceNumber and for nothing else. Arriving from a free-text send it is
     * still evidence that the value is not a message id — which is what matters —
     * but it is not evidence of what it would mean there, so no meaning is claimed
     * and the refusal stays conservative.
     */
    meliText();

    Http::fake(['*' => Http::response(['Value' => '-4', 'RetStatus' => 1])]);

    $attempt = meliSend()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->provider_message_id)->toBeNull()
        ->and($attempt->error)->toContain('other send method')
        ->and($attempt->error)->not->toContain('body id')
        ->and($attempt->safe_to_failover)->toBeFalse();
});

it('reads -111 as a text error this endpoint documents, and lets the message move on', function () {
    /*
     * ⚠️ Regression, found by reading the vendor's own REST guide against this
     * driver.
     *
     * `-111` — "IP درخواست کننده نامعتبر است" — is documented for SendSMS, and
     * this driver's text table did not list it. An unlisted code falls to
     * `unknown()`, which claims no meaning and, correctly for something unknown,
     * never fails over. Here that caution was exactly wrong: an API allowlist is a
     * property of the ACCOUNT, so the next gateway is the one thing that WOULD
     * have carried the message, and it was the one thing ruled out.
     *
     * The asymmetry is what makes this worth its own test rather than a row in the
     * table above: the fix has to leave the message failable-over AND stop the
     * driver describing it as somebody else's endpoint.
     */
    meliText();

    Http::fake(['*' => Http::response(['Value' => '-111', 'RetStatus' => 1])]);

    $attempt = meliSend()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->failure_kind)->toBe(FailureKind::GatewayConfiguration)
        ->and($attempt->safe_to_failover)->toBeTrue()
        ->and($attempt->retryable_on_same_gateway)->toBeFalse()
        ->and($attempt->provider_message_id)->toBeNull()
        ->and($attempt->error)->toContain('IP')
        // The tell of the bug: the unknown-code wording, on a code this endpoint
        // documents perfectly well.
        ->and($attempt->error)->not->toContain('other send method')
        ->and($attempt->error)->not->toContain('does not document');
});

it('accepts the documented text acknowledgement without inventing a message id', function () {
    /*
     * ⚠️ Regression. `SendSMS` lists `1` among its return values meaning "درخواست
     * با موفقیت انجام شد" — the request succeeded. It is not a recId, and it was
     * being stored as one: any positive number the endpoint does not document as
     * an error passes `isRecordId()`.
     *
     * The outcome was never wrong, which is why nothing caught it. The RECORD was:
     * a message filed against provider id "1", which `GetDeliveries2` will happily
     * answer for — about a different message, or about nothing.
     *
     * Accepted, and with no id, because that is what this response actually says.
     */
    meliText();

    Http::fake(['*' => Http::response(['Value' => '1', 'RetStatus' => 1, 'StrRetStatus' => 'Ok'])]);

    $attempt = meliSend()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Accepted)
        ->and($attempt->provider_message_id)->toBeNull()
        ->and($attempt->failure_kind)->toBeNull();
});

it('does not read the text acknowledgement as success on the pattern endpoint', function () {
    /*
     * ⚠️ The other half of the same fix. `BaseServiceNumber` publishes no such
     * sentinel — its successful values are longer than fifteen digits — so a bare
     * `1` there is an outcome the vendor does not document, and borrowing the text
     * endpoint's meaning would file a refusal as a delivered message.
     */
    meliPattern();

    Http::fake(['*' => Http::response(['Value' => '1', 'RetStatus' => 1, 'StrRetStatus' => 'Ok'])]);

    $attempt = meliSend()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->provider_message_id)->toBeNull()
        ->and($attempt->error)->toContain('does not document');
});

it('does not treat a pattern-only error code as a text error', function (int $code) {
    /*
     * ⚠️ Acceptance is per operation, exactly as the research is.
     *
     * 19 is "hourly limit exceeded" at BaseServiceNumber and appears nowhere on
     * the SendSMS page. An earlier pass excluded the union of both tables here,
     * which sounds cautious and is not: the text page publishes no shape for a
     * recId — no length, no minimum — so there is no evidence that a small
     * positive recId is impossible, and rejecting one on another method's
     * documentation is the same class of invention as the `>1000` threshold that
     * was removed. It would record a genuinely accepted message as refused and
     * send it a second time through another gateway.
     */
    meliText();

    Http::fake(['*' => Http::response(['Value' => (string) $code, 'RetStatus' => 1])]);

    $attempt = meliSend()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Accepted)
        ->and($attempt->provider_message_id)->toBe((string) $code);
})->with([19, 8, 13, 20, 21]);

it('refuses a pattern recId of exactly fifteen digits', function () {
    /*
     * The pattern endpoint is the one that publishes a shape for a successful id:
     * "more than 15 digits". Fifteen is therefore not one, and the boundary is
     * worth a test of its own — an off-by-one here is a documented rejection code
     * being filed as a successful send.
     */
    meliPattern();

    Http::fake(['*' => Http::response(['Value' => '123456789012345', 'RetStatus' => 1, 'StrRetStatus' => 'Ok'])]);

    $attempt = meliSend()->attempts()->first();

    expect(strlen('123456789012345'))->toBe(15)
        ->and($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->provider_message_id)->toBeNull();
});

it('accepts a pattern recId of sixteen digits', function () {
    meliPattern();

    Http::fake(['*' => Http::response(['Value' => MELI_REC_ID, 'RetStatus' => 1, 'StrRetStatus' => 'Ok'])]);

    $attempt = meliSend()->attempts()->first();

    expect(strlen(MELI_REC_ID))->toBe(16)
        ->and($attempt->outcome)->toBe(SendOutcome::Accepted)
        ->and($attempt->provider_message_id)->toBe(MELI_REC_ID);
});

it('keeps a pattern recId longer than an integer exactly as it arrived', function () {
    // ⚠️ Larger than PHP_INT_MAX. Cast anywhere along the way and it comes back a
    // different number, which is a delivery report that will never match and a
    // dispute nobody can settle.
    meliPattern();

    $id = '92233720368547758099';

    Http::fake(['*' => Http::response(['Value' => $id, 'RetStatus' => 1])]);

    expect($id > (string) PHP_INT_MAX)->toBeTrue()
        ->and(meliSend()->attempts()->first()->provider_message_id)->toBe($id);
});

it('does not apply the pattern digit rule to a text send', function () {
    /*
     * ⚠️ The other half of the same discipline. The SendSMS page publishes no
     * recId shape, so borrowing the pattern rule would be inventing a threshold —
     * and a threshold the provider never promised is one it is free to fall below,
     * at which point every message it genuinely accepted is recorded as refused
     * and sent a second time somewhere else.
     */
    meliText();

    Http::fake(['*' => Http::response(['Value' => '1234567890', 'RetStatus' => 1])]);

    $attempt = meliSend()->attempts()->first();

    expect(strlen('1234567890'))->toBeLessThan(16)
        ->and($attempt->outcome)->toBe(SendOutcome::Accepted)
        ->and($attempt->provider_message_id)->toBe('1234567890');
});

it('classifies documented codes by what they actually mean', function (
    int $code,
    FailureKind $kind,
    bool $retryable,
    bool $failover,
) {
    meliText();

    Http::fake(['*' => Http::response(['Value' => (string) $code, 'RetStatus' => 1])]);

    $attempt = meliSend()->attempts()->first();

    expect($attempt->failure_kind)->toBe($kind)
        ->and($attempt->retryable_on_same_gateway)->toBe($retryable)
        ->and($attempt->safe_to_failover)->toBe($failover);
})->with([
    // This account, these credentials, this line: another gateway has its own.
    'bad credentials' => [0, FailureKind::GatewayConfiguration, false, true],
    'no credit' => [2, FailureKind::GatewayConfiguration, false, true],
    'invalid sender' => [5, FailureKind::GatewayConfiguration, false, true],
    'inactive account' => [10, FailureKind::GatewayConfiguration, false, true],
    'api key required' => [-110, FailureKind::GatewayConfiguration, false, true],
    'unauthorised requesting ip' => [-111, FailureKind::GatewayConfiguration, false, true],
    'ip allowlist not configured' => [-109, FailureKind::GatewayConfiguration, false, true],
    'ip blocked' => [-108, FailureKind::GatewayConfiguration, false, true],

    // Quotas: another gateway has its own quota, but waiting five minutes on this
    // one will not clear a daily limit.
    'daily limit' => [3, FailureKind::ProviderUnavailable, false, true],
    'volume limit' => [4, FailureKind::ProviderUnavailable, false, true],

    // The one genuinely transient code, and the only one worth trying again here.
    'system updating' => [6, FailureKind::ProviderUnavailable, true, true],

    // The message: the next gateway would read it the same way.
    'filtered word' => [7, FailureKind::InvalidMessage, false, false],
    'contains a link' => [14, FailureKind::InvalidMessage, false, false],
    'empty text' => [17, FailureKind::InvalidMessage, false, false],

    // The recipient: the same number at every gateway.
    'no recipient found' => [16, FailureKind::InvalidRecipient, false, false],
    'invalid recipient' => [18, FailureKind::InvalidRecipient, false, false],

    // Stated to be a non-delivery, with no reason offered.
    'not sent' => [11, FailureKind::GatewayRejected, false, false],
]);

it('treats the operator blacklist as a gateway refusal rather than a bad number', function () {
    /*
     * ⚠️ Deliberately NOT InvalidRecipient, which would stop the chain.
     *
     * A number on the telecom opt-out list is a perfectly valid number. The list
     * restricts ADVERTISING traffic on ordinary lines; approved service lines are
     * exactly the route that still reaches it. This package lets one logical
     * template be free text on one gateway and an approved service pattern on
     * another, so refusing to try the next gateway would block the one delivery
     * path that exists for this case.
     */
    meliText();

    Http::fake(['*' => Http::response(['Value' => '35', 'RetStatus' => 1])]);

    $attempt = meliSend()->attempts()->first();

    expect($attempt->failure_kind)->toBe(FailureKind::GatewayRejected)
        ->and($attempt->failure_kind)->not->toBe(FailureKind::InvalidRecipient)
        ->and($attempt->safe_to_failover)->toBeTrue()
        ->and($attempt->retryable_on_same_gateway)->toBeFalse();
});

it('treats a documented internal provider error as uncertain, not as a refusal', function () {
    /*
     * ⚠️ The only documented code that does not settle the question.
     *
     * "خطای داخلی رخ داده است" says something broke inside the provider; it does
     * not say whether the message had already been taken. A provider can fail
     * after accepting, so calling this a definite non-delivery is the assumption
     * that sends one order confirmation twice. Same reading this package gives an
     * HTTP 5xx.
     */
    meliPattern();

    Http::fake(['*' => Http::response(['Value' => '-6', 'RetStatus' => 1])]);

    $message = meliSend();
    $attempt = $message->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Uncertain)
        ->and($attempt->safe_to_failover)->toBeFalse()
        ->and($attempt->retryable_on_same_gateway)->toBeFalse()
        ->and($message->status->value)->toBe('unknown');
});

it('reads a documented pattern code with the meaning its own endpoint gives it', function (
    int $code,
    FailureKind $kind,
    bool $failover,
) {
    meliPattern();

    Http::fake(['*' => Http::response(['Value' => (string) $code, 'RetStatus' => 1])]);

    $attempt = meliSend()->attempts()->first();

    expect($attempt->failure_kind)->toBe($kind)
        ->and($attempt->safe_to_failover)->toBe($failover);
})->with([
    // This binding's approved body, not the logical message: another gateway's
    // pattern may be registered correctly.
    'unknown body id' => [-4, FailureKind::GatewayRejected, true],
    'values do not match the body' => [-5, FailureKind::GatewayRejected, true],
    'one recipient per request' => [-2, FailureKind::GatewayRejected, true],

    // The account and the line.
    'webservice disabled' => [-1, FailureKind::GatewayConfiguration, true],
    'line not defined' => [-3, FailureKind::GatewayConfiguration, true],
    // ⚠️ The one row here the text endpoint DOES share. It used to be pattern-only
    // as far as this driver was concerned, which was the bug; it stays in this
    // table to prove the pattern side kept its meaning when the text side gained
    // one, rather than the two endpoints having been quietly merged.
    'invalid requester ip' => [-111, FailureKind::GatewayConfiguration, true],

    // The values themselves.
    'link in the values' => [-10, FailureKind::InvalidMessage, false],

    // An hourly limit will not clear inside this package's backoff.
    'hourly limit' => [19, FailureKind::ProviderUnavailable, true],
]);

it('refuses a Value that is not a positive whole number', function (mixed $value) {
    // Not an id and not a documented code: the one honest answer left is that this
    // response was not understood, and an unrecognised response is not a send.
    $this->configureGateway(
        driver: 'melipayamak',
        mode: DeliveryMode::Text,
        body: 'Hello {customer_name}',
        credentials: meliCredentials(),
    );

    Http::fake(['*' => Http::response(['Value' => $value, 'RetStatus' => 1])]);

    $attempt = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->provider_message_id)->toBeNull();
})->with([
    'zero' => ['0'],
    'an undocumented negative' => ['-7'],
    'not a number' => ['error'],
    'empty' => [''],
]);

it('keeps the recId as written rather than as a number', function () {
    // recIds are documented as long unique numbers; casting one to an int is how a
    // 19-digit id becomes a different id on the next machine.
    $this->configureGateway(
        driver: 'melipayamak',
        mode: DeliveryMode::Text,
        body: 'Hello {customer_name}',
        credentials: meliCredentials(),
    );

    Http::fake(['*' => Http::response(meliOk('9223372036854775809'))]);

    $attempt = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    expect($attempt->provider_message_id)->toBe('9223372036854775809');
});

it('classifies an unexplained refusal conservatively', function () {
    /*
     * An error number outside the documented set. Recognising some codes does not
     * make the rest guessable: this could be an account problem another gateway
     * would not have, or a refusal of this exact message that every gateway would
     * repeat. Failing over on a guess turns one refusal into one per gateway.
     *
     * `StrRetStatus` is quoted here because there is nothing documented to say
     * instead — quoted into the error text, never read to decide anything.
     */
    $this->configureGateway(
        driver: 'melipayamak',
        mode: DeliveryMode::Text,
        body: 'Hello {customer_name}',
        credentials: meliCredentials(),
    );

    Http::fake(['*' => Http::response(['Value' => '4242', 'RetStatus' => 7, 'StrRetStatus' => 'InvalidUser'])]);

    $attempt = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->failure_kind)->toBe(FailureKind::GatewayRejected)
        ->and($attempt->safe_to_failover)->toBeFalse()
        ->and($attempt->error)->toContain('InvalidUser');
});

it('refuses a pattern value containing the separator, without contacting the provider', function () {
    /*
     * ⚠️ The failure this provider's wire format cannot report.
     *
     * Values are joined into one delimited string, so a value that contains the
     * delimiter does not produce an error — it produces a delivered, billed
     * message with everything after the split shifted by one parameter, recorded
     * here as a complete success. There is nothing to detect afterwards, so it is
     * refused before the request is made.
     */
    $this->configureGateway(
        driver: 'melipayamak',
        mode: DeliveryMode::Pattern,
        body: 'Hi {customer_name}, order {order_number}.',
        patternCode: '48123',
        parameterMap: [
            ['variable' => 'customer_name'],
            ['variable' => 'order_number'],
        ],
        credentials: meliCredentials(),
    );

    Http::fake(['*' => Http::response(meliOk())]);

    $message = Sms::to('09121234567')->template('order-created')
        ->with(['customer_name' => 'Amid;Esfahani', 'order_number' => 'CF-1204'])
        ->send();

    $attempt = $message->attempts()->first();

    Http::assertNothingSent();

    expect($attempt->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempt->failure_kind)->toBe(FailureKind::GatewayRejected)
        // This gateway cannot encode it; a provider with discrete parameter fields
        // can, and nothing was sent, so moving on is safe.
        ->and($attempt->safe_to_failover)->toBeTrue()
        // Nothing about it would be different next time on this gateway.
        ->and($attempt->retryable_on_same_gateway)->toBeFalse()
        // The variable name, so the mapping can be found. Never the value.
        ->and($attempt->error)->toContain('[customer_name]')
        ->and($attempt->error)->not->toContain('Esfahani');
});

it('names every colliding value, and leaves all of them untouched', function () {
    // No escaping is attempted, because no escaping mechanism is documented: a
    // guess at the provider's parser would corrupt the message just as quietly,
    // and rewriting somebody's data to force a send through is worse than refusing.
    $this->configureGateway(
        driver: 'melipayamak',
        mode: DeliveryMode::Pattern,
        body: 'Hi {customer_name}, order {order_number}.',
        patternCode: '48123',
        parameterMap: [
            ['variable' => 'customer_name'],
            ['variable' => 'order_number'],
        ],
        credentials: meliCredentials(),
    );

    Http::fake(['*' => Http::response(meliOk())]);

    $variables = ['customer_name' => 'Amid;Esfahani', 'order_number' => 'CF;1204'];

    $attempt = Sms::to('09121234567')->template('order-created')->with($variables)->send()
        ->attempts()->first();

    expect($attempt->error)->toContain('[customer_name]')
        ->and($attempt->error)->toContain('[order_number]')
        // The caller's own array is exactly as it was handed over.
        ->and($variables)->toBe(['customer_name' => 'Amid;Esfahani', 'order_number' => 'CF;1204']);
});

it('checks the separator the gateway actually configured, not the default', function () {
    // A semicolon is unremarkable in a value once the gateway is set to commas, and
    // a comma is unremarkable in an address. Only the configured one matters.
    [$gateway] = $this->configureGateway(
        driver: 'melipayamak',
        mode: DeliveryMode::Pattern,
        body: 'Hi {customer_name}, order {order_number}.',
        patternCode: '48123',
        parameterMap: [
            ['variable' => 'customer_name'],
            ['variable' => 'order_number'],
        ],
        credentials: meliCredentials(),
    );
    $gateway->forceFill(['options' => ['parameter_separator' => ',']])->save();

    Http::fake(['*' => Http::response(meliOk(MELI_REC_ID))]);

    // Contains the default separator, but not the configured one: perfectly sendable.
    Sms::to('09121234567')->template('order-created')
        ->with(['customer_name' => 'Amid;Esfahani', 'order_number' => 'CF-1204'])
        ->send();

    Http::assertSent(fn (Request $r): bool => $r['text'] === 'Amid;Esfahani,CF-1204');
});

it('refuses a text send with no separator concern at all', function () {
    // The guard belongs to the pattern encoding. Free text is one field and may
    // contain anything, and a text message must not be refused for a semicolon.
    $this->configureGateway(
        driver: 'melipayamak',
        mode: DeliveryMode::Text,
        body: 'Hello {customer_name}',
        credentials: meliCredentials(),
    );

    Http::fake(['*' => Http::response(meliOk())]);

    $message = Sms::to('09121234567')->template('order-created')
        ->with(['customer_name' => 'Amid; and everyone'])->send();

    expect($message->status->value)->toBe('accepted');
    Http::assertSent(fn (Request $r): bool => $r['text'] === 'Hello Amid; and everyone');
});

it('treats a server error as uncertain', function () {
    $this->configureGateway(
        driver: 'melipayamak',
        mode: DeliveryMode::Text,
        body: 'Hello {customer_name}',
        credentials: meliCredentials(),
    );

    Http::fake(['*' => Http::response('down', 502)]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    expect($message->attempts()->first()->outcome)->toBe(SendOutcome::Uncertain)
        ->and($message->status->value)->toBe('unknown');
});

it('never lets the username or password reach the stored error or payload', function () {
    // Both travel in the request body on every call, so a provider that echoes the
    // request is a credential leak into a log kept for months.
    $this->configureGateway(
        driver: 'melipayamak',
        mode: DeliveryMode::Text,
        body: 'Hello {customer_name}',
        credentials: meliCredentials(),
    );

    Http::fake(['*' => Http::response([
        'Value' => '3',
        'RetStatus' => 3,
        'StrRetStatus' => 'rejected for meli-user with meli-pass',
        'echo' => ['password' => 'meli-pass'],
    ])]);

    $attempt = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])
        ->send()->attempts()->first();

    expect($attempt->error)->not->toContain('meli-user')
        ->and($attempt->error)->not->toContain('meli-pass')
        ->and(json_encode($attempt->provider_payload))->not->toContain('meli-pass');
});
