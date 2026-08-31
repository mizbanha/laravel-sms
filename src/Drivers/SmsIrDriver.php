<?php

declare(strict_types=1);

namespace Amid\Sms\Drivers;

use Amid\Sms\Contracts\Driver;
use Amid\Sms\Drivers\Concerns\InteractsWithHttp;
use Amid\Sms\Enums\Capability;
use Amid\Sms\Enums\DeliveryMode;
use Amid\Sms\Enums\FailureKind;
use Amid\Sms\Gateways\GatewayConfig;
use Amid\Sms\Results\SendResult;
use Amid\Sms\Sending\OutboundMessage;
use Illuminate\Http\Client\Response;

/**
 * SMS.ir. The NAMED half of the parameter-mapping proof.
 *
 * Where Kavenegar numbers its pattern parameters, this provider names them, and
 * the names are the ones registered with the template at the provider's end rather
 * than anything of ours. There is no way to discover them from here.
 *
 * That is precisely what a binding's parameter_map is for. Without it, the only
 * thing keeping a pattern working would be that our own variable names happened to
 * match the provider's - which means renaming a variable in a template body would
 * silently break this provider and nothing else. With it, the two vocabularies are
 * connected by a row an operator can edit, and neither has to follow the other.
 *
 * The key travels in a header here, which is the sane arrangement of the two.
 */
final class SmsIrDriver implements Driver
{
    use InteractsWithHttp;

    /** The provider's own "accepted", inside an HTTP 200. */
    private const OK = 1;

    public function __construct(private readonly GatewayConfig $config) {}

    public function capabilities(): array
    {
        return [Capability::Text, Capability::Pattern];
    }

    public function send(OutboundMessage $message): SendResult
    {
        return $message->mode === DeliveryMode::Pattern
            ? $this->sendPattern($message)
            : $this->sendText($message);
    }

    private function sendText(OutboundMessage $message): SendResult
    {
        return $this->perform(
            fn (): Response => $this->request()->post($this->url('/v1/send/bulk'), [
                'lineNumber' => $message->sender ?? $this->config->sender,
                'messageText' => (string) $message->body,
                'mobiles' => [$message->to->national],
            ]),
            fn (Response $response): SendResult => $this->interpret($response, 'data.messageIds.0'),
        );
    }

    private function sendPattern(OutboundMessage $message): SendResult
    {
        return $this->perform(
            fn (): Response => $this->request()->post($this->url('/v1/send/verify'), [
                'mobile' => $message->to->national,
                'templateId' => (string) $message->patternCode,
                'parameters' => $this->named($message->namedParameters()),
            ]),
            fn (Response $response): SendResult => $this->interpret($response, 'data.messageId'),
        );
    }

    /**
     * The mapped parameters in this provider's shape: a list of name/value pairs.
     *
     * The keys are used as given, because they are the provider's own registered
     * names - which is the whole difference between this driver and the positional
     * one.
     *
     * @param  array<string, string>  $parameters
     * @return list<array{name: string, value: string}>
     */
    private function named(array $parameters): array
    {
        $named = [];

        foreach ($parameters as $name => $value) {
            $named[] = ['name' => $name, 'value' => $value];
        }

        return $named;
    }

    private function request()
    {
        return $this->http()->withHeaders([
            'X-API-KEY' => $this->config->requireCredential('api_key'),
        ]);
    }

    private function url(string $path): string
    {
        return rtrim((string) $this->config->option('url', 'https://api.sms.ir'), '/').$path;
    }

    private function interpret(Response $response, string $idPath): SendResult
    {
        // Raw for every decision below; the sanitized copy is what gets stored.
        $payload = $this->decode($response);

        if ((int) data_get($payload, 'status') !== self::OK) {
            // ⚠️ Not failable over, for the same reason as the other drivers: this
            // package has no verified mapping from this provider's status numbers
            // to causes, so it cannot tell an account problem from a refusal of
            // this particular message. See KavenegarDriver::interpret().
            return SendResult::rejected(
                FailureKind::GatewayRejected,
                $this->config->redact(sprintf(
                    'sms.ir %s: %s',
                    (string) data_get($payload, 'status', '?'),
                    (string) data_get($payload, 'message', 'no response'),
                )),
                $this->sanitized($payload),
                safeToFailover: false,
            );
        }

        $id = data_get($payload, $idPath);

        return SendResult::accepted($id === null ? null : (string) $id, $this->sanitized($payload));
    }
}
