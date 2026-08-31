---
name: keep-docs-current
description: Keep the FreeScout module documentation in step with the code. Invoke AFTER changing module behaviour and BEFORE committing — when a module is built, deployed, enabled or disabled, when a setting or artisan command is added or renamed, when a FreeScout hook starts or stops being used, or when a documented claim turns out to be wrong. Also invoke when asked to check or update the docs.
---

# Keep the documentation current

Documentation here goes stale silently. Every plan document in `docs/` once
said "Plan only. Nothing built" for modules that had been live for weeks, and
the system documentation described five of eight modules. Nobody noticed
because nothing breaks when a document is wrong — it just quietly misleads the
next person, who is usually the operator at 2am during an incident.

The fix is to update docs **in the same change as the code**, not later.

## The rule that decides where something goes

`docs/` is **git-ignored** — local to one machine, never committed, never on
the server, and not backed up. This repository is **public**, and those files
name the origin IP and the security posture, which is why they cannot be
committed.

So: **anything that must survive cannot live in `docs/`.**

| What you changed | Update this | Committed |
|---|---|---|
| How a module works, its settings, its failure modes | `<Module>/README.md` | Yes |
| How modules attach to FreeScout, hooks relied on, upgrade safety | `ARCHITECTURE.md` | Yes |
| Something staff do or see | DOTHelp topic (`DOTHelp/Resources/views/topics/`) | Yes |
| Module list, install steps | `README.md` | Yes |
| Infrastructure, runbooks, design records | `docs/` | **No — local only** |

When in doubt, prefer a committed location. A design record in `docs/` is fine;
the operational truth belongs in a README.

## What to check, by change type

**A module was built, deployed, enabled or disabled**
- `<Module>/README.md` — does it exist at all? Two modules shipped without one.
- `docs/FreeScout-System-Documentation.md` — the module table in section 1, and
  a section describing it
- `docs/<MODULE>-MODULE-PLAN.md` — the status header. If the module is live,
  the plan is a **design record**, not a statement of current behaviour; say so
  and point at the README
- `README.md` — the module table and the symlink list in Installation
- DOTHelp `modules` topic — the count in the intro sentence and the table

**A setting, env var or artisan command changed**
- The module README's settings table
- `docs/FreeScout-System-Documentation.md` appendix — "Common commands" and
  "Where things are configured"
- Any runbook step that names the old command

**A FreeScout hook started or stopped being used**
- `ARCHITECTURE.md` — the hooks table. Mark it if losing the hook is
  security-relevant (e.g. losing `login.custom_check` silently re-enables
  password sign-in)
- The module README's own hook list, if it has one

**Staff-visible behaviour changed**
- The relevant DOTHelp topic. This is the documentation staff actually read
- If it changes how someone signs in, works a queue, or reads a notification,
  it needs a handbook change, not just a README line

**A documented claim turned out to be wrong**
- Fix it where it is wrong, and check whether the same claim is repeated
  elsewhere. Stale claims travel: the same wrong sentence was in three files

## Verify before you write

Do not describe intended behaviour. Check the running system, then write what
is true. The `production-access` skill has the commands.

Cheap checks that have each caught a wrong claim:

```bash
# Is the module actually enabled? (DB Option overrides file config)
sudo -u www-data php artisan <module>:status      # where one exists
sudo mariadb freescout -e "SELECT alias, active FROM modules;"
sudo mariadb freescout -e "SELECT name, LEFT(value,30) FROM options WHERE name LIKE '<prefix>%';"

# Is that command really scheduled, or run by hand?
grep -A8 "addFilter('schedule'" <Module>/Providers/*.php

# Does that hook still exist in core? (views AND app - login.custom_check
# lives in app/Http/Controllers/Auth/LoginController.php, not in a view)
cd /var/www/freescout/support.dotrust.org
grep -rn "<hook.name>" resources/views/ app/
```

A claim about scheduling, a default value, or a file path is worth ten seconds
of checking. "Auto-close is run manually" survived in the docs while it had
been running hourly for weeks.

## Writing style

Match the surrounding prose — these documents are written to be read, not
skimmed as reference cards.

- Say what is true now, not what was planned. If a document is a historical
  record, label it one at the top
- Give the reason when it is not obvious. "Checked against the `hd` claim, not
  the email domain, because an attacker can register `dotrust.org.evil.com`" is
  worth three times its length in prose that just states the rule
- Prefer a table when there are more than three parallel facts
- Record traps as traps. The ones that cost real debugging time belong in the
  README where the next person will hit them
- Do not add a changelog section or date-stamp every edit. Git already knows

## Before committing

Run the `public-repo-safety` skill. Documentation is the easiest way to leak an
IP address or a runbook step into a public repository — prose is not scanned as
carefully as code, and an example command with a real hostname in it is exactly
the kind of thing that slips through.

Confirm `git status` does not show anything under `docs/`. If it does, either
`.gitignore` has changed or the file was force-added; stop and reassess.
