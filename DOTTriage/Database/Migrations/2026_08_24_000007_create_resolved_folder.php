<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Historically this migration also created a "Resolved" folder (custom
 * folder type 65) per mailbox; that feature was removed the same day and
 * the next migration drops the folders again. What remains is the useful
 * part: repairing an older AutoCloser bug - it set `status` directly
 * instead of going through setStatus(), which skips updateFolder(), so
 * auto-closed conversations kept the folder_id they had while open and
 * never showed up in the Closed folder.
 */
class CreateResolvedFolder extends Migration
{
    public function up()
    {
        // Repair: closed conversations still sitting in a non-Closed folder.
        $closed_folder_ids = \App\Folder::where('type', \App\Folder::TYPE_CLOSED)
            ->pluck('id')->toArray();

        $stale = \App\Conversation::where('status', \App\Conversation::STATUS_CLOSED)
            ->where('state', \App\Conversation::STATE_PUBLISHED)
            ->where(function ($q) use ($closed_folder_ids) {
                $q->whereNotIn('folder_id', $closed_folder_ids)
                  ->orWhereNull('folder_id');
            })
            ->get();

        foreach ($stale as $conversation) {
            $conversation->updateFolder();
            $conversation->save();
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
