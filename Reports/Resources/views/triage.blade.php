@extends('layouts.app')

@section('title', 'Reports — Triage')

@section('stylesheets')
    <link rel="stylesheet" href="{{ asset('modules/reports/css/module.css') }}">
@endsection

@section('content')
@php
    use Modules\Reports\Services\Format;
@endphp

<div class="container">

    <h2 class="subheader">Reports</h2>

    @include('reports::partials.filters')

    @if (!$hasTriage)
        @include('reports::partials.empty', [
            'message' => 'The Triage module is not installed on this instance.',
            'hint'    => 'Triage reporting reads the triage_decisions table, which does not exist here.',
        ])
    @else

        @if (!$triageOn)
            <div class="rep-caveat">
                <strong>Triage is currently switched off.</strong>
                <code>TRIAGE_ENABLED</code> is false, so no new decisions are being
                recorded. Anything below is history from when it last ran.
            </div>
        @endif

        {{-- ── Coverage funnel ───────────────────────────────────── --}}
        <h3 class="subheader">Coverage</h3>

        <div class="descr-block">
            <p>
                The denominator is every conversation received — not just those triage
                attempted. Anything else would let the module score well by declining
                to try.
            </p>
        </div>

        @include('reports::partials.bars', [
            'rows' => [
                ['label' => 'Received',       'value' => $funnel['received']],
                ['label' => 'Triaged',        'value' => $funnel['triaged'],
                 'note' => Format::percentLabel($funnel['triaged'], $funnel['received']).' of received'],
                ['label' => 'Auto-assigned',  'value' => $funnel['applied'],
                 'note' => Format::percentLabel($funnel['applied'], $funnel['received']).' of received'],
                ['label' => 'Suggested only', 'value' => $funnel['suggested']],
                ['label' => 'No match',       'value' => $funnel['no_match']],
                ['label' => 'Failed',         'value' => $funnel['errors']],
                ['label' => 'Never triaged',  'value' => $funnel['untouched']],
            ],
            'emptyText' => 'No conversations received in this period.',
        ])

        @if ($daily && array_sum($daily['triaged']))
            <h3 class="subheader">Triage activity</h3>
            @include('reports::partials.linechart', [
                'series' => $daily['triaged'],
                'label'  => 'Decisions recorded',
            ])
        @endif

        {{-- ── Accuracy ──────────────────────────────────────────── --}}
        <h3 class="subheader">Routing accuracy</h3>

        @if (!$accuracy || !$accuracy['applied'])
            @include('reports::partials.empty', [
                'message' => 'No decisions were auto-assigned in this period.',
                'hint'    => 'Accuracy counts only applied decisions — a suggestion nobody acted on says nothing about whether the model was right.',
            ])
        @else
            <div class="rep-stats">
                @include('reports::partials.stat', [
                    'label' => 'Left alone by a human',
                    'value' => $accuracy['accuracy'] === null ? '—' : $accuracy['accuracy'].'%',
                    'trend' => null,
                    'note'  => $accuracy['applied'].' auto-assigned, '
                               .$accuracy['overridden'].' later reassigned',
                ])
            </div>

            @if (!$accuracy['significant'])
                <div class="rep-caveat rep-caveat-warn">
                    <strong>Small sample.</strong>
                    {{ $accuracy['applied'] }} auto-assigned decision(s) is not yet
                    enough to judge routing quality. Treat this as a direction of
                    travel, not a verdict.
                </div>
            @endif
        @endif

        {{-- ── Confidence calibration ────────────────────────────── --}}
        <h3 class="subheader">Confidence calibration</h3>

        <div class="descr-block">
            <p>
                The evidence base for changing the auto-assign threshold. If high
                confidence is not measurably more correct than low confidence, then
                the score is decoration and the threshold is doing nothing. If it is,
                this shows where to draw the line.
                Current threshold: <strong>{{ config('triage.confidence_threshold', 0.75) }}</strong>.
            </p>
        </div>

        @php
            $hasCalibration = false;
            foreach ((array) $calibration as $b) {
                if ($b['total'] > 0) { $hasCalibration = true; break; }
            }
        @endphp

        @if (!$hasCalibration)
            @include('reports::partials.empty', [
                'message' => 'No confidence data yet.',
                'hint'    => 'This fills in once decisions have been auto-assigned and reviewed.',
            ])
        @else
            <table class="table table-striped rep-table">
                <thead>
                    <tr>
                        <th>Confidence band</th>
                        <th class="text-right">Auto-assigned</th>
                        <th class="text-right">Left alone</th>
                        <th class="text-right">Accuracy</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($calibration as $b)
                    <tr class="{{ $b['total'] ? '' : 'rep-row-empty' }}">
                        <td>{{ $b['label'] }}</td>
                        <td class="text-right">{{ $b['total'] }}</td>
                        <td class="text-right">{{ $b['correct'] }}</td>
                        <td class="text-right">
                            <strong>{{ $b['accuracy'] === null ? '—' : $b['accuracy'].'%' }}</strong>
                        </td>
                        <td class="rep-muted rep-small">
                            @if (!$b['total'])
                                no data
                            @elseif (!$b['significant'])
                                too few to be meaningful
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        {{-- ── Override matrix ───────────────────────────────────── --}}
        <h3 class="subheader">Where routing went wrong</h3>

        <div class="descr-block">
            <p>
                Who the model chose, against who the ticket actually ended up with.
                A cluster on one pair usually means one profile description needs
                rewording — a five-minute fix, not a model problem.
            </p>
        </div>

        @if (empty($matrix))
            @include('reports::partials.empty', [
                'message' => 'No overrides recorded in this period.',
                'hint'    => 'Either routing was accepted every time, or nothing was auto-assigned.',
            ])
        @else
            <table class="table table-striped rep-table">
                <thead>
                    <tr>
                        <th>Model chose</th>
                        <th>Actually went to</th>
                        <th class="text-right">Times</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($matrix as $m)
                    <tr>
                        <td>{{ $m['from'] }}</td>
                        <td>{{ $m['to'] }}</td>
                        <td class="text-right">{{ $m['total'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        <div class="row">
            {{-- ── Method split ──────────────────────────────────── --}}
            <div class="col-md-6">
                <h3 class="subheader">How decisions were reached</h3>
                <p class="rep-muted rep-small">
                    Keyword matches are deterministic and cost nothing, so a healthy
                    keyword share is a saving rather than a failure of the model.
                </p>
                @include('reports::partials.bars', [
                    'rows' => array_map(function ($m) {
                        return ['label' => ucfirst($m['method']), 'value' => $m['total']];
                    }, (array) $methods),
                    'emptyText' => 'No decisions recorded in this period.',
                ])
            </div>

            {{-- ── Escalations ───────────────────────────────────── --}}
            <div class="col-md-6">
                <h3 class="subheader">Escalations</h3>
                @if (!$escalations || !$escalations['total'])
                    @include('reports::partials.empty', [
                        'message' => 'No escalations were raised in this period.',
                    ])
                @else
                    @include('reports::partials.bars', [
                        'rows' => [
                            ['label' => 'Raised',            'value' => $escalations['total']],
                            ['label' => 'Answered in time',  'value' => $escalations['within_sla']],
                            ['label' => 'Target notified',   'value' => $escalations['notified']],
                            ['label' => 'Reassigned',        'value' => $escalations['reassigned']],
                        ],
                    ])
                    <p class="rep-muted rep-small">
                        Many notifications with few reassignments is the intended
                        outcome — people responded to the nudge.
                    </p>
                @endif
            </div>
        </div>

        {{-- ── Cost and latency ──────────────────────────────────── --}}
        <h3 class="subheader">Cost and latency</h3>

        @if (!$cost || !$cost['calls'])
            @include('reports::partials.empty', [
                'message' => 'No model calls were made in this period.',
            ])
        @else
            <div class="rep-stats">
                @include('reports::partials.stat', [
                    'label' => 'Estimated spend',
                    'value' => Format::money($cost['estimate']),
                    'trend' => null,
                    'note'  => Format::money($cost['per_day']).' per day average',
                ])

                @include('reports::partials.stat', [
                    'label' => 'Model calls',
                    'value' => Format::number($cost['calls']),
                    'trend' => null,
                    'note'  => 'daily limit '.config('triage.daily_call_limit', 500),
                ])

                @include('reports::partials.stat', [
                    'label' => 'Median latency',
                    'value' => $cost['latency']['median'] === null
                        ? '—'
                        : round($cost['latency']['median']).' ms',
                    'trend' => null,
                    'note'  => $cost['latency']['p90'] === null
                        ? ''
                        : 'p90 '.round($cost['latency']['p90']).' ms',
                ])
            </div>

            <p class="rep-muted rep-small">
                Spend is estimated from configured rates
                (${{ config('reports.cost_per_mtok_in') }}/M in,
                ${{ config('reports.cost_per_mtok_out') }}/M out) across
                {{ Format::number($cost['tokens_in']) }} input and
                {{ Format::number($cost['tokens_out']) }} output tokens.
                It is an order-of-magnitude guide, not a billing figure.
            </p>
        @endif

        {{-- ── Failures ──────────────────────────────────────────── --}}
        @if (!empty($failures))
            <h3 class="subheader">Failures</h3>
            <table class="table table-striped rep-table">
                <thead>
                    <tr>
                        <th>Error</th>
                        <th class="text-right">Times</th>
                        <th class="text-right">Last seen</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($failures as $f)
                    <tr>
                        <td class="rep-error">{{ $f['error'] }}</td>
                        <td class="text-right">{{ $f['total'] }}</td>
                        <td class="text-right rep-muted">{{ $f['last_seen'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

    @endif

</div>
@endsection
