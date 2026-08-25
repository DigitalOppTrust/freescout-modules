# DOTHelp

An in-app handbook explaining how the DO Trust help desk works, written for a
support person on their first week — reachable from **Help** in the main
navigation, or **Manage → Help**.

## Why

Onboarding knowledge for this desk lived in three places: the repository README
(developer-facing), the server runbook (operations), and whatever the last
person happened to explain in person. None of them answered the questions a new
agent actually has — *why did this ticket arrive already assigned to me, and why
did that one close itself on Tuesday?*

This module puts those answers next to the queue they are about, so the person
who needs them can find them without asking.

## What it contains

Fourteen topics in reading order, from "what is this system" through the
lifecycle of a ticket to a glossary. Four are marked `admin` and describe
screens an agent cannot open; they are listed for administrators only, but
nothing in them is needed to work the queue.

The two pages worth reading first are **Start here** and **The life of a
ticket** — together they cover almost everything needed in week one.

## What it deliberately does

- **Documents what is true, not what was planned.** Escalation is configurable
  in the Triage UI but no code currently fires it, so the handbook says so
  plainly rather than describing an SLA feature that does not run. The same goes
  for the two counters that are never written to.
- **Leads with reversibility.** The single most useful thing a new agent can
  know is that no automatic action is irreversible and none of them ever emails
  a customer — so correcting the system is always safe.
- **Carries no customer data, credentials or server addresses**, which is what
  makes it safe to show every logged-in user.

## Design

No tables, no migrations, no hooks that touch tickets, no scheduled work, and
no writes of any kind. Two `GET` routes and a set of Blade partials. Its worst
failure mode is a broken page.

Topics are declared as data in `Services/Handbook.php`; adding one means adding
a partial under `Resources/views/topics/` and a line in that registry. The slug
is validated against the registry before it reaches the view namespace.

## Configuration

| Variable | Default | Description |
|---|---|---|
| `DOTHELP_ENABLED` | `true` | Master switch. When false, nothing is registered. |
| `DOTHELP_AUDIENCE` | `all` | `all` (every logged-in user) or `admin`. |

## Access

Readable by every logged-in user by default — an onboarding guide nobody can
open has failed at its one job. Individual admin-marked topics return 403 for
non-admins, and the whole handbook can be restricted with `DOTHELP_AUDIENCE`.

## Keeping it accurate

The handbook describes behaviour, so it goes stale when behaviour changes. The
pages most likely to need updating:

| If you change… | Update |
|---|---|
| Escalation (making it actually run) | `escalation`, `reports`, `glossary` |
| Closing rules or timers | `auto-close`, `ticket-lifecycle` |
| Suggest-only vs auto-assign | `triage`, `admin` |
| Adding or removing a module | `modules`, and the registry |
| Folder structure | `folders` |
