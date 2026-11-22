#!/usr/bin/env bash
set -euo pipefail
umask 077
base=$(cd -- "$(dirname -- "$0")" && pwd)
target=/usr/local/libexec/serverpanel-lxc-guest-access
policy=/etc/serverpanel-lxc-guest-access
keys=/root/.ssh/authorized_keys

if [ "$(id -u)" != 0 ] || [ "$#" != 2 ]; then
    printf '%s\n' 'Run as root: bash install.sh <vmid-ranges-file> <dedicated-ed25519-public-key>' >&2
    exit 2
fi
test -d /etc/pve/local/lxc
test -x /usr/sbin/pct
command -v ssh-keygen >/dev/null
ssh-keygen -lf "$2" >/dev/null

read -r key_type key_blob _ < "$2"
test "$key_type" = ssh-ed25519
[[ "$key_blob" =~ ^[A-Za-z0-9+/]+={0,2}$ ]]
entry="restrict,command=\"$target\" $key_type $key_blob serverpanel-lxc"
if [ -f "$keys" ]; then
    existing=$(awk -v blob="$key_blob" '{for (i=1;i<=NF;i++) if ($i==blob) print $0}' "$keys")
    if [ -n "$existing" ] && [ "$existing" != "$entry" ]; then
        printf '%s\n' 'The key already has a different authorization. Generate a dedicated key.' >&2
        exit 1
    fi
fi

/usr/bin/python3 - "$base/guest-access.py" "$1" <<'PY'
import runpy
import sys
from pathlib import Path

module = runpy.run_path(sys.argv[1])
module["parse_ranges"](Path(sys.argv[2]).read_text())
PY

mkdir -p /usr/local/libexec
install -d -m 0700 "$policy"
if [ ! -d /root/.ssh ]; then
    install -d -m 0700 /root/.ssh
fi
install -m 0600 "$1" "$policy/ranges"
# This also works when the release files came from a Windows checkout.
tmp=$(mktemp "$policy/guest-access.XXXXXX")
trap 'rm -f -- "$tmp"' EXIT
sed 's/\r$//' "$base/guest-access.py" > "$tmp"
install -m 0755 "$tmp" "$target"
if [ ! -f "$keys" ] || ! grep -Fxq -- "$entry" "$keys"; then
    printf '\n%s\n' "$entry" >> "$keys"
fi
printf '%s\n' 'Installed the restricted ServerPanel LXC key and connector.'
