#!/usr/bin/env bash
# shellcheck disable=SC2154  # QRID_DEFAULT_BRANCH is a placeholder, filled in at install time
#
# /usr/local/bin/update — the `update` command in every Condo ID container.
# Installed by create-qrid-stack.sh, which fills in the branch placeholder
# below with the branch the stack was built from (main, normally).
#
# Type `update` in either container of a stack:
#
#   app container       pulls the latest code on its branch, builds,
#                       migrates and restarts PHP (deploy.sh), then installs
#                       the container's OS package updates
#   database container  installs its OS package updates — PostgreSQL's
#                       minor releases and security fixes included
#
# It downloads the current create-qrid-stack.sh and runs it here; that
# script works out which container it's in and does the rest, so a fix to
# the update logic reaches every container the next time anyone updates.
# `REPO_BRANCH=<branch> update` runs another branch's version instead.
set -euo pipefail

branch="${REPO_BRANCH:-${QRID_DEFAULT_BRANCH}}"
url="https://raw.githubusercontent.com/alrlchoa/qr-id-generator/${branch}/deploy/proxmox/create-qrid-stack.sh"

# A private directory, not a bare file in /tmp: the updater looks for its
# helper files next to itself, and nothing but this run can write here.
dir="$(mktemp -d)"
trap 'rm -rf "$dir"' EXIT
script="${dir}/create-qrid-stack.sh"

if ! curl -fsSL --retry 3 --retry-delay 5 --retry-all-errors "$url" -o "$script"; then
    echo "Couldn't download the updater from ${url}" >&2
    echo "Check this container's internet access, then run update again." >&2
    exit 1
fi

REPO_BRANCH="$branch" bash "$script" "$@"
