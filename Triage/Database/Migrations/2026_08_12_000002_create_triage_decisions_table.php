<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Audit trail of every routing decision.
 *
 * `overridden_by_user_id` is the important column: it records the model being
 * wrong. Without it there is no way to measure routing accuracy, and no basis
 * for deciding whether auto-assignment is trustworthy.
 */
class CreateTriageDecisionsTable extends Migration
{
    public function up()
    {
        Schema::create('triage_decisions', function (Blueprint $table) {
            $table->increments('id');

            $table->integer('conversation_id')->unsigned()->index();
            $table->integer('mailbox_id')->unsigned()->nullable();

            // Who the model chose. Null = no confident match.
            $table->integer('suggested_user_id')->unsigned()->nullable();

            $table->decimal('confidence', 4, 3)->nullable();
            $table->text('reasoning')->nullable();

            // 'model' | 'keyword' | 'fallback' | 'skipped'
            $table->string('method', 20)->default('model');

            $table->string('model', 64)->nullable();
            $table->integer('tokens_in')->unsigned()->nullable();
            $table->integer('tokens_out')->unsigned()->nullable();
            $table->integer('duration_ms')->unsigned()->nullable();

            // Was the suggestion acted on (auto-assigned)?
            $table->boolean('applied')->default(false);

            // Set when a human later reassigned the conversation.
            $table->integer('overridden_by_user_id')->unsigned()->nullable();
            $table->integer('overridden_to_user_id')->unsigned()->nullable();
            $table->timestamp('overridden_at')->nullable();

            // Populated when the API call failed, so failures are visible
            // rather than silently absent.
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index('created_at');
            $table->index('applied');
        });
    }

    public function down()
    {
        Schema::dropIfExists('triage_decisions');
    }
}
