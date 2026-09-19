#!/usr/bin/env bash
# shellcheck source-path=SCRIPTDIR
#
# /usr/local/bin/qrid-set-domain — installed in the app container by
# install-system-files.sh (Phase 18). Run as root:
#
#   qrid-set-domain ids.example.com
#
# Not needed for the Cloudflare Tunnel to work: the app builds every link
# and redirect from the address it was reached at. APP_URL is what it uses
# when there is no request to read an address from (an artisan command), so
# this is the fallback for pointing that at the public hostname. It sets
# APP_URL, rebuilds the config cache, reloads Caddy and PHP-FPM, then shows
# what the hostname answers. Safe to run again: an unchanged APP_URL is
# left as it is and the rest just repeats.
set -euo pipefail

APP_DIR=/opt/qrid/app

# shellcheck source=qrid-domain.func
source /usr/local/lib/qrid-domain.func

if [[ $# -ne 1 ]]; then
    echo "Usage: qrid-set-domain <hostname>    e.g. qrid-set-domain ids.example.com" >&2
    exit 2
fi
if [[ $EUID -ne 0 ]]; then
    echo "Run this as root inside the app container." >&2
    exit 1
fi

host="$(qrid_normalize_hostname "$1")"
if ! qrid_valid_hostname "$host"; then
    echo "'$1' isn't a public hostname. Give the name only — no https://, no path, no IP address — e.g. ids.example.com" >&2
    exit 2
fi

cd "$APP_DIR"
url="https://${host}"

if grep -qx "APP_URL=${url}" .env; then
    echo "APP_URL is already ${url}"
elif grep -q '^APP_URL=' .env; then
    sed -i "s#^APP_URL=.*#APP_URL=${url}#" .env
    echo "APP_URL set to ${url}"
else
    echo "APP_URL=${url}" >> .env
    echo "APP_URL added: ${url}"
fi

php artisan config:clear
php artisan config:cache
chown qrid:qrid .env
chown -R qrid:qrid bootstrap/cache

systemctl reload caddy
systemctl reload php8.3-fpm
echo "Caddy and PHP-FPM reloaded"

echo
echo "curl -sI ${url}"
if ! headers="$(curl -sI --max-time 20 "$url")"; then
    echo "  No answer from ${url}. Has the Public Hostname route been added in Cloudflare Zero Trust, and has its DNS record appeared?"
    exit 0
fi
echo "$headers" | grep -iE '^(HTTP/|location:)' | sed 's/^/  /'
if echo "$headers" | grep -q '^HTTP/[0-9.]* 502'; then
    echo "  502: Cloudflare couldn't reach the app. The route's Service should be http://<this container's IP>:80 — or, for an https:// service, enable No TLS Verify."
fi
