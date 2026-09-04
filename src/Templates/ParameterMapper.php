<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Templates;

use Mizbanha\Sms\Exceptions\InvalidParameterMap;
use Mizbanha\Sms\Exceptions\MissingVariables;

/**
 * Our logical variable names, translated into one provider's parameters, in one
 * provider's order.
 *
 * This is the piece that lets one logical template be a pattern at two providers
 * that disagree about what the parameters are called - and at a third that does
 * not name them at all:
 *
 *     kavenegar     (positional)  1st => customer_name, 2nd => order_number
 *     sms.ir        (named)       CUSTOMER => customer_name, ORDER_NO => order_number
 *
 * ⚠️ **Order is data, and it is stored as order.** A mapping is an ordered LIST:
 *
 *     [
 *       {"provider": "token",  "variable": "customer_name"},
 *       {"provider": "token2", "variable": "order_number"}
 *     ]
 *
 * It used to be a JSON object keyed by provider parameter, and that was a real
 * defect rather than a matter of taste. A JSON OBJECT has no ordering contract:
 * MySQL normalises object keys when it stores them - sorted by key length, then
 * bytewise - so `{"z_customer": ..., "a_code": ...}` comes back the other way
 * round. SQLite keeps the text as written, so the same code that reordered a
 * customer's name and their order number in production passed every test. A JSON
 * ARRAY is ordered by definition on both engines, so the sequence written is the
 * sequence read.
 *
 * `provider` may be null for a provider that only counts its parameters, and the
 * template's own variable name is used wherever a name is needed anyway.
 *
 * With no mapping configured at all, the template's own placeholder names are
 * used, in body order. That is the sane default for a provider whose registered
 * parameter names were copied from our wording, and it means a gateway binding
 * only needs a mapping when the provider actually disagrees.
 *
 * ⚠️ No provider knows anything about this class and it knows nothing about any
 * provider. A driver enforces its own limits - Kavenegar's three-parameter
 * ceiling, Melipayamak's separator - on what it is given here.
 */
final class ParameterMapper
{
    /**
     * @param  list<array{provider?: string|null, variable: string}>|null  $map
     * @param  list<string>  $templateVariables  fallback, in body order
     * @param  array<string, string|int|float|null>  $variables
     * @return list<MappedParameter>  in the configured order
     *
     * @throws InvalidParameterMap
     * @throws MissingVariables
     */
    public function map(?array $map, array $templateVariables, array $variables, string $context = 'Pattern'): array
    {
        $pairs = $this->pairs($map, $templateVariables, $context);

        $parameters = [];
        $missing = [];

        foreach ($pairs as [$provider, $logical]) {
            $value = $variables[$logical] ?? null;

            if ($value === null || trim((string) $value) === '') {
                $missing[] = $logical;

                continue;
            }

            $parameters[] = new MappedParameter($provider, $logical, (string) $value);
        }

        if ($missing !== []) {
            throw MissingVariables::forNames(array_values(array_unique($missing)), $context);
        }

        return $parameters;
    }

    /**
     * The mapping as an ordered list of [providerParameter, logicalVariable] pairs.
     *
     * Validated rather than trusted. A stored map is configuration somebody typed,
     * and every shape this rejects is one that would otherwise have produced a
     * plausible-looking message with the values in the wrong places.
     *
     * @param  array<mixed>|null  $map
     * @param  list<string>  $templateVariables
     * @return list<array{0: string|null, 1: string}>
     *
     * @throws InvalidParameterMap
     */
    private function pairs(?array $map, array $templateVariables, string $context): array
    {
        if ($map === null || $map === []) {
            return array_map(
                // Our own names, in body order. Positional providers get that order;
                // named ones get our names, which is the documented default.
                static fn (string $variable): array => [null, $variable],
                array_values($templateVariables),
            );
        }

        if (! array_is_list($map)) {
            // Almost certainly the old object form, or a hand-written one. Refused
            // rather than read, because reading it would mean trusting key order.
            throw InvalidParameterMap::notAList($context);
        }

        $pairs = [];
        $seen = [];

        foreach ($map as $position => $entry) {
            if (! is_array($entry)) {
                throw InvalidParameterMap::malformedEntry($context, $position);
            }

            $variable = $entry['variable'] ?? null;
            $provider = $entry['provider'] ?? null;

            if (! is_string($variable) || trim($variable) === '') {
                throw InvalidParameterMap::malformedEntry($context, $position);
            }

            if ($provider !== null && (! is_string($provider) || trim($provider) === '')) {
                throw InvalidParameterMap::malformedEntry($context, $position);
            }

            if ($provider !== null) {
                // Two values on one provider parameter: a named provider would
                // receive one of them and nobody would be told which.
                if (isset($seen[$provider])) {
                    throw InvalidParameterMap::duplicateProvider($context, $provider);
                }

                $seen[$provider] = true;
            }

            $pairs[] = [$provider, $variable];
        }

        return $pairs;
    }
}
