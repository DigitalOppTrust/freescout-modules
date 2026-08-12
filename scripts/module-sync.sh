#!/bin/bash
# Shared module reconciliation, sourced by pull-modules.sh and upgrade.sh.
#
# Renaming a module leaves three kinds of stale state behind, any one of which
# takes the whole site down with "Class ... not found":
#   1. Modules/<OldName>          symlink to a directory that no longer exists
#   2. public/modules/<oldalias>  dangling asset symlink
#   3. the cached module list     bootstrap/cache/*_module.php AND the file
#                                 cache, because modules.cache.enabled = true
#
# The database is a fourth: FreeScout only boots modules whose alias is
# active in the `modules` table, so a renamed module stays invisible until
# it is registered under its new alias.

# Remove every cache that can hold a stale provider class name.
# Both locations matter - clearing bootstrap alone leaves the site at 500.
triage_clear_module_caches() {
    local app="$1"
    rm -f "$app"/bootstrap/cache/*_module.php 2>/dev/null
    rm -f "$app"/bootstrap/cache/config.php \
          "$app"/bootstrap/cache/services.php \
          "$app"/bootstrap/cache/packages.php 2>/dev/null
    rm -rf "$app"/storage/framework/cache/data/* 2>/dev/null
    install -d -o www-data -g www-data "$app/storage/framework/cache/data" 2>/dev/null
}

# Drop Modules/ symlinks whose target has gone, and public asset symlinks
# that no longer resolve.
triage_prune_stale_links() {
    local app="$1"
    local pruned=0

    for link in "$app"/Modules/*; do
        [ -L "$link" ] || continue
        if [ ! -d "$link" ]; then
            rm -f "$link"
            echo "    pruned stale module link: $(basename "$link")"
            pruned=$((pruned + 1))
        fi
    done

    for link in "$app"/public/modules/*; do
        [ -L "$link" ] || continue
        if [ ! -d "$link" ]; then
            rm -f "$link"
            echo "    pruned stale asset link: $(basename "$link")"
            pruned=$((pruned + 1))
        fi
    done

    [ "$pruned" -eq 0 ] && echo "    no stale links"
    return 0
}

# Remove Modules/ entries that are not backed by a directory in the repo.
# Catches a rename where the old symlink still resolves because the old
# directory lingers untracked.
triage_prune_unknown_links() {
    local app="$1" repo="$2"

    for link in "$app"/Modules/*; do
        [ -L "$link" ] || continue
        local name
        name=$(basename "$link")
        if [ ! -f "$repo/$name/module.json" ]; then
            rm -f "$link"
            echo "    pruned unknown module link: $name"
        fi
    done
    return 0
}

# Create Modules/ and public asset symlinks for everything in the repo, and
# register each module under the alias declared in its module.json.
triage_link_and_register() {
    local app="$1" repo="$2"

    for d in "$repo"/*/; do
        local name alias
        name=$(basename "$d")
        [ -f "$d/module.json" ] || continue

        ln -sfn "${d%/}" "$app/Modules/$name"
        echo "    linked $name"

        # Asset symlink, but only when the module actually ships assets -
        # a link to a missing Public/ is what triggers FreeScout's
        # "Invalid or missing modules symlinks" warning.
        alias=$(grep -oE '"alias"[[:space:]]*:[[:space:]]*"[^"]+"' "$d/module.json" \
                | head -1 | sed 's/.*"\([^"]*\)"$/\1/')
        if [ -n "$alias" ] && [ -d "${d%/}/Public" ]; then
            ln -sfn "${d%/}/Public" "$app/public/modules/$alias"
        fi

        # Register under the new alias. FreeScout filters module discovery on
        # this table, so a renamed module is invisible until it is added.
        if [ -n "$alias" ]; then
            mariadb -N -e "INSERT INTO freescout.modules (alias, active, activated)
                           VALUES ('$alias', 1, 0)
                           ON DUPLICATE KEY UPDATE active = 1;" 2>/dev/null \
                && echo "    registered $alias"
        fi
    done
    return 0
}

# Deactivate rows in the modules table with no module on disk, so FreeScout
# does not try to boot a provider that no longer exists.
triage_deactivate_missing() {
    local repo="$1"

    local aliases
    aliases=$(for d in "$repo"/*/; do
        [ -f "$d/module.json" ] || continue
        grep -oE '"alias"[[:space:]]*:[[:space:]]*"[^"]+"' "$d/module.json" \
            | head -1 | sed "s/.*\"\([^\"]*\)\"$/'\1'/"
    done | paste -sd, -)

    if [ -n "$aliases" ]; then
        local n
        n=$(mariadb -N -e "SELECT COUNT(*) FROM freescout.modules
                           WHERE active = 1 AND alias NOT IN ($aliases);" 2>/dev/null)
        if [ "${n:-0}" -gt 0 ]; then
            mariadb -e "UPDATE freescout.modules SET active = 0
                        WHERE alias NOT IN ($aliases);" 2>/dev/null
            echo "    deactivated $n module(s) no longer on disk"
        fi
    fi
    return 0
}

# Full reconcile: prune, link, register, deactivate, clear caches.
triage_sync_modules() {
    local app="$1" repo="$2"

    triage_prune_stale_links "$app"
    triage_prune_unknown_links "$app" "$repo"
    triage_link_and_register "$app" "$repo"
    triage_deactivate_missing "$repo"
    triage_clear_module_caches "$app"
}
