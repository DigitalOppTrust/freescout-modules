{{-- Shared chrome: tabs, period picker, mailbox filter, export link. --}}

<ul class="nav nav-tabs rep-tabs">
    @foreach ([
        'overview'   => ['reports.overview',   'Overview'],
        'triage'     => ['reports.triage',     'Triage'],
        'resolution' => ['reports.resolution', 'Resolution'],
        'team'       => ['reports.team',       'Team'],
    ] as $key => $t)
        <li class="{{ $tab === $key ? 'active' : '' }}">
            <a href="{{ route($t[0], $params) }}">{{ $t[1] }}</a>
        </li>
    @endforeach
</ul>

<form method="GET" class="form-inline rep-filters">
    <div class="form-group">
        <label>Period</label>
        <select name="period" class="form-control input-sm" onchange="this.form.submit()">
            @foreach (\Modules\DOTReports\Services\DateRange::$presets as $key => $label)
                <option value="{{ $key }}" {{ $range->preset === (string) $key ? 'selected' : '' }}>
                    {{ $label }}
                </option>
            @endforeach
            @if ($range->preset === 'custom')
                <option value="custom" selected>{{ $range->label() }}</option>
            @endif
        </select>
    </div>

    <div class="form-group">
        <label>From</label>
        <input type="date" name="from" class="form-control input-sm"
               value="{{ $range->start->toDateString() }}">
    </div>

    <div class="form-group">
        <label>To</label>
        <input type="date" name="to" class="form-control input-sm"
               value="{{ $range->end->toDateString() }}">
    </div>

    @if ($mailboxes->count() > 1)
        <div class="form-group">
            <label>Mailbox</label>
            <select name="mailbox_id" class="form-control input-sm" onchange="this.form.submit()">
                <option value="">All mailboxes</option>
                @foreach ($mailboxes as $mb)
                    <option value="{{ $mb->id }}" {{ $mailboxId == $mb->id ? 'selected' : '' }}>
                        {{ $mb->name }}
                    </option>
                @endforeach
            </select>
        </div>
    @endif

    <button type="submit" class="btn btn-default btn-sm">Apply</button>

    @if (in_array($tab, ['overview', 'triage', 'resolution', 'team']))
        @php
            $exportName = $tab === 'overview' ? 'volume' : $tab;
        @endphp
        <a href="{{ route('reports.export', ['report' => $exportName] + $params) }}"
           class="btn btn-link btn-sm rep-export">Export CSV</a>
    @endif
</form>

<p class="rep-period-note">
    {{ $range->label() }}
    <span class="rep-muted">
        &mdash; {{ $range->start->format('j M Y') }} to {{ $range->end->format('j M Y') }},
        compared with the preceding {{ $range->days() }} days
    </span>
</p>
