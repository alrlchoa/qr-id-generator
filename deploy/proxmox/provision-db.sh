#!/usr/bin/env bash
#
# Runs INSIDE the DB LXC. Pushed and executed by create-qrid-stack.sh — the
# ${...} placeholders below are substituted by that script's envsubst call
# before this file ever reaches the container, so this is a plain (already
# resolved) shell script by the time it runs.
# shellcheck disable=SC2016  # single-quoted ${...} below are envsubst placeholders, not bash
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive

apt-get update -y
apt-get install -y postgresql postgresql-contrib

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

echo "PostgreSQL provisioned: database=${DB_NAME} user=${DB_USER}"
