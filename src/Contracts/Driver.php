<?php

declare(strict_types=1);

namespace Amid\Sms\Contracts;

use Amid\Sms\Enums\Capability;
use Amid\Sms\Results\SendResult;
use Amid\Sms\Sending\OutboundMessage;

/**
 * One SMS gateway, behind one shape.
 *
 * Two methods, and they carry the entire provider-facing contract:
 *
 *   - capabilities() so the router can tell whether this gateway can carry a
 *     message at all, instead of finding out by failing;
 *   - send() which takes an already-canonical, already-mapped message and answers
 *     with a provider-neutral result.
 *
 * ⚠️ **A driver does not throw for provider behaviour.** No credit, a wrong key,
 * an unregistered pattern, a rate limit and a timeout are all ordinary answers and
 * all belong in a SendResult, because they are facts about one message that an
 * operator has to be able to read later. An exception here would be recorded
 * nowhere and retried blindly.
 *
 * A driver MAY throw for a genuinely unusable execution condition — a credential
 * that is not configured at all — because that is not a fact about this message,
 * it is the gateway being unusable for every message until someone changes it.
 * The orchestrator records that too, and never lets it reach the caller.
 *
 * ⚠️ One recipient per call. Providers that accept a list answer with a single
 * status for the batch, which would mean recording, for every recipient but one, a
 * result that was never theirs.
 */
interface Driver
{
    /**
     * @return list<Capability>
     */
    public function capabilities(): array;

    public function send(OutboundMessage $message): SendResult;
}
