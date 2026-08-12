<?php

return [
    'name' => 'Triage',

    // Master kill switch. Set TRIAGE_ENABLED=false in the FreeScout .env
    // to disable all module behaviour without removing the module.
    'enabled' => env('TRIAGE_ENABLED', false),

    // Claude API key — read from the server .env, NEVER committed.
    'api_key' => env('CLAUDE_API_KEY', ''),

    'model' => env('TRIAGE_MODEL', 'claude-haiku-4-5-20251001'),

    // Assign automatically only at or above this confidence.
    'confidence_threshold' => env('TRIAGE_CONFIDENCE', 0.75),

    // Safety valve: stop calling the API after this many calls per day.
    'daily_call_limit' => env('TRIAGE_DAILY_LIMIT', 500),
];
