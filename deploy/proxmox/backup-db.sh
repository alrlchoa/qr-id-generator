#!/usr/bin/env bash
#
# Installed as a cron job (2am nightly) inside the DB LXC by
# create-qrid-stack.sh. Dumps to /mnt/backup, which is a bind mount to a
# directory ON THE PROXMOX HOST — "stored off the LXC itself" per the
# Phase 2 requirement. Reads DB_NAME/DB_USER from the crontab entry's
# environment (set at install time); falls back to the schema defaults.
set -euo pipefail

DB_NAME="${DB_NAME:-qr_id_generator}"
DB_USER="${DB_USER:-qrid}"
BACKUP_DIR=/mnt/backup
KEEP_DAYS=14

mkdir -p "$BACKUP_DIR"

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
DUMP_FILE="${BACKUP_DIR}/${DB_NAME}-${TIMESTAMP}.dump"

sudo -u postgres pg_dump -Fc -d "$DB_NAME" -f "$DUMP_FILE"

find "$BACKUP_DIR" -name "${DB_NAME}-*.dump" -mtime "+${KEEP_DAYS}" -delete

echo "Backed up ${DB_NAME} to ${DUMP_FILE}"
