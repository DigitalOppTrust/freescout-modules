@extends('layouts.app')

@section('title', 'Triage')

@section('stylesheets')
    <link rel="stylesheet" href="{{ asset('modules/dottriage/css/module.css') }}">
@endsection

@section('content')
<div class="container">

    <h2 class="subheader">Triage</h2>

    @include('partials/flash_messages')

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
                <th class="text-center">Triaged</th>
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
                        {{ \Modules\DOTTriage\Services\BusinessTime::describe($p->escalate_after_minutes) }}
                    @elseif ($p)
                        <span class="triage-meta">{{ \Modules\DOTTriage\Services\BusinessTime::describe(config('triage.escalate_after_minutes')) }}</span>
                    @else
                        <span class="triage-meta">—</span>
                    @endif
                </td>
                <td class="text-center">
                    @php $c = $counts[$user->id] ?? null; @endphp
                    @if ($c)
                        <strong>{{ $c['total'] }}</strong>
                        @if ($c['overridden'])
                            <br><span class="triage-meta">{{ $c['overridden'] }} overridden</span>
                        @endif
                    @else
                        <span class="triage-meta">0</span>
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

    {{-- ── Escalation ────────────────────────────────────────────── --}}
    <div class="panel panel-default">
        <div class="panel-heading"><strong>Escalation</strong></div>
        <div class="panel-body">
            <p class="triage-meta" style="margin-bottom:12px;">
                A clock starts when a ticket is assigned and whenever the customer writes
                back; it stops when the assignee replies. Past the agent's window the
                escalation target is emailed; {{ \Modules\DOTTriage\Services\BusinessTime::describe(config('triage.reassign_after_minutes', 120)) }}
                later, if still unanswered, the ticket transfers to them. Checked every
                30 minutes, working time only.
                Last 30 days: <strong>{{ $escalationStats['notified'] }}</strong> notified,
                <strong>{{ $escalationStats['reassigned'] }}</strong> transferred.
            </p>

            @if ($escalations->isEmpty())
                <p class="triage-meta">No tickets are on the clock right now.</p>
            @else
                <table class="table table-condensed" style="margin-bottom:0;">
                    <thead>
                        <tr>
                            <th>Ticket</th><th>Assigned to</th><th>Escalates to</th>
                            <th>Quiet for</th><th>Window</th><th>Stage</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($escalations as $e)
                        @php $conv = $e->conversation; @endphp
                        <tr>
                            <td>
                                @if ($conv)
                                    <a href="{{ route('conversations.view', ['id' => $conv->id]) }}">#{{ $conv->number }}</a>
                                    {{ \Illuminate\Support\Str::limit($conv->subject, 50) }}
                                @else
                                    #{{ $e->conversation_id }}
                                @endif
                            </td>
                            <td>{{ $userNames[$e->assigned_user_id] ?? $e->assigned_user_id }}</td>
                            <td>{{ $userNames[$e->escalate_to_user_id] ?? '—' }}</td>
                            <td>{{ \Modules\DOTTriage\Services\BusinessTime::describe($e->minutesElapsed()) }}</td>
                            <td>{{ \Modules\DOTTriage\Services\BusinessTime::describe($e->escalate_after_minutes) }}</td>
                            <td>
                                @if ($e->notified_at)
                                    <span class="label label-warning">notified {{ $e->notified_at->diffForHumans() }}</span>
                                @elseif ($e->isDueForNotify())
                                    <span class="label label-danger">due</span>
                                @else
                                    <span class="triage-meta">waiting</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    {{-- ── Automatic closing ─────────────────────────────────────── --}}
    <div class="panel panel-default">
        <div class="panel-heading">
            <strong>Automatic closing</strong>
            <a href="{{ route('triage.closing.preview') }}"
               class="btn btn-default btn-xs pull-right">Preview what would close</a>
        </div>
        <div class="panel-body">
            <p class="triage-meta" style="margin-bottom:16px;">
                Closed tickets keep their history and stay searchable. The customer is
                never emailed — and if a ticket was closed wrongly, their next reply
                reopens it automatically.
            </p>

            <form method="POST" action="{{ route('triage.closing.save') }}" class="form-horizontal">
                {{ csrf_field() }}

                @foreach ($closing as $key => $s)
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
                        <button type="submit" class="btn btn-primary">Save closing settings</button>
                    </div>
                </div>
            </form>

            @if (count($closeStats))
                <hr>
                <div class="triage-stats">
                    @foreach ($closeStats as $reason => $n)
                        <span>
                            <span class="triage-meta">
                                {{ ['noise'         => 'Not a support request',
                                    'backlog_noise' => 'Not a support request (backlog)',
                                    'inactivity'    => 'Customer stopped replying',
                                    'resolved'      => 'Looked resolved',
                                    'not_reopened'  => 'Reply needed no action',
                                    'unrecorded'    => 'Closed (reason not recorded)'][$reason] ?? $reason }}
                            </span>
                            <strong>{{ $n }}</strong>
                        </span>
                    @endforeach
                    <span>
                        <span class="triage-meta">Reopened by a human</span>
                        <strong class="{{ $noiseReopened ? 'text-danger' : '' }}">{{ $noiseReopened }}</strong>
                    </span>
                </div>
                <p class="triage-meta" style="margin-top:10px;">
                    Last 30 days. Reopened tickets are the signal that a rule is closing
                    things it should not.
                </p>
            @endif
        </div>
    </div>

    {{-- ── Data retention ────────────────────────────────────────── --}}
    <div class="panel panel-default">
        <div class="panel-heading">
            <strong>Data retention</strong>
            <a href="{{ route('triage.retention.preview') }}"
               class="btn btn-default btn-xs pull-right">Preview what would be deleted</a>
        </div>
        <div class="panel-body">
            <p class="triage-meta" style="margin-bottom:16px;">
                Unlike closing, deletion is <strong>permanent</strong>: the conversation,
                its messages and its attachments are removed and cannot be recovered.
                Only resolved (closed) tickets are ever eligible.
            </p>

            <form method="POST" action="{{ route('triage.retention.save') }}" class="form-horizontal">
                {{ csrf_field() }}

                @foreach ($retention as $key => $s)
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
                        <button type="submit" class="btn btn-primary">Save retention settings</button>
                    </div>
                </div>
            </form>

            <hr>
            <div class="triage-stats">
                <span>
                    <span class="triage-meta">Past the retention period now</span>
                    <strong class="{{ $retentionEligible ? 'text-warning' : '' }}">{{ $retentionEligible }}</strong>
                </span>
            </div>
            <p class="triage-meta" style="margin-top:10px;">
                Deletion runs via <code>php artisan triage:retention --apply</code> on the
                server. Nothing is deleted just by saving these settings.
            </p>
        </div>
    </div>

    {{-- ── Non-support mail ──────────────────────────────────────── --}}
    <div class="panel panel-default">
        <div class="panel-heading"><strong>Non-support mail (30 days)</strong></div>
        <div class="panel-body">
            @if (count($noise))
                <div class="triage-stats">
                    @foreach ($noise as $cat => $n)
                        <span>
                            <span class="triage-meta">{{ \Modules\DOTTriage\Services\NoiseDetector::label($cat) }}</span>
                            <strong>{{ $n }}</strong>
                        </span>
                    @endforeach
                    <span>
                        <span class="triage-meta">Reopened by a human</span>
                        <strong class="{{ $noiseReopened ? 'text-danger' : '' }}">{{ $noiseReopened }}</strong>
                    </span>
                </div>
                @if ($noiseReopened)
                    <p class="triage-meta" style="margin-top:10px;">
                        Reopened conversations mean a detection rule is closing genuine
                        requests. Worth reviewing before it happens again.
                    </p>
                @endif
            @else
                <p class="triage-meta" style="margin:0;">
                    Nothing closed as non-support yet. Auto-replies, newsletters, system
                    notifications and delivery failures are closed automatically with a
                    note explaining why — they stay searchable and can be reopened.
                </p>
            @endif
        </div>
    </div>

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

</div>
@endsection

@section('javascripts')
    <script src="{{ asset('modules/dottriage/js/module.js') }}"></script>
@endsection
