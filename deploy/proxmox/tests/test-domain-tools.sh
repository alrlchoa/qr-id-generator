#!/usr/bin/env bash
# shellcheck source-path=SCRIPTDIR
#
# Exercises qrid-domain.func — what qrid-set-domain and qrid-selftest use to
# validate a hostname and spot a private address (Phase 18) — on any
# machine with bash. CI runs it next to ShellCheck.
#
#   bash deploy/proxmox/tests/test-domain-tools.sh
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../qrid-domain.func
source "${HERE}/../qrid-domain.func"

pass=0
fail=0

yes() {
    if "$@"; then
        pass=$(( pass + 1 ))
    else
        fail=$(( fail + 1 ))
        echo "FAIL — expected true: $*"
    fi
}

no() {
    if "$@"; then
        fail=$(( fail + 1 ))
        echo "FAIL — expected false: $*"
    else
        pass=$(( pass + 1 ))
    fi
}

same() {
    if [[ "$1" == "$2" ]]; then
        pass=$(( pass + 1 ))
    else
        fail=$(( fail + 1 ))
        echo "FAIL — expected '$2', got '$1'"
    fi
}

# --- hostnames
yes qrid_valid_hostname ids.example.com
yes qrid_valid_hostname mpcid.seisystem.win
yes qrid_valid_hostname a-b.c-d.example.co.uk
yes qrid_valid_hostname x1.example.io
no qrid_valid_hostname ""
no qrid_valid_hostname localhost
no qrid_valid_hostname example
no qrid_valid_hostname 192.168.100.177
no qrid_valid_hostname https://ids.example.com
no qrid_valid_hostname ids.example.com/login
no qrid_valid_hostname ids.example.com:443
no qrid_valid_hostname -ids.example.com
no qrid_valid_hostname ids-.example.com
no qrid_valid_hostname ids..example.com
no qrid_valid_hostname "ids example.com"
no qrid_valid_hostname "ids.example.com;reboot"
no qrid_valid_hostname IDS.EXAMPLE.COM
no qrid_valid_hostname "$(printf 'a%.0s' {1..64}).example.com"

same "$(qrid_normalize_hostname IDS.Example.COM.)" ids.example.com

# --- private addresses
yes qrid_is_private_host 192.168.100.177
yes qrid_is_private_host 10.0.0.5
yes qrid_is_private_host 172.16.0.1
yes qrid_is_private_host 172.31.255.255
yes qrid_is_private_host 127.0.0.1
yes qrid_is_private_host 169.254.1.1
yes qrid_is_private_host localhost
yes qrid_is_private_host ::1
yes qrid_is_private_host '[fd12:3456::1]'
yes qrid_is_private_host fe80::1
no qrid_is_private_host 172.32.0.1
no qrid_is_private_host 8.8.8.8
no qrid_is_private_host ids.example.com
no qrid_is_private_host fcbarcelona.com
no qrid_is_private_host fdroid.org

# --- the host part of a URL
same "$(qrid_url_host https://ids.example.com/login)" ids.example.com
same "$(qrid_url_host https://192.168.100.177/login)" 192.168.100.177
same "$(qrid_url_host https://192.168.100.177:8443/)" 192.168.100.177
same "$(qrid_url_host http://user@ids.example.com:80/x)" ids.example.com
same "$(qrid_url_host 'https://[fd12::1]:443/')" '[fd12::1]'
same "$(qrid_url_host https://ids.example.com)" ids.example.com
same "$(qrid_url_host "")" ""

echo "${pass} passed, ${fail} failed"
(( fail == 0 ))
