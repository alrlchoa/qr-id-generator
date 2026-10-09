#!/usr/bin/env bash
# shellcheck source-path=SCRIPTDIR
#
# Exercises qrid.func's apt_upgrade — the OS-package step of `update` — with
# a stand-in apt-get, so no network and no container are needed. The case
# it exists for: one third-party repository down (Caddy's, on Cloudsmith,
# answered 402 Payment Required), so `apt-get update` exits 100 while every
# other repository refreshed. CI runs it next to ShellCheck.
#
#   bash deploy/proxmox/tests/test-apt-upgrade.sh
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../qrid.func
source "${HERE}/../qrid.func"

pass=0
fail=0

expect() {
    local description="$1"
    shift
    if "$@"; then
        pass=$(( pass + 1 ))
    else
        fail=$(( fail + 1 ))
        echo "FAIL — ${description}"
    fi
}

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# A stand-in apt-get: records what it was asked, and exits with the status
# the scenario sets for `update` and for `upgrade`.
mkdir -p "${work}/bin"
cat > "${work}/bin/apt-get" <<'STUB'
#!/usr/bin/env bash
echo "$*" >> "${STUB_CALLS}"
case " $* " in
    *" update "*) exit "${STUB_UPDATE_EXIT:-0}" ;;
    *" upgrade "*) exit "${STUB_UPGRADE_EXIT:-0}" ;;
esac
exit 0
STUB
chmod +x "${work}/bin/apt-get"
export PATH="${work}/bin:${PATH}"
export STUB_CALLS="${work}/calls"

QRID_LOG="${work}/log"
QRID_VERBOSE=no

scenario() {
    : > "$STUB_CALLS"
    : > "$QRID_LOG"
    STUB_UPDATE_EXIT="$1" STUB_UPGRADE_EXIT="$2" apt_upgrade 2>/dev/null
}

called() { grep -q -- "$1" "$STUB_CALLS"; }
not_called() { ! grep -q -- "$1" "$STUB_CALLS"; }

# --- everything works
scenario 0 0
expect "a clean update and upgrade succeed" test $? -eq 0
expect "a clean run isn't marked partial" test "$QRID_APT_PARTIAL" = no
expect "a clean run updates the lists" called "update"
expect "a clean run upgrades" called "upgrade"

# --- one repository down: update exits 100, upgrade still works
scenario 100 0
rc=$?
expect "a failed repository refresh doesn't fail the update" test "$rc" -eq 0
expect "it is marked partial, so no caller claims 'up to date'" test "$QRID_APT_PARTIAL" = yes
expect "it still upgrades from the repositories that refreshed" called "upgrade"
expect "it notes the warning in the log" grep -q "WARN" "$QRID_LOG"

# --- the down repository's packages are held back, only when it was down
scenario 100 0
expect "after a failed refresh the upgrade holds back what it can't download (-m)" called "-m"
scenario 0 0
expect "after a clean refresh the upgrade does not pass -m" not_called " -m "

# --- the upgrade itself failing is still a failure
scenario 0 100
expect "a failing upgrade still fails" test $? -ne 0

scenario 100 100
expect "an unusable package list stops at the upgrade" test $? -ne 0

# --- the next run starts clean
scenario 100 0
scenario 0 0
expect "the partial flag resets on the next run" test "$QRID_APT_PARTIAL" = no

echo "${pass} passed, ${fail} failed"
(( fail == 0 ))
