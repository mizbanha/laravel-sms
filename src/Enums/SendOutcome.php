<?php

declare(strict_types=1);

namespace Amid\Sms\Enums;

/**
 * What is known about one delivery attempt.
 *
 * Three cases, not two. The third is the reason this enum exists: a request that
 * timed out, or a provider that answered 500, may or may not have been processed,
 * and treating that as a plain failure is how one message becomes two.
 */
enum SendOutcome: string
{
    /** The gateway took responsibility for the message. Not the same as delivered. */
    case Accepted = 'accepted';

    /** The gateway definitively did not take it. Known not-sent. */
    case Rejected = 'rejected';

    /** Unknown whether the provider accepted it. Never automatically re-sent. */
    case Uncertain = 'uncertain';
}
