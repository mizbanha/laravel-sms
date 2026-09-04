<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Sending;

use Mizbanha\Sms\Enums\DeliveryMode;
use Mizbanha\Sms\Phone\PhoneNumber;
use Mizbanha\Sms\Templates\MappedParameter;

/**
 * What a driver is handed: one message, ready to go out.
 *
 * Everything a provider could disagree about has already been settled by the time
 * this object exists. The number is canonical, the wording is rendered, and the
 * parameters are already named and ordered the way THIS provider wants them. A
 * driver's remaining job is the shape of one HTTP call and the reading of one
 * answer - which is the only part that is genuinely provider-specific.
 *
 * That is what keeps provider knowledge out of calling code: an application asks
 * for a logical message with logical variables, and never learns that one provider
 * numbers its parameters and another names them.
 */
final readonly class OutboundMessage
{
    /**
     * @param  string|null  $body  the rendered wording. Present for a text send;
     *                             also present for a pattern send, where it is our
     *                             own record of what was said rather than something
     *                             transmitted.
     * @param  list<MappedParameter>  $parameters  the pattern values in the order
     *                                            this provider expects. An ORDERED
     *                                            LIST rather than a keyed array,
     *                                            because at a positional provider
     *                                            the position IS the parameter and
     *                                            nothing may be free to reorder it.
     */
    public function __construct(
        public PhoneNumber $to,
        public DeliveryMode $mode,
        public ?string $body = null,
        public ?string $patternCode = null,
        public array $parameters = [],
        public ?string $sender = null,
        /**
         * ⚠️ Whether this message must not be written down.
         *
         * A driver needs this for one reason only: a driver that LOGS - and this
         * package ships one - must not write the wording of a login code to a file
         * that outlives it. A driver that sends over HTTP ignores it entirely; the
         * code has to reach the provider or nothing works.
         */
        public bool $sensitive = false,
    ) {}

    /**
     * The values alone, in order, for a provider that numbers rather than names
     * its placeholders.
     *
     * @return list<string>
     */
    public function parameterValues(): array
    {
        return array_map(static fn (MappedParameter $parameter): string => $parameter->value, $this->parameters);
    }

    /**
     * The values keyed by what this provider calls them, for a provider that names
     * its placeholders.
     *
     * Order is preserved here too. It carries no meaning at a named provider, but
     * it is what makes a logged request read like the template it came from.
     *
     * @return array<string, string>
     */
    public function namedParameters(): array
    {
        $named = [];

        foreach ($this->parameters as $parameter) {
            $named[$parameter->name()] = $parameter->value;
        }

        return $named;
    }
}
