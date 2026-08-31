<?php

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
        Schema::create('sms_template_gateways', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sms_template_id')->constrained('sms_templates')->cascadeOnDelete();
            $table->foreignId('sms_gateway_id')->constrained('sms_gateways')->cascadeOnDelete();

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

            $table->timestamps();

            // One binding per pairing. Two would be two answers to the question
            // "how does this message go out through this gateway".
            $table->unique(['sms_template_id', 'sms_gateway_id'], 'sms_template_gateway_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_template_gateways');
    }
};
