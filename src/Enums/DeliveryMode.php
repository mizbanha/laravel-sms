<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Enums;

/**
 * How one template is delivered through one gateway.
 *
 * Deliberately NOT a property of the template. The same logical message may be a
 * registered pattern at one provider, a pattern with different parameter names at
 * a second, and free text at a third, so the mode lives on the template/gateway
 * binding and nowhere else.
 */
enum DeliveryMode: string
{
    case Text = 'text';

    case Pattern = 'pattern';

    /**
     * The capability a gateway must have to carry a message in this mode.
     */
    public function requiredCapability(): Capability
    {
        return match ($this) {
            self::Text => Capability::Text,
            self::Pattern => Capability::Pattern,
        };
    }
}
