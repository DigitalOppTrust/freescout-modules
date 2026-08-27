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

<form method="GET" class="form-inline rep-filters" id="rep-filters">
    <div class="form-group">
        <label>Period</label>
        <select name="period" class="form-control input-sm" id="rep-period">
            @foreach (\Modules\DOTReports\Services\DateRange::$presets as $key => $label)
                <option value="{{ $key }}" {{ $range->preset === (string) $key ? 'selected' : '' }}>
                    {{ $label }}
                </option>
            @endforeach
            <option value="custom" {{ $range->preset === 'custom' ? 'selected' : '' }}>
                {{ $range->preset === 'custom' ? $range->label() : 'Custom range' }}
            </option>
        </select>
    </div>

    <div class="form-group">
        <label>From</label>
        <input type="date" name="from" class="form-control input-sm rep-date"
               value="{{ $range->start->toDateString() }}">
    </div>

    <div class="form-group">
        <label>To</label>
        <input type="date" name="to" class="form-control input-sm rep-date"
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

{{--
    Keep the period select and the date inputs telling the same story.
    Picking a preset clears the dates so the server cannot mistake the
    stale pre-filled range for a custom one; editing a date switches the
    select to "Custom range" so the dates are what gets applied.
--}}
<script>
(function () {
    var form   = document.getElementById('rep-filters');
    var period = document.getElementById('rep-period');
    if (!form || !period) { return; }

    var dates = form.querySelectorAll('.rep-date');

    period.addEventListener('change', function () {
        if (period.value !== 'custom') {
            for (var i = 0; i < dates.length; i++) { dates[i].value = ''; }
            form.submit();
        }
    });

    for (var i = 0; i < dates.length; i++) {
        dates[i].addEventListener('change', function () {
            period.value = 'custom';
        });
    }
})();
</script>

<p class="rep-period-note">
    {{ $range->label() }}
    <span class="rep-muted">
        &mdash; {{ $range->start->format('j M Y') }} to {{ $range->end->format('j M Y') }},
        compared with the preceding {{ $range->days() }} days
    </span>
</p>
