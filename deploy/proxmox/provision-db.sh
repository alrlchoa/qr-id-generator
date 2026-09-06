#!/usr/bin/env bash
#
# Runs INSIDE the DB LXC. Pushed and executed by create-qrid-stack.sh — the
# ${...} placeholders below are substituted by that script's envsubst call
# before this file ever reaches the container, so this is a plain (already
# resolved) shell script by the time it runs. SUDO_USERNAME/SUDO_PASSWORD
# are the one exception: they arrive as real environment variables (see
# push_and_run in create-qrid-stack.sh), never substituted into this text.
# shellcheck disable=SC2016  # single-quoted ${...} below are envsubst placeholders, not bash
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive

# Optional non-root sudo user, identical on both containers.
if [[ -n "${SUDO_USERNAME:-}" ]]; then
    # See provision-app.sh: 'root' would skip useradd and fall through to
    # chpasswd, replacing the generated root password the summary reports.
    if [[ "$SUDO_USERNAME" == "root" ]]; then
        echo "Refusing SUDO_USERNAME=root: it would silently replace the generated" >&2
        echo "root password rather than creating a separate sudo account." >&2
        exit 1
    fi
    if ! id "$SUDO_USERNAME" >/dev/null 2>&1; then
        useradd -m -s /bin/bash -G sudo "$SUDO_USERNAME"
    fi
    echo "${SUDO_USERNAME}:${SUDO_PASSWORD}" | chpasswd
fi

# See provision-app.sh for why: one transient apt mirror failure should not
# kill a multi-minute provision and leave a half-built container behind.
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

retry 3 5 apt-get update -y
retry 3 5 apt-get install -y postgresql postgresql-contrib ca-certificates curl gnupg debian-keyring debian-archive-keyring

systemctl enable --now postgresql

DB_NAME='${DB_NAME}'
DB_USER='${DB_USER}'
DB_PASSWORD='${DB_PASSWORD}'
APP_IP='${APP_IP}'
DB_MEM_MB='${DB_MEM_MB}'

PG_VERSION="$(psql -V | grep -oE '[0-9]+' | head -1)"
PG_CONF="/etc/postgresql/${PG_VERSION}/main/postgresql.conf"
PG_HBA="/etc/postgresql/${PG_VERSION}/main/pg_hba.conf"

# Least-privilege app role: LOGIN only, no CREATEDB/CREATEROLE/SUPERUSER.
# Owning its one database is exactly the access this system needs (§2/§12).
sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${DB_USER}') THEN
        CREATE ROLE ${DB_USER} WITH LOGIN PASSWORD '${DB_PASSWORD}';
    END IF;
END
\$\$;

SELECT 'CREATE DATABASE ${DB_NAME} OWNER ${DB_USER}'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${DB_NAME}')\gexec
SQL

# Minimal tuning, scaled off the container's own RAM — not a general-purpose
# tuning pass, just avoiding the stock defaults on a box with more than the
# ~128MB Postgres assumes out of the box.
SHARED_BUFFERS_MB=$(( DB_MEM_MB / 4 ))
EFFECTIVE_CACHE_MB=$(( DB_MEM_MB / 2 ))
sed -i "s/^#\?shared_buffers = .*/shared_buffers = ${SHARED_BUFFERS_MB}MB/" "$PG_CONF"
sed -i "s/^#\?effective_cache_size = .*/effective_cache_size = ${EFFECTIVE_CACHE_MB}MB/" "$PG_CONF"

# listen_addresses only controls which local interfaces Postgres binds
# to — it does not restrict which clients may connect. '*' is needed so
# Postgres accepts the connection arriving over the bridge interface at
# all; the actual access restriction is the pg_hba.conf rule below,
# scoped to exactly the app LXC's IP.
sed -i "s/^#\?listen_addresses = .*/listen_addresses = '*'/" "$PG_CONF"
if ! grep -q "^host.*${DB_NAME}.*${DB_USER}.*${APP_IP}" "$PG_HBA"; then
    echo "host    ${DB_NAME}    ${DB_USER}    ${APP_IP}/32    scram-sha-256" >> "$PG_HBA"
fi

systemctl restart postgresql

# --- Landing page, plain HTTP on :80 — a way to verify the container
# itself is up without a Postgres client. This is a status page only,
# nothing sensitive is exposed by it.
if ! command -v caddy >/dev/null 2>&1; then
    retry 3 5 bash -c "set -o pipefail; curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
        | gpg --dearmor --yes -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg"
    retry 3 5 bash -c "curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
        > /etc/apt/sources.list.d/caddy-stable.list"
    retry 3 5 apt-get update -y
    retry 3 5 apt-get install -y caddy
fi

mkdir -p /var/www/qrid-status
cat > /var/www/qrid-status/index.html <<'HTML'
<!doctype html>
<title>QRID Database Container</title>
<h1>QRID Database Container</h1>
<p>This container is running. PostgreSQL is listening on port 5432.</p>
HTML

cat > /etc/caddy/Caddyfile <<'CADDYFILE'
:80 {
    handle /health {
        respond "OK" 200
    }
    handle {
        root * /var/www/qrid-status
        file_server
    }
}
CADDYFILE

systemctl enable --now caddy
systemctl restart caddy

echo "PostgreSQL provisioned: database=${DB_NAME} user=${DB_USER}"
echo "Landing page: http://<db-ip>/  (health check: http://<db-ip>/health)"
