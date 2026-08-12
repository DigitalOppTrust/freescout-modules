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
    <h3 class="subheader">Claude connection</h3>
    <div class="descr-block">
        <p>
            Triage uses the Claude API to route incoming conversations. The key is read
            from <code>CLAUDE_API_KEY</code> in the FreeScout <code>.env</code> file and is
            never stored in the database.
        </p>
        <p>
            <button type="button" class="btn btn-default btn-sm"
                    id="triage-test-connection"
                    data-url="{{ route('triage.test') }}">Test connection</button>
            <span id="triage-connection-result" style="margin-left:10px;">
                @if (config('triage.api_key'))
                    <span class="triage-meta">Key configured — click to verify.</span>
                @else
                    <span class="triage-status fail"><span class="dot"></span>No API key</span>
                    <span class="triage-meta">Set CLAUDE_API_KEY in .env</span>
                @endif
            </span>
        </p>
        <p class="triage-meta">
            Model: <strong>{{ config('triage.model') }}</strong> &nbsp;·&nbsp;
            Status: <strong>{{ config('triage.enabled') ? 'enabled' : 'disabled' }}</strong> &nbsp;·&nbsp;
            Auto-assign: <strong>{{ config('triage.auto_assign') ? 'on' : 'suggest only' }}</strong> &nbsp;·&nbsp;
            API calls today: <strong>{{ $callsToday }}</strong> / {{ config('triage.daily_call_limit') }}
            @if ($accuracy)
                &nbsp;·&nbsp; Routing accuracy (30d):
                <strong>{{ $accuracy['accuracy'] }}%</strong>
                <span class="triage-meta">({{ $accuracy['overridden'] }} of {{ $accuracy['applied'] }} overridden)</span>
            @endif
        </p>
    </div>

    {{-- ── Mailbox selector ──────────────────────────────────────── --}}
    @if ($mailboxes->count() > 1)
        <h3 class="subheader">Mailbox</h3>
        <div class="descr-block">
            <p>Profiles are configured per mailbox.</p>
            <form method="GET" class="form-inline">
                <select name="mailbox_id" class="form-control" onchange="this.form.submit()">
                    @foreach ($mailboxes as $mb)
                        <option value="{{ $mb->id }}" {{ $mailboxId == $mb->id ? 'selected' : '' }}>
                            {{ $mb->name }}
                        </option>
                    @endforeach
                </select>
            </form>
        </div>
    @endif

    {{-- ── Agent profiles ────────────────────────────────────────── --}}
    <h3 class="subheader">Agent profiles</h3>
    <div class="descr-block">
        <p>
            Describe what each agent handles. <strong>These descriptions are what the model
            reasons over</strong> — specific descriptions ("billing, invoices, refunds and
            failed payments") route far better than vague ones ("general queries").
        </p>
        <p>
            Give two or more agents the same <strong>rotation group</strong> to share a
            workload: the model picks the group, and tickets rotate between its members
            on a least-recently-assigned basis.
        </p>
        <p>
            <strong>Escalate to</strong> sets who receives the ticket if this agent has not
            replied to the customer within the SLA window. Loops are rejected on save.
        </p>
    </div>

    @foreach ($users as $user)
        @php $p = $profiles->get($user->id); @endphp
        <form method="POST" action="{{ route('triage.profile.save') }}" class="form-horizontal">
            {{ csrf_field() }}
            <input type="hidden" name="user_id" value="{{ $user->id }}">
            <input type="hidden" name="mailbox_id" value="{{ $mailboxId }}">

            <div class="panel panel-default">
                <div class="panel-heading">
                    <strong>{{ $user->getFullName() }}</strong>
                    <span class="triage-meta">{{ $user->email }}</span>
                    @if ($p && !$p->available)
                        <span class="triage-status warn" style="float:right;">
                            <span class="dot"></span>Unavailable
                        </span>
                    @elseif ($p)
                        <span class="triage-status ok" style="float:right;">
                            <span class="dot"></span>Routing
                        </span>
                    @endif
                </div>
                <div class="panel-body">

                    <div class="form-group">
                        <label class="col-sm-3 control-label">Handles</label>
                        <div class="col-sm-9">
                            <textarea name="description" class="form-control" rows="2"
                                placeholder="e.g. Billing enquiries, invoices, refunds and failed payments"
                            >{{ $p->description ?? '' }}</textarea>
                            <p class="help-block">Leave blank to exclude this agent from AI routing.</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-3 control-label">Keywords</label>
                        <div class="col-sm-9">
                            <input type="text" name="keywords" class="form-control"
                                value="{{ $p->keywords ?? '' }}"
                                placeholder="invoice, refund, payment">
                            <p class="help-block">
                                Comma separated. Matched before the model runs — a hit routes
                                instantly with no API call.
                            </p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-3 control-label">Rotation group</label>
                        <div class="col-sm-9">
                            <input type="text" name="rotation_group" class="form-control"
                                value="{{ $p->rotation_group ?? '' }}"
                                placeholder="e.g. frontline">
                            <p class="help-block">
                                Optional. Agents sharing a group take turns.
                            </p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-3 control-label">Escalate to</label>
                        <div class="col-sm-4">
                            <select name="escalate_to_user_id" class="form-control">
                                <option value="">— nobody —</option>
                                @foreach ($users as $u)
                                    @if ($u->id !== $user->id)
                                        <option value="{{ $u->id }}"
                                            {{ ($p && $p->escalate_to_user_id == $u->id) ? 'selected' : '' }}>
                                            {{ $u->getFullName() }}
                                        </option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                        <label class="col-sm-2 control-label">After (min)</label>
                        <div class="col-sm-3">
                            <input type="number" name="escalate_after_minutes" class="form-control"
                                value="{{ $p->escalate_after_minutes ?? '' }}"
                                placeholder="{{ config('triage.escalate_after_minutes') }}">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-3 control-label">Max open tickets</label>
                        <div class="col-sm-3">
                            <input type="number" name="max_open" class="form-control"
                                value="{{ $p->max_open ?? 0 }}" min="0">
                            <p class="help-block">0 = no limit</p>
                        </div>
                        <div class="col-sm-6">
                            <label class="control-label">
                                <input type="checkbox" name="available" value="1"
                                    {{ (!$p || $p->available) ? 'checked' : '' }}>
                                Available for routing
                            </label>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-sm-9 col-sm-offset-3">
                            <button type="submit" class="btn btn-primary">Save</button>
                            @if ($p)
                                <span class="triage-meta" style="margin-left:10px;">
                                    @if ($p->last_assigned_at)
                                        Last assigned {{ $p->last_assigned_at->diffForHumans() }}
                                    @else
                                        Never assigned
                                    @endif
                                </span>
                            @endif
                        </div>
                    </div>

                </div>
            </div>
        </form>
    @endforeach

</div>
@endsection

@section('javascripts')
    <script src="{{ asset('modules/triage/js/module.js') }}"></script>
@endsection
