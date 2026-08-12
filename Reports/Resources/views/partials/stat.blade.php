{{--
    A headline figure with its period-over-period movement.

    Expects:
      $label   string
      $value   string  already formatted
      $trend   Trend|null
      $note    string|null  caveat shown under the number
--}}
<div class="rep-stat">
    <div class="rep-stat-label">{{ $label }}</div>

    <div class="rep-stat-value">{{ $value }}</div>

    @if (!empty($trend) && $trend->hasComparison())
        <div class="rep-stat-trend rep-{{ $trend->sentiment() }}">
            {{ $trend->changeLabel() }}
            <span class="rep-muted">vs previous period</span>
        </div>
    @else
        <div class="rep-stat-trend rep-muted">no comparison available</div>
    @endif

    @if (!empty($note))
        <div class="rep-stat-note">{{ $note }}</div>
    @endif
</div>
