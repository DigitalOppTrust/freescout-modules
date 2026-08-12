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
