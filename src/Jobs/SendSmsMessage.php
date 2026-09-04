<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Jobs;

use Mizbanha\Sms\Enums\MessageStatus;
use Mizbanha\Sms\Models\SmsMessage;
use Mizbanha\Sms\Sending\MessageDispatcher;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Delivers one recorded message. One job per message, never per batch.
 *
 * ⚠️ **This job is the single owner of retry.** The driver reports what happened,
 * the dispatcher records it, and the decision to try again is taken here and
 * nowhere else. That matters because Laravel will re-run a job for reasons that
 * have nothing to do with the gateway - a worker killed mid-run, a deployment, a
 * timeout on the job itself - and every one of those is an opportunity to send a
 * message that was already sent.
 *
 * Two mechanisms prevent that, and they cover different failures:
 *
 *   1. **The settled check.** A message that reached a terminal state is never
 *      touched again. This is what stops a re-queued job from re-sending, and it is
 *      why an uncertain result settles as Unknown rather than staying pending.
 *
 *   2. **The lock.** Two workers can hold the same job at the same time - after a
 *      visibility timeout, or with a job dispatched twice - and both would read
 *      "not settled" before either wrote. The lock makes read-decide-write atomic.
 *      It is a single cache lock, not a coordination protocol: the failure it
 *      guards against is two workers on one row, which is the only concurrency this
 *      package actually has.
 *
 * ⚠️ The variables travel in the payload rather than being read back from the row.
 * That is what lets a sensitive message be sent while persisting nothing: the
 * payload lives for the seconds the job takes and is deleted when it succeeds,
 * where the row is kept for as long as the log is.
 *
 * ⚠️ **Which is why the payload is encrypted.** `ShouldBeEncrypted` is Laravel's
 * own mechanism and Laravel does the crypto; this package invents none. A queued
 * sensitive message would otherwise put a live one-time code in clear text into a
 * `jobs` row, a Redis key or an SQS message — readable by anyone with database
 * access, and often retained long after the ninety seconds the code was good for.
 *
 * ⚠️ **Every** send job is encrypted, not only the sensitive ones. A conditional
 * job class would be a second code path whose only job is to be chosen correctly
 * every time, and the day it is not is the day a code is in the clear. The cost is
 * one cipher operation per job on a payload of a few hundred bytes; the failure it
 * removes is silent and permanent. It does mean a queued payload is no longer
 * readable in a database client, which is the intended trade.
 */
class SendSmsMessage implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;

    /**
     * @param  array<string, string|int|float|null>  $variables
     */
    public function __construct(
        public int $messageId,
        public array $variables = [],
    ) {}

    public function handle(MessageDispatcher $dispatcher): void
    {
        $lock = Cache::store(config('laravel-sms.lock.store'))
            ->lock($this->lockKey(), (int) config('laravel-sms.lock.seconds', 120));

        if (! $lock->get()) {
            // Another worker holds this message right now. Come back rather than
            // fail: the other worker may be about to settle it, in which case there
            // will be nothing left to do.
            $this->release(10);

            return;
        }

        try {
            $message = SmsMessage::query()->with('template')->find($this->messageId);

            if ($message === null || $message->isSettled()) {
                // Deleted, or already finished by an earlier run of this same job.
                // This is the check that makes the job idempotent.
                return;
            }

            $dispatcher->attempt($message, $this->variables, mayRetry: $this->mayRetry());

            /*
             * The dispatcher is the one that decides. It ran the whole failover
             * chain, and it leaves the message unsettled only when it wants another
             * attempt — so "still unsettled" is the signal, not a flag read back off
             * a result. One owner, one decision.
             */
            if (! $message->refresh()->isSettled()) {
                $this->release($this->backoffSeconds());
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether another attempt on this same gateway is still allowed.
     */
    private function mayRetry(): bool
    {
        return $this->attempts() < (int) config('laravel-sms.retry.tries', 3);
    }

    /**
     * Growing gaps. A gateway that just refused is not going to answer half a
     * second later, and hammering it is how an account gets rate limited on top of
     * being down.
     */
    private function backoffSeconds(): int
    {
        $backoff = (array) config('laravel-sms.retry.backoff', [10, 60, 300]);
        $index = min(max($this->attempts() - 1, 0), count($backoff) - 1);

        return (int) ($backoff[$index] ?? 60);
    }

    private function lockKey(): string
    {
        return 'sms:message:'.$this->messageId;
    }

    /**
     * The queue has given up.
     *
     * The dispatcher has already written the reason onto the row, so there is
     * normally nothing to do here. This covers the failure that never reached it -
     * the worker being killed, the job timing out - which would otherwise leave a
     * message pending forever with nothing to explain it.
     */
    public function failed(?Throwable $exception): void
    {
        $message = SmsMessage::query()->find($this->messageId);

        if ($message !== null && ! $message->isSettled()) {
            /*
             * ⚠️ No exception text for a sensitive message. An exception raised
             * inside a driver can carry the request it was building, and the row
             * this is written to is the one that deliberately holds no content. The
             * status still says the job gave up, which is the fact that matters.
             */
            $message->transitionTo(
                MessageStatus::Unknown,
                $message->is_sensitive
                    ? 'The send job failed before a gateway answered.'
                    : 'The send job failed before a gateway answered: '.($exception?->getMessage() ?? 'unknown reason'),
            );
        }
    }
}
