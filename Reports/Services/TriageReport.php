<?php

namespace Modules\Reports\Services;

use Illuminate\Support\Facades\DB;

/**
 * Triage effectiveness - the part no off-the-shelf reporting module can
 * produce, because it reads tables only this install has.
 *
 * The two metrics that actually matter for deciding whether to grant the
 * model more autonomy are confidenceCalibration() and overrideMatrix().
 * Everything else is context around them.
 *
 * Reads triage_* tables directly rather than through the Triage module's
 * entities, so this module keeps working if Triage is disabled - a disabled
 * module still has history worth reporting on. tablesExist() guards the case
 * where Triage was never installed at all.
 */
class TriageReport
{
    protected $range;
    protected $mailboxId;

    public function __construct(DateRange $range, $mailboxId = null)
    {
        $this->range     = $range;
        $this->mailboxId = $mailboxId ?: null;
    }

    /**
     * Whether the Triage module's tables are present.
     *
     * Reports must degrade to an honest empty state rather than a 500 when
     * Triage is not installed.
     */
    public function tablesExist()
    {
        static $exists = null;

        if ($exists === null) {
            $exists = \Schema::hasTable('triage_decisions');
        }

        return $exists;
    }

    /** Whether triage is currently switched on, for the empty-state copy. */
    public function isEnabled()
    {
        return (bool) config('triage.enabled', false);
    }

    protected function decisionsQuery()
    {
        $q = DB::table('triage_decisions')
            ->whereBetween('created_at', [$this->range->startSql(), $this->range->endSql()]);

        if ($this->mailboxId) {
            $q->where('mailbox_id', $this->mailboxId);
        }

        return $q;
    }

    /**
     * The coverage funnel: received -> triaged -> auto-assigned.
     *
     * The denominator is deliberately every conversation received, not just
     * those triage attempted. Anything else would let the module score well
     * by simply declining to try - "100% accurate on the three it looked at"
     * is not a useful claim.
     */
    public function funnel()
    {
        if (!$this->tablesExist()) {
            return null;
        }

        $received = (new VolumeReport($this->range, $this->mailboxId))->received();

        $decisions = $this->decisionsQuery()->count();
        $applied   = (clone $this->decisionsQuery())->where('applied', 1)->count();
        $errors    = (clone $this->decisionsQuery())->whereNotNull('error')->count();

        // Recorded a decision but chose nobody - below threshold, or no
        // sensible match. Distinct from an outright failure.
        $noMatch = (clone $this->decisionsQuery())
            ->whereNull('suggested_user_id')
            ->whereNull('error')
            ->count();

        $suggested = max(0, $decisions - $applied - $errors - $noMatch);

        return [
            'received'      => $received,
            'triaged'       => $decisions,
            'applied'       => $applied,
            'suggested'     => $suggested,
            'no_match'      => $noMatch,
            'errors'        => $errors,
            'untouched'     => max(0, $received - $decisions),
            'coverage_pct'  => Format::percent($decisions, $received),
            'auto_pct'      => Format::percent($applied, $received),
        ];
    }

    /**
     * Routing accuracy over the period.
     *
     * Counts only APPLIED decisions. A suggestion nobody acted on says
     * nothing about whether the model was right - nobody tested it. This
     * mirrors TriageDecision::accuracy() deliberately, so the settings screen
     * and the report never disagree.
     */
    public function accuracy()
    {
        if (!$this->tablesExist()) {
            return null;
        }

        $applied = (clone $this->decisionsQuery())->where('applied', 1)->count();

        if (!$applied) {
            return ['applied' => 0, 'overridden' => 0, 'accuracy' => null];
        }

        $overridden = (clone $this->decisionsQuery())
            ->where('applied', 1)
            ->whereNotNull('overridden_by_user_id')
            ->count();

        return [
            'applied'     => $applied,
            'overridden'  => $overridden,
            'accuracy'    => Format::percent($applied - $overridden, $applied),
            'significant' => Format::isSignificant($applied),
        ];
    }

    /**
     * Accuracy bucketed by confidence band.
     *
     * THE metric for deciding whether to raise or lower the auto-assign
     * threshold. If high-confidence decisions are not measurably more correct
     * than low-confidence ones, the confidence score is decoration and the
     * threshold is doing nothing. If they are, this shows exactly where to
     * put the line.
     */
    public function confidenceCalibration()
    {
        if (!$this->tablesExist()) {
            return null;
        }

        $bounds = config('reports.confidence_buckets', [0.0, 0.5, 0.6, 0.7, 0.8, 0.9]);

        $rows = (clone $this->decisionsQuery())
            ->where('applied', 1)
            ->whereNotNull('confidence')
            ->select(['confidence', 'overridden_by_user_id'])
            ->get();

        $buckets = [];
        foreach ($bounds as $i => $lower) {
            $upper = $bounds[$i + 1] ?? 1.01;

            $buckets[] = [
                'label'      => sprintf('%.2f-%.2f', $lower, min($upper, 1.0)),
                'lower'      => $lower,
                'upper'      => $upper,
                'total'      => 0,
                'correct'    => 0,
                'accuracy'   => null,
            ];
        }

        foreach ($rows as $row) {
            foreach ($buckets as $i => $b) {
                if ($row->confidence >= $b['lower'] && $row->confidence < $b['upper']) {
                    $buckets[$i]['total']++;

                    if (!$row->overridden_by_user_id) {
                        $buckets[$i]['correct']++;
                    }
                    break;
                }
            }
        }

        foreach ($buckets as $i => $b) {
            $buckets[$i]['accuracy']    = Format::percent($b['correct'], $b['total']);
            $buckets[$i]['significant'] = Format::isSignificant($b['total']);
        }

        return $buckets;
    }

    /**
     * Suggested agent against who the ticket actually ended up with.
     *
     * The diagnostic that turns "the AI is bad" into something actionable: a
     * cluster on one pair means one profile description is wrong, which is a
     * five-minute fix, not a model problem.
     */
    public function overrideMatrix()
    {
        if (!$this->tablesExist()) {
            return null;
        }

        $rows = (clone $this->decisionsQuery())
            ->whereNotNull('overridden_by_user_id')
            ->whereNotNull('suggested_user_id')
            ->selectRaw('suggested_user_id, overridden_to_user_id, COUNT(*) as total')
            ->groupBy('suggested_user_id', 'overridden_to_user_id')
            ->orderByDesc('total')
            ->limit((int) config('reports.table_limit', 50))
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $names = $this->userNames();

        return $rows->map(function ($r) use ($names) {
            return [
                'from'  => $names[$r->suggested_user_id] ?? ('User '.$r->suggested_user_id),
                'to'    => $r->overridden_to_user_id
                    ? ($names[$r->overridden_to_user_id] ?? ('User '.$r->overridden_to_user_id))
                    : 'Unassigned',
                'total' => (int) $r->total,
            ];
        })->all();
    }

    /**
     * How decisions were reached - keyword, model, fallback, skipped.
     *
     * Keyword matches are deterministic and free, so a healthy keyword share
     * is a cost saving rather than a failure of the model.
     */
    public function methodSplit()
    {
        if (!$this->tablesExist()) {
            return null;
        }

        return (clone $this->decisionsQuery())
            ->selectRaw('method, COUNT(*) as total')
            ->groupBy('method')
            ->orderByDesc('total')
            ->get()
            ->map(function ($r) {
                return ['method' => $r->method ?: 'unknown', 'total' => (int) $r->total];
            })->all();
    }

    /**
     * Token spend and latency.
     *
     * Cost is an estimate from configured per-million-token rates, not a
     * billing figure. Labelled as such in the view - a number that looks like
     * an invoice will eventually be treated as one.
     */
    public function cost()
    {
        if (!$this->tablesExist()) {
            return null;
        }

        $row = (clone $this->decisionsQuery())
            ->where('method', 'model')
            ->selectRaw('COUNT(*) as calls, SUM(tokens_in) as tin, SUM(tokens_out) as tout')
            ->first();

        $tin  = (int) ($row->tin ?? 0);
        $tout = (int) ($row->tout ?? 0);

        $rateIn  = (float) config('reports.cost_per_mtok_in', 1.00);
        $rateOut = (float) config('reports.cost_per_mtok_out', 5.00);

        $estimate = ($tin / 1000000 * $rateIn) + ($tout / 1000000 * $rateOut);

        // Latency percentiles from the same rows.
        $durations = (clone $this->decisionsQuery())
            ->where('method', 'model')
            ->whereNotNull('duration_ms')
            ->pluck('duration_ms')
            ->map('intval')
            ->all();

        return [
            'calls'      => (int) ($row->calls ?? 0),
            'tokens_in'  => $tin,
            'tokens_out' => $tout,
            'estimate'   => $estimate,
            'per_day'    => $this->range->days() ? $estimate / $this->range->days() : 0,
            'latency'    => Stats::summarise($durations),
        ];
    }

    /** Failures, surfaced rather than silently absent. */
    public function failures()
    {
        if (!$this->tablesExist()) {
            return null;
        }

        return (clone $this->decisionsQuery())
            ->whereNotNull('error')
            ->selectRaw('error, COUNT(*) as total, MAX(created_at) as last_seen')
            ->groupBy('error')
            ->orderByDesc('total')
            ->limit(20)
            ->get()
            ->map(function ($r) {
                return [
                    'error'     => $r->error,
                    'total'     => (int) $r->total,
                    'last_seen' => $r->last_seen,
                ];
            })->all();
    }

    /**
     * Escalation outcomes from triage_escalations.
     *
     * "Resolved before breach" is the number that says the SLA machinery is
     * working; a high notify count with few reassignments means people are
     * responding to the nudge, which is the intended behaviour.
     */
    public function escalations()
    {
        if (!\Schema::hasTable('triage_escalations')) {
            return null;
        }

        $q = DB::table('triage_escalations')
            ->whereBetween('created_at', [$this->range->startSql(), $this->range->endSql()]);

        $total      = (clone $q)->count();
        $notified   = (clone $q)->whereNotNull('notified_at')->count();
        $reassigned = (clone $q)->whereNotNull('reassigned_at')->count();

        return [
            'total'       => $total,
            'notified'    => $notified,
            'reassigned'  => $reassigned,
            'within_sla'  => max(0, $total - $notified),
            'sla_pct'     => Format::percent(max(0, $total - $notified), $total),
        ];
    }

    /** Daily triaged-vs-received series for the coverage trend chart. */
    public function dailySeries()
    {
        if (!$this->tablesExist()) {
            return null;
        }

        $rows = $this->decisionsQuery()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total,
                         SUM(CASE WHEN applied = 1 THEN 1 ELSE 0 END) as applied')
            ->groupBy('day')
            ->get();

        $triaged = $this->range->emptyDaySeries();
        $applied = $this->range->emptyDaySeries();

        foreach ($rows as $r) {
            if (array_key_exists($r->day, $triaged)) {
                $triaged[$r->day] = (int) $r->total;
                $applied[$r->day] = (int) $r->applied;
            }
        }

        return ['triaged' => $triaged, 'applied' => $applied];
    }

    /** Summary for the overview tab. */
    public function summary()
    {
        if (!$this->tablesExist()) {
            return null;
        }

        $funnel = $this->funnel();

        $prev       = new self($this->range->previous(), $this->mailboxId);
        $prevFunnel = $prev->funnel();

        return [
            'funnel'   => $funnel,
            'accuracy' => $this->accuracy(),
            'auto_pct' => Trend::of(
                $funnel['auto_pct'],
                $prevFunnel['auto_pct'] ?? null
            ),
        ];
    }

    protected function userNames()
    {
        return DB::table('users')
            ->selectRaw("id, CONCAT(first_name, ' ', last_name) as name")
            ->pluck('name', 'id')
            ->all();
    }
}
