<?php

declare(strict_types=1);

namespace Amid\Sms\Models;

use Amid\Sms\Enums\DeliveryStatus;
use Amid\Sms\Enums\MessageStatus;
use Amid\Sms\Enums\SendOutcome;
use Amid\Sms\Support\TableNames;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One logical message to one destination.
 *
 * Separate from its transport attempts on purpose: "we told this customer" and
 * "the second gateway took it after the first timed out" are different facts, and
 * a schema that merges them cannot answer either one during a support call.
 *
 * ⚠️ **Nothing is fillable.** Every column is decided by the send pipeline or
 * written back from a gateway's answer. A fillable status would be a message that
 * could be marked accepted without anything having been sent, which is the one
 * lie this table exists to make impossible.
 *
 * ⚠️ `body` and `variables` are both nullable, and since M5 that is used rather
 * than merely reserved. When `is_sensitive` is true BOTH ARE NULL: the row records
 * that a message was sent, to whom, by which gateway and with what result, and
 * deliberately does not record what it said. A one-time code written here in clear
 * text turns a delivery log into a table of live credentials, readable for as long
 * as the log is kept.
 *
 * They are NULL rather than masked. `"******"` in `variables` would look like
 * data and is not; null says plainly that the value was deliberately not kept,
 * which is the fact an auditor actually needs. The consequence is accepted and
 * intended: such a message cannot be reconstructed and re-sent from history. An
 * expired code should be re-requested, never replayed.
 */
class SmsMessage extends Model
{

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => MessageStatus::Queued->value,
        'is_sensitive' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => MessageStatus::class,
            'variables' => 'array',
            'is_sensitive' => 'boolean',
            'sent_at' => 'datetime',
            'delivery_status' => DeliveryStatus::class,
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
        return $this->table ?? TableNames::messages();
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SmsTemplate::class, 'sms_template_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(SmsAttempt::class, 'sms_message_id');
    }

    /**
     * Optional application context: the order, the ticket, whatever caused this.
     *
     * ⚠️ Optional in the strong sense. The destination number is stored on the row
     * itself, so the package can send to a bare number with no model behind it at
     * all, and nothing reads this relation to decide anything.
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeSettled(Builder $query): Builder
    {
        return $query->whereNotIn('status', [MessageStatus::Queued->value, MessageStatus::Sending->value]);
    }

    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }

    /**
     * The attempt that actually carried this message, if one did.
     *
     * ⚠️ **Only an accepted attempt can represent a delivered logical message.** A
     * failover chain can leave two refusals and one acceptance behind, and the
     * refusals have nothing to report: the provider never had the message, and
     * there is no identifier to ask about.
     *
     * ⚠️ Lowest sequence wins if there is somehow more than one. The orchestrator
     * stops the chain at the first acceptance, so a second accepted attempt means
     * data that was written by something other than this pipeline - a hand-edited
     * row, an import, a bug. Rather than guess which of them is the real one, the
     * rule is the one the orchestrator would itself have produced: the first
     * acceptance is the send, and it is deterministic, which matters more here than
     * being clever.
     */
    public function acceptedAttempt(): ?SmsAttempt
    {
        return $this->attempts()
            ->where('outcome', SendOutcome::Accepted->value)
            ->orderBy('sequence')
            ->orderBy('id')
            ->first();
    }

    /**
     * Copy the delivery verdict up from the attempt that carried this message.
     *
     * ⚠️ A mirror, not a second opinion. The monotonic rule that protects a
     * terminal verdict lives on the attempt and runs before this is called, so
     * there is exactly one place where "may this replace what we already knew" is
     * decided. Copying rather than re-deriving means the two rows can never
     * disagree about what the provider said.
     *
     * Only the neutral verdict and the confirmation time come up here. The
     * provider's own status token, its error code and the last-checked time stay on
     * the attempt that owns the identifier they belong to.
     *
     * ⚠️ `delivery_confirmed_at` is when this package obtained the verdict, not
     * when the handset received the message. No provider here publishes the latter.
     */
    public function summariseDelivery(SmsAttempt $attempt): void
    {
        $this->forceFill([
            'delivery_status' => $attempt->delivery_status,
            'delivery_confirmed_at' => $attempt->delivery_confirmed_at,
        ])->save();
    }

    /**
     * Move the row to a new state. The only writer of status.
     */
    public function transitionTo(MessageStatus $status, ?string $error = null): void
    {
        $this->forceFill([
            'status' => $status,
            'error' => $error === null ? null : mb_substr(trim($error), 0, 500),
            // Stamped only on acceptance. A failure has no send time, and inventing
            // one would put it into "sent in the last hour" counts.
            'sent_at' => $status === MessageStatus::Accepted ? ($this->sent_at ?? now()) : $this->sent_at,
        ])->save();
    }
}
