<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One handover of one message to one gateway, and what came back.
 *
 * Separate from the message because they are separate facts. "We told this
 * customer" is one thing; "the first gateway timed out and the second accepted it"
 * is another, and a schema that merges them can answer neither during a support
 * call. This is also the table that makes a duplicate-message investigation
 * possible at all: it records what was uncertain, not only what failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sms_message_id')->constrained('sms_messages')->cascadeOnDelete();

            /*
             * Which gateway - and, beside it, its key and driver as plain text.
             *
             * The foreign key goes null when a gateway is deleted, but the evidence
             * must not: "which provider was failing last Tuesday" has to remain
             * answerable after that provider has been removed.
             */
            $table->foreignId('sms_gateway_id')->nullable()
                ->constrained('sms_gateways')->nullOnDelete();
            $table->string('gateway_key');
            $table->string('driver');

            // 1, 2, 3... within one message. The order the gateways were tried in.
            $table->unsignedSmallInteger('sequence')->default(1);

            // How this attempt was carried: text or pattern, as it was at the time.
            $table->string('mode');

            // The provider's code for the pattern, as it was when the attempt ran.
            // Kept because a binding can be re-keyed afterwards.
            $table->string('pattern_code')->nullable();

            /*
             * accepted, rejected or uncertain - the three states of knowledge.
             *
             * uncertain is the reason this column is not a boolean. A request that
             * timed out may have been processed, and recording that as a failure is
             * how one message becomes two.
             */
            $table->string('outcome');

            // The provider-neutral classification of a failure. Null on success.
            $table->string('failure_kind')->nullable();

            /*
             * What the orchestrator is permitted to do next, as the driver judged
             * it at the time.
             *
             * Persisted rather than recomputed because it is evidence: when two
             * messages reach one customer, the question is what the code believed
             * when it made the decision, not what today's rules would say.
             */
            $table->boolean('retryable_on_same_gateway')->default(false);
            $table->boolean('safe_to_failover')->default(false);

            // The gateway's own id, which is the currency of a delivery-status
            // lookup and of a dispute with the provider.
            $table->string('provider_message_id')->nullable();

            // The reason, in the provider's words, already truncated and already
            // stripped of anything secret.
            $table->text('error')->nullable();

            /*
             * The decoded provider RESPONSE, kept for the failures nobody
             * anticipated.
             *
             * Response only. Request data is never recorded: some providers carry
             * the API key in the request URL, and a log of requests would be a log
             * of credentials.
             */
            $table->json('provider_payload')->nullable();

            /*
             * What happened AFTER the provider accepted it.
             *
             * ⚠️ Delivery lives here rather than only on the message because the
             * provider message id lives here. A report endpoint is asked about one
             * identifier, and that identifier belongs to one handover to one
             * gateway - the message may have been refused by two other gateways
             * first, and neither of those has anything to report.
             *
             * ⚠️ All nullable, and NULL MEANS "NOT TRACKED": a driver with no
             * report API leaves every one of these empty, which is not a failure
             * and not an unknown - it is an ordinary message sent through a
             * provider that does not offer delivery receipts. There is deliberately
             * no `unsupported` state to make the nullability feel tidier.
             */
            $table->string('delivery_status', 16)->nullable();

            /*
             * The provider's own word for it - `undelivered`, `2` - kept because it
             * is what a support ticket to that provider will quote, and because our
             * five neutral states deliberately lose detail.
             *
             * ⚠️ A short token only. Never the provider's prose, and never its
             * report body: those responses carry the original message text, the
             * recipient and account and billing details, and persisting one would
             * reintroduce through a reporting endpoint exactly the content a
             * sensitive message refused to store in the first place.
             */
            $table->string('provider_delivery_status', 64)->nullable();

            // The structured failure code where the provider publishes one, as a
            // string: these are identifiers, and casting one to an integer is how a
            // code with a leading zero becomes a different code.
            $table->string('delivery_error_code', 32)->nullable();

            // A short sanitized reason, for an ordinary message only. ⚠️ Null for a
            // sensitive message: no free-form provider text is persisted for those
            // at all.
            $table->text('delivery_error')->nullable();

            /*
             * When we last asked, and when a positive verdict was first obtained.
             *
             * ⚠️ The second one is NOT the carrier's delivery time, and its name
             * says so. Neither provider here publishes a handset timestamp at
             * recipient level, so the only honest thing this package can record is
             * when IT learned the message had arrived - which, with polling, can be
             * an hour after the fact. A column called `delivered_at` would be read
             * by every management screen as "the phone received it at 10:42", and
             * nobody reading that would think to doubt it.
             *
             * A provider that does publish a trustworthy carrier timestamp can be
             * given a separate `provider_delivered_at` later. There is no such
             * provider here yet, so there is no such column yet.
             */
            $table->timestamp('delivery_checked_at')->nullable();
            $table->timestamp('delivery_confirmed_at')->nullable();

            $table->timestamps();

            // "Everything that happened to this message, in order" - the only
            // question this table is ever asked.
            $table->index(['sms_message_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_attempts');
    }
};
