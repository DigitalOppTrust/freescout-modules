#!/bin/bash
# Apply module changes to the running FreeScout install.
# Run after editing module code or pulling from git.
#   ./deploy.sh          - apply changes
#   ./deploy.sh --pull   - git pull first
set -uo pipefail

# Configure for your installation, or set these in the environment.
APP="${FREESCOUT_PATH:-/var/www/freescout}"
REPO="${MODULES_PATH:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)}"
SITE="${FREESCOUT_SITE:-}"   # e.g. help.example.org - enables the health check

# The app root is often a subdirectory named after the site, e.g.
# /var/www/freescout/help.example.org. Pointing APP at the parent is silent
# and destructive-by-omission: every symlink check below simply finds nothing,
# so the script reports success while cleaning up and linking nothing at all.
#
# Rather than guess, fail loudly - and if there is exactly one obvious
# candidate underneath, name it.
if [ ! -f "$APP/artisan" ]; then
    echo "ERROR: no artisan found at $APP - that is not a FreeScout app root."

    for c in "$APP"/*/; do
        [ -f "$c/artisan" ] && echo "  did you mean:  FREESCOUT_PATH=${c%/} $0 $*"
    done

    echo "  set FREESCOUT_PATH to the directory containing 'artisan'."
    exit 1
fi

if [ "${1:-}" = "--pull" ]; then
    echo "== Pulling =="
    git -C "$REPO" pull --ff-only || { echo "pull failed"; exit 1; }
fi

echo "== Syntax check =="
FAIL=0
while IFS= read -r f; do
    php -l "$f" > /dev/null 2>&1 || { echo "  SYNTAX ERROR: $f"; php -l "$f" 2>&1 | head -2; FAIL=1; }
done < <(find "$REPO" -name '*.php' -not -path '*/vendor/*')
if [ "$FAIL" -ne 0 ]; then
    echo "ABORT: fix syntax errors before deploying (site not touched)"
    exit 1
fi
echo "  all files parse"

# Modules renamed to a DOT prefix in Aug 2026. Their old symlinks and public
# asset directories must go, or FreeScout registers both the old and new module
# and boots two copies of the same service provider - duplicate menu entries,
# and two sets of hooks acting on the same ticket.
#
# Only ever removes SYMLINKS, never real directories, so a genuine module that
# happens to share the name is left alone.
echo "== Removing superseded module links =="
for old in Triage Reports; do
    if [ -L "$APP/Modules/$old" ]; then
        sudo rm -f "$APP/Modules/$old"
        echo "  unlinked Modules/$old"
    fi
done
for old in triage reports; do
    if [ -L "$APP/public/modules/$old" ]; then
        sudo rm -f "$APP/public/modules/$old"
        echo "  unlinked public/modules/$old"
    fi
done

echo "== Symlinks =="
for d in "$REPO"/*/; do
    name=$(basename "$d")
    [ -f "$d/module.json" ] || continue
    if [ ! -L "$APP/Modules/$name" ]; then
        sudo ln -sfn "$d" "$APP/Modules/$name"
        echo "  linked $name"
    else
        echo "  $name already linked"
    fi
done

# FreeScout serves module assets from public/modules/<alias>, via a symlink
# that only freescout:module-install creates. Without it the module's CSS 404s
# and /modules/list reports "Invalid or missing modules symlinks".
#
# The Public directory must exist at the target first: module-install resolves
# Modules/<Name> through to its real path, and will not link to something that
# is not there.
echo "== Public asset symlinks =="
for d in "$REPO"/*/; do
    name=$(basename "$d")
    [ -f "$d/module.json" ] || continue
    if [ ! -d "$d/Public" ]; then
        mkdir -p "$d/Public"
        echo "  created $name/Public"
    fi
done
sudo -u www-data php "$APP/artisan" freescout:module-install 2>&1 | tail -5

# The modules table keys on alias and holds the enabled flag, so the renamed
# modules arrive as new rows and start DISABLED. The old rows are orphans -
# their code is gone, but they still show on the Modules page.
#
# Dropping them here rather than by hand keeps the deploy repeatable. Enabling
# the new ones is left to a human: switching on a module that acts on tickets
# should be a deliberate decision, not a side effect of a deploy.
# Uses mariadb directly rather than artisan tinker: FreeScout ships
# laravel/tinker 1.0.7, which has no --execute flag (that arrived in 2.x), so
# the artisan route would just open an interactive shell and hang a deploy.
echo "== Superseded module rows =="
if command -v mariadb >/dev/null 2>&1; then
    sudo mariadb -N -B -e \
        "DELETE FROM freescout.modules WHERE alias IN ('triage','reports');
         SELECT CONCAT('  removed ', ROW_COUNT(), ' stale module row(s)');"
else
    echo "  SKIPPED - mariadb client not found; remove rows manually:"
    echo "    DELETE FROM freescout.modules WHERE alias IN ('triage','reports');"
fi

echo "== Migrations =="
sudo -u www-data php "$APP/artisan" module:migrate --force 2>&1 | tail -3

echo "== Clear cache =="
sudo -u www-data php "$APP/artisan" freescout:clear-cache 2>&1 | tail -1

echo "== Health check =="
if [ -n "$SITE" ]; then
    CODE=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 \
        --resolve "$SITE:443:127.0.0.1" "https://$SITE/login")
    if [ "$CODE" = "200" ]; then
        echo "  site OK (HTTP 200)"
    else
        echo "  ⚠️  SITE RETURNED HTTP $CODE - check immediately"
        echo "     disable module:  mariadb -e \"UPDATE freescout.modules SET active=0 WHERE alias='triage';\""
        echo "     then:            sudo -u www-data php $APP/artisan freescout:clear-cache"
        exit 1
    fi
else
    echo "  skipped (set FREESCOUT_SITE to enable)"
fi
