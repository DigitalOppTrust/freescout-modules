<?php

namespace Modules\DOTReports\Services;

use Illuminate\Support\Facades\DB;
use Modules\DOTTriage\Services\BusinessTime;

/**
 * Per-agent activity.
 *
 * ---------------------------------------------------------------------------
 * A caution that belongs in the code, not only in the UI copy
 * ---------------------------------------------------------------------------
 * These tables invite ranking, and ticket counts are a poor proxy for
 * contribution - one hard ticket can be worth thirty password resets. Agents
 * who take the difficult work will look slower on every column here.
 *
 * The intended use is spotting overload and imbalance, not performance
 * management. The view renders that caveat on the page; this note exists so
 * the next person to add a column knows why it is there.
 * ---------------------------------------------------------------------------
 */
class TeamReport
{
    protected $range;
    protected $mailboxId;

    public function __construct(DateRange $range, $mailboxId = null)
    {
        $this->range     = $range;
        $this->mailboxId = $mailboxId ?: null;
    }

    /**
     * One row per agent who did anything in the period.
     *
     * Built from three grouped queries plus one pass for timings, rather than
     * a query per agent. On a small server, N+1 across a staff list is the
     * kind of thing that turns a fast page slow as the team grows.
     */
    public function agents()
    {
        $names = DB::table('users')
            ->selectRaw("id, CONCAT(first_name, ' ', last_name) as name, status")
            ->get()
            ->keyBy('id');

        $replies  = $this->repliesByUser();
        $resolved = $this->resolvedByUser();
        $assigned = $this->assignedByUser();
        $timings  = $this->timingsByUser();
        $open     = $this->openByUser();

        // Union of every user id that appears in any measure, so an agent who
        // only closed tickets still gets a row.
        $ids = array_unique(array_merge(
            array_keys($replies),
            array_keys($resolved),
            array_keys($assigned),
            array_keys($open)
        ));

        $rows = [];

        foreach ($ids as $id) {
            $user = $names[$id] ?? null;

            $timing = $timings[$id] ?? ['first_response' => [], 'resolution' => []];

            $frt = Stats::summarise($timing['first_response']);
            $res = Stats::summarise($timing['resolution']);

            $rows[] = [
                'user_id'        => $id,
                'name'           => $user->name ?? ('User '.$id),
                'active'         => $user ? (int) $user->status === \App\User::STATUS_ACTIVE : false,
                'assigned'       => $assigned[$id] ?? 0,
                'replies'        => $replies[$id] ?? 0,
                'resolved'       => $resolved[$id] ?? 0,
                'open'           => $open[$id] ?? 0,
                'first_response' => $frt,
                'resolution'     => $res,
            ];
        }

        // Busiest first. Deliberately ordered by volume handled rather than
        // by any speed measure, so the table does not read as a leaderboard.
        usort($rows, function ($a, $b) {
            return $b['replies'] <=> $a['replies'];
        });

        return $rows;
    }

    /** Published agent replies per user in the period. */
    protected function repliesByUser()
    {
        // Always joins conversations so the spam / deleted / imported
        // exclusions apply here exactly as they do everywhere else. Counting
        // replies to spam would inflate an agent's row for work that the rest
        // of the module does not acknowledge exists.
        $q = DB::table('threads')
            ->join('conversations', 'conversations.id', '=', 'threads.conversation_id')
            ->whereBetween('threads.created_at', [$this->range->startSql(), $this->range->endSql()])
            ->where('threads.type', \App\Thread::TYPE_MESSAGE)
            ->where('threads.state', \App\Thread::STATE_PUBLISHED)
            ->where('threads.imported', 0)
            ->whereNotNull('threads.created_by_user_id')
            ->where('conversations.state', '!=', \App\Conversation::STATE_DELETED)
            ->where('conversations.status', '!=', \App\Conversation::STATUS_SPAM)
            ->where('conversations.imported', 0);

        if ($this->mailboxId) {
            $q->where('conversations.mailbox_id', $this->mailboxId);
        }

        return $q->selectRaw('threads.created_by_user_id as uid, COUNT(*) as total')
            ->groupBy('uid')
            ->pluck('total', 'uid')
            ->map('intval')
            ->all();
    }

    /**
     * Conversations closed per user in the period.
     *
     * Uses closed_by_user_id, which core sets on the same paths that set
     * closed_at. Conversations closed without a recorded user are counted in
     * unattributedClosures() rather than silently dropped.
     */
    protected function resolvedByUser()
    {
        $q = DB::table('conversations')
            ->whereBetween('closed_at', [$this->range->startSql(), $this->range->endSql()])
            ->where('state', '!=', \App\Conversation::STATE_DELETED)
            ->where('status', \App\Conversation::STATUS_CLOSED)
            ->where('imported', 0)
            ->whereNotNull('closed_by_user_id');

        if ($this->mailboxId) {
            $q->where('mailbox_id', $this->mailboxId);
        }

        return $q->selectRaw('closed_by_user_id as uid, COUNT(*) as total')
            ->groupBy('uid')
            ->pluck('total', 'uid')
            ->map('intval')
            ->all();
    }

    /**
     * Closures in the period with no attributable user.
     *
     * The companion to the resolution-coverage figure: if this is large, the
     * per-agent "resolved" column understates real work, and should be read
     * accordingly.
     */
    public function unattributedClosures()
    {
        $q = DB::table('conversations')
            ->whereBetween('closed_at', [$this->range->startSql(), $this->range->endSql()])
            ->where('state', '!=', \App\Conversation::STATE_DELETED)
            ->where('status', \App\Conversation::STATUS_CLOSED)
            ->where('imported', 0)
            ->whereNull('closed_by_user_id');

        if ($this->mailboxId) {
            $q->where('mailbox_id', $this->mailboxId);
        }

        return $q->count();
    }

    /** Conversations currently assigned to each user, created in the period. */
    protected function assignedByUser()
    {
        $q = DB::table('conversations')
            ->whereBetween('created_at', [$this->range->startSql(), $this->range->endSql()])
            ->where('state', '!=', \App\Conversation::STATE_DELETED)
            ->where('status', '!=', \App\Conversation::STATUS_SPAM)
            ->where('imported', 0)
            ->whereNotNull('user_id')
            ->where('user_id', '>', 0);

        if ($this->mailboxId) {
            $q->where('mailbox_id', $this->mailboxId);
        }

        return $q->selectRaw('user_id as uid, COUNT(*) as total')
            ->groupBy('uid')
            ->pluck('total', 'uid')
            ->map('intval')
            ->all();
    }

    /** Currently open conversations per user - present tense, not period. */
    protected function openByUser()
    {
        $q = DB::table('conversations')
            ->whereIn('status', [
                \App\Conversation::STATUS_ACTIVE,
                \App\Conversation::STATUS_PENDING,
            ])
            ->where('state', '!=', \App\Conversation::STATE_DELETED)
            ->where('imported', 0)
            ->whereNotNull('user_id')
            ->where('user_id', '>', 0);

        if ($this->mailboxId) {
            $q->where('mailbox_id', $this->mailboxId);
        }

        return $q->selectRaw('user_id as uid, COUNT(*) as total')
            ->groupBy('uid')
            ->pluck('total', 'uid')
            ->map('intval')
            ->all();
    }

    /**
     * First-response and resolution durations, grouped by the agent who sent
     * the first reply.
     *
     * Attributed to the FIRST responder rather than the current assignee: the
     * person who answered is the one whose responsiveness is being measured,
     * and tickets get reassigned afterwards for all sorts of reasons.
     *
     * @return array uid => ['first_response' => minutes[], 'resolution' => minutes[]]
     */
    protected function timingsByUser()
    {
        // Earliest published agent reply per conversation, as a derived
        // table. Raw rather than joinSub() - FreeScout runs Laravel 5.5,
        // which has no joinSub().
        $q = DB::table('conversations')
            ->join(DB::raw(
                '(SELECT conversation_id, MIN(created_at) as first_reply_at
                    FROM threads
                   WHERE type = '.(int) \App\Thread::TYPE_MESSAGE.'
                     AND state = '.(int) \App\Thread::STATE_PUBLISHED.'
                     AND imported = 0
                   GROUP BY conversation_id) as fr'
            ), 'fr.conversation_id', '=', 'conversations.id')
            // Re-join threads to recover who actually sent that first reply.
            ->join('threads as ft', function ($join) {
                $join->on('ft.conversation_id', '=', 'conversations.id')
                     ->whereColumn('ft.created_at', '=', 'fr.first_reply_at')
                     ->where('ft.type', '=', \App\Thread::TYPE_MESSAGE);
            })
            ->whereBetween('conversations.created_at', [
                $this->range->startSql(), $this->range->endSql(),
            ])
            ->where('conversations.state', '!=', \App\Conversation::STATE_DELETED)
            ->where('conversations.status', '!=', \App\Conversation::STATUS_SPAM)
            ->where('conversations.imported', 0)
            ->select([
                'ft.created_by_user_id as uid',
                'conversations.created_at',
                'fr.first_reply_at',
                'conversations.closed_at',
                'conversations.status',
            ]);

        if ($this->mailboxId) {
            $q->where('conversations.mailbox_id', $this->mailboxId);
        }

        $out = [];

        foreach ($q->get() as $row) {
            $uid = (int) $row->uid;

            if (!$uid) {
                continue;
            }

            if (!isset($out[$uid])) {
                $out[$uid] = ['first_response' => [], 'resolution' => []];
            }

            $created = strtotime($row->created_at);
            $replied = strtotime($row->first_reply_at);

            if ($replied >= $created) {
                $out[$uid]['first_response'][] = ($replied - $created) / 60;
            }

            if ($row->closed_at && (int) $row->status === \App\Conversation::STATUS_CLOSED) {
                $closed = strtotime($row->closed_at);

                if ($closed >= $created) {
                    $out[$uid]['resolution'][] = ($closed - $created) / 60;
                }
            }
        }

        return $out;
    }

    /**
     * Time from arrival to first assignment.
     *
     * Isolates triage lag from agent lag: a slow first response caused by a
     * ticket sitting unassigned for a day is a routing problem, not an agent
     * problem, and the two have completely different fixes.
     *
     * Derived from the USER_CHANGED line item, which records when assignment
     * actually happened - conversations.user_id only shows the current state.
     */
    public function unassignedDwell()
    {
        $q = DB::table('conversations')
            ->join(DB::raw(
                '(SELECT conversation_id, MIN(created_at) as assigned_at
                    FROM threads
                   WHERE type = '.(int) \App\Thread::TYPE_LINEITEM.'
                     AND action_type = '.(int) \App\Thread::ACTION_TYPE_USER_CHANGED.'
                   GROUP BY conversation_id) as fa'
            ), 'fa.conversation_id', '=', 'conversations.id')
            ->whereBetween('conversations.created_at', [
                $this->range->startSql(), $this->range->endSql(),
            ])
            ->where('conversations.state', '!=', \App\Conversation::STATE_DELETED)
            ->where('conversations.status', '!=', \App\Conversation::STATUS_SPAM)
            ->where('conversations.imported', 0)
            ->select(['conversations.created_at', 'fa.assigned_at']);

        if ($this->mailboxId) {
            $q->where('conversations.mailbox_id', $this->mailboxId);
        }

        $elapsed = [];
        $working = [];

        foreach ($q->get() as $row) {
            $start = new \DateTimeImmutable($row->created_at);
            $end   = new \DateTimeImmutable($row->assigned_at);

            $mins = ($end->getTimestamp() - $start->getTimestamp()) / 60;

            if ($mins < 0) {
                continue;
            }

            $elapsed[] = $mins;
            $working[] = BusinessTime::minutesBetween($start, $end);
        }

        return [
            'elapsed' => Stats::summarise($elapsed),
            'working' => Stats::summarise($working),
        ];
    }

    /**
     * Escalations broken down by the agent whose ticket escalated.
     *
     * Read as a workload signal, not a fault: the same person appearing often
     * usually means they are carrying too much, or their SLA is set too tight.
     */
    public function escalationsByUser()
    {
        if (!\Schema::hasTable('triage_escalations')) {
            return null;
        }

        $rows = DB::table('triage_escalations')
            ->whereBetween('created_at', [$this->range->startSql(), $this->range->endSql()])
            ->selectRaw('assigned_user_id as uid, COUNT(*) as total,
                         SUM(CASE WHEN notified_at IS NOT NULL THEN 1 ELSE 0 END) as notified,
                         SUM(CASE WHEN reassigned_at IS NOT NULL THEN 1 ELSE 0 END) as reassigned')
            ->groupBy('uid')
            ->orderByDesc('total')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $names = DB::table('users')
            ->selectRaw("id, CONCAT(first_name, ' ', last_name) as name")
            ->pluck('name', 'id');

        return $rows->map(function ($r) use ($names) {
            return [
                'name'       => $names[$r->uid] ?? ('User '.$r->uid),
                'total'      => (int) $r->total,
                'notified'   => (int) $r->notified,
                'reassigned' => (int) $r->reassigned,
            ];
        })->all();
    }
}
