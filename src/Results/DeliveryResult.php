<?php

declare(strict_types=1);

namespace Amid\Sms\Results;

use Amid\Sms\Enums\DeliveryStatus;

/**
 * What a provider currently says about the fate of one accepted message.
 *
 * The delivery-side counterpart of `SendResult`, and deliberately much smaller
 * than it. A `SendResult` drives decisions — retry, failover, settle — so it
 * carries the flags those decisions need. This drives nothing at all: it is
 * observational, and everything it holds is written down and read by humans.
 *
 * ⚠️ **It carries no provider payload, and it must not.** Delivery-report
 * endpoints are the most content-rich responses in this package: IPPanel's
 * recipient report returns the original message text alongside the status, and
 * Twilio's message resource returns the body too. Persisting a delivery report
 * wholesale would reintroduce, through a reporting endpoint, exactly the content
 * that M5 went to some trouble never to store — including a one-time code that a
 * sensitive message deliberately kept out of the database in the first place.
 *
 * So the raw report is parsed in memory and only these normalised fields survive:
 * a neutral status, the provider's own status token, its structured error code,
 * and — for an ordinary message only — a short sanitized explanation.
 */
final readonly class DeliveryResult
{
    /**
     * @param  DeliveryStatus  $status  the provider-neutral verdict
     * @param  string|null  $providerStatus  the provider's own status token, kept
     *         verbatim because it is what a support ticket to that provider will
     *         quote; a short token such as `undelivered` or `2`, never prose
     * @param  string|null  $providerErrorCode  the provider's structured failure
     *         code where it publishes one, as a string: these are identifiers, and
     *         casting them to integers is how a code with a leading zero or a
     *         non-numeric prefix becomes a different code
     * @param  string|null  $error  a short human-readable reason, already stripped
     *         of credentials. ⚠️ Dropped entirely for a sensitive message — see
     *         DeliveryTracker
     */
    public function __construct(
        public DeliveryStatus $status,
        public ?string $providerStatus = null,
        public ?string $providerErrorCode = null,
        public ?string $error = null,
    ) {}
}
