{{--
    Horizontal bar list, rendered as plain HTML.

    Used for distributions and buckets. Proportional width is easier to scan
    than a column of numbers, and needs no chart library to draw.

    Expects:
      $rows      array  [['label' => string, 'value' => int, 'note' => ?string], ...]
      $emptyText string shown when there is nothing to draw
--}}
@php
    $max = 0;
    foreach ($rows as $r) { $max = max($max, $r['value']); }
@endphp

@if (empty($rows) || $max === 0)
    <p class="rep-muted rep-small">{{ $emptyText ?? 'Nothing to show for this period.' }}</p>
@else
    <div class="rep-bars">
        @foreach ($rows as $r)
            <div class="rep-bar-row">
                <div class="rep-bar-label">{{ $r['label'] }}</div>
                <div class="rep-bar-track">
                    <div class="rep-bar-fill"
                         style="width: {{ $max ? round(($r['value'] / $max) * 100, 1) : 0 }}%"></div>
                </div>
                <div class="rep-bar-value">
                    {{ number_format($r['value']) }}
                    @if (!empty($r['note']))
                        <span class="rep-muted">{{ $r['note'] }}</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif
