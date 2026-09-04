<?php

declare(strict_types=1);

use Mizbanha\Sms\Enums\DeliveryMode;
use Mizbanha\Sms\Enums\RoutingStrategy;
use Mizbanha\Sms\Exceptions\InvalidRoutingConfiguration;
use Mizbanha\Sms\Facades\Sms;
use Mizbanha\Sms\Models\SmsGateway;
use Mizbanha\Sms\Models\SmsMessage;
use Mizbanha\Sms\Models\SmsTemplate;
use Mizbanha\Sms\Models\SmsTemplateGateway;
use Mizbanha\Sms\Support\TableNames;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Weighted round-robin: unequal shares, decided in advance.
 *
 * ⚠️ **The tests here assert exact counts, and that is the point of the feature.**
 * A weighted random draw would make every assertion below approximate - "roughly
 * five in ten, given enough messages" - and approximate is not a property anybody
 * can hold a provider contract to, reproduce from a bug report, or explain to
 * somebody looking at yesterday's traffic. Weights 5, 3 and 2 mean five, then
 * three, then two. Every cycle. In that order.
 *
 * ⚠️ And weights govern PRIMARY selection only. Once a message has been pointed
 * somewhere, failing over is failover: it follows configured priority, not the
 * shares, because "which gateway carries most of the traffic" and "which gateway do
 * I trust when the first one refuses" are two different questions an administrator
 * answers with two different settings.
 */

/** A template that routes by weight. */
function wrrTemplate(): SmsTemplate
{
    return SmsTemplate::query()->create([
        'key' => 'order-created',
        'name' => 'Order created',
        'body' => 'Hello {customer_name}',
        'routing_strategy' => RoutingStrategy::WeightedRoundRobin,
    ]);
}

/** One gateway, bound with a weight. Call order is priority order. */
function wrrBind(
    SmsTemplate $template,
    string $key,
    ?int $weight = null,
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
        'credentials' => ['api_key' => 'a-gateway-key'],
        'is_enabled' => true,
        'priority' => (SmsGateway::query()->count() + 1) * 10,
        ...$gateway,
    ])->save();

    SmsTemplateGateway::query()->create([
        'sms_template_id' => $template->getKey(),
        'sms_gateway_id' => $row->getKey(),
        'mode' => DeliveryMode::Text,
        'is_enabled' => true,
        ...($weight === null ? [] : ['weight' => $weight]),
        ...$binding,
    ]);

    return $row;
}

function wrrSend(string $to = '09121234567'): SmsMessage
{
    return Sms::to($to)->template('order-created')->with(['customer_name' => 'Amid'])->send();
}

/**
 * The gateway each of the next `$count` messages was tried on first.
 *
 * @return list<string>
 */
function wrrPrimaries(int $count): array
{
    $primaries = [];

    foreach (range(1, $count) as $ignored) {
        $primaries[] = (string) wrrSend()->attempts()->orderBy('sequence')->first()?->gateway_key;
    }

    return $primaries;
}

it('gives each gateway exactly its share of one complete cycle', function () {
    $template = wrrTemplate();
    wrrBind($template, 'a', weight: 5);
    wrrBind($template, 'b', weight: 3);
    wrrBind($template, 'c', weight: 2);

    $cycle = wrrPrimaries(10);

    /*
     * Ten messages, and the counts are exact rather than close. ⚠️ Note that the
     * weights do not add up to a hundred and nothing here needs them to: they are
     * ratios, so adding a fourth gateway will never require editing the other
     * three.
     */
    expect(array_count_values($cycle))->toBe(['a' => 5, 'b' => 3, 'c' => 2])
        // The order is a plain walk down the cumulative weights - five slots, then
        // three, then two. Deterministic, with no floating point and no rand().
        ->and($cycle)->toBe(['a', 'a', 'a', 'a', 'a', 'b', 'b', 'b', 'c', 'c']);
});

it('repeats the same cycle exactly, rather than approximately', function () {
    $template = wrrTemplate();
    wrrBind($template, 'a', weight: 5);
    wrrBind($template, 'b', weight: 3);
    wrrBind($template, 'c', weight: 2);

    $first = wrrPrimaries(10);
    $second = wrrPrimaries(10);

    /*
     * ⚠️ The distinction from weighted random selection, stated as an assertion. A
     * weighted draw would pass the previous test only on average and would fail
     * this one on most runs; determinism is what makes the distribution something
     * that can be predicted, tested and explained rather than merely hoped for.
     */
    expect($second)->toBe($first);
});

it('treats a binding nobody has weighted as an equal share', function () {
    $template = wrrTemplate();
    wrrBind($template, 'a');
    wrrBind($template, 'b');
    wrrBind($template, 'c');

    // Weight defaults to 1, so a template switched to weighted routing before
    // anybody has thought about the numbers behaves exactly like plain round-robin
    // rather than favouring whoever happens to be first.
    expect(SmsTemplateGateway::query()->pluck('weight')->all())->toBe([1, 1, 1])
        ->and(wrrPrimaries(4))->toBe(['a', 'b', 'c', 'a']);
});

it('starts a fresh cycle when a weight changes', function () {
    $template = wrrTemplate();
    wrrBind($template, 'a', weight: 1);
    $b = wrrBind($template, 'b', weight: 5);

    // Cycle of six: a, then five of b. Four messages in, we are three slots into b.
    expect(wrrPrimaries(4))->toBe(['a', 'b', 'b', 'b']);

    $b->templateBindings()->first()->update(['weight' => 2]);

    /*
     * ⚠️ A cycle position measured against the old weights means nothing once the
     * weights change, and half a cycle of the old distribution is a worse answer
     * than a clean start. The routing key carries the weights, so changing one
     * simply leaves the old cursor unreferenced.
     *
     * Under a cursor that carried over, the next message would be b - position four
     * of a three-slot cycle. It is a.
     */
    expect(wrrPrimaries(3))->toBe(['a', 'b', 'b']);
});

it('gives no share at all to a binding that has been disabled', function () {
    $template = wrrTemplate();
    wrrBind($template, 'a', weight: 5);
    wrrBind($template, 'b', weight: 3);
    $c = wrrBind($template, 'c', weight: 2);

    $c->templateBindings()->first()->update(['is_enabled' => false]);

    // Eight slots now, not ten. The share does not linger, and c's two messages go
    // to the gateways that can actually carry them rather than being lost.
    expect(array_count_values(wrrPrimaries(8)))->toBe(['a' => 5, 'b' => 3]);
});

it('survives a hand-edited weight of zero instead of dividing by nothing', function () {
    $template = wrrTemplate();
    wrrBind($template, 'a');
    wrrBind($template, 'b');

    // Straight past the model, the way a SQL console or a bad import would arrive.
    DB::table(TableNames::templateGateways())->update(['weight' => 0]);

    /*
     * ⚠️ The column is validated on write and cannot be zero, so this is defence
     * against data that did not come through the model. A whole binding set of
     * zeroes is a total of nothing to divide by - a fatal error inside the send
     * path, for every message, from a row somebody edited by hand. Clamped to one
     * instead: an equal share, which is the least surprising reading of "no weight
     * at all".
     */
    expect(wrrPrimaries(4))->toBe(['a', 'b', 'a', 'b']);
});

it('fails over in configured priority order rather than in weight order', function () {
    $template = wrrTemplate();
    wrrBind($template, 'a', weight: 1, driver: 'kavenegar');
    wrrBind($template, 'b', weight: 1);
    wrrBind($template, 'c', weight: 5, driver: 'smsir');

    Http::fake([
        // The weighted primary refuses in a way that is safe to move on from.
        'api.sms.ir/*' => Http::response([], 401),
        'api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 7]]]),
    ]);

    // Two messages to reach c's block of the cycle.
    wrrPrimaries(2);

    $attempts = wrrSend()->attempts()->orderBy('sequence')->get();

    /*
     * ⚠️ c carries most of the traffic and is still the last gateway an operator
     * ranked. Weights answer "how much of this message's traffic does each gateway
     * carry"; priority answers "who do I trust when the first one refuses". Letting
     * the weights order the failover chain too would mean a message that fails over
     * lands on the biggest account rather than the most reliable one - a second
     * policy nobody asked for, hiding inside the first.
     */
    expect($attempts->pluck('gateway_key')->all())->toBe(['c', 'a']);
});

it('refuses a weight that is not a whole number of shares', function (mixed $weight) {
    $template = wrrTemplate();
    $gateway = wrrBind($template, 'a');

    expect(fn () => SmsTemplateGateway::query()->where('sms_gateway_id', $gateway->getKey())
        ->first()->update(['weight' => $weight]))
        ->toThrow(InvalidRoutingConfiguration::class);
})->with([
    // A gateway an administrator has bound, enabled and expects traffic on, which
    // would receive none.
    'zero' => [0],
    'negative' => [-3],
    // Absurd rather than dangerous: nothing is expressed at 60000 that is not
    // expressed at 6, and a five-digit weight is somebody typing a message count
    // into the wrong field.
    'beyond the bound' => [SmsTemplateGateway::MAXIMUM_WEIGHT + 1],
    'fractional' => [1.5],
    'not a number' => ['heavy'],
]);

it('says what a weight is when it refuses one', function () {
    $template = wrrTemplate();
    $gateway = wrrBind($template, 'a');

    // The message has to teach as well as refuse: somebody who typed 0 is usually
    // somebody who thought the numbers were percentages.
    expect(fn () => SmsTemplateGateway::query()->where('sms_gateway_id', $gateway->getKey())
        ->first()->update(['weight' => 0]))
        ->toThrow(InvalidRoutingConfiguration::class, 'ratios, not percentages');
});
