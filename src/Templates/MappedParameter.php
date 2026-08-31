<?php

declare(strict_types=1);

namespace Amid\Sms\Templates;

/**
 * One pattern value, ready for one provider.
 *
 * Three facts travel together because different providers need different ones:
 * a named provider wants the name, a positional provider wants only the value in
 * the right place, and the logical variable name is what makes an error message
 * mean something to the person who wrote the template.
 *
 * ⚠️ These are handed to a driver as an ORDERED LIST, never as an associative
 * array. Position is data here: it is the only thing that tells Kavenegar which
 * token a value belongs to, and a structure whose order can be reshuffled by a
 * storage engine cannot carry it. See ParameterMapper.
 */
final readonly class MappedParameter
{
    public function __construct(
        /** What this provider calls the parameter; null at a provider that only counts. */
        public ?string $provider,
        /** Our own name for it — the template placeholder. */
        public string $variable,
        public string $value,
    ) {}

    /**
     * The name to send to a provider that names its parameters.
     *
     * Falls back to our own variable name, which is the sane default for a
     * provider whose registered parameter names were copied from our wording.
     */
    public function name(): string
    {
        return $this->provider ?? $this->variable;
    }
}
