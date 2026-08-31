<?php

declare(strict_types=1);

namespace Amid\Sms\Otp;

/**
 * What happened when an application asked for a one-time code to be sent.
 *
 * Five outcomes, because an application genuinely does something different for
 * each: show the code entry form, show a countdown, show an error, ask the person
 * to wait and try again, or say nothing is being sent in this environment.
 *
 * ⚠️ None of them carries the code. That is not an oversight — see OtpResult.
 */
enum OtpStatus: string
{
    /** A gateway took the message. Show the code entry form. */
    case Sent = 'sent';

    /**
     * A code was sent recently and the cooldown has not passed. **Nothing new was
     * generated**, and the previous code is still the valid one.
     */
    case Cooldown = 'cooldown';

    /**
     * Every eligible gateway definitively refused. The challenge has been destroyed
     * and the cooldown released, so the caller may try again immediately — waiting
     * ninety seconds for a code that provably never left would be punishing the
     * person for the provider's failure.
     */
    case Failed = 'failed';

    /**
     * ⚠️ It is not known whether the message was delivered — a timeout, or a 5xx
     * after the request arrived.
     *
     * **The challenge is kept.** The message may well have reached the handset, and
     * destroying the challenge would leave somebody holding a code that this
     * package has decided to forget. Ask them to enter the code they may have
     * received, and let the cooldown expire normally.
     */
    case Unknown = 'unknown';

    /**
     * The master switch is off. Nothing was sent and nothing is pending; the
     * challenge and its cooldown were removed rather than left behind for a code
     * that reached nobody.
     */
    case Suppressed = 'suppressed';

    /**
     * Whether the caller should now be showing a code entry form.
     *
     * `Cooldown` counts: an earlier code is still live and still verifiable, which
     * is the whole reason the cooldown refused to issue another one.
     */
    public function awaitingCode(): bool
    {
        return in_array($this, [self::Sent, self::Cooldown, self::Unknown], true);
    }
}
