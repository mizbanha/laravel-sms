<?php

use Amid\Sms\Support\TableNames;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The gateways this installation can send through.
 *
 * In the database rather than in config because enabling a gateway, reordering two
 * of them, or replacing an expired API key are all things an operator does at
 * runtime, and none of them should require a deployment. What stays in config is
 * the map of driver names to driver CLASSES: a new provider is code, and code
 * arrives by deployment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(TableNames::gateways(), function (Blueprint $table) {
            $table->id();

            // How everything else refers to this gateway, e.g. kavenegar-main.
            // Stable and technical; it is snapshotted onto every attempt.
            $table->string('key')->unique();

            // What an operator sees in a list. Free to change, unlike the key.
            $table->string('label');

            /*
             * Which driver carries it - a key of config('laravel-sms.drivers'), not a class
             * name. A class name in the database is a class name that gets renamed
             * by a refactor and silently stops resolving.
             */
            $table->string('driver');

            // The line messages go out from, where the provider has the concept.
            $table->string('sender')->nullable();

            /*
             * Encrypted at rest (encrypted:array on the model) and hidden from
             * every serialisation of the model.
             *
             * A JSON blob rather than a column per credential because no two
             * providers want the same set - one wants an API key, another a
             * username and a password - and a column per provider would mean a
             * migration every time a gateway is added.
             */
            $table->text('credentials')->nullable();

            // Non-secret provider settings: a base URL override, an endpoint choice.
            $table->json('options')->nullable();

            /*
             * Defaults to false, deliberately. A gateway that is created - by a
             * seeder, by a restored database, by a half-finished form - must not
             * begin carrying real traffic because somebody forgot a step.
             */
            $table->boolean('is_enabled')->default(false);

            // Lower goes first. The router's ordering, and the whole of what
            // "gateway priority" means.
            $table->unsignedInteger('priority')->default(100);

            /*
             * Where this gateway is meant to send.
             *
             * ⚠️ Gateway columns rather than a corner of `options`, because this is
             * generic routing behaviour that the router reads for every gateway,
             * not a provider-specific setting only one driver understands. No
             * driver knows this exists.
             *
             *   country_policy = all    countries = []                  everywhere
             *   country_policy = allow  countries = ["IR"]              only Iran
             *   country_policy = deny   countries = ["IR","SY","CU"]    all but those
             *
             * `countries` is a JSON array of ISO 3166-1 alpha-2 codes, uppercase,
             * unique, validated on write against the regions a destination can
             * actually be classified into - so `UK`, which is not an ISO code, is
             * refused rather than silently matching nothing forever.
             *
             * This is COVERAGE, not permission. What a provider's account will
             * actually accept is a separate runtime fact that arrives as a
             * structured refusal and fails over normally.
             */
            $table->string('country_policy', 8)->default('all');
            $table->json('countries')->nullable();

            $table->timestamps();

            // The router's query: enabled gateways, best first.
            $table->index(['is_enabled', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableNames::gateways());
    }
};
