#!/usr/bin/env bash
#
# Installed as a cron job (2:15am nightly) inside the App LXC by
# create-qrid-stack.sh. Archives storage/app/private (photos, template
# backgrounds) and .env to /mnt/backup, a bind mount to a directory ON
# THE PROXMOX HOST — "stored off the LXC itself" per the Phase 2
# requirement.
set -euo pipefail

APP_DIR=/opt/qrid/app
BACKUP_DIR=/mnt/backup
KEEP_DAYS=14

mkdir -p "$BACKUP_DIR"

TIMESTAMP="$(date +%Y%m%d-%H%M%S)"
ARCHIVE="${BACKUP_DIR}/qrid-app-${TIMESTAMP}.tar.gz"

tar -czf "$ARCHIVE" \
    -C "$APP_DIR" storage/app/private \
    -C "$APP_DIR" .env

find "$BACKUP_DIR" -name 'qrid-app-*.tar.gz' -mtime "+${KEEP_DAYS}" -delete

echo "Backed up app private storage + .env to ${ARCHIVE}"
