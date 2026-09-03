<?php

declare(strict_types=1);

use Amid\Sms\Enums\CountryPolicy;
use Amid\Sms\Enums\DeliveryMode;
use Amid\Sms\Enums\FailureKind;
use Amid\Sms\Enums\MessageStatus;
use Amid\Sms\Enums\RoutingStrategy;
use Amid\Sms\Enums\SendOutcome;
use Amid\Sms\Exceptions\GatewayNotConfigured;
use Amid\Sms\Exceptions\SmsException;
use Amid\Sms\Facades\Sms;
use Amid\Sms\Health\CircuitBreaker;
use Amid\Sms\Models\SmsGateway;
use Amid\Sms\Models\SmsMessage;
use Amid\Sms\Models\SmsTemplate;
use Amid\Sms\Models\SmsTemplateGateway;
use Amid\Sms\Results\SendResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * `viaGateway()`: pinning one logical send to one gateway.
 *
 * The feature exists because ordinary sending deliberately cannot answer "does
 * THIS gateway work". Routing exists to choose a gateway, and every mechanism here
 * is built to move a message away from one that is failing — which is exactly
 * wrong when somebody is trying to prove a configuration they have just typed in.
 *
 * ⚠️ Three of these tests are the whole feature, and the rest are detail:
 *
 *   - a pinned send NEVER fails over, even when the gateway behind it would have
 *     succeeded, because the answer "it worked" about a gateway nobody asked about
 *     is worse than no answer at all;
 *   - a pinned send NEVER moves a routing cursor, because an operator pressing a
 *     button in an admin panel must not change which provider the next real
 *     customer's message goes to;
 *   - a pinned send NEVER bypasses an open circuit, because a test that ignores
 *     this application's own evidence reports something production would not do.
 *
 * Everything else is the ordinary pipeline, unchanged, and several tests below
 * exist only to prove that it really is unchanged.
 */

/** A template routing by the given strategy. */
function pinTemplate(RoutingStrategy $strategy = RoutingStrategy::Priority, bool $sensitive = false): SmsTemplate
{
    return SmsTemplate::query()->create([
        'key' => 'order-created',
        'name' => 'Order created',
        'body' => 'Hello {customer_name}',
        'is_sensitive' => $sensitive,
        'routing_strategy' => $strategy,
    ]);
}

/**
 * One gateway, bound to the template. Priority follows call order.
 *
 * @param  array<string, mixed>  $gateway
 * @param  array<string, mixed>  $binding
 */
function pinBind(
    SmsTemplate $template,
    string $key,
    string $driver = 'log',
    array $gateway = [],
    ?array $binding = [],
): SmsGateway {
    $row = new SmsGateway;
    $row->forceFill([
        'key' => $key,
        'label' => $key,
        'driver' => $driver,
        'sender' => '30001234',
        // Every provider's credentials at once, so a fleet can mix drivers without
        // this helper knowing which one wants what.
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

    if ($binding !== null) {
        SmsTemplateGateway::query()->create([
            'sms_template_id' => $template->getKey(),
            'sms_gateway_id' => $row->getKey(),
            'mode' => DeliveryMode::Text,
            'is_enabled' => true,
            ...$binding,
        ]);
    }

    return $row;
}

/** An ordinary send, choosing its own gateway. */
function pinSendOrdinary(): SmsMessage
{
    return Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();
}

/** A send pinned to one gateway, by model or by key. */
function pinSend(SmsGateway|string $gateway, string $to = '09121234567'): SmsMessage
{
    return Sms::to($to)
        ->template('order-created')
        ->with(['customer_name' => 'Amid'])
        ->viaGateway($gateway)
        ->send();
}

/** The gateway keys this message was actually handed to, in order. */
function pinAttemptKeys(SmsMessage $message): array
{
    return $message->attempts()->orderBy('sequence')->pluck('gateway_key')->all();
}

/** Take a gateway's circuit out of service the way the pipeline would. */
function pinOpenCircuit(SmsGateway $gateway): void
{
    foreach (range(1, 3) as $ignored) {
        app(CircuitBreaker::class)->record(
            $gateway,
            SendResult::uncertain(FailureKind::Network, 'unreachable'),
        );
    }
}

// ---------------------------------------------------------------------------
// The gateway is genuinely the one used
// ---------------------------------------------------------------------------

it('sends through the pinned gateway even when another has better priority', function () {
    $template = pinTemplate();
    pinBind($template, 'preferred');           // priority 10 — an ordinary send starts here
    $pinned = pinBind($template, 'secondary'); // priority 20

    $message = pinSend($pinned);

    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and(pinAttemptKeys($message))->toBe(['secondary'])
        // ⚠️ Intent is recorded as the gateway the operator named, not as whatever
        // ordinary routing would have chosen.
        ->and((int) $message->routing_gateway_id)->toBe((int) $pinned->getKey());
});

it('accepts a gateway key as well as a model', function () {
    $template = pinTemplate();
    pinBind($template, 'preferred');
    pinBind($template, 'secondary');

    expect(pinAttemptKeys(pinSend('secondary')))->toBe(['secondary']);
});

// ---------------------------------------------------------------------------
// ⚠️ It never fails over
// ---------------------------------------------------------------------------

it('does not fail over when the pinned gateway refuses, even though the next one would have worked', function () {
    $template = pinTemplate();
    $pinned = pinBind($template, 'first', 'smsir');
    pinBind($template, 'second', 'kavenegar');

    Http::fake([
        // 401 is failover-safe: an ordinary send WOULD move on and succeed at
        // Kavenegar. That is precisely what must not happen here.
        'api.sms.ir/*' => Http::response(['message' => 'unauthorized'], 401),
        'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 77]]]),
    ]);

    $message = pinSend($pinned);
    $attempts = $message->attempts()->orderBy('sequence')->get();

    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($attempts)->toHaveCount(1)
        ->and($attempts[0]->gateway_key)->toBe('first')
        ->and($attempts[0]->outcome)->toBe(SendOutcome::Rejected)
        // The refusal is still classified as failover-safe — the finding about the
        // provider is unchanged. What changed is that there was nowhere to go.
        ->and($attempts[0]->safe_to_failover)->toBeTrue()
        ->and($message->attempts()->where('gateway_key', 'second')->count())->toBe(0);
});

it('proves the same chain does fail over when it is not pinned', function () {
    $template = pinTemplate();
    pinBind($template, 'first', 'smsir');
    pinBind($template, 'second', 'kavenegar');

    Http::fake([
        'api.sms.ir/*' => Http::response(['message' => 'unauthorized'], 401),
        'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 77]]]),
    ]);

    // The control for the test above: without the pin this configuration reaches
    // the second gateway and succeeds, so the single attempt above is the pin's
    // doing and not an accident of the fixture.
    $message = pinSendOrdinary();

    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and(pinAttemptKeys($message))->toBe(['first', 'second']);
});

it('records an uncertain pinned result as unknown and contacts nobody else', function () {
    $template = pinTemplate();
    $pinned = pinBind($template, 'first', 'smsir');
    pinBind($template, 'second', 'kavenegar');

    Http::fake([
        'api.sms.ir/*' => Http::response([], 503),
        'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 77]]]),
    ]);

    $message = pinSend($pinned);

    expect($message->status)->toBe(MessageStatus::Unknown)
        ->and(pinAttemptKeys($message))->toBe(['first']);
});

// ---------------------------------------------------------------------------
// ⚠️ It never advances routing state
// ---------------------------------------------------------------------------

it('does not advance a round-robin cursor, so production distribution is untouched', function () {
    $template = pinTemplate(RoutingStrategy::RoundRobin);
    pinBind($template, 'a');
    $b = pinBind($template, 'b');
    pinBind($template, 'c');

    // One ordinary message: the rotation is now sitting after `a`.
    expect(pinAttemptKeys(pinSendOrdinary()))->toBe(['a']);

    // Three pinned sends at the far end of the rotation. If any of them advanced
    // the cursor, the next ordinary message would skip past `b`.
    pinSend('c');
    pinSend('c');
    pinSend('c');

    expect(pinAttemptKeys(pinSendOrdinary()))->toBe(['b'])
        ->and(pinAttemptKeys(pinSendOrdinary()))->toBe(['c'])
        ->and(pinAttemptKeys(pinSendOrdinary()))->toBe(['a']);
});

it('does not advance a weighted round-robin cursor either', function () {
    $template = pinTemplate(RoutingStrategy::WeightedRoundRobin);
    pinBind($template, 'heavy', binding: ['weight' => 3]);
    pinBind($template, 'light', binding: ['weight' => 1]);

    // The deterministic cycle is heavy, heavy, heavy, light.
    expect(pinAttemptKeys(pinSendOrdinary()))->toBe(['heavy'])
        ->and(pinAttemptKeys(pinSendOrdinary()))->toBe(['heavy']);

    pinSend('light');
    pinSend('light');

    // Still exactly where the two ordinary messages left it.
    expect(pinAttemptKeys(pinSendOrdinary()))->toBe(['heavy'])
        ->and(pinAttemptKeys(pinSendOrdinary()))->toBe(['light'])
        ->and(pinAttemptKeys(pinSendOrdinary()))->toBe(['heavy']);
});

it('leaves no routing cursor behind when only pinned sends have ever run', function () {
    $template = pinTemplate(RoutingStrategy::RoundRobin);
    pinBind($template, 'a');
    pinBind($template, 'b');

    pinSend('b');
    pinSend('b');
    pinSend('b');

    // The first ordinary message still starts at the top of the configured order,
    // which is what an untouched cursor means.
    expect(pinAttemptKeys(pinSendOrdinary()))->toBe(['a']);
});

// ---------------------------------------------------------------------------
// ⚠️ It never bypasses a circuit, and never bypasses eligibility
// ---------------------------------------------------------------------------

it('refuses to send through a pinned gateway whose circuit is open', function () {
    $template = pinTemplate();
    $pinned = pinBind($template, 'first', 'smsir');

    pinOpenCircuit($pinned);

    Http::fake(['api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageIds' => [5]]])]);

    $message = pinSend($pinned);

    expect($message->status)->toBe(MessageStatus::Failed)
        // Nothing was called: an open circuit is a decision on this side of the
        // wire and creates no evidence about a provider.
        ->and($message->attempts()->count())->toBe(0)
        ->and($message->error)->toContain('circuit is open');

    Http::assertNothingSent();
});

it('sends through a pinned gateway once its circuit has been reset', function () {
    $template = pinTemplate();
    $pinned = pinBind($template, 'first', 'smsir');

    pinOpenCircuit($pinned);
    app(CircuitBreaker::class)->reset($pinned);

    Http::fake(['api.sms.ir/*' => Http::response(['status' => 1, 'data' => ['messageIds' => [5]]])]);

    expect(pinSend($pinned)->status)->toBe(MessageStatus::Accepted);
});

it('refuses a pinned gateway that is disabled', function () {
    $template = pinTemplate();
    pinBind($template, 'live');
    $off = pinBind($template, 'off', gateway: ['is_enabled' => false]);

    $message = pinSend($off);

    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->attempts()->count())->toBe(0)
        ->and($message->error)->toBe('The gateway this message was pinned to cannot carry it.');
});

it('refuses a pinned gateway with no binding to this template', function () {
    $template = pinTemplate();
    pinBind($template, 'bound');
    $unbound = pinBind($template, 'unbound', binding: null);

    $message = pinSend($unbound);

    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->attempts()->count())->toBe(0);
});

it('refuses a pinned gateway whose binding is disabled', function () {
    $template = pinTemplate();
    $gateway = pinBind($template, 'only', binding: ['is_enabled' => false]);

    expect(pinSend($gateway)->attempts()->count())->toBe(0);
});

it('refuses a pinned gateway that does not serve the destination country', function () {
    $template = pinTemplate();
    $gateway = pinBind($template, 'iran-only', gateway: [
        'country_policy' => CountryPolicy::Allow,
        'countries' => ['IR'],
    ]);

    // An Emirati destination: eligible nowhere on this Iran-only gateway.
    $message = pinSend($gateway, '+971501234567');

    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->attempts()->count())->toBe(0);
});

it('refuses a pinned gateway whose driver cannot carry the binding mode', function () {
    $template = pinTemplate();
    // Twilio declares Text and DeliveryReport, never Pattern.
    $gateway = pinBind($template, 'twilio-line', 'twilio', binding: [
        'mode' => DeliveryMode::Pattern,
        'pattern_code' => 'tpl-1',
    ]);

    expect(pinSend($gateway)->attempts()->count())->toBe(0);
});

it('refuses a pattern binding with no pattern code, exactly as ordinary routing does', function () {
    $template = pinTemplate();
    $gateway = pinBind($template, 'kave', 'kavenegar', binding: [
        'mode' => DeliveryMode::Pattern,
        'pattern_code' => null,
    ]);

    expect(pinSend($gateway)->attempts()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Everything else about the pipeline is unchanged
// ---------------------------------------------------------------------------

it('is suppressed by the master switch like any other send', function () {
    config()->set('laravel-sms.enabled', false);

    $template = pinTemplate();
    $gateway = pinBind($template, 'only');

    $message = pinSend($gateway);

    expect($message->status)->toBe(MessageStatus::Suppressed)
        ->and($message->attempts()->count())->toBe(0);
});

it('normalises the recipient and records the destination country', function () {
    $template = pinTemplate();
    $gateway = pinBind($template, 'only');

    $message = pinSend($gateway, '۰۹۱۲۱۲۳۴۵۶۷');

    expect($message->to)->toBe('+989121234567')
        ->and($message->country_code)->toBe('IR');
});

it('keeps the sensitive-message policy on a pinned send', function () {
    $template = pinTemplate(sensitive: true);
    $gateway = pinBind($template, 'only');

    $message = pinSend($gateway);

    expect($message->is_sensitive)->toBeTrue()
        ->and($message->body)->toBeNull()
        ->and($message->variables)->toBeNull()
        ->and($message->attempts()->first()->error)->toBeNull()
        ->and($message->attempts()->first()->provider_payload)->toBeNull();
});

it('maps pattern parameters on a pinned send', function () {
    $template = pinTemplate();
    $gateway = pinBind($template, 'kave', 'kavenegar', binding: [
        'mode' => DeliveryMode::Pattern,
        'pattern_code' => 'welcome',
        'parameter_map' => [['provider' => 'token', 'variable' => 'customer_name']],
    ]);

    Http::fake(['api.kavenegar.com/*' => Http::response([
        'return' => ['status' => 200],
        'entries' => [['messageid' => 42]],
    ])]);

    $message = pinSend($gateway);
    $attempt = $message->attempts()->first();

    expect($message->status)->toBe(MessageStatus::Accepted)
        ->and($attempt->mode)->toBe(DeliveryMode::Pattern)
        ->and($attempt->pattern_code)->toBe('welcome')
        ->and($attempt->provider_message_id)->toBe('42');
});

// ---------------------------------------------------------------------------
// What the caller is refused
// ---------------------------------------------------------------------------

it('refuses to queue a pinned send', function () {
    Queue::fake();

    $template = pinTemplate();
    $gateway = pinBind($template, 'only');

    expect(fn () => Sms::to('09121234567')
        ->template('order-created')
        ->with(['customer_name' => 'Amid'])
        ->viaGateway($gateway)
        ->queue())
        ->toThrow(SmsException::class, 'synchronous only');

    // ⚠️ Refused BEFORE anything is written: a pinned queue() must not leave a
    // message row behind that nobody will ever send.
    expect(SmsMessage::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('throws when the pinned gateway key names nothing', function () {
    pinTemplate();

    expect(fn () => pinSend('does-not-exist'))
        ->toThrow(GatewayNotConfigured::class, 'does not exist');
});

it('throws when pinned to a gateway model that was never saved', function () {
    pinTemplate();

    expect(fn () => pinSend(new SmsGateway))
        ->toThrow(GatewayNotConfigured::class, 'exists in the database');
});
