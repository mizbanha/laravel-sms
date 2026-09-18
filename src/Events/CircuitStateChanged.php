<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Events;

use Carbon\CarbonImmutable;
use Mizbanha\Sms\Health\CircuitState;
use Mizbanha\Sms\Models\SmsGateway;

/**
 * This application's circuit for one gateway has moved.
 *
 *   closed    → open       `failure_threshold`  enough transport failures in the window
 *   open      → half_open  `cooldown_elapsed`   the cooldown passed and a probe was claimed
 *   half_open → open       `probe_failed`       the probe failed the same way
 *   half_open → closed     `probe_succeeded`    the probe was accepted
 *   any       → closed     `reset`              somebody called `CircuitBreaker::reset()`
 *
 * ⚠️ **Local evidence about one account, not a statement about a provider.** See
 * `CircuitState`. Anything that reports these to a human should say "this
 * application's circuit for gateway X", never "provider X is down".
 *
 * ⚠️ `half_open` is time-based inside the breaker; it is announced at the moment
 * this process claims the probe, which is the moment it becomes observable.
 *
 * ⚠️ Observational; see `AttemptRecorded`. The gateway model is the application's
 * own row: its credentials are encrypted and hidden, and a listener that forwards
 * anything must not forward them.
 */
final class CircuitStateChanged
{
    public const REASON_FAILURE_THRESHOLD = 'failure_threshold';

    public const REASON_COOLDOWN_ELAPSED = 'cooldown_elapsed';

    public const REASON_PROBE_FAILED = 'probe_failed';

    public const REASON_PROBE_SUCCEEDED = 'probe_succeeded';

    public const REASON_RESET = 'reset';

    /**
     * @param  int  $failures  the qualifying failures that caused an opening, or zero
     *                         for any other transition
     * @param  CarbonImmutable|null  $openUntil  when the cooldown ends, for a
     *                                           transition INTO open
     */
    public function __construct(
        public readonly SmsGateway $gateway,
        public readonly CircuitState $from,
        public readonly CircuitState $to,
        public readonly string $reason,
        public readonly int $failures = 0,
        public readonly ?CarbonImmutable $openUntil = null,
    ) {}
}
