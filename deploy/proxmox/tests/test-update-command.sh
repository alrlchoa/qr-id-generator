#!/usr/bin/env bash
# shellcheck source-path=SCRIPTDIR
# shellcheck disable=SC2016  # this file checks for literal ${...} text on purpose
#
# Exercises the `update` command installed in every Condo ID container: how
# qrid_render_update_command fills in its branch, and what the rendered
# command does — with a stand-in curl, so no network and no container are
# needed. CI runs it next to ShellCheck.
#
#   bash deploy/proxmox/tests/test-update-command.sh
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

# not_in_file TEXT FILE / not_in_text PATTERN TEXT — the negative checks.
not_in_file() { ! grep -qF -- "$1" "$2"; }
not_in_text() { ! grep -q -- "$1" <<<"$2"; }

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# A stand-in curl on PATH: records the URL it was asked for, then writes a
# tiny "create-qrid-stack.sh" that reports how it was run and from where.
mkdir -p "${work}/bin"
cat >"${work}/bin/curl" <<'FAKE_CURL'
#!/usr/bin/env bash
out="" url=""
while (( $# )); do
    case "$1" in
        -o) out="$2"; shift 2 ;;
        --retry|--retry-delay) shift 2 ;;
        -*) shift ;;
        *) url="$1"; shift ;;
    esac
done
echo "$url" >>"$FAKE_CURL_LOG"
if [[ "${FAKE_CURL_FAIL:-}" == 1 ]]; then
    exit 22
fi
cat >"$out" <<'UPDATER'
#!/usr/bin/env bash
echo "REPO_BRANCH=${REPO_BRANCH}"
echo "DIR=$(cd "$(dirname "$0")" && pwd)"
echo "ARGS=$*"
UPDATER
FAKE_CURL
chmod +x "${work}/bin/curl"

template="${HERE}/../update-command.sh"
expected_url() {
    echo "https://raw.githubusercontent.com/alrlchoa/qr-id-generator/$1/deploy/proxmox/create-qrid-stack.sh"
}

# Rendering fills the placeholder and leaves a script bash accepts.
qrid_render_update_command "$template" main "${work}/update-main"
qrid_render_update_command "$template" Phase-15-proxmox-script-polish "${work}/update-branch"
qrid_render_update_command "$template" feature/nested-name "${work}/update-slash"
expect "rendering fills the branch in" grep -qF 'branch="${REPO_BRANCH:-main}"' "${work}/update-main"
expect "rendering leaves no placeholder behind" not_in_file '${QRID_DEFAULT_BRANCH}' "${work}/update-main"
expect "a branch name with a slash renders intact" grep -qF 'branch="${REPO_BRANCH:-feature/nested-name}"' "${work}/update-slash"
for f in update-main update-branch update-slash; do
    expect "rendered ${f} passes bash -n" bash -n "${work}/${f}"
done

run_update() {
    local rendered="$1"
    shift
    : >"${work}/curl.log"
    PATH="${work}/bin:${PATH}" FAKE_CURL_LOG="${work}/curl.log" bash "${work}/${rendered}" "$@" 2>&1
}

# The installed branch is what it downloads from, and what it hands on.
output="$(run_update update-main)"
expect "update fetches create-qrid-stack.sh from main" grep -qxF "$(expected_url main)" "${work}/curl.log"
expect "update runs the updater with REPO_BRANCH=main" grep -qx 'REPO_BRANCH=main' <<<"$output"

output="$(run_update update-branch)"
expect "a stack built from a branch updates from that branch" grep -qxF "$(expected_url Phase-15-proxmox-script-polish)" "${work}/curl.log"

# REPO_BRANCH=<branch> update overrides the installed branch.
output="$(REPO_BRANCH=some-fix run_update update-main)"
expect "REPO_BRANCH overrides the installed branch" grep -qxF "$(expected_url some-fix)" "${work}/curl.log"
expect "the override reaches the updater" grep -qx 'REPO_BRANCH=some-fix' <<<"$output"

# Arguments pass straight through.
output="$(run_update update-main --verbose extra)"
expect "arguments reach the updater" grep -qx 'ARGS=--verbose extra' <<<"$output"

# The updater runs from a private directory, removed afterwards — never
# from a shared one like /tmp, where its sibling-file lookup could pick up
# something planted there.
output="$(run_update update-main)"
updater_dir="$(sed -n 's/^DIR=//p' <<<"$output")"
expect "the updater runs from its own directory, not /tmp itself" test -n "$updater_dir" -a "$updater_dir" != "/tmp" -a "$updater_dir" != "/var/tmp"
expect "that directory is removed afterwards" test ! -e "$updater_dir"

# A failed download says so and exits non-zero — never runs a half file.
output="$(FAKE_CURL_FAIL=1 run_update update-main)"
status=$?
expect "a failed download exits non-zero" test "$status" -ne 0
expect "a failed download explains itself" grep -q "Couldn't download the updater" <<<"$output"
expect "a failed download runs nothing" not_in_text '^REPO_BRANCH=' "$output"

echo "${pass} passed, ${fail} failed"
(( fail == 0 ))
