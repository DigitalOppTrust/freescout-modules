{{--
    Server-rendered SVG line chart.

    FreeScout bundles no charting library, and vendoring one into a public
    repo to draw a single trend line is a poor trade. This renders on the
    server, needs no JavaScript, and still works if scripts are blocked.

    Expects:
      $series  array  label => value, in order
      $label   string series name for the tooltip text
--}}
@php
    $values = array_values($series);
    $keys   = array_keys($series);
    $count  = count($values);

    $w = 900;
    $h = 180;
    $pad = 24;

    $max = $count ? max($values) : 0;
    // Never divide by zero, and give a flat-zero series a sensible axis.
    $scale = $max > 0 ? $max : 1;

    $points = [];
    foreach ($values as $i => $v) {
        $x = $count > 1
            ? $pad + ($i / ($count - 1)) * ($w - 2 * $pad)
            : $w / 2;
        $y = $h - $pad - ($v / $scale) * ($h - 2 * $pad);
        $points[] = round($x, 1).','.round($y, 1);
    }

    $line = implode(' ', $points);
    // Close the path along the baseline for the soft fill underneath.
    $area = $count
        ? $line.' '.round($pad + ($w - 2 * $pad), 1).','.($h - $pad)
              .' '.$pad.','.($h - $pad)
        : '';
@endphp

<div class="rep-chart">
    <svg viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none"
         role="img" aria-label="{{ $label }} over time">

        {{-- Baseline and midpoint gridline --}}
        <line x1="{{ $pad }}" y1="{{ $h - $pad }}" x2="{{ $w - $pad }}" y2="{{ $h - $pad }}"
              class="rep-axis" />
        <line x1="{{ $pad }}" y1="{{ ($h - $pad + $pad) / 2 }}"
              x2="{{ $w - $pad }}" y2="{{ ($h - $pad + $pad) / 2 }}"
              class="rep-grid" />

        @if ($count > 1)
            <polygon points="{{ $area }}" class="rep-area" />
            <polyline points="{{ $line }}" class="rep-line" />
        @endif

        @foreach ($points as $i => $p)
            @php [$px, $py] = explode(',', $p); @endphp
            <circle cx="{{ $px }}" cy="{{ $py }}" r="{{ $count > 60 ? 1.5 : 2.5 }}"
                    class="rep-dot">
                <title>{{ $keys[$i] }} — {{ $values[$i] }}</title>
            </circle>
        @endforeach
    </svg>

    <div class="rep-chart-axis">
        <span>{{ $keys[0] ?? '' }}</span>
        <span class="rep-chart-max">peak {{ $max }}</span>
        <span>{{ $keys[$count - 1] ?? '' }}</span>
    </div>
</div>
