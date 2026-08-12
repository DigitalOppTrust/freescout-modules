<?php

return [
    'name' => 'Triage',

    // Master kill switch. Set TRIAGE_ENABLED=false in the FreeScout .env
    // to disable all module behaviour without removing the module.
    'enabled' => env('TRIAGE_ENABLED', false),

    // Claude API key — read from the server .env, NEVER committed.
    'api_key' => env('CLAUDE_API_KEY', ''),

    // Haiku is the sensible default: routing against a fixed list of agents
    // is classification, not hard reasoning, and every inbound email triggers
    // a call. Move to Sonnet only if measured accuracy proves inadequate.
    'model' => env('TRIAGE_MODEL', 'claude-haiku-4-5-20251001'),

    // Assign automatically only at or above this confidence. Below it, the
    // suggestion is recorded as a note and a human decides.
    'confidence_threshold' => env('TRIAGE_CONFIDENCE', 0.75),

    // Safety valve: stop calling the API after this many calls per day.
    'daily_call_limit' => env('TRIAGE_DAILY_LIMIT', 500),

    // Auto-assign, or only ever suggest? Start with suggest-only to measure
    // accuracy before granting autonomy.
    'auto_assign' => env('TRIAGE_AUTO_ASSIGN', false),

    // Default minutes without a reply to the customer before escalating.
    // Per-agent profiles can override this.
    'escalate_after_minutes' => env('TRIAGE_ESCALATE_AFTER', 240),

    // After the escalation target is notified, how long before ownership
    // actually transfers to them.
    'reassign_after_minutes' => env('TRIAGE_REASSIGN_AFTER', 120),

    // Maximum hops in an escalation chain, to bound runaway escalation.
    'max_escalation_depth' => env('TRIAGE_MAX_DEPTH', 3),

    // Truncate very long emails before sending to the API.
    'max_body_chars' => env('TRIAGE_MAX_BODY', 4000),

    // API request timeout in seconds.
    'timeout' => env('TRIAGE_TIMEOUT', 30),
];
