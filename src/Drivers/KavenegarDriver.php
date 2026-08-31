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
 * Kavenegar. The POSITIONAL half of the parameter-mapping proof.
 *
 * Its pattern parameters are numbered, not named: token, token2, token3. Whatever
 * a binding calls them, this provider matches them by ORDER against wording it
 * approved, so the driver takes the mapped values in sequence and assigns the
 * names itself. That is the entire reason ParameterMapper guarantees order.
 *
 * Two provider facts that are not obvious and are not negotiable:
 *
 *   - it accepts at most THREE parameters, and a fourth is not truncated, it is a
 *     refusal;
 *   - a parameter may not contain a space, and one that does makes the gateway
 *     reject the whole request with a status that does not say which one was at
 *     fault.
 *
 * Both are handled here, inside the driver, because they are facts about this
 * provider and calling code must never have to know them.
 *
 * The API key travels in the URL PATH rather than a header, which is why nothing
 * here logs a request and why every error string this class produces is passed
 * through redaction before it leaves.
 */
final class KavenegarDriver implements Driver
{
    use InteractsWithHttp;

    /** Kavenegar's own "this went fine", inside the body of an HTTP 200. */
    private const OK = 200;

    /** The provider's hard ceiling on pattern parameters. */
    private const MAX_PARAMETERS = 3;

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
            fn (): Response => $this->http()->asForm()->post($this->url('sms', 'send'), array_filter([
                'receptor' => $message->to->national,
                'sender' => $message->sender ?? $this->config->sender,
                'message' => (string) $message->body,
            ], static fn (mixed $value): bool => $value !== null && $value !== '')),
            $this->interpret(...),
        );
    }

    private function sendPattern(OutboundMessage $message): SendResult
    {
        $values = $message->parameterValues();

        if (count($values) > self::MAX_PARAMETERS) {
            /*
             * A refusal by this provider, not a broken message.
             *
             * GatewayRejected rather than InvalidMessage, and safe to fail over,
             * because the logical message is perfectly sendable - just not here.
             * Classifying it as a bad message would take a template that another
             * gateway could deliver and mark it undeliverable everywhere.
             */
            return SendResult::rejected(
                FailureKind::GatewayRejected,
                sprintf(
                    'Kavenegar accepts at most %d pattern parameters; this message has %d.',
                    self::MAX_PARAMETERS,
                    count($values),
                ),
            );
        }

        return $this->perform(
            fn (): Response => $this->http()->asForm()->post($this->url('verify', 'lookup'), [
                'receptor' => $message->to->national,
                'template' => (string) $message->patternCode,
                ...$this->numbered($values),
            ]),
            $this->interpret(...),
        );
    }

    /**
     * The values as token, token2, token3.
     *
     * Spaces become hyphens: the gateway rejects a parameter containing one, and
     * the alternative is a message that fails for every recipient whose name is
     * two words. Silent, because there is no better answer available at send time
     * and refusing would be worse than a hyphen.
     *
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private function numbered(array $values): array
    {
        $tokens = [];

        foreach ($values as $index => $value) {
            $tokens['token'.($index === 0 ? '' : $index + 1)] = str_replace(' ', '-', $value);
        }

        return $tokens;
    }

    private function url(string $base, string $method): string
    {
        return sprintf(
            '%s/%s/%s/%s.json',
            rtrim((string) $this->config->option('url', 'https://api.kavenegar.com/v1'), '/'),
            $this->config->requireCredential('api_key'),
            $base,
            $method,
        );
    }

    /**
     * Kavenegar answers HTTP 200 even when it refused the message. The verdict is
     * inside, at return.status, and the id is under entries.
     */
    private function interpret(Response $response): SendResult
    {
        // Raw for every decision below; the sanitized copy is what gets stored.
        $payload = $this->decode($response);
        $status = (int) data_get($payload, 'return.status');

        if ($status !== self::OK) {
            /*
             * ⚠️ Not failable over.
             *
             * This provider returns a numeric status for every refusal, but this
             * package has no verified mapping from those numbers to causes — so an
             * unrecognised refusal could be an account problem another gateway
             * would not have, or a refusal of this exact message that every gateway
             * would repeat. Reading it optimistically turns one refusal into one
             * refusal per gateway, and the recipient of a message that DID get
             * through somewhere would have no way of knowing which.
             *
             * Narrowing this needs the provider's status catalogue verified, not a
             * guess.
             */
            return SendResult::rejected(
                FailureKind::GatewayRejected,
                $this->config->redact(sprintf(
                    'kavenegar %d: %s',
                    $status,
                    (string) data_get($payload, 'return.message', 'no response'),
                )),
                $this->sanitized($payload),
                safeToFailover: false,
            );
        }

        $id = data_get($payload, 'entries.0.messageid');

        return SendResult::accepted($id === null ? null : (string) $id, $this->sanitized($payload));
    }
}
