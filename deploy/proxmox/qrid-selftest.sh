#!/usr/bin/env bash
# shellcheck source-path=SCRIPTDIR
#
# /usr/local/bin/qrid-selftest — installed in the app container by
# install-system-files.sh (Phase 18):
#
#   qrid-selftest ids.example.com
#
# Checks the app is ready to serve that hostname through a Cloudflare
# Tunnel, and still serves the LAN. It changes nothing. Prints PASS, FAIL or
# WARN per check; exits 1 if anything failed.
set -uo pipefail

APP_DIR=/opt/qrid/app

# shellcheck source=qrid-domain.func
source /usr/local/lib/qrid-domain.func

if [[ $# -ne 1 ]]; then
    echo "Usage: qrid-selftest <hostname>    e.g. qrid-selftest ids.example.com" >&2
    exit 2
fi
host="$(qrid_normalize_hostname "$1")"
if ! qrid_valid_hostname "$host"; then
    echo "'$1' isn't a public hostname. Give the name only — no https://, no path, no IP address — e.g. ids.example.com" >&2
    exit 2
fi

failed=0
result() {
    local status="$1" what="$2" detail="${3:-}"
    printf '%-4s  %s%s\n' "$status" "$what" "${detail:+ — $detail}"
    if [[ "$status" == FAIL ]]; then
        failed=$(( failed + 1 ))
    fi
}

# What the tunnel sends: the public hostname as Host, over plain HTTP to
# :80, with the headers Cloudflare adds. No Cf-Connecting-IP, so an
# unclaimed system answers with its setup redirect instead of refusing.
tunnel=(-H "Host: ${host}" -H "Cf-Ray: qrid-selftest" -H "X-Forwarded-Proto: https")

for service in php8.3-fpm caddy; do
    if systemctl is-active --quiet "$service"; then
        result PASS "${service} is running"
    else
        result FAIL "${service} is running" "systemctl status ${service}"
    fi
done

code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "${tunnel[@]}" http://127.0.0.1/)"
if [[ "$code" == 200 || "$code" == 302 ]]; then
    result PASS "The app answers for ${host} on :80" "HTTP ${code}"
else
    result FAIL "The app answers for ${host} on :80" "HTTP ${code}, expected 200 or 302"
fi

# /dashboard always redirects a visitor who isn't signed in.
location="$(curl -s -o /dev/null -w '%{redirect_url}' --max-time 10 "${tunnel[@]}" http://127.0.0.1/dashboard)"
location_host="$(qrid_url_host "$location")"
if [[ -z "$location" ]]; then
    result FAIL "Redirects stay on ${host}" "no redirect from /dashboard"
elif qrid_is_private_host "$location_host"; then
    result FAIL "Redirects stay on ${host}" "redirected to a private address: ${location}"
elif [[ "$location" != "https://${host}/"* ]]; then
    result FAIL "Redirects stay on ${host}" "redirected to ${location}"
else
    result PASS "Redirects stay on ${host}" "$location"
fi

if [[ "$location" == */setup ]]; then
    result FAIL "First-run setup is finished" "open https://<this container's IP>/setup on the LAN; until then the tunnel refuses every page"
else
    result PASS "First-run setup is finished"
fi

app_url="$(sed -n 's/^APP_URL=//p' "${APP_DIR}/.env" | tail -n 1)"
app_url_host="$(qrid_url_host "$app_url")"
if [[ "$app_url_host" == "$host" ]]; then
    result PASS "APP_URL is ${app_url}"
elif qrid_is_private_host "$app_url_host"; then
    result WARN "APP_URL is ${app_url}" "a private address; pages still use ${host}, only links built by artisan commands would not — qrid-set-domain ${host} to change it"
else
    result WARN "APP_URL is ${app_url}" "not ${host}; pages still use ${host} — qrid-set-domain ${host} to change it"
fi

code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 https://127.0.0.1/up)"
if [[ "$code" == 200 ]]; then
    result PASS "The LAN is served over HTTPS on :443"
else
    result FAIL "The LAN is served over HTTPS on :443" "HTTP ${code} from https://127.0.0.1/up"
fi

code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 http://127.0.0.1/up)"
if [[ "$code" == 308 ]]; then
    result PASS "Plain HTTP from the LAN redirects to HTTPS"
else
    result FAIL "Plain HTTP from the LAN redirects to HTTPS" "HTTP ${code}, expected 308"
fi

code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "https://${host}/up")"
case "$code" in
    200) result PASS "https://${host} answers through Cloudflare" ;;
    502) result FAIL "https://${host} answers through Cloudflare" "502 — the route's Service should be http://<this container's IP>:80" ;;
    000) result FAIL "https://${host} answers through Cloudflare" "no answer — add the Public Hostname route in Cloudflare Zero Trust, or wait for its DNS record" ;;
    *) result FAIL "https://${host} answers through Cloudflare" "HTTP ${code}" ;;
esac

echo
if (( failed > 0 )); then
    echo "${failed} check(s) failed."
    exit 1
fi
echo "All checks passed."
