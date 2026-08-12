<?php

namespace Modules\DOTReports\Services;

/**
 * Presentation helpers shared by every report.
 *
 * Duration formatting in particular lives here rather than in the views,
 * because "4h 12m" and "4.2 hours" appearing on different tabs of the same
 * module is the kind of small inconsistency that makes people doubt the
 * numbers themselves.
 */
class Format
{
    /**
     * Minutes as a compact human duration.
     *
     * Deliberately coarse: nobody makes a decision on the difference between
     * 4h 12m and 4h 13m, and false precision invites false confidence.
     */
    public static function duration($minutes)
    {
        if ($minutes === null) {
            return '-';
        }

        $minutes = (int) round($minutes);

        if ($minutes < 1) {
            return 'under a minute';
        }

        if ($minutes < 60) {
            return $minutes.'m';
        }

        $hours = intdiv($minutes, 60);
        $mins  = $minutes % 60;

        if ($hours < 24) {
            return $mins ? $hours.'h '.$mins.'m' : $hours.'h';
        }

        $days  = intdiv($hours, 24);
        $hours = $hours % 24;

        return $hours ? $days.'d '.$hours.'h' : $days.'d';
    }

    /**
     * A percentage, or a dash when the denominator is zero.
     *
     * Zero-over-zero is not 0% - it is "nothing to measure", and showing 0%
     * would read as a real, bad result.
     */
    public static function percent($numerator, $denominator, $decimals = 1)
    {
        if (!$denominator) {
            return null;
        }

        return round(($numerator / $denominator) * 100, $decimals);
    }

    public static function percentLabel($numerator, $denominator, $decimals = 1)
    {
        $pct = self::percent($numerator, $denominator, $decimals);

        return $pct === null ? '-' : $pct.'%';
    }

    /**
     * Whether a sample is large enough to quote percentiles from.
     *
     * Below the threshold the caller shows the raw count instead. This is the
     * difference between reporting and fortune telling.
     */
    public static function isSignificant($count)
    {
        return $count >= (int) config('reports.min_sample', 20);
    }

    /** Money, for the triage cost panel. */
    public static function money($amount)
    {
        if ($amount === null) {
            return '-';
        }

        if ($amount > 0 && $amount < 0.01) {
            return '<$0.01';
        }

        return '$'.number_format($amount, 2);
    }

    public static function number($value)
    {
        return $value === null ? '-' : number_format((int) $value);
    }

    /** ISO day number to short name, matching BusinessTime's convention. */
    public static function dayName($iso)
    {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu',
                  5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

        return $names[$iso] ?? '';
    }

    /** Hour of day as a short label, e.g. 14 => "14:00". */
    public static function hourLabel($hour)
    {
        return sprintf('%02d:00', $hour);
    }
}
