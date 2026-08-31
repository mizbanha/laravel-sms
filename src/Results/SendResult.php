<?php

declare(strict_types=1);

namespace Amid\Sms\Results;

use Amid\Sms\Enums\FailureKind;
use Amid\Sms\Enums\SendOutcome;

/**
 * What one gateway did with one message, in terms the orchestrator can act on.
 *
 * Every driver returns one of these. Nothing above a driver ever inspects an HTTP
 * status, a provider error code, or an exception message to decide what happens
 * next — the driver has already answered that here.
 *
 * ⚠️ The two policy flags are carried explicitly rather than derived from
 * `failureKind`, because the safe answer is not a function of the kind alone. A
 * connection that was refused before the request left is known not-sent; a
 * connection that timed out mid-request is not, and both are `Network`. The driver
 * is the only layer that knows which one it saw, so the driver is the layer that
 * says.
 */
final readonly class SendResult
{
    /**
     * @param  bool  $retryableOnSameGateway  whether sending this again to this
     *                                        same gateway could plausibly succeed
     * @param  bool  $safeToFailover  whether the message is known not to have been
     *                                accepted, so another gateway may carry it
     *                                without risking a duplicate
     * @param  array<string, mixed>|null  $providerPayload  the decoded provider
     *                                                      RESPONSE only — never
     *                                                      request data, which is
     *                                                      where credentials live
     */
    private function __construct(
        public SendOutcome $outcome,
        public ?FailureKind $failureKind = null,
        public bool $retryableOnSameGateway = false,
        public bool $safeToFailover = false,
        public ?string $providerMessageId = null,
        public ?string $error = null,
        public ?array $providerPayload = null,
    ) {}

    /**
     * The gateway took responsibility for the message.
     *
     * @param  array<string, mixed>|null  $providerPayload
     */
    public static function accepted(?string $providerMessageId = null, ?array $providerPayload = null): self
    {
        return new self(
            outcome: SendOutcome::Accepted,
            providerMessageId: $providerMessageId,
            providerPayload: $providerPayload,
        );
    }

    /**
     * The gateway definitively did not take the message.
     *
     * Defaults are the conservative reading of each kind; a driver that knows
     * better overrides them.
     *
     * @param  array<string, mixed>|null  $providerPayload
     */
    public static function rejected(
        FailureKind $failureKind,
        string $error,
        ?array $providerPayload = null,
        ?bool $retryableOnSameGateway = null,
        ?bool $safeToFailover = null,
    ): self {
        return new self(
            outcome: SendOutcome::Rejected,
            failureKind: $failureKind,
            retryableOnSameGateway: $retryableOnSameGateway ?? ($failureKind === FailureKind::ProviderUnavailable),
            // A rejection is by definition known not-sent, so failing over cannot
            // duplicate anything. The exception is a message that no gateway could
            // send: retrying it elsewhere only produces the same refusal again.
            safeToFailover: $safeToFailover ?? ! in_array(
                $failureKind,
                [FailureKind::InvalidMessage, FailureKind::InvalidRecipient],
                true,
            ),
            error: self::trim($error),
            providerPayload: $providerPayload,
        );
    }

    /**
     * It is not knowable whether the provider accepted this message.
     *
     * ⚠️ Both policy flags are false and cannot be overridden. This is the whole
     * point of the case: a timeout that is retried is how one order confirmation
     * becomes two, and no caller is permitted to decide otherwise.
     *
     * @param  array<string, mixed>|null  $providerPayload
     */
    public static function uncertain(
        FailureKind $failureKind,
        string $error,
        ?array $providerPayload = null,
    ): self {
        return new self(
            outcome: SendOutcome::Uncertain,
            failureKind: $failureKind,
            retryableOnSameGateway: false,
            safeToFailover: false,
            error: self::trim($error),
            providerPayload: $providerPayload,
        );
    }

    public function successful(): bool
    {
        return $this->outcome === SendOutcome::Accepted;
    }

    /**
     * Truncated here rather than at the column: a provider that answers with an
     * HTML error page must not cause the write that records the failure to fail.
     */
    private static function trim(string $error): string
    {
        return mb_substr(trim($error), 0, 500);
    }
}
