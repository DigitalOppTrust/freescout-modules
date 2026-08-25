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

The index asks **one question — how much time do you have** — and offers two
big blocks to choose from. Someone about to answer their first ticket does not
have an hour, and pointing them at sixteen pages means they read none.

| Choice | Goes to | Contains |
|---|---|---|
| **5 minutes** | `/dothelp/course/five-minutes` | The reply rule, where the queue is, what the triage notes mean, and that nothing is irreversible |
| **35 minutes** | `/dothelp/course/one-hour` | Eight topics as one scrollable page with a contents list and per-part progress |

The durations are measured from word count at an unhurried pace, rounded up
with slack for tables and the diagram — not aspirational. Overstating them is
the easy mistake: a reader told "an hour" who finishes in twenty minutes stops
trusting the other numbers on the page, and one who only has twenty minutes
never starts. If you add or expand a topic, re-check `Handbook::partMinutes()`
against it.

Both land on a **single continuous page**, not a list of links. The hour is
deliberately one page rather than eight: a reader who has set an hour aside
should scroll rather than navigate, and should be able to see how far through
they are. The full topic grid is still there, behind a *Browse all topics*
disclosure.

Courses are defined in `Handbook::courses()` — a key, a duration, the blurb
and bullet list shown on the block, and the ordered `parts`. Adding one means
adding an entry there; the controller and view need no changes. Partials
receive `$inCourse` so they can hide standalone-only navigation when composed
into a course.

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
| Adding or reordering topics | `Handbook::courses()` and `Handbook::partMinutes()` |
