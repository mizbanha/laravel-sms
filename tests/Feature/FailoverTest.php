<?php

declare(strict_types=1);

use Amid\Sms\Enums\DeliveryMode;
use Amid\Sms\Enums\FailureKind;
use Amid\Sms\Enums\MessageStatus;
use Amid\Sms\Enums\SendOutcome;
use Amid\Sms\Facades\Sms;
use Amid\Sms\Models\SmsGateway;
use Amid\Sms\Models\SmsTemplate;
use Amid\Sms\Models\SmsTemplateGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Failover: one logical message, several gateways, and the rules about when it is
 * allowed to move on.
 *
 * The whole point is the asymmetry. Moving on after a KNOWN refusal costs nothing;
 * moving on after an UNCERTAIN one can deliver the same message twice to a real
 * person, and no test here is more important than the ones that refuse to move.
 */

/**
 * Bind one template to several gateways, in the order given, priority ascending.
 *
 * @param  list<array{0: string, 1: string}>  $gateways  [driver, key] pairs
 */
function chain(array $gateways, DeliveryMode $mode = DeliveryMode::Text): SmsTemplate
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
            'sender' => '30001234',
            // Every provider's credentials at once, so a chain can mix any of them
            // without the helper knowing which driver wants what.
            'credentials' => [
                'api_key' => 'kavenegar-key',
                'username' => 'meli-user',
                'password' => 'meli-pass',
                'account_sid' => 'ACtest0000000000000000000000000001',
                'auth_token' => 'twilio-secret-token',
            ],
            'is_enabled' => true,
            'priority' => ($index + 1) * 10,
        ])->save();

        SmsTemplateGateway::query()->create([
            'sms_template_id' => $template->getKey(),
            'sms_gateway_id' => $gateway->getKey(),
            'mode' => $mode,
            'pattern_code' => $mode === DeliveryMode::Pattern ? 'code-'.$key : null,
            'is_enabled' => true,
        ]);
    }

    return $template;
}

function sendChained()
{
    return Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();
}

it('moves to the next gateway after a failover-safe refusal and succeeds there', function () {
    chain([['smsir', 'first'], ['kavenegar', 'second']]);

    Http::fake([
        // 401 is failover-safe: the credentials are this account's problem, the
        // message is fine.
        'api.sms.ir/*' => Http::response(['message' => 'unauthorized'], 401),
        'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 77]]]),
    ]);

    $message = sendChained();
    $attempts = $message->attempts()->orderBy('sequence')->get();

    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and($attempts)->toHaveCount(2)
        // Both handovers are on the record, in order, with the losing one kept.
        ->and($attempts[0]->gateway_key)->toBe('first')
        ->and($attempts[0]->sequence)->toBe(1)
        ->and($attempts[0]->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempts[0]->failure_kind)->toBe(FailureKind::GatewayConfiguration)
        ->and($attempts[0]->safe_to_failover)->toBeTrue()
        ->and($attempts[1]->gateway_key)->toBe('second')
        ->and($attempts[1]->sequence)->toBe(2)
        ->and($attempts[1]->driver)->toBe('kavenegar')
        ->and($attempts[1]->outcome)->toBe(SendOutcome::Accepted)
        ->and($attempts[1]->provider_message_id)->toBe('77');
});

it('tries gateways in configured priority order', function () {
    chain([['smsir', 'cheap'], ['kavenegar', 'expensive']]);

    // Reverse the priorities: the second gateway should now be tried first.
    SmsGateway::query()->where('key', 'cheap')->update(['priority' => 90]);
    SmsGateway::query()->where('key', 'expensive')->update(['priority' => 10]);

    Http::fake([
        'api.kavenegar.com/*' => Http::response([], 401),
        'api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageIds' => [5]]]),
    ]);

    $attempts = sendChained()->attempts()->orderBy('sequence')->get();

    expect($attempts[0]->gateway_key)->toBe('expensive')
        ->and($attempts[1]->gateway_key)->toBe('cheap');
});

it('stops and fails when a refusal is not safe to fail over', function () {
    // An unexplained provider refusal. The next gateway would very likely repeat
    // it, and a loop of identical refusals helps nobody.
    chain([['smsir', 'first'], ['kavenegar', 'second']]);

    Http::fake([
        'api.sms.ir/*' => Http::response(['status' => 0, 'message' => 'refused']),
        'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 1]]]),
    ]);

    $message = sendChained();

    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->attempts)->toHaveCount(1);

    // The second gateway was never contacted.
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'kavenegar'));
});

it('stops immediately on an uncertain result and never tries another gateway', function () {
    /*
     * The most important test in the package.
     *
     * The first gateway may already have the message. Handing it to a second one
     * now is exactly how one person receives the same SMS twice, and no number of
     * healthy remaining gateways makes that acceptable.
     */
    chain([['smsir', 'first'], ['kavenegar', 'second']]);

    Http::fake([
        'api.sms.ir/*' => Http::response('upstream exploded', 500),
        'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 1]]]),
    ]);

    $message = sendChained();

    expect($message->status)->toBe(MessageStatus::Unknown)
        ->and($message->attempts)->toHaveCount(1)
        ->and($message->attempts()->first()->outcome)->toBe(SendOutcome::Uncertain);

    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'kavenegar'));
});

it('stops on an uncertain transport failure just as firmly', function () {
    chain([['smsir', 'first'], ['kavenegar', 'second']]);

    Http::fake([
        'api.sms.ir/*' => fn () => throw new ConnectionException('timed out'),
        'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 1]]]),
    ]);

    $message = sendChained();

    // Asserted on the attempt history rather than on recorded requests: a fake that
    // throws never records one, so the history is the honest evidence that the
    // second gateway was never reached.
    expect($message->status)->toBe(MessageStatus::Unknown)
        ->and($message->attempts)->toHaveCount(1)
        ->and($message->attempts()->first()->gateway_key)->toBe('first')
        ->and($message->attempts()->first()->outcome)->toBe(SendOutcome::Uncertain);
});

it('fails a synchronous send once every eligible gateway has safely refused', function () {
    // No future attempt exists for a synchronous send, so it must settle rather
    // than leave an invisible promise that something will pick it up.
    chain([['smsir', 'first'], ['kavenegar', 'second']]);

    Http::fake(['*' => Http::response([], 401)]);

    $message = sendChained();

    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->attempts)->toHaveCount(2);
    Http::assertSentCount(2);
});

it('fails over across three genuinely different providers', function () {
    // The orchestrator does not know or care which providers these are: three
    // different request shapes, three different response shapes, one contract.
    chain([['smsir', 'a'], ['ippanel', 'b'], ['melipayamak', 'c']]);

    Http::fake([
        'api.sms.ir/*' => Http::response([], 401),
        'edge.ippanel.com/*' => Http::response([], 403),
        'rest.payamak-panel.com/*' => Http::response(['Value' => '5544332211', 'RetStatus' => 1]),
    ]);

    $message = sendChained();
    $attempts = $message->attempts()->orderBy('sequence')->get();

    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and($attempts->pluck('driver')->all())->toBe(['smsir', 'ippanel', 'melipayamak'])
        ->and($attempts->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and($attempts[2]->provider_message_id)->toBe('5544332211');
});

it('does not consider a gateway whose binding is disabled', function () {
    chain([['smsir', 'first'], ['kavenegar', 'second']]);

    SmsTemplateGateway::query()
        ->whereIn('sms_gateway_id', SmsGateway::query()->where('key', 'second')->select('id'))
        ->update(['is_enabled' => false]);

    Http::fake(['*' => Http::response([], 401)]);

    $message = sendChained();

    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->attempts)->toHaveCount(1);
});

it('records the full attempt history with flags for every handover', function () {
    chain([['smsir', 'first'], ['kavenegar', 'second']]);

    Http::fake([
        'api.sms.ir/*' => Http::response(['message' => 'slow down'], 429),
        'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 42]]]),
    ]);

    $attempts = sendChained()->attempts()->orderBy('sequence')->get();

    expect($attempts[0]->gateway_key)->toBe('first')
        ->and($attempts[0]->driver)->toBe('smsir')
        ->and($attempts[0]->mode)->toBe(DeliveryMode::Text)
        ->and($attempts[0]->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempts[0]->failure_kind)->toBe(FailureKind::ProviderUnavailable)
        ->and($attempts[0]->retryable_on_same_gateway)->toBeTrue()
        ->and($attempts[0]->safe_to_failover)->toBeTrue()
        ->and($attempts[0]->provider_message_id)->toBeNull()
        ->and($attempts[1]->outcome)->toBe(SendOutcome::Accepted)
        ->and($attempts[1]->failure_kind)->toBeNull()
        ->and($attempts[1]->provider_message_id)->toBe('42');
});

it('keeps each gateway to one call per run', function () {
    // A retryable refusal is not retried inside the loop: that is a decision for
    // the next run, taken with a delay, not something to hammer out immediately.
    chain([['smsir', 'only']]);

    Http::fake(['*' => Http::response(['message' => 'slow down'], 429)]);

    $message = sendChained();

    expect($message->attempts)->toHaveCount(1);
    Http::assertSentCount(1);
});

it('fails over when one provider cannot encode a value that another can', function () {
    /*
     * A refusal that is about the wire format rather than the message.
     *
     * Melipayamak joins pattern values into one delimited string, so a value
     * containing the delimiter is unsendable there — and perfectly sendable at
     * IPPanel, which passes parameters as discrete fields. This is the case the
     * safe/unsafe distinction exists for: nothing was sent, nothing is ambiguous,
     * and the next gateway is not going to repeat the refusal.
     */
    chain([['melipayamak', 'delimited'], ['ippanel', 'structured']], DeliveryMode::Pattern);

    Http::fake([
        'rest.payamak-panel.com/*' => Http::response(['Value' => '1234567890', 'RetStatus' => 1]),
        'edge.ippanel.com/*' => Http::response([
            'data' => ['message_outbox_ids' => [61]],
            'meta' => ['status' => true, 'message_code' => '200-1'],
        ]),
    ]);

    $message = Sms::to('09121234567')->template('order-created')
        ->with(['customer_name' => 'Amid;Esfahani'])->send();

    $attempts = $message->attempts()->orderBy('sequence')->get();

    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and($attempts)->toHaveCount(2)
        ->and($attempts[0]->gateway_key)->toBe('delimited')
        ->and($attempts[0]->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempts[0]->safe_to_failover)->toBeTrue()
        ->and($attempts[1]->gateway_key)->toBe('structured')
        ->and($attempts[1]->outcome)->toBe(SendOutcome::Accepted)
        ->and($attempts[1]->provider_message_id)->toBe('61');

    // The first provider was never contacted, and the second got the value whole.
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'payamak-panel'));
    Http::assertSent(fn ($request): bool => $request['params'] === ['customer_name' => 'Amid;Esfahani']);
});

/*
 * Provider neutrality: Iranian and international gateways in one chain.
 *
 * The orchestrator has no idea that one of these providers numbers its parameters,
 * one wants a national number and one wants E.164 with a plus, or that one of them
 * cannot deliver to Iran at all. It reads an outcome and two flags. If any of the
 * tests below had needed a change to MessageDispatcher, the abstraction would have
 * failed — none of them did.
 */

it('fails over from an Iranian gateway to Twilio', function () {
    chain([['smsir', 'iranian'], ['twilio', 'international']]);

    Http::fake([
        // The Iranian gateway's credentials are that account's problem; the message
        // itself is fine.
        'api.sms.ir/*' => Http::response([], 401),
        'api.twilio.com/*' => Http::response(['sid' => 'SM1234567890abcdef', 'status' => 'queued'], 201),
    ]);

    $message = Sms::to('+14155552671')->template('order-created')->with(['customer_name' => 'Amid'])->send();
    $attempts = $message->attempts()->orderBy('sequence')->get();

    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and($attempts->pluck('driver')->all())->toBe(['smsir', 'twilio'])
        ->and($attempts[1]->provider_message_id)->toBe('SM1234567890abcdef');
});

it('fails over from Twilio to an Iranian gateway for an Iranian destination', function () {
    /*
     * The case this driver was added for, and the one that justifies keeping both
     * kinds of provider in one chain.
     *
     * Twilio does not deliver to Iran — its own documentation for 21408 says not to
     * retry expecting Geo Permissions to fix it. That is a fact about this gateway's
     * account and region permissions, not about the message, so the chain moves on
     * and an Iranian provider carries it.
     */
    chain([['twilio', 'international'], ['kavenegar', 'iranian']]);

    Http::fake([
        'api.twilio.com/*' => Http::response([
            'code' => 21408,
            'message' => 'Permission to send an SMS has not been enabled for the region indicated by the To number',
        ], 400),
        'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 55]]]),
    ]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();
    $attempts = $message->attempts()->orderBy('sequence')->get();

    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and($attempts->pluck('driver')->all())->toBe(['twilio', 'kavenegar'])
        ->and($attempts[0]->failure_kind)->toBe(FailureKind::GatewayConfiguration)
        ->and($attempts[0]->safe_to_failover)->toBeTrue()
        ->and($attempts[1]->provider_message_id)->toBe('55');
});

it('never routes around an opt-out, however many gateways remain', function () {
    /*
     * ⚠️ Consent, and the single most important test in this file.
     *
     * Twilio 21610 means the recipient replied STOP. Every remaining gateway here is
     * healthy and would happily deliver. Failing over would use a reliability
     * mechanism to ignore somebody who asked not to be contacted, one provider at a
     * time — so the chain stops dead, with two working gateways untouched.
     */
    chain([['twilio', 'international'], ['smsir', 'backup-one'], ['kavenegar', 'backup-two']]);

    Http::fake([
        'api.twilio.com/*' => Http::response(['code' => 21610, 'message' => 'Attempt to send to unsubscribed recipient'], 400),
        'api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageIds' => [1]]]),
        'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 1]]]),
    ]);

    $message = Sms::to('+14155552671')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->attempts)->toHaveCount(1)
        ->and($message->attempts()->first()->safe_to_failover)->toBeFalse();

    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'sms.ir'));
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'kavenegar'));
});

it('never routes around a destination Twilio proved invalid', function () {
    chain([['twilio', 'international'], ['smsir', 'backup']]);

    Http::fake([
        'api.twilio.com/*' => Http::response(['code' => 21211, 'message' => 'The To number is not a valid phone number'], 400),
        'api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageIds' => [1]]]),
    ]);

    $message = Sms::to('+14155552671')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->attempts)->toHaveCount(1);
    Http::assertSentCount(1);
});

it('never fails over from Twilio after an uncertain answer', function () {
    // Twilio may already have the message. Unchanged for the international driver:
    // the rule is about duplicates, not about which provider it is.
    chain([['twilio', 'international'], ['smsir', 'backup']]);

    Http::fake([
        'api.twilio.com/*' => Http::response(['code' => 20500, 'message' => 'Internal error'], 500),
        'api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageIds' => [1]]]),
    ]);

    $message = Sms::to('+14155552671')->template('order-created')->with(['customer_name' => 'Amid'])->send();

    expect($message->status)->toBe(MessageStatus::Unknown)
        ->and($message->attempts)->toHaveCount(1);
    Http::assertSentCount(1);
});

it('fails over past a gateway whose credentials were never configured', function () {
    /*
     * A latent defect found while building the Twilio driver, kept as a regression
     * test.
     *
     * Drivers resolve credentials while BUILDING the request, so a missing one was
     * caught by the shared transport handler and recorded as an UNCERTAIN network
     * failure — which stops the chain and settles the message as `unknown`. A
     * gateway missing an API key never contacted anybody. Nothing is uncertain about
     * it, and the healthy gateway behind it should carry the message.
     */
    chain([['smsir', 'unconfigured'], ['kavenegar', 'healthy']]);

    SmsGateway::query()->where('key', 'unconfigured')->first()
        ->forceFill(['credentials' => []])->save();

    Http::fake(['api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 9]]])]);

    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();
    $attempts = $message->attempts()->orderBy('sequence')->get();

    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and($attempts[0]->outcome)->toBe(SendOutcome::Rejected)
        ->and($attempts[0]->failure_kind)->toBe(FailureKind::GatewayConfiguration)
        ->and($attempts[0]->safe_to_failover)->toBeTrue()
        // ⚠️ The credential NAME reaches the log; no value ever does.
        ->and($attempts[0]->error)->toContain('[api_key]')
        ->and($attempts[1]->provider_message_id)->toBe('9');
});
