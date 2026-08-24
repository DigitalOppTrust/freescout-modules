<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Removes the short-lived "Resolved" folder (custom folder type 65).
 *
 * The team decided Closed alone is the better fit: Resolved was not a real
 * status, and a folder that mirrors "closed by the AI/agent as done" added
 * a distinction nobody needed. Deleting the folder rows matters beyond
 * tidiness - with the module code gone, type 65 has no name or icon, so a
 * leftover row would render as a blank sidebar entry.
 */
class DropResolvedFolder extends Migration
{
    const TYPE_RESOLVED = 65;

    public function up()
    {
        $folder_ids = \App\Folder::where('type', self::TYPE_RESOLVED)
            ->pluck('id')->toArray();

        if ($folder_ids) {
            \DB::table('conversation_folder')->whereIn('folder_id', $folder_ids)->delete();
            \App\Folder::whereIn('id', $folder_ids)->delete();
        }
    }

    public function down()
    {
        // Recreating empty Resolved folders would resurrect blank sidebar
        // entries without the module code that names them; deliberately no-op.
    }
}
