<?php

declare(strict_types=1);

namespace Amid\Sms\Gateways;

use Amid\Sms\Enums\RoutingStrategy;
use Amid\Sms\Health\CircuitBreaker;
use Amid\Sms\Models\SmsTemplate;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * In what order should this message be offered the gateways that could carry it?
 *
 * ⚠️ **Ordering only, and the separation is the point.** `GatewayRouter` answers
 * which gateways are eligible at all - enabled, bound, usable, capable, serving the
 * destination's country - and this class does not revisit any of it. It receives
 * that list and returns a permutation of it. It never adds a candidate, never
 * removes one, and never decides anything about what happens after a gateway has
 * answered:
 *
 *     GatewayRouter     -> who could carry this      (database, no side effects)
 *     CircuitBreaker    -> who is answering us now   (cache)
 *     RoutingPlanner    -> in what order             (cache cursor)
 *     MessageDispatcher -> calls drivers, decides failover from their answers
 *
 * ⚠️ **A strategy can never weaken failover.** An uncertain result still stops a
 * message permanently, an unsafe refusal still stops it, and only a safe refusal
 * still moves on - all of it decided from what a provider actually said, in the
 * dispatcher, exactly as it has been since M2. Round-robin changes which gateway is
 * asked first. It changes nothing about being asked twice.
 *
 * **Why the state is in the cache rather than in this object.** A counter on an
 * instance, or a `static` inside a method, distributes nothing: an application with
 * four queue workers has four processes and therefore four counters, each starting
 * at zero, each choosing the same first gateway. Round-robin that is not shared is
 * not round-robin.
 */
final class RoutingPlanner
{
    /** Whether the store has already been reported as unusable. Once is enough. */
    private bool $warned = false;

    public function __construct(private readonly CircuitBreaker $breaker) {}

    /**
     * The order in which to try these gateways.
     *
     * @param  list<GatewayCandidate>  $candidates  eligible, in configured priority
     *         order - the order the package used before strategies existed
     * @param  int|null  $preferred  the gateway this message was already routed to
     *         on an earlier run, if it has one
     */
    public function plan(SmsTemplate $template, array $candidates, ?int $preferred = null): RoutingPlan
    {
        $strategy = $template->routing_strategy ?? RoutingStrategy::Priority;

        /*
         * ⚠️ Two ways out before anything touches the cache.
         *
         * `priority` is the default, and the whole of its behaviour is the order
         * the database already returned: it reads no cursor, writes no cursor, and
         * is unaffected by the cache store, by a lock, or by anything else the
         * other two strategies have to care about. And with one candidate there is
         * no distribution to make - spending a slot on it would mean a template
         * bound to a single gateway silently advancing a cycle it is not part of.
         */
        if (! $strategy->needsSharedState() || count($candidates) < 2) {
            return new RoutingPlan($candidates);
        }

        if ($preferred !== null) {
            /*
             * ⚠️ A retry, not a new message, and it takes no slot.
             *
             * Round-robin distributes NEW logical messages. A job Laravel released
             * and ran again is the same one, and re-planning it would hand it
             * whatever slot the shared cursor had reached in the meantime - so ten
             * unrelated messages could move this one to a different gateway between
             * two runs of the same job, which is a routing decision made by other
             * people's traffic.
             *
             * The recorded gateway leads if it is still eligible. If it is not -
             * disabled since, or spent by a refusal it already gave - the rest keep
             * their configured order rather than taking a fresh slot, and ⚠️ a
             * gateway enabled since the first run is still in this list and can
             * still rescue the message. Stable intent is not a frozen candidate set.
             */
            $index = $this->indexOf($candidates, $preferred);

            return new RoutingPlan(
                $index === null ? $candidates : $this->order($candidates, $index, $strategy),
                $preferred,
            );
        }

        [$participating, $skipped] = $this->partition($candidates);

        if ($participating === []) {
            /*
             * Every eligible gateway is circuit-open. Nothing will be called, so
             * there is nothing to distribute and no slot is spent - a cycle
             * position consumed by a message that reaches no provider is a share of
             * the traffic that goes nowhere.
             *
             * ⚠️ The candidates are returned in full rather than emptied. The
             * dispatcher's own handling of "nothing could be attempted" - unsettled
             * for a queued send, failed for a synchronous one - is M7 behaviour and
             * depends on there being candidates that were skipped rather than no
             * candidates at all.
             */
            return new RoutingPlan($candidates);
        }

        $cursor = $this->advance($this->key($template, $strategy, $participating));

        if ($cursor === null) {
            // No shared cursor is available. Explicitly the configured order,
            // announced in the log - never a process-local counter pretending to be
            // a distributed one. See advance().
            return new RoutingPlan($candidates);
        }

        $ordered = $this->order(
            $participating,
            $strategy === RoutingStrategy::RoundRobin
                ? $cursor % count($participating)
                : $this->weightedIndex($participating, $cursor),
            $strategy,
        );

        return new RoutingPlan(
            // Skipped gateways go last rather than away. They will be skipped again
            // by the breaker at the moment of the call; leaving them in means a
            // circuit that closes between this plan and that call can still help.
            [...$ordered, ...$skipped],
            (int) $ordered[0]->gateway->getKey(),
        );
    }

    /**
     * Which slot of the weighted cycle this cursor position falls in.
     *
     * ⚠️ Deterministic, and not a weighted random draw. Weights 5, 3 and 2 make a
     * cycle of ten consecutive slots -
     *
     *     A A A A A B B B C C
     *
     * - which then repeats: cursor 0 to 4 select A, 5 to 7 select B, 8 and 9 select
     * C, and cursor 10 is A again. Over any complete cycle the counts are exactly
     * five, three and two.
     *
     * A random draw weighted the same way produces that ratio only in the long run,
     * and "eventually, on average" is not something anybody can hold a provider
     * contract to, reproduce in a test, or explain to somebody looking at
     * yesterday's traffic. There is no `rand()` here and no floating point: one
     * modulo, and a walk down the cumulative weights.
     *
     * @param  list<GatewayCandidate>  $participating
     */
    private function weightedIndex(array $participating, int $cursor): int
    {
        $weights = array_map(
            // Clamped rather than trusted. The column is validated on write and
            // cannot be zero, but a hand-edited row must not be able to divide the
            // cycle by nothing.
            static fn (GatewayCandidate $candidate): int => max(1, (int) $candidate->binding->weight),
            $participating,
        );

        $slot = $cursor % array_sum($weights);

        foreach ($weights as $index => $weight) {
            if ($slot < $weight) {
                return $index;
            }

            $slot -= $weight;
        }

        // Unreachable: the slot is a modulo of the total, so the walk above always
        // lands. Here so the method has one return type and no silent null.
        return 0;
    }

    /**
     * The chain, given which candidate leads it.
     *
     * ⚠️ The two strategies order the REMAINDER differently, deliberately.
     *
     * Round-robin rotates: with A, B, C and B leading, the chain is B, C, A. The
     * whole strategy is a rotation, and failing over into the gateway that would
     * have been next anyway keeps one idea in the reader's head instead of two.
     *
     * Weighted round-robin does not rotate. Weights govern PRIMARY selection and
     * nothing else - that is what an administrator is expressing when they write 5,
     * 3 and 2 - so once the primary is chosen, the remainder is offered in plain
     * configured priority order. Weighting every failover hop would mean a message
     * that fails over lands on the gateway with the largest share rather than the
     * one an operator ranked most reliable: a second, unasked-for policy hiding
     * inside the first.
     *
     * @param  list<GatewayCandidate>  $candidates
     * @return list<GatewayCandidate>
     */
    private function order(array $candidates, int $lead, RoutingStrategy $strategy): array
    {
        if ($strategy === RoutingStrategy::RoundRobin) {
            return [...array_slice($candidates, $lead), ...array_slice($candidates, 0, $lead)];
        }

        $rest = $candidates;
        [$primary] = array_splice($rest, $lead, 1);

        return [$primary, ...$rest];
    }

    /**
     * Split the eligible candidates into those a circuit would currently let
     * through and those it would not.
     *
     * ⚠️ Read-only. `CircuitBreaker::available()` claims nothing, unlike `allows()`,
     * which reserves the single half-open probe as a side effect. Asking `allows()`
     * here would consume the one probe a recovering gateway is owed while merely
     * deciding an ORDER, and then quite possibly never make the call - leaving that
     * gateway unprobeable until the reservation expired.
     *
     * ⚠️ A half-open gateway PARTICIPATES. It is owed a probe, and the ordinary
     * routing order is how it gets one; excluding it here would design a system in
     * which a recovering gateway can never be measured again.
     *
     * @param  list<GatewayCandidate>  $candidates
     * @return array{0: list<GatewayCandidate>, 1: list<GatewayCandidate>}
     */
    private function partition(array $candidates): array
    {
        $participating = [];
        $skipped = [];

        foreach ($candidates as $candidate) {
            if ($this->breaker->available($candidate->gateway)) {
                $participating[] = $candidate;
            } else {
                $skipped[] = $candidate;
            }
        }

        return [$participating, $skipped];
    }

    /**
     * @param  list<GatewayCandidate>  $candidates
     */
    private function indexOf(array $candidates, int $gatewayId): ?int
    {
        foreach ($candidates as $index => $candidate) {
            if ((int) $candidate->gateway->getKey() === $gatewayId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Take the next position in this cycle, atomically.
     *
     * ⚠️ **A read, an increment and a write is three operations, and three
     * operations are a race.** Two workers reading 7 at the same moment both send
     * to the same gateway, which is exactly the outcome round-robin exists to
     * prevent - and unlike most races it leaves no trace, because both messages
     * succeed. A Laravel cache lock makes the three one, on every store this
     * package supports, with no Redis command and no new dependency.
     *
     * ⚠️ **When shared state is not available this returns null and says so** -
     * once, at error level, naming the setting to change. The caller then uses the
     * configured priority order, which is a real strategy and is correct without
     * any shared state at all. What it must never do is keep a counter in this
     * process and call the result round-robin: that would look exactly like working
     * distribution while every worker sent to the same gateway.
     */
    private function advance(string $key): ?int
    {
        $store = $this->store();

        if (! $store->getStore() instanceof LockProvider) {
            $this->warn(
                'the configured cache store does not support atomic locks. Point laravel-sms.routing.store at a '
                .'store that does (database, redis, memcached or dynamodb), or set the template back to '
                .'the priority strategy, which needs no shared state.'
            );

            return null;
        }

        try {
            return $store->getStore()->lock($key.':lock', 5)->block(1, function () use ($store, $key): int {
                $current = $store->get($key);

                // Starts at 0, so the first message of a cycle goes to the first
                // participating gateway - the one an operator ranked highest.
                $next = is_int($current) ? $current + 1 : 0;

                $store->put($key, $next, $this->ttl());

                return $next;
            });
        } catch (LockTimeoutException) {
            $this->warn(
                'the routing cursor could not be locked within a second. That is a cache store under '
                .'severe contention or partly unavailable; messages are still being sent, in configured '
                .'priority order.'
            );

            return null;
        }
    }

    /**
     * Where this cycle's position is kept.
     *
     * ⚠️ **Scoped so that stale state is harmless.** The key carries the template,
     * the strategy, and the identity of the gateways actually taking part - with
     * their weights, where weights mean something. Change any of it and the cycle
     * starts fresh, which is the behaviour worth having: after a gateway is added,
     * removed, disabled or reweighted, a position measured against the old set means
     * nothing, and half a cycle of the old distribution is a worse answer than a
     * clean start.
     *
     * ⚠️ **Nothing personal and nothing secret**, by construction. The material is
     * database identifiers, a strategy name and small integers - never a recipient,
     * a body, a variable, a one-time code, a credential, a sender or a provider
     * message id. It is hashed anyway, so a cache browser shows an opaque string
     * rather than a map of the installation.
     *
     * @param  list<GatewayCandidate>  $participating
     */
    private function key(SmsTemplate $template, RoutingStrategy $strategy, array $participating): string
    {
        $material = [$strategy->value];

        foreach ($participating as $candidate) {
            $material[] = sprintf(
                '%s:%s%s',
                (string) $candidate->binding->getKey(),
                (string) $candidate->gateway->getKey(),
                // Weights are part of the identity only where they change the
                // answer. Reweighting a binding must not restart a plain
                // round-robin cycle that never looked at weights.
                $strategy === RoutingStrategy::WeightedRoundRobin
                    ? ':'.(int) $candidate->binding->weight
                    : '',
            );
        }

        return sprintf(
            'sms:routing:%s:%s',
            (string) $template->getKey(),
            substr(hash('sha256', implode('|', $material)), 0, 32),
        );
    }

    private function store(): Repository
    {
        return Cache::store(config('laravel-sms.routing.store') ?? config('laravel-sms.lock.store'));
    }

    /**
     * How long an idle cycle is remembered.
     *
     * A cursor that expires costs one restarted cycle and nothing else, so this is
     * generous rather than precise: it only has to outlive the quiet hours of a
     * template that sends a few messages a day.
     */
    private function ttl(): int
    {
        $ttl = (int) config('laravel-sms.routing.state_ttl', 86400);

        return $ttl > 0 ? $ttl : 86400;
    }

    private function warn(string $problem): void
    {
        if ($this->warned) {
            return;
        }

        $this->warned = true;

        Log::error('SMS round-robin routing fell back to priority order: '.$problem);
    }
}
