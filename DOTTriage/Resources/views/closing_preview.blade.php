@extends('layouts.app')
@section('title', 'Closing preview')
@section('stylesheets')
    <link rel="stylesheet" href="{{ asset('modules/dottriage/css/module.css') }}">
@endsection
@section('content')
<div class="container">
    <h2 class="subheader">
        <a href="{{ route('triage.settings') }}" class="triage-back">&larr; Triage</a>
        What would close
    </h2>

    <div class="descr-block">
        <p>
            Nothing on this page has been closed. This shows what the current settings
            would act on if a sweep ran now.
        </p>
    </div>

    <h3 class="subheader">Not a support request</h3>
    @if (count($noise))
        <table class="table table-condensed table-striped">
            <thead><tr><th>#</th><th>Subject</th><th>Why</th></tr></thead>
            <tbody>
            @foreach ($noise as $r)
                <tr>
                    <td>{{ $r['number'] }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($r['subject'], 55) }}</td>
                    <td class="triage-meta">{{ $r['reason'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <p class="triage-meta">Nothing — either none matches, or this rule is switched off.</p>
    @endif

    <h3 class="subheader">Customer stopped replying</h3>
    @if (count($inactive))
        <table class="table table-condensed table-striped">
            <thead><tr><th>#</th><th>Subject</th><th>Quiet for</th></tr></thead>
            <tbody>
            @foreach ($inactive as $r)
                <tr>
                    <td>{{ $r['number'] }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($r['subject'], 55) }}</td>
                    <td class="triage-meta">{{ $r['quiet'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <p class="triage-meta">Nothing — either none qualifies, or this rule is switched off.</p>
    @endif

    <h3 class="subheader">Looked resolved</h3>
    @if (isset($resolved['skipped']))
        <p class="triage-meta">{{ $resolved['skipped'] }}</p>
    @elseif (count($resolved))
        <table class="table table-condensed table-striped">
            <thead><tr><th>#</th><th>Subject</th><th class="text-center">Confidence</th><th>Reasoning</th></tr></thead>
            <tbody>
            @foreach ($resolved as $r)
                <tr>
                    <td>{{ $r['number'] }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($r['subject'], 40) }}</td>
                    <td class="text-center triage-confidence">{{ number_format($r['confidence'], 2) }}</td>
                    <td class="triage-meta">{{ \Illuminate\Support\Str::limit($r['reasoning'], 60) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @else
        <p class="triage-meta">Nothing looks resolved right now.</p>
    @endif

    <div class="descr-block" style="margin-top:20px;">
        <p class="triage-meta">
            To act on these, run <code>php artisan triage:sweep --apply</code> on the server.
        </p>
    </div>
</div>
@endsection
