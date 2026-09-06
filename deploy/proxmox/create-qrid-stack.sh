#!/usr/bin/env bash
#
# Condo ID System — Proxmox VE stack builder
#
# Run this ON THE PROXMOX HOST (as root), not inside any container. Either
# as a one-liner (no local checkout needed — matches the community-scripts
# helper-script convention):
#
#   bash -c "$(curl -fsSL https://raw.githubusercontent.com/alrlchoa/qr-id-generator/main/deploy/proxmox/create-qrid-stack.sh)"
#
# ...or from a local clone:
#
#   bash create-qrid-stack.sh
#
# It then asks explicitly for container IDs, hostnames, resources, storage
# pools, and the app/DB settings — each prompt shows a default in
# [brackets]; press Enter to accept it. Exporting a variable first (every
# "Configuration" variable below reads from the environment) changes the
# default shown rather than skipping the prompt:
#
#   export CTID_DB=201 CTID_APP=202
#   export HOSTNAME_DB=condo-db HOSTNAME_APP=condo-app
#   export MEM_DB_MB=2048 MEM_APP_MB=2048
#   export DISK_DB_GB=16 DISK_APP_GB=16
#   bash -c "$(curl -fsSL https://raw.githubusercontent.com/alrlchoa/qr-id-generator/main/deploy/proxmox/create-qrid-stack.sh)"
#
# For unattended runs (no prompts at all — everything from env vars/
# defaults), set QRID_NONINTERACTIVE=1, or just don't run it from a
# terminal (piped input, cron, CI): prompts are skipped automatically
# whenever stdin isn't a TTY.
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
#
# The one-liner form runs with no sibling files on disk, so this script
# fetches provision-db.sh, provision-app.sh, deploy.sh, backup-db.sh, and
# backup-app.sh from the same repo/branch at runtime rather than assuming
# they live next to it — the one canonical copy of each stays in this
# directory in git; nothing is duplicated inline here.
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

# Storage pool for the LXC template file. Deliberately separate from
# $STORAGE: on a stock Proxmox install, local-lvm (LVM-thin) holds VM/CT
# disks but does NOT support the vztmpl content type — only a directory-
# backed storage like the default "local" does. Using $STORAGE here fails
# with "storage 'local-lvm' does not support templates".
TEMPLATE_STORAGE="${TEMPLATE_STORAGE:-local}"

# Resources. Both containers are light — this is a few-thousand-row LAN app.
CORES_DB="${CORES_DB:-2}"
MEM_DB_MB="${MEM_DB_MB:-2048}"
DISK_DB_GB="${DISK_DB_GB:-8}"

CORES_APP="${CORES_APP:-2}"
MEM_APP_MB="${MEM_APP_MB:-2048}"
DISK_APP_GB="${DISK_APP_GB:-8}"

# The repo to deploy and the branch to track.
REPO_URL="${REPO_URL:-https://github.com/alrlchoa/qr-id-generator.git}"
REPO_BRANCH="${REPO_BRANCH:-main}"

# Where this script's sibling files (provision-db.sh, provision-app.sh,
# deploy.sh, backup-db.sh, backup-app.sh) are fetched from when they aren't
# sitting next to it on disk — i.e. every time this runs as the one-liner
# curl invocation, which has no local checkout to read them from. Override
# this to test a branch before it's merged to $REPO_BRANCH.
REPO_RAW_BASE="${REPO_RAW_BASE:-https://raw.githubusercontent.com/alrlchoa/qr-id-generator/${REPO_BRANCH}/deploy/proxmox}"

DB_NAME="${DB_NAME:-qr_id_generator}"
DB_USER="${DB_USER:-qrid}"

# Where backups land ON THE PROXMOX HOST (bind-mounted into both
# containers) — "stored off the LXC itself" per the Phase 2 requirement.
# Point this at a different physical disk/pool than $STORAGE if you can;
# a backup that lives on the same disk as the thing it backs up is not
# really a backup.
BACKUP_HOST_DIR="${BACKUP_HOST_DIR:-/var/lib/vz/qrid-backups}"

# Optional non-root sudo user, created identically on both containers.
# Leave SUDO_USERNAME blank (the default) to skip this and only have root.
SUDO_USERNAME="${SUDO_USERNAME:-}"
SUDO_PASSWORD="${SUDO_PASSWORD:-}"

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

if ! command -v curl >/dev/null 2>&1; then
    apt-get install -y curl
fi

# Stage the 5 sibling scripts into a working directory: copied from a local
# checkout if one exists next to this file, otherwise fetched from
# $REPO_RAW_BASE (the one-liner invocation case — see the file header).
LOCAL_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]:-}")" 2>/dev/null && pwd || echo "")"
SCRIPT_DIR="$(mktemp -d)"
trap 'rm -rf "$SCRIPT_DIR"' EXIT

for f in provision-db.sh provision-app.sh deploy.sh backup-db.sh backup-app.sh; do
    if [[ -n "$LOCAL_SCRIPT_DIR" && -f "${LOCAL_SCRIPT_DIR}/${f}" ]]; then
        cp "${LOCAL_SCRIPT_DIR}/${f}" "${SCRIPT_DIR}/${f}"
    elif ! curl -fsSL --retry 3 --retry-delay 5 --retry-all-errors \
        "${REPO_RAW_BASE}/${f}" -o "${SCRIPT_DIR}/${f}"; then
        echo "Failed to fetch ${REPO_RAW_BASE}/${f}" >&2
        echo "REPO_BRANCH=${REPO_BRANCH} — if you're testing an unmerged branch, make sure" >&2
        echo "REPO_BRANCH (not just the URL you curled) is set to that branch too, e.g.:" >&2
        echo "  export REPO_BRANCH=your-branch-name" >&2
        exit 1
    fi
done

# ============================================================================
# Helpers
# ============================================================================

# Always to stderr — log() is called from functions whose stdout is
# captured (e.g. ensure_template_downloaded's returned path via $(...)),
# and a stdout leak there silently corrupts the captured value.
log() { echo -e "\n\033[1;32m==>\033[0m $*" >&2; }

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
    local ctid="$1" hostname="$2" cores="$3" mem="$4" disk_gb="$5" template="$6"

    if pct status "$ctid" >/dev/null 2>&1; then
        local existing_hostname
        existing_hostname="$(pct config "$ctid" | sed -n 's/^hostname: //p')"
        if [[ "$existing_hostname" != "$hostname" ]]; then
            echo "ERROR: Container $ctid already exists with hostname '${existing_hostname:-<none>}', expected '$hostname'." >&2
            echo "Refusing to reuse a container this script didn't create — pick a different CTID." >&2
            exit 1
        fi
        log "Container $ctid already exists as '$hostname' — resuming against it"
        return 0
    fi

    log "Creating container $ctid ($hostname)"
    # No --password here: pct create would put it in this process's argv,
    # readable via /proc/<pid>/cmdline for the duration of the call. Root's
    # password is set after boot instead, piped over stdin (see
    # set_root_password below).
    pct create "$ctid" "$template" \
        --hostname "$hostname" \
        --cores "$cores" \
        --memory "$mem" \
        --swap 512 \
        --rootfs "${STORAGE}:${disk_gb}" \
        --net0 "name=eth0,bridge=${BRIDGE},ip=dhcp" \
        --unprivileged 1 \
        --features nesting=0 \
        --onboot 1 >&2
    # Deliberately not started here — bind mount points (bind_backup_dir)
    # need to be set on a stopped container to be mounted at boot, not
    # hotplugged into an already-running one.
}

# Requires the container to be running. Piping over stdin keeps the
# password out of this (or pct exec's) argv entirely.
set_root_password() {
    local ctid="$1" root_password="$2"
    printf 'root:%s\n' "$root_password" | pct exec "$ctid" -- chpasswd
}

bind_backup_dir() {
    local ctid="$1" subdir="$2"
    local host_dir="${BACKUP_HOST_DIR}/${subdir}"
    mkdir -p "$host_dir"
    # Unprivileged LXCs remap UIDs: the Proxmox host's real root (uid 0)
    # falls outside the container's mapped range and shows up as
    # nobody:nogroup from inside, so a mkdir'd 0755 dir can't be written to
    # by postgres/qrid there. Rather than opening it to every user on the
    # host, chown it to container-root's host-side uid instead: both
    # containers here are `--unprivileged 1` with no custom idmap, so they
    # share Proxmox's default subuid/subgid pool, which maps container uid 0
    # to host uid/gid 100000.
    chown 100000:100000 "$host_dir"
    chmod 0770 "$host_dir"
    pct set "$ctid" -mp0 "${host_dir},mp=/mnt/backup" >&2
}

push_and_run() {
    local ctid="$1" local_script="$2" remote_path="$3"
    shift 3
    pct push "$ctid" "$local_script" "$remote_path" --perms 0700 >&2
    # Extra NAME=value pairs (e.g. SUDO_USERNAME/SUDO_PASSWORD) are passed
    # as real environment variables via `env`, not baked into the script
    # text — a user-typed password can contain characters (quotes,
    # backticks, $) that would break envsubst's plain text substitution or
    # worse, get re-interpreted as shell syntax. `env` hands them to bash
    # as ordinary argv entries; no intermediate shell ever re-parses them.
    pct exec "$ctid" -- env "$@" bash "$remote_path" >&2
}

random_password() {
    openssl rand -base64 24 | tr -d '=+/' | cut -c1-24
}

# ============================================================================
# Interactive configuration
# ============================================================================
# Matches the community-scripts helper-script convention: explicitly asks
# for the values that matter (container IDs, hostnames, resources, ...)
# with the current default shown in brackets — press Enter to accept it.
# Any value already set via environment variable (see the header comment)
# becomes that default, so exporting still works for automation.
#
# Skipped when stdin isn't a terminal (piped input, CI, cron) or when
# QRID_NONINTERACTIVE=1 is set, so this script still runs unattended when
# every value is supplied via env vars.

ask() {
    local __varname="$1" __prompt="$2" __default __input
    __default="${!__varname}"
    read -rp "${__prompt} [${__default}]: " __input
    if [[ -n "$__input" ]]; then
        printf -v "$__varname" '%s' "$__input"
    fi
}

# /cluster/nextid doesn't reserve anything — it just reports the next free
# ID — so asking again would return the same value until something is
# actually created. Bump past it explicitly instead. Resolved before the
# prompts (whether or not they run) so "Container ID" has a real suggested
# default rather than an empty one.
if [[ -z "$CTID_DB" ]]; then CTID_DB="$(next_free_ctid)"; fi
if [[ -z "$CTID_APP" ]]; then CTID_APP=$((CTID_DB + 1)); fi

if [[ -t 0 && "${QRID_NONINTERACTIVE:-}" != "1" ]]; then
    echo
    echo "Condo ID System — Proxmox stack configuration"
    echo "Press Enter on any prompt to accept the default shown in [brackets]."

    echo
    echo "--- PostgreSQL container ---"
    ask CTID_DB "Container ID"
    ask HOSTNAME_DB "Hostname"
    ask CORES_DB "CPU cores"
    ask MEM_DB_MB "RAM (MB)"
    ask DISK_DB_GB "Disk (GB)"

    echo
    echo "--- App container ---"
    ask CTID_APP "Container ID"
    ask HOSTNAME_APP "Hostname"
    ask CORES_APP "CPU cores"
    ask MEM_APP_MB "RAM (MB)"
    ask DISK_APP_GB "Disk (GB)"

    echo
    echo "--- Shared infrastructure ---"
    ask STORAGE "Storage pool for container disks"
    ask TEMPLATE_STORAGE "Storage pool for the LXC template"
    ask BRIDGE "Network bridge"

    echo
    echo "--- Application ---"
    ask DB_NAME "PostgreSQL database name"
    ask DB_USER "PostgreSQL app role"

    echo
    echo "--- Backups ---"
    ask BACKUP_HOST_DIR "Backup directory on the Proxmox host"

    echo
    echo "--- Sudo user (optional, created identically on both containers) ---"
    while true; do
        SUDO_USERNAME_INPUT=""
        read -rp "Username (blank = skip, root-only) [${SUDO_USERNAME}]: " SUDO_USERNAME_INPUT
        if [[ -n "$SUDO_USERNAME_INPUT" ]]; then
            SUDO_USERNAME="$SUDO_USERNAME_INPUT"
        fi
        if [[ -z "$SUDO_USERNAME" ]]; then
            break
        fi
        if [[ "$SUDO_USERNAME" == "root" ]]; then
            echo "'root' is not a valid answer here — root already exists on both" >&2
            echo "containers with the password this script generates and prints at the" >&2
            echo "end. Entering it would silently replace that password and make the" >&2
            echo "printed one wrong. Leave this blank to stay root-only." >&2
            SUDO_USERNAME=""
            continue
        fi
        if [[ ! "$SUDO_USERNAME" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]]; then
            echo "Not a valid Linux username: must start with a lowercase letter or" >&2
            echo "underscore, contain only lowercase letters, digits, '_' or '-', and" >&2
            echo "be at most 32 characters. Got: '${SUDO_USERNAME}'" >&2
            SUDO_USERNAME=""
            continue
        fi
        break
    done
    if [[ -n "$SUDO_USERNAME" ]]; then
        while true; do
            read -rsp "Password for ${SUDO_USERNAME}: " SUDO_PASSWORD_1
            echo
            read -rsp "Confirm password: " SUDO_PASSWORD_2
            echo
            if [[ -z "$SUDO_PASSWORD_1" ]]; then
                echo "Password can't be empty — try again." >&2
                continue
            fi
            if [[ "$SUDO_PASSWORD_1" != "$SUDO_PASSWORD_2" ]]; then
                echo "Passwords didn't match — try again." >&2
                continue
            fi
            SUDO_PASSWORD="$SUDO_PASSWORD_1"
            break
        done
    fi

    echo
    echo "  DB:  CT ${CTID_DB} (${HOSTNAME_DB}) — ${CORES_DB} cores, ${MEM_DB_MB}MB RAM, ${DISK_DB_GB}GB disk"
    echo "  App: CT ${CTID_APP} (${HOSTNAME_APP}) — ${CORES_APP} cores, ${MEM_APP_MB}MB RAM, ${DISK_APP_GB}GB disk"
    echo "  Storage: ${STORAGE} (disks) / ${TEMPLATE_STORAGE} (template) on ${BRIDGE}"
    echo "  DB: ${DB_NAME} / ${DB_USER}"
    echo "  Backups: ${BACKUP_HOST_DIR}"
    if [[ -n "$SUDO_USERNAME" ]]; then
        echo "  Sudo user: ${SUDO_USERNAME} (created on both containers)"
    else
        echo "  Sudo user: none (root-only)"
    fi
    echo
    CONFIRM=""
    read -rp "Proceed? [Y/n]: " CONFIRM
    if [[ "$CONFIRM" =~ ^[Nn] ]]; then
        echo "Aborted."
        exit 1
    fi
fi

# DB_NAME/DB_USER end up unquoted in generated SQL (provision-db.sh) and in
# a crontab line (the backup job below) — anything but a plain identifier
# there is a syntax break at best and injection at worst.
for _pair in "DB_NAME:$DB_NAME" "DB_USER:$DB_USER"; do
    _name="${_pair%%:*}" _value="${_pair#*:}"
    if [[ ! "$_value" =~ ^[a-zA-Z_][a-zA-Z0-9_]*$ ]]; then
        echo "ERROR: $_name must start with a letter/underscore and contain only letters, digits, and underscores. Got: '$_value'" >&2
        exit 1
    fi
done
unset _pair _name _value

# Also enforced outside the prompt loop above, because SUDO_USERNAME can
# arrive from the environment (QRID_NONINTERACTIVE=1, or a pre-exported
# value), which never passes through that loop.
if [[ -n "$SUDO_USERNAME" ]]; then
    if [[ "$SUDO_USERNAME" == "root" ]]; then
        echo "ERROR: SUDO_USERNAME cannot be 'root'." >&2
        echo "Root already exists on both containers, with the password this script" >&2
        echo "generates and prints in its summary. Passing 'root' here skips useradd" >&2
        echo "and runs chpasswd instead, silently replacing that password — leaving" >&2
        echo "the summary telling you a root password that no longer works." >&2
        echo "Leave SUDO_USERNAME empty to stay root-only." >&2
        exit 1
    fi
    if [[ ! "$SUDO_USERNAME" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]]; then
        echo "ERROR: SUDO_USERNAME must be a valid Linux username — start with a" >&2
        echo "lowercase letter or underscore, contain only lowercase letters, digits," >&2
        echo "'_' or '-', and be at most 32 characters. Got: '${SUDO_USERNAME}'" >&2
        exit 1
    fi
fi

# ============================================================================
# Provision
# ============================================================================

DB_ROOT_PASSWORD="$(random_password)"
APP_ROOT_PASSWORD="$(random_password)"
DB_PASSWORD="$(random_password)"

log "Ubuntu 24.04 template"
TEMPLATE="$(ensure_template_downloaded "$TEMPLATE_STORAGE")"

create_container "$CTID_DB" "$HOSTNAME_DB" "$CORES_DB" "$MEM_DB_MB" "$DISK_DB_GB" "$TEMPLATE"
create_container "$CTID_APP" "$HOSTNAME_APP" "$CORES_APP" "$MEM_APP_MB" "$DISK_APP_GB" "$TEMPLATE"

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

log "Setting root passwords"
set_root_password "$CTID_DB" "$DB_ROOT_PASSWORD"
set_root_password "$CTID_APP" "$APP_ROOT_PASSWORD"

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
push_and_run "$CTID_DB" /tmp/qrid-provision-db.sh /root/provision-db.sh \
    "SUDO_USERNAME=${SUDO_USERNAME}" "SUDO_PASSWORD=${SUDO_PASSWORD}"

log "Provisioning the app ($CTID_APP)"
# shellcheck disable=SC2016  # single-quoted on purpose: this is envsubst's variable allowlist, not a bash expansion
REPO_URL="$REPO_URL" REPO_BRANCH="$REPO_BRANCH" APP_IP="$APP_IP" \
    DB_HOST="$DB_IP" DB_NAME="$DB_NAME" DB_USER="$DB_USER" DB_PASSWORD="$DB_PASSWORD" \
    envsubst '${REPO_URL} ${REPO_BRANCH} ${APP_IP} ${DB_HOST} ${DB_NAME} ${DB_USER} ${DB_PASSWORD}' \
    < "${SCRIPT_DIR}/provision-app.sh" > /tmp/qrid-provision-app.sh
push_and_run "$CTID_APP" /tmp/qrid-provision-app.sh /root/provision-app.sh \
    "SUDO_USERNAME=${SUDO_USERNAME}" "SUDO_PASSWORD=${SUDO_PASSWORD}"

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
$( [[ -n "$SUDO_USERNAME" ]] && echo "  Sudo user '${SUDO_USERNAME}' created on both containers with the password you entered." )

  Save these somewhere safe — they are not stored anywhere else.

  ------------------------------------------------------------------------
  Access summary
  ------------------------------------------------------------------------

  qrid-db   ($DB_IP)
    5432/tcp  PostgreSQL — reachable only from $APP_IP (pg_hba.conf)
    80/tcp    http://${DB_IP}/         landing page
              http://${DB_IP}/health   -> "OK"
    22/tcp    ssh (base image default, not configured by this script)

  qrid-app  ($APP_IP)
    443/tcp   https://${APP_IP}/setup  first-run wizard — every other route
                                       redirects here until you complete it
              https://${APP_IP}/up     -> 200 once migrations have run
                                       (exempt from the redirect, so this
                                       check works before bootstrap)
              (self-signed via Caddy's internal CA — see step 2 below)
    80/tcp    redirects to 443
    22/tcp    ssh (base image default, not configured by this script)

  ------------------------------------------------------------------------

  Still to do by hand (this script can't reach outside the containers):
    1. DHCP reservation for $APP_IP (and ideally $DB_IP too) on your router
       — the app is served by IP only, so this address needs to stay fixed.
    2. Caddy is serving $APP_IP with its own internal CA cert (self-signed,
       not from a public CA — this is a LAN-only deployment, per
       architecture §1/§12). Your browser will warn on first visit until
       you trust that CA; see README.md for how to fetch and install it.
    3. >>> DO THIS NOW, NOT LATER <<<  Open https://${APP_IP}/setup and
       create the two Superadmin accounts. Until you do, the system is
       unclaimed: it serves the setup wizard to anyone who reaches
       $APP_IP on this LAN, and the first person to complete it becomes
       both Superadmins. That window is inherent to browser-based
       bootstrap (architecture §12) and the only thing that closes it is
       completing the wizard. Do not deploy and walk away.
       (Console break-glass still exists if you ever need it:
       cd /opt/qrid/app && php artisan id:superadmin-create <username>)
    4. Perform and verify one backup restore — Phase 2 isn't done until
       you've actually opened a restored backup, not just configured the
       job. See README.md.

  Sanity-check right now (bypasses TLS verification):
    curl http://${DB_IP}/          # DB container landing page (port 80)
    curl -ko /dev/null -w '%{http_code}\n' https://${APP_IP}/up
    # -> 200 means Laravel booted and migrations ran

  The real check, once you've trusted Caddy's internal CA (step 2 above):
    curl https://${APP_IP}/up

SUMMARY
