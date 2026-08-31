<?php

declare(strict_types=1);

namespace Amid\Sms\Health;

use Amid\Sms\Enums\FailureKind;
use Amid\Sms\Enums\SendOutcome;
use Amid\Sms\Exceptions\SmsException;
use Amid\Sms\Models\SmsGateway;
use Amid\Sms\Results\SendResult;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Stops this application from spending every message's latency on a gateway that
 * has just failed to answer the last several.
 *
 * Failover already makes a message survive a dead gateway. What it does not do is
 * stop the NEXT message paying the same fifteen-second timeout, and the one after
 * that: with a provider that is genuinely down, every message in the queue waits
 * for it in turn before reaching the healthy gateway behind it. This is the small
 * amount of memory that fixes that.
 *
 * ⚠️ **It answers exactly one question:** should this application temporarily
 * avoid calling this gateway, because recent transport evidence is bad? It is not
 * a provider status service, not a health dashboard, not a permanent disable
 * switch, and not a second opinion on `is_enabled`. Anything it cannot answer from
 * an outcome and a failure kind, it does not answer.
 *
 * **Three states**, and the middle one is the reason this is not four lines of
 * code: `closed` → `open` (skip, for a cooldown) → `half_open` (exactly one probe
 * at a time) → `closed` or `open` again.
 *
 * ⚠️ **Ephemeral, and cache-only.** There is no column, no table and no history.
 * Gateway CONFIGURATION belongs in the database because an operator owns it;
 * transient operational health is an observation this process made a minute ago,
 * and storing it beside the configuration would make it look like a setting.
 *
 * ⚠️ **Local evidence about one account.** A rate-limited account, an expired
 * credential and a provider outage all look identical from here. See CircuitState.
 */
final class CircuitBreaker
{
    /**
     * Circuits this instance currently holds a half-open probe for.
     *
     * Kept in memory rather than in the cache because it is a fact about THIS
     * dispatch: the same singleton answers `allows()` and then `record()` for one
     * attempt, and the record has to know whether the call it is reporting on was
     * the probe. Nothing else needs it, and it must not outlive the process.
     *
     * @var array<string, true>
     */
    private array $probes = [];

    /** Whether the store has already been reported as unusable. Once is enough. */
    private bool $warned = false;

    /**
     * May a message be handed to this gateway right now?
     *
     * ⚠️ Called by the dispatcher immediately before the call, never by the router.
     * `GatewayRouter` answers a static question — enabled, bound, capable, serves
     * the country — from the database, in one query, with no side effects. This one
     * reads and WRITES cache state, and a query builder that quietly reserves a
     * probe would be a trap for whoever next tries to reason about routing.
     */
    public function allows(SmsGateway $gateway): bool
    {
        if (! $this->usable()) {
            return true;
        }

        return match ($this->status($gateway)->state) {
            CircuitState::Closed => true,
            CircuitState::Open => false,
            // The cooldown has passed. One message gets to find out; the rest go
            // to the gateway behind this one rather than queueing up behind a
            // provider that may still be down.
            CircuitState::HalfOpen => $this->reserveProbe($gateway),
        };
    }

    /**
     * Record what a gateway just did, for the benefit of the NEXT message.
     *
     * ⚠️ **This never affects the message that produced the result.** The
     * dispatcher has already decided what happens to that one, under rules that
     * have nothing to do with health — and in particular, an uncertain result stops
     * that message permanently whatever this class concludes. Opening a circuit and
     * then continuing the same message to another gateway is precisely the
     * duplicate send the whole architecture is built to avoid.
     *
     * The evidence is read from the structured result and nothing else: no provider
     * name, no HTTP status, no error text, and not `safeToFailover` or
     * `retryableOnSameGateway` either — those are policy answers about one message,
     * not statements about whether a gateway can be reached.
     */
    public function record(SmsGateway $gateway, SendResult $result): void
    {
        if (! $this->usable()) {
            return;
        }

        $identity = $this->identity($gateway);
        $probing = isset($this->probes[$identity]);
        unset($this->probes[$identity]);

        if ($result->outcome === SendOutcome::Accepted) {
            /*
             * Proof, and the only proof this class accepts: the gateway was
             * reached, understood us and took the message. Whatever the counter
             * held is now history.
             */
            $this->close($gateway);

            return;
        }

        if (! $this->qualifies($result)) {
            /*
             * ⚠️ Neutral, and neutral is a third answer rather than a quiet
             * failure.
             *
             * An invalid recipient, a message the provider will not carry, a
             * pattern that is not registered, a rejected credential, a refusal
             * generated locally before any request — none of them says the gateway
             * cannot be reached, and several of them never touched it. They do not
             * count against health, and they must not be mistaken for evidence of
             * recovery either: a probe that produces one has proved nothing, so the
             * reservation is released and the gateway stays owed a probe.
             */
            if ($probing) {
                $this->store()->forget($this->key($gateway, 'probe'));
            }

            return;
        }

        if ($probing) {
            // The one careful try failed the same way. Straight back to open for
            // another cooldown - waiting for the threshold again would mean
            // hammering a provider that has just told us twice.
            $this->open($gateway);

            return;
        }

        $this->registerFailure($gateway);
    }

    /**
     * What a management screen may be told. See CircuitSnapshot.
     */
    public function status(SmsGateway $gateway): CircuitSnapshot
    {
        if (! $this->usable()) {
            return new CircuitSnapshot(CircuitState::Closed);
        }

        $openUntil = $this->store()->get($this->key($gateway, 'open'));
        $failures = $this->store()->get($this->key($gateway, 'failures'));

        if (! is_int($openUntil)) {
            return new CircuitSnapshot(CircuitState::Closed, (int) ($failures['count'] ?? 0));
        }

        return new CircuitSnapshot(
            // Past the cooldown the record survives a while longer on purpose: it
            // is what distinguishes "recovering, owed one probe" from "nothing ever
            // happened here".
            now()->getTimestamp() < $openUntil ? CircuitState::Open : CircuitState::HalfOpen,
            (int) ($failures['count'] ?? 0),
            CarbonImmutable::createFromTimestamp($openUntil),
        );
    }

    /**
     * Forget everything observed about this gateway.
     *
     * The primitive behind a future "reset health" button, and it does exactly one
     * thing: it clears an observation. ⚠️ It does not enable a disabled gateway,
     * does not touch priority, credentials, country policy or any other stored
     * configuration, and sends nothing. An operator who resets a circuit is saying
     * "try again now", not "I have fixed it".
     */
    public function reset(SmsGateway $gateway): void
    {
        if (! $this->usable()) {
            return;
        }

        $this->close($gateway);
    }

    /**
     * The failure kinds that are evidence about the GATEWAY rather than about the
     * message.
     *
     * ⚠️ Two, deliberately. `Network` is "we could not complete the request" and
     * `ProviderUnavailable` is "the provider said it could not deal with this now"
     * — a 5xx, a rate limit. Everything else is either about this message or about
     * configuration, and neither gets better by waiting sixty seconds.
     *
     * ⚠️ An UNCERTAIN network result counts. It is the single most valuable signal
     * here — a provider timing out mid-request is exactly the state worth avoiding
     * for the next message — and it is safe to count precisely because counting it
     * changes nothing about the current message, which stops as `unknown` either
     * way.
     */
    private function qualifies(SendResult $result): bool
    {
        return in_array(
            $result->failureKind,
            [FailureKind::Network, FailureKind::ProviderUnavailable],
            true,
        );
    }

    /**
     * Count one qualifying failure, and open if there have now been enough of them
     * close enough together.
     *
     * ⚠️ A bounded window, not a lifetime streak. One outage on Monday, one on
     * Wednesday and one on Friday is a gateway that works; a counter with no window
     * would open on the third of them, weeks apart, having proved nothing. The
     * window start is stored explicitly rather than left to a cache TTL, so the
     * behaviour is the same on every store and testable without waiting.
     */
    private function registerFailure(SmsGateway $gateway): void
    {
        $window = $this->window();
        $now = now()->getTimestamp();
        $record = $this->store()->get($this->key($gateway, 'failures'));

        if (! is_array($record) || ($now - (int) ($record['started_at'] ?? 0)) >= $window) {
            $record = ['count' => 0, 'started_at' => $now];
        }

        $record['count'] = (int) $record['count'] + 1;

        if ($record['count'] >= $this->threshold()) {
            $this->open($gateway);

            return;
        }

        $this->store()->put($this->key($gateway, 'failures'), $record, $window);
    }

    private function open(SmsGateway $gateway): void
    {
        $cooldown = $this->cooldown();

        /*
         * The record outlives the cooldown on purpose. Once `open_until` has
         * passed, its continued presence is what says "half-open, owed one probe";
         * without it the circuit would read as closed and every waiting message
         * would rush the gateway at the same moment.
         *
         * It does not live forever either. If nothing sends through this gateway
         * for a long time there is no evidence left worth keeping, and the next
         * message simply tries it - which is what a probe is anyway.
         */
        $this->store()->put(
            $this->key($gateway, 'open'),
            now()->getTimestamp() + $cooldown,
            $cooldown * 2 + $this->probeTtl(),
        );

        $this->store()->forget($this->key($gateway, 'failures'));
        $this->store()->forget($this->key($gateway, 'probe'));
    }

    private function close(SmsGateway $gateway): void
    {
        unset($this->probes[$this->identity($gateway)]);

        foreach (['open', 'failures', 'probe'] as $suffix) {
            $this->store()->forget($this->key($gateway, $suffix));
        }
    }

    /**
     * Claim the single half-open probe, if it is still going.
     *
     * ⚠️ `add()` is the whole mechanism: it writes only if the key is absent and
     * reports whether it did, atomically, on every store this package supports.
     * Two workers reaching a recovering gateway in the same millisecond therefore
     * produce one probe and one skip, with no lock of our own and no distributed
     * anything.
     *
     * ⚠️ The reservation has a TTL because a process can die holding it. Without
     * one, a worker killed mid-probe would leave the gateway half-open and
     * unprobeable forever, which is a worse outage than the one that opened it.
     */
    private function reserveProbe(SmsGateway $gateway): bool
    {
        if (! $this->store()->add($this->key($gateway, 'probe'), 1, $this->probeTtl())) {
            return false;
        }

        $this->probes[$this->identity($gateway)] = true;

        return true;
    }

    /**
     * The persisted columns a circuit's identity is derived from.
     *
     * Everything here changes how - or whether - this application talks to the
     * provider: which driver, which line, which credentials, which per-driver
     * options, and which destinations the account is configured to serve. A change
     * to any of them is a different gateway as far as availability is concerned,
     * and the evidence gathered about the old one no longer applies.
     *
     * ⚠️ Read as PERSISTED values, never as decoded ones. `credentials` is the
     * encrypted-at-rest ciphertext and is hashed exactly as it sits in the column;
     * nothing here is ever decrypted, and the JSON columns are hashed as the
     * strings the database holds rather than as PHP arrays, so no object iteration
     * order can influence the result.
     */
    private const FINGERPRINTED = ['driver', 'sender', 'credentials', 'options', 'country_policy', 'countries'];

    /**
     * What this gateway's circuit is called, in a way that a configuration change
     * invalidates.
     *
     * ⚠️ The configuration fingerprint is the point. A gateway whose credentials
     * were wrong opens its circuit; an administrator then fixes the credentials -
     * and must not have to find a "reset health" button before the fix takes
     * effect. Saving different configuration produces a different circuit, and the
     * corrected gateway starts closed. The stale record expires on its own,
     * unreferenced.
     *
     * ⚠️ It is a fingerprint rather than `updated_at`, and that is a deliberate
     * correction. A timestamp with second resolution assumes two meaningful
     * configuration writes can never land inside one tick, which is an assumption
     * about the database's column precision and about how fast somebody clicks
     * Save. It held in ordinary use; it is not something to release on. Content
     * decides identity now, so the timing question does not arise.
     *
     * ⚠️ Nothing secret and nothing personal appears in a key: the fingerprint is a
     * one-way digest, and the material behind it is ciphertext and configuration
     * columns - never a recipient, a template, a body, an OTP or a message
     * variable.
     */
    private function identity(SmsGateway $gateway): string
    {
        return sprintf('%s:%s', (string) $gateway->getKey(), $this->fingerprint($gateway));
    }

    /**
     * A deterministic digest of this gateway's transport configuration.
     *
     * Deterministic for one persisted row: two model instances loaded from the same
     * record produce the same digest, because the material is the raw column values
     * rather than anything reconstructed.
     *
     * Encryption is not deterministic, so hashing ciphertext could in principle
     * change the identity on every save. It does not: Laravel compares encrypted
     * attributes by their decrypted values, so re-saving the same credentials
     * writes nothing and the material is untouched.
     *
     * ⚠️ And the asymmetry is deliberate anyway. If some future cast did churn the
     * digest, the cost would be a gateway measured again from scratch. The
     * direction that must never happen is the other one - stale evidence about old
     * configuration blocking configuration that has since been corrected - and
     * content-derived identity cannot produce it.
     */
    private function fingerprint(SmsGateway $gateway): string
    {
        $attributes = $gateway->getAttributes();
        $material = [];

        // A fixed list, iterated in a fixed order: the model's own attribute order
        // depends on how the instance was built and must not reach the digest.
        foreach (self::FINGERPRINTED as $column) {
            $value = $attributes[$column] ?? null;

            $material[] = $column.'='.(is_scalar($value) ? (string) $value : '');
        }

        // Half a SHA-256 is 128 bits of a digest nothing has to be recovered from;
        // it keeps the key readable in a cache browser without meaning anything to
        // anybody who reads it.
        return substr(hash('sha256', implode('|', $material)), 0, 32);
    }

    private function key(SmsGateway $gateway, string $suffix): string
    {
        return sprintf('sms:circuit:%s:%s', $this->identity($gateway), $suffix);
    }

    /**
     * Whether the breaker can run at all.
     *
     * ⚠️ It refuses to work on a cache store without atomic operations rather than
     * quietly running a racy version of itself — on such a store, `add()` is a read
     * followed by a write, and the single-probe guarantee is not a guarantee.
     *
     * It says so loudly, once, and then behaves as though it were switched off.
     * That is a deliberate choice about blast radius: this is a latency
     * optimisation, and refusing to send anything at all because an optional
     * resilience feature cannot get the store it wants would turn a cache
     * misconfiguration into a total outage. The message goes; the operator gets an
     * error in the log naming the setting to change.
     */
    private function usable(): bool
    {
        if (! (bool) config('sms.circuit_breaker.enabled', true)) {
            return false;
        }

        if ($this->store()->getStore() instanceof LockProvider) {
            return true;
        }

        if (! $this->warned) {
            $this->warned = true;

            Log::error(
                'SMS circuit breaker disabled: the configured cache store does not support atomic operations. '
                .'Point sms.circuit_breaker.store at a store that does (database, redis, memcached, dynamodb or array), '
                .'or set sms.circuit_breaker.enabled to false to stop this message.'
            );
        }

        return false;
    }

    private function store(): Repository
    {
        return Cache::store(config('sms.circuit_breaker.store') ?? config('sms.lock.store'));
    }

    private function threshold(): int
    {
        return $this->positive('failure_threshold', 3);
    }

    private function window(): int
    {
        return $this->positive('failure_window', 60);
    }

    private function cooldown(): int
    {
        return $this->positive('cooldown', 60);
    }

    /**
     * How long one half-open probe may be outstanding.
     *
     * ⚠️ Floored at the HTTP budget one call can actually take. A probe TTL shorter
     * than the request it is protecting would expire while that request is still in
     * flight, admit a second probe, and produce exactly the pile-up the half-open
     * state exists to prevent.
     */
    private function probeTtl(): int
    {
        return max(
            $this->positive('probe_ttl', 30),
            (int) config('sms.http.timeout', 15) + (int) config('sms.http.connect_timeout', 5),
        );
    }

    /**
     * A configured whole number of seconds, or a clear refusal.
     *
     * A threshold of zero opens a circuit that was never closed; a window or
     * cooldown of zero is a breaker that never remembers anything. Both are
     * mistakes worth naming at the moment somebody makes them rather than
     * silently treating as "off".
     */
    private function positive(string $name, int $default): int
    {
        $value = config('sms.circuit_breaker.'.$name, $default);

        if (! is_numeric($value) || (int) $value < 1) {
            throw new SmsException(sprintf(
                'sms.circuit_breaker.%s must be a whole number of seconds greater than zero.',
                $name,
            ));
        }

        return (int) $value;
    }
}
