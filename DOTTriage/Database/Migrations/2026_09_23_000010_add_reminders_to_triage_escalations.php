<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Reminders to the assignee before a ticket escalates.
 *
 * Once the reply window runs out the assignee is reminded `reminder_count`
 * times, `reminder_interval_minutes` of working time apart, and the ticket
 * escalates one interval after the last reminder.
 *
 * Count and interval are copied onto the row when the clock starts, like
 * the window itself, so changing the settings never retimes a running clock.
 * Existing rows get 0 reminders and keep the rule they started under.
 */
class AddRemindersToTriageEscalations extends Migration
{
    public function up()
    {
        Schema::table('triage_escalations', function (Blueprint $table) {
            $table->integer('reminder_count')->unsigned()->default(0)->after('escalate_to_user_id');
            $table->integer('reminder_interval_minutes')->unsigned()->default(0)->after('reminder_count');
            $table->integer('reminders_sent')->unsigned()->default(0)->after('reminder_interval_minutes');
            $table->timestamp('last_reminded_at')->nullable()->after('reminders_sent');
        });
    }

    public function down()
    {
        Schema::table('triage_escalations', function (Blueprint $table) {
            $table->dropColumn(['reminder_count', 'reminder_interval_minutes', 'reminders_sent', 'last_reminded_at']);
        });
    }
}
