# FreeScout Modules

Custom [FreeScout](https://freescout.net/) modules built and maintained by
**Digital Opportunity Trust**.

## Modules

| Module | Status | Description |
|---|---|---|
| `DOTTriage` | In development | AI-assisted ticket routing and SLA escalation |
| `DOTReports` | In development | Volume, triage effectiveness and resolution reporting |
| `DOTLog` | In development | Per-conversation event log for debugging the mail pipeline |

### Triage

Routes incoming support conversations to the most appropriate agent based on
per-agent expertise profiles, and escalates tickets where the assignee has not
responded within a configured window.

- **Routing** uses a Claude model to match ticket content against agent profiles
- **Escalation** is deterministic — plain SLA timers, no model involved
- Every decision is recorded with its reasoning, so accuracy can be reviewed
  and the module can be switched to suggest-only or disabled entirely

### Reports

Read-only reporting on inbound volume, how much of it was triaged
automatically, and how long issues take to answer and resolve.

- **Derives timings from the `threads` table** rather than the conversation
  convenience columns, which are unreliable for reporting
- **Reports its own coverage** — resolution figures state how many
  conversations they could actually time, rather than hiding the gap
- **Elapsed and working-hours** durations side by side, reusing Triage's
  business-time calendar so the two modules cannot disagree
- Creates no tables and registers no hooks that act on tickets

See [`DOTReports/README.md`](DOTReports/README.md) for the metric definitions.

### DOTLog

A filterable per-conversation event log for debugging the mail pipeline: what
arrived, what triage did, who was assigned, what mail was actually sent.

- **One timeline per ticket** instead of four separate logs
- **Never stores message bodies** — metadata and subjects only, admin-only view
- **Self-pruning** — entries older than the configurable retention period
  (default 21 days) are deleted nightly

See [`DOTLog/README.md`](DOTLog/README.md) for the event reference.

## Installation

Modules are installed into a FreeScout instance's `Modules/` directory.
FreeScout excludes `/Modules` from its own `.gitignore`, so modules are
intended to live in separate repositories.

```bash
git clone https://github.com/DigitalOppTrust/freescout-modules.git /opt/freescout-modules
ln -s /opt/freescout-modules/DOTTriage  /path/to/freescout/Modules/DOTTriage
ln -s /opt/freescout-modules/DOTReports /path/to/freescout/Modules/DOTReports
ln -s /opt/freescout-modules/DOTLog     /path/to/freescout/Modules/DOTLog
cd /path/to/freescout
php artisan freescout:module-install
php artisan freescout:clear-cache
```

Enable the modules in **Manage → Modules**.

`deploy.sh` does all of the above, including the public asset symlinks that
`freescout:module-install` creates.

### Why the `DOT` prefix

FreeScout matches installed modules against its own module directory **by
alias**, and offers the directory's version as an update to anything sharing
one. A module called `reports` therefore collides with the official *paid*
Reports module, which FreeScout then advertises as an upgrade — and installing
it would overwrite ours.

Every module here is prefixed `DOT` (`dottriage`, `dotreports`) so the alias
namespace is ours alone. The alias also determines the public asset path, so
stylesheets live under `public/modules/dottriage` and `public/modules/dotreports`.

## Configuration

All configuration is read from the FreeScout instance's `.env` file at runtime.
No credentials are stored in this repository.

| Variable | Default | Description |
|---|---|---|
| `TRIAGE_ENABLED` | `false` | Master switch. Nothing runs when false. |
| `CLAUDE_API_KEY` | — | Claude API key |
| `TRIAGE_MODEL` | `claude-haiku-4-5-20251001` | Model used for routing |
| `TRIAGE_CONFIDENCE` | `0.75` | Minimum confidence to auto-assign |
| `TRIAGE_DAILY_LIMIT` | `500` | Maximum API calls per day |
| `DOTLOG_ENABLED` | `true` | DOTLog capture kill switch |
| `DOTLOG_RETENTION_DAYS` | `21` | DOTLog retention until saved in the UI |

## Requirements

- FreeScout 1.8.0 or later
- PHP 8.1+
- A Claude API key ([console.anthropic.com](https://console.anthropic.com))

## Contributing

This repository is public so the modules can be useful to other FreeScout
users. Issues and pull requests are welcome.

**Please do not include** API keys, server addresses, or other environment
specifics in issues or pull requests.

## Licence

MIT
