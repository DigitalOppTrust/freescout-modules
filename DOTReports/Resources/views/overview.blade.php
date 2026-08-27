@extends('layouts.app')

@section('title', 'Reports')

@section('stylesheets')
    <link rel="stylesheet" href="{{ asset('modules/dotreports/css/module.css') }}">
@endsection

@section('content')
@php
    use Modules\DOTReports\Services\Format;
@endphp

<div class="container">

    <h2 class="subheader">Reports</h2>

    @include('reports::partials.filters')

    {{-- ── The three headline questions ──────────────────────────── --}}
    <div class="rep-stats">
        @include('reports::partials.stat', [
            'label' => 'Conversations received',
            'value' => Format::number($summary['received']->current),
            'trend' => $summary['received'],
            'note'  => Format::number($summary['inbound_messages']->current).' inbound messages'
                       .(!empty($summary['auto_closed']) && $summary['auto_closed']->current
                           ? '; '.Format::number($summary['auto_closed']->current).' closed automatically by Triage'
                           : ''),
        ])

        @if ($hasTriage && $triage)
            @include('reports::partials.stat', [
                'label' => 'Triaged automatically',
                'value' => $triage['funnel']['auto_pct'] === null
                    ? '—'
                    : $triage['funnel']['auto_pct'].'%',
                'trend' => $triage['auto_pct'],
                'note'  => Format::number($triage['funnel']['applied']).' of '
                           .Format::number($triage['funnel']['received']).' auto-assigned',
            ])
        @else
            @include('reports::partials.stat', [
                'label' => 'Triaged automatically',
                'value' => '—',
                'trend' => null,
                'note'  => 'Triage module not installed',
            ])
        @endif

        @include('reports::partials.stat', [
            'label' => 'Median resolution time',
            'value' => Format::duration($resolution['resolution']->current),
            'trend' => $resolution['resolution'],
            'note'  => $resolution['coverage']['timed'].' resolved by the team'
                       .($resolution['coverage']['auto_closed']
                           ? ', '.$resolution['coverage']['auto_closed'].' closed automatically'
                           : '')
                       .' of '.$resolution['coverage']['closed_total'].' closed',
        ])
    </div>

    {{-- Small-sample warning. A median from three tickets is noise. --}}
    @if ($resolution['coverage']['timed'] > 0 && !$resolution['coverage']['elapsed']['significant'])
        <div class="rep-caveat rep-caveat-warn">
            <strong>Small sample.</strong>
            The resolution figure is based on {{ $resolution['coverage']['timed'] }}
            conversation(s). Treat it as indicative only — it will move a lot with
            each new ticket until there are at least
            {{ config('reports.min_sample', 20) }}.
        </div>
    @endif

    {{-- Coverage caveat, on the page rather than in documentation. --}}
    @if ($resolution['coverage']['untimed'] > 0 || $resolution['coverage']['from_fallback'] > 0
         || $resolution['coverage']['auto_closed'] > 0)
        <div class="rep-caveat">
            <strong>How the resolution figure was calculated.</strong>
            @if ($resolution['coverage']['auto_closed'] > 0)
                {{ $resolution['coverage']['auto_closed'] }} conversation(s) were closed
                automatically by Triage ({{ $resolution['coverage']['auto_reasons']['noise'] }}
                not support requests, {{ $resolution['coverage']['auto_reasons']['inactivity'] }}
                customer went quiet, {{ $resolution['coverage']['auto_reasons']['resolved'] }}
                judged resolved) and are left out — the median describes conversations a
                person resolved.
            @endif
            @if ($resolution['coverage']['from_fallback'] > 0)
                {{ $resolution['coverage']['from_fallback'] }} conversation(s) had no
                <code>closed_at</code> timestamp and were timed from their status-change
                history instead.
            @endif
            @if ($resolution['coverage']['untimed'] > 0)
                {{ $resolution['coverage']['untimed'] }} closed conversation(s) had no
                usable close timestamp at all and are excluded from the median.
            @endif
        </div>
    @endif

    @if (!$summary['received']->current)
        @include('reports::partials.empty', [
            'message' => 'No conversations were received in this period.',
            'hint'    => 'Try a longer period, or check that mail is being fetched under Manage → Mailboxes.',
        ])
    @else

    {{-- ── Volume trend ──────────────────────────────────────────── --}}
    <h3 class="subheader">Volume</h3>

    @include('reports::partials.linechart', [
        'series' => $daily,
        'label'  => 'Conversations received',
    ])

    <div class="row">
        {{-- Status mix --}}
        <div class="col-md-6">
            <h3 class="subheader">Current status</h3>
            <table class="table table-striped rep-table">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th class="text-right">Conversations</th>
                        <th class="text-right">Share</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($byStatus as $s)
                    <tr>
                        <td>{{ $s['name'] }}</td>
                        <td class="text-right">{{ Format::number($s['total']) }}</td>
                        <td class="text-right rep-muted">
                            {{ Format::percentLabel($s['total'], $summary['received']->current) }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <p class="rep-muted rep-small">
                Status as it stands now, for conversations received in this period.
            </p>
        </div>

        <div class="col-md-6">
            @if (count($byMailbox) > 1)
                <h3 class="subheader">By mailbox</h3>
                @include('reports::partials.bars', [
                    'rows' => array_map(function ($m) {
                        return ['label' => $m['name'], 'value' => $m['total']];
                    }, $byMailbox),
                ])
            @endif

            <h3 class="subheader">By channel</h3>
            @include('reports::partials.bars', [
                'rows' => array_map(function ($c) {
                    return ['label' => $c['name'], 'value' => $c['total']];
                }, $byChannel),
            ])
        </div>
    </div>

    {{-- ── Arrival pattern ───────────────────────────────────────── --}}
    <h3 class="subheader">When mail arrives</h3>

    <div class="descr-block">
        <p>
            Drives staffing, and shows whether the escalation windows configured in
            Triage match when mail actually arrives.
            @if ($peakHour)
                Busiest hour is <strong>{{ Format::hourLabel($peakHour['hour']) }}</strong>
                with {{ Format::number($peakHour['total']) }} conversation(s).
            @endif
        </p>
    </div>

    @php
        $peak = 0;
        foreach ($heatmap as $hours) { $peak = max($peak, max($hours)); }
    @endphp

    <div class="rep-heatmap-wrap">
        <table class="rep-heatmap">
            <thead>
                <tr>
                    <th></th>
                    @for ($h = 0; $h < 24; $h++)
                        <th>{{ $h }}</th>
                    @endfor
                </tr>
            </thead>
            <tbody>
            @for ($d = 1; $d <= 7; $d++)
                <tr>
                    <th>{{ Format::dayName($d) }}</th>
                    @for ($h = 0; $h < 24; $h++)
                        @php
                            $v = $heatmap[$d][$h];
                            $intensity = $peak ? round($v / $peak, 2) : 0;
                        @endphp
                        <td class="rep-cell"
                            style="background-color: rgba(54,124,201,{{ $intensity }})"
                            title="{{ Format::dayName($d) }} {{ Format::hourLabel($h) }} — {{ $v }} conversation(s)">
                        </td>
                    @endfor
                </tr>
            @endfor
            </tbody>
        </table>
        <p class="rep-muted rep-small">
            Server time, hour of day across the top. Darker means more mail.
        </p>
    </div>

    @endif

</div>
@endsection
