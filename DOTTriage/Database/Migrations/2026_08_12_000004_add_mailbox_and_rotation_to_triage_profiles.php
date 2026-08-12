<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Per-mailbox profiles, plus round-robin support.
 *
 * Routing becomes two stages: the model picks the best *description* match,
 * then the module picks a *person* among agents sharing that description by
 * least-recently-assigned rotation. The model has no view of who is busy, so
 * asking it to load-balance would be both unreliable and non-deterministic.
 */
class AddMailboxAndRotationToTriageProfiles extends Migration
{
    public function up()
    {
        Schema::table('triage_profiles', function (Blueprint $table) {
            // Null = applies to every mailbox (useful while there is only one).
            $table->integer('mailbox_id')->unsigned()->nullable()->after('user_id');

            // Agents sharing a group are rotated between when the model picks
            // that group. Null = not part of a rotation, routed individually.
            $table->string('rotation_group', 64)->nullable()->after('keywords');

            // Least-recently-assigned wins the next ticket in the group.
            $table->timestamp('last_assigned_at')->nullable()->after('rotation_group');

            $table->index(['mailbox_id', 'available']);
            $table->index('rotation_group');
        });

        // A user can now have a different profile per mailbox.
        Schema::table('triage_profiles', function (Blueprint $table) {
            $table->dropUnique('triage_profiles_user_id_unique');
            $table->unique(['user_id', 'mailbox_id'], 'triage_profiles_user_mailbox_unique');
        });
    }

    public function down()
    {
        Schema::table('triage_profiles', function (Blueprint $table) {
            $table->dropUnique('triage_profiles_user_mailbox_unique');
            $table->dropIndex(['mailbox_id', 'available']);
            $table->dropIndex(['rotation_group']);
            $table->dropColumn(['mailbox_id', 'rotation_group', 'last_assigned_at']);
            $table->unique('user_id');
        });
    }
}
