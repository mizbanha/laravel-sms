<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Templates;

use Mizbanha\Sms\Exceptions\MissingVariables;

/**
 * Body plus values in, finished text out.
 *
 * The rendered text is produced even for a pattern send, where the provider holds
 * the approved wording: it is our own record of what was said, and the only
 * readable copy left once a pattern is retired at the operator's end.
 */
final class TemplateRenderer
{
    /**
     * @param  array<string, string|int|float|null>  $variables
     *
     * @throws MissingVariables
     */
    public function render(string $body, array $variables, string $context = 'Template'): string
    {
        $names = PlaceholderParser::extract($body);
        $missing = $this->missing($names, $variables);

        if ($missing !== []) {
            throw MissingVariables::forNames($missing, $context);
        }

        $replacements = [];

        foreach ($names as $name) {
            $replacements[PlaceholderParser::placeholder($name)] = (string) $variables[$name];
        }

        return strtr($body, $replacements);
    }

    /**
     * Names the body needs that the caller did not usefully supply.
     *
     * Blank counts as missing — an empty string is a lookup that found nothing.
     *
     * @param  list<string>  $names
     * @param  array<string, string|int|float|null>  $variables
     * @return list<string>
     */
    public function missing(array $names, array $variables): array
    {
        return array_values(array_filter(
            $names,
            static fn (string $name): bool => ! isset($variables[$name])
                || trim((string) $variables[$name]) === '',
        ));
    }
}
