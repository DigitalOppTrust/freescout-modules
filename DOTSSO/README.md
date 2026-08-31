# DOTSSO

Google Workspace sign-in for FreeScout staff, restricted to users who already
have an account on the help desk.

## What it does

Adds a **Sign in with Google** button to the login page. A sign-in succeeds
only when both of these are true:

1. **The Google account is in our Workspace.** Verified against the signed
   `hd` claim on Google's ID token.
2. **The account already exists here, and may log in.** Matched by email to an
   active, non-robot user row.

Signing in with Google **never creates an account**. Accounts are still created
by an administrator; SSO only decides whether an existing one may proceed.

The second factor comes from Google Workspace, which already enforces it —
rather than being built and supported here.

## Why the `hd` claim and not the email domain

Checking that the email *ends with* `@dotrust.org` is not a domain check. An
attacker can register `dotrust.org.example.com` and create
`someone@dotrust.org.example.com`, which passes a naive suffix match. The `hd`
claim is asserted by Google, inside the signature, and cannot be set by the
account holder. That is the check this module makes.

## The two switches

The module has two independent switches, and the separation is the whole
safety story:

| `enabled` | `enforce` | Effect |
|---|---|---|
| off | — | Nothing changes. The module is inert. **Default.** |
| on | off | Google button appears. Passwords still work. |
| on | on | Password sign-in is refused; reset link hidden. |

Under enforcement the email and password fields are hidden from the login page
as well as refused. Core exposes no hook for that, so it is done with CSS
injected through `login_form.before` rather than by patching the core view,
which an upgrade would undo. **The refusal is server-side and does not depend
on the page** — see `login.custom_check` in the service provider; hiding the
form is honesty about what the page can do, not the control itself.

**Never turn on enforcement before observing a successful Google sign-in.**
There is no staging environment, and six of the eight users are administrators.

The settings page refuses to enable enforcement unless SSO is switched on,
fully configured, and has a Workspace domain set.

## Break-glass

If SSO breaks under enforcement, nobody can log in and the fix is not reachable
through the web UI. Recovery is from a shell:

```bash
sudo -u www-data php artisan dotsso:disable                 # SSO off, passwords back
sudo -u www-data php artisan dotsso:disable --enforce-only  # keep the button, lift enforcement
sudo -u www-data php artisan dotsso:status                  # what state is it actually in?
```

`dotsso:disable` clears the config and application caches itself.

Optionally set `DOTSSO_BREAKGLASS_EMAILS` in `.env` to a single administrator
who may always use a password. Every address listed is an account whose
security is back to being a password, so keep the list to one.

## Setup

1. In Google Cloud Console, create an **OAuth 2.0 Client ID** of type *Web
   application*.
2. Add the authorised redirect URI shown on the settings page — it must match
   exactly, including `https` and no trailing slash:
   `https://support.dotrust.org/sso/callback`
3. Scopes are `openid email profile` only. Nothing that touches customer data.
4. Paste the client ID and secret into **Manage → Single Sign-On**. The secret
   is encrypted with the app key before it is stored.
5. Set the Workspace domain (`dotrust.org`).
6. Tick **Show the Google button**. Save.
7. Check the *Who can sign in* table — every user should read **yes**.
8. Sign out. Sign in with Google. Only once that works, tick **Refuse password
   sign-in**.

## Security properties

- **PKCE** (S256) on the authorization code flow.
- **`state`** ties the callback to a login this browser started (CSRF).
- **`nonce`** ties the ID token to that attempt (replay).
- Attempt state is **consumed on first use** — a callback URL cannot be replayed.
- **Algorithm is pinned to RS256** from our side, so `alg: none` and
  RS256→HS256 confusion are rejected before any signature check.
- **Session is regenerated** on login (session fixation).
- **Remember-me is never set** for an SSO session: a remembered cookie is a
  login that never revisits Google, so it would outlive a Workspace suspension.
- **Robot accounts** (`type = TYPE_ROBOT`) can never hold an interactive session.
- Google's signing keys are cached for 6 hours; an unknown key id triggers one
  refresh, which is the key-rotation case.

## What it deliberately does not do

- **No auto-provisioning.** An identity Google vouches for is not authorisation
  to use this help desk.
- **No user table changes.** Match is on the existing `email` column, so there
  is no migration and nothing to roll back.
- **Customers are unaffected.** Rating links, the reopen form and the mail
  pipeline all sit outside `auth`. This module covers staff login only.

## Known gaps

- **`TokenAuth` middleware** (core, `app/Http/Middleware/TokenAuth.php`)
  restores a session from an `auth_token` URL parameter, independently of the
  login form. It is hash-validated and time-limited, but it is a login path
  that does not pass through SSO. Left as-is, documented rather than silently
  inherited.
- **Core's `remember` checkbox** is hidden along with the rest of the password
  form under enforcement, and the POST is refused regardless, so it has no
  effect.

## Audit

Every decision is logged — successes at info, refusals at warning — through
DOTLog when installed, and always to `laravel.log`. Repeated `sso.refused`
entries are what an attack looks like from this side. Tokens, codes and the
client secret are never logged.

Events: `sso.login`, `sso.refused`, `sso.password_blocked`.
