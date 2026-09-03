<?php

declare(strict_types=1);

use Amid\Sms\Enums\DeliveryMode;
use Amid\Sms\Enums\FailureKind;
use Amid\Sms\Enums\MessageStatus;
use Amid\Sms\Enums\RoutingStrategy;
use Amid\Sms\Enums\SendOutcome;
use Amid\Sms\Facades\Sms;
use Amid\Sms\Health\CircuitBreaker;
use Amid\Sms\Jobs\SendSmsMessage;
use Amid\Sms\Models\SmsGateway;
use Amid\Sms\Models\SmsMessage;
use Amid\Sms\Models\SmsTemplate;
use Amid\Sms\Models\SmsTemplateGateway;
use Amid\Sms\Results\SendResult;
use Amid\Sms\Sending\MessageDispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Round-robin: where each NEW logical message starts.
 *
 * ⚠️ The two things this file is really guarding are easy to lose sight of behind
 * the distribution arithmetic.
 *
 * The first is that a strategy decides an ORDER and nothing else. Every rule about
 * when a message may move on to the next gateway is still decided from what a
 * provider actually said, and the test that proves an uncertain result stops the
 * chain matters more than every counting test here put together: rotating the
 * candidate list must never become a reason to hand the same message to a second
 * provider.
 *
 * The second is that a slot must only ever be spent on traffic that was really
 * meant to go out. A gateway that is disabled, unbound, incapable, wrong for the
 * destination's country or circuit-open receives no share - it would be a share of
 * the traffic that silently goes nowhere.
 */

/** A template that routes by the given strategy. */
function rrTemplate(
    RoutingStrategy $strategy = RoutingStrategy::RoundRobin,
    bool $sensitive = false,
    string $key = 'order-created',
): SmsTemplate {
    return SmsTemplate::query()->create([
        'key' => $key,
        'name' => 'Order created',
        'body' => 'Hello {customer_name}',
        'is_sensitive' => $sensitive,
        'routing_strategy' => $strategy,
    ]);
}

/**
 * One gateway, bound to the template.
 *
 * Priority follows the order of the calls, so `a`, `b`, `c` is also the configured
 * priority order - the order the package used before strategies existed, and the
 * one every rotation here is measured against.
 */
function rrBind(
    SmsTemplate $template,
    string $key,
    string $driver = 'log',
    array $gateway = [],
    array $binding = [],
): SmsGateway {
    $row = new SmsGateway;
    $row->forceFill([
        'key' => $key,
        'label' => $key,
        'driver' => $driver,
        'sender' => '30001234',
        // Every provider's credentials at once, so a fleet can mix any of them
        // without this helper knowing which driver wants what.
        'credentials' => [
            'api_key' => 'a-gateway-key',
            'username' => 'meli-user',
            'password' => 'meli-pass',
            'account_sid' => 'ACtest0000000000000000000000000001',
            'auth_token' => 'twilio-secret-token',
        ],
        'is_enabled' => true,
        'priority' => (SmsGateway::query()->count() + 1) * 10,
        ...$gateway,
    ])->save();

    SmsTemplateGateway::query()->create([
        'sms_template_id' => $template->getKey(),
        'sms_gateway_id' => $row->getKey(),
        'mode' => DeliveryMode::Text,
        'is_enabled' => true,
        ...$binding,
    ]);

    return $row;
}

function rrSend(string $to = '09121234567'): SmsMessage
{
    return Sms::to($to)->template('order-created')->with(['customer_name' => 'Amid'])->send();
}

/**
 * The gateway each of the next `$count` messages was actually tried on FIRST.
 *
 * Read from the attempt rows rather than from anything the planner returned, so
 * these tests describe what the pipeline did rather than what it intended.
 *
 * @return list<string>
 */
function rrPrimaries(int $count, string $to = '09121234567'): array
{
    $primaries = [];

    foreach (range(1, $count) as $ignored) {
        $primaries[] = (string) rrSend($to)->attempts()->orderBy('sequence')->first()?->gateway_key;
    }

    return $primaries;
}

/** Take a gateway's circuit out of service the way the pipeline would. */
function rrOpenCircuit(SmsGateway $gateway): void
{
    foreach (range(1, 3) as $ignored) {
        app(CircuitBreaker::class)->record(
            $gateway,
            SendResult::uncertain(FailureKind::Network, 'unreachable'),
        );
    }
}

it('starts each new message one gateway further along, and wraps', function () {
    $template = rrTemplate();
    rrBind($template, 'a');
    rrBind($template, 'b');
    rrBind($template, 'c');

    // The whole of round-robin, stated once: four messages over three gateways.
    expect(rrPrimaries(4))->toBe(['a', 'b', 'c', 'a']);
});

it('never gives a slot to a gateway that is disabled', function () {
    $template = rrTemplate();
    rrBind($template, 'a');
    rrBind($template, 'b', gateway: ['is_enabled' => false]);
    rrBind($template, 'c');

    /*
     * ⚠️ Not "b is skipped when its turn comes" - b has no turn. Eligibility is
     * settled before distribution, so the cycle is two gateways long, and a
     * disabled gateway costs nobody a message.
     */
    expect(rrPrimaries(4))->toBe(['a', 'c', 'a', 'c']);
});

it('never gives a slot to a gateway whose binding is disabled', function () {
    $template = rrTemplate();
    rrBind($template, 'a');
    rrBind($template, 'b', binding: ['is_enabled' => false]);
    rrBind($template, 'c');

    // The gateway is perfectly healthy and carries other messages; it just does
    // not carry THIS one.
    expect(rrPrimaries(4))->toBe(['a', 'c', 'a', 'c']);
});

it('never gives a slot to a gateway that cannot serve the destination country', function () {
    $template = rrTemplate();
    rrBind($template, 'a');
    rrBind($template, 'b', gateway: ['country_policy' => 'deny', 'countries' => ['IR']]);
    rrBind($template, 'c');

    expect(rrPrimaries(4))->toBe(['a', 'c', 'a', 'c']);
});

it('never gives a slot to a gateway whose driver lacks the capability', function () {
    $template = rrTemplate();
    rrBind($template, 'a');
    // Twilio implements text and delivery reports, not registered patterns. The
    // binding asks for one, so the router drops it - and it is dropped before
    // distribution, not after.
    rrBind($template, 'b', driver: 'twilio', binding: [
        'mode' => DeliveryMode::Pattern,
        'pattern_code' => 'order-created',
    ]);
    rrBind($template, 'c');

    expect(rrPrimaries(4))->toBe(['a', 'c', 'a', 'c']);
});

it('does not give a slot to a gateway whose circuit is open', function () {
    $template = rrTemplate();
    rrBind($template, 'a');
    $b = rrBind($template, 'b');
    rrBind($template, 'c');

    rrOpenCircuit($b);

    /*
     * ⚠️ Fairness is measured among the gateways that can actually receive
     * traffic. Continuing to allocate every third message to a gateway this
     * application already knows it will not call would mean a third of the
     * distribution going nowhere - and the messages themselves would still be
     * sent, just always by whoever happened to be next in the chain.
     */
    expect(rrPrimaries(4))->toBe(['a', 'c', 'a', 'c']);
});

it('lets a recovering gateway take its probe from the ordinary rotation', function () {
    $template = rrTemplate();
    rrBind($template, 'a');
    $b = rrBind($template, 'b');

    rrOpenCircuit($b);

    // Past the cooldown: b is half-open and owed exactly one careful try.
    $this->travel(61)->seconds();

    $primaries = rrPrimaries(2);

    /*
     * ⚠️ The design constraint this test exists for: a routing strategy must not
     * be able to starve a half-open gateway of its recovery probe. A half-open
     * gateway PARTICIPATES in the distribution - excluding it, as an open one is
     * excluded, would build a system in which a gateway that has failed once can
     * never be measured again.
     */
    expect($primaries)->toBe(['a', 'b'])
        ->and(app(CircuitBreaker::class)->status($b)->state->value)->toBe('closed');
});

it('keeps the attempt sequence contiguous when a circuit-open gateway is skipped', function () {
    $template = rrTemplate();
    $a = rrBind($template, 'a', driver: 'smsir');
    rrBind($template, 'b', driver: 'kavenegar');
    rrBind($template, 'c');

    rrOpenCircuit($a);

    // b refuses in a way that is safe to move on from; c is the log driver and
    // takes everything it is offered.
    Http::fake(['api.kavenegar.com/*' => Http::response([], 401)]);

    $attempts = rrSend()->attempts()->orderBy('sequence')->get();

    /*
     * ⚠️ §17 in one assertion. A routing skip is not an attempt: `a` was chosen by
     * nothing, called by nothing, and must not appear in the one table that exists
     * to be trusted during an incident. The sequence numbers count real provider
     * calls, and they start at 1.
     */
    expect($attempts->pluck('gateway_key')->all())->toBe(['b', 'c'])
        ->and($attempts->pluck('sequence')->all())->toBe([1, 2])
        ->and(SmsMessage::query()->first()->attempts()->where('gateway_key', 'a')->count())->toBe(0);
});

it('fails over from the rotated primary into the gateway the rotation put next', function () {
    $template = rrTemplate();
    rrBind($template, 'a', driver: 'smsir');
    rrBind($template, 'b', driver: 'kavenegar');
    rrBind($template, 'c');

    Http::fake([
        'api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageIds' => [5]]]),
        // b is unauthorised: a refusal about this account, not about the message,
        // so moving on costs nothing.
        'api.kavenegar.com/*' => Http::response([], 401),
    ]);

    rrSend();

    // Message 2 starts at b, and the chain rotates around it: b -> c -> a.
    $attempts = rrSend()->attempts()->orderBy('sequence')->get();

    expect($attempts->pluck('gateway_key')->all())->toBe(['b', 'c'])
        ->and($attempts[1]->outcome)->toBe(SendOutcome::Accepted);
});

it('still refuses to fail over after an uncertain result, whatever the rotation says', function () {
    $template = rrTemplate();
    rrBind($template, 'a', driver: 'smsir');
    rrBind($template, 'b', driver: 'kavenegar');
    // c is the log driver: it contacts nobody, cannot fail, and would certainly
    // have accepted this message if the chain had reached it.
    rrBind($template, 'c');

    Http::fake([
        'api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageIds' => [5]]]),
        // A timeout: kavenegar may already have this message.
        'api.kavenegar.com/*' => fn () => throw new Illuminate\Http\Client\ConnectionException('timed out'),
    ]);

    rrSend();

    $message = rrSend();

    /*
     * ⚠️ The most important test in this file.
     *
     * Round-robin put b first and c second. c is healthy, reachable and would
     * happily take this message - and it is never contacted, because b timing out
     * means b may already have it. Rotating a candidate list is a decision about
     * ORDER; it can never become a reason to hand one person's message to a second
     * provider.
     */
    expect($message->status)->toBe(MessageStatus::Unknown)
        ->and($message->attempts()->pluck('gateway_key')->all())->toBe(['b']);
});

it('keeps a queued message on the gateway it was first routed to, whatever the cursor did meanwhile', function () {
    $template = rrTemplate();
    rrBind($template, 'a', driver: 'smsir');
    rrBind($template, 'b', driver: 'kavenegar');
    rrBind($template, 'c', driver: 'melipayamak');

    // Every gateway rate-limits us. 429 is a definitive rejection - the request was
    // not processed - which is safe to move on from AND worth trying again here
    // later, so the whole chain is walked and nothing settles.
    Http::fake(['*' => Http::response([], 429)]);

    // Switched off for this test only. It would open all three circuits partway
    // through the ten messages below, which is correct behaviour and would drown
    // the thing under test - where a RETRY starts - in health evidence.
    config()->set('laravel-sms.circuit_breaker.enabled', false);

    Queue::fake();
    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->queue();

    (new SendSmsMessage($message->getKey(), ['customer_name' => 'Amid']))->handle(app(MessageDispatcher::class));

    $message->refresh();

    expect($message->attempts()->orderBy('sequence')->pluck('gateway_key')->all())->toBe(['a', 'b', 'c'])
        ->and($message->routing_gateway_id)->not->toBeNull()
        ->and($message->isSettled())->toBeFalse();

    // Nine other messages go out in the meantime and move the shared cursor on by
    // nine. Under a naive implementation the retry below would land wherever they
    // left it.
    foreach (range(1, 9) as $ignored) {
        rrSend('0912111'.str_pad((string) $ignored, 4, '0', STR_PAD_LEFT));
    }

    (new SendSmsMessage($message->getKey(), ['customer_name' => 'Amid']))->handle(app(MessageDispatcher::class));

    /*
     * ⚠️ Round-robin distributes NEW logical messages. A job Laravel released and
     * ran again is the same message, and its routing must not be decided by other
     * people's traffic - a retry that starts somewhere unrelated makes the attempt
     * history of one message unreadable, and makes "which gateway is this message
     * on" a question with no stable answer.
     *
     * The cursor is at nine by now, so a message re-planned from scratch here
     * would start at `b`. This one starts where it started the first time.
     */
    expect($message->refresh()->attempts()->orderBy('sequence')->pluck('gateway_key')->all())
        ->toBe(['a', 'b', 'c', 'a', 'b', 'c']);
});

it('lets a gateway enabled after the first run join a queued message that is still unsettled', function () {
    $template = rrTemplate();
    rrBind($template, 'a', driver: 'smsir');
    $b = rrBind($template, 'b', driver: 'kavenegar', gateway: ['is_enabled' => false]);

    Http::fake([
        'api.sms.ir/*' => Http::response([], 429),
        'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 77]]]),
    ]);

    Queue::fake();
    $message = Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->queue();

    (new SendSmsMessage($message->getKey(), ['customer_name' => 'Amid']))->handle(app(MessageDispatcher::class));

    expect($message->refresh()->isSettled())->toBeFalse();

    // Somebody fixes the infrastructure between the two runs.
    $b->forceFill(['is_enabled' => true])->save();

    (new SendSmsMessage($message->getKey(), ['customer_name' => 'Amid']))->handle(app(MessageDispatcher::class));

    /*
     * ⚠️ Stable routing intent is not a frozen candidate set. The message keeps
     * leading with the gateway it was pointed at, and newly available
     * infrastructure still gets to rescue it - which is the M2 behaviour, and the
     * reason the snapshot records a PRIMARY rather than a whole plan.
     */
    expect($message->refresh()->status)->toBe(MessageStatus::Accepted)
        ->and($message->attempts()->orderBy('sequence')->pluck('gateway_key')->all())
        ->toBe(['a', 'a', 'b']);
});

it('carries the same one-time code to the fallback gateway a rotation chose', function () {
    $template = rrTemplate(sensitive: true, key: 'login-otp');
    rrBind($template, 'a', driver: 'smsir');
    rrBind($template, 'b', driver: 'kavenegar');

    $template->forceFill(['body' => 'Your code is {code}'])->save();

    Http::fake([
        'api.sms.ir/*' => Http::response([], 401),
        'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 9]]]),
    ]);

    $message = Sms::to('09121234567')->template('login-otp')->with(['code' => '482193'])->send();

    $sent = collect(Http::recorded())
        ->map(fn (array $pair): string => (string) $pair[0]->body())
        ->all();

    /*
     * ⚠️ Routing is transparent to a sensitive message, in both directions.
     *
     * The SAME code reaches the fallback - it is not regenerated on the way, which
     * would leave the recipient holding a code the application no longer expects -
     * and none of it is written down. Every M5 guarantee still holds through a
     * rotated chain: no body, no variables, no provider prose.
     */
    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and($sent)->toHaveCount(2)
        ->and($sent[0])->toContain('482193')
        ->and($sent[1])->toContain('482193')
        ->and($message->body)->toBeNull()
        ->and($message->variables)->toBeNull()
        ->and($message->attempts()->pluck('error')->filter()->all())->toBe([]);
});

it('advances no cursor for a message the master switch suppressed', function () {
    config()->set('laravel-sms.enabled', false);

    $template = rrTemplate();
    rrBind($template, 'a');
    rrBind($template, 'b');

    $suppressed = rrSend();

    expect($suppressed->status)->toBe(MessageStatus::Suppressed)
        ->and($suppressed->routing_gateway_id)->toBeNull()
        ->and($suppressed->attempts()->count())->toBe(0);

    config()->set('laravel-sms.enabled', true);

    /*
     * ⚠️ The distribution represents traffic we actually intended to dispatch. A
     * suppressed message contacted nobody, so the first real message is still the
     * first message of the cycle - otherwise a staging environment with the switch
     * off would be silently rotating a production cycle it is not part of.
     */
    expect(rrPrimaries(2))->toBe(['a', 'b']);
});
