<?php

namespace Modules\DOTMCP\Services\Tools;

use Illuminate\Support\Facades\DB;
use Modules\DOTTriage\Services\BusinessTime;

/**
 * Aggregate reporting tools - counts, trends and averages.
 *
 * These never return an individual conversation or any customer identity, so
 * they are safe at every access level. Anything that returns a record belongs
 * in DetailTools instead.
 */
class AggregateTools
{
    protected function since($days)
    {
        $days = max(1, min(730, (int) ($days ?: 30)));

        return [date('Y-m-d H:i:s', strtotime("-{$days} days")), $days];
    }

    /** How many conversations arrived, bucketed. */
    public function conversationVolume(array $args)
    {
        list($since, $days) = $this->since($args['days'] ?? 30);
        $groupBy = in_array($args['group_by'] ?? 'day', ['day', 'week', 'month'], true)
            ? $args['group_by'] : 'day';

        $format = ['day' => '%Y-%m-%d', 'week' => '%x-W%v', 'month' => '%Y-%m'][$groupBy];

        $rows = DB::table('conversations')
            ->selectRaw("DATE_FORMAT(created_at, '{$format}') AS bucket, COUNT(*) AS total")
            ->where('created_at', '>=', $since)
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $total = DB::table('conversations')->where('created_at', '>=', $since)->count();

        return [
            'period_days' => $days,
            'grouped_by'  => $groupBy,
            'total'       => $total,
            'average_per_day' => round($total / $days, 1),
            'buckets'     => $rows->map(function ($r) {
                return ['period' => $r->bucket, 'conversations' => (int) $r->total];
            })->all(),
        ];
    }

    /** This period against the one before it. */
    public function volumeTrend(array $args)
    {
        list($since, $days) = $this->since($args['days'] ?? 30);
        $prevSince = date('Y-m-d H:i:s', strtotime("-".($days * 2)." days"));

        $current  = DB::table('conversations')->where('created_at', '>=', $since)->count();
        $previous = DB::table('conversations')
            ->where('created_at', '>=', $prevSince)
            ->where('created_at', '<', $since)
            ->count();

        $change = $previous > 0
            ? round((($current - $previous) / $previous) * 100, 1)
            : null;

        return [
            'period_days'      => $days,
            'current_period'   => $current,
            'previous_period'  => $previous,
            'change_percent'   => $change,
            'direction'        => $current === $previous ? 'flat' : ($current > $previous ? 'up' : 'down'),
            'note' => $previous === 0
                ? 'No conversations in the previous period, so no percentage change can be calculated.'
                : null,
        ];
    }

    /** How triage handled inbound mail. */
    public function triageSummary(array $args)
    {
        list($since, $days) = $this->since($args['days'] ?? 30);

        $rows = DB::table('triage_decisions')
            ->selectRaw('method, COUNT(*) AS total, SUM(applied) AS applied, SUM(closed) AS closed')
            ->where('created_at', '>=', $since)
            ->groupBy('method')
            ->get();

        $totalConversations = DB::table('conversations')->where('created_at', '>=', $since)->count();
        $totalDecisions     = (int) $rows->sum('total');

        return [
            'period_days'           => $days,
            'conversations_received'=> $totalConversations,
            'triage_decisions'      => $totalDecisions,
            'not_triaged'           => max(0, $totalConversations - $totalDecisions),
            'by_method'             => $rows->map(function ($r) {
                return [
                    'method'        => $r->method,
                    'decisions'     => (int) $r->total,
                    'auto_assigned' => (int) $r->applied,
                    'closed'        => (int) $r->closed,
                ];
            })->all(),
            'note' => $totalDecisions === 0
                ? 'No triage decisions in this period. Triage may have been disabled.'
                : null,
        ];
    }

    /** Was the routing right? */
    public function triageAccuracy(array $args)
    {
        list($since, $days) = $this->since($args['days'] ?? 30);

        $applied = DB::table('triage_decisions')
            ->where('created_at', '>=', $since)
            ->where('applied', true)
            ->count();

        $overridden = DB::table('triage_decisions')
            ->where('created_at', '>=', $since)
            ->where('applied', true)
            ->whereNotNull('overridden_by_user_id')
            ->count();

        $perAgent = DB::table('triage_decisions as d')
            ->leftJoin('users as u', 'u.id', '=', 'd.suggested_user_id')
            ->selectRaw("CONCAT(IFNULL(u.first_name,''),' ',IFNULL(u.last_name,'')) AS agent")
            ->selectRaw('COUNT(*) AS routed')
            ->selectRaw('SUM(d.overridden_by_user_id IS NOT NULL) AS overridden')
            ->where('d.created_at', '>=', $since)
            ->whereNotNull('d.suggested_user_id')
            ->groupBy('d.suggested_user_id', 'u.first_name', 'u.last_name')
            ->get();

        return [
            'period_days'      => $days,
            'auto_assigned'    => $applied,
            'later_reassigned' => $overridden,
            'accuracy_percent' => $applied > 0
                ? round((($applied - $overridden) / $applied) * 100, 1)
                : null,
            'per_agent' => $perAgent->map(function ($r) {
                return [
                    'agent'      => trim($r->agent) ?: 'unknown',
                    'routed_to'  => (int) $r->routed,
                    'reassigned' => (int) $r->overridden,
                ];
            })->all(),
            'note' => $applied === 0
                ? 'Nothing was auto-assigned in this period, so accuracy cannot be measured. '
                 .'Suggestions that a human never acted on say nothing about whether routing was correct.'
                : null,
        ];
    }

    /** How much inbound was not a support request. */
    public function noiseSummary(array $args)
    {
        list($since, $days) = $this->since($args['days'] ?? 30);

        $rows = DB::table('triage_decisions')
            ->selectRaw('noise_category, COUNT(*) AS total')
            ->where('created_at', '>=', $since)
            ->where('closed', true)
            ->whereNotNull('noise_category')
            ->groupBy('noise_category')
            ->get();

        $closed = (int) $rows->sum('total');
        $total  = DB::table('conversations')->where('created_at', '>=', $since)->count();

        $reopened = DB::table('triage_decisions')
            ->where('created_at', '>=', $since)
            ->where('closed', true)
            ->whereNotNull('reopened_by_user_id')
            ->count();

        return [
            'period_days'          => $days,
            'closed_as_non_support'=> $closed,
            'total_received'       => $total,
            'percent_of_inbound'   => $total > 0 ? round(($closed / $total) * 100, 1) : 0,
            'reopened_by_a_human'  => $reopened,
            'by_category'          => $rows->map(function ($r) {
                return ['category' => $r->noise_category, 'count' => (int) $r->total];
            })->all(),
            'note' => $reopened > 0
                ? 'Reopened conversations mean a detection rule closed something genuine. Worth reviewing.'
                : null,
        ];
    }

    /**
     * What people are asking about.
     *
     * Derived from triage reasoning rather than subject lines: real subjects
     * are dominated by newsletter noise and misspellings, so clustering them
     * would describe the mailing list rather than the support load.
     */
    public function topicSummary(array $args)
    {
        list($since, $days) = $this->since($args['days'] ?? 30);
        $limit = max(1, min(50, (int) ($args['limit'] ?? 15)));

        $reasons = DB::table('triage_decisions')
            ->where('created_at', '>=', $since)
            ->whereNull('noise_category')
            ->whereNotNull('reasoning')
            ->pluck('reasoning');

        // Frequency over meaningful words. Deliberately simple: at low volume
        // this beats clustering, and it costs nothing.
        $stop = array_flip([
            'this','that','with','from','about','request','support','the','and','for','are','is',
            'was','were','has','have','had','not','but','their','they','them','which','who','a','an',
            'to','of','in','on','it','be','as','by','or','at','relating','related','regarding',
            'user','users','email','emails','message','sent','asking','asks','query','question',
        ]);

        $freq = [];
        foreach ($reasons as $r) {
            $words = preg_split('/[^a-z0-9]+/i', mb_strtolower((string) $r), -1, PREG_SPLIT_NO_EMPTY);
            foreach (array_unique($words) as $w) {
                if (mb_strlen($w) < 4 || isset($stop[$w])) {
                    continue;
                }
                $freq[$w] = ($freq[$w] ?? 0) + 1;
            }
        }
        arsort($freq);

        $keywords = DB::table('triage_decisions')
            ->selectRaw('COUNT(*) AS total')
            ->where('created_at', '>=', $since)
            ->where('method', 'keyword')
            ->value('total');

        return [
            'period_days'      => $days,
            'analysed'         => $reasons->count(),
            'matched_by_keyword' => (int) $keywords,
            'themes' => array_map(
                function ($w, $n) { return ['term' => $w, 'conversations' => $n]; },
                array_slice(array_keys($freq), 0, $limit),
                array_slice(array_values($freq), 0, $limit)
            ),
            'note' => $reasons->count() < 20
                ? 'Only '.$reasons->count().' triaged conversations in this period. '
                 .'Themes drawn from this little data are indicative at best.'
                : null,
        ];
    }

    /** First response and resolution times. */
    public function responseTimes(array $args)
    {
        list($since, $days) = $this->since($args['days'] ?? 30);

        // Timings derive from threads, not the conversation convenience
        // columns, which are unreliable for reporting.
        $rows = DB::select("
            SELECT c.id, c.created_at AS opened,
                   (SELECT MIN(t.created_at) FROM threads t
                     WHERE t.conversation_id = c.id AND t.type = 2) AS first_reply,
                   c.closed_at
              FROM conversations c
             WHERE c.created_at >= ?
        ", [$since]);

        $firstElapsed = $firstWorking = $resElapsed = $resWorking = [];

        foreach ($rows as $r) {
            if ($r->first_reply) {
                $a = new \DateTimeImmutable($r->opened);
                $b = new \DateTimeImmutable($r->first_reply);
                $firstElapsed[] = ($b->getTimestamp() - $a->getTimestamp()) / 60;
                $firstWorking[] = BusinessTime::minutesBetween($a, $b);
            }
            if ($r->closed_at) {
                $a = new \DateTimeImmutable($r->opened);
                $b = new \DateTimeImmutable($r->closed_at);
                $resElapsed[] = ($b->getTimestamp() - $a->getTimestamp()) / 60;
                $resWorking[] = BusinessTime::minutesBetween($a, $b);
            }
        }

        return [
            'period_days'    => $days,
            'conversations'  => count($rows),
            'first_response' => [
                'measured'        => count($firstElapsed),
                'median_elapsed'  => $this->human($this->median($firstElapsed)),
                'median_working'  => $this->human($this->median($firstWorking)),
                'p90_working'     => $this->human($this->percentile($firstWorking, 90)),
            ],
            'resolution' => [
                'measured'        => count($resElapsed),
                'median_elapsed'  => $this->human($this->median($resElapsed)),
                'median_working'  => $this->human($this->median($resWorking)),
                'p90_working'     => $this->human($this->percentile($resWorking, 90)),
            ],
            'coverage_note' => sprintf(
                '%d of %d conversations had an agent reply; %d were closed. '
               .'Figures cover only those, not the whole period.',
                count($firstElapsed), count($rows), count($resElapsed)
            ),
            'working_hours_note' => 'Working time excludes weekends.',
        ];
    }

    /** Who is carrying what. */
    public function agentWorkload(array $args)
    {
        $rows = DB::table('conversations as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->selectRaw("CONCAT(IFNULL(u.first_name,''),' ',IFNULL(u.last_name,'')) AS agent")
            ->selectRaw('COUNT(*) AS open_count')
            ->selectRaw('MIN(c.created_at) AS oldest')
            ->where('c.status', 1)
            ->groupBy('c.user_id', 'u.first_name', 'u.last_name')
            ->get();

        $unassigned = DB::table('conversations')
            ->where('status', 1)->whereNull('user_id')->count();

        return [
            'unassigned_open' => $unassigned,
            'per_agent' => $rows->filter(function ($r) {
                return trim($r->agent) !== '';
            })->map(function ($r) {
                $age = $r->oldest
                    ? BusinessTime::minutesBetween(new \DateTimeImmutable($r->oldest), new \DateTimeImmutable())
                    : null;

                return [
                    'agent'             => trim($r->agent),
                    'open_conversations'=> (int) $r->open_count,
                    'oldest_working_age'=> $age !== null ? BusinessTime::describe($age) : null,
                ];
            })->values()->all(),
        ];
    }

    // ── helpers ──────────────────────────────────────────────────────

    protected function median(array $v)
    {
        if (empty($v)) {
            return null;
        }
        sort($v);
        $n = count($v);
        $m = (int) floor($n / 2);

        return $n % 2 ? $v[$m] : ($v[$m - 1] + $v[$m]) / 2;
    }

    protected function percentile(array $v, $p)
    {
        if (empty($v)) {
            return null;
        }
        sort($v);
        $i = (int) ceil(($p / 100) * count($v)) - 1;

        return $v[max(0, min($i, count($v) - 1))];
    }

    protected function human($minutes)
    {
        if ($minutes === null) {
            return null;
        }
        $minutes = (int) round($minutes);

        if ($minutes < 60) {
            return $minutes.' min';
        }
        if ($minutes < 1440) {
            return round($minutes / 60, 1).' hours';
        }

        return round($minutes / 1440, 1).' days';
    }
}
