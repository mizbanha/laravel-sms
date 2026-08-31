<?php

declare(strict_types=1);

namespace Amid\Sms\Health;

use Carbon\CarbonImmutable;

/**
 * A read-only view of one gateway's circuit, for something that wants to display
 * it.
 *
 * Deliberately small, and deliberately not the internals: no cache keys, no store
 * name, no configuration, nothing secret. A management screen needs to say "this
 * gateway is being skipped, since 10:42, after three failures" and nothing more —
 * and anything it does not receive here is something it cannot accidentally put on
 * a page.
 */
final readonly class CircuitSnapshot
{
    /**
     * @param  int  $failures  qualifying transport failures counted in the current
     *                         window; zero once the circuit has opened, because the
     *                         streak has already done its work
     * @param  CarbonImmutable|null  $openUntil  when the cooldown ends. Null when
     *                              the circuit is closed; in the past while the
     *                              gateway is half-open and owed a probe
     */
    public function __construct(
        public CircuitState $state,
        public int $failures = 0,
        public ?CarbonImmutable $openUntil = null,
    ) {}

    /** Whether a message may currently be handed to this gateway at all. */
    public function isAvailable(): bool
    {
        return $this->state !== CircuitState::Open;
    }
}
