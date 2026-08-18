@extends('layouts.app')
@section('title', 'Retention preview')
@section('stylesheets')
    <link rel="stylesheet" href="{{ asset('modules/dottriage/css/module.css') }}">
@endsection
@section('content')
<div class="container">
    <h2 class="subheader">
        <a href="{{ route('triage.settings') }}" class="triage-back">&larr; Triage</a>
        What would be deleted
    </h2>

    <div class="descr-block">
        <p>
            Nothing on this page has been deleted. With a {{ $months }}-month retention
            period, tickets closed before <strong>{{ $cutoff }}</strong> are past
            retention — <strong>{{ $total }}</strong> in total.
            @if (!$enabled)
                Retention is currently <strong>switched off</strong>, so a sweep would
                delete nothing until it is enabled.
            @endif
        </p>
    </div>

    @if (count($rows))
        <table class="table table-condensed table-striped">
            <thead><tr><th>#</th><th>Subject</th><th>Closed</th></tr></thead>
            <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td>{{ $r['number'] }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($r['subject'], 60) }}</td>
                    <td class="triage-meta">{{ $r['closed'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if ($total > count($rows))
            <p class="triage-meta">
                Showing the first {{ count($rows) }} of {{ $total }} — one run deletes at
                most this many; the rest go in later runs.
            </p>
        @endif
    @else
        <p class="triage-meta">No closed ticket is past the retention period.</p>
    @endif

    <div class="descr-block" style="margin-top:20px;">
        <p class="triage-meta">
            To act on these, run <code>php artisan triage:retention --apply</code> on the
            server. Deletion is permanent — the conversation, its messages and its
            attachments are removed and cannot be recovered.
        </p>
    </div>
</div>
@endsection
