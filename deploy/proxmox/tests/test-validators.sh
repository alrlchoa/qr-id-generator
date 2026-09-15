#!/usr/bin/env bash
# shellcheck source-path=SCRIPTDIR
#
# Exercises qrid.func's pure pieces — the validators and the quiet/verbose
# output switch — on any machine with bash; no Proxmox needed. CI runs it
# next to ShellCheck. The Proxmox-dependent checks (container IDs, storage,
# bridges) can only be proven on a real host.
#
#   bash deploy/proxmox/tests/test-validators.sh
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../qrid.func
source "${HERE}/../qrid.func"

pass=0
fail=0

valid() {
    if "$@"; then
        pass=$(( pass + 1 ))
    else
        fail=$(( fail + 1 ))
        echo "FAIL — expected valid: $* (${QRID_ERR})"
    fi
}

invalid() {
    QRID_ERR=""
    if "$@"; then
        fail=$(( fail + 1 ))
        echo "FAIL — expected invalid: $*"
    elif [[ -z "$QRID_ERR" ]]; then
        fail=$(( fail + 1 ))
        echo "FAIL — rejected without a reason: $*"
    else
        pass=$(( pass + 1 ))
    fi
}

# Whole numbers in a range — container IDs, cores, RAM, disk.
valid   v_int_range 100 100 999999999 "Container ID"
valid   v_int_range 999999999 100 999999999 "Container ID"
valid   v_int_range 010 1 10 "Cores"          # leading zero is still ten
invalid v_int_range 99 100 999999999 "Container ID"
invalid v_int_range 1000000000 100 999999999 "Container ID"
invalid v_int_range "" 1 10 "Cores"
invalid v_int_range abc 1 10 "Cores"
invalid v_int_range -5 1 10 "Cores"
invalid v_int_range 2.5 1 10 "Cores"
invalid v_int_range 99999999999999999999 1 10 "Cores"

# Hostnames.
valid   v_hostname qrid-db
valid   v_hostname a
valid   v_hostname "$(printf 'a%.0s' {1..63})"
invalid v_hostname -qrid
invalid v_hostname qrid-
invalid v_hostname qrid_db
invalid v_hostname "qrid db"
invalid v_hostname ""
invalid v_hostname "$(printf 'a%.0s' {1..64})"

# SQL identifiers — they end up unquoted in SQL and a crontab line.
valid   v_identifier qr_id_generator "Database name"
valid   v_identifier _private
invalid v_identifier 1abc
invalid v_identifier bad-name
invalid v_identifier "x; DROP TABLE users"
invalid v_identifier "it's"
invalid v_identifier ""

# Linux usernames for the optional sudo account.
valid   v_linux_username alice
valid   v_linux_username _svc-1
invalid v_linux_username root
invalid v_linux_username Alice
invalid v_linux_username 1alice
invalid v_linux_username "$(printf 'a%.0s' {1..33})"

# Absolute paths for the backup directory.
valid   v_abs_path /var/lib/vz/qrid-backups
invalid v_abs_path relative/path
invalid v_abs_path /
invalid v_abs_path "/has space"
invalid v_abs_path /a/../b
invalid v_abs_path ""

# Stack names — they become part of hostnames, a Proxmox tag and a path.
valid   v_instance_name main
valid   v_instance_name tower-a
valid   v_instance_name s2
valid   v_instance_name "$(printf 'a%.0s' {1..20})"
invalid v_instance_name Main
invalid v_instance_name 2tower
invalid v_instance_name tower-
invalid v_instance_name tower_a
invalid v_instance_name "tower a"
invalid v_instance_name ../etc
invalid v_instance_name ""
invalid v_instance_name "$(printf 'a%.0s' {1..21})"

# Quiet mode sends a command's output to the log, not the terminal.
QRID_LOG="$(mktemp)"
QRID_VERBOSE=no
terminal_output="$(run printf '%s\n' "quiet-mode-marker" 2>&1)"
if grep -q quiet-mode-marker "$QRID_LOG" && [[ -z "$terminal_output" ]]; then
    pass=$(( pass + 1 ))
else
    fail=$(( fail + 1 ))
    echo "FAIL — quiet mode should log output and keep the terminal clean"
fi

# Verbose mode streams it too, and still logs it.
QRID_VERBOSE=yes
terminal_output="$(run printf '%s\n' "verbose-mode-marker" 2>&1)"
if grep -q verbose-mode-marker "$QRID_LOG" && [[ "$terminal_output" == *verbose-mode-marker* ]]; then
    pass=$(( pass + 1 ))
else
    fail=$(( fail + 1 ))
    echo "FAIL — verbose mode should both show and log output"
fi

# A failing command's status survives run() in both modes.
for mode in no yes; do
    QRID_VERBOSE="$mode"
    if run false 2>/dev/null; then
        fail=$(( fail + 1 ))
        echo "FAIL — run() swallowed a failure in verbose=${mode}"
    else
        pass=$(( pass + 1 ))
    fi
done
rm -f "$QRID_LOG"

echo "${pass} passed, ${fail} failed"
(( fail == 0 ))
