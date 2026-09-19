#!/usr/bin/env bash
#
# Installs the app container's system files from the repository checkout
# it lives in (Phase 18): the Caddyfile, and the qrid-set-domain and
# qrid-selftest commands. provision-app.sh runs it when a stack is built or
# re-run, and deploy.sh on every `update`, so a change to any of them
# reaches existing stacks too, not only new ones. Run as root.
set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

install -m 0644 "${SRC}/qrid-domain.func" /usr/local/lib/qrid-domain.func
install -m 0755 "${SRC}/qrid-set-domain.sh" /usr/local/bin/qrid-set-domain
install -m 0755 "${SRC}/qrid-selftest.sh" /usr/local/bin/qrid-selftest

# Validated before it replaces the running one, so a Caddyfile Caddy can't
# load never takes the site down: the old one keeps serving, and the
# deploy stops here with Caddy's own reason.
if ! cmp -s "${SRC}/Caddyfile" /etc/caddy/Caddyfile; then
    if ! caddy validate --adapter caddyfile --config "${SRC}/Caddyfile"; then
        echo "The repository's Caddyfile didn't validate; /etc/caddy/Caddyfile was left as it was." >&2
        exit 1
    fi
    install -m 0644 "${SRC}/Caddyfile" /etc/caddy/Caddyfile
    echo "Caddyfile updated"
fi

systemctl enable caddy
if systemctl is-active --quiet caddy; then
    systemctl reload caddy
else
    systemctl restart caddy
fi
