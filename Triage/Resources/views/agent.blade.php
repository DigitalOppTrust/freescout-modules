@extends('layouts.app')

@section('title', 'Triage — '.$user->getFullName())

@section('stylesheets')
    <link rel="stylesheet" href="{{ asset('modules/triage/css/module.css') }}">
@endsection

@section('content')
<div class="container">

    <h2 class="subheader">
        <a href="{{ route('triage.settings', ['mailbox_id' => $mailboxId]) }}"
           class="triage-back">&larr; Triage</a>
        {{ $user->getFullName() }}
    </h2>

    @include('partials/flash_messages')

    <form method="POST" action="{{ route('triage.profile.save') }}" class="form-horizontal">
        {{ csrf_field() }}
        <input type="hidden" name="user_id" value="{{ $user->id }}">
        <input type="hidden" name="mailbox_id" value="{{ $mailboxId }}">

        {{-- ── Routing ───────────────────────────────────────────── --}}
        <h3 class="subheader">Routing</h3>
        <div class="descr-block">
            <p>
                Describe what this agent handles, in plain language. <strong>This text is
                what the model reasons over</strong> — "billing enquiries, invoices, refunds
                and failed payments" routes far better than "general queries".
            </p>
        </div>

        <div class="form-group">
            <label class="col-sm-3 control-label">Handles</label>
            <div class="col-sm-9">
                <textarea name="description" class="form-control" rows="3"
                    placeholder="e.g. Billing enquiries, invoices, refunds and failed payments"
                >{{ old('description', $profile->description ?? '') }}</textarea>
                <p class="help-block">Leave blank to exclude this agent from AI routing entirely.</p>
            </div>
        </div>

        <div class="form-group">
            <label class="col-sm-3 control-label">Keywords</label>
            <div class="col-sm-9">
                <input type="text" name="keywords" class="form-control"
                    value="{{ old('keywords', $profile->keywords ?? '') }}"
                    placeholder="invoice, refund, payment failed">
                <p class="help-block">
                    Comma separated. Checked <em>before</em> the model runs — a match routes
                    instantly with no API call and no cost. Use for unambiguous terms only.
                </p>
            </div>
        </div>

        <div class="form-group">
            <label class="col-sm-3 control-label">Rotation group</label>
            <div class="col-sm-9">
                <input type="text" name="rotation_group" class="form-control"
                    value="{{ old('rotation_group', $profile->rotation_group ?? '') }}"
                    placeholder="e.g. frontline"
                    list="triage-rotation-groups">
                <datalist id="triage-rotation-groups">
                    @foreach ($rotationGroups as $g)
                        <option value="{{ $g }}">
                    @endforeach
                </datalist>
                <p class="help-block">
                    Optional. Agents sharing a group are treated as interchangeable: the model
                    picks the group, and tickets rotate to whoever was assigned least recently.
                    @if (!empty($groupPeers))
                        <br><strong>Currently sharing this group:</strong> {{ implode(', ', $groupPeers) }}
                    @endif
                </p>
            </div>
        </div>

        {{-- ── Escalation ────────────────────────────────────────── --}}
        <h3 class="subheader">Escalation</h3>
        <div class="descr-block">
            <p>
                If this agent has not replied to the customer within the window, the
                escalation target is notified. If it is still unanswered
                {{ config('triage.reassign_after_minutes') }} minutes later, the ticket
                transfers to them.
            </p>
            <p>
                <strong>Weekends are not counted.</strong> A ticket arriving Friday
                afternoon with a one-working-day window escalates on Monday afternoon,
                not over the weekend.
            </p>
        </div>

        <div class="form-group">
            <label class="col-sm-3 control-label">Escalate to</label>
            <div class="col-sm-9">
                <select name="escalate_to_user_id" class="form-control">
                    <option value="">— nobody —</option>
                    @foreach ($users as $u)
                        @if ($u->id !== $user->id)
                            <option value="{{ $u->id }}"
                                {{ old('escalate_to_user_id', $profile->escalate_to_user_id ?? '') == $u->id ? 'selected' : '' }}>
                                {{ $u->getFullName() }}
                            </option>
                        @endif
                    @endforeach
                </select>
                <p class="help-block">Loops are rejected when you save.</p>
            </div>
        </div>

        <div class="form-group">
            <label class="col-sm-3 control-label">Escalate after</label>
            <div class="col-sm-4">
                <select name="escalate_after_minutes" class="form-control">
                    <option value="">
                        Default — {{ \Modules\Triage\Services\BusinessTime::describe(config('triage.escalate_after_minutes')) }}
                    </option>
                    @foreach ([240 => '4 hours', 480 => '8 hours', 1440 => '1 working day', 2880 => '2 working days', 4320 => '3 working days', 7200 => '5 working days'] as $mins => $label)
                        <option value="{{ $mins }}"
                            {{ (string) old('escalate_after_minutes', $profile->escalate_after_minutes ?? '') === (string) $mins ? 'selected' : '' }}>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                <p class="help-block">Working time — weekends excluded.</p>
            </div>
        </div>

        {{-- ── Availability ──────────────────────────────────────── --}}
        <h3 class="subheader">Availability</h3>

        <div class="form-group">
            <label class="col-sm-3 control-label">Available</label>
            <div class="col-sm-9">
                <label class="control-label" style="font-weight:normal;">
                    <input type="checkbox" name="available" value="1"
                        {{ old('available', ($profile === null || $profile->available)) ? 'checked' : '' }}>
                    Include this agent in routing
                </label>
                <p class="help-block">Uncheck while on leave — existing tickets are unaffected.</p>
            </div>
        </div>

        <div class="form-group">
            <label class="col-sm-3 control-label">Max open tickets</label>
            <div class="col-sm-3">
                <input type="number" name="max_open" class="form-control"
                    value="{{ old('max_open', $profile->max_open ?? 0) }}" min="0">
                <p class="help-block">0 = no limit</p>
            </div>
            <div class="col-sm-6">
                <p class="help-block" style="margin-top:8px;">
                    Currently <strong>{{ $openCount }}</strong> open
                    {{ \Illuminate\Support\Str::plural('ticket', $openCount) }} assigned.
                    @if ($profile && $profile->isAtCapacity())
                        <br><span class="text-danger">At capacity — not receiving new tickets.</span>
                    @endif
                </p>
            </div>
        </div>

        <div class="form-group">
            <div class="col-sm-9 col-sm-offset-3">
                <button type="submit" class="btn btn-primary">Save</button>
                <a href="{{ route('triage.settings', ['mailbox_id' => $mailboxId]) }}"
                   class="btn btn-link">Cancel</a>

                @if ($profile)
                    <span class="triage-meta pull-right" style="margin-top:8px;">
                        @if ($profile->last_assigned_at)
                            Last routed a ticket {{ $profile->last_assigned_at->diffForHumans() }}
                        @else
                            Never routed a ticket
                        @endif
                    </span>
                @endif
            </div>
        </div>
    </form>

    @if ($profile)
        <hr>
        <form method="POST" action="{{ route('triage.profile.delete') }}"
              onsubmit="return confirm('Remove this agent from triage routing?');">
            {{ csrf_field() }}
            <input type="hidden" name="user_id" value="{{ $user->id }}">
            <input type="hidden" name="mailbox_id" value="{{ $mailboxId }}">
            <button type="submit" class="btn btn-link text-danger">
                Remove from triage routing
            </button>
            <span class="triage-meta">
                Deletes this profile. Does not affect the FreeScout user account.
            </span>
        </form>
    @endif

</div>
@endsection
