@extends('layouts.app')

@section('title', 'Triage')

@section('stylesheets')
    <link rel="stylesheet" href="{{ asset('modules/triage/css/module.css') }}">
@endsection

@section('content')
<div class="container">

    <h2 class="subheader">Triage</h2>

    @include('partials/flash_messages')

    {{-- ── Connection status ─────────────────────────────────────── --}}
    <div class="panel panel-default">
        <div class="panel-heading">
            <strong>Claude connection</strong>
            <button type="button" class="btn btn-default btn-xs pull-right"
                    id="triage-test-connection"
                    data-url="{{ route('triage.test') }}">Test connection</button>
        </div>
        <div class="panel-body">
            <p id="triage-connection-result" style="margin-bottom:12px;">
                @if (config('triage.api_key'))
                    <span class="triage-meta">Key configured — click Test connection to verify.</span>
                @else
                    <span class="triage-status fail"><span class="dot"></span>No API key</span>
                    <span class="triage-meta">Set CLAUDE_API_KEY in the FreeScout .env file.</span>
                @endif
            </p>
            <div class="triage-stats">
                <span><span class="triage-meta">Model</span><strong>{{ config('triage.model') }}</strong></span>
                <span><span class="triage-meta">Triage</span>
                    <strong class="{{ config('triage.enabled') ? 'text-success' : 'text-muted' }}">
                        {{ config('triage.enabled') ? 'enabled' : 'disabled' }}
                    </strong>
                </span>
                <span><span class="triage-meta">Mode</span>
                    <strong>{{ config('triage.auto_assign') ? 'auto-assign' : 'suggest only' }}</strong>
                </span>
                <span><span class="triage-meta">API calls today</span>
                    <strong>{{ $callsToday }}</strong>
                    <span class="triage-meta">/ {{ config('triage.daily_call_limit') }}</span>
                </span>
                @if ($accuracy)
                    <span><span class="triage-meta">Accuracy (30d)</span>
                        <strong>{{ $accuracy['accuracy'] }}%</strong>
                        <span class="triage-meta">{{ $accuracy['overridden'] }}/{{ $accuracy['applied'] }} overridden</span>
                    </span>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Mailbox selector ──────────────────────────────────────── --}}
    @if ($mailboxes->count() > 1)
        <form method="GET" class="form-inline" style="margin-bottom:15px;">
            <label class="triage-meta" style="margin-right:8px;">Mailbox</label>
            <select name="mailbox_id" class="form-control input-sm" onchange="this.form.submit()">
                @foreach ($mailboxes as $mb)
                    <option value="{{ $mb->id }}" {{ $mailboxId == $mb->id ? 'selected' : '' }}>
                        {{ $mb->name }}
                    </option>
                @endforeach
            </select>
        </form>
    @endif

    {{-- ── Agent list ────────────────────────────────────────────── --}}
    <h3 class="subheader">Agents</h3>
    <div class="descr-block">
        <p>
            The <strong>Handles</strong> description is what the model reasons over when
            routing. Specific descriptions route far better than vague ones — use
            <strong>Set up</strong> or <strong>Edit</strong> to change them.
        </p>
    </div>

    <table class="table table-striped triage-agents">
        <thead>
            <tr>
                <th>Agent</th>
                <th>Handles</th>
                <th>Rotation</th>
                <th>Escalates to</th>
                <th class="text-center">SLA</th>
                <th class="text-center">Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        @foreach ($users as $user)
            @php $p = $profiles->get($user->id); @endphp
            <tr class="triage-agent-row">
                <td>
                    <a href="{{ route('triage.agent', ['id' => $user->id, 'mailbox_id' => $mailboxId]) }}">
                        <strong>{{ $user->getFullName() }}</strong>
                    </a><br>
                    <span class="triage-meta">{{ $user->email }}</span>
                </td>
                <td>
                    @if ($p && $p->description)
                        {{ \Illuminate\Support\Str::limit($p->description, 90) }}
                        @if ($p->keywords)
                            <br><span class="triage-meta">keywords: {{ $p->keywords }}</span>
                        @endif
                    @else
                        <span class="triage-meta">Not configured — excluded from routing</span>
                    @endif
                </td>
                <td>
                    @if ($p && $p->rotation_group)
                        <span class="label label-default">{{ $p->rotation_group }}</span>
                    @else
                        <span class="triage-meta">—</span>
                    @endif
                </td>
                <td>
                    @if ($p && $p->escalateTo)
                        {{ $p->escalateTo->getFullName() }}
                    @else
                        <span class="triage-meta">—</span>
                    @endif
                </td>
                <td class="text-center">
                    @if ($p && $p->escalate_after_minutes)
                        {{ $p->escalate_after_minutes }}m
                    @elseif ($p)
                        <span class="triage-meta">{{ config('triage.escalate_after_minutes') }}m</span>
                    @else
                        <span class="triage-meta">—</span>
                    @endif
                </td>
                <td class="text-center">
                    @if (!$p || !$p->description)
                        <span class="triage-status off"><span class="dot"></span>Off</span>
                    @elseif (!$p->available)
                        <span class="triage-status warn"><span class="dot"></span>Away</span>
                    @elseif ($p->isAtCapacity())
                        <span class="triage-status warn"><span class="dot"></span>Full</span>
                    @else
                        <span class="triage-status ok"><span class="dot"></span>Routing</span>
                    @endif
                </td>
                <td class="text-right">
                    <a href="{{ route('triage.agent', ['id' => $user->id, 'mailbox_id' => $mailboxId]) }}"
                       class="btn btn-default btn-xs">
                        {{ ($p && $p->description) ? 'Edit' : 'Set up' }}
                    </a>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <p class="triage-meta">
        {{ $profiles->filter(function($p){ return $p->description && $p->available; })->count() }}
        of {{ $users->count() }} agents available for routing.
    </p>

</div>
@endsection

@section('javascripts')
    <script src="{{ asset('modules/triage/js/module.js') }}"></script>
@endsection
