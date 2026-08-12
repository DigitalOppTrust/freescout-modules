@extends('layouts.app')

@section('title', 'Reports — Resolution')

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

    {{-- ── First response ────────────────────────────────────────── --}}
    <h3 class="subheader">First response</h3>

    <div class="descr-block">
        <p>
            Time from a conversation arriving to the first reply actually sent to the
            customer. Internal notes are excluded — a note is not a response, and
            counting one would let the team look responsive while the customer heard
            nothing.
        </p>
    </div>

    @if (!$frt['answered'])
        @include('reports::partials.empty', [
            'message' => 'No conversations in this period have had an agent reply yet.',
            'hint'    => $frt['received']
                ? $frt['received'].' conversation(s) received and still awaiting a first reply.'
                : 'No conversations were received in this period.',
        ])
    @else
        <div class="rep-stats">
            @include('reports::partials.stat', [
                'label' => 'Median first response',
                'value' => Format::duration($frt['elapsed']['median']),
                'trend' => null,
                'note'  => 'working time: '.Format::duration($frt['working']['median']),
            ])

            @include('reports::partials.stat', [
                'label' => 'Slowest 10% (p90)',
                'value' => Format::duration($frt['elapsed']['p90']),
                'trend' => null,
                'note'  => 'working time: '.Format::duration($frt['working']['p90']),
            ])

            @include('reports::partials.stat', [
                'label' => 'Awaiting first reply',
                'value' => Format::number($frt['unanswered']),
                'trend' => null,
                'note'  => 'of '.Format::number($frt['received']).' received',
            ])
        </div>

        @if (!$frt['elapsed']['significant'])
            <div class="rep-caveat rep-caveat-warn">
                <strong>Small sample.</strong>
                Based on {{ $frt['elapsed']['count'] }} conversation(s) —
                below the {{ config('reports.min_sample', 20) }} needed before
                percentiles mean much.
            </div>
        @endif
    @endif

    {{-- ── Resolution ────────────────────────────────────────────── --}}
    <h3 class="subheader">Resolution</h3>

    @if (!$resolution['timed'])
        @include('reports::partials.empty', [
            'message' => 'No conversations from this period have been resolved yet.',
            'hint'    => $resolution['closed_total']
                ? $resolution['closed_total'].' were closed, but none carried a usable timestamp.'
                : 'Nothing received in this period has been closed.',
        ])
    @else
        <div class="rep-stats">
            @include('reports::partials.stat', [
                'label' => 'Median resolution',
                'value' => Format::duration($resolution['elapsed']['median']),
                'trend' => null,
                'note'  => 'working time: '.Format::duration($resolution['working']['median']),
            ])

            @include('reports::partials.stat', [
                'label' => 'Slowest 10% (p90)',
                'value' => Format::duration($resolution['elapsed']['p90']),
                'trend' => null,
                'note'  => 'working time: '.Format::duration($resolution['working']['p90']),
            ])

            @include('reports::partials.stat', [
                'label' => 'Longest',
                'value' => Format::duration($resolution['elapsed']['max']),
                'trend' => null,
                'note'  => 'mean '.Format::duration($resolution['elapsed']['mean']),
            ])
        </div>

        {{-- The coverage statement. This is the point of the whole module. --}}
        <div class="rep-caveat">
            <strong>Coverage.</strong>
            Timed {{ $resolution['timed'] }} of {{ $resolution['closed_total'] }}
            closed conversation(s).
            @if ($resolution['from_fallback'])
                {{ $resolution['from_fallback'] }} had no <code>closed_at</code> value
                and were timed from their status-change history instead.
            @endif
            @if ($resolution['untimed'])
                <strong>{{ $resolution['untimed'] }} could not be timed at all</strong>
                and are excluded — the median above describes only the rest.
            @else
                Every closed conversation in this period could be timed.
            @endif
        </div>

        <p class="rep-muted rep-small">
            Medians and p90 rather than means throughout: a single ticket left over a
            holiday drags a mean far enough to make it useless. Working time excludes
            weekends, using the same calendar as Triage's escalation clock.
        </p>
    @endif

    <div class="row">
        {{-- ── Reply effort ──────────────────────────────────────── --}}
        <div class="col-md-6">
            <h3 class="subheader">Replies to resolve</h3>

            @if (!$effort['resolved'])
                @include('reports::partials.empty', [
                    'message' => 'Nothing resolved in this period.',
                ])
            @else
                <div class="rep-stats rep-stats-sm">
                    @include('reports::partials.stat', [
                        'label' => 'Median replies',
                        'value' => $effort['stats']['median'] === null
                            ? '—' : round($effort['stats']['median'], 1),
                        'trend' => null,
                        'note'  => 'per resolved conversation',
                    ])

                    @include('reports::partials.stat', [
                        'label' => 'First-contact resolution',
                        'value' => $effort['fcr_pct'] === null ? '—' : $effort['fcr_pct'].'%',
                        'trend' => null,
                        'note'  => $effort['fcr'].' of '.$effort['resolved'].' took one reply',
                    ])
                </div>

                <p class="rep-muted rep-small">
                    A rising reply count means questions are being answered badly the
                    first time. FCR is a direct read on answer quality that no timing
                    metric captures.
                </p>
            @endif
        </div>

        {{-- ── Reopened ──────────────────────────────────────────── --}}
        <div class="col-md-6">
            <h3 class="subheader">Reopened</h3>

            <div class="descr-block">
                <p>
                    The counterweight to resolution time. Closing early improves every
                    speed metric on this page, so premature closes would otherwise be
                    invisible — and rewarded.
                </p>
            </div>

            @if (!$reopened['count'])
                @include('reports::partials.empty', [
                    'message' => 'No conversations from this period were reopened after closing.',
                ])
            @else
                <div class="rep-stats rep-stats-sm">
                    @include('reports::partials.stat', [
                        'label' => 'Reopened after closing',
                        'value' => Format::number($reopened['count']),
                        'trend' => null,
                        'note'  => $reopened['pct'] === null
                            ? '' : $reopened['pct'].'% of closed conversations',
                    ])
                </div>
            @endif
        </div>
    </div>

    {{-- ── Backlog ───────────────────────────────────────────────── --}}
    <h3 class="subheader">Backlog</h3>

    <div class="descr-block">
        <p>
            Open conversations right now, by age — not restricted to the selected
            period, because a backlog is a present-tense fact. This is the leading
            indicator the averages hide: a team can hit every target on what it
            answers while quietly drowning in what it does not.
        </p>
    </div>

    @if (!$backlog['total'])
        @include('reports::partials.empty', [
            'message' => 'Nothing is currently open. The queue is clear.',
        ])
    @else
        @include('reports::partials.bars', [
            'rows' => array_map(function ($label, $value) {
                return ['label' => $label, 'value' => $value];
            }, array_keys($backlog['buckets']), array_values($backlog['buckets'])),
        ])

        <p class="rep-muted rep-small">
            {{ Format::number($backlog['total']) }} open in total.
            @if ($backlog['oldest'])
                Oldest has been waiting {{ round($backlog['oldest']) }} day(s).
            @endif
        </p>
    @endif

    {{-- ── Resolution rate ───────────────────────────────────────── --}}
    <h3 class="subheader">Resolution rate</h3>

    <div class="rep-stats">
        @include('reports::partials.stat', [
            'label' => 'Closed vs received',
            'value' => $rate['rate'] === null ? '—' : $rate['rate'].'%',
            'trend' => null,
            'note'  => Format::number($rate['closed']).' closed, '
                       .Format::number($rate['received']).' received',
        ])
    </div>

    <p class="rep-muted rep-small">
        Above 100% means the backlog is shrinking. This is a flow measure — the
        conversations closed are not necessarily the ones received, so it does not
        describe a cohort. It counts only closures carrying a real
        <code>closed_at</code>, so it understates where tickets are closed
        automatically.
    </p>

</div>
@endsection
