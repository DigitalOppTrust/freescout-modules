# Reports

Help desk reporting for FreeScout: inbound volume, triage effectiveness,
response and resolution times, and per-agent workload.

Read-only. It creates no tables, writes nothing to conversations, and registers
no hooks that act on tickets. Its worst failure mode is a broken page, never a
mishandled ticket.

## Tabs

| Tab | Answers |
|---|---|
| **Overview** | How much mail arrived, how much was triaged automatically, how long resolution takes |
| **Triage** | Coverage funnel, routing accuracy, confidence calibration, override matrix, cost, failures |
| **Resolution** | First response and resolution times (elapsed and working hours), backlog age, reopens, first-contact resolution |
| **Team** | Per-agent workload, time spent unassigned, escalations |

Every tab exports to CSV using the same queries that render the screen, so an
export always matches what was on it.

## Two things worth knowing about the numbers

**Resolution times report their own coverage.** FreeScout only stamps
`closed_at` when a user object reaches `Conversation::setStatus()`. The ordinary
UI paths do; imports, API creation, and modules calling `setStatus()` without a
user do not. Where `closed_at` is missing, this module falls back to the
conversation's status-change line item, and **states on the page** how many
conversations it could actually time. The rows that go missing are not a random
sample — automated closes are the fast ones — so a median that hid its own gap
would skew slow and be trusted anyway.

**Automatic closes are kept out of the resolution median.** DOTTriage closes
conversations itself — at arrival when a message is not a support request
(auto-replies, bounces, newsletters), and later by sweep when the customer goes
quiet. Those closes stamp `closed_at` but not `closed_by_user_id`, and the
arrival ones take seconds, so a mailbox where most mail is noise would otherwise
report a "median resolution time" of under a minute — a figure describing the
filter, not the team. The headline covers conversations a person closed; the
automatic ones are counted and shown alongside it, broken down by reason.

**Timings never use `conversations.last_reply_at`.** Its meaning changes with
the `app.waiting_since_as_first_unanswered_customer_message` config flag, and
the newer `last_customer_reply_at` is explicitly documented as unindexed. Both
are FreeScout's folder-sorting machinery, not a reporting API. Everything here
is derived from the `threads` table: `type = 1` inbound customer, `type = 2`
outbound agent reply, notes and line items excluded from reply counts.

Spam, deleted and imported conversations are excluded throughout — including
from message counts, not just conversation counts.

## Working hours

Durations are shown two ways: **elapsed** (wall clock, what the customer
experienced) and **working** (business time, what the team could control).
Working time reuses `Triage\Services\BusinessTime`, the same calendar the
escalation clock uses, so the SLA report and the escalation engine cannot
disagree about whether a ticket breached.

Currently that means weekends only. Working hours and public holidays are an
open item — see the plan.

## Small samples

Below `REPORTS_MIN_SAMPLE` (default 20) data points, percentiles are flagged
rather than presented as fact. A "median resolution time" from three tickets is
noise wearing a suit.

## Configuration

All optional; the defaults are sensible for a small desk.

| Variable | Default | Description |
|---|---|---|
| `REPORTS_MIN_SAMPLE` | `20` | Below this, percentiles are flagged as unreliable |
| `REPORTS_DEFAULT_DAYS` | `30` | Default period |
| `REPORTS_TABLE_LIMIT` | `50` | Max rows in detail tables |
| `REPORTS_COST_IN` | `1.00` | USD per million input tokens, for the triage spend estimate |
| `REPORTS_COST_OUT` | `5.00` | USD per million output tokens |

Cost is an order-of-magnitude estimate from these rates, not a billing figure.

## Access

Admin only. Reports expose per-agent performance and customer content, which is
not general-staff data.

## Requirements

- FreeScout 1.8.0 or later
- The Triage module for the Triage tab; without it that tab explains its
  absence rather than erroring, and every other tab works normally

## Tests

Metric correctness is covered by a standalone harness that runs the real
services against seeded SQLite fixtures with known answers:

```bash
FREESCOUT_PATH=/var/www/freescout php Reports/Tests/metrics-test.php
```

It exits non-zero on failure, so it can gate a deploy. The assertions that
matter most are the ones proving a conversation closed without `closed_at` is
still timed, and that a note never counts as a first response.

## Why the `DOT` prefix

The module directory, name and alias are all `DOTReports` / `dotreports`,
deliberately.

FreeScout's Modules page matches installed modules against its own module
directory **by alias**, and offers the directory's version as an update to
anything sharing one. The official *paid* Reports module owns `reports`, so
using that alias made FreeScout report "updates available — Reports (1.0.59)"
and offer to install a completely different, paid module over this one.

Prefixing every module in this repository with `DOT` keeps the alias namespace
ours, so no future module can collide with the directory either.

Two consequences worth knowing:

- The alias sets the public asset path, so stylesheets are served from
  `public/modules/dotreports`. If the alias changes, `asset('modules/<alias>/…')`
  in the views must change with it, and `freescout:module-install` must re-run.
- The directory name sets the PHP namespace (`Modules\DOTReports\…`), and the
  provider path in `module.json` must match it exactly or the module will not
  boot.

The Blade namespace stays `reports::` — it is registered in the service provider
and is independent of both.

## Implementation notes

FreeScout runs **Laravel 5.5**, which has no `joinSub()` / `leftJoinSub()`.
Subquery joins here are written as raw derived tables. Calling the Eloquent
helpers would throw `BadMethodCallException` at runtime rather than failing at
deploy time.

There is no charting library in FreeScout core, so charts are server-rendered
SVG and HTML. No JS dependency, and they still render with scripts blocked.
