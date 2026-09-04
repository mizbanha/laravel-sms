<?php

declare(strict_types=1);

namespace Mizbanha\Sms\Gateways;

use Mizbanha\Sms\Exceptions\GatewayNotConfigured;

/**
 * The settings one driver is built with, and the only object that knows which of
 * them are secret.
 *
 * A driver receives this instead of the gateway model, so no driver can reach the
 * database, and every credential in the package passes through exactly one class.
 *
 * There are three ways a secret escapes a system like this, and all three are shut
 * here rather than left to each driver to remember:
 *
 *   - it is dumped, logged, or serialised along with the object holding it
 *     (__debugInfo, and the model's own $hidden);
 *   - it is named in an exception when it is missing (requireCredential names the
 *     KEY, never a value);
 *   - it comes back inside a provider's own error text, because the provider
 *     echoed the request - or because the credential was in the request URL, which
 *     is where two of the four Iranian providers put it (redact).
 */
final class GatewayConfig
{
    /**
     * @param  array<string, string>  $credentials
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $key,
        public readonly ?string $sender,
        private readonly array $credentials = [],
        private readonly array $options = [],
    ) {}

    public function credential(string $name): ?string
    {
        $value = $this->credentials[$name] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * A credential the driver cannot work without.
     *
     * Throws rather than returning a rejection: a missing API key is not something
     * one message got wrong, it is the gateway being unusable for every message
     * until somebody edits it. The orchestrator records that as an attempt like any
     * other failure, so it still reaches the log rather than the caller.
     */
    public function requireCredential(string $name): string
    {
        return $this->credential($name)
            ?? throw GatewayNotConfigured::missingCredential($this->key, $name);
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    /**
     * Remove every configured secret from a string.
     *
     * Applied to everything a driver reports - provider error text, exception
     * messages - because a provider that echoes the request, or a transport error
     * that quotes the URL, will otherwise write an API key into the attempt log and
     * into the application log behind it.
     */
    public function redact(string $text): string
    {
        foreach ($this->credentials as $value) {
            if (is_string($value) && trim($value) !== '') {
                $text = str_replace($value, '[redacted]', $text);
            }
        }

        return $text;
    }

    /**
     * The same, through a decoded provider response.
     *
     * A provider that echoes the request back - or quotes it inside a validation
     * error - would otherwise write a live credential into the stored attempt
     * payload, which is kept for as long as the log is. Recursive because provider
     * errors nest.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redactArray(array $data): array
    {
        foreach ($data as $key => $value) {
            $data[$key] = match (true) {
                is_string($value) => $this->redact($value),
                is_array($value) => $this->redactArray($value),
                default => $value,
            };
        }

        return $data;
    }

    /**
     * What var_dump, dd() and every debug helper see. Never the values.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'key' => $this->key,
            'sender' => $this->sender,
            'credentials' => array_map(static fn (): string => '[redacted]', $this->credentials),
            'options' => $this->options,
        ];
    }
}
