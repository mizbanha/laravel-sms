<?php

declare(strict_types=1);

namespace Amid\Sms\Models;

use Amid\Sms\Enums\DeliveryMode;
use Amid\Sms\Enums\DeliveryStatus;
use Amid\Sms\Enums\FailureKind;
use Amid\Sms\Enums\SendOutcome;
use Amid\Sms\Support\TableNames;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One handover of one message to one gateway, and what came back.
 *
 * This is the row that answers "why did the customer get two messages" and "which
 * provider was down on Tuesday". It stores the gateway key and driver as text as
 * well as the foreign key, because a gateway that is later renamed or deleted must
 * not take last month's evidence with it.
 *
 * ⚠️ `provider_payload` holds the decoded provider RESPONSE only. Request data is
 * never recorded here: some providers carry the API key in the request URL, and a
 * log of requests would be a log of credentials.
 *
 * ⚠️ **For a sensitive message, `error` and `provider_payload` are both null.**
 * Not scrubbed, not masked - absent. Everything an investigation actually needs is
 * structured and still here: the outcome, the failure kind, both policy flags, the
 * gateway, the driver, the sequence and the provider's message id. What is gone is
 * the provider's prose, which is the only part that can quote a one-time code back
 * at us.
 *
 * ⚠️ The `delivery_*` columns describe what happened AFTER acceptance and are
 * written only by an explicit refresh. Null throughout means the driver that
 * carried this attempt cannot report delivery - an ordinary state, not a failure.
 */
class SmsAttempt extends Model
{

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'mode' => DeliveryMode::class,
            'outcome' => SendOutcome::class,
            'failure_kind' => FailureKind::class,
            'retryable_on_same_gateway' => 'boolean',
            'safe_to_failover' => 'boolean',
            'provider_payload' => 'array',
            'delivery_status' => DeliveryStatus::class,
            'delivery_checked_at' => 'datetime',
            'delivery_confirmed_at' => 'datetime',
        ];
    }

    /**
     * ⚠️ Resolved on every query rather than declared in `$table`, so a configured
     * name reaches relations, eager loads and queued jobs. See `SmsGateway` for the
     * full reasoning, and `Amid\Sms\Support\TableNames` for the map.
     */
    public function getTable(): string
    {
        return $this->table ?? TableNames::attempts();
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(SmsMessage::class, 'sms_message_id');
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(SmsGateway::class, 'sms_gateway_id');
    }

    /**
     * Whether the provider took responsibility for this handover.
     *
     * ⚠️ The precondition for every delivery lookup in this package. A rejected
     * attempt has nothing to report - the provider never had the message - and an
     * uncertain one has no identifier to ask about.
     */
    public function isAccepted(): bool
    {
        return $this->outcome === SendOutcome::Accepted;
    }

    /**
     * Write down what a provider currently says about this attempt's delivery.
     *
     * The only writer of the delivery columns, so the monotonic rule lives in
     * exactly one place. Returns whether the verdict actually changed.
     *
     * ⚠️ `delivery_checked_at` is stamped even when the verdict is rejected as
     * stale, because "when did we last ask" is true either way and a poller needs
     * it.
     *
     * ⚠️ `delivery_confirmed_at` is stamped once, when a delivered verdict is first
     * obtained, and it is named for what it actually is: the moment THIS PACKAGE
     * learned the message had arrived. With polling that can be an hour after the
     * handset received it. It is not `delivered_at`, because a column called that
     * would be shown as "delivered at 10:42" by every management screen ever built
     * on it, and nobody would think to doubt it.
     */
    public function applyDelivery(DeliveryStatus $status, ?string $providerStatus = null, ?string $errorCode = null, ?string $error = null): bool
    {
        $current = $this->delivery_status;
        $accepted = DeliveryStatus::mayReplace($current, $status);

        $this->forceFill([
            'delivery_status' => $accepted ? $status : $current,
            'provider_delivery_status' => $accepted ? $providerStatus : $this->provider_delivery_status,
            'delivery_error_code' => $accepted ? $errorCode : $this->delivery_error_code,
            'delivery_error' => $accepted ? ($error === null ? null : mb_substr(trim($error), 0, 500)) : $this->delivery_error,
            'delivery_checked_at' => now(),
            'delivery_confirmed_at' => $accepted && $status === DeliveryStatus::Delivered
                ? ($this->delivery_confirmed_at ?? now())
                : $this->delivery_confirmed_at,
        ])->save();

        return $accepted && $current !== $status;
    }
}
