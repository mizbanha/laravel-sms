<?php

use Amid\Sms\Support\TableNames;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One logical message to one destination.
 *
 * One row per recipient, never per batch. It is the answer to "was this person
 * told", and a row covering ten thousand numbers cannot answer it for any of them.
 *
 * Note what is NOT here: no customer id, no user id, no branch. The package does
 * not know what an application's models are, and a message must be sendable when
 * no model exists at all. The destination itself is the identity of the row; the
 * optional morph below is context, and nothing reads it to make a decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(TableNames::messages(), function (Blueprint $table) {
            $table->id();

            // Which wording. Null once a template is deleted - the body below is
            // then the surviving record of what was said.
            $table->foreignId('sms_template_id')->nullable()
                ->constrained(TableNames::templates())->nullOnDelete();

            /*
             * The destination, canonical, in E.164 with its leading plus:
             * +989121234567.
             *
             * Stored normalised so that "what did we send to this number" is one
             * query rather than a search across six spellings of one phone. 20
             * characters covers E.164's maximum of 15 digits plus the plus sign
             * with room to spare.
             */
            $table->string('to', 20);

            /*
             * The destination's country, ISO 3166-1 alpha-2, derived once from the
             * number above when the message was recorded.
             *
             * A snapshot rather than something re-derived at each step. The country
             * decides which gateways are offered the message, and a routing input
             * that is recomputed in three places is a routing input that can differ
             * between them - most obviously when a message is retried later and the
             * numbering data has moved on underneath it. Written once, read
             * everywhere, and visible in the log beside the decision it drove.
             *
             * ⚠️ Nullable, and null is a real value: a valid non-geographic number
             * belongs to no country and this package does not invent one for it.
             */
            $table->string('country_code', 2)->nullable();

            /*
             * How this message was actually handled, snapshotted at send time.
             *
             * ⚠️ A snapshot rather than a join to the template, deliberately.
             * Marking a template sensitive next month must not retroactively claim
             * that last month's messages were handled that way - and unmarking one
             * must not suggest that a body which was never stored ought to be
             * there. This column is the record of what the pipeline did, and a
             * management screen reads it to know that a "retry from history" is
             * impossible for this row.
             */
            $table->boolean('is_sensitive')->default(false);

            // queued, sending, accepted, delivered, failed, suppressed, unknown.
            $table->string('status')->default('queued');

            /*
             * What was said, and the values that filled it in.
             *
             * Both nullable, and that is a deliberate schema commitment rather than
             * an accident of the current feature set. A future sensitive template
             * must be able to record that a message was sent, to whom, through
             * which gateway and with what result, while storing neither the wording
             * nor the values - a one-time code written here in clear text turns a
             * delivery log into a table of live credentials, readable for as long
             * as the log is kept. Nothing may come to depend on either column being
             * present.
             */
            $table->text('body')->nullable();
            $table->json('variables')->nullable();

            // Why the message as a whole ended where it did, in the provider's
            // words. The per-attempt detail is on sms_attempts.
            $table->text('error')->nullable();

            /*
             * Optional application context: the order, the ticket, the campaign.
             *
             * Morph rather than a column per cause, and nullable rather than
             * required, because the package must be able to send to a bare phone
             * number with nothing behind it.
             */
            $table->nullableMorphs('reference');

            /*
             * The gateway this message was routed to FIRST, recorded before the
             * first provider call.
             *
             * ⚠️ Intent, not evidence. An attempt row says a provider was
             * contacted; this column says only where the routing strategy pointed.
             * The two can differ - the chosen gateway may have been circuit-open by
             * the time it was reached - and when they do, the attempts are the
             * truth about what happened.
             *
             * It exists because round-robin distributes NEW logical messages, and a
             * queued job that Laravel releases and runs again is the same logical
             * message. Without a record, the retry would take whatever slot the
             * shared cursor had reached in the meantime, so ten unrelated messages
             * could move this one from its first gateway to a completely different
             * one between two runs of the same job - a routing decision made by
             * other people's traffic. The retry reads this column instead, and
             * takes no new slot.
             *
             * ⚠️ It does not freeze the candidate list. A gateway enabled since the
             * first run still joins the chain behind this one, which is the M2
             * behaviour and worth keeping: newly available infrastructure should be
             * able to rescue a message that is still unsettled.
             *
             * Null for a message that never reached routing - suppressed by the
             * master switch, or with no template left - and null on every message
             * of a `priority` template, which needs no such record because its
             * order is the same on every run by construction.
             */
            $table->foreignId('routing_gateway_id')->nullable()
                ->constrained(TableNames::gateways())->nullOnDelete();

            // When a gateway accepted it. Null for everything else, including
            // messages whose fate is unknown.
            $table->timestamp('sent_at')->nullable();

            /*
             * The delivery summary, following the accepted attempt.
             *
             * ⚠️ A summary, not a second source of truth. The detail - the
             * provider's own status, its error code, when it was last asked - stays
             * on the attempt that owns the provider message id; only the neutral
             * verdict and the confirmation time are copied here.
             *
             * It exists because "was this message delivered" is the question a
             * management screen asks about ten thousand rows at once, and answering
             * it by reconstructing the winning attempt for each of them is a join
             * and a sort per row. Indexed below for the same reason.
             *
             * ⚠️ Null means not tracked: the driver that carried it cannot report
             * delivery. Reading this column never contacts a provider.
             */
            $table->string('delivery_status', 16)->nullable();
            // ⚠️ When this package first obtained a delivered verdict, not when the
            // handset received the message. See the attempts table.
            $table->timestamp('delivery_confirmed_at')->nullable();

            $table->timestamps();

            // "What is stuck, what failed" - the log screen's default view.
            $table->index(['status', 'created_at']);

            // "What did we send to this number", including numbers with no
            // application record behind them.
            $table->index('to');

            // "What is still waiting on a delivery receipt" - the query a future
            // management or monitoring layer runs to decide what to poll. Core does
            // not poll anything itself; it makes the question cheap to ask.
            $table->index(['delivery_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableNames::messages());
    }
};
