#!/usr/bin/env bash
# shellcheck source-path=SCRIPTDIR
#
# Condo ID System — Proxmox VE helper
#
# One command; where you run it decides what it does:
#
#   on the Proxmox host (as root)     builds a stack, or re-runs one
#   inside a Condo ID container       updates that container — the app
#                                     container its code and packages, the
#                                     database container its packages
#   anywhere else                     refuses, and says where to run it
#
#   bash -c "$(curl -fsSL https://raw.githubusercontent.com/alrlchoa/qr-id-generator/main/deploy/proxmox/create-qrid-stack.sh)"
#
# ...or from a local clone: bash create-qrid-stack.sh
#
# Inside a container you don't need the one-liner: every container this
# script builds gets an `update` command that downloads and runs it.
#
# A "stack" is one Condo ID system: a database container and an app
# container. One host can hold several — one per condo, say. Each stack has
# a name, stored as a tag (qrid-stack-<name>) on both of its containers, and
# gets its own hostnames and its own backup directory. The first stack on a
# host is "main", with the Phase 2 layout (qrid-db / qrid-app,
# /var/lib/vz/qrid-backups), so a stack built before stacks had names is
# still recognised as "main".
#
# On a terminal it opens a menu, community-scripts style (Phase 15):
#   New stack — Default   everything picked for you; you only confirm
#   New stack — Advanced  every setting as a dialog, checked as you type
#   Re-run a stack        pick one this host already has; its containers
#                         are resumed, never recreated
#
# Any setting can be given up front as an environment variable, read before
# the menu opens. var_* is the community-scripts naming; the Phase 2 names
# still work (README.md has the full table):
#
#   var_instance=tower-a var_db_ctid=301 bash -c "$(curl -fsSL ...)"
#
# QRID_NONINTERACTIVE=1 — or no terminal at all (piped, cron, CI) — skips
# every dialog: var_instance naming an existing stack re-runs it, anything
# else builds a new one. Nothing is created until every setting has passed
# the same checks the dialogs run. QRID_VERBOSE=yes (or var_verbose=yes)
# streams every command's output instead of writing it only to the log
# under /var/log/qrid/.
#
# Each stack is the two sibling LXCs architecture.md §12 calls for (app +
# Postgres, no Docker) plus a nightly backup cron in each — the one
# deliberate exception to "nothing runs unattended" (§7/§12). Updates only
# ever run because someone typed `update`.
#
# A container is only ever resumed as part of its own stack; anything else
# at a chosen ID is refused, with the stack it belongs to named. When a run
# fails it offers to remove the containers that run itself created —
# nothing that existed before it.
#
# The one-liner has no local checkout, so this script fetches its sibling
# files (qrid.func, provision-db.sh, provision-app.sh, deploy.sh,
# backup-db.sh, backup-app.sh, update-command.sh) from the same repo/branch
# at runtime; the one canonical copy of each stays in this directory in git.
set -euo pipefail

# ============================================================================
# Settings — each reads var_* first, then its Phase 2 name. Empty means
# "work it out": from the stack name, from this host, or — re-running a
# stack — from its containers.
# ============================================================================

INSTANCE="${var_instance:-${QRID_INSTANCE:-}}"   # "main" first, then stack2, stack3...

CTID_DB="${var_db_ctid:-${CTID_DB:-}}"      # the next free ID
CTID_APP="${var_app_ctid:-${CTID_APP:-}}"   # the one after it
HOSTNAME_DB="${var_db_hostname:-${HOSTNAME_DB:-}}"      # qrid-db, or qrid-<stack>-db
HOSTNAME_APP="${var_app_hostname:-${HOSTNAME_APP:-}}"   # qrid-app, or qrid-<stack>-app

# Both containers are light — this is a few-thousand-row LAN app.
CORES_DB="${var_db_cpu:-${CORES_DB:-2}}"
MEM_DB_MB="${var_db_ram:-${MEM_DB_MB:-2048}}"
DISK_DB_GB="${var_db_disk:-${DISK_DB_GB:-8}}"
CORES_APP="${var_app_cpu:-${CORES_APP:-2}}"
MEM_APP_MB="${var_app_ram:-${MEM_APP_MB:-2048}}"
DISK_APP_GB="${var_app_disk:-${DISK_APP_GB:-8}}"

# local-lvm / local / vmbr0 on a stock install, otherwise the active storage
# with the most free space and the first bridge. TEMPLATE_STORAGE stays
# separate from STORAGE because LVM-thin pools like local-lvm hold container
# disks but can't hold the vztmpl template file — only a directory storage
# like "local" can.
STORAGE="${var_storage:-${STORAGE:-}}"
TEMPLATE_STORAGE="${var_template_storage:-${TEMPLATE_STORAGE:-}}"
BRIDGE="${var_bridge:-${BRIDGE:-}}"

DB_NAME="${var_db_name:-${DB_NAME:-}}"   # qr_id_generator
DB_USER="${var_db_user:-${DB_USER:-}}"   # qrid

# Where this stack's backups land ON THE PROXMOX HOST, bind-mounted into both
# containers — "stored off the LXC itself" per Phase 2. Default
# /var/lib/vz/qrid-backups for "main", /var/lib/vz/qrid-backups-<stack>
# otherwise, so two stacks never prune each other's files. A different
# physical disk than STORAGE is better still: a backup on the same disk as
# the thing it backs up is not really a backup.
BACKUP_HOST_DIR="${var_backup_dir:-${BACKUP_HOST_DIR:-}}"

# Optional non-root sudo user, created identically on both containers.
# Blank (the default) = root only.
SUDO_USERNAME="${var_sudo_user:-${SUDO_USERNAME:-}}"
SUDO_PASSWORD="${var_sudo_password:-${SUDO_PASSWORD:-}}"
CHOSEN_ROOT_PASSWORD="${var_root_password:-}"

QRID_VERBOSE="${var_verbose:-${QRID_VERBOSE:-no}}"

# The app repo and branch to deploy, and where this script's sibling files
# come from when it runs as the one-liner. Set REPO_BRANCH to test a branch
# before it's merged — the app checkout, the sibling fetch and the update
# command installed in each container all follow it.
REPO_URL="${REPO_URL:-https://github.com/alrlchoa/qr-id-generator.git}"
REPO_BRANCH="${REPO_BRANCH:-main}"
REPO_RAW_BASE="${REPO_RAW_BASE:-https://raw.githubusercontent.com/alrlchoa/qr-id-generator/${REPO_BRANCH}/deploy/proxmox}"

UBUNTU_TEMPLATE_PATTERN="ubuntu-24.04-standard"
APP_DIR=/opt/qrid/app

# new = build a stack; resume = re-run one this host already has.
RUN_MODE=new
# yes = worked out from the stack name, so a renamed stack re-derives it.
HOSTNAME_DB_AUTO=no
HOSTNAME_APP_AUTO=no
BACKUP_DIR_AUTO=no
DB_NAMES_AUTO=no

# Stacks found on this host: stack name -> container ID.
declare -A STACK_DB=() STACK_APP=()

# ============================================================================
# Bootstrap — fetch and load qrid.func before anything else
# ============================================================================

# Sibling files come from a local checkout only when this script was run as
# a file from one. Run as the one-liner (bash -c) it has no file of its own,
# and the current directory — /tmp, say — is nobody's checkout: anything
# lying there must never be picked up and run as root.
LOCAL_SCRIPT_DIR=""
if [[ -n "${BASH_SOURCE[0]:-}" && -f "${BASH_SOURCE[0]}" ]]; then
    LOCAL_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
fi
WORK_DIR="$(mktemp -d)"
chmod 700 "$WORK_DIR"

# Copies NAME from a local checkout next to this file when there is one,
# otherwise fetches it from REPO_RAW_BASE (the one-liner case).
fetch_sibling() {
    local name="$1"
    if [[ -n "$LOCAL_SCRIPT_DIR" && -f "${LOCAL_SCRIPT_DIR}/${name}" ]]; then
        cp "${LOCAL_SCRIPT_DIR}/${name}" "${WORK_DIR}/${name}"
        return 0
    fi
    if ! curl -fsSL --retry 3 --retry-delay 5 --retry-all-errors \
        "${REPO_RAW_BASE}/${name}" -o "${WORK_DIR}/${name}"; then
        echo "Failed to fetch ${REPO_RAW_BASE}/${name}" >&2
        echo "REPO_BRANCH=${REPO_BRANCH} — if you're testing an unmerged branch, set REPO_BRANCH" >&2
        echo "(not just the URL you curled) to that branch too: export REPO_BRANCH=your-branch" >&2
        return 1
    fi
}

cleanup_work_dir() {
    rm -rf "$WORK_DIR"
}

if ! fetch_sibling qrid.func; then
    cleanup_work_dir
    exit 1
fi
# shellcheck source=qrid.func
source "${WORK_DIR}/qrid.func"

if ! v_branch_name "$REPO_BRANCH"; then
    msg_error "$QRID_ERR"
    cleanup_work_dir
    exit 1
fi

# ============================================================================
# Host queries
# ============================================================================

next_free_ctid() {
    pvesh get /cluster/nextid
}

# Cluster-wide: pvesh refuses an ID that any VM or container on any node holds.
ctid_is_free() {
    pvesh get /cluster/nextid --vmid "$1" >/dev/null 2>&1
}

next_free_after() {
    local id=$(( 10#$1 + 1 ))
    while ! ctid_is_free "$id"; do
        id=$(( id + 1 ))
    done
    echo "$id"
}

# Prints "free", "foreign", or "qrid STACK ROLE" for a Condo ID container.
# Containers are recognised by their tags: qrid-db / qrid-app for the role,
# qrid-stack-<name> for the stack. A role tag without a stack tag, or no
# tags at all with the Phase 2 hostnames (qrid-db / qrid-app), is "main" —
# stacks built before stacks had names.
container_info() {
    local ctid="$1" config tags host role stack
    if ctid_is_free "$ctid"; then
        echo free
        return 0
    fi
    if ! config="$(pct config "$ctid" 2>/dev/null)"; then
        echo foreign   # a VM, or a container on another node
        return 0
    fi
    tags="$(sed -n 's/^tags: //p' <<<"$config")"
    host="$(sed -n 's/^hostname: //p' <<<"$config")"
    role=""
    if [[ ";${tags};" == *";qrid-db;"* ]]; then
        role=db
    elif [[ ";${tags};" == *";qrid-app;"* ]]; then
        role=app
    fi
    if [[ -n "$role" ]]; then
        stack="$(trap - ERR; tr ';' '\n' <<<"$tags" | sed -n 's/^qrid-stack-//p' | head -n 1 || true)"
        echo "qrid ${stack:-main} ${role}"
        return 0
    fi
    if [[ ";${tags};" != *";qrid"* ]]; then
        case "$host" in
            qrid-db) echo "qrid main db"; return 0 ;;
            qrid-app) echo "qrid main app"; return 0 ;;
        esac
    fi
    echo foreign
}

# Prints free | ours | foreign | other:STACK:ROLE, relative to the stack this
# run is building (INSTANCE). "ours" is the only status a run resumes.
container_status_for() {
    local ctid="$1" role="$2" hostname="$3" info config _q stack r
    info="$(trap - ERR; container_info "$ctid" || true)"
    case "$info" in
        free)
            echo free
            ;;
        "qrid ${INSTANCE} ${role}")
            echo ours
            ;;
        qrid\ *)
            read -r _q stack r <<<"$info"
            echo "other:${stack}:${r}"
            ;;
        *)
            # A Phase 2 stack built with custom hostnames has no tags to go
            # by: an untagged container with the hostname this role expects
            # is taken as main's.
            config="$(trap - ERR; pct config "$ctid" 2>/dev/null || true)"
            if [[ "$INSTANCE" == main && -n "$config" \
                && -z "$(sed -n 's/^tags: //p' <<<"$config")" \
                && "$(sed -n 's/^hostname: //p' <<<"$config")" == "$hostname" ]]; then
                echo ours
            else
                echo foreign
            fi
            ;;
    esac
}

# Fills STACK_DB / STACK_APP with every Condo ID stack on this node.
scan_stacks() {
    local ctid info _q stack role
    STACK_DB=()
    STACK_APP=()
    while read -r ctid; do
        if [[ -z "$ctid" ]]; then
            continue
        fi
        info="$(trap - ERR; container_info "$ctid" || true)"
        if [[ "$info" != qrid\ * ]]; then
            continue
        fi
        read -r _q stack role <<<"$info"
        if [[ "$role" == db ]]; then
            STACK_DB["$stack"]="$ctid"
        else
            STACK_APP["$stack"]="$ctid"
        fi
    done < <(trap - ERR; pct list 2>/dev/null | awk 'NR > 1 {print $1}' || true)
}

stack_exists() {
    [[ -n "${STACK_DB[$1]:-}${STACK_APP[$1]:-}" ]]
}

all_stack_names() {
    printf '%s\n' "${!STACK_DB[@]}" "${!STACK_APP[@]}" | grep -v '^$' | sort -u
}

next_stack_name() {
    local n=2
    if ! stack_exists main; then
        echo main
        return 0
    fi
    while stack_exists "stack${n}"; do
        n=$(( n + 1 ))
    done
    echo "stack${n}"
}

stack_hostname() {
    if [[ "$INSTANCE" == main ]]; then
        echo "qrid-$1"
    else
        echo "qrid-${INSTANCE}-$1"
    fi
}

# stack_backup_dir [STACK] — this run's stack when no name is given.
stack_backup_dir() {
    local name="${1:-$INSTANCE}"
    if [[ "$name" == main ]]; then
        echo /var/lib/vz/qrid-backups
    else
        echo "/var/lib/vz/qrid-backups-${name}"
    fi
}

host_mem_mb() {
    free -m | awk '/^Mem:/ {print $2}'
}

# Active storages that can hold CONTENT, one "name free-GB" line each.
storage_list() {
    pvesm status --content "$1" 2>/dev/null \
        | awk 'NR > 1 && $3 == "active" { printf "%s %d\n", $1, $6 / 1048576 }'
}

storage_names() {
    storage_list "$1" | awk '{print $1}' | paste -sd, -
}

bridge_list() {
    ip -o link show type bridge 2>/dev/null | awk -F': ' '{print $2}' | cut -d@ -f1
}

pick_storage() {
    local content="$1" preferred="$2" lines
    lines="$(trap - ERR; storage_list "$content" || true)"
    if grep -q "^${preferred} " <<<"$lines"; then
        echo "$preferred"
        return 0
    fi
    sort -k2 -nr <<<"$lines" | awk 'NR == 1 {print $1}'
}

pick_bridge() {
    local bridges
    bridges="$(trap - ERR; bridge_list || true)"
    if grep -qx vmbr0 <<<"$bridges"; then
        echo vmbr0
        return 0
    fi
    head -n 1 <<<"$bridges"
}

random_password() {
    openssl rand -base64 24 | tr -d '=+/' | cut -c1-24
}

# ============================================================================
# Validators that need the host (the pure ones live in qrid.func)
# ============================================================================

# v_ctid_for VALUE ROLE HOSTNAME [OTHER_CTID]
v_ctid_for() {
    local id="$1" role="$2" hostname="$3" other="${4:-}" status rest
    if ! v_int_range "$id" 100 999999999 "A container ID"; then
        return 1
    fi
    if [[ -n "$other" && "$id" == "$other" ]]; then
        QRID_ERR="The two containers need different IDs — ${id} is already the database container's."
        return 1
    fi
    status="$(trap - ERR; container_status_for "$id" "$role" "$hostname" || true)"
    case "$status" in
        free|ours)
            return 0
            ;;
        other:*)
            rest="${status#other:}"
            QRID_ERR="Container ${id} is the ${rest#*:} container of Condo ID stack '${rest%%:*}'. To re-run that stack, choose \"Re-run an existing stack\" (or set var_instance=${rest%%:*}); for this stack, pick another ID."
            return 1
            ;;
    esac
    QRID_ERR="Container ID ${id} is already used by something this script didn't create. Pick another — $(trap - ERR; next_free_ctid || echo 'run pvesh get /cluster/nextid to find one that') is free."
    return 1
}

v_db_ctid()  { v_ctid_for "$1" db "$HOSTNAME_DB"; }
v_app_ctid() { v_ctid_for "$1" app "$HOSTNAME_APP" "$CTID_DB"; }
v_cores()    { v_int_range "$1" 1 "$(nproc)" "CPU cores"; }
v_mem()      { v_int_range "$1" 512 "$(host_mem_mb)" "RAM (MB)"; }
v_disk()     { v_int_range "$1" 4 65536 "Disk (GB)"; }
v_db_name()  { v_identifier "$1" "The database name"; }
v_db_user()  { v_identifier "$1" "The database role"; }
v_backup_dir() { v_abs_path "$1" "The backup directory"; }

v_new_instance() {
    if ! v_instance_name "$1"; then
        return 1
    fi
    if stack_exists "$1"; then
        QRID_ERR="A stack named '$1' already exists on this host (database CT ${STACK_DB[$1]:-none}, app CT ${STACK_APP[$1]:-none}). Pick another name, or choose \"Re-run an existing stack\" to re-run it."
        return 1
    fi
}

v_optional_username() {
    if [[ -z "$1" ]]; then
        return 0
    fi
    v_linux_username "$1"
}

v_storage_rootdir() {
    if storage_list rootdir | awk '{print $1}' | grep -qxF -- "$1"; then
        return 0
    fi
    QRID_ERR="Storage '${1}' doesn't exist, isn't active, or can't hold container disks. Usable here: $(trap - ERR; storage_names rootdir || true)."
    return 1
}

v_storage_vztmpl() {
    if storage_list vztmpl | awk '{print $1}' | grep -qxF -- "$1"; then
        return 0
    fi
    QRID_ERR="Storage '${1}' doesn't exist, isn't active, or can't hold LXC templates. Usable here: $(trap - ERR; storage_names vztmpl || true)."
    return 1
}

v_bridge() {
    if bridge_list | grep -qxF -- "$1"; then
        return 0
    fi
    QRID_ERR="Bridge '${1}' doesn't exist on this host. Available: $(trap - ERR; bridge_list | paste -sd, - || true)."
    return 1
}

# ============================================================================
# Choosing the settings
# ============================================================================

cancelled() {
    stop_spinner
    msg_warn "Cancelled — nothing was changed."
    exit 0
}

refuse() {
    msg_error "$1"
    exit 1
}

# Fills the settings that follow from the stack name. A value marked auto
# was derived from it, so it's derived again if the name changes.
fill_derived() {
    if [[ -z "$HOSTNAME_DB" || "$HOSTNAME_DB_AUTO" == yes ]]; then
        HOSTNAME_DB="$(stack_hostname db)"
        HOSTNAME_DB_AUTO=yes
    fi
    if [[ -z "$HOSTNAME_APP" || "$HOSTNAME_APP_AUTO" == yes ]]; then
        HOSTNAME_APP="$(stack_hostname app)"
        HOSTNAME_APP_AUTO=yes
    fi
    if [[ -z "$BACKUP_HOST_DIR" || "$BACKUP_DIR_AUTO" == yes ]]; then
        BACKUP_HOST_DIR="$(stack_backup_dir)"
        BACKUP_DIR_AUTO=yes
    fi
    if [[ -z "$DB_NAME" || -z "$DB_USER" ]]; then
        DB_NAME="${DB_NAME:-qr_id_generator}"
        DB_USER="${DB_USER:-qrid}"
        DB_NAMES_AUTO=yes
    fi
}

resolve_defaults() {
    local candidate status
    if [[ -z "$INSTANCE" ]]; then
        INSTANCE="$(next_stack_name)"
    fi
    fill_derived
    if [[ -z "$CTID_DB" ]]; then
        CTID_DB="$(trap - ERR; next_free_ctid || true)"
    fi
    # The app container defaults to the ID after the database's — unless
    # that ID holds something else, in which case the next free one.
    if [[ -z "$CTID_APP" && "$CTID_DB" =~ ^[0-9]+$ ]]; then
        candidate=$(( 10#$CTID_DB + 1 ))
        status="$(trap - ERR; container_status_for "$candidate" app "$HOSTNAME_APP" || true)"
        if [[ "$status" == free || "$status" == ours ]]; then
            CTID_APP="$candidate"
        else
            CTID_APP="$(trap - ERR; next_free_after "$candidate" || true)"
        fi
    fi
    if [[ -z "$STORAGE" ]]; then
        STORAGE="$(pick_storage rootdir local-lvm)"
    fi
    if [[ -z "$TEMPLATE_STORAGE" ]]; then
        TEMPLATE_STORAGE="$(pick_storage vztmpl local)"
    fi
    if [[ -z "$BRIDGE" ]]; then
        BRIDGE="$(pick_bridge)"
    fi
}

# Re-running a stack: its database and role names are whatever it was built
# with, read back from the app's .env or, failing that, the database
# container's backup cron line. Guessing the defaults instead would point a
# stack built with other names at an empty new database.
read_stack_db_names() {
    local env_name="" env_user="" line
    if [[ -n "${STACK_APP[$INSTANCE]:-}" ]] && pct status "$CTID_APP" 2>/dev/null | grep -q running; then
        env_name="$(trap - ERR; ct_exec "$CTID_APP" sed -n 's/^DB_DATABASE=//p' "${APP_DIR}/.env" 2>/dev/null || true)"
        env_user="$(trap - ERR; ct_exec "$CTID_APP" sed -n 's/^DB_USERNAME=//p' "${APP_DIR}/.env" 2>/dev/null || true)"
    fi
    if [[ -z "$env_name" && -n "${STACK_DB[$INSTANCE]:-}" ]] && pct status "$CTID_DB" 2>/dev/null | grep -q running; then
        line="$(trap - ERR; ct_exec "$CTID_DB" crontab -l 2>/dev/null | grep -F /root/backup-db.sh | head -n 1 || true)"
        env_name="$(sed -n 's/.*DB_NAME=\([A-Za-z0-9_]*\).*/\1/p' <<<"$line")"
        env_user="$(sed -n 's/.*DB_USER=\([A-Za-z0-9_]*\).*/\1/p' <<<"$line")"
    fi
    if [[ -z "$DB_NAME" && -n "$env_name" ]]; then
        DB_NAME="$env_name"
    fi
    if [[ -z "$DB_USER" && -n "$env_user" ]]; then
        DB_USER="$env_user"
    fi
    if [[ -z "$DB_NAME" || -z "$DB_USER" ]]; then
        msg_warn "Couldn't read stack '${INSTANCE}''s database names from its containers — assuming qr_id_generator / qrid. If it was built with other names, set var_db_name and var_db_user and run again."
    fi
}

# Loads everything a re-run needs from the stack's own containers.
load_stack_settings() {
    local name="$1" config backup
    INSTANCE="$name"
    RUN_MODE=resume
    CTID_DB="${STACK_DB[$name]:-$CTID_DB}"
    CTID_APP="${STACK_APP[$name]:-$CTID_APP}"
    if [[ -n "${STACK_DB[$name]:-}" ]]; then
        config="$(trap - ERR; pct config "$CTID_DB" 2>/dev/null || true)"
        HOSTNAME_DB="$(sed -n 's/^hostname: //p' <<<"$config")"
        CORES_DB="$(sed -n 's/^cores: //p' <<<"$config")"
        MEM_DB_MB="$(sed -n 's/^memory: //p' <<<"$config")"
        DISK_DB_GB="$(sed -n 's/^rootfs: .*size=\([0-9]*\)G.*/\1/p' <<<"$config")"
        backup="$(sed -n 's/^mp0: \([^,]*\),.*/\1/p' <<<"$config")"
        if [[ -z "$BACKUP_HOST_DIR" && "$backup" == */db ]]; then
            BACKUP_HOST_DIR="${backup%/db}"
        fi
    fi
    if [[ -n "${STACK_APP[$name]:-}" ]]; then
        config="$(trap - ERR; pct config "$CTID_APP" 2>/dev/null || true)"
        HOSTNAME_APP="$(sed -n 's/^hostname: //p' <<<"$config")"
        CORES_APP="$(sed -n 's/^cores: //p' <<<"$config")"
        MEM_APP_MB="$(sed -n 's/^memory: //p' <<<"$config")"
        DISK_APP_GB="$(sed -n 's/^rootfs: .*size=\([0-9]*\)G.*/\1/p' <<<"$config")"
        backup="$(sed -n 's/^mp0: \([^,]*\),.*/\1/p' <<<"$config")"
        if [[ -z "$BACKUP_HOST_DIR" && "$backup" == */app ]]; then
            BACKUP_HOST_DIR="${backup%/app}"
        fi
    fi
    CORES_DB="${CORES_DB:-2}" MEM_DB_MB="${MEM_DB_MB:-2048}" DISK_DB_GB="${DISK_DB_GB:-8}"
    CORES_APP="${CORES_APP:-2}" MEM_APP_MB="${MEM_APP_MB:-2048}" DISK_APP_GB="${DISK_APP_GB:-8}"
    read_stack_db_names
}

# Fills MENU_ITEMS with "name" "N GB free" pairs for dialog_menu.
storage_menu_items() {
    local name gb
    MENU_ITEMS=()
    while read -r name gb; do
        if [[ -n "$name" ]]; then
            MENU_ITEMS+=("$name" "${gb} GB free")
        fi
    done < <(trap - ERR; storage_list "$1" || true)
}

advanced_settings() {
    local bridge
    local -a bridges=()

    dialog_input INSTANCE "Stack name" "A short name for this Condo ID stack — one per condo, say.\nIt names the containers (qrid-<name>-db / -app) and the backup directory.\nLowercase letters, digits and hyphens." v_new_instance || cancelled
    fill_derived

    dialog_input CTID_DB "Database container" "Container ID for the PostgreSQL container." v_db_ctid || cancelled
    dialog_input HOSTNAME_DB "Database container" "Hostname for the PostgreSQL container." v_hostname || cancelled
    HOSTNAME_DB_AUTO=no
    dialog_input CORES_DB "Database container" "CPU cores (this host has $(nproc))." v_cores || cancelled
    dialog_input MEM_DB_MB "Database container" "RAM in MB (at least 512; this host has $(host_mem_mb))." v_mem || cancelled
    dialog_input DISK_DB_GB "Database container" "Disk size in GB (at least 4)." v_disk || cancelled

    if [[ "$CTID_APP" == "$CTID_DB" ]]; then
        CTID_APP="$(trap - ERR; next_free_after "$CTID_DB" || true)"
    fi
    dialog_input CTID_APP "App container" "Container ID for the app container." v_app_ctid || cancelled
    dialog_input HOSTNAME_APP "App container" "Hostname for the app container." v_hostname || cancelled
    HOSTNAME_APP_AUTO=no
    dialog_input CORES_APP "App container" "CPU cores (this host has $(nproc))." v_cores || cancelled
    dialog_input MEM_APP_MB "App container" "RAM in MB (at least 512; this host has $(host_mem_mb))." v_mem || cancelled
    dialog_input DISK_APP_GB "App container" "Disk size in GB (at least 4)." v_disk || cancelled

    storage_menu_items rootdir
    if (( ${#MENU_ITEMS[@]} == 0 )); then
        refuse "No active storage on this host can hold container disks (content type 'rootdir')."
    fi
    dialog_menu STORAGE "Storage" "Where both container disks go." "${MENU_ITEMS[@]}" || cancelled

    storage_menu_items vztmpl
    if (( ${#MENU_ITEMS[@]} == 0 )); then
        refuse "No active storage on this host can hold LXC templates (content type 'vztmpl')."
    fi
    dialog_menu TEMPLATE_STORAGE "Template storage" "Where the Ubuntu 24.04 template file goes.\nLVM-thin pools can't hold templates, so this is usually 'local'." "${MENU_ITEMS[@]}" || cancelled

    while read -r bridge; do
        if [[ -n "$bridge" ]]; then
            bridges+=("$bridge" "network bridge")
        fi
    done < <(trap - ERR; bridge_list || true)
    if (( ${#bridges[@]} == 0 )); then
        refuse "No network bridge found on this host."
    fi
    dialog_menu BRIDGE "Network" "The bridge both containers attach to. They get their addresses from DHCP on it." "${bridges[@]}" || cancelled

    dialog_input DB_NAME "Database" "PostgreSQL database name." v_db_name || cancelled
    dialog_input DB_USER "Database" "PostgreSQL role the app connects as." v_db_user || cancelled
    DB_NAMES_AUTO=no
    dialog_input BACKUP_HOST_DIR "Backups" "Directory on THIS host that receives this stack's nightly backups.\nEach stack needs its own. A different physical disk from the container storage is best." v_backup_dir || cancelled
    BACKUP_DIR_AUTO=no

    dialog_input SUDO_USERNAME "Sudo user (optional)" "A non-root sudo account, created identically on both containers.\nLeave blank to stay root-only." v_optional_username || cancelled
    if [[ -n "$SUDO_USERNAME" ]]; then
        dialog_password SUDO_PASSWORD "Sudo user" "Password for ${SUDO_USERNAME}." no || cancelled
    else
        dialog_password CHOSEN_ROOT_PASSWORD "Root password (optional)" "Root password for both containers.\nLeave blank to use a generated one, shown at the end." yes || cancelled
    fi

    if dialog_yesno "Output" "Show every command's output while building (verbose)?\n\nNo keeps the screen to one line per step and writes the rest to the log." 12; then
        QRID_VERBOSE=yes
    else
        QRID_VERBOSE=no
    fi
}

choose_existing_stack() {
    local picked="" name
    local -a items=()
    while read -r name; do
        if [[ -n "$name" ]]; then
            items+=("$name" "database CT ${STACK_DB[$name]:-missing} · app CT ${STACK_APP[$name]:-missing}")
        fi
    done < <(trap - ERR; all_stack_names || true)
    dialog_menu picked "Re-run a stack" "Pick the stack to re-run. Its containers are resumed, not recreated: provisioning is re-applied, and every password is reset — the summary at the end shows the new ones." "${items[@]}" || cancelled

    # Start from the stack itself, not from the new-stack defaults picked
    # before the menu opened.
    CTID_DB="" CTID_APP="" HOSTNAME_DB="" HOSTNAME_APP=""
    HOSTNAME_DB_AUTO=no HOSTNAME_APP_AUTO=no
    if [[ "$BACKUP_DIR_AUTO" == yes ]]; then
        BACKUP_HOST_DIR="" BACKUP_DIR_AUTO=no
    fi
    if [[ "$DB_NAMES_AUTO" == yes ]]; then
        DB_NAME="" DB_USER="" DB_NAMES_AUTO=no
    fi
    load_stack_settings "$picked"
    resolve_defaults
}

describe_ctid() {
    local status
    status="$(trap - ERR; container_status_for "$1" "$2" "$3" || true)"
    if [[ "$status" == ours ]]; then
        echo "CT $1 (exists — will resume)"
    else
        echo "CT $1 (new)"
    fi
}

summary_text() {
    cat <<SUMMARY_TEXT
Stack      ${INSTANCE} ($([[ "$RUN_MODE" == resume ]] && echo "re-run" || echo "new"))
Database   $(describe_ctid "$CTID_DB" db "$HOSTNAME_DB") ${HOSTNAME_DB}
           ${CORES_DB} cores, ${MEM_DB_MB} MB RAM, ${DISK_DB_GB} GB disk
App        $(describe_ctid "$CTID_APP" app "$HOSTNAME_APP") ${HOSTNAME_APP}
           ${CORES_APP} cores, ${MEM_APP_MB} MB RAM, ${DISK_APP_GB} GB disk
Storage    ${STORAGE} (disks), ${TEMPLATE_STORAGE} (template)
Network    ${BRIDGE}, DHCP
Postgres   database ${DB_NAME}, role ${DB_USER}
Backups    ${BACKUP_HOST_DIR} on this host
Sudo user  ${SUDO_USERNAME:-none — root only}
Output     $([[ "$QRID_VERBOSE" == yes ]] && echo verbose || echo "quiet (full log in /var/log/qrid)")
SUMMARY_TEXT
}

PROBLEMS=()

# check LABEL VALIDATOR VALUE — records the validator's reason on failure.
check() {
    local label="$1"
    shift
    if ! "$@"; then
        PROBLEMS+=("${label}: ${QRID_ERR}")
    fi
}

# Every setting, however it arrived (dialog, environment, default, or read
# back from an existing stack), passes here before anything is created — so
# a bad value fails in seconds, not deep into provisioning.
preflight() {
    local need=0 free name
    PROBLEMS=()
    if [[ "$RUN_MODE" == new ]]; then
        check "Stack name" v_new_instance "$INSTANCE"
    else
        check "Stack name" v_instance_name "$INSTANCE"
    fi
    check "Database container ID" v_db_ctid "$CTID_DB"
    check "App container ID" v_app_ctid "$CTID_APP"
    check "Database hostname" v_hostname "$HOSTNAME_DB"
    check "App hostname" v_hostname "$HOSTNAME_APP"
    check "Database CPU cores" v_cores "$CORES_DB"
    check "Database RAM" v_mem "$MEM_DB_MB"
    check "Database disk" v_disk "$DISK_DB_GB"
    check "App CPU cores" v_cores "$CORES_APP"
    check "App RAM" v_mem "$MEM_APP_MB"
    check "App disk" v_disk "$DISK_APP_GB"
    check "Container storage" v_storage_rootdir "$STORAGE"
    check "Template storage" v_storage_vztmpl "$TEMPLATE_STORAGE"
    check "Network bridge" v_bridge "$BRIDGE"
    check "Database name" v_db_name "$DB_NAME"
    check "Database role" v_db_user "$DB_USER"
    check "Backup directory" v_backup_dir "$BACKUP_HOST_DIR"
    check "Sudo username" v_optional_username "$SUDO_USERNAME"
    if [[ -n "$SUDO_USERNAME" && -z "$SUDO_PASSWORD" ]]; then
        PROBLEMS+=("Sudo password: a sudo user needs a password (SUDO_PASSWORD or var_sudo_password).")
    fi

    # Two stacks sharing a backup directory would prune each other's files.
    for name in "${!STACK_DB[@]}" "${!STACK_APP[@]}"; do
        if [[ "$name" != "$INSTANCE" && "$BACKUP_HOST_DIR" == "$(stack_backup_dir "$name")" ]]; then
            PROBLEMS+=("Backup directory: ${BACKUP_HOST_DIR} is stack '${name}''s — each stack needs its own.")
            break
        fi
    done

    # Only containers this run will create take new space.
    if (( ${#PROBLEMS[@]} == 0 )); then
        if ctid_is_free "$CTID_DB"; then
            need=$(( need + 10#$DISK_DB_GB ))
        fi
        if ctid_is_free "$CTID_APP"; then
            need=$(( need + 10#$DISK_APP_GB ))
        fi
        free="$(trap - ERR; storage_list rootdir | awk -v s="$STORAGE" '$1 == s {print $2}' || true)"
        if (( need > ${free:-0} )); then
            PROBLEMS+=("Disk space: the new containers need ${need} GB on '${STORAGE}', which has ${free:-0} GB free.")
        fi
    fi

    if (( ${#PROBLEMS[@]} )); then
        msg_error "These settings won't work — nothing has been created:"
        printf '     • %s\n' "${PROBLEMS[@]}" >&2
        printf '\n   %s\n' "Re-run and choose Advanced to change them, or set them as environment variables (README.md)." >&2
        exit 1
    fi
    msg_ok "Settings checked"
}

choose_settings() {
    local mode=1 count
    local -a items=(1 "New stack — Default install (named '${INSTANCE}')" 2 "New stack — Advanced install")
    count="$(trap - ERR; all_stack_names | grep -c . || true)"
    if (( ${count:-0} > 0 )); then
        items+=(3 "Re-run an existing stack (${count} on this host)")
    fi
    items+=(4 "Cancel")

    header_info "Condo ID stacks on $(hostname)"
    dialog_menu mode "Condo ID" "Build a new Condo ID stack — a database container and an app container — or re-run one this host already has.\n\nDefault picks every setting for you; Advanced asks for each one." \
        "${items[@]}" || cancelled
    case "$mode" in
        1) ;;
        2) advanced_settings ;;
        3) choose_existing_stack ;;
        *) cancelled ;;
    esac
    preflight
    if ! dialog_yesno "$([[ "$RUN_MODE" == resume ]] && echo "Re-run the stack?" || echo "Create the stack?")" \
        "$(summary_text)\n\n$([[ "$RUN_MODE" == resume ]] && echo "Re-run stack '${INSTANCE}' with these settings?" || echo "Create stack '${INSTANCE}' with these settings?")" 23; then
        cancelled
    fi
}

# ============================================================================
# Building
# ============================================================================

CREATED_CTIDS=()

# The error trap's cleanup hook. Only ever touches containers this run
# created — a resumed container is left exactly as it was.
offer_cleanup() {
    local ids id
    if (( ${#CREATED_CTIDS[@]} == 0 )); then
        msg_warn "This run created no containers — nothing to clean up. Re-run once the problem above is fixed."
        return 0
    fi
    ids="${CREATED_CTIDS[*]}"
    if qrid_interactive && dialog_yesno "Clean up?" "This run created container(s) ${ids}, and they're incomplete.\n\nRemove them now? Containers that existed before this run are never touched.\n\nChoose No to keep them for inspection — choosing \"Re-run an existing stack\" for '${INSTANCE}' resumes against them." 16; then
        for id in "${CREATED_CTIDS[@]}"; do
            pct stop "$id" >/dev/null 2>&1 || true
            if pct destroy "$id" --purge >/dev/null 2>&1; then
                msg_ok "Removed container ${id}"
            else
                msg_warn "Couldn't remove container ${id} — remove it by hand: pct destroy ${id} --purge"
            fi
        done
    else
        msg_warn "Kept container(s) ${ids}. Re-run stack '${INSTANCE}' to resume against them, or remove them: pct destroy <id> --purge"
    fi
}

ensure_template() {
    local storage="$1" template
    template="$(trap - ERR; pveam available 2>/dev/null | awk '{print $2}' | grep "^${UBUNTU_TEMPLATE_PATTERN}" | sort -V | tail -1 || true)"
    if [[ -z "$template" ]]; then
        run pveam update
        template="$(trap - ERR; pveam available 2>/dev/null | awk '{print $2}' | grep "^${UBUNTU_TEMPLATE_PATTERN}" | sort -V | tail -1 || true)"
    fi
    if [[ -z "$template" ]]; then
        refuse "No ${UBUNTU_TEMPLATE_PATTERN} template is listed by 'pveam available' — check this host's internet access."
    fi
    if ! pveam list "$storage" 2>/dev/null | grep -q "$template"; then
        run pveam download "$storage" "$template"
    fi
    TEMPLATE="${storage}:vztmpl/${template}"
}

# A resumed container gets this stack's tags if it's missing them — a
# Phase 2 container has none — so the next run knows it by tag. Any tags an
# admin added themselves are kept.
adopt_tags() {
    local ctid="$1" role="$2" tags wanted
    tags="$(trap - ERR; pct config "$ctid" 2>/dev/null | sed -n 's/^tags: //p' || true)"
    wanted="$(trap - ERR; { tr ';' '\n' <<<"$tags"; printf '%s\n' qrid "qrid-${role}" "qrid-stack-${INSTANCE}"; } \
        | grep -v '^$' | sort -u | paste -sd';' - || true)"
    if [[ -n "$wanted" && ";${tags};" != *";qrid-stack-${INSTANCE};"* ]]; then
        run pct set "$ctid" --tags "$wanted"
    fi
}

create_container() {
    local role="$1" ctid="$2" hostname="$3" cores="$4" mem="$5" disk_gb="$6" status
    status="$(trap - ERR; container_status_for "$ctid" "$role" "$hostname" || true)"
    case "$status" in
        ours)
            adopt_tags "$ctid" "$role"
            msg_ok "Container ${ctid} (${hostname}) already exists — resuming against it"
            return 0
            ;;
        free) ;;
        *)
            refuse "Container ${ctid} is already used by something that isn't stack '${INSTANCE}'s ${role} container. Pick a different ID."
            ;;
    esac

    msg_info "Creating container ${ctid} (${hostname})"
    # Recorded before pct create, not after: a create that dies halfway
    # leaves a container behind, and that's exactly the one cleanup must find.
    CREATED_CTIDS+=("$ctid")
    # No --password: pct create would put it in this process's argv, readable
    # via /proc/<pid>/cmdline. Root's password is set after boot instead,
    # piped over stdin (set_root_password).
    # Deliberately not started here — the backup bind mount must be set on a
    # stopped container to mount at boot, not hotplugged into a running one.
    run pct create "$ctid" "$TEMPLATE" \
        --hostname "$hostname" \
        --tags "qrid;qrid-${role};qrid-stack-${INSTANCE}" \
        --cores "$cores" \
        --memory "$mem" \
        --swap 512 \
        --rootfs "${STORAGE}:${disk_gb}" \
        --net0 "name=eth0,bridge=${BRIDGE},ip=dhcp" \
        --unprivileged 1 \
        --features nesting=0 \
        --onboot 1
    msg_ok "Created container ${ctid} (${hostname})"
}

bind_backup_dir() {
    local ctid="$1" subdir="$2" host_dir
    host_dir="${BACKUP_HOST_DIR}/${subdir}"
    mkdir -p "$host_dir"
    # Unprivileged LXCs remap UIDs: the host's root shows up as nobody:nogroup
    # inside, so a root-owned 0755 dir can't be written by postgres/qrid
    # there. Rather than opening it to every user on the host, chown it to
    # container-root's host-side uid: both containers are --unprivileged 1
    # with no custom idmap, so Proxmox maps container uid 0 to host 100000.
    chown 100000:100000 "$host_dir"
    chmod 0770 "$host_dir"
    if pct config "$ctid" | grep -qxF "mp0: ${host_dir},mp=/mnt/backup"; then
        return 0   # already attached by an earlier run
    fi
    run pct set "$ctid" -mp0 "${host_dir},mp=/mnt/backup"
}

# Requires the container to be running. Piping over stdin keeps the
# password out of every process's argv.
set_root_password() {
    printf 'root:%s\n' "$2" | ct_exec "$1" chpasswd >>"${QRID_LOG:-/dev/null}" 2>&1
}

# wait_for_ip CTID VAR
wait_for_ip() {
    local ctid="$1" __var="$2" ip=""
    for _ in $(seq 1 30); do
        ip="$(trap - ERR; ct_exec "$ctid" hostname -I 2>/dev/null | awk '{print $1}' || true)"
        if [[ -n "$ip" ]]; then
            printf -v "$__var" '%s' "$ip"
            return 0
        fi
        sleep 2
    done
    msg_error "Container ${ctid} didn't get an IP address within a minute — check bridge ${BRIDGE} and your DHCP server."
    return 1
}

push_and_run() {
    local ctid="$1" local_script="$2" remote_path="$3"
    shift 3
    run pct push "$ctid" "$local_script" "$remote_path" --perms 0700
    # Extra NAME=value pairs (SUDO_USERNAME/SUDO_PASSWORD) travel as real
    # environment variables via env, not baked into the script text — a
    # typed password can contain quotes, backticks or $ that would break
    # envsubst's plain substitution or be re-read as shell syntax.
    run ct_exec "$ctid" "$@" bash "$remote_path"
}

# install_cron CTID MATCH LINE — replaces any earlier line for MATCH, so a
# re-run leaves one backup job, not two.
install_cron() {
    # shellcheck disable=SC2016  # single-quoted on purpose: $CRON_MATCH/$CRON_LINE expand in the container's shell, from env
    run ct_exec "$1" CRON_MATCH="$2" CRON_LINE="$3" bash -c \
        '(crontab -l 2>/dev/null | grep -vF "$CRON_MATCH"; echo "$CRON_LINE") | crontab -'
}

# The `update` command, and /etc/qrid-role telling it which container it's
# in. Typing `update` downloads the current create-qrid-stack.sh from this
# stack's branch and runs it in the container (see update_mode below).
install_update_command() {
    local ctid="$1" role="$2"
    qrid_render_update_command "${WORK_DIR}/update-command.sh" "$REPO_BRANCH" "${WORK_DIR}/update-command.rendered.sh"
    run pct push "$ctid" "${WORK_DIR}/update-command.rendered.sh" /usr/local/bin/update --perms 0755
    printf 'role=%s\nstack=%s\nbranch=%s\n' "$role" "$INSTANCE" "$REPO_BRANCH" > "${WORK_DIR}/qrid-role"
    run pct push "$ctid" "${WORK_DIR}/qrid-role" /etc/qrid-role --perms 0644
}

# The Notes panel on each container's Summary page in the Proxmox UI — what
# it is and where to go, for whoever finds it there later. No passwords.
set_notes() {
    local db_notes app_notes
    db_notes="## Condo ID — database (stack ${INSTANCE})

PostgreSQL for Condo ID stack **${INSTANCE}**. It accepts connections only from its app container, CT ${CTID_APP} (${APP_IP}).

- Status page: http://${DB_IP}/ — health check: http://${DB_IP}/health
- Update: pct enter ${CTID_DB}, then type update — installs OS package updates, PostgreSQL's included
- Nightly backup at 2:00 → ${BACKUP_HOST_DIR}/db on the Proxmox host
- Built by create-qrid-stack.sh — see deploy/proxmox/README.md in the repo"
    app_notes="## Condo ID — app (stack ${INSTANCE})

- App: https://${APP_IP}/
- First run: https://${APP_IP}/setup — complete it right away; until then anyone on the LAN can claim the system
- Cloudflare Tunnel: Public Hostname → Service http://${APP_IP}:80, HTTP Host Header empty. Check it with: qrid-selftest <hostname>
- Database: CT ${CTID_DB} (${DB_IP})
- Update: pct enter ${CTID_APP}, then type update — pulls the latest code, builds, migrates, and installs OS package updates
- Nightly backup at 2:15 → ${BACKUP_HOST_DIR}/app on the Proxmox host
- Built by create-qrid-stack.sh — see deploy/proxmox/README.md in the repo"
    run pct set "$CTID_DB" --description "$db_notes"
    run pct set "$CTID_APP" --description "$app_notes"
}

build_stack() {
    local ctid

    DB_ROOT_PASSWORD="$(random_password)"
    APP_ROOT_PASSWORD="$(random_password)"
    DB_PASSWORD="$(random_password)"
    # An operator-chosen root password replaces the generated ones outright,
    # so the summary never reports a password that no longer works.
    if [[ -n "$CHOSEN_ROOT_PASSWORD" ]]; then
        DB_ROOT_PASSWORD="$CHOSEN_ROOT_PASSWORD"
        APP_ROOT_PASSWORD="$CHOSEN_ROOT_PASSWORD"
    fi

    QRID_CLEANUP_HOOK=offer_cleanup

    msg_info "Checking for the Ubuntu 24.04 template on ${TEMPLATE_STORAGE}"
    ensure_template "$TEMPLATE_STORAGE"
    msg_ok "Template ready: ${TEMPLATE#*vztmpl/}"

    create_container db "$CTID_DB" "$HOSTNAME_DB" "$CORES_DB" "$MEM_DB_MB" "$DISK_DB_GB"
    create_container app "$CTID_APP" "$HOSTNAME_APP" "$CORES_APP" "$MEM_APP_MB" "$DISK_APP_GB"

    msg_info "Attaching the backup directory to both containers"
    bind_backup_dir "$CTID_DB" db
    bind_backup_dir "$CTID_APP" app
    msg_ok "Backups go to ${BACKUP_HOST_DIR}/db and ${BACKUP_HOST_DIR}/app"

    msg_info "Starting both containers"
    for ctid in "$CTID_DB" "$CTID_APP"; do
        if ! pct status "$ctid" | grep -q running; then
            run pct start "$ctid"
        fi
    done
    msg_ok "Both containers running"

    msg_info "Setting root passwords"
    set_root_password "$CTID_DB" "$DB_ROOT_PASSWORD"
    set_root_password "$CTID_APP" "$APP_ROOT_PASSWORD"
    msg_ok "Root passwords set"

    msg_info "Waiting for both containers to get an IP address"
    wait_for_ip "$CTID_DB" DB_IP
    wait_for_ip "$CTID_APP" APP_IP
    msg_ok "Database ${DB_IP} · App ${APP_IP}"

    msg_info "Provisioning PostgreSQL in container ${CTID_DB} (a few minutes)"
    # shellcheck disable=SC2016  # single-quoted on purpose: envsubst's variable allowlist, not a bash expansion
    DB_MEM_MB="$MEM_DB_MB" DB_NAME="$DB_NAME" DB_USER="$DB_USER" DB_PASSWORD="$DB_PASSWORD" APP_IP="$APP_IP" \
        envsubst '${DB_MEM_MB} ${DB_NAME} ${DB_USER} ${DB_PASSWORD} ${APP_IP}' \
        < "${WORK_DIR}/provision-db.sh" > "${WORK_DIR}/provision-db.rendered.sh"
    push_and_run "$CTID_DB" "${WORK_DIR}/provision-db.rendered.sh" /root/provision-db.sh \
        "SUDO_USERNAME=${SUDO_USERNAME}" "SUDO_PASSWORD=${SUDO_PASSWORD}"
    msg_ok "PostgreSQL ready — database ${DB_NAME}, reachable only from ${APP_IP}"

    msg_info "Provisioning the app in container ${CTID_APP} — PHP, Caddy, build, migrations (several minutes)"
    # shellcheck disable=SC2016  # single-quoted on purpose: envsubst's variable allowlist, not a bash expansion
    REPO_URL="$REPO_URL" REPO_BRANCH="$REPO_BRANCH" APP_IP="$APP_IP" \
        DB_HOST="$DB_IP" DB_NAME="$DB_NAME" DB_USER="$DB_USER" DB_PASSWORD="$DB_PASSWORD" \
        envsubst '${REPO_URL} ${REPO_BRANCH} ${APP_IP} ${DB_HOST} ${DB_NAME} ${DB_USER} ${DB_PASSWORD}' \
        < "${WORK_DIR}/provision-app.sh" > "${WORK_DIR}/provision-app.rendered.sh"
    push_and_run "$CTID_APP" "${WORK_DIR}/provision-app.rendered.sh" /root/provision-app.sh \
        "SUDO_USERNAME=${SUDO_USERNAME}" "SUDO_PASSWORD=${SUDO_PASSWORD}"
    msg_ok "App deployed behind Caddy at https://${APP_IP}"

    msg_info "Installing deploy.sh and the nightly backup jobs"
    run pct push "$CTID_APP" "${WORK_DIR}/deploy.sh" /opt/qrid/deploy.sh --perms 0700
    run pct push "$CTID_DB" "${WORK_DIR}/backup-db.sh" /root/backup-db.sh --perms 0700
    run pct push "$CTID_APP" "${WORK_DIR}/backup-app.sh" /root/backup-app.sh --perms 0700
    # The one deliberate cron entry on each box — architecture §7/§12.
    install_cron "$CTID_DB" /root/backup-db.sh \
        "0 2 * * * DB_NAME=${DB_NAME} DB_USER=${DB_USER} /root/backup-db.sh >> /var/log/qrid-backup.log 2>&1"
    install_cron "$CTID_APP" /root/backup-app.sh \
        "15 2 * * * /root/backup-app.sh >> /var/log/qrid-backup.log 2>&1"
    msg_ok "deploy.sh installed; backups run nightly at 2:00 (database) and 2:15 (app)"

    msg_info "Installing the update command in both containers"
    install_update_command "$CTID_DB" db
    install_update_command "$CTID_APP" app
    msg_ok "Type update inside either container to update it"

    msg_info "Writing notes to each container's Summary panel"
    set_notes
    msg_ok "Proxmox notes written"

    QRID_CLEANUP_HOOK=""
}

print_summary() {
    cat <<SUMMARY

  Stack:    $INSTANCE
  DB LXC:   $CTID_DB  ($HOSTNAME_DB)  $DB_IP
  App LXC:  $CTID_APP  ($HOSTNAME_APP)  $APP_IP

  DB root password (Proxmox container login): $DB_ROOT_PASSWORD
  App root password (Proxmox container login): $APP_ROOT_PASSWORD
  Postgres app-user password ($DB_USER):        $DB_PASSWORD
$( [[ -n "$SUDO_USERNAME" ]] && echo "  Sudo user '${SUDO_USERNAME}' created on both containers with the password you entered." )
$( [[ -n "$CHOSEN_ROOT_PASSWORD" ]] && echo "  (Root's password above is the one you entered, not a generated one.)" )

  Save these somewhere safe — they are not stored anywhere else.
  Full log of this run: $QRID_LOG

  ------------------------------------------------------------------------
  Access summary
  ------------------------------------------------------------------------

  $HOSTNAME_DB   ($DB_IP)
    5432/tcp  PostgreSQL — reachable only from $APP_IP (pg_hba.conf)
    80/tcp    http://${DB_IP}/         landing page
              http://${DB_IP}/health   -> "OK"
    22/tcp    ssh (base image default, not configured by this script)

  $HOSTNAME_APP  ($APP_IP)
    443/tcp   https://${APP_IP}/setup  first-run wizard — every other route
                                       redirects here until you complete it
              https://${APP_IP}/up     -> 200 once migrations have run
                                       (exempt from the redirect, so this
                                       check works before bootstrap)
              (self-signed via Caddy's internal CA — see step 2 below)
    80/tcp    the Cloudflare Tunnel's origin — plain HTTP, for requests
              Cloudflare forwards; a browser on the LAN is redirected to 443
    22/tcp    ssh (base image default, not configured by this script)

  ------------------------------------------------------------------------

  Still to do by hand (this script can't reach outside the containers):
    1. DHCP reservation for $APP_IP (and ideally $DB_IP too) on your router
       — the LAN and any Cloudflare route reach the app by this IP, so it
       needs to stay fixed.
    2. Caddy is serving $APP_IP with its own internal CA cert (self-signed,
       not from a public CA — on the LAN the app is reached by IP,
       architecture §12). Your browser will warn on first visit until
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

  ------------------------------------------------------------------------
  Public access through Cloudflare Zero Trust (optional, after step 3)
  ------------------------------------------------------------------------

  Finish step 3 first: until setup is done, the app refuses every page
  that comes through the tunnel.

  In Cloudflare Zero Trust:  Networks > Tunnels > (your tunnel) >
  Public Hostname > Add a public hostname
    Hostname:          your domain, e.g. ids.example.com
    Service:           http://${APP_IP}:80
    HTTP Host Header:  leave empty (under Additional application settings)

  That's all. The app works at any hostname with no further changes —
  links and redirects follow the address it was reached at. If you use an
  https:// service instead (https://${APP_IP}), also turn on No TLS Verify:
  the certificate here is self-signed, and without it Cloudflare shows 502.

  Check it from inside the app container (pct enter ${CTID_APP}):
    qrid-selftest ids.example.com      # PASS/FAIL per check
  Optional — point APP_URL (used only by artisan commands) at the domain:
    qrid-set-domain ids.example.com

  To update later, open either container and type: update
    pct enter ${CTID_APP}    # app: latest code, build, migrate, OS packages
    pct enter ${CTID_DB}    # database: OS packages, PostgreSQL included

SUMMARY
}

start_log() {
    mkdir -p /var/log/qrid
    chmod 700 /var/log/qrid
    QRID_LOG="/var/log/qrid/$1-$(date +%Y%m%d-%H%M%S).log"
    ( umask 077 && : >"$QRID_LOG" )
    qrid_log "Condo ID Proxmox helper — $1 (branch ${REPO_BRANCH})"
}

ensure_host_tools() {
    local -a missing=()
    if ! command -v envsubst >/dev/null 2>&1; then
        missing+=(gettext-base)
    fi
    if ! command -v curl >/dev/null 2>&1; then
        missing+=(curl)
    fi
    if qrid_interactive && ! command -v whiptail >/dev/null 2>&1; then
        missing+=(whiptail)
    fi
    if (( ${#missing[@]} )); then
        msg_info "Installing ${missing[*]} on this host"
        run apt-get install -y "${missing[@]}"
        msg_ok "Installed ${missing[*]}"
    fi
}

build_mode() {
    local f
    if [[ $EUID -ne 0 ]]; then
        refuse "Run this as root on the Proxmox VE host."
    fi
    start_log create-qrid-stack
    ensure_host_tools
    for f in provision-db.sh provision-app.sh deploy.sh backup-db.sh backup-app.sh update-command.sh; do
        if ! fetch_sibling "$f"; then
            exit 1
        fi
    done
    scan_stacks
    if qrid_interactive; then
        # Named in var_instance and already on this host: re-run it straight
        # away; otherwise new-stack defaults, then the menu.
        if [[ -n "$INSTANCE" ]] && stack_exists "$INSTANCE"; then
            load_stack_settings "$INSTANCE"
            resolve_defaults
            header_info "Re-run Condo ID stack '${INSTANCE}' on $(hostname)"
            preflight
            if ! dialog_yesno "Re-run the stack?" "$(summary_text)\n\nRe-run stack '${INSTANCE}' with these settings?" 23; then
                cancelled
            fi
        else
            resolve_defaults
            choose_settings
        fi
    else
        if [[ -n "$INSTANCE" ]] && stack_exists "$INSTANCE"; then
            load_stack_settings "$INSTANCE"
        fi
        resolve_defaults
        header_info "$([[ "$RUN_MODE" == resume ]] && echo "Re-run" || echo "Build") Condo ID stack '${INSTANCE}' on $(hostname) — unattended"
        preflight
    fi
    build_stack
    msg_ok "Done — Condo ID stack '${INSTANCE}' is up"
    print_summary
}

# ============================================================================
# Updating — `update` (or the one-liner) run inside one of a stack's
# containers. Nothing here runs on a schedule: an update happens because
# someone typed it (architecture §7).
# ============================================================================

# app | db | none. /etc/qrid-role is written when a stack is built or
# re-run; a container from before that is recognised by what's installed.
container_role() {
    local role=""
    if [[ -r /etc/qrid-role ]]; then
        role="$(sed -n 's/^role=//p' /etc/qrid-role)"
    fi
    if [[ -z "$role" ]]; then
        if [[ -x /opt/qrid/deploy.sh && -d "${APP_DIR}/.git" ]]; then
            role=app
        elif [[ -x /root/backup-db.sh || -d /var/www/qrid-status ]]; then
            role=db
        fi
    fi
    echo "${role:-none}"
}

container_stack() {
    if [[ -r /etc/qrid-role ]]; then
        sed -n 's/^stack=//p' /etc/qrid-role
    fi
}

app_commit() {
    git -c safe.directory="$APP_DIR" -C "$APP_DIR" rev-parse --short HEAD 2>/dev/null || echo unknown
}

# community-scripts' update menu: quiet, verbose, or cancel. With no
# terminal (pct exec from the host, say) it goes ahead quietly.
choose_update_output() {
    local choice=1
    if ! qrid_interactive; then
        return 0
    fi
    dialog_menu choice "Update Condo ID" "$1" \
        1 "Yes — quiet (output goes to the log)" \
        2 "Yes — verbose (show everything)" \
        3 "No — cancel" || cancelled
    case "$choice" in
        1) QRID_VERBOSE=no ;;
        2) QRID_VERBOSE=yes ;;
        *) cancelled ;;
    esac
}

# Replaces this container's copies of the Condo ID helper scripts — the
# update command included — with the repo's current ones, so a fix to any
# of them reaches existing stacks on their next update, not only new ones.
refresh_container_scripts() {
    local role="$1" f
    local -a files=(update-command.sh)
    if [[ "$role" == app ]]; then
        files+=(deploy.sh backup-app.sh)
    else
        files+=(backup-db.sh)
    fi
    for f in "${files[@]}"; do
        if ! fetch_sibling "$f"; then
            exit 1
        fi
    done
    qrid_render_update_command "${WORK_DIR}/update-command.sh" "$REPO_BRANCH" "${WORK_DIR}/update-command.rendered.sh"
    install -m 0755 "${WORK_DIR}/update-command.rendered.sh" /usr/local/bin/update
    if [[ "$role" == app ]]; then
        install -m 0700 "${WORK_DIR}/deploy.sh" /opt/qrid/deploy.sh
        install -m 0700 "${WORK_DIR}/backup-app.sh" /root/backup-app.sh
    else
        install -m 0700 "${WORK_DIR}/backup-db.sh" /root/backup-db.sh
    fi
    # A container from before /etc/qrid-role existed gets one now, so it's
    # recognised by the file from here on.
    if [[ ! -e /etc/qrid-role ]]; then
        printf 'role=%s\nbranch=%s\n' "$role" "$REPO_BRANCH" > /etc/qrid-role
    fi
}

# --force-confold keeps the config files this system edited — the
# Caddyfile, PHP-FPM's pool config — instead of stopping mid-upgrade to ask
# whether to replace them. A new package config lands beside it as
# *.dpkg-dist instead.
apt_upgrade() {
    run env DEBIAN_FRONTEND=noninteractive apt-get update -y
    run env DEBIAN_FRONTEND=noninteractive apt-get -y \
        -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold upgrade
}

update_app() {
    local stack before after
    stack="$(container_stack)"
    before="$(app_commit)"
    header_info "Update the Condo ID app${stack:+ — stack ${stack}}"
    choose_update_output "Update the Condo ID app in this container?\n\nIt pulls the latest code on its branch (now at ${before}), builds, migrates and restarts PHP, then installs this container's OS package updates."

    msg_info "Refreshing this container's Condo ID scripts from ${REPO_BRANCH}"
    refresh_container_scripts app
    msg_ok "Scripts refreshed — deploy.sh, backup-app.sh and the update command"

    msg_info "Updating the app from ${before} — pull, build, migrate (a few minutes)"
    run /opt/qrid/deploy.sh
    after="$(app_commit)"
    if [[ "$after" == "$before" ]]; then
        msg_ok "App already at the latest commit, ${after} — rebuilt and re-migrated anyway"
    else
        msg_ok "App updated ${before} → ${after}"
    fi

    msg_info "Installing this container's OS package updates"
    apt_upgrade
    msg_ok "OS packages up to date"
}

update_db() {
    local stack
    stack="$(container_stack)"
    header_info "Update the Condo ID database container${stack:+ — stack ${stack}}"
    choose_update_output "Update this Condo ID database container?\n\nIt installs the container's OS package updates — PostgreSQL's minor releases and security fixes included — and refreshes the backup script. The data isn't touched; database migrations run from the app container's update."

    msg_info "Refreshing this container's Condo ID scripts from ${REPO_BRANCH}"
    refresh_container_scripts db
    msg_ok "Scripts refreshed — backup-db.sh and the update command"

    msg_info "Installing OS package updates, PostgreSQL's included"
    apt_upgrade
    if ! systemctl is-active --quiet postgresql; then
        refuse "PostgreSQL isn't running after the upgrade. Check it with: systemctl status postgresql"
    fi
    msg_ok "OS packages up to date — PostgreSQL $(psql -V | awk '{print $3}') is running"
}

update_mode() {
    local role="$1"
    if [[ $EUID -ne 0 ]]; then
        refuse "Run update as root inside the container."
    fi
    start_log update
    case "$role" in
        app) update_app ;;
        db) update_db ;;
    esac
    printf '\n  Full log: %s\n\n' "$QRID_LOG" >&2
}

# ============================================================================
# Where are we?
# ============================================================================

main() {
    local role
    qrid_catch_errors
    QRID_EXIT_HOOK=cleanup_work_dir
    if command -v pveversion >/dev/null 2>&1; then
        build_mode
        return 0
    fi
    role="$(container_role)"
    case "$role" in
        app|db)
            update_mode "$role"
            ;;
        *)
            refuse "Run this on a Proxmox VE host (as root) to build or re-run a Condo ID stack, or type update inside one of a stack's containers. This machine is neither."
            ;;
    esac
}

main "$@"
