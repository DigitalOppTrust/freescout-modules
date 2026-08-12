<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Escalation tracking, one row per conversation currently on the clock.
 *
 * Two-stage by design: the escalation target is notified first, and ownership
 * only transfers if the ticket is still unanswered after a second window.
 * `depth` and `chain` bound the hop count and make loops detectable.
 */
class CreateTriageEscalationsTable extends Migration
{
    public function up()
    {
        Schema::create('triage_escalations', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('conversation_id')->unsigned()->unique();
            $table->integer('assigned_user_id')->unsigned();

            // When the clock started (assignment, or last agent reply).
            $table->timestamp('clock_started_at')->nullable();

            // Resolved from the profile at assignment time, so later profile
            // edits do not retroactively change tickets already on the clock.
            $table->integer('escalate_after_minutes')->unsigned();
            $table->integer('escalate_to_user_id')->unsigned()->nullable();

            // Stage 1 - target notified, assignee keeps ownership.
            $table->timestamp('notified_at')->nullable();

            // Stage 2 - ownership transferred after a second window.
            $table->timestamp('reassigned_at')->nullable();

            // How many hops taken so far (0 = original assignee).
            $table->integer('depth')->unsigned()->default(0);

            // Comma separated user ids already escalated through, so the same
            // person is never escalated to twice in one chain.
            $table->string('chain', 255)->nullable();

            // Cleared when the assignee replies to the customer.
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->index(['active', 'clock_started_at']);
            $table->index('assigned_user_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('triage_escalations');
    }
}
