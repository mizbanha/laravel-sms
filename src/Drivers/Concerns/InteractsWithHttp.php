<?php

declare(strict_types=1);

namespace Amid\Sms\Drivers\Concerns;

use Amid\Sms\Enums\FailureKind;
use Amid\Sms\Exceptions\GatewayNotConfigured;
use Amid\Sms\Results\SendResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The HTTP behaviour every driver shares, and - more importantly - the transport
 * half of the failure classification.
 *
 * Every driver has to answer the same three transport questions before it gets to
 * anything provider-specific, and getting them wrong is how duplicates happen. So
 * they are answered once, here, and a driver only interprets what its provider
 * actually said.
 */
trait InteractsWithHttp
{
    /**
     * No retry policy here on purpose.
     *
     * Retrying inside the driver would make one logical attempt into several
     * invisible ones, and the second of them could be the duplicate nobody can
     * explain. Retry is the orchestrator's decision, taken on a result that says
     * whether it is safe.
     */
    protected function http(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout((int) config('sms.http.timeout', 15))
            ->connectTimeout((int) config('sms.http.connect_timeout', 5));
    }

    /**
     * Run a request and classify anything that is not the provider talking.
     *
     * @param  callable(): Response  $request
     * @param  callable(Response): SendResult  $interpret  called only when the
     *                                                     provider actually answered
     */
    protected function perform(callable $request, callable $interpret): SendResult
    {
        try {
            $response = $request();
        } catch (ConnectionException $exception) {
            /*
             * The request did not complete. It may never have left, or it may have
             * been received and processed with the answer lost on the way back -
             * and Laravel surfaces a refused connection and a read timeout as the
             * same exception, with only the message text telling them apart.
             *
             * Reading that text to decide is exactly what the architecture forbids,
             * and guessing wrong in the optimistic direction sends the message
             * twice. So this is uncertain, which is never automatically re-sent.
             *
             * The exception message is deliberately NOT used: it contains the
             * request URL, and some providers put the API key in the URL.
             */
            return SendResult::uncertain(
                FailureKind::Network,
                'The gateway could not be reached, or did not answer in time. Whether it received the message is not known.',
            );
        } catch (GatewayNotConfigured $exception) {
            /*
             * ⚠️ Deliberately re-thrown, not swallowed.
             *
             * Drivers resolve their credentials while BUILDING the request, so this
             * arrives from inside the closure above and would otherwise be caught
             * by the generic handler below and recorded as an uncertain network
             * failure - which stops the failover chain and settles the message as
             * `unknown`. A gateway with a missing credential never contacted
             * anybody: nothing is uncertain about it, and a healthy second gateway
             * should carry the message. The dispatcher already classifies this
             * exception correctly, so it is allowed to reach it.
             */
            throw $exception;
        } catch (Throwable $exception) {
            // Anything else from the HTTP layer. Same reasoning: unknown, and the
            // message is scrubbed of credentials before it is recorded.
            return SendResult::uncertain(
                FailureKind::Network,
                $this->config->redact($exception::class.': '.$exception->getMessage()),
            );
        }

        return $this->classify($response) ?? $interpret($response);
    }

    /**
     * The verdicts that can be reached from the HTTP status alone.
     *
     * Null means "the provider answered something worth reading" and hands over to
     * the driver.
     */
    protected function classify(Response $response): ?SendResult
    {
        $status = $response->status();

        if ($status === 429) {
            /*
             * Rate limited. Definitively not processed - that is what the status
             * means - so it is a rejection rather than an uncertainty, it is worth
             * trying again on this gateway once the window passes, and another
             * gateway can take it immediately.
             */
            return SendResult::rejected(
                FailureKind::ProviderUnavailable,
                'The gateway is rate limiting requests.',
                $this->sanitized($this->decode($response)),
                retryableOnSameGateway: true,
                safeToFailover: true,
            );
        }

        if ($status >= 500) {
            /*
             * A server error means the request arrived. Whether it was processed
             * before things went wrong is not knowable from here, and assuming it
             * was not is how a customer receives two of the same message.
             */
            return SendResult::uncertain(
                FailureKind::ProviderUnavailable,
                sprintf('The gateway answered %d. Whether it accepted the message is not known.', $status),
                $this->sanitized($this->decode($response)),
            );
        }

        if ($status === 401 || $status === 403) {
            // Credentials, or an account that is not permitted to do this. Nothing
            // about this message is wrong, so another gateway should have it, but
            // repeating it here will produce the same answer forever.
            return SendResult::rejected(
                FailureKind::GatewayConfiguration,
                sprintf('The gateway rejected our credentials (%d).', $status),
                $this->sanitized($this->decode($response)),
            );
        }

        return null;
    }

    /**
     * The decoded response body, exactly as the provider sent it.
     *
     * ⚠️ **Every decision is made from THIS, never from the redacted copy.**
     *
     * That separation is the whole point and it was got wrong until M5. Redaction
     * is a substring replacement over each configured credential value, so a short
     * credential rewrites unrelated text - a gateway whose password happened to be
     * `u` turned the message id `SMcountryrouted0001` into
     * `SMco[redacted]ntryro[redacted]ted0001`. Reading a provider message id, a
     * status or an error code out of that is reading data a security transform has
     * been allowed to edit.
     *
     * So: parse from `decode()`, persist `sanitized()`. Never the other way round.
     * The fix is not a minimum credential length - a two-character credential is
     * still a credential and must still be scrubbed aggressively.
     *
     * @return array<string, mixed>|null
     */
    protected function decode(Response $response): ?array
    {
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The same body with every configured credential removed: the copy that is
     * allowed to leave process memory.
     *
     * The attempt payload is persisted and kept for as long as the log is, and a
     * provider that echoes the request back - or quotes it inside a validation
     * error - would otherwise write a live credential into it. Nothing reads the
     * result of this to decide anything.
     *
     * @param  array<string, mixed>|null  $raw
     * @return array<string, mixed>|null
     */
    protected function sanitized(?array $raw): ?array
    {
        return $raw === null ? null : $this->config->redactArray($raw);
    }
}
