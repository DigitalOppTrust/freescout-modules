<?php

namespace Modules\Reports\Services;

use Illuminate\Support\Facades\DB;

/**
 * Inbound volume: how much mail arrived, from where, and when.
 *
 * Every query here aggregates in SQL. Hydrating thousands of Eloquent models
 * on a 908MB server with a 128MB InnoDB buffer pool is the one thing that
 * will actually hurt, and counting rows does not need objects.
 */
class VolumeReport
{
    protected $range;
    protected $mailboxId;

    public function __construct(DateRange $range, $mailboxId = null)
    {
        $this->range     = $range;
        $this->mailboxId = $mailboxId ?: null;
    }

    /**
     * Base query for conversations genuinely received in the period.
     *
     * Exclusions, all deliberate:
     *   state != 3    - deleted conversations are not evidence of anything
     *   status != 4   - spam is not work
     *   imported != 1 - imported history is not our response record
     *
     * Shaped to hit the core composite index on
     * (mailbox_id, created_at, closed_at) added in 2026_04_20.
     */
    public function conversationsQuery(DateRange $range = null)
    {
        $range = $range ?: $this->range;

        $q = DB::table('conversations')
            ->whereBetween('created_at', [$range->startSql(), $range->endSql()])
            ->where('state', '!=', \App\Conversation::STATE_DELETED)
            ->where('status', '!=', \App\Conversation::STATUS_SPAM)
            ->where('imported', 0);

        if ($this->mailboxId) {
            $q->where('mailbox_id', $this->mailboxId);
        }

        return $q;
    }

    /** Conversations received in the period. */
    public function received(DateRange $range = null)
    {
        return $this->conversationsQuery($range)->count();
    }

    /**
     * Inbound customer messages, which counts follow-ups as well as new
     * tickets. Diverges from the conversation count when customers send many
     * messages per issue - the gap between the two is itself informative.
     *
     * threads.type = 1 (TYPE_CUSTOMER) is the inbound customer message.
     * state = 2 (STATE_PUBLISHED) excludes drafts.
     */
    public function inboundMessages(DateRange $range = null)
    {
        return $this->messagesOfType(\App\Thread::TYPE_CUSTOMER, $range);
    }

    /** Agent replies sent in the period. threads.type = 2 (TYPE_MESSAGE). */
    public function outboundReplies(DateRange $range = null)
    {
        return $this->messagesOfType(\App\Thread::TYPE_MESSAGE, $range);
    }

    /**
     * Published, non-imported threads of one type, counted in the period.
     *
     * ALWAYS joins conversations, even with no mailbox filter, so that the
     * spam / deleted / imported exclusions apply to messages exactly as they
     * do to conversations. Without the join, a spam conversation's messages
     * would be counted while the conversation itself was excluded - so
     * "inbound messages" would exceed what the rest of the page reports, for
     * no visible reason.
     */
    protected function messagesOfType($type, DateRange $range = null)
    {
        $range = $range ?: $this->range;

        $q = DB::table('threads')
            ->join('conversations', 'conversations.id', '=', 'threads.conversation_id')
            ->whereBetween('threads.created_at', [$range->startSql(), $range->endSql()])
            ->where('threads.type', $type)
            ->where('threads.state', \App\Thread::STATE_PUBLISHED)
            ->where('threads.imported', 0)
            ->where('conversations.state', '!=', \App\Conversation::STATE_DELETED)
            ->where('conversations.status', '!=', \App\Conversation::STATUS_SPAM)
            ->where('conversations.imported', 0);

        if ($this->mailboxId) {
            $q->where('conversations.mailbox_id', $this->mailboxId);
        }

        return $q->count();
    }

    /**
     * Daily counts, with quiet days present as zero.
     *
     * @return array Y-m-d => count
     */
    public function dailySeries()
    {
        $rows = $this->conversationsQuery()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day')
            ->all();

        // Start from a complete zero-filled series so gaps read as quiet
        // days rather than as missing data.
        $series = $this->range->emptyDaySeries();

        foreach ($rows as $day => $total) {
            if (array_key_exists($day, $series)) {
                $series[$day] = (int) $total;
            }
        }

        return $series;
    }

    /** Received per mailbox, largest first. */
    public function byMailbox()
    {
        $rows = $this->conversationsQuery()
            ->selectRaw('mailbox_id, COUNT(*) as total')
            ->groupBy('mailbox_id')
            ->orderByDesc('total')
            ->get();

        $names = DB::table('mailboxes')->pluck('name', 'id');

        return $rows->map(function ($r) use ($names) {
            return [
                'mailbox_id' => $r->mailbox_id,
                'name'       => $names[$r->mailbox_id] ?? ('Mailbox '.$r->mailbox_id),
                'total'      => (int) $r->total,
            ];
        })->all();
    }

    /** Received per channel - email, phone, chat. */
    public function byChannel()
    {
        $labels = [
            \App\Conversation::TYPE_EMAIL  => 'Email',
            \App\Conversation::TYPE_PHONE  => 'Phone',
            \App\Conversation::TYPE_CHAT   => 'Chat',
            \App\Conversation::TYPE_CUSTOM => 'Custom',
        ];

        return $this->conversationsQuery()
            ->selectRaw('type, COUNT(*) as total')
            ->groupBy('type')
            ->orderByDesc('total')
            ->get()
            ->map(function ($r) use ($labels) {
                return [
                    'type'  => (int) $r->type,
                    'name'  => $labels[$r->type] ?? ('Type '.$r->type),
                    'total' => (int) $r->total,
                ];
            })->all();
    }

    /** Current status mix of conversations received in the period. */
    public function byStatus()
    {
        $labels = [
            \App\Conversation::STATUS_ACTIVE  => 'Active',
            \App\Conversation::STATUS_PENDING => 'Pending',
            \App\Conversation::STATUS_CLOSED  => 'Closed',
        ];

        return $this->conversationsQuery()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->get()
            ->map(function ($r) use ($labels) {
                return [
                    'status' => (int) $r->status,
                    'name'   => $labels[$r->status] ?? ('Status '.$r->status),
                    'total'  => (int) $r->total,
                ];
            })->all();
    }

    /**
     * Arrival pattern by hour of day and day of week.
     *
     * Drives staffing, and shows whether the SLA windows configured in Triage
     * match when mail actually arrives.
     *
     * MariaDB DAYOFWEEK() is 1=Sunday..7=Saturday; remapped to ISO-8601
     * 1=Monday..7=Sunday so it agrees with Triage's BusinessTime, which uses
     * ISO day numbers for its weekend configuration.
     *
     * @return array [dow => [hour => count]]
     */
    public function arrivalHeatmap()
    {
        $rows = $this->conversationsQuery()
            ->selectRaw('DAYOFWEEK(created_at) as dow, HOUR(created_at) as hr, COUNT(*) as total')
            ->groupBy('dow', 'hr')
            ->get();

        // Pre-fill the whole grid so the heatmap renders complete.
        $grid = [];
        for ($d = 1; $d <= 7; $d++) {
            $grid[$d] = array_fill(0, 24, 0);
        }

        foreach ($rows as $r) {
            // 1=Sun..7=Sat  ->  1=Mon..7=Sun
            $iso = ((int) $r->dow + 5) % 7 + 1;
            $grid[$iso][(int) $r->hr] = (int) $r->total;
        }

        return $grid;
    }

    /** Busiest single hour, for the summary line under the heatmap. */
    public function peakHour()
    {
        $row = $this->conversationsQuery()
            ->selectRaw('HOUR(created_at) as hr, COUNT(*) as total')
            ->groupBy('hr')
            ->orderByDesc('total')
            ->first();

        return $row ? ['hour' => (int) $row->hr, 'total' => (int) $row->total] : null;
    }

    /**
     * Headline figures with period-over-period change.
     */
    public function summary()
    {
        $prev = $this->range->previous();

        return [
            'received' => Trend::of(
                $this->received(),
                $this->received($prev)
            ),
            'inbound_messages' => Trend::of(
                $this->inboundMessages(),
                $this->inboundMessages($prev)
            ),
            'outbound_replies' => Trend::of(
                $this->outboundReplies(),
                $this->outboundReplies($prev)
            ),
        ];
    }
}
