# Triage

AI-assisted ticket routing and SLA escalation for FreeScout.

## Status

Live in production. `TRIAGE_ENABLED` must be `true` in the FreeScout `.env`.

## How it works

**Noise** — auto-replies, bounces, newsletters, system notifications and mail
the mailbox sends itself are recognised from headers and closed at arrival, at
no cost. Mail the headers cannot settle goes to the model, which can also say
"not a support request".

**Routing** — the model reads the message and a list of agents with what each
one handles (the profile's *Handles* description), and returns a person, a
confidence and one sentence of reasoning. At or above the confidence threshold
the ticket is assigned; below it, a note suggests. There is no keyword routing:
keyword hits were recorded as certainty and produced most of the human
overrides, so improving routing means improving the descriptions.

**Retry** — a transient API failure (network, rate limit, overload) is retried
quickly inside the call, then by releasing the queued job with a growing delay,
and finally by an hourly `triage:run --failed` sweep for anything still
unassigned. A ticket is never left orphaned by one bad API minute.

**Escalation** — a clock starts when a ticket is assigned and whenever the
customer writes back; it stops when the assignee replies. When the agent's
reply window runs out (working time, weekends excluded) the **assignee** is
reminded by email, with a note on the ticket, a set number of times an interval
apart. One interval after the last reminder the escalation target is emailed
and a note is left; after a further grace period the ticket transfers to them
and their own clock starts, one hop deeper, reminders and all. Depth and chain
bound the hops. `triage:escalate --apply` runs every 30 minutes; without
`--apply` it is a dry run listing what is due.

With the defaults (1 working day window, 3 reminders 3 working days apart) a
ticket escalates **10 working days** after the reply became owed: reminders at
days 1, 4 and 7, escalation at day 10.

An agent whose profile names no escalation target (or only someone already in
the chain) still gets a clock, so they are still reminded; the ticket simply
never escalates. Those clocks have `escalate_to_user_id` null and are left out
of the escalation SLA figures in Reports, because they can never breach.
Reminders are emailed regardless of *Email the escalation target*: that switch
is about the target, and a reminder is nothing but its email.

Configured at **Manage → Triage → Escalation**:

| Setting | Default | What it does |
|---|---|---|
| Reply window | 1 working day | Time without a reply before the first reminder (or escalation, with reminders off) |
| Reminders before escalating | 3 | Emails to the assignee before the target is involved; 0 escalates at the window |
| Time between reminders | 3 working days | Between reminders, and between the last reminder and escalation |
| Transfer ownership after | 2 hours | Grace period between notifying the target and the ticket becoming theirs |
| Email the escalation target | on | Off leaves only the note on the ticket, which is easy to miss |
| Maximum escalation hops | 3 | Bound on runaway escalation |

**A per-agent window overrides the global one.** Set it on the agent's own page
(Manage → Triage → the agent); left blank, they use the global default. Both
count working time only, so a ticket arriving on Friday afternoon does not
escalate over the weekend.

**Changing the window or the reminders does not retime clocks that are already
running.** Each escalation records its own window, reminder count and interval
when it starts, so a ticket mid-clock keeps
the rule it began under; new clocks pick up the new value. Moving a deadline a
ticket is already being measured against would make the audit trail dishonest.

**Reopening** — FreeScout reopens a closed ticket on any customer reply. With
the reopen judgement on (Manage → Triage), the ticket is reopened and the model
is asked whether the reply needs a person. If it clearly does not ("thanks",
out-of-office, a reply to the closure email that says nothing) the ticket goes
back to closed with a note; anything unclear stays open, and an unowned ticket
that stays open is routed afresh on the reply that reopened it.

**Closing** — hourly sweep: backlog noise, inactivity after an agent reply,
and (optionally) tickets the model judges resolved. See Manage → Triage.

The resolved pass is the only one that reads a conversation where the customer
had the last word. The inactivity pass skips those on turn order — the ball is
with us — but applying that rule to the resolved pass discarded its best
evidence, since an explicit "it is, thank you" is the clearest sign a ticket is
finished. Whether a final customer message is a sign-off or a fresh question is
a reading task, so the model decides it. Those get a much shorter quiet window
(`resolved_confirmed_quiet_minutes`, default 1 hour) than a thread that simply
went silent, because a conversation the customer has closed off has nothing
left to wait for.

Quiet is measured from the last message in **either** direction. Measuring from
the agent's older reply would let a brand-new customer question through as soon
as the original window elapsed.

**Review** — every decision is recorded with its reasoning, whether a human
later overrode it, and whether a human reopened something it closed, so
accuracy is measured rather than assumed.

## Hooks used

| Hook | Purpose |
|---|---|
| `thread.created` | Customer email arrives — queue triage; on an assigned ticket, (re)start the escalation clock; on a just-reopened ticket, queue the reopen judgement |
| `conversation.status_changing` | A customer reply is about to reopen a closed ticket — mark it for judgement |
| `conversation.user_changed` | Detect human corrections to routing; start the new assignee's clock |
| `conversation.user_replied` | Stop the escalation clock |
| `conversation.status_changed` | Stop the clock on close; record a human reopening something triage closed |
| `schedule` | `triage:sweep`, `triage:escalate`, `triage:run --failed` |

## Safety

- `TRIAGE_ENABLED=false` disables all behaviour without uninstalling
- Hook registration is wrapped in try/catch, so a fault cannot take the site down
- Triage runs as a queued job — an API outage never blocks email fetching
- `TRIAGE_DAILY_LIMIT` caps API calls per day
- Settings are validated against their own choice list, so a tampered form
  cannot set an arbitrary escalation window
