#!/usr/bin/env bash
#
# /usr/local/sbin/qrid-install-caddy — installed in both containers by
# create-qrid-stack.sh. Run as root; safe to run again at any time:
#
#   qrid-install-caddy
#
# Installs, or upgrades to, the newest Caddy from its official release
# package on GitHub — instead of the apt repository Caddy publishes on
# Cloudsmith, which began answering "402 Payment Required" on 2026-10-09
# and, because apt-get update exits with an error when ANY repository is
# unreadable, broke every `update`. GitHub is already something these
# containers depend on (the app is cloned from it), so this adds no new
# third party.
#
# What it does, in order:
#   1. Removes the Cloudsmith apt source and key this system's own scripts
#      once wrote, so apt stops asking a repository that's down. Nothing
#      else under /etc/apt is touched.
#   2. Finds the latest release tag from the redirect on GitHub's
#      releases/latest page — no API call, so no rate limit.
#   3. If that's newer than what's installed (or nothing is), downloads the
#      .deb and checks it against the release's own SHA-512 checksum file.
#      A package that doesn't match is never installed.
#   4. Installs it with dpkg. It's the same `caddy` package the repository
#      served — same user, same systemd unit, same /etc/caddy — so on an
#      existing box this is an in-place upgrade. --force-confold keeps the
#      Caddyfile this system installed rather than asking to replace it.
#
# Exit status: 0 done or already current; 1 failed; 3 couldn't reach GitHub
# but Caddy is already installed, so it was kept as it is.
set -euo pipefail

ROOT="${QRID_ROOT:-}"                                   # a path prefix, for tests only
RELEASES="${QRID_CADDY_RELEASES:-https://github.com/caddyserver/caddy/releases}"
LEGACY_LIST="${ROOT}/etc/apt/sources.list.d/caddy-stable.list"
LEGACY_KEYRING="${ROOT}/usr/share/keyrings/caddy-stable-archive-keyring.gpg"
EXIT_KEPT=3

say() { echo "qrid-install-caddy: $*"; }
die() { echo "qrid-install-caddy: $*" >&2; exit 1; }

# The installed version, or nothing. "ii " is dpkg's "installed and
# configured" — a package removed but not purged has a version, and isn't it.
installed_version() {
    local out
    # shellcheck disable=SC2016  # dpkg-query's own ${...} format, not bash's
    out="$(dpkg-query -W -f='${db:Status-Abbrev}${Version}' caddy 2>/dev/null || true)"
    if [[ "$out" == "ii "* ]]; then
        echo "${out#ii }"
    fi
}

current="$(installed_version)"

# Couldn't reach GitHub: fine if Caddy is already here, fatal if it isn't.
cannot_check() {
    if [[ -n "$current" ]]; then
        echo "qrid-install-caddy: $1 — keeping Caddy ${current}" >&2
        exit "$EXIT_KEPT"
    fi
    die "$1, and Caddy isn't installed yet. Check this container's internet access (github.com), then run qrid-install-caddy again."
}

# --- 1. Away from Cloudsmith
if [[ -e "$LEGACY_LIST" || -e "$LEGACY_KEYRING" ]]; then
    rm -f "$LEGACY_LIST" "$LEGACY_KEYRING"
    say "removed the Cloudsmith apt source — Caddy now comes from its GitHub releases"
fi

# --- 2. Which package, and which version
case "$(dpkg --print-architecture)" in
    amd64) arch=amd64 ;;
    arm64) arch=arm64 ;;
    armhf) arch=armv7 ;;
    ppc64el) arch=ppc64le ;;
    riscv64) arch=riscv64 ;;
    s390x) arch=s390x ;;
    *) die "There's no official Caddy package for this CPU architecture ($(dpkg --print-architecture))." ;;
esac

latest_url="$(curl -fsSLI --max-time 60 --retry 3 --retry-delay 5 --retry-all-errors \
    -o /dev/null -w '%{url_effective}' "${RELEASES}/latest")" \
    || cannot_check "Couldn't reach ${RELEASES}/latest"

tag="${latest_url##*/tag/}"
if [[ ! "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    die "Unexpected answer from ${RELEASES}/latest (${latest_url}) — expected a release tag like v2.11.7."
fi
version="${tag#v}"

# --- 3. Newer than what's installed?
if [[ -n "$current" ]] && ! dpkg --compare-versions "$version" gt "$current"; then
    say "Caddy ${current} is already current (latest release: ${tag})"
    exit 0
fi

# --- 4. Download, verify, install
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
deb="caddy_${version}_linux_${arch}.deb"

curl -fsSL --max-time 300 --retry 3 --retry-delay 5 --retry-all-errors \
    -o "${work}/${deb}" "${RELEASES}/download/${tag}/${deb}" \
    || cannot_check "Couldn't download ${deb}"
curl -fsSL --max-time 60 --retry 3 --retry-delay 5 --retry-all-errors \
    -o "${work}/checksums.txt" "${RELEASES}/download/${tag}/caddy_${version}_checksums.txt" \
    || cannot_check "Couldn't download the checksum list for ${tag}"

expected="$(awk -v f="$deb" '$2 == f { print $1 }' "${work}/checksums.txt")"
[[ -n "$expected" ]] || die "${deb} isn't in the release's checksum list — not installing it."

actual="$(sha512sum "${work}/${deb}" | awk '{ print $1 }')"
if [[ "$actual" != "$expected" ]]; then
    die "${deb} doesn't match its published SHA-512 checksum — not installing it."
fi

DEBIAN_FRONTEND=noninteractive dpkg --force-confdef --force-confold -i "${work}/${deb}"
say "installed Caddy ${tag}${current:+ (was ${current})}"
