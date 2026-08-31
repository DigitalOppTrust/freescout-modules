# FreeScout Modules

Custom [FreeScout](https://freescout.net/) modules built and maintained by
**Digital Opportunity Trust**.

## Modules

| Module | Status | Description |
|---|---|---|
| `DOTTriage` | In development | AI-assisted ticket routing and SLA escalation |
| `DOTReports` | In development | Volume, triage effectiveness and resolution reporting |
| `DOTLog` | In development | Per-conversation event log for debugging the mail pipeline |
| `DOTHelp` | In development | In-app handbook explaining the desk and its modules to new staff |
| `DOTSSO` | In development | Google Workspace sign-in, restricted to existing users |

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

### DOTHelp

An in-app handbook for a support person who has just been given an account:
what the desk is, how a ticket moves through it, and which of the automatic
behaviours they are about to notice are deliberate.

- **Reachable from the queue** — `Help` in the main navigation, and under
  `Manage`. Readable by every logged-in user, because an onboarding guide
  nobody can open has failed at its one job
- **Documents what is true, not what was planned** — where a feature is
  configurable but inert, the handbook says so rather than describing it as
  working
- **Read-only by construction** — no tables, no hooks, no scheduled work; two
  `GET` routes and a set of Blade partials

See [`DOTHelp/README.md`](DOTHelp/README.md) for the topic list and how to add
a page.

### DOTSSO

Google Workspace sign-in for staff, restricted to people who already have an
account on the desk. The second factor comes from Workspace, which already
enforces it, rather than being built and supported here.

- **Two gates, both required** — the account must be in the Workspace (checked
  against the signed `hd` claim, never the email domain, which a lookalike
  domain defeats) *and* already match an active user row
- **Never creates accounts** — an identity Google vouches for is not
  authorisation to use this help desk
- **Two switches** — showing the button and refusing passwords are separate, so
  SSO can be proven to work before passwords are turned off
- **Break-glass from a shell** — `artisan dotsso:disable` restores password
  login without a deploy, because under enforcement a misconfiguration locks
  out every administrator

See [`DOTSSO/README.md`](DOTSSO/README.md) for setup and the security
properties.

## Installation

Modules are installed into a FreeScout instance's `Modules/` directory.
FreeScout excludes `/Modules` from its own `.gitignore`, so modules are
intended to live in separate repositories.

```bash
git clone https://github.com/DigitalOppTrust/freescout-modules.git /opt/freescout-modules
ln -s /opt/freescout-modules/DOTTriage  /path/to/freescout/Modules/DOTTriage
ln -s /opt/freescout-modules/DOTReports /path/to/freescout/Modules/DOTReports
ln -s /opt/freescout-modules/DOTLog     /path/to/freescout/Modules/DOTLog
ln -s /opt/freescout-modules/DOTHelp    /path/to/freescout/Modules/DOTHelp
ln -s /opt/freescout-modules/DOTSSO    /path/to/freescout/Modules/DOTSSO
cd /path/to/freescout
php artisan freescout:module-install
php artisan freescout:clear-cache
```

Enable the modules in **Manage → Modules**.

`deploy.sh` does all of the above, including the public asset symlinks that
`freescout:module-install` creates.

### Upgrades

Modules attach to FreeScout through its hooks and are symlinked in from a
separate git tree, so a FreeScout upgrade cannot overwrite them. The rule that
keeps that true — **never patch core** — along with the hooks currently relied
on and a post-upgrade checklist, is in
[`ARCHITECTURE.md`](ARCHITECTURE.md).

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
| `DOTHELP_ENABLED` | `true` | DOTHelp master switch |
| `DOTHELP_AUDIENCE` | `all` | Who may read the handbook: `all` or `admin` |

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
