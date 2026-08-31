<?php

declare(strict_types=1);

namespace Amid\Sms\Drivers;

use Amid\Sms\Contracts\Driver;
use Amid\Sms\Enums\Capability;
use Amid\Sms\Gateways\GatewayConfig;
use Amid\Sms\Results\SendResult;
use Amid\Sms\Sending\OutboundMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes the message to a log channel instead of sending it.
 *
 * Not a stub. It is what a developer machine and a staging environment run on, and
 * it is the only gateway a new template can be exercised against while its wording
 * is still being argued over - a template tried against a real provider costs money
 * and reaches a real phone, and the phone belongs to a real person.
 *
 * It always accepts. An environment where every send fails makes every feature that
 * sends one look broken while it is being built.
 *
 * It mints an id in the same position a provider's would occupy, so that anything
 * reading a message log, or later a delivery-status lookup, meets the same shape in
 * every environment.
 *
 * ⚠️ It writes message bodies verbatim, which is the point of it — EXCEPT for a
 * sensitive message, where it writes metadata only. See send().
 */
final class LogDriver implements Driver
{
    public function __construct(private readonly GatewayConfig $config) {}

    public function capabilities(): array
    {
        return [Capability::Text, Capability::Pattern];
    }

    public function send(OutboundMessage $message): SendResult
    {
        $id = 'log-'.Str::lower((string) Str::ulid());

        /*
         * ⚠️ A sensitive message is logged as metadata and nothing else.
         *
         * This driver exists because writing the wording verbatim is useful while a
         * template is being argued over. That usefulness is exactly what makes it
         * dangerous here: a log channel outlives the 180 seconds a login code is
         * good for, is readable by anyone with the file, and is frequently shipped
         * somewhere central. The delivery facts are still worth having, so they are
         * still written; the content is not.
         */
        $content = $message->sensitive
            ? ['content' => '[sensitive content omitted]']
            : ['body' => $message->body, 'parameters' => $message->namedParameters()];

        Log::channel($this->channel())->info('SMS', [
            'gateway' => $this->config->key,
            'mode' => $message->mode->value,
            'to' => $message->to->e164,
            'from' => $message->sender ?? $this->config->sender,
            'pattern_code' => $message->patternCode,
            'sensitive' => $message->sensitive,
            ...$content,
            'provider_message_id' => $id,
        ]);

        return SendResult::accepted($id);
    }

    /**
     * Its own channel where one is configured.
     *
     * Worth configuring: this driver writes message bodies verbatim, which is the
     * point of it locally, and those bodies should not sit in the general
     * application log for as long as that log is kept.
     */
    private function channel(): string
    {
        $channel = $this->config->option('channel') ?? config('sms.log.channel');

        return is_string($channel) && $channel !== '' ? $channel : (string) config('logging.default', 'stack');
    }
}
