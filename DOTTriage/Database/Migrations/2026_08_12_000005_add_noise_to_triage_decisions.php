<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Record non-support mail decisions alongside routing decisions.
 *
 * Kept in the same table rather than a separate one: both are "what triage
 * decided about this conversation", and a single table means the settings
 * page can show closed-as-noise and routed counts without a union.
 */
class AddNoiseToTriageDecisions extends Migration
{
    public function up()
    {
        Schema::table('triage_decisions', function (Blueprint $table) {
            // auto_reply | bulk | system | self_sent | bounce | not_support
            $table->string('noise_category', 20)->nullable()->after('method');

            // Was the conversation closed as a result.
            $table->boolean('closed')->default(false)->after('applied');

            // Set when a human reopens something triage closed - the noise
            // equivalent of overridden_by_user_id, and the signal that a
            // detection rule is too aggressive.
            $table->integer('reopened_by_user_id')->unsigned()->nullable()->after('overridden_at');
            $table->timestamp('reopened_at')->nullable()->after('reopened_by_user_id');

            $table->index('noise_category');
            $table->index('closed');
        });
    }

    public function down()
    {
        Schema::table('triage_decisions', function (Blueprint $table) {
            $table->dropIndex(['noise_category']);
            $table->dropIndex(['closed']);
            $table->dropColumn(['noise_category', 'closed', 'reopened_by_user_id', 'reopened_at']);
        });
    }
}
