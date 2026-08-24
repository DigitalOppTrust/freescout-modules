<?php

namespace Modules\DOTTriage\Services;

/**
 * The "Resolved" folder.
 *
 * Closed and Resolved mean different things on this help desk. Closed is
 * manual: an agent shut the ticket, often because it was irrelevant. Resolved
 * is earned: an agent replied, the customer went quiet, and the model judged
 * the exchange finished (AutoCloser's resolved pass).
 *
 * FreeScout has no custom statuses, so this is an "indirect" folder -
 * membership lives in the core conversation_folder pivot, the same mechanism
 * Drafts and Starred use. The conversation itself stays CLOSED, which keeps
 * every core behaviour intact: a customer reply reopens it, retention treats
 * it like any other closed ticket. The pivot row is removed whenever the
 * conversation stops being closed, so the folder only ever shows tickets that
 * are both closed and model-judged resolved.
 */
class ResolvedFolder
{
    /**
     * Core folder types: Closed = 60, Deleted = 70. The sidebar orders
     * folders by type, so 65 places Resolved directly under Closed.
     */
    const TYPE = 65;

    /** Wire the folder type into FreeScout's folder system. */
    public static function register()
    {
        \App\Folder::$types[self::TYPE] = 'Resolved';

        // Public: visible to every user of the mailbox, and included when
        // core creates folders for a new mailbox. Indirect: membership via
        // the conversation_folder pivot rather than conversations.folder_id.
        if (!in_array(self::TYPE, \App\Folder::$public_types)) {
            \App\Folder::$public_types[] = self::TYPE;
        }
        if (!in_array(self::TYPE, \App\Folder::$indirect_types)) {
            \App\Folder::$indirect_types[] = self::TYPE;
        }

        \Eventy::addFilter('folder.type_name', function ($name, $folder) {
            return (int) $folder->type === self::TYPE ? 'Resolved' : $name;
        }, 20, 2);

        \Eventy::addFilter('folder.type_icon', function ($icon, $folder) {
            return (int) $folder->type === self::TYPE ? 'ok' : $icon;
        }, 20, 2);

        \Eventy::addFilter('folder.conversations_order_by', function ($order_by, $type) {
            return (int) $type === self::TYPE ? [['closed_at' => 'desc']] : $order_by;
        }, 20, 2);

        // A resolved ticket lives in ONE folder. Its status is still CLOSED,
        // so without this it would be listed in Closed as well.
        \Eventy::addFilter('folder.conversations_query', function ($query, $folder, $user_id) {
            if ((int) $folder->type !== \App\Folder::TYPE_CLOSED) {
                return $query;
            }

            $resolved = self::folder($folder->mailbox_id);
            if (!$resolved) {
                return $query;
            }

            return $query->whereNotExists(function ($q) use ($resolved) {
                $q->select(\DB::raw(1))
                  ->from('conversation_folder')
                  ->whereRaw('conversation_folder.conversation_id = conversations.id')
                  ->where('conversation_folder.folder_id', $resolved->id);
            });
        }, 20, 3);
    }

    /** The mailbox's Resolved folder row, or null. */
    public static function folder($mailbox_id)
    {
        return \App\Folder::where('mailbox_id', $mailbox_id)
            ->where('type', self::TYPE)
            ->first();
    }

    /** The mailbox's Resolved folder row, created if missing. */
    public static function ensure($mailbox_id)
    {
        $folder = self::folder($mailbox_id);

        if (!$folder) {
            $folder = new \App\Folder();
            $folder->mailbox_id = $mailbox_id;
            $folder->type = self::TYPE;
            $folder->save();
        }

        return $folder;
    }

    /**
     * Put a conversation in the Resolved folder.
     *
     * Query builder, not the ConversationFolder model - the pivot table has
     * no timestamp columns and the model does not switch timestamps off.
     */
    public static function add($conversation)
    {
        $folder = self::ensure($conversation->mailbox_id);

        $exists = \DB::table('conversation_folder')
            ->where('folder_id', $folder->id)
            ->where('conversation_id', $conversation->id)
            ->exists();

        if (!$exists) {
            \DB::table('conversation_folder')->insert([
                'folder_id'       => $folder->id,
                'conversation_id' => $conversation->id,
            ]);
        }

        $folder->updateCounters();
    }

    /** Take a conversation out of the Resolved folder. Safe to call blind. */
    public static function remove($conversation)
    {
        $folder = self::folder($conversation->mailbox_id);
        if (!$folder) {
            return;
        }

        $deleted = \DB::table('conversation_folder')
            ->where('folder_id', $folder->id)
            ->where('conversation_id', $conversation->id)
            ->delete();

        if ($deleted) {
            $folder->updateCounters();
        }
    }
}
