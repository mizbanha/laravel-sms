<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Exceptions;

final class TemplateNotFound extends SmsException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf('No SMS template is keyed [%s].', $key));
    }
}
