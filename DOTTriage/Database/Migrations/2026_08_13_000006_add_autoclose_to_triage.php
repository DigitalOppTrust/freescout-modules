<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Records why a conversation was closed automatically, and lets a human
 * disagree.
 *
 * `reopened_by_user_id` already exists for noise closures. This adds the
 * close-reason so the three mechanisms - noise, inactivity, and AI resolution
 * judgement - can be told apart when reviewing whether auto-closing is
 * behaving. Without that, a wrongly-closed ticket looks the same as a
 * correctly-closed newsletter.
 */
class AddAutocloseToTriage extends Migration
{
    public function up()
    {
        Schema::table('triage_decisions', function (Blueprint $table) {
            // noise | inactivity | resolved | backlog_noise
            $table->string('close_reason', 20)->nullable()->after('closed');
            $table->index('close_reason');
        });
    }

    public function down()
    {
        Schema::table('triage_decisions', function (Blueprint $table) {
            $table->dropIndex(['close_reason']);
            $table->dropColumn('close_reason');
        });
    }
}
