<?php

declare(strict_types=1);

namespace Amid\Sms\Gateways;

use Amid\Sms\Contracts\Driver;
use Amid\Sms\Models\SmsGateway;
use Amid\Sms\Models\SmsTemplateGateway;

/**
 * One gateway that could carry one message, with everything needed to try it.
 *
 * The three parts always travel together: the gateway says who, the binding says
 * how (mode, pattern code, parameter names), and the driver is the thing that
 * actually goes out. Passing them separately is how a message ends up sent through
 * one gateway using another gateway's pattern code.
 */
final readonly class GatewayCandidate
{
    public function __construct(
        public SmsGateway $gateway,
        public SmsTemplateGateway $binding,
        public Driver $driver,
    ) {}
}
