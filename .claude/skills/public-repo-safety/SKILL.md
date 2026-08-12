---
name: public-repo-safety
description: Pre-commit safety checks for the PUBLIC DigitalOppTrust/freescout-modules repository. Invoke BEFORE any git add, commit, or push in this repo, and before creating or moving any file into it. Catches secrets, credentials, infrastructure identifiers, and internal runbooks that must never be published.
---

# Public repo safety

`DigitalOppTrust/freescout-modules` is **public**. Everything committed is
world-readable and stays in git history after deletion. Rotating a leaked
secret is the only real remedy — removal is not.

Run these checks **before** staging, committing, or pushing.

## 1. Scan for secrets and infrastructure identifiers

```bash
# Generic secret patterns
git diff --cached -U0 | grep -inE \
  'sk-ant-|BEGIN [A-Z ]*PRIVATE KEY|AKIA[0-9A-Z]{16}|ghp_[A-Za-z0-9]{20,}|github_pat_|xox[baprs]-|password\s*[:=]|passwd\s*[:=]|secret\s*[:=]|api[_-]?key\s*[:=]|i-0[0-9a-f]{16}|[0-9]{12}\.dkr\.ecr'

# Environment-specific identifiers (IP addresses, instance IDs)
git diff --cached -U0 | grep -inEf .githooks/infra-patterns.local
```

Any hit is a stop. Unstage the file and reassess.

Also check untracked files about to be added:

```bash
git status --porcelain | grep '^??'
```

## 2. What must never be committed

**Secrets** — API keys (`CLAUDE_API_KEY`, `ANTHROPIC_API_KEY`), `.env` files of
any kind, SSH or TLS private keys, database passwords, OAuth client secrets or
tokens, Cloudflare Origin certificate keys.

**Infrastructure identifiers** — the origin IP address, the EC2 instance ID,
AWS account numbers, internal hostnames, VPN egress IPs. (The actual values are
in `docs/`, which is git-ignored, and in the pre-commit hook's local pattern
file.) Individually these are not secrets; together they are a reconnaissance
map naming exactly what to attack and where.

**Internal runbooks** — anything describing live security posture: firewall
rules, which ports are open and to whom, backup locations, recovery procedures,
`UPGRADE-NOTES.md`, files under `docs/`.

**Customer data** — ticket content, email addresses, names, attachments,
database dumps.

## 3. What is fine to publish

Module PHP source, migrations, Blade views, `module.json`, `composer.json`,
`.gitignore`, generic README content, config files that read values via
`env()` **without** defaults containing real values.

Config should look like this:

```php
'api_key' => env('CLAUDE_API_KEY', ''),        // correct — empty default
'api_key' => env('CLAUDE_API_KEY', 'sk-ant-'), // WRONG — real value in repo
```

## 4. Documentation written for this repo

Keep public docs generic. Refer to "the server", "the origin IP", "the
instance" rather than actual values. When a real value is genuinely needed to
explain something, put that document in `docs/` (git-ignored) and keep the
public version abstract.

## 5. If a secret is committed

1. **Rotate it immediately** — assume it is compromised the moment it is pushed.
   Removing the commit does not help; GitHub caches, forks, and clones persist.
2. Rotate at the source: Anthropic console for API keys, Elastic Email for SMTP,
   Google Cloud for OAuth secrets.
3. Only then clean history (`git filter-repo`), and force-push.
4. Update the live `.env` on the server with the new value and restart services.

## 6. Handling the server's `.env`

The **live** `/var/www/freescout/support.dotrust.org/.env` is the source of
truth. `/var/www/freescout/.env.backup` is a copy taken *from* it, never the
reverse — editing the backup and copying it forward silently reverts whatever
changed in the live file since (this has already caused one near-miss with
`MAIL_PASSWORD` and `APP_FETCH_SCHEDULE`).

Neither file belongs in git. To add a new setting, edit the live `.env`, then
refresh the backup from it.
