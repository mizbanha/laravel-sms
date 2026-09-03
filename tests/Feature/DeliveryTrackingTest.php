<?php

declare(strict_types=1);

use Amid\Sms\Enums\DeliveryMode;
use Amid\Sms\Enums\DeliveryStatus;
use Amid\Sms\Facades\Sms;
use Amid\Sms\Models\SmsAttempt;
use Amid\Sms\Models\SmsGateway;
use Amid\Sms\Models\SmsTemplate;
use Amid\Sms\Models\SmsTemplateGateway;
use Amid\Sms\Support\TableNames;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Delivery status: what happened AFTER a provider accepted the message.
 *
 * ⚠️ Every test in this file is about a phase that comes after the sending
 * decision is complete and cannot be revisited. The rules that follow from that
 * are the ones worth reading twice:
 *
 *   - a delivery lookup NEVER changes a send outcome, whatever it says or fails
 *     to say;
 *   - a failure to ask is not a failure to deliver;
 *   - terminal evidence is never downgraded by a stale answer;
 *   - only the attempt that actually carried the message can speak for it.
 *
 * The provider-specific halves are in TwilioDeliveryTest and IpPanelDeliveryTest.
 * This file uses those two drivers only as the two available ways of being
 * delivery-capable, and everything it asserts is about the Core machinery.
 */
const TWILIO_SEND = 'api.twilio.com/2010-04-01/Accounts/*/Messages.json';
const TWILIO_REPORT = 'api.twilio.com/2010-04-01/Accounts/*/Messages/*.json';

/**
 * A template bound to the given [driver, key] gateways, priority ascending.
 *
 * @param  list<array{0: string, 1: string}>  $gateways
 */
function deliveryChain(array $gateways): SmsTemplate
{
    $template = SmsTemplate::query()->create([
        'key' => 'order-created',
        'name' => 'Order created',
        'body' => 'Hello {customer_name}',
    ]);

    foreach ($gateways as $index => [$driver, $key]) {
        $gateway = new SmsGateway;
        $gateway->forceFill([
            'key' => $key,
            'label' => $key,
            'driver' => $driver,
            'sender' => '+15005550006',
            'credentials' => [
                'api_key' => 'ippanel-or-smsir-key',
                'account_sid' => 'ACtest0000000000000000000000000001',
                'auth_token' => 'twilio-secret-token',
            ],
            'is_enabled' => true,
            'priority' => ($index + 1) * 10,
        ])->save();

        SmsTemplateGateway::query()->create([
            'sms_template_id' => $template->getKey(),
            'sms_gateway_id' => $gateway->getKey(),
            'mode' => DeliveryMode::Text,
            'is_enabled' => true,
        ]);
    }

    return $template;
}

function sendForDelivery(string $to = '+14155552671')
{
    return Sms::to($to)->template('order-created')->with(['customer_name' => 'Amid'])->send();
}

/**
 * One accepted Twilio message, ready to be asked about.
 *
 * ⚠️ The report answer is stubbed up front rather than in each test, because a
 * second `Http::fake()` does not replace a stub that already matches — it adds
 * one behind it, and resets the recorded requests while it is at it.
 *
 * @param  mixed  $report  what the report endpoint will answer; a decoded array,
 *                         or any faked response for a non-200 answer
 */
function accepted(mixed $report = null, string $sid = 'SMdelivery0001')
{
    deliveryChain([['twilio', 'primary']]);

    Http::fake([
        TWILIO_SEND => Http::response(['sid' => $sid, 'status' => 'queued'], 201),
        TWILIO_REPORT => is_array($report) || $report === null
            ? Http::response($report ?? ['sid' => $sid, 'status' => 'delivered'])
            : $report,
    ]);

    return sendForDelivery();
}

it('starts an accepted attempt as pending when the driver can report delivery', function () {
    /*
     * ⚠️ And without a second request. An acceptance through a delivery-capable
     * driver is `pending` by definition — the provider has it and has not said what
     * became of it — so asking immediately would spend a call to be told `queued`,
     * which the send response already said.
     */
    $message = accepted();
    $attempt = $message->attempts()->first();

    expect($attempt->delivery_status)->toBe(DeliveryStatus::Pending)
        ->and($attempt->delivery_confirmed_at)->toBeNull()
        ->and($attempt->delivery_checked_at)->toBeNull()
        // The summary is on the message from the moment of acceptance, so a
        // management screen has something to filter on immediately.
        ->and($message->delivery_status)->toBe(DeliveryStatus::Pending)
        ->and($message->status->value)->toBe('accepted');

    // Exactly one request: the send.
    Http::assertSentCount(1);
});

it('leaves delivery untracked for a driver that cannot report it', function () {
    /*
     * ⚠️ Null, not `unknown` and not a state called `unsupported`. A message sent
     * through a provider with no report API is an ordinary, successful message; the
     * columns say "nobody can tell you", which is different from "we asked and were
     * told nothing" and very different from "it failed".
     */
    deliveryChain([['smsir', 'primary']]);

    Http::fake(['*' => Http::response(['status' => 1, 'data' => ['messageIds' => [42]]])]);

    $message = sendForDelivery('09121234567');
    $attempt = $message->attempts()->first();

    expect($attempt->delivery_status)->toBeNull()
        ->and($message->delivery_status)->toBeNull()
        ->and($attempt->provider_message_id)->toBe('42')
        // And asking anyway costs nothing and changes nothing.
        ->and(Sms::refreshDelivery($message))->toBeNull()
        ->and($attempt->fresh()->delivery_checked_at)->toBeNull();
});

it('refuses to look up delivery for an attempt that was not accepted', function () {
    // A refusal has nothing to report: the provider never had the message, and
    // there is no identifier to ask about.
    deliveryChain([['twilio', 'primary']]);

    Http::fake([TWILIO_SEND => Http::response(['code' => 21211, 'message' => 'invalid number'], 400)]);

    $message = sendForDelivery();
    $attempt = $message->attempts()->first();

    expect($attempt->outcome->value)->toBe('rejected')
        ->and($attempt->delivery_status)->toBeNull()
        ->and(Sms::refreshDelivery($attempt))->toBeNull()
        ->and(Sms::refreshDelivery($message))->toBeNull();

    // Only the send was ever sent. No report request was made for a refusal.
    Http::assertSentCount(1);
});

it('refuses to look up delivery with no provider message id', function () {
    /*
     * The id is the only thing a report endpoint takes. Without one there is no
     * question to ask, and asking with an empty id would at best return an error
     * and at worst return somebody else's message.
     */
    $message = accepted();

    $message->attempts()->first()->forceFill(['provider_message_id' => null])->save();

    expect(Sms::refreshDelivery($message))->toBeNull();

    Http::assertSentCount(1);
});

it('does not ask a gateway that has since been re-pointed at another provider', function () {
    /*
     * A gateway row is runtime configuration. If somebody switches `primary` from
     * Twilio to another provider between the send and the lookup, the stored id
     * belongs to the old provider — and asking the new one about it is how a report
     * for somebody else's message ends up on this row.
     */
    $message = accepted();

    SmsGateway::query()->where('key', 'primary')->update(['driver' => 'smsir']);

    expect(Sms::refreshDelivery($message))->toBeNull();

    Http::assertSentCount(1);
});

it('leaves the send outcome untouched when the report endpoint fails', function () {
    /*
     * ⚠️ The most important test in this file.
     *
     * A report API that times out, rejects our token or is simply down has told us
     * nothing about the message. The provider accepted it — that happened, it is
     * recorded, and no failure to ask about it afterwards can un-accept it. Writing
     * `unknown` here would be recording a verdict about our own inability to ask.
     */
    $message = accepted(Http::response(['message' => 'Authenticate'], 401));

    expect(Sms::refreshDelivery($message))->toBeNull();

    $attempt = $message->attempts()->first()->fresh();

    expect($message->fresh()->status->value)->toBe('accepted')
        ->and($message->fresh()->delivery_status)->toBe(DeliveryStatus::Pending)
        ->and($attempt->delivery_status)->toBe(DeliveryStatus::Pending)
        ->and($attempt->delivery_checked_at)->toBeNull()
        // And it must not have produced a second send.
        ->and($message->fresh()->attempts()->count())->toBe(1);
});

it('records a delivered verdict on the attempt and mirrors it onto the message', function () {
    $message = accepted();

    $result = Sms::refreshDelivery($message);
    $attempt = $message->attempts()->first()->fresh();

    expect($result->status)->toBe(DeliveryStatus::Delivered)
        ->and($attempt->delivery_status)->toBe(DeliveryStatus::Delivered)
        ->and($attempt->provider_delivery_status)->toBe('delivered')
        ->and($attempt->delivery_confirmed_at)->not->toBeNull()
        ->and($attempt->delivery_checked_at)->not->toBeNull()
        // Mirrored, not re-derived: the two rows cannot disagree.
        ->and($message->fresh()->delivery_status)->toBe(DeliveryStatus::Delivered)
        ->and($message->fresh()->delivery_confirmed_at?->toDateTimeString())
        ->toBe($attempt->delivery_confirmed_at?->toDateTimeString())
        // ⚠️ And the send status is untouched. Delivery is a second axis, not a
        // correction of the first.
        ->and($message->fresh()->status->value)->toBe('accepted');
});

it('does not move the confirmation timestamp on a later delivered report', function () {
    /*
     * ⚠️ `delivery_confirmed_at` is when this package FIRST obtained a delivered
     * verdict. Re-stamping it on every poll would turn it into "when we last asked
     * about a message that had already arrived", which is what `delivery_checked_at`
     * is for - and would quietly make the two columns say the same thing.
     */
    $message = accepted();

    Sms::refreshDelivery($message);
    $first = $message->attempts()->first()->fresh()->delivery_confirmed_at;

    test()->travel(90)->minutes();
    Sms::refreshDelivery($message->fresh());

    $attempt = $message->attempts()->first()->fresh();

    expect($attempt->delivery_confirmed_at?->toDateTimeString())->toBe($first?->toDateTimeString())
        ->and($message->fresh()->delivery_confirmed_at?->toDateTimeString())->toBe($first?->toDateTimeString())
        // We did ask again, and that is recorded separately.
        ->and($attempt->delivery_checked_at?->greaterThan($first))->toBeTrue();
});

it('never downgrades a delivered verdict when a stale report arrives', function () {
    /*
     * ⚠️ Report endpoints are eventually consistent and occasionally cached, so a
     * later poll can genuinely answer with an older row. Letting that rewrite
     * `delivered` back to `pending` would make the column oscillate and would make
     * every alert built on it untrustworthy.
     */
    deliveryChain([['twilio', 'primary']]);

    Http::fake([
        TWILIO_SEND => Http::response(['sid' => 'SMstale0001', 'status' => 'queued'], 201),
        TWILIO_REPORT => Http::sequence()
            ->push(['sid' => 'SMstale0001', 'status' => 'delivered'])
            ->push(['sid' => 'SMstale0001', 'status' => 'queued']),
    ]);

    $message = sendForDelivery();

    Sms::refreshDelivery($message);
    $delivered = $message->attempts()->first()->fresh()->delivery_confirmed_at;

    Sms::refreshDelivery($message->fresh());

    $attempt = $message->attempts()->first()->fresh();

    expect($attempt->delivery_status)->toBe(DeliveryStatus::Delivered)
        ->and($attempt->provider_delivery_status)->toBe('delivered')
        ->and($attempt->delivery_confirmed_at?->toDateTimeString())->toBe($delivered?->toDateTimeString())
        ->and($message->fresh()->delivery_status)->toBe(DeliveryStatus::Delivered)
        // We still record that we asked - "when did we last check" is true either
        // way, and a poller needs it.
        ->and($attempt->delivery_checked_at)->not->toBeNull();
});

it('never overwrites a terminal failure with a stale pending report', function () {
    // The same rule in the other direction. A confirmed non-delivery is evidence,
    // and an older row saying "queued" is not newer evidence.
    deliveryChain([['twilio', 'primary']]);

    Http::fake([
        TWILIO_SEND => Http::response(['sid' => 'SMfail0001', 'status' => 'queued'], 201),
        TWILIO_REPORT => Http::sequence()
            ->push(['sid' => 'SMfail0001', 'status' => 'undelivered', 'error_code' => 30006])
            ->push(['sid' => 'SMfail0001', 'status' => 'sending']),
    ]);

    $message = sendForDelivery();

    Sms::refreshDelivery($message);
    Sms::refreshDelivery($message->fresh());

    $attempt = $message->attempts()->first()->fresh();

    expect($attempt->delivery_status)->toBe(DeliveryStatus::Failed)
        ->and($attempt->provider_delivery_status)->toBe('undelivered')
        ->and($attempt->delivery_error_code)->toBe('30006')
        ->and($attempt->delivery_confirmed_at)->toBeNull()
        ->and($message->fresh()->delivery_status)->toBe(DeliveryStatus::Failed);
});

it('persists no part of the raw delivery report', function () {
    /*
     * ⚠️ Twilio's message resource carries the body, the sender, the price and the
     * account SID. IPPanel's recipient report carries the original SMS text. A
     * stored copy of either would put message content back into the database
     * through the reporting door, which is the whole reason this milestone exists.
     */
    $message = accepted([
        'sid' => 'SMdelivery0001',
        'status' => 'delivered',
        'body' => 'Hello Amid, your order is ready',
        'from' => '+15005550006',
        'account_sid' => 'ACtest0000000000000000000000000001',
        'price' => '-0.0075',
    ]);

    Sms::refreshDelivery($message);

    $row = json_encode(DB::table(TableNames::attempts())->where('sms_message_id', $message->getKey())->first());

    expect($row)->not->toContain('your order is ready')
        ->not->toContain('-0.0075')
        ->not->toContain('twilio-secret-token')
        // What IS there is the normalised handful and nothing else.
        ->toContain('delivered');
});

it('follows the winning attempt when an earlier gateway refused', function () {
    /*
     * ⚠️ Only an accepted attempt can represent a delivered logical message. The
     * refusal has no provider id and nothing to report, and it must never be able
     * to speak for the message.
     */
    deliveryChain([['smsir', 'first'], ['twilio', 'second']]);

    Http::fake([
        // A failover-safe refusal: this account's credentials, not this message.
        'api.sms.ir/*' => Http::response(['status' => 0, 'message' => 'unauthorised'], 401),
        TWILIO_SEND => Http::response(['sid' => 'SMwinner0001', 'status' => 'queued'], 201),
        TWILIO_REPORT => Http::response(['sid' => 'SMwinner0001', 'status' => 'delivered']),
    ]);

    $message = sendForDelivery();

    expect($message->attempts()->count())->toBe(2);

    $refused = $message->attempts()->where('sequence', 1)->first();
    $carried = $message->attempts()->where('sequence', 2)->first();

    expect($refused->delivery_status)->toBeNull()
        ->and($carried->delivery_status)->toBe(DeliveryStatus::Pending)
        ->and($message->delivery_status)->toBe(DeliveryStatus::Pending);

    Sms::refreshDelivery($message);

    expect($message->fresh()->delivery_status)->toBe(DeliveryStatus::Delivered)
        // The refusal is untouched by any of it.
        ->and($refused->fresh()->delivery_status)->toBeNull()
        ->and($refused->fresh()->delivery_checked_at)->toBeNull();
});

it('lets a rejected attempt change nothing about the message summary', function () {
    // Even asked directly, by an application holding the wrong attempt.
    deliveryChain([['smsir', 'first'], ['twilio', 'second']]);

    Http::fake([
        'api.sms.ir/*' => Http::response(['status' => 0, 'message' => 'unauthorised'], 401),
        TWILIO_SEND => Http::response(['sid' => 'SMwinner0002', 'status' => 'queued'], 201),
        TWILIO_REPORT => Http::response(['sid' => 'SMwinner0002', 'status' => 'delivered']),
    ]);

    $message = sendForDelivery();
    $refused = $message->attempts()->where('sequence', 1)->first();

    expect(Sms::refreshDelivery($refused))->toBeNull()
        ->and($message->fresh()->delivery_status)->toBe(DeliveryStatus::Pending);
});

it('picks the earliest accepted attempt when history somehow holds two', function () {
    /*
     * The orchestrator stops at the first acceptance, so a second accepted attempt
     * means data written by something other than this pipeline. Rather than guess
     * which is real, the rule is the one the orchestrator would itself have
     * produced — and, above all, it is deterministic.
     */
    $message = accepted();

    SmsAttempt::query()->create([
        'sms_message_id' => $message->getKey(),
        'sms_gateway_id' => $message->attempts()->first()->sms_gateway_id,
        'gateway_key' => 'primary',
        'driver' => 'twilio',
        'sequence' => 2,
        'mode' => DeliveryMode::Text,
        'outcome' => \Amid\Sms\Enums\SendOutcome::Accepted,
        'provider_message_id' => 'SMimpossible0002',
    ]);

    Sms::refreshDelivery($message);

    // The first acceptance is the one that was asked about.
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'SMdelivery0001'));

    expect($message->fresh()->delivery_status)->toBe(DeliveryStatus::Delivered)
        ->and($message->attempts()->where('sequence', 2)->first()->delivery_status)->toBeNull();
});

it('does not contact a provider when delivery status is merely read', function () {
    /*
     * ⚠️ A property that quietly makes an HTTP request turns an admin table of
     * fifty rows into fifty provider calls, from inside a Blade template where
     * nobody thinks to look for it. Reading is reading.
     */
    $message = accepted();

    expect($message->fresh()->delivery_status)->toBe(DeliveryStatus::Pending)
        ->and($message->attempts()->first()->delivery_status)->toBe(DeliveryStatus::Pending);

    Http::assertSentCount(1);
});
