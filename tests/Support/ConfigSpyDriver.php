<?php

declare(strict_types=1);

namespace Amid\Sms\Tests\Support;

use Amid\Sms\Contracts\Driver;
use Amid\Sms\Enums\Capability;
use Amid\Sms\Gateways\GatewayConfig;
use Amid\Sms\Results\SendResult;
use Amid\Sms\Sending\OutboundMessage;

/**
 * A driver that reports WHICH configuration it was built with, and nothing else.
 *
 * It exists for one question: when a gateway is edited and a driver is resolved
 * again inside the same request, does the new driver see the new configuration?
 * That cannot be asked of the real drivers without either sending something or
 * reading a credential back out, and this package will do neither in a test.
 *
 * ⚠️ **The credential is never returned, printed or asserted on.** `fingerprint()`
 * is a truncated one-way digest, so a changed key produces a changed string and a
 * failure message that quotes it reveals nothing — the same reasoning the circuit
 * breaker uses for its own cache keys. Everything else this class exposes (the
 * gateway key, the sender, an option) is non-secret by definition.
 *
 * Contacts nobody. `send()` exists because the contract requires it.
 */
final class ConfigSpyDriver implements Driver
{
    public function __construct(private readonly GatewayConfig $config) {}

    public function capabilities(): array
    {
        return [Capability::Text, Capability::DeliveryReport];
    }

    public function send(OutboundMessage $message): SendResult
    {
        return SendResult::accepted('spy-'.$this->fingerprint());
    }

    public function gatewayKey(): string
    {
        return $this->config->key;
    }

    public function sender(): ?string
    {
        return $this->config->sender;
    }

    public function marker(): mixed
    {
        return $this->config->option('marker');
    }

    /**
     * A stable, non-reversible witness for the credential this driver holds.
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', (string) $this->config->credential('api_key')), 0, 12);
    }
}
