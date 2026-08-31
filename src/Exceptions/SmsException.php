<?php

declare(strict_types=1);

namespace Amid\Sms\Exceptions;

use RuntimeException;

/**
 * Base for everything this package throws.
 *
 * ⚠️ Nothing in this hierarchy ever carries a credential value. Messages are
 * built from names and identifiers only — see Amid\Sms\Gateways\GatewayConfig.
 */
class SmsException extends RuntimeException {}
