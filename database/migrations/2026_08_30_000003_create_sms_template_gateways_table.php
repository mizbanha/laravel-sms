<?php

use Mizbanha\Sms\Support\TableNames;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How one logical template is carried by one gateway.
 *
 * The table that makes a template a logical message rather than a provider
 * artefact. Three facts live here and nowhere else, because all three are
 * properties of the pairing rather than of either side:
 *
 *   - the mode, so the same message can be a pattern here and text there;
 *   - the code THIS provider knows the pattern by;
 *   - the names THIS provider calls the parameters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(TableNames::templateGateways(), function (Blueprint $table) {
            $table->id();

            $table->foreignId('sms_template_id')->constrained(TableNames::templates())->cascadeOnDelete();
            $table->foreignId('sms_gateway_id')->constrained(TableNames::gateways())->cascadeOnDelete();

            // text or pattern. A string, never a native enum, so adding a case is a
            // code change rather than a schema migration on every consumer - and so
            // the column behaves identically on SQLite and MySQL.
            $table->string('mode')->default('text');

            /*
             * What this provider knows the registered pattern by - one provider's
             * template name, another's numeric template id. Opaque on purpose: one
             * column holds every provider's idea of an identifier.
             *
             * Required for a pattern binding. Without it the binding is dropped by
             * the router and never quietly downgraded to free text: a pattern is
             * chosen precisely because free text would not arrive.
             */
            $table->string('pattern_code')->nullable();

            /*
             * The pattern parameters, IN ORDER:
             *
             *   [{"provider": "token",  "variable": "customer_name"},
             *    {"provider": "token2", "variable": "order_number"}]
             *
             * ⚠️ An ordered JSON ARRAY, deliberately, and not an object keyed by
             * provider parameter. MySQL normalises the key order of a JSON object
             * when it stores one - sorted by key length, then bytewise - so an
             * object could not carry position, and at a positional provider
             * position is the whole meaning. A JSON array is ordered on both MySQL
             * and SQLite.
             *
             * `provider` may be null at a provider that only counts its
             * parameters. Null for the whole column means this provider uses our
             * own names, in body order.
             */
            $table->json('parameter_map')->nullable();

            $table->boolean('is_enabled')->default(true);

            /*
             * This gateway's share of THIS message's traffic, when the template
             * routes by weight.
             *
             * ⚠️ A ratio, never a percentage. 5, 3 and 2 give three gateways half,
             * a third and a fifth of the primary selections; so do 50, 30 and 20.
             * Nothing has to add up to a hundred, which means adding a fourth
             * gateway does not require editing the other three.
             *
             * ⚠️ On the BINDING rather than on the gateway, because the question it
             * answers is per logical message: an account with a good service line
             * may be worth most of the one-time codes and none of the marketing.
             * A weight on the gateway row could only ever express one answer for
             * all traffic.
             *
             * Ignored entirely by the other two strategies. 1 is the default, so a
             * binding nobody has thought about takes an equal share.
             */
            $table->unsignedSmallInteger('weight')->default(1);

            $table->timestamps();

            /*
             * One binding per pairing. Two would be two answers to the question
             * "how does this message go out through this gateway".
             *
             * ⚠️ The index name is derived from the configured table, not written
             * out. Laravel's own generated name for these two columns would be
             * `{table}_sms_template_id_sms_gateway_id_unique` — 45 characters of
             * suffix, which overruns MySQL's 64-character identifier limit for any
             * table name longer than 19. Hence an explicit name; and hence it has
             * to follow the table, or an installation with custom names would carry
             * an index called after a table it does not have.
             */
            $table->unique(
                ['sms_template_id', 'sms_gateway_id'],
                TableNames::templateGateways().'_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableNames::templateGateways());
    }
};
