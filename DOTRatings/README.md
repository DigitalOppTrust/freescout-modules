# DOTRatings

Emails the customer when their ticket is closed, asks them to rate the support
one to five stars, and lets them reopen the ticket if it was closed too soon.

## Why it is a separate module

It triggers on *every* close, not only triage's — most closure emails come from
an agent pressing Close, a path DOTTriage has nothing to do with. It is also the
only part of the help desk a stranger can reach without logging in, so keeping
it separate keeps that surface reviewable on its own and independently
switch-off-able.

The one link to DOTTriage is an Eventy action: `AutoCloser` fires
`dottriage.auto_closed`, this module listens. Triage does not depend on this
module; this module works without triage installed.

## How the reopen works

The closure email is given a Message-ID of the form
`FS_autoreply-{thread_id}-{hash}@{domain}`, matching what core's own auto-replies
use. FreeScout's `FetchEmails` parses `In-Reply-To` for exactly that pattern,
verifies the hash with `MailHelper::getMessageIdHash()`, and reopens the matched
conversation. So replying to the closure email reopens the ticket with no code
of ours involved.

The rating page also offers a reopen form, which goes through
`Thread::createExtended()` — core's own inbound-message path, so the
conversation is reopened, refiled and the agent notified exactly as a real
email would.

## Settings

Manage → Ratings. Everything that emails a customer is **off by default**:
installing this module sends nobody anything until an administrator turns
`send_enabled` on.

Mail closed as *not a support request* (newsletters, auto-replies, bounces) is
never emailed regardless of settings — replying confirms the address is real.

## Safety notes for future work

- **The GET must never record a rating.** Mail scanners fetch every link in an
  email before the recipient sees it. `?stars=N` only preselects; the POST
  records.
- **The public page shows a ticket number and a mailbox name, nothing else.**
  No subject, no message content, no customer name — so a leaked link discloses
  nothing.
- **Unknown and expired tokens render the same page**, so probing cannot learn
  whether a token ever existed.
- The resend guard exists because a closure email can trigger an out-of-office,
  which reopens the ticket, which gets closed again. Without it that loop emails
  the customer every time round.

## Files

| Path | What |
|---|---|
| `Providers/RatingsServiceProvider.php` | Hooks: manual closes and `dottriage.auto_closed` |
| `Services/ClosureNotifier.php` | The guard chain and the send |
| `Services/Settings.php` | Settings, stored in the options table |
| `Mail/ClosureNotification.php` | The email, including the Message-ID scheme |
| `Jobs/SendClosureEmail.php` | Queued send |
| `Http/Controllers/PublicRatingsController.php` | The unauthenticated rating page |
| `Http/Controllers/RatingsController.php` | Admin settings and ratings list |
| `Entities/Rating.php` | `dot_ratings` — one row per closure email |
