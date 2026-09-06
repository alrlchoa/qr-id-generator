#!/usr/bin/env bash
#
# Lives at /opt/qrid/deploy.sh inside the App LXC. Run it (as root, or via
# sudo) after create-qrid-stack.sh has provisioned the box once, to roll
# out a new commit on the tracked branch:
#
#   pct exec <app-ctid> -- /opt/qrid/deploy.sh
#
# This is a script an operator runs deliberately, not a scheduled job —
# consistent with architecture §7/§12: nothing in this system deploys
# itself unattended.
set -euo pipefail

# `pct exec` runs with a minimal PATH that doesn't include /usr/local/bin,
# where Composer's installer puts the composer binary.
export PATH="/usr/local/bin:${PATH}"

# Composer refuses to run plugins as root and warns about it; with --no-dev
# there are none to run, and the warning otherwise reads like a cause when
# something else fails.
export COMPOSER_ALLOW_SUPERUSER=1

# Same reasoning as provision-app.sh: a transient GitHub 504 or apt blip
# should not abort a deploy under `set -e` and leave the app half-updated
# with caches rebuilt against the old code.
retry() {
    local attempts="$1" delay="$2"
    shift 2
    local n=1
    until "$@"; do
        if (( n >= attempts )); then
            echo "FAILED after ${attempts} attempts: $*" >&2
            return 1
        fi
        echo "Attempt ${n}/${attempts} failed: $* — retrying in ${delay}s" >&2
        sleep "$delay"
        n=$(( n + 1 ))
        delay=$(( delay * 2 ))
    done
}

APP_DIR=/opt/qrid/app
cd "$APP_DIR"

BRANCH="$(git rev-parse --abbrev-ref HEAD)"

echo "==> Fetching origin/${BRANCH}"
retry 3 5 git fetch origin
git reset --hard "origin/${BRANCH}"

echo "==> composer install"
if ! retry 3 15 composer install --no-dev --optimize-autoloader --no-interaction; then
    echo "Dist downloads keep failing — falling back to --prefer-source (git protocol)." >&2
    retry 2 15 composer install --no-dev --optimize-autoloader --no-interaction --prefer-source
fi

echo "==> npm build"
retry 3 10 npm install --ignore-scripts
npm run build

echo "==> migrate --force"
php artisan migrate --force

echo "==> cache config/routes/views"
php artisan config:cache
php artisan route:cache
php artisan view:cache

chown -R qrid:qrid "$APP_DIR"

systemctl restart php8.3-fpm

echo "==> Deployed $(git rev-parse --short HEAD)"
