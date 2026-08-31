<?php

declare(strict_types=1);

namespace Amid\Sms\Otp;

use Amid\Sms\Models\SmsMessage;

/**
 * What an application gets back from asking for a code to be sent.
 *
 * ⚠️ **It does not contain the code, and there is no accessor that returns it.**
 *
 * That is the single most important line in this class. A result object that
 * carried the code would put it in every stack trace, every `dd()`, every
 * exception reporter's context payload and every log line that dumped a return
 * value — and the whole of M5's sensitive-message work exists to keep it out of
 * exactly those places. An application never needs it: it sends the code to a
 * phone and later asks this package whether what the person typed matches.
 *
 * Tests that must know the code bind a deterministic `OtpCodeGenerator` instead.
 * That is a deliberate seam for verification, not a way in.
 */
final readonly class OtpResult
{
    private function __construct(
        public OtpStatus $status,
        /**
         * The message row, for a send that reached the pipeline. Null when the
         * cooldown refused before anything was recorded.
         *
         * Safe to expose: a sensitive message's row holds no body and no variables.
         */
        public ?SmsMessage $message = null,
        /** Seconds until another code may be requested. */
        public ?int $retryAfter = null,
    ) {}

    public static function of(OtpStatus $status, ?SmsMessage $message = null): self
    {
        return new self($status, $message);
    }

    /**
     * ⚠️ The CONFIGURED interval, not the true age of the existing challenge.
     *
     * Reporting the real remaining time would answer "when exactly was a code last
     * sent to this number", which is an enumeration oracle: an attacker who cannot
     * see whether a number is registered can often infer it from a countdown that
     * differs from the constant. A fixed interval says the same thing to everyone.
     */
    public static function cooldown(int $seconds): self
    {
        return new self(OtpStatus::Cooldown, retryAfter: $seconds);
    }

    public function sent(): bool
    {
        return $this->status === OtpStatus::Sent;
    }
}
