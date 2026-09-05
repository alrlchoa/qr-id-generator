#!/usr/bin/env bash
#
# Condo ID System — Proxmox VE stack builder
#
# Run this ON THE PROXMOX HOST (as root), not inside any container:
#
#   bash create-qrid-stack.sh
#
# It creates the two sibling LXCs architecture.md §12 calls for (app +
# Postgres — no Docker), provisions Postgres in the DB LXC, deploys the
# app into the App LXC behind Caddy, and installs a nightly backup cron
# in each container. Nothing else runs unattended (architecture §7/§12) —
# the backup job is the one deliberate exception.
#
# This script is idempotent-ish for re-runs against the SAME container IDs
# (it won't recreate containers that already exist) but is meant to be run
# once per fresh stack. For redeploying app code after this has already
# run, use deploy.sh inside the App LXC instead of re-running this script.
set -euo pipefail

# ============================================================================
# Configuration — edit before running
# ============================================================================

# Container IDs. Leave empty to auto-pick the next free IDs.
CTID_DB="${CTID_DB:-}"
CTID_APP="${CTID_APP:-}"

HOSTNAME_DB="${HOSTNAME_DB:-qrid-db}"
HOSTNAME_APP="${HOSTNAME_APP:-qrid-app}"

# Proxmox storage pool for container root disks, and the network bridge.
STORAGE="${STORAGE:-local-lvm}"
BRIDGE="${BRIDGE:-vmbr0}"

# Resources. Both containers are light — this is a few-thousand-row LAN app.
CORES_DB="${CORES_DB:-2}"
MEM_DB_MB="${MEM_DB_MB:-1024}"
DISK_DB_GB="${DISK_DB_GB:-8}"

CORES_APP="${CORES_APP:-2}"
MEM_APP_MB="${MEM_APP_MB:-1024}"
DISK_APP_GB="${DISK_APP_GB:-8}"

# The repo to deploy and the branch to track.
REPO_URL="${REPO_URL:-https://github.com/alrlchoa/qr-id-generator.git}"
REPO_BRANCH="${REPO_BRANCH:-main}"

# Internal hostname the app answers on. Caddy issues itself a locally-
# trusted cert for this name (`tls internal`) — see README.md for what
# that means for client trust and the DNS/DHCP reservation you still need
# to set up by hand (architecture §12: DHCP reservation + internal DNS,
# neither of which this script can configure from inside a container).
APP_DOMAIN="${APP_DOMAIN:-qrid.internal}"

DB_NAME="${DB_NAME:-qr_id_generator}"
DB_USER="${DB_USER:-qrid}"

# Where backups land ON THE PROXMOX HOST (bind-mounted into both
# containers) — "stored off the LXC itself" per the Phase 2 requirement.
# Point this at a different physical disk/pool than $STORAGE if you can;
# a backup that lives on the same disk as the thing it backs up is not
# really a backup.
BACKUP_HOST_DIR="${BACKUP_HOST_DIR:-/var/lib/vz/qrid-backups}"

UBUNTU_TEMPLATE_PATTERN="ubuntu-24.04-standard"

# ============================================================================
# Sanity checks
# ============================================================================

if [[ $EUID -ne 0 ]]; then
    echo "Run this as root on the Proxmox VE host." >&2
    exit 1
fi

if ! command -v pveversion >/dev/null 2>&1; then
    echo "pveversion not found — this doesn't look like a Proxmox VE host." >&2
    exit 1
fi

if ! command -v envsubst >/dev/null 2>&1; then
    apt-get install -y gettext-base
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ============================================================================
# Helpers
# ============================================================================

log() { echo -e "\n\033[1;32m==>\033[0m $*"; }

next_free_ctid() {
    pvesh get /cluster/nextid
}

wait_for_ip() {
    local ctid="$1" ip=""
    for _ in $(seq 1 30); do
        ip="$(pct exec "$ctid" -- hostname -I 2>/dev/null | awk '{print $1}')"
        if [[ -n "$ip" ]]; then
            echo "$ip"
            return 0
        fi
        sleep 2
    done
    echo "Timed out waiting for $ctid to get an IP address." >&2
    return 1
}

ensure_template_downloaded() {
    local storage="$1"
    local template
    template="$(pveam available 2>/dev/null | awk '{print $2}' | grep "^${UBUNTU_TEMPLATE_PATTERN}" | sort -V | tail -1)"

    if [[ -z "$template" ]]; then
        pveam update >/dev/null
        template="$(pveam available 2>/dev/null | awk '{print $2}' | grep "^${UBUNTU_TEMPLATE_PATTERN}" | sort -V | tail -1)"
    fi

    if [[ -z "$template" ]]; then
        echo "Could not find an ${UBUNTU_TEMPLATE_PATTERN} template in 'pveam available'." >&2
        exit 1
    fi

    if ! pveam list "$storage" 2>/dev/null | grep -q "$template"; then
        log "Downloading LXC template $template"
        pveam download "$storage" "$template" >&2
    fi

    echo "${storage}:vztmpl/${template}"
}

create_container() {
    local ctid="$1" hostname="$2" cores="$3" mem="$4" disk_gb="$5" root_password="$6" template="$7"

    if pct status "$ctid" >/dev/null 2>&1; then
        log "Container $ctid already exists — skipping creation"
        return 0
    fi

    log "Creating container $ctid ($hostname)"
    pct create "$ctid" "$template" \
        --hostname "$hostname" \
        --cores "$cores" \
        --memory "$mem" \
        --swap 512 \
        --rootfs "${STORAGE}:${disk_gb}" \
        --net0 "name=eth0,bridge=${BRIDGE},ip=dhcp" \
        --password "$root_password" \
        --unprivileged 1 \
        --features nesting=0 \
        --onboot 1 >&2
    # Deliberately not started here — bind mount points (bind_backup_dir)
    # need to be set on a stopped container to be mounted at boot, not
    # hotplugged into an already-running one.
}

bind_backup_dir() {
    local ctid="$1" subdir="$2"
    local host_dir="${BACKUP_HOST_DIR}/${subdir}"
    mkdir -p "$host_dir"
    pct set "$ctid" -mp0 "${host_dir},mp=/mnt/backup" >&2
}

push_and_run() {
    local ctid="$1" local_script="$2" remote_path="$3"
    pct push "$ctid" "$local_script" "$remote_path" --perms 0700 >&2
    pct exec "$ctid" -- bash "$remote_path" >&2
}

random_password() {
    openssl rand -base64 24 | tr -d '=+/' | cut -c1-24
}

# ============================================================================
# Provision
# ============================================================================

CTID_DB="${CTID_DB:-$(next_free_ctid)}"
# /cluster/nextid doesn't reserve anything — it just reports the next free
# ID — so asking again would return the same value until something is
# actually created. Bump past it explicitly instead.
CTID_APP="${CTID_APP:-$((CTID_DB + 1))}"

DB_ROOT_PASSWORD="$(random_password)"
APP_ROOT_PASSWORD="$(random_password)"
DB_PASSWORD="$(random_password)"

log "Ubuntu 24.04 template"
TEMPLATE="$(ensure_template_downloaded "$STORAGE")"

create_container "$CTID_DB" "$HOSTNAME_DB" "$CORES_DB" "$MEM_DB_MB" "$DISK_DB_GB" "$DB_ROOT_PASSWORD" "$TEMPLATE"
create_container "$CTID_APP" "$HOSTNAME_APP" "$CORES_APP" "$MEM_APP_MB" "$DISK_APP_GB" "$APP_ROOT_PASSWORD" "$TEMPLATE"

# Bind mounts before first start — LXC mount points need the container
# stopped to take effect at boot, not hotplugged into a running one.
bind_backup_dir "$CTID_DB" "db"
bind_backup_dir "$CTID_APP" "app"

log "Starting containers"
for ctid in "$CTID_DB" "$CTID_APP"; do
    if ! pct status "$ctid" | grep -q running; then
        pct start "$ctid"
    fi
done

log "Waiting for network"
DB_IP="$(wait_for_ip "$CTID_DB")"
APP_IP="$(wait_for_ip "$CTID_APP")"
echo "DB LXC ($CTID_DB): $DB_IP"
echo "App LXC ($CTID_APP): $APP_IP"

log "Provisioning PostgreSQL ($CTID_DB)"
# shellcheck disable=SC2016  # single-quoted on purpose: this is envsubst's variable allowlist, not a bash expansion
DB_MEM_MB="$MEM_DB_MB" DB_NAME="$DB_NAME" DB_USER="$DB_USER" DB_PASSWORD="$DB_PASSWORD" APP_IP="$APP_IP" \
    envsubst '${DB_MEM_MB} ${DB_NAME} ${DB_USER} ${DB_PASSWORD} ${APP_IP}' \
    < "${SCRIPT_DIR}/provision-db.sh" > /tmp/qrid-provision-db.sh
push_and_run "$CTID_DB" /tmp/qrid-provision-db.sh /root/provision-db.sh

log "Provisioning the app ($CTID_APP)"
# shellcheck disable=SC2016  # single-quoted on purpose: this is envsubst's variable allowlist, not a bash expansion
REPO_URL="$REPO_URL" REPO_BRANCH="$REPO_BRANCH" APP_DOMAIN="$APP_DOMAIN" \
    DB_HOST="$DB_IP" DB_NAME="$DB_NAME" DB_USER="$DB_USER" DB_PASSWORD="$DB_PASSWORD" \
    envsubst '${REPO_URL} ${REPO_BRANCH} ${APP_DOMAIN} ${DB_HOST} ${DB_NAME} ${DB_USER} ${DB_PASSWORD}' \
    < "${SCRIPT_DIR}/provision-app.sh" > /tmp/qrid-provision-app.sh
push_and_run "$CTID_APP" /tmp/qrid-provision-app.sh /root/provision-app.sh

pct push "$CTID_APP" "${SCRIPT_DIR}/deploy.sh" /opt/qrid/deploy.sh --perms 0700
pct push "$CTID_DB" "${SCRIPT_DIR}/backup-db.sh" /root/backup-db.sh --perms 0700
pct push "$CTID_APP" "${SCRIPT_DIR}/backup-app.sh" /root/backup-app.sh --perms 0700

log "Installing backup cron jobs (the one deliberate cron entry on each box — architecture §7/§12)"
pct exec "$CTID_DB" -- bash -c "(crontab -l 2>/dev/null; echo '0 2 * * * DB_NAME=${DB_NAME} DB_USER=${DB_USER} /root/backup-db.sh >> /var/log/qrid-backup.log 2>&1') | crontab -"
pct exec "$CTID_APP" -- bash -c "(crontab -l 2>/dev/null; echo '15 2 * * * /root/backup-app.sh >> /var/log/qrid-backup.log 2>&1') | crontab -"

rm -f /tmp/qrid-provision-db.sh /tmp/qrid-provision-app.sh

log "Done"
cat <<SUMMARY

  DB LXC:   $CTID_DB  ($HOSTNAME_DB)  $DB_IP
  App LXC:  $CTID_APP  ($HOSTNAME_APP)  $APP_IP

  DB root password (Proxmox container login): $DB_ROOT_PASSWORD
  App root password (Proxmox container login): $APP_ROOT_PASSWORD
  Postgres app-user password ($DB_USER):        $DB_PASSWORD

  Save these somewhere safe — they are not stored anywhere else.

  Still to do by hand (this script can't reach outside the containers):
    1. DHCP reservation for $APP_IP (and ideally $DB_IP too) on your router.
    2. Internal DNS: point ${APP_DOMAIN} at $APP_IP.
    3. Caddy is serving ${APP_DOMAIN} with its own internal CA cert (self-
       signed, not from a public CA — this is a LAN-only deployment, per
       architecture §1/§12). Your browser will warn on first visit until
       you trust that CA; see README.md for how to fetch and install it.
    4. Bootstrap the two Superadmin accounts (Phase 3) once auth exists —
       not part of this schema-only Phase 2 stack.
    5. Perform and verify one backup restore — Phase 2 isn't done until
       you've actually opened a restored backup, not just configured the
       job. See README.md.

  Sanity-check right now (bypasses TLS verification and DNS):
    curl -ko /dev/null -w '%{http_code}\n' https://${APP_IP}/up
    # -> 200 means Laravel booted and migrations ran

  The real check, once DNS resolves ${APP_DOMAIN} and its cert is trusted:
    curl https://${APP_DOMAIN}/up

SUMMARY
