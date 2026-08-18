@extends('layouts.app')

@section('title', 'DOTLog')

@section('stylesheets')
    <link rel="stylesheet" href="{{ asset('modules/dotlog/css/module.css') }}">
@endsection

@section('content')
<div class="container">

    <h2 class="subheader">DOTLog</h2>

    @include('partials/flash_messages')

    @if (!$capturing)
        <div class="alert alert-warning">
            Event capture is switched off (<code>DOTLOG_ENABLED=false</code>).
            Existing entries remain readable and retention still applies, but
            nothing new is being recorded.
        </div>
    @endif

    {{-- ── Retention ─────────────────────────────────────────────── --}}
    <div class="panel panel-default">
        <div class="panel-heading"><strong>Retention</strong></div>
        <div class="panel-body">
            <form method="POST" action="{{ route('dotlog.settings.save') }}" class="form-inline">
                {{ csrf_field() }}

                @foreach ($retention as $key => $s)
                    <label class="dotlog-meta" style="margin-right:8px;">{{ $s['label'] }}</label>
                    <select name="{{ $key }}" class="form-control input-sm" style="margin-right:8px;">
                        @foreach ($s['choices'] as $val => $label)
                            <option value="{{ $val }}"
                                {{ (string) $s['value'] === (string) $val ? 'selected' : '' }}>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                @endforeach

                <button type="submit" class="btn btn-primary btn-sm">Save</button>

                <p class="help-block" style="margin-top:8px;">
                    {{ reset($retention)['help'] }}
                    The prune runs automatically each night; <code>php artisan dotlog:prune</code>
                    runs it by hand.
                </p>
            </form>
        </div>
    </div>

    {{-- ── Filters ───────────────────────────────────────────────── --}}
    <form method="GET" class="form-inline dotlog-filters">
        <input type="text" name="conversation" class="form-control input-sm"
               placeholder="Ticket # or conversation id"
               value="{{ $filters['conversation'] }}">

        <select name="event" class="form-control input-sm">
            <option value="">All events</option>
            @foreach ($events as $ev)
                <option value="{{ $ev }}" {{ $filters['event'] === $ev ? 'selected' : '' }}>
                    {{ $ev }}
                </option>
            @endforeach
        </select>

        <select name="level" class="form-control input-sm">
            <option value="">All levels</option>
            @foreach (['info', 'warning', 'error'] as $lv)
                <option value="{{ $lv }}" {{ $filters['level'] === $lv ? 'selected' : '' }}>
                    {{ ucfirst($lv) }}
                </option>
            @endforeach
        </select>

        <button type="submit" class="btn btn-default btn-sm">Filter</button>
        @if ($filters['conversation'] !== '' || $filters['event'] !== '' || $filters['level'] !== '')
            <a href="{{ route('dotlog.index') }}" class="btn btn-link btn-sm">Clear</a>
        @endif
    </form>

    {{-- ── Entries ───────────────────────────────────────────────── --}}
    <table class="table table-striped dotlog-table">
        <thead>
            <tr>
                <th class="dotlog-time">Time</th>
                <th>Event</th>
                <th>Ticket</th>
                <th>What happened</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($entries as $entry)
            <tr class="dotlog-{{ $entry->level }}">
                <td class="dotlog-time dotlog-meta">
                    {{ $entry->created_at ? $entry->created_at->format('Y-m-d H:i:s') : '' }}
                </td>
                <td><code>{{ $entry->event }}</code></td>
                <td>
                    @if ($entry->conversation_id)
                        <a href="{{ url('conversation/'.$entry->conversation_id) }}">
                            #{{ $entry->conversation_id }}
                        </a>
                    @endif
                </td>
                <td>
                    {{ $entry->message }}
                    @if ($entry->context)
                        <div class="dotlog-context dotlog-meta">
                            @foreach ($entry->context as $k => $v)
                                <span><strong>{{ $k }}:</strong>
                                    {{ is_scalar($v) || $v === null ? $v : json_encode($v) }}</span>
                            @endforeach
                        </div>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="4" class="text-center dotlog-meta" style="padding:30px;">
                    No log entries match.
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>

    {{ $entries->links() }}

</div>
@endsection
