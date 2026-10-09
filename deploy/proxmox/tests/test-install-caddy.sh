#!/usr/bin/env bash
# shellcheck source-path=SCRIPTDIR
# shellcheck disable=SC2030,SC2031  # each scenario runs in its own subshell, on purpose
#
# Exercises install-caddy.sh — what moves a container's Caddy off the
# Cloudsmith apt repository and onto the official release package on GitHub
# — with stand-ins for curl, dpkg and dpkg-query, so no network, no root and
# no container are needed. CI runs it next to ShellCheck.
#
#   bash deploy/proxmox/tests/test-install-caddy.sh
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="${HERE}/../install-caddy.sh"

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
mkdir -p "${work}/bin" "${work}/root/etc/apt/sources.list.d" "${work}/root/usr/share/keyrings"

# --- stand-ins -------------------------------------------------------------

# curl: answers the "latest" redirect probe, and writes a fake .deb or the
# checksum list. STUB_NET=down makes every call fail like an unreachable host.
cat > "${work}/bin/curl" <<'STUB'
#!/usr/bin/env bash
[[ "${STUB_NET:-up}" == down ]] && exit 6
out="" url="" probe=no
while (( $# )); do
    case "$1" in
        -o) out="$2"; shift ;;
        -w) probe=yes; shift ;;
    esac
    url="$1"
    shift
done
if [[ "$probe" == yes ]]; then
    printf '%s' "${STUB_LATEST_URL}"
    exit 0
fi
case "$url" in
    *_checksums.txt)
        sum="$(printf 'fake-deb-bytes' | sha512sum | awk '{print $1}')"
        [[ "${STUB_BAD_SUM:-no}" == yes ]] && sum="$(printf '0%.0s' {1..128})"
        name="$(basename "${url%_checksums.txt}")_linux_${STUB_ARCH_NAME:-amd64}.deb"
        [[ "${STUB_NO_ENTRY:-no}" == yes ]] && name="caddy_0.0.0_linux_other.deb"
        printf '%s  %s\n' "$sum" "$name" > "$out"
        ;;
    *.deb) printf 'fake-deb-bytes' > "$out"; echo "$url" >> "${STUB_CALLS}.downloads" ;;
esac
STUB

# dpkg: the architecture, the version comparison, and an install recorder.
cat > "${work}/bin/dpkg" <<'STUB'
#!/usr/bin/env bash
case "$1" in
    --print-architecture) echo "${STUB_ARCH:-amd64}" ;;
    --compare-versions)
        [[ "$3" == gt ]] || exit 2
        [[ "$2" != "$4" && "$(printf '%s\n%s\n' "$2" "$4" | sort -V | tail -n 1)" == "$2" ]]
        ;;
    *) echo "$*" >> "${STUB_CALLS}.installs" ;;
esac
STUB

# dpkg-query: the installed version, or "not installed".
cat > "${work}/bin/dpkg-query" <<'STUB'
#!/usr/bin/env bash
[[ -n "${STUB_INSTALLED:-}" ]] || exit 1
printf 'ii %s' "${STUB_INSTALLED}"
STUB
chmod +x "${work}/bin/"*
export PATH="${work}/bin:${PATH}"
export STUB_CALLS="${work}/calls"
export QRID_ROOT="${work}/root"
export QRID_CADDY_RELEASES="https://example.test/caddy/releases"

legacy_list="${work}/root/etc/apt/sources.list.d/caddy-stable.list"
legacy_key="${work}/root/usr/share/keyrings/caddy-stable-archive-keyring.gpg"

# run_scenario: reset the world, apply "VAR=value" arguments, run the
# script, and keep its exit status, stdout and stderr.
run_scenario() {
    : > "${STUB_CALLS}.installs"
    : > "${STUB_CALLS}.downloads"
    touch "$legacy_list" "$legacy_key"
    (
        export STUB_LATEST_URL="https://example.test/caddy/releases/tag/v2.11.7"
        for assignment in "$@"; do export "${assignment?}"; done
        bash "$SCRIPT"
    ) > "${work}/out" 2> "${work}/err"
    rc=$?
}

installed_once() { [[ "$(wc -l < "${STUB_CALLS}.installs")" -eq 1 ]]; }
nothing_installed() { [[ ! -s "${STUB_CALLS}.installs" ]]; }
legacy_gone() { [[ ! -e "$legacy_list" && ! -e "$legacy_key" ]]; }
output_has() { grep -q -- "$1" "${work}/out" "${work}/err"; }

# --- a fresh install -------------------------------------------------------
run_scenario
expect "a fresh install succeeds" test "$rc" -eq 0
expect "it installs exactly one package" installed_once
expect "it installs the amd64 .deb for the latest tag" grep -q "caddy_2.11.7_linux_amd64.deb" "${STUB_CALLS}.installs"
expect "it keeps the Caddyfile the system installed (--force-confold)" grep -q -- "--force-confold" "${STUB_CALLS}.installs"
expect "it downloaded from the release for that tag" grep -q "/download/v2.11.7/" "${STUB_CALLS}.downloads"
expect "it removes the Cloudsmith apt source and key" legacy_gone

# --- already current -------------------------------------------------------
run_scenario STUB_INSTALLED=2.11.7
expect "an up-to-date Caddy is left alone" test "$rc" -eq 0
expect "nothing is installed when current" nothing_installed
expect "the Cloudsmith source is still removed when current" legacy_gone
expect "it says it is already current" output_has "already current"

# --- an older Caddy is upgraded --------------------------------------------
run_scenario STUB_INSTALLED=2.11.6
expect "an older Caddy is upgraded" test "$rc" -eq 0
expect "the upgrade installs the package" installed_once
expect "it reports the version it replaced" output_has "was 2.11.6"

# --- a NEWER Caddy is never downgraded -------------------------------------
run_scenario STUB_INSTALLED=2.12.0
expect "a newer installed Caddy is not downgraded" nothing_installed
expect "a newer installed Caddy is not an error" test "$rc" -eq 0

# --- a package that doesn't match its checksum is never installed -----------
run_scenario STUB_BAD_SUM=yes
expect "a checksum mismatch fails" test "$rc" -eq 1
expect "a checksum mismatch installs nothing" nothing_installed
expect "it says why" output_has "checksum"

run_scenario STUB_BAD_SUM=yes STUB_INSTALLED=2.11.6
expect "a checksum mismatch fails even when Caddy is already installed" test "$rc" -eq 1
expect "and still installs nothing" nothing_installed

run_scenario STUB_NO_ENTRY=yes
expect "a package missing from the checksum list is refused" test "$rc" -eq 1
expect "and installs nothing" nothing_installed

# --- GitHub unreachable ----------------------------------------------------
run_scenario STUB_NET=down STUB_INSTALLED=2.11.6
expect "unreachable GitHub with Caddy installed exits 3 (kept)" test "$rc" -eq 3
expect "nothing is installed when offline" nothing_installed
expect "the Cloudsmith source is removed even when offline" legacy_gone
expect "it says it kept the installed version" output_has "keeping Caddy 2.11.6"

run_scenario STUB_NET=down
expect "unreachable GitHub with no Caddy is a failure" test "$rc" -eq 1
expect "it explains what to check" output_has "isn't installed yet"

# --- odd inputs ------------------------------------------------------------
run_scenario STUB_LATEST_URL="https://example.test/somewhere/else"
expect "an unexpected 'latest' answer is refused" test "$rc" -eq 1
expect "and installs nothing" nothing_installed

run_scenario STUB_LATEST_URL="https://example.test/caddy/releases/tag/v2.12.0-beta.1"
expect "a pre-release tag is refused" test "$rc" -eq 1

run_scenario STUB_ARCH=i386
expect "an unsupported architecture is refused" test "$rc" -eq 1
expect "it names the architecture" output_has "i386"

run_scenario STUB_ARCH=arm64 STUB_ARCH_NAME=arm64
expect "arm64 gets the arm64 package" grep -q "caddy_2.11.7_linux_arm64.deb" "${STUB_CALLS}.installs"
run_scenario STUB_ARCH=armhf STUB_ARCH_NAME=armv7
expect "armhf maps to the armv7 package" grep -q "caddy_2.11.7_linux_armv7.deb" "${STUB_CALLS}.installs"

# --- running again changes nothing -----------------------------------------
rm -f "$legacy_list" "$legacy_key"
( export STUB_LATEST_URL="https://example.test/caddy/releases/tag/v2.11.7" STUB_INSTALLED=2.11.7; bash "$SCRIPT" ) > "${work}/out" 2> "${work}/err"
expect "a second run with nothing to do succeeds" test $? -eq 0
expect "and does not mention removing a source that is already gone" bash -c "! grep -q 'removed the Cloudsmith' '${work}/out'"

echo "${pass} passed, ${fail} failed"
(( fail == 0 ))
