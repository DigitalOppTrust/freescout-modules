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

Sixteen topics in reading order, from "what is this system" through the
lifecycle of a ticket to a glossary. Four are marked `admin` and describe
screens an agent cannot open; they are listed for administrators only, but
nothing in them is needed to work the queue.

The index offers **two time-boxed routes**, because someone about to answer
their first ticket does not have an hour, and pointing them at sixteen pages
means they read none:

- **5 minutes** — *The five-minute version*: the reply rule, where the queue
  is, what the automatic notes mean, and the fact that nothing is
  irreversible. Enough to start safely.
- **60 minutes** — eight topics in sequence with per-page timings, from
  *Start here* to *Escalation*. The remaining pages stay as reference.

## The reply rule

The handbook's most emphasised point, repeated across five pages rather than
stated once: **agents reply from inside the ticket, never from their own
mailbox and never by CCing the customer.**

It gets that weight because the failure is silent and expensive — an
off-system reply is invisible to the desk, so the ticket reads as unanswered,
it is excluded from the closing rules and sits open, the customer's response
lands in one person's private inbox, and the response-time figures understate
the whole team. It appears in `quick-start` (first item), `replying` (the full
page), `start`, `ticket-lifecycle`, `daily-work` and `troubleshooting`, plus
`Reply` and `Forward` entries in the glossary.

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
| Reply/forward mechanics | `replying`, `quick-start`, `glossary` |
| Adding or reordering topics | the 60-minute route list in `index.blade.php` |
