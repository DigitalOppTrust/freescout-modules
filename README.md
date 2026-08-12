# FreeScout Modules

Custom [FreeScout](https://freescout.net/) modules built and maintained by
**Digital Opportunity Trust**.

## Modules

| Module | Status | Description |
|---|---|---|
| `Triage` | In development | AI-assisted ticket routing and SLA escalation |

### Triage

Routes incoming support conversations to the most appropriate agent based on
per-agent expertise profiles, and escalates tickets where the assignee has not
responded within a configured window.

- **Routing** uses a Claude model to match ticket content against agent profiles
- **Escalation** is deterministic — plain SLA timers, no model involved
- Every decision is recorded with its reasoning, so accuracy can be reviewed
  and the module can be switched to suggest-only or disabled entirely

## Installation

Modules are installed into a FreeScout instance's `Modules/` directory.
FreeScout excludes `/Modules` from its own `.gitignore`, so modules are
intended to live in separate repositories.

```bash
git clone https://github.com/DigitalOppTrust/freescout-modules.git /opt/freescout-modules
ln -s /opt/freescout-modules/Triage /path/to/freescout/Modules/Triage
cd /path/to/freescout
php artisan freescout:module-install
php artisan freescout:clear-cache
```

Enable the module in **Manage → Modules**.

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
