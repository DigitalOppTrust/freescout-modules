<?php

use Illuminate\Database\Migrations\Migration;
use Modules\DOTTriage\Services\ResolvedFolder;

/**
 * Adds a Resolved folder to every mailbox and populates it.
 *
 * Also repairs an older AutoCloser bug: it set `status` directly instead of
 * going through setStatus(), which skips updateFolder() - so auto-closed
 * conversations kept the folder_id they had while open and never showed up
 * in the Closed folder. Those rows get their folder re-derived here.
 */
class CreateResolvedFolder extends Migration
{
    public function up()
    {
        foreach (\App\Mailbox::pluck('id') as $mailbox_id) {
            ResolvedFolder::ensure($mailbox_id);
        }

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

        // Backfill: conversations the model already judged resolved and that
        // are still closed move into the Resolved folder.
        $resolved_ids = \DB::table('triage_decisions')
            ->join('conversations', 'conversations.id', '=', 'triage_decisions.conversation_id')
            ->where('triage_decisions.close_reason', 'resolved')
            ->where('triage_decisions.closed', true)
            ->where('conversations.status', \App\Conversation::STATUS_CLOSED)
            ->where('conversations.state', \App\Conversation::STATE_PUBLISHED)
            ->pluck('conversations.id');

        foreach ($resolved_ids as $id) {
            $conversation = \App\Conversation::find($id);
            if ($conversation) {
                ResolvedFolder::add($conversation);
            }
        }

        foreach (\App\Mailbox::all() as $mailbox) {
            $mailbox->updateFoldersCounters();
        }
    }

    public function down()
    {
        $folder_ids = \App\Folder::where('type', ResolvedFolder::TYPE)
            ->pluck('id')->toArray();

        if ($folder_ids) {
            \DB::table('conversation_folder')->whereIn('folder_id', $folder_ids)->delete();
            \App\Folder::whereIn('id', $folder_ids)->delete();
        }
    }
}
