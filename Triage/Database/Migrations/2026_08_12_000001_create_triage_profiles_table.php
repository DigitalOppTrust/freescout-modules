<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Per-agent routing profile.
 *
 * One row per FreeScout user who should receive routed tickets. The
 * `description` is what the model actually reasons over, so its quality
 * determines routing quality more than the prompt or model choice does.
 */
class CreateTriageProfilesTable extends Migration
{
    public function up()
    {
        Schema::create('triage_profiles', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('user_id')->unsigned()->unique();

            // Free text: "Handles billing, invoices, refunds and payment failures."
            $table->text('description')->nullable();

            // Optional deterministic overrides, checked before the model is called.
            // Comma separated, matched case-insensitively against subject + body.
            $table->text('keywords')->nullable();

            // Who this user's tickets escalate to when unanswered.
            $table->integer('escalate_to_user_id')->unsigned()->nullable();

            // Minutes without a reply to the customer before escalating.
            // Null falls back to the module-wide default.
            $table->integer('escalate_after_minutes')->unsigned()->nullable();

            // Excluded from routing while false (leave, workload, etc).
            $table->boolean('available')->default(true);

            // Do not auto-assign beyond this many open conversations. 0 = no cap.
            $table->integer('max_open')->unsigned()->default(0);

            $table->timestamps();

            $table->index('available');
            $table->index('escalate_to_user_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('triage_profiles');
    }
}
