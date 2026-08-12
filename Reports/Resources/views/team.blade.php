@extends('layouts.app')

@section('title', 'Reports — Team')

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

    {{-- The caution is part of the deliverable, not decoration. --}}
    <div class="rep-caveat">
        <strong>Read this as workload, not performance.</strong>
        Ticket counts are a poor proxy for contribution — one hard ticket can be
        worth thirty password resets, and whoever takes the difficult work will look
        slower in every column here. These figures are for spotting overload and
        imbalance.
    </div>

    <h3 class="subheader">Per agent</h3>

    @if (empty($agents))
        @include('reports::partials.empty', [
            'message' => 'No agent activity recorded in this period.',
            'hint'    => 'Nobody replied to, was assigned, or closed a conversation.',
        ])
    @else
        <table class="table table-striped rep-table rep-team">
            <thead>
                <tr>
                    <th>Agent</th>
                    <th class="text-right">Assigned</th>
                    <th class="text-right">Replies</th>
                    <th class="text-right">Resolved</th>
                    <th class="text-right">Open now</th>
                    <th class="text-right">Median first response</th>
                    <th class="text-right">Median resolution</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($agents as $a)
                <tr>
                    <td>
                        {{ $a['name'] }}
                        @if (!$a['active'])
                            <span class="rep-muted rep-small">(inactive)</span>
                        @endif
                    </td>
                    <td class="text-right">{{ Format::number($a['assigned']) }}</td>
                    <td class="text-right">{{ Format::number($a['replies']) }}</td>
                    <td class="text-right">{{ Format::number($a['resolved']) }}</td>
                    <td class="text-right">{{ Format::number($a['open']) }}</td>
                    <td class="text-right">
                        {{ Format::duration($a['first_response']['median']) }}
                        @if ($a['first_response']['count'] && !$a['first_response']['significant'])
                            <span class="rep-muted rep-small">
                                (n={{ $a['first_response']['count'] }})
                            </span>
                        @endif
                    </td>
                    <td class="text-right">
                        {{ Format::duration($a['resolution']['median']) }}
                        @if ($a['resolution']['count'] && !$a['resolution']['significant'])
                            <span class="rep-muted rep-small">
                                (n={{ $a['resolution']['count'] }})
                            </span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <p class="rep-muted rep-small">
            Timings are attributed to whoever sent the first reply, not the current
            assignee — tickets get reassigned afterwards for all sorts of reasons.
            <code>n=</code> marks samples too small to read as a median.
            @if ($unattributed)
                {{ $unattributed }} closure(s) in this period had no recorded user and
                are missing from the Resolved column.
            @endif
        </p>
    @endif

    {{-- ── Unassigned dwell ──────────────────────────────────────── --}}
    <h3 class="subheader">Time spent unassigned</h3>

    <div class="descr-block">
        <p>
            From arrival to first assignment. This separates triage lag from agent
            lag: a slow first response caused by a ticket sitting unassigned for a day
            is a routing problem, not an agent problem, and the two have completely
            different fixes.
        </p>
    </div>

    @if (!$dwell['elapsed']['count'])
        @include('reports::partials.empty', [
            'message' => 'No assignment events recorded for conversations in this period.',
            'hint'    => 'Assignments made before a conversation existed, or by direct database change, are not visible here.',
        ])
    @else
        <div class="rep-stats">
            @include('reports::partials.stat', [
                'label' => 'Median time unassigned',
                'value' => Format::duration($dwell['elapsed']['median']),
                'trend' => null,
                'note'  => 'working time: '.Format::duration($dwell['working']['median']),
            ])

            @include('reports::partials.stat', [
                'label' => 'Slowest 10% (p90)',
                'value' => Format::duration($dwell['elapsed']['p90']),
                'trend' => null,
                'note'  => 'from '.$dwell['elapsed']['count'].' assignment(s)',
            ])
        </div>
    @endif

    {{-- ── Escalations by agent ──────────────────────────────────── --}}
    <h3 class="subheader">Escalations by agent</h3>

    @if ($escalations === null)
        @include('reports::partials.empty', [
            'message' => 'The Triage module is not installed, so no escalation data exists.',
        ])
    @elseif (empty($escalations))
        @include('reports::partials.empty', [
            'message' => 'No escalations were raised in this period.',
        ])
    @else
        <table class="table table-striped rep-table">
            <thead>
                <tr>
                    <th>Agent</th>
                    <th class="text-right">Escalations raised</th>
                    <th class="text-right">Target notified</th>
                    <th class="text-right">Reassigned away</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($escalations as $e)
                <tr>
                    <td>{{ $e['name'] }}</td>
                    <td class="text-right">{{ $e['total'] }}</td>
                    <td class="text-right">{{ $e['notified'] }}</td>
                    <td class="text-right">{{ $e['reassigned'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <p class="rep-muted rep-small">
            A workload signal rather than a fault: the same person appearing often
            usually means they are carrying too much, or their SLA is set too tight.
        </p>
    @endif

</div>
@endsection
