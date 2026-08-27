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

    // Default working time without a reply to the customer before escalating.
    // 1440 = one working day. Per-agent profiles can override this.
    'escalate_after_minutes' => env('TRIAGE_ESCALATE_AFTER', 1440),

    // Escalation clocks count working time only, so a ticket arriving on
    // Friday afternoon does not escalate over the weekend. ISO-8601 day
    // numbers: 6 = Saturday, 7 = Sunday.
    'weekend_days' => array_filter(array_map('intval',
        explode(',', (string) env('TRIAGE_WEEKEND_DAYS', '6,7')))),

    // After the escalation target is notified, how long before ownership
    // actually transfers to them.
    'reassign_after_minutes' => env('TRIAGE_REASSIGN_AFTER', 120),

    // Maximum hops in an escalation chain, to bound runaway escalation.
    'max_escalation_depth' => env('TRIAGE_MAX_DEPTH', 3),

    // Truncate very long emails before sending to the API.
    'max_body_chars' => env('TRIAGE_MAX_BODY', 4000),

    // API request timeout in seconds.
    'timeout' => env('TRIAGE_TIMEOUT', 30),

    // Quick in-process attempts per API call on transient failures (network,
    // 429, 5xx). The queued job then backs off for longer between its own
    // attempts, and triage:run --failed catches anything still unrouted.
    'api_attempts' => env('TRIAGE_API_ATTEMPTS', 3),

    // Email the escalation target directly (in addition to the note on the
    // ticket). Off means notes only.
    'escalation_email' => env('TRIAGE_ESCALATION_EMAIL', true),

    // When a customer replies to a closed ticket, the model must be at least
    // this confident that the reply needs no action before the ticket is put
    // back to closed. Below it, the ticket stays open for a person.
    'reopen_confidence' => env('TRIAGE_REOPEN_CONFIDENCE', 0.75),

    // ── Automatic closing ────────────────────────────────────────────
    // Working minutes of customer silence after an agent reply before a
    // conversation is closed as inactive. 7200 = 5 working days.
    'close_after_inactive_minutes' => env('TRIAGE_CLOSE_INACTIVE_AFTER', 7200),

    // Ask the model whether a conversation is finished. Off by default:
    // closing an unresolved issue makes a customer think they were ignored,
    // and nobody notices because the ticket has left the queue.
    'close_resolved_enabled' => env('TRIAGE_CLOSE_RESOLVED', false),

    // A conversation must be quiet this long before the model is even asked,
    // so an active exchange is never judged mid-flight. 1440 = 1 working day.
    'resolved_min_quiet_minutes' => env('TRIAGE_RESOLVED_QUIET', 1440),

    // The model must be at least this confident before a close happens.
    'resolved_confidence' => env('TRIAGE_RESOLVED_CONFIDENCE', 0.85),
];
