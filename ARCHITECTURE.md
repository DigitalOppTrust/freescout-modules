# Architecture and upgrade safety

How these modules attach to FreeScout, and why a FreeScout upgrade does not
break them. Read this before adding a module or changing how one hooks in.

## Two git trees, one application

| Path | Repo | Upgraded by |
|---|---|---|
| `/var/www/freescout/support.dotrust.org/` | FreeScout, `dist` branch | `upgrade.sh` |
| `/opt/freescout-modules/` | this repo | `pull-modules.sh` |

The application's `Modules/DOTx` entries are **symlinks** into
`/opt/freescout-modules/`. A `git pull` in the application tree therefore
cannot touch module code: the two trees never overlap.

This is the whole reason an upgrade is low-risk. Preserve it.

## The rule: never patch core

Every customisation goes through a FreeScout hook — `Eventy::addAction()` or
`Eventy::addFilter()`. Never edit a file under the application tree.

An edited core file is reverted by the next `git pull --ff-only origin dist`,
silently, and the behaviour it provided disappears with it. A hook survives.

If something appears to need a core edit, it almost always means the right hook
has not been found yet. Core exposes far more than is obvious — the login page
alone has four.

## Hooks currently relied on

If core removes or renames one of these, that feature silently stops working.
This table is the upgrade checklist.

| Hook | Module | Effect if it disappears |
|---|---|---|
| `login.banner` | DOTTheme | Sign-in page reverts to the FreeScout logo |
| `layout.header_logo` | DOTTheme | Header reverts to the FreeScout logo |
| `footer.text` | DOTTheme | Footer reverts to FreeScout's copyright line |
| `layout.head` | DOTTheme | Branding stylesheet stops loading |
| `login_form.before` | DOTSSO | **Password fields reappear under enforcement** |
| `login_form.after` | DOTSSO | **Google button disappears — nobody can sign in** |
| `login.custom_check` | DOTSSO | **Password sign-in silently starts working again** |
| `auth.password_reset_available` | DOTSSO | Reset link reappears |
| `schedule` | DOTTriage, DOTLog | Sweeps and pruning stop running |
| `conversation.status_changed` | DOTRatings | Closure emails stop sending |
| `thread.created`, `conversation.user_*` | DOTTriage | Routing and escalation stop |
| `menu.append`, `menu.manage.append` | Several | Navigation entries vanish |
| `settings.sections` | DOTTriage | Settings page unreachable |

The three marked in bold are security-relevant. **Check those first after any
upgrade.**

## Post-upgrade check

Behavioural, in order of severity — takes about a minute:

1. **Sign in.** The Google button is present and works. This is the one that
   locks everyone out if it breaks.
2. **Sign-in page** shows the DOT logo, no email/password fields, footer reads
   "DOT Support".
3. **Manage menu** lists Triage, Reports, MCP, DOTLog, Ratings, Single Sign-On.
4. **Help** appears in the main navigation.
5. `php artisan dotsso:status` reports enabled and enforcing.
6. Send a test email in and confirm it becomes a ticket and gets triaged.

If step 1 fails, recover with `php artisan dotsso:disable` and investigate with
password sign-in restored.

## Conventions every module follows

- **A kill switch.** `config('<module>.enabled')`, default false for anything
  that acts on tickets or on sign-in. Deploying must never change behaviour on
  its own.
- **Hook registration wrapped in try/catch.** Laravel boots every provider on
  every request, so an uncaught throw in one module 500s the *entire site*, not
  just that module. Degrade to a log line.
- **Settings links register outside the kill switch,** so a module can still be
  configured and diagnosed while switched off — which is exactly when someone
  needs to reach it.
- **`php -l` everything.** FreeScout escalates PHP deprecations to exceptions:
  implicit nullable parameters (`Foo $x = null` → `?Foo $x = null`),
  `curl_close()` and `trim(null)` all throw at runtime.
- **Never nest route groups.** `Route::middleware()->prefix()->group()` plus an
  inner `Route::group(['prefix' => ...])` passes null into Laravel's prefix
  merge → `trim(null)` → 500 on every page. Set the prefix once, in the route
  service provider.
- **Assets are cache-busted on `filemtime`,** not a hand-maintained version
  constant. Two modules have shipped invisible CSS fixes because a version key
  was never incremented — or, in DOTTheme's case, never defined at all.

## Where documentation lives

| What | Where | Committed |
|---|---|---|
| How a module works | `<Module>/README.md` | Yes — travels with the code |
| How it all fits together | this file | Yes |
| Staff-facing handbook | DOTHelp, in-app at *Help* | Yes |
| Infrastructure, runbooks, design records | `docs/` | **No — git-ignored** |

`docs/` is deliberately outside version control: this repository is **public**,
and those documents name the origin IP, the instance and the security posture.
Anything that must survive belongs in a README or in DOTHelp.
