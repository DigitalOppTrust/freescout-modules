<?php

namespace Modules\DOTReports\Services;

/**
 * Percentiles over a list of numbers.
 *
 * Computed in PHP rather than SQL, deliberately. MariaDB's PERCENTILE_CONT is
 * a window function, which is awkward to combine with grouped aggregates and
 * unavailable if this module ever runs against MySQL. The inputs here are a
 * single lean column of integers - at this install's volume, pulling them and
 * sorting costs nothing, and the result is identical on every engine.
 *
 * If a range ever returns enough rows for this to matter, the fix is the
 * nightly rollup table described in the plan, not clever SQL.
 */
class Stats
{
    /**
     * Median and p90 over a list of minute values.
     *
     * Medians, not means: one ticket left over a public holiday drags a mean
     * far enough to make it useless. The mean is returned alongside so the
     * two can be shown together, never the mean alone.
     */
    public static function summarise(array $values)
    {
        $count = count($values);

        if (!$count) {
            return [
                'count'  => 0,
                'median' => null,
                'p90'    => null,
                'mean'   => null,
                'min'    => null,
                'max'    => null,
                'significant' => false,
            ];
        }

        sort($values, SORT_NUMERIC);

        return [
            'count'       => $count,
            'median'      => self::percentile($values, 0.5, true),
            'p90'         => self::percentile($values, 0.9, true),
            'mean'        => array_sum($values) / $count,
            'min'         => $values[0],
            'max'         => $values[$count - 1],
            'significant' => Format::isSignificant($count),
        ];
    }

    /**
     * Linear-interpolated percentile, matching PERCENTILE_CONT semantics.
     *
     * @param array $values must already be sorted ascending
     * @param bool  $sorted set false to sort here
     */
    public static function percentile(array $values, $p, $sorted = false)
    {
        $count = count($values);

        if (!$count) {
            return null;
        }

        if (!$sorted) {
            sort($values, SORT_NUMERIC);
        }

        if ($count === 1) {
            return $values[0];
        }

        $index = ($count - 1) * $p;
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        if ($lower === $upper) {
            return $values[$lower];
        }

        // Interpolate between the two neighbouring values.
        return $values[$lower] + ($index - $lower) * ($values[$upper] - $values[$lower]);
    }

    /**
     * Bucket values into labelled ranges, for distribution charts.
     *
     * @param array $bounds ascending upper bounds; a final "and over" bucket
     *                      is appended automatically
     */
    public static function bucket(array $values, array $bounds, $labelFn = null)
    {
        $labelFn = $labelFn ?: function ($v) { return (string) $v; };

        $buckets = [];
        foreach ($bounds as $b) {
            $buckets[$labelFn($b)] = 0;
        }
        $buckets[$labelFn(end($bounds)).'+'] = 0;

        $keys = array_keys($buckets);

        foreach ($values as $v) {
            $placed = false;

            foreach ($bounds as $i => $b) {
                if ($v <= $b) {
                    $buckets[$keys[$i]]++;
                    $placed = true;
                    break;
                }
            }

            if (!$placed) {
                $buckets[$keys[count($bounds)]]++;
            }
        }

        return $buckets;
    }
}
