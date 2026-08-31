<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One logical message the system knows how to send.
 *
 * There is deliberately no send_type column. A template is a logical message, not
 * a delivery method: the same order-created may be a registered pattern at one
 * provider and free text at another, and classifying the template globally is what
 * makes that impossible to express. The mode lives on sms_template_gateways.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();

            /*
             * How code refers to this message: order-created, login-otp.
             * Technical and stable - it is written into call sites, so renaming one
             * silently stops that message being sent rather than failing loudly.
             */
            $table->string('key')->unique();

            // What an operator sees. Free to change.
            $table->string('name');

            /*
             * The wording, with {variable} placeholders.
             *
             * Kept even where a provider holds the approved text: it is what the
             * message log records as what we said, and the only copy of the wording
             * that survives changing providers.
             *
             * The variable list is NOT stored alongside it. It is derived from this
             * text on read, so the two can never disagree.
             */
            $table->text('body');

            /*
             * Whether this logical message carries something that must not be kept.
             *
             * ⚠️ A property of the WORDING, not of any one send: a login code
             * template is sensitive every time it is used, by anybody. A send may
             * additionally be forced sensitive by its caller - OTP always is - but
             * this flag can never be turned off from the call site. Security only
             * moves upward.
             *
             * One boolean, not a level. "How sensitive" is a question nothing in
             * this package would do anything different about.
             */
            $table->boolean('is_sensitive')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_templates');
    }
};
