<?php

namespace Modules\DOTReports\Services;

/**
 * A reporting period, plus the equivalent preceding period for comparison.
 *
 * A number without a trend is nearly uninterpretable, so every range knows
 * how to produce the period immediately before it, of identical length. That
 * keeps "up 12% on the previous 30 days" honest - it is genuinely 30 days
 * against 30 days, not 30 against a calendar month of 31.
 *
 * Boundaries are inclusive of the whole start day and the whole end day, in
 * the application timezone. Reports group by day using that same timezone,
 * so a "day" means the same thing everywhere in the module.
 */
class DateRange
{
    /** @var \Carbon\Carbon start of the first day */
    public $start;

    /** @var \Carbon\Carbon end of the last day */
    public $end;

    /** @var string preset key, or 'custom' */
    public $preset;

    public static $presets = [
        'today'      => 'Today',
        '7'          => 'Last 7 days',
        '30'         => 'Last 30 days',
        '90'         => 'Last 90 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_year'  => 'This year',
    ];

    public function __construct($start, $end, $preset = 'custom')
    {
        $this->start  = $start->copy()->startOfDay();
        $this->end    = $end->copy()->endOfDay();
        $this->preset = $preset;
    }

    /**
     * Build from request input, falling back to the configured default.
     *
     * Invalid or reversed dates fall back rather than throwing - a report
     * page should never 500 because of a malformed query string.
     */
    public static function fromRequest($request)
    {
        $preset = $request->get('period');

        // An explicit custom range wins over a preset.
        $from = $request->get('from');
        $to   = $request->get('to');

        if ($from && $to) {
            try {
                $start = \Carbon\Carbon::parse($from);
                $end   = \Carbon\Carbon::parse($to);

                // Tolerate a reversed range rather than returning nothing.
                if ($start->gt($end)) {
                    [$start, $end] = [$end, $start];
                }

                return new self($start, $end, 'custom');
            } catch (\Exception $e) {
                // Fall through to the preset default.
            }
        }

        return self::preset($preset ?: (string) config('reports.default_days', 30));
    }

    public static function preset($key)
    {
        $now = \Carbon\Carbon::now();

        switch ($key) {
            case 'today':
                return new self($now, $now, 'today');

            case 'this_month':
                return new self($now->copy()->startOfMonth(), $now, 'this_month');

            case 'last_month':
                $start = $now->copy()->subMonthNoOverflow()->startOfMonth();
                return new self($start, $start->copy()->endOfMonth(), 'last_month');

            case 'this_year':
                return new self($now->copy()->startOfYear(), $now, 'this_year');

            default:
                $days = (int) $key ?: 30;
                // Inclusive of today, so "last 7 days" spans 7 days total.
                return new self($now->copy()->subDays($days - 1), $now, (string) $days);
        }
    }

    /**
     * The period of identical length ending immediately before this one.
     *
     * Calendar-month presets compare against the previous calendar month
     * instead, because "last month vs the 31 days before it" is not what
     * anyone means.
     */
    public function previous()
    {
        if ($this->preset === 'this_month' || $this->preset === 'last_month') {
            $start = $this->start->copy()->subMonthNoOverflow()->startOfMonth();

            return new self($start, $start->copy()->endOfMonth(), $this->preset);
        }

        $length = $this->days();
        $end    = $this->start->copy()->subDay();

        return new self($end->copy()->subDays($length - 1), $end, $this->preset);
    }

    /** Whole days covered, inclusive of both ends. */
    public function days()
    {
        return $this->start->diffInDays($this->end) + 1;
    }

    /** SQL-ready bounds. */
    public function startSql()
    {
        return $this->start->toDateTimeString();
    }

    public function endSql()
    {
        return $this->end->toDateTimeString();
    }

    public function label()
    {
        if (isset(self::$presets[$this->preset])) {
            return self::$presets[$this->preset];
        }

        return $this->start->format('j M Y').' - '.$this->end->format('j M Y');
    }

    /**
     * Query-string parameters that reproduce this range, for links and the
     * CSV export, so an export always matches what is on screen.
     */
    public function queryParams()
    {
        if ($this->preset === 'custom') {
            return [
                'from' => $this->start->toDateString(),
                'to'   => $this->end->toDateString(),
            ];
        }

        return ['period' => $this->preset];
    }

    /**
     * Every date in the range, as Y-m-d keys mapped to zero.
     *
     * Trend charts must show days with no activity as zero rather than
     * omitting them - a gap in a line chart reads as missing data, while a
     * zero reads as a quiet day, which is the truth.
     */
    public function emptyDaySeries()
    {
        $series = [];
        $cursor = $this->start->copy();

        while ($cursor->lte($this->end)) {
            $series[$cursor->toDateString()] = 0;
            $cursor->addDay();
        }

        return $series;
    }

    /**
     * Whether grouping by day is sensible, or the range is long enough that
     * weekly buckets read better.
     */
    public function groupBy()
    {
        return $this->days() > 92 ? 'week' : 'day';
    }
}
