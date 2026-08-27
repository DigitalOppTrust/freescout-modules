<?php

namespace Modules\DOTReports\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\DOTReports\Services\DateRange;
use Modules\DOTReports\Services\VolumeReport;
use Modules\DOTReports\Services\TriageReport;
use Modules\DOTReports\Services\ResolutionReport;
use Modules\DOTReports\Services\TeamReport;
use Modules\DOTReports\Services\Format;

class ReportsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');

        // Reports expose per-agent performance and customer content, which is
        // not general-staff data. Admin-only for now; the plan records a later
        // refinement letting agents see their own figures.
        $this->middleware(function ($request, $next) {
            if (!auth()->user() || !auth()->user()->isAdmin()) {
                abort(403, 'Reports are restricted to administrators.');
            }

            return $next($request);
        });
    }

    /**
     * Shared context for every tab: the period, the mailbox filter, and the
     * links needed to keep both when moving between tabs.
     */
    protected function context(Request $request)
    {
        $range     = DateRange::fromRequest($request);
        $mailboxes = \App\Mailbox::orderBy('name')->get();
        $mailboxId = $request->get('mailbox_id') ?: null;

        // Only a real mailbox id filters; anything else shows everything.
        if ($mailboxId && !$mailboxes->contains('id', (int) $mailboxId)) {
            $mailboxId = null;
        }

        $params = $range->queryParams();
        if ($mailboxId) {
            $params['mailbox_id'] = $mailboxId;
        }

        return [
            'range'     => $range,
            'mailboxes' => $mailboxes,
            'mailboxId' => $mailboxId ? (int) $mailboxId : null,
            'params'    => $params,
        ];
    }

    /** The three original questions, on one screen. */
    public function overview(Request $request)
    {
        $ctx = $this->context($request);

        $volume     = new VolumeReport($ctx['range'], $ctx['mailboxId']);
        $triage     = new TriageReport($ctx['range'], $ctx['mailboxId']);
        $resolution = new ResolutionReport($ctx['range'], $ctx['mailboxId']);

        return view('reports::overview', $ctx + [
            'tab'        => 'overview',
            'summary'    => $volume->summary(),
            'daily'      => $volume->dailySeries(),
            'byMailbox'  => $volume->byMailbox(),
            'byChannel'  => $volume->byChannel(),
            'byStatus'   => $volume->byStatus(),
            'peakHour'   => $volume->peakHour(),
            'heatmap'    => $volume->arrivalHeatmap(),
            'triage'     => $triage->summary(),
            'triageOn'   => $triage->isEnabled(),
            'hasTriage'  => $triage->tablesExist(),
            'resolution' => $resolution->summary(),
        ]);
    }

    /** Triage effectiveness. */
    public function triage(Request $request)
    {
        $ctx = $this->context($request);

        $report = new TriageReport($ctx['range'], $ctx['mailboxId']);

        return view('reports::triage', $ctx + [
            'tab'         => 'triage',
            'hasTriage'   => $report->tablesExist(),
            'triageOn'    => $report->isEnabled(),
            'funnel'      => $report->funnel(),
            'accuracy'    => $report->accuracy(),
            'calibration' => $report->confidenceCalibration(),
            'matrix'      => $report->overrideMatrix(),
            'methods'     => $report->methodSplit(),
            'cost'        => $report->cost(),
            'failures'    => $report->failures(),
            'escalations' => $report->escalations(),
            'daily'       => $report->dailySeries(),
        ]);
    }

    /** Response and resolution times. */
    public function resolution(Request $request)
    {
        $ctx = $this->context($request);

        $report = new ResolutionReport($ctx['range'], $ctx['mailboxId']);

        return view('reports::resolution', $ctx + [
            'tab'        => 'resolution',
            'resolution' => $report->resolutionTimes(),
            'frt'        => $report->firstResponseTimes(),
            'effort'     => $report->replyEffort(),
            'backlog'    => $report->backlog(),
            'rate'       => $report->resolutionRate(),
            'reopened'   => $report->reopened(),
        ]);
    }

    /** Per-agent activity. */
    public function team(Request $request)
    {
        $ctx = $this->context($request);

        $report = new TeamReport($ctx['range'], $ctx['mailboxId']);

        return view('reports::team', $ctx + [
            'tab'          => 'team',
            'agents'       => $report->agents(),
            'dwell'        => $report->unassignedDwell(),
            'escalations'  => $report->escalationsByUser(),
            'unattributed' => $report->unattributedClosures(),
        ]);
    }

    /**
     * CSV export.
     *
     * Streamed rather than built in memory, and it reuses exactly the same
     * services as the screen - so an export always matches what was on it.
     */
    public function export(Request $request, $report)
    {
        $ctx = $this->context($request);

        $rows = $this->exportRows($report, $ctx);

        if ($rows === null) {
            abort(404, 'Unknown report.');
        }

        $filename = 'freescout-'.$report.'-'
            .$ctx['range']->start->toDateString().'-to-'
            .$ctx['range']->end->toDateString().'.csv';

        return response()->stream(function () use ($rows) {
            $out = fopen('php://output', 'w');

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Rows for a given export, header first.
     *
     * @return array|null null when the report name is unknown
     */
    protected function exportRows($report, array $ctx)
    {
        $range     = $ctx['range'];
        $mailboxId = $ctx['mailboxId'];

        switch ($report) {
            case 'volume':
                $rows = [['Date', 'Conversations received']];

                foreach ((new VolumeReport($range, $mailboxId))->dailySeries() as $day => $total) {
                    $rows[] = [$day, $total];
                }

                return $rows;

            case 'team':
                $rows = [[
                    'Agent', 'Assigned', 'Replies sent', 'Resolved', 'Currently open',
                    'Median first response (min)', 'Median resolution (min)', 'Sample',
                ]];

                foreach ((new TeamReport($range, $mailboxId))->agents() as $a) {
                    $rows[] = [
                        $a['name'],
                        $a['assigned'],
                        $a['replies'],
                        $a['resolved'],
                        $a['open'],
                        $a['first_response']['median'] !== null
                            ? round($a['first_response']['median']) : '',
                        $a['resolution']['median'] !== null
                            ? round($a['resolution']['median']) : '',
                        $a['first_response']['count'],
                    ];
                }

                return $rows;

            case 'triage':
                $t = new TriageReport($range, $mailboxId);

                if (!$t->tablesExist()) {
                    return [['Triage module not installed']];
                }

                $funnel = $t->funnel();

                $rows = [['Metric', 'Value']];
                foreach ([
                    'Conversations received' => $funnel['received'],
                    'Triaged'                => $funnel['triaged'],
                    'Auto-assigned'          => $funnel['applied'],
                    'Suggested only'         => $funnel['suggested'],
                    'No match'               => $funnel['no_match'],
                    'Errors'                 => $funnel['errors'],
                    'Never triaged'          => $funnel['untouched'],
                    'Coverage %'             => $funnel['coverage_pct'],
                ] as $label => $value) {
                    $rows[] = [$label, $value];
                }

                $rows[] = [];
                $rows[] = ['Confidence band', 'Applied', 'Correct', 'Accuracy %'];

                foreach ((array) $t->confidenceCalibration() as $b) {
                    $rows[] = [$b['label'], $b['total'], $b['correct'], $b['accuracy']];
                }

                return $rows;

            case 'resolution':
                $r = new ResolutionReport($range, $mailboxId);

                $res = $r->resolutionTimes();
                $frt = $r->firstResponseTimes();

                return [
                    ['Metric', 'Elapsed (min)', 'Working (min)', 'Sample'],
                    ['Median first response',
                        self::r($frt['elapsed']['median']),
                        self::r($frt['working']['median']),
                        $frt['elapsed']['count']],
                    ['P90 first response',
                        self::r($frt['elapsed']['p90']),
                        self::r($frt['working']['p90']),
                        $frt['elapsed']['count']],
                    ['Median resolution',
                        self::r($res['elapsed']['median']),
                        self::r($res['working']['median']),
                        $res['timed']],
                    ['P90 resolution',
                        self::r($res['elapsed']['p90']),
                        self::r($res['working']['p90']),
                        $res['timed']],
                    [],
                    ['Coverage note'],
                    ['Support requests received (automatic closes excluded)', $frt['received']],
                    ['Awaiting first reply', $frt['unanswered']],
                    ['Closed conversations in period', $res['closed_total']],
                    ['Timed successfully', $res['timed']],
                    ['Needed line-item fallback', $res['from_fallback']],
                    ['No usable close timestamp', $res['untimed']],
                    ['Closed automatically by Triage (excluded)', $res['auto_closed']],
                    ['  of which not support requests', $res['auto_reasons']['noise']],
                    ['  of which customer went quiet', $res['auto_reasons']['inactivity']],
                    ['  of which judged resolved', $res['auto_reasons']['resolved']],
                    ['Median including automatic closes (min)', self::r($res['all_median'])],
                ];

            default:
                return null;
        }
    }

    protected static function r($value)
    {
        return $value === null ? '' : round($value);
    }
}
