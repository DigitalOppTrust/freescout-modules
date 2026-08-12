<?php

return [
    'name' => 'Reports',

    // Below this many data points, percentiles are suppressed and only the
    // raw count is shown. A "median resolution time" from three tickets is
    // noise wearing a suit.
    'min_sample' => env('REPORTS_MIN_SAMPLE', 20),

    // Default period when none is given, in days.
    'default_days' => env('REPORTS_DEFAULT_DAYS', 30),

    // Rows in the per-agent and detail tables.
    'table_limit' => env('REPORTS_TABLE_LIMIT', 50),

    // Cost estimation for triage spend. Rates are US dollars per million
    // tokens; override when the model or pricing changes. These are not
    // billing figures - they are an order-of-magnitude guide.
    'cost_per_mtok_in'  => env('REPORTS_COST_IN', 1.00),
    'cost_per_mtok_out' => env('REPORTS_COST_OUT', 5.00),

    // Buckets for the confidence calibration curve. Each entry is the lower
    // bound; the last bucket runs to 1.0.
    'confidence_buckets' => [0.0, 0.5, 0.6, 0.7, 0.8, 0.9],

    // Backlog age buckets, in days.
    'backlog_buckets' => [1, 3, 7, 14, 30],
];
