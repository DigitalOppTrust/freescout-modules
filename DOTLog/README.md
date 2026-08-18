# DOTLog

A per-conversation event log for debugging the FreeScout mail pipeline:
what arrived, what triage did with it, who was assigned, and what mail
FreeScout actually sent — one filterable timeline under **Manage → DOTLog**.

## Why

When mail misbehaves, the evidence is scattered: fetch activity in
`laravel.log`, triage reasoning in conversation notes, sends in FreeScout's
send log, and some events (a module assigning a ticket without firing the
notification event, an agent replying from outside FreeScout) leave no trace
anywhere. DOTLog records the pipeline as it happens, so "why did nobody get
an email for ticket X?" is answered by reading one page instead of four logs.

## What it records

| Event | Meaning |
|---|---|
| `conversation.created` | A customer email became a new ticket |
| `thread.customer` / `thread.agent` / `thread.note` | Message or note added to a ticket |
| `conversation.assigned` | Assignment changed **via the core event** — if a ticket changed hands with no entry here, whatever assigned it bypassed notifications |
| `conversation.status` | Status changed (active / pending / closed / spam) |
| `mail.sent` | An email was handed to SMTP — recipient, subject and FreeScout mail type; never the body |
| `triage.*` | Written by the DOTTriage module when both are installed |

Message bodies are never stored. The log is visible to administrators only.

## Retention

Entries are pruned automatically every night. The period defaults to
**21 days** and is set under **Manage → DOTLog** (or `DOTLOG_RETENTION_DAYS`
in `.env` until first saved). Manual run:

```bash
php artisan dotlog:prune          # apply
php artisan dotlog:prune --dry    # preview only
php artisan dotlog:prune --days=7 # one-off override
```

The schedule registers itself through FreeScout's scheduler, so the standard
`schedule:run` cron is all it needs.

## Logging from other modules

```php
if (class_exists(\Modules\DOTLog\Services\DotLog::class)) {
    \Modules\DOTLog\Services\DotLog::write('mymodule.thing', 'What happened', [
        'conversation' => $conversation,   // model or id, optional
        'level'        => 'info',          // info | warning | error
        'context'      => ['key' => 'value'],
    ]);
}
```

`write()` never throws; a failed insert degrades to a `laravel.log` warning.

## Configuration

| Variable | Default | Description |
|---|---|---|
| `DOTLOG_ENABLED` | `true` | Capture kill switch. The viewer and pruning stay active when off. |
| `DOTLOG_RETENTION_DAYS` | `21` | Fallback retention until the setting is saved in the UI. |
