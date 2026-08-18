<?php

namespace Modules\DOTTriage\Services;

use Modules\DOTTriage\Entities\TriageDecision;
use Modules\DOTTriage\Entities\TriageEscalation;

/**
 * Permanently deletes resolved conversations once they are older than the
 * retention period.
 *
 * This is the only thing in the module that destroys data, so the rules are
 * deliberately narrow:
 *
 *   - Only CLOSED conversations are eligible. Active, pending, spam and
 *     deleted-folder conversations are never touched — an open ticket is
 *     someone's unfinished work, however old it is.
 *   - The clock runs from when the ticket was closed, in calendar time.
 *     Retention is a compliance clock, not a workload one, so weekends count.
 *   - A ticket with ANY thread newer than the cutoff is skipped, even if its
 *     closed_at is old. closed_at is not reliable on conversations closed
 *     before FreeScout tracked it, and a late note should keep a ticket
 *     around for a full period anyway.
 *   - Deletion goes through FreeScout's own path,
 *     Conversation::deleteConversationsForever(), which removes threads,
 *     followers, folder links, and attachments both in the database and on
 *     disk, then fixes folder counters. A hand-rolled delete would leak
 *     attachment files and corrupt counters.
 *
 * Customer profiles are NOT deleted — a customer may have newer tickets, and
 * removing people is the GDPR module's job, done deliberately per person.
 */
class RetentionSweeper
{
    protected $dryRun;

    public function __construct($dryRun = true)
    {
        $this->dryRun = (bool) $dryRun;
    }

    /** The moment before which a closed conversation is past retention. */
    public static function cutoff()
    {
        $months = (int) Settings::get('retention_months');

        return date('Y-m-d H:i:s', strtotime("-{$months} months"));
    }

    /**
     * Query for conversations past retention.
     *
     * COALESCE because closed_at can be null on conversations closed before
     * FreeScout recorded it; last_reply_at and finally updated_at stand in.
     * The NOT EXISTS guard means any later activity — a note, a forwarded
     * message — restarts the ticket's retention clock in full.
     */
    public static function eligible($cutoff = null)
    {
        $cutoff = $cutoff ?: self::cutoff();

        return \App\Conversation::where('status', \App\Conversation::STATUS_CLOSED)
            ->whereRaw('COALESCE(closed_at, last_reply_at, updated_at) < ?', [$cutoff])
            ->whereNotExists(function ($q) use ($cutoff) {
                $q->select(\DB::raw(1))
                  ->from('threads')
                  ->whereRaw('threads.conversation_id = conversations.id')
                  ->where('threads.created_at', '>=', $cutoff);
            });
    }

    /** How many conversations are currently past retention, for the UI. */
    public static function eligibleCount()
    {
        return self::eligible()->count();
    }

    /**
     * List what a sweep would delete, oldest first, regardless of whether
     * retention is enabled — so an administrator can inspect the blast
     * radius before switching it on.
     */
    public function collect($limit = null)
    {
        $limit = $this->cap($limit);
        $rows  = [];

        foreach (self::eligible()->orderBy('id')->limit($limit)->get() as $c) {
            $closed = $c->closed_at ?: $c->last_reply_at ?: $c->updated_at;

            $rows[] = [
                'id'      => $c->id,
                'number'  => $c->number,
                'subject' => $c->subject,
                'closed'  => substr((string) $closed, 0, 10),
            ];
        }

        return $rows;
    }

    /**
     * Delete conversations past retention. Returns the rows it acted on, or
     * ['skipped' => reason] when retention is switched off.
     */
    public function sweep($limit = null)
    {
        if (!Settings::get('retention_enabled')) {
            return ['skipped' => 'Data retention is switched off in Manage → Triage.'];
        }

        $rows = $this->collect($limit);

        if (!$this->dryRun && count($rows)) {
            $this->delete(array_map(function ($r) { return $r['id']; }, $rows));
        }

        return $rows;
    }

    /** Bound a run to the configured maximum. */
    protected function cap($requested)
    {
        $max = (int) Settings::get('retention_max_per_run');

        return $requested === null ? $max : min((int) $requested, $max);
    }

    protected function delete(array $ids)
    {
        // FreeScout's own permanent-deletion path: threads, followers,
        // folder links, attachments (DB rows and files on disk), and
        // folder counter updates. It chunks internally.
        \App\Conversation::deleteConversationsForever($ids);

        // This module's own records for those conversations go too — audit
        // rows that outlive the data they describe defeat the point of
        // retention.
        TriageDecision::whereIn('conversation_id', $ids)->delete();
        TriageEscalation::whereIn('conversation_id', $ids)->delete();

        // Count and ids only. Logging subjects would re-retain the very
        // content this exists to remove.
        \Log::info('[Triage] retention: permanently deleted '.count($ids)
            .' conversation(s) closed before '.self::cutoff()
            .' (ids '.implode(',', $ids).')');
    }
}
