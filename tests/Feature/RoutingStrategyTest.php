<?php

declare(strict_types=1);

use Amid\Sms\Enums\DeliveryMode;
use Amid\Sms\Enums\RoutingStrategy;
use Amid\Sms\Exceptions\InvalidRoutingConfiguration;
use Amid\Sms\Facades\Sms;
use Amid\Sms\Gateways\GatewayRouter;
use Amid\Sms\Gateways\RoutingPlanner;
use Amid\Sms\Health\CircuitBreaker;
use Amid\Sms\Models\SmsGateway;
use Amid\Sms\Models\SmsMessage;
use Amid\Sms\Models\SmsTemplate;
use Amid\Sms\Models\SmsTemplateGateway;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The routing strategy itself: what it is, where it lives, and what happens when
 * the machinery the other two strategies depend on is not there.
 *
 * ⚠️ **`priority` is the default and the fallback, and both matter.** It is the
 * behaviour the package had before this milestone, so every existing installation
 * and every existing test keeps the ordering it had; and it is the only strategy
 * that needs no shared state at all, so it is what the package degrades TO rather
 * than something it degrades from.
 */

/**
 * A cache store with no locks at all.
 *
 * ⚠️ Built here rather than borrowed from Laravel, because the obvious candidate is
 * no longer one: the framework's `file` store implements `LockProvider` and takes a
 * real exclusive lock on a lock file, so it is atomic within one machine. What it
 * cannot do is coordinate two machines, which is a separate warning and not this
 * one. A store that cannot lock at all is what this class is, and the package must
 * refuse to call itself round-robin on it.
 */
final class UnlockableStore implements Store
{
    public function __construct(private readonly ArrayStore $inner = new ArrayStore) {}

    public function get($key)
    {
        return $this->inner->get($key);
    }

    public function many(array $keys)
    {
        return $this->inner->many($keys);
    }

    public function put($key, $value, $seconds)
    {
        return $this->inner->put($key, $value, $seconds);
    }

    public function putMany(array $values, $seconds)
    {
        return $this->inner->putMany($values, $seconds);
    }

    public function increment($key, $value = 1)
    {
        return $this->inner->increment($key, $value);
    }

    public function decrement($key, $value = 1)
    {
        return $this->inner->decrement($key, $value);
    }

    public function forever($key, $value)
    {
        return $this->inner->forever($key, $value);
    }

    public function forget($key)
    {
        return $this->inner->forget($key);
    }

    public function flush()
    {
        return $this->inner->flush();
    }

    public function touch($key, $seconds)
    {
        return $this->inner->touch($key, $seconds);
    }

    public function getPrefix()
    {
        return '';
    }
}

function strategyTemplate(?RoutingStrategy $strategy = null): SmsTemplate
{
    return SmsTemplate::query()->create([
        'key' => 'order-created',
        'name' => 'Order created',
        'body' => 'Hello {customer_name}',
        ...($strategy === null ? [] : ['routing_strategy' => $strategy]),
    ]);
}

function strategyBind(SmsTemplate $template, string $key, int $priority): SmsGateway
{
    $row = new SmsGateway;
    $row->forceFill([
        'key' => $key,
        'label' => $key,
        'driver' => 'log',
        'sender' => '30001234',
        'credentials' => ['api_key' => 'a-gateway-key'],
        'is_enabled' => true,
        'priority' => $priority,
    ])->save();

    SmsTemplateGateway::query()->create([
        'sms_template_id' => $template->getKey(),
        'sms_gateway_id' => $row->getKey(),
        'mode' => DeliveryMode::Text,
        'is_enabled' => true,
    ]);

    return $row;
}

function strategySend(): SmsMessage
{
    return Sms::to('09121234567')->template('order-created')->with(['customer_name' => 'Amid'])->send();
}

/** @return list<string> */
function strategyPrimaries(int $count): array
{
    $primaries = [];

    foreach (range(1, $count) as $ignored) {
        $primaries[] = (string) strategySend()->attempts()->orderBy('sequence')->first()?->gateway_key;
    }

    return $primaries;
}

it('offers exactly three strategies', function () {
    /*
     * ⚠️ Three, and no plugin point. `cheapest`, `fastest` and `healthiest` are not
     * routing strategies but pricing, latency and reputation models, each needing
     * data this package does not have and has no honest way to invent; and
     * user-supplied routing scripts would move the decision that produces real
     * charges out of code review.
     */
    expect(array_column(RoutingStrategy::cases(), 'value'))
        ->toBe(['priority', 'round_robin', 'weighted_round_robin']);
});

it('routes a template nobody has configured by priority, exactly as before', function () {
    $template = strategyTemplate();
    strategyBind($template, 'cheap', priority: 90);
    strategyBind($template, 'expensive', priority: 10);

    // The default is the old behaviour: lowest priority number first, every time,
    // for every message. An existing installation notices nothing.
    expect($template->routing_strategy)->toBe(RoutingStrategy::Priority)
        ->and(strategyPrimaries(3))->toBe(['expensive', 'expensive', 'expensive']);
});

it('needs no shared routing state for a priority template', function () {
    // A store with no locks whatsoever. Round-robin refuses to run on one; priority
    // must not even look at it.
    Cache::extend('nolock', fn ($app): Repository => Cache::repository(new UnlockableStore));
    config()->set('cache.stores.nolock', ['driver' => 'nolock']);
    config()->set('sms.routing.store', 'nolock');

    Log::spy();

    $template = strategyTemplate(RoutingStrategy::Priority);
    strategyBind($template, 'first', priority: 10);
    strategyBind($template, 'second', priority: 20);

    expect(strategyPrimaries(3))->toBe(['first', 'first', 'first']);

    Log::shouldNotHaveReceived('error');
});

it('falls back to priority, loudly, when the cache store cannot lock', function () {
    Cache::extend('nolock', fn ($app): Repository => Cache::repository(new UnlockableStore));
    config()->set('cache.stores.nolock', ['driver' => 'nolock']);
    config()->set('sms.routing.store', 'nolock');

    Log::spy();

    $template = strategyTemplate(RoutingStrategy::RoundRobin);
    strategyBind($template, 'first', priority: 10);
    strategyBind($template, 'second', priority: 20);

    /*
     * ⚠️ The one thing that must never happen here is a counter in this process
     * called round-robin. Four queue workers would hold four counters, all starting
     * at zero, all choosing `first` - which looks exactly like working distribution
     * from the inside and is not distribution at all.
     *
     * So it degrades to a strategy that is CORRECT without shared state, and says
     * so at error level, naming the setting to change. Messages keep going out.
     */
    expect(strategyPrimaries(3))->toBe(['first', 'first', 'first']);

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message): bool => str_contains($message, 'fell back to priority order')
            && str_contains($message, 'sms.routing.store'))
        ->once();
});

it('keeps the routing cursor in the cache rather than in the object that reads it', function () {
    $template = strategyTemplate(RoutingStrategy::RoundRobin);
    strategyBind($template, 'a', priority: 10);
    strategyBind($template, 'b', priority: 20);

    $candidates = app(GatewayRouter::class)->candidatesFor($template->refresh(), 'IR');

    // Two planners that share nothing but the cache: no constructor argument
    // between them carries state, and neither knows the other exists.
    $one = new RoutingPlanner(app(CircuitBreaker::class));
    $two = new RoutingPlanner(app(CircuitBreaker::class));

    $first = $one->plan($template, $candidates);
    $second = $two->plan($template, $candidates);
    $third = $one->plan($template, $candidates);

    /*
     * ⚠️ This is the closest a single-process test can honestly get to the property
     * that matters. It does NOT prove behaviour under real multi-server contention,
     * which has not been tested and is not claimed anywhere. What it does prove is
     * the thing that would make such a test pointless: the position is not held in
     * the planner. A `static` counter or an instance field would give the second
     * planner slot zero again, and four queue workers would all send to `a`.
     */
    expect($first->candidates[0]->gateway->key)->toBe('a')
        ->and($second->candidates[0]->gateway->key)->toBe('b')
        ->and($third->candidates[0]->gateway->key)->toBe('a')
        ->and($first->primaryGatewayId)->not->toBeNull();
});

it('records no routing intent on a message routed by priority', function () {
    $template = strategyTemplate(RoutingStrategy::Priority);
    strategyBind($template, 'a', priority: 10);
    strategyBind($template, 'b', priority: 20);

    /*
     * Nothing to remember. A priority template produces the same order on every run
     * by construction, so a snapshot would be a column recording a fact that is
     * already derivable - and one more thing to keep in step.
     */
    expect(strategySend()->routing_gateway_id)->toBeNull();
});

it('records where a round-robin message was pointed, as intent rather than evidence', function () {
    $template = strategyTemplate(RoutingStrategy::RoundRobin);
    $a = strategyBind($template, 'a', priority: 10);
    strategyBind($template, 'b', priority: 20);

    $message = strategySend();

    expect($message->routing_gateway_id)->toBe($a->getKey())
        // The attempt is the evidence that a provider was actually contacted. The
        // column above is only where routing pointed, and the two can differ.
        ->and($message->attempts()->first()->gateway_key)->toBe('a');
});

it('refuses a strategy nobody implements, and says which three exist', function () {
    /*
     * ⚠️ Refused when it is WRITTEN, which is the only moment there is anybody
     * around to be told. A misspelling accepted here would have to fall back to
     * something at send time, and a template silently routed by a policy nobody
     * chose is worse than a template that would not save.
     */
    expect(fn () => strategyTemplate()->update(['routing_strategy' => 'cheapest']))
        ->toThrow(InvalidRoutingConfiguration::class, 'priority, round_robin, weighted_round_robin');
});

it('forgives case and spacing on a strategy that does exist', function () {
    $template = strategyTemplate();

    $template->update(['routing_strategy' => '  Round_Robin ']);

    expect($template->refresh()->routing_strategy)->toBe(RoutingStrategy::RoundRobin);
});

it('says which strategies need shared state', function () {
    expect(RoutingStrategy::Priority->needsSharedState())->toBeFalse()
        ->and(RoutingStrategy::RoundRobin->needsSharedState())->toBeTrue()
        ->and(RoutingStrategy::WeightedRoundRobin->needsSharedState())->toBeTrue();
});
