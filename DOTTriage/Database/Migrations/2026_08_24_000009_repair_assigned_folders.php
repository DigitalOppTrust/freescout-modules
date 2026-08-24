<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Re-derives the folder for every published conversation.
 *
 * Triage's assign() used to set user_id and save without updateFolder(),
 * the same skipped-step that once kept auto-closed conversations out of
 * the Closed folder - so triage-assigned tickets stayed filed under
 * Unassigned. Human assignments were unaffected because core's
 * changeUser() updates the folder. Rather than patching just the known
 * misfiled rows, recompute all of them; updateFolder() is authoritative
 * and idempotent.
 */
class RepairAssignedFolders extends Migration
{
    public function up()
    {
        $conversations = \App\Conversation::where('state', \App\Conversation::STATE_PUBLISHED)->get();

        foreach ($conversations as $conversation) {
            $before = $conversation->folder_id;
            $conversation->updateFolder();
            if ($conversation->folder_id != $before) {
                $conversation->save();
            }
        }

        foreach (\App\Mailbox::all() as $mailbox) {
            $mailbox->updateFoldersCounters();
        }
    }

    public function down()
    {
        // The repair is not reversible, and should not be.
    }
}
