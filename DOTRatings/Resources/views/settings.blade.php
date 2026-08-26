@extends('layouts.app')

@section('title', 'Ratings')

@section('content')
<div class="container">

    <h2 class="subheader">Ratings</h2>

    @include('partials/flash_messages')

    @if (!$enabled)
        <div class="alert alert-warning">
            This module is switched off in the server configuration
            (<code>DOTRATINGS_ENABLED=false</code>). Nothing below takes effect until
            that is changed.
        </div>
    @endif

    <div class="descr-block">
        <p>
            When a ticket is closed, the customer gets one email: what was closed, why,
            and a link to rate the support from one to five stars. Replying to that email
            reopens the ticket, so a closure that turns out to be wrong corrects itself.
        </p>
        <p>
            Mail closed as <strong>not a support request</strong> — newsletters,
            auto-replies, bounces — is never emailed, whatever these settings say.
            Replying to a spammer confirms the address is real.
        </p>
    </div>

    {{-- ── Headline numbers ──────────────────────────────────────── --}}
    <h3 class="subheader">Last 30 days</h3>

    <table class="table table-striped">
        <thead>
            <tr>
                <th>Closure emails sent</th>
                <th>Rated</th>
                <th>Response rate</th>
                <th>Average rating</th>
                <th>Reopened from the link</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $summary['sent'] }}</td>
                <td>{{ $summary['rated'] }}</td>
                <td>{{ $summary['response_rate'] === null ? '—' : $summary['response_rate'].'%' }}</td>
                <td>
                    @if ($summary['average'] === null)
                        —
                    @else
                        <strong>{{ number_format($summary['average'], 2) }}</strong> / 5
                    @endif
                </td>
                <td>{{ $summary['reopened'] }}</td>
            </tr>
        </tbody>
    </table>

    @if ($summary['rated'])
        <table class="table table-striped">
            <tbody>
                @foreach ($distribution as $stars => $count)
                    <tr>
                        <td style="width:120px;">{!! str_repeat('&#9733;', $stars) !!}</td>
                        <td style="width:60px;">{{ $count }}</td>
                        <td>
                            @php
                                $pct = $summary['rated'] ? round(($count / $summary['rated']) * 100) : 0;
                            @endphp
                            <div style="background:#e8eaed;border-radius:3px;height:14px;width:100%;">
                                <div style="background:#f0a202;height:14px;border-radius:3px;
                                            width:{{ $pct }}%;"></div>
                            </div>
                        </td>
                        <td style="width:60px;" class="text-right">{{ $pct }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p><a href="{{ route('dotratings.list') }}">See individual ratings and comments &rarr;</a></p>
    @endif

    {{-- ── Settings ──────────────────────────────────────────────── --}}
    <h3 class="subheader">Settings</h3>

    <form method="POST" action="{{ route('dotratings.settings.save') }}" class="form-horizontal">
        {{ csrf_field() }}

        @foreach (array_merge($sending, $link) as $key => $s)
            <div class="form-group">
                @if ($s['type'] === 'bool')
                    <div class="col-sm-9 col-sm-offset-3">
                        <label style="font-weight:normal;">
                            <input type="checkbox" name="{{ $key }}" value="1"
                                {{ $s['value'] ? 'checked' : '' }}>
                            <strong>{{ $s['label'] }}</strong>
                        </label>
                        <p class="help-block" style="margin-top:2px;">{{ $s['help'] }}</p>
                    </div>
                @else
                    <label class="col-sm-3 control-label">{{ $s['label'] }}</label>
                    <div class="col-sm-4">
                        <select name="{{ $key }}" class="form-control">
                            @foreach ($s['choices'] as $val => $label)
                                <option value="{{ $val }}"
                                    {{ (string) $s['value'] === (string) $val ? 'selected' : '' }}>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                        <p class="help-block">{{ $s['help'] }}</p>
                    </div>
                @endif
            </div>
        @endforeach

        <div class="form-group">
            <div class="col-sm-9 col-sm-offset-3">
                <button type="submit" class="btn btn-primary">Save ratings settings</button>
            </div>
        </div>
    </form>

</div>
@endsection
