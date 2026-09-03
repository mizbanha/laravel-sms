<?php

declare(strict_types=1);

use Amid\Sms\Contracts\OtpCodeGenerator;
use Amid\Sms\Contracts\ReportsDeliveryStatus;
use Amid\Sms\Drivers\IpPanelDriver;
use Amid\Sms\Enums\Capability;
use Amid\Sms\Enums\DeliveryMode;
use Amid\Sms\Enums\DeliveryStatus;
use Amid\Sms\Exceptions\DeliveryLookupFailed;
use Amid\Sms\Facades\Otp;
use Amid\Sms\Facades\Sms;
use Amid\Sms\Gateways\GatewayConfig;
use Amid\Sms\Phone\PhoneNumber;
use Amid\Sms\Support\TableNames;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * IPPanel delivery lookup, against the current Edge reporting documentation.
 *
 * Two things make this provider the right second one to prove the abstraction on,
 * and both are the opposite of Twilio:
 *
 *   - **its report is per recipient, in a list.** The provider also publishes a
 *     bulk report whose `state` reaches `finish`, and "the bulk finished" is not
 *     "the handset received it". Only the recipients endpoint carries a
 *     per-destination delivery code.
 *
 *   - ⚠️ **its report contains the original message text**, right beside the
 *     status. That is the leak this milestone exists to close: a delivery lookup
 *     for an OTP would otherwise walk the code back into the database days after
 *     the send deliberately refused to store it.
 *
 * Every request here is faked. No IPPanel account was contacted.
 */
const IPPANEL_SEND = 'edge.ippanel.com/v1/api/send';
const IPPANEL_REPORT = 'edge.ippanel.com/v1/api/report/recipients*';
const IPPANEL_TOKEN = 'ippanel-secret-token';

function ippanel(): IpPanelDriver
{
    return new IpPanelDriver(new GatewayConfig(
        key: 'primary',
        sender: '+983000505',
        credentials: ['api_key' => IPPANEL_TOKEN],
    ));
}

/** The documented success envelope around a list of recipient rows. */
function recipientReport(array $rows): array
{
    return [
        'data' => $rows,
        'meta' => ['current_page' => 1, 'total' => count($rows), 'status' => true, 'message_code' => '200-1'],
    ];
}

function recipientRow(string $recipient, ?string $status = '2', string $message = 'متن پیام'): array
{
    return [
        'recipient' => $recipient,
        // ⚠️ The provider really does return the SMS body here.
        'message' => $message,
        'is_readable' => true,
        'msg_parts' => '1',
        'message_status' => $status,
    ];
}

function askIpPanel(array $body, int $status = 200, string $to = '+989121234567')
{
    Http::fake(['*' => Http::response($body, $status)]);

    return ippanel()->deliveryStatus('5544778899', new PhoneNumber($to, '09121234567', 'IR'));
}

it('advertises the delivery-report capability it actually implements', function () {
    expect(ippanel()->capabilities())->toContain(Capability::DeliveryReport)
        ->and(ippanel())->toBeInstanceOf(ReportsDeliveryStatus::class);
});

it('asks the recipients endpoint for the stored outbox id, with the bare token', function () {
    /*
     * ⚠️ The recipients endpoint, not `by_bulk`. And ⚠️ no "Bearer" on the header:
     * the documented value is the bare token, and adding a scheme produces a 401
     * that reads like a wrong key.
     */
    askIpPanel(recipientReport([recipientRow('+989121234567')]));

    Http::assertSent(function ($request): bool {
        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://edge.ippanel.com/v1/api/report/recipients?')
            && str_contains($request->url(), 'bulk_id=5544778899')
            && $request->hasHeader('Authorization', IPPANEL_TOKEN);
    });
});

it('maps every documented recipient status onto a neutral one', function (string $code, DeliveryStatus $expected) {
    $result = askIpPanel(recipientReport([recipientRow('+989121234567', $code)]));

    expect($result->status)->toBe($expected)
        ->and($result->providerStatus)->toBe($code);
})->with([
    /*
     * ⚠️ 0 and 1 are both `sent`, not `delivered`. Handing a message to an operator
     * and an operator acknowledging it are both events on the way to a handset, and
     * neither one is the handset.
     */
    ['0', DeliveryStatus::Sent],   // sent to the operator
    ['1', DeliveryStatus::Sent],   // the operator received the message
    ['2', DeliveryStatus::Delivered],
    ['3', DeliveryStatus::Failed], // not delivered to the recipient
    /*
     * ⚠️ 4 is blacklisted, and here it is a delivery FAILURE — which reads as a
     * contradiction of Melipayamak's blacklist code 35, deliberately kept
     * failable-over at send time because an approved service line may still reach a
     * number on the operator's advertising opt-out list. It is not a contradiction:
     * that is the send phase, deciding whether another gateway may try. This is
     * afterwards, reporting what happened, and what happened is that this message
     * was not delivered. It causes no resend and cannot.
     */
    ['4', DeliveryStatus::Failed],
]);

it('treats an undocumented recipient status as unknown', function () {
    // The documentation publishes exactly five values. A sixth is not one we are
    // willing to translate.
    expect(askIpPanel(recipientReport([recipientRow('+989121234567', '9')]))->status)
        ->toBe(DeliveryStatus::Unknown);
});

it('picks the row belonging to this attempt recipient', function () {
    /*
     * The report is a list. Reading the first row would attach somebody else's
     * delivery result to this message — quietly, and with no way to notice.
     */
    $result = askIpPanel(recipientReport([
        recipientRow('+989350000000', '3'),
        recipientRow('+989121234567', '2'),
        recipientRow('+989190000000', '4'),
    ]));

    expect($result->status)->toBe(DeliveryStatus::Delivered);
});

it('matches a recipient the provider spelled differently', function () {
    /*
     * We hold canonical E.164; the provider echoes what it was given, and no
     * contract promises it comes back spelled identically. Digits, with leading
     * zeros dropped, is the whole of the tolerance - ⚠️ matching on the last N
     * digits would eventually attach one subscriber's delivery result to another
     * subscriber's message.
     */
    expect(askIpPanel(recipientReport([recipientRow('0098 912 123 4567', '2')]))->status)
        ->toBe(DeliveryStatus::Delivered);
});

it('reports nothing rather than guessing at a number it cannot line up', function () {
    // A different subscriber entirely. The conservative answer is silence.
    askIpPanel(recipientReport([recipientRow('+989121234568', '2')]));
})->throws(DeliveryLookupFailed::class);

it('reports nothing when this recipient is absent from the report', function () {
    askIpPanel(recipientReport([recipientRow('+989350000000', '2')]));
})->throws(DeliveryLookupFailed::class);

it('reports nothing when the report carries no status yet', function () {
    // The documentation says the per-recipient delivery status appears once the
    // message is finalised. Until then there is nothing to learn, and nothing is
    // written.
    askIpPanel(recipientReport([recipientRow('+989121234567', null)]));
})->throws(DeliveryLookupFailed::class);

it('refuses to guess when the report request fails', function (int $status) {
    // 401 for an expired token, 422 for an id this account cannot see. Neither says
    // anything about whether the message arrived.
    askIpPanel(['data' => null, 'meta' => ['status' => false, 'message_code' => '400-1']], $status);
})->with([401, 422, 500])->throws(DeliveryLookupFailed::class);

it('refuses a 200 that is not a success envelope', function () {
    askIpPanel(['data' => [], 'meta' => ['status' => false, 'message_code' => '400-2']]);
})->throws(DeliveryLookupFailed::class);

it('never carries the original message text out of the report', function () {
    /*
     * ⚠️ The single most important assertion in this file. `message_status` is read
     * and `message` is not — not into the result, not into a payload field, because
     * there is no payload field for it to go into.
     */
    $result = askIpPanel(recipientReport([recipientRow('+989121234567', '2', 'Your code is 482193')]));

    expect(json_encode($result))->not->toContain('482193')
        ->and($result->error)->toContain('delivered to the recipient')
        ->and($result->error)->toContain('message_status=2');
});

/*
|--------------------------------------------------------------------------
| Through the real pipeline
|--------------------------------------------------------------------------
*/

it('records a delivered verdict through the pipeline without storing the report', function () {
    test()->configureGateway(driver: 'ippanel', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake([
        IPPANEL_SEND => Http::response([
            'data' => ['message_outbox_ids' => [5544778899]],
            'meta' => ['status' => true, 'message_code' => '200-1'],
        ]),
        IPPANEL_REPORT => Http::response(recipientReport([
            recipientRow('+989121234567', '2', 'Hello Amid'),
        ])),
    ]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    expect($message->delivery_status)->toBe(DeliveryStatus::Pending)
        ->and($message->attempts()->first()->provider_message_id)->toBe('5544778899');

    Sms::refreshDelivery($message);

    $attempt = $message->attempts()->first()->fresh();

    expect($attempt->delivery_status)->toBe(DeliveryStatus::Delivered)
        ->and($attempt->provider_delivery_status)->toBe('2')
        ->and($message->fresh()->delivery_status)->toBe(DeliveryStatus::Delivered)
        // ⚠️ Nothing raw. Not the report envelope, not the row, not the token.
        ->and(json_encode(DB::table(TableNames::attempts())->where('id', $attempt->getKey())->first()))
        ->not->toContain('is_readable')
        ->not->toContain(IPPANEL_TOKEN);
});

it('never brings an OTP back into the database through a delivery report', function () {
    /*
     * ⚠️ **The leak M6 exists to close, end to end.**
     *
     * The send stored no body, no variables and no payload, because an OTP is
     * sensitive. Days later the provider's report answers with the message text
     * still in it. If any of this were persisted, the code would be back in the
     * database — through the reporting door, long after it was carefully kept out
     * of the sending one.
     */
    app()->bind(OtpCodeGenerator::class, fn (): OtpCodeGenerator => new class implements OtpCodeGenerator
    {
        public function generate(int $length): string
        {
            return '482193';
        }
    });
    app()->forgetInstance(\Amid\Sms\Otp\OtpManager::class);
    Otp::clearResolvedInstances();

    test()->configureGateway(
        driver: 'ippanel',
        mode: DeliveryMode::Text,
        body: 'Your login code is {code}.',
        templateKey: 'login-otp',
    );

    Http::fake([
        IPPANEL_SEND => Http::response([
            'data' => ['message_outbox_ids' => [5544778899]],
            'meta' => ['status' => true, 'message_code' => '200-1'],
        ]),
        // The provider echoes the delivered text, exactly as its documentation shows.
        IPPANEL_REPORT => Http::response(recipientReport([
            recipientRow('+989121234567', '3', 'Your login code is 482193.'),
        ])),
    ]);

    $result = Otp::send('09121234567', 'login-otp');
    $message = $result->message;

    expect($message->is_sensitive)->toBeTrue();

    Sms::refreshDelivery($message);

    $attempt = $message->attempts()->first()->fresh();

    // The delivery verdict was learned and recorded...
    expect($attempt->delivery_status)->toBe(DeliveryStatus::Failed)
        ->and($attempt->provider_delivery_status)->toBe('3')
        ->and($message->fresh()->delivery_status)->toBe(DeliveryStatus::Failed)
        // ⚠️ ...and no free-form provider text came with it, because this message is
        // sensitive. The structured tokens are identifiers, not content.
        ->and($attempt->delivery_error)->toBeNull();

    // And the code appears nowhere in the database at all.
    $rows = json_encode([
        DB::table(TableNames::messages())->where('id', $message->getKey())->first(),
        DB::table(TableNames::attempts())->where('id', $attempt->getKey())->first(),
    ]);

    expect($rows)->not->toContain('482193')
        ->not->toContain('Your login code is');

    // The code still verifies: none of this touched the challenge.
    expect(Otp::verify('09121234567', '482193', 'login-otp'))->toBeTrue();
});

it('keeps the delivery reason for an ordinary message', function () {
    // The stricter rule is for sensitive messages only. Removing the reason from
    // every delivery failure would destroy the diagnostic the column exists for.
    test()->configureGateway(driver: 'ippanel', mode: DeliveryMode::Text, body: 'Hello {customer_name}');

    Http::fake([
        IPPANEL_SEND => Http::response([
            'data' => ['message_outbox_ids' => [5544778899]],
            'meta' => ['status' => true, 'message_code' => '200-1'],
        ]),
        IPPANEL_REPORT => Http::response(recipientReport([recipientRow('+989121234567', '4', 'Hello Amid')])),
    ]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    Sms::refreshDelivery($message);

    $attempt = $message->attempts()->first()->fresh();

    expect($attempt->delivery_status)->toBe(DeliveryStatus::Failed)
        ->and($attempt->delivery_error)->toContain('blacklisted')
        // Our words and the provider's code — never the provider's copy of the body.
        ->and($attempt->delivery_error)->not->toContain('Hello Amid');
});
