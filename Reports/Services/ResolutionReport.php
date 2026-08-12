<?php

namespace Modules\Reports\Services;

use Illuminate\Support\Facades\DB;
use Modules\Triage\Services\BusinessTime;

/**
 * How long issues take to answer and to resolve.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS CLASS IS MORE COMPLICATED THAN IT LOOKS
 * ---------------------------------------------------------------------------
 * The obvious query - closed_at minus created_at - cannot be trusted on its
 * own, and when it fails it fails in a direction that flatters.
 *
 * FreeScout core only stamps closed_at when a $user object is passed to
 * Conversation::setStatus():
 *
 *     if ($user && $status == self::STATUS_CLOSED) {
 *         $this->closed_by_user_id = $user->id;
 *         $this->closed_at = $now;
 *     }
 *
 * An audit of every closed_at write path in core found the ordinary UI routes
 * ARE safe - the reply path sets it inline, and bulk close and the mailbox
 * "status after replying" setting both pass a user. The real gaps are:
 *
 *   - Conversation::create() copies $data['closed_at'] ?? null, so imported
 *     and API-created conversations often carry nothing.
 *   - Any module or workflow calling setStatus() without a user, which the
 *     signature invites since $user defaults to null.
 *
 * So closed_at is usually present but not guaranteed, and the missing rows
 * are not a random sample - automated and imported closes skew fast, so a
 * naive median skews slow. The honest response is not to guess the rate but
 * to measure and publish it, which is what coverage reporting below does.
 *
 * Mitigation is three-part:
 *   1. Use closed_at when present.
 *   2. Fall back to the newest LINEITEM / STATUS_CHANGED thread, which
 *      records the transition even when the column was not stamped.
 *   3. REPORT THE COVERAGE. Every figure carries how many conversations it
 *      could actually time. A metric that hides its own blind spot is worse
 *      than no metric, because it gets trusted.
 *
 * Similarly, conversations.last_reply_at is NOT used anywhere here. Its
 * meaning changes with the app.waiting_since_as_first_unanswered_customer_message
 * config flag, and last_customer_reply_at is documented as unindexed. Both are
 * FreeScout's folder-sorting machinery, not a reporting API. Every timing
 * below is derived from the threads table.
 * ---------------------------------------------------------------------------
 */
class ResolutionReport
{
    protected $range;
    protected $mailboxId;

    public function __construct(DateRange $range, $mailboxId = null)
    {
        $this->range     = $range;
        $this->mailboxId = $mailboxId ?: null;
    }

    /**
     * Conversations created in the period, with a resolved timestamp worked
     * out per the fallback chain above.
     *
     * Returns one row per conversation with:
     *   id, created_at, closed_at, lineitem_closed_at, status
     */
    protected function resolvedRows()
    {
        // The fallback source: the most recent status-change line item per
        // conversation. Joined as a subquery so this stays one round trip.
        $lineitems = DB::table('threads')
            ->selectRaw('conversation_id, MAX(created_at) as lineitem_closed_at')
            ->where('type', \App\Thread::TYPE_LINEITEM)
            ->where('action_type', \App\Thread::ACTION_TYPE_STATUS_CHANGED)
            ->groupBy('conversation_id');

        $q = DB::table('conversations')
            ->leftJoinSub($lineitems, 'li', function ($join) {
                $join->on('li.conversation_id', '=', 'conversations.id');
            })
            ->whereBetween('conversations.created_at', [
                $this->range->startSql(), $this->range->endSql(),
            ])
            ->where('conversations.state', '!=', \App\Conversation::STATE_DELETED)
            ->where('conversations.status', '!=', \App\Conversation::STATUS_SPAM)
            ->where('conversations.imported', 0)
            ->select([
                'conversations.id',
                'conversations.created_at',
                'conversations.closed_at',
                'conversations.status',
                'li.lineitem_closed_at',
            ]);

        if ($this->mailboxId) {
            $q->where('conversations.mailbox_id', $this->mailboxId);
        }

        return $q->get();
    }

    /**
     * Resolution durations, in minutes, plus the coverage figures that must
     * be displayed alongside them.
     *
     * @return array {
     *   elapsed: Stats summary in wall-clock minutes,
     *   working: Stats summary in business minutes,
     *   closed_total: closed conversations in the period,
     *   timed: how many of those we could actually time,
     *   from_fallback: how many needed the line-item fallback,
     *   untimed: closed conversations with no usable timestamp at all
     * }
     */
    public function resolutionTimes()
    {
        $rows = $this->resolvedRows();

        $elapsed      = [];
        $working      = [];
        $closedTotal  = 0;
        $fromFallback = 0;
        $untimed      = 0;

        foreach ($rows as $row) {
            if ((int) $row->status !== \App\Conversation::STATUS_CLOSED) {
                continue;
            }

            $closedTotal++;

            $resolvedAt = $row->closed_at;

            if (!$resolvedAt) {
                // Trap 1 fallback: the status-change line item.
                $resolvedAt = $row->lineitem_closed_at;

                if ($resolvedAt) {
                    $fromFallback++;
                }
            }

            if (!$resolvedAt) {
                // Closed, but genuinely no usable timestamp. Counted and
                // surfaced rather than quietly dropped.
                $untimed++;
                continue;
            }

            $start = new \DateTimeImmutable($row->created_at);
            $end   = new \DateTimeImmutable($resolvedAt);

            // Guard against clock skew and imported oddities producing
            // negative durations, which would corrupt the percentiles.
            $mins = ($end->getTimestamp() - $start->getTimestamp()) / 60;

            if ($mins < 0) {
                $untimed++;
                continue;
            }

            $elapsed[] = $mins;
            $working[] = BusinessTime::minutesBetween($start, $end);
        }

        return [
            'elapsed'       => Stats::summarise($elapsed),
            'working'       => Stats::summarise($working),
            'closed_total'  => $closedTotal,
            'timed'         => count($elapsed),
            'from_fallback' => $fromFallback,
            'untimed'       => $untimed,
        ];
    }

    /**
     * Time from arrival to the first agent reply.
     *
     * threads.type = 2 (TYPE_MESSAGE) is the outbound agent reply. Notes
     * (type 3) and line items (type 4) are excluded - an internal note is not
     * a response to the customer, and counting it would let a team look
     * responsive while the customer heard nothing.
     */
    public function firstResponseTimes()
    {
        $firstReplies = DB::table('threads')
            ->selectRaw('conversation_id, MIN(created_at) as first_reply_at')
            ->where('type', \App\Thread::TYPE_MESSAGE)
            ->where('state', \App\Thread::STATE_PUBLISHED)
            ->where('imported', 0)
            ->groupBy('conversation_id');

        $q = DB::table('conversations')
            ->joinSub($firstReplies, 'fr', function ($join) {
                $join->on('fr.conversation_id', '=', 'conversations.id');
            })
            ->whereBetween('conversations.created_at', [
                $this->range->startSql(), $this->range->endSql(),
            ])
            ->where('conversations.state', '!=', \App\Conversation::STATE_DELETED)
            ->where('conversations.status', '!=', \App\Conversation::STATUS_SPAM)
            ->where('conversations.imported', 0)
            ->select(['conversations.created_at', 'fr.first_reply_at']);

        if ($this->mailboxId) {
            $q->where('conversations.mailbox_id', $this->mailboxId);
        }

        $elapsed = [];
        $working = [];

        foreach ($q->get() as $row) {
            $start = new \DateTimeImmutable($row->created_at);
            $end   = new \DateTimeImmutable($row->first_reply_at);

            $mins = ($end->getTimestamp() - $start->getTimestamp()) / 60;

            if ($mins < 0) {
                continue;
            }

            $elapsed[] = $mins;
            $working[] = BusinessTime::minutesBetween($start, $end);
        }

        // The denominator that matters: how many received conversations have
        // had any agent reply at all. Awaiting-first-reply is the gap.
        $received = (new VolumeReport($this->range, $this->mailboxId))->received();

        return [
            'elapsed'   => Stats::summarise($elapsed),
            'working'   => Stats::summarise($working),
            'answered'  => count($elapsed),
            'received'  => $received,
            'unanswered'=> max(0, $received - count($elapsed)),
        ];
    }

    /**
     * Replies needed to resolve, and first-contact resolution.
     *
     * A rising reply count means questions are being answered badly the first
     * time. FCR - resolved with exactly one agent reply - is a direct read on
     * answer quality that no timing metric captures.
     */
    public function replyEffort()
    {
        $replyCounts = DB::table('threads')
            ->selectRaw('conversation_id, COUNT(*) as replies')
            ->where('type', \App\Thread::TYPE_MESSAGE)
            ->where('state', \App\Thread::STATE_PUBLISHED)
            ->where('imported', 0)
            ->groupBy('conversation_id');

        $q = DB::table('conversations')
            ->joinSub($replyCounts, 'rc', function ($join) {
                $join->on('rc.conversation_id', '=', 'conversations.id');
            })
            ->whereBetween('conversations.created_at', [
                $this->range->startSql(), $this->range->endSql(),
            ])
            ->where('conversations.state', '!=', \App\Conversation::STATE_DELETED)
            ->where('conversations.status', '!=', \App\Conversation::STATUS_SPAM)
            ->where('conversations.imported', 0)
            ->where('conversations.status', \App\Conversation::STATUS_CLOSED)
            ->select(['rc.replies']);

        if ($this->mailboxId) {
            $q->where('conversations.mailbox_id', $this->mailboxId);
        }

        $counts = $q->pluck('replies')->map('intval')->all();

        $oneReply = count(array_filter($counts, function ($c) { return $c === 1; }));

        return [
            'stats'    => Stats::summarise($counts),
            'resolved' => count($counts),
            'fcr'      => $oneReply,
            'fcr_pct'  => Format::percent($oneReply, count($counts)),
        ];
    }

    /**
     * Open conversations by age. Not restricted to the period - a backlog is
     * a present-tense fact, and the oldest items are the point.
     *
     * The leading indicator the averages hide: a team can hit every SLA on
     * what it answers while quietly drowning in what it does not.
     */
    public function backlog()
    {
        $q = DB::table('conversations')
            ->whereIn('status', [
                \App\Conversation::STATUS_ACTIVE,
                \App\Conversation::STATUS_PENDING,
            ])
            ->where('state', '!=', \App\Conversation::STATE_DELETED)
            ->where('imported', 0);

        if ($this->mailboxId) {
            $q->where('mailbox_id', $this->mailboxId);
        }

        $ages = [];
        $now  = time();

        foreach ($q->pluck('created_at') as $created) {
            $ages[] = ($now - strtotime($created)) / 86400;
        }

        $bounds  = config('reports.backlog_buckets', [1, 3, 7, 14, 30]);
        $buckets = Stats::bucket($ages, $bounds, function ($d) {
            return $d == 1 ? 'under 1d' : 'under '.$d.'d';
        });

        return [
            'total'   => count($ages),
            'buckets' => $buckets,
            'oldest'  => $ages ? max($ages) : null,
        ];
    }

    /**
     * Closed in period against received in period.
     *
     * Above 100% means the backlog is shrinking. Note the two sets are not
     * the same conversations - this is a flow measure, not a cohort one.
     */
    public function resolutionRate()
    {
        $q = DB::table('conversations')
            ->where('state', '!=', \App\Conversation::STATE_DELETED)
            ->where('status', \App\Conversation::STATUS_CLOSED)
            ->where('imported', 0)
            ->whereBetween('closed_at', [$this->range->startSql(), $this->range->endSql()]);

        if ($this->mailboxId) {
            $q->where('mailbox_id', $this->mailboxId);
        }

        $closed   = $q->count();
        $received = (new VolumeReport($this->range, $this->mailboxId))->received();

        return [
            'closed'   => $closed,
            'received' => $received,
            'rate'     => Format::percent($closed, $received),
            // This one counts only conversations with a real closed_at, so
            // it understates when workflow closes are common. Flagged in the
            // view rather than silently corrected.
            'caveat'   => true,
        ];
    }

    /**
     * Conversations closed and then reopened.
     *
     * Premature closes are invisible in every other metric, and actively
     * rewarded by resolution-time reporting - closing fast improves the
     * headline number. This is the counterweight.
     *
     * Detected as a status-change line item to ACTIVE that occurs after a
     * status-change line item to CLOSED on the same conversation.
     */
    public function reopened()
    {
        $sub = DB::table('threads as t_close')
            ->join('threads as t_open', function ($join) {
                $join->on('t_open.conversation_id', '=', 't_close.conversation_id')
                     ->whereColumn('t_open.created_at', '>', 't_close.created_at');
            })
            ->where('t_close.type', \App\Thread::TYPE_LINEITEM)
            ->where('t_close.action_type', \App\Thread::ACTION_TYPE_STATUS_CHANGED)
            ->where('t_close.status', \App\Thread::STATUS_CLOSED)
            ->where('t_open.type', \App\Thread::TYPE_LINEITEM)
            ->where('t_open.action_type', \App\Thread::ACTION_TYPE_STATUS_CHANGED)
            ->where('t_open.status', \App\Thread::STATUS_ACTIVE)
            ->distinct()
            ->pluck('t_close.conversation_id');

        if ($sub->isEmpty()) {
            return ['count' => 0, 'of_closed' => 0, 'pct' => null];
        }

        $q = DB::table('conversations')
            ->whereIn('id', $sub->all())
            ->whereBetween('created_at', [$this->range->startSql(), $this->range->endSql()])
            ->where('state', '!=', \App\Conversation::STATE_DELETED)
            ->where('imported', 0);

        if ($this->mailboxId) {
            $q->where('mailbox_id', $this->mailboxId);
        }

        $count  = $q->count();
        $closed = $this->resolutionTimes()['closed_total'];

        return [
            'count'     => $count,
            'of_closed' => $closed,
            'pct'       => Format::percent($count, $closed),
        ];
    }

    /**
     * Headline figures for the overview tab, with period comparison.
     *
     * Resolution time is lower-is-better, so the trend sentiment is inverted
     * relative to volume.
     */
    public function summary()
    {
        $current = $this->resolutionTimes();

        $prevReport = new self($this->range->previous(), $this->mailboxId);
        $previous   = $prevReport->resolutionTimes();

        $frt     = $this->firstResponseTimes();
        $prevFrt = $prevReport->firstResponseTimes();

        return [
            'resolution' => Trend::of(
                $current['elapsed']['median'],
                $previous['elapsed']['median'],
                true
            ),
            'first_response' => Trend::of(
                $frt['elapsed']['median'],
                $prevFrt['elapsed']['median'],
                true
            ),
            'coverage' => $current,
            'frt'      => $frt,
        ];
    }
}
