<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Support;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Announces something this package did, to whoever chose to listen.
 *
 * ⚠️ **A listener can never change a send.** Every event in `Mizbanha\Sms\Events`
 * is dispatched from inside the send path — after an attempt is recorded, as a
 * message settles, as a circuit moves — and the send path has one rule above all
 * others: it does not throw. An exception raised by somebody's listener would
 * otherwise escape `MessageDispatcher`, roll back the order the message was
 * announcing, or fail a queued job that has already handed the message to a
 * provider — the retry of which is exactly how one person receives it twice.
 *
 * So a listener that throws is logged and swallowed, here, once, for every event.
 * The log line names the event and the exception CLASS only: a listener's
 * exception message is text this package did not write and cannot vouch for, and
 * the models these events carry hold recipients.
 *
 * ⚠️ **It is not a queue.** Events are dispatched synchronously, in-process, with
 * Laravel's own dispatcher. A listener that wants to do anything slow must hand the
 * work off itself; one that blocks is blocking a send.
 *
 * @internal Dispatch mechanism only. The events themselves are the public API.
 */
final class Events
{
    public static function emit(object $event): void
    {
        try {
            $container = Container::getInstance();

            if (! $container->bound('events')) {
                return;
            }

            $container->make('events')->dispatch($event);
        } catch (Throwable $exception) {
            try {
                Log::warning('laravel-sms: an event listener threw; the send it observed was not affected.', [
                    'event' => $event::class,
                    'exception' => $exception::class,
                ]);
            } catch (Throwable) {
                // Logging is the last resort, and a broken logger is not a reason
                // to break a send either.
            }
        }
    }
}
