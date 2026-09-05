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

APP_DIR=/opt/qrid/app
cd "$APP_DIR"

BRANCH="$(git rev-parse --abbrev-ref HEAD)"

echo "==> Fetching origin/${BRANCH}"
git fetch origin
git reset --hard "origin/${BRANCH}"

echo "==> composer install"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> npm build"
npm install --ignore-scripts
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
