# Triage

AI-assisted ticket routing and SLA escalation for FreeScout.

## Status

Skeleton only. The module loads and registers, but performs no triage yet.
`TRIAGE_ENABLED` defaults to `false`.

## How it will work

**Routing** — when a customer email arrives, the module matches the ticket
against per-agent expertise profiles and assigns it to the best fit. Decisions
below the confidence threshold are left unassigned with a suggestion note.

**Escalation** — if the assignee has not replied within the configured window,
a manager is notified. This is a plain timer comparison; no model is involved.

**Review** — every decision is recorded with its reasoning and whether a human
later overrode it, so routing accuracy can be measured rather than assumed.

## Hooks used

| Hook | Purpose |
|---|---|
| `thread.created` | Customer email arrives — trigger triage |
| `conversation.user_changed` | Detect human corrections to routing |
| `conversation.user_replied` | Reset the escalation timer |

## Safety

- `TRIAGE_ENABLED=false` disables all behaviour without uninstalling
- Hook registration is wrapped in try/catch, so a fault cannot take the site down
- Triage runs as a queued job — an API outage never blocks email fetching
- `TRIAGE_DAILY_LIMIT` caps API calls per day
