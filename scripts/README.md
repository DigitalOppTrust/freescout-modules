# Deployment scripts

Helpers for keeping a FreeScout installation in step with this repository.

## `module-sync.sh`

Reconciles the modules on disk with what FreeScout believes is installed.
Sourced by the server's `pull-modules.sh` and `upgrade.sh`.

### Why it exists

Renaming a module leaves stale state in **four** places, and any one of them
can take the entire site down with `Class ... not found` — not just the
module's own pages, because Laravel boots every registered provider on every
request:

| Stale state | Symptom |
|---|---|
| `Modules/<OldName>` symlink | provider for a missing class is booted |
| `public/modules/<oldalias>` symlink | "Invalid or missing modules symlinks" |
| `bootstrap/cache/*_module.php` | cached provider list names the old class |
| **module list in the file cache** | same, and survives clearing bootstrap |
| `modules` table row | module invisible until registered under the new alias |

The file cache is the one that catches people out: `config/modules.php` sets
`cache.enabled = true`, so clearing `bootstrap/cache` alone leaves the site
broken.

### What it does

```
triage_sync_modules <app-path> <repo-path>
```

1. **Prunes** `Modules/` and `public/modules/` symlinks that no longer resolve
2. **Prunes** `Modules/` entries with no matching directory in the repo
3. **Links** every module in the repo, plus its `Public/` assets if present
4. **Registers** each module under the alias from its `module.json`
5. **Deactivates** `modules` rows with no module on disk
6. **Clears** both module caches

Idempotent — safe to run when nothing has changed.

### Verified against

- A rename leaving dangling `Modules/` and `public/modules/` symlinks
- A stale `bootstrap/cache/*_module.php` naming a deleted class
- A `modules` row for a module no longer present
- A real upgrade pulling a commit with all of the above planted

In each case the reconcile cleaned up and the site stayed on HTTP 200.

## Server scripts

These live on the server, outside this repository, because they contain
paths and hostnames specific to one installation:

| Script | Purpose |
|---|---|
| `/var/www/freescout/pull-modules.sh` | Pull module code and apply it |
| `/var/www/freescout/upgrade.sh` | Upgrade FreeScout itself plus modules |
| `/var/www/freescout/module-sync.sh` | This file, deployed |

Both call `triage_sync_modules` — `pull-modules.sh` even when there is
nothing to pull, since stale state can outlive the commit that caused it.
