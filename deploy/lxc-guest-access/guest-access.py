#!/usr/bin/python3
"""OpenSSH forced command for Linux account operations in local Proxmox containers."""

import base64
import binascii
import hashlib
import json
import os
import re
import selectors
import subprocess
import sys
import time
from pathlib import Path

PCT = "/usr/sbin/pct"
LOCAL_LXC = Path("/etc/pve/local/lxc")
RANGES = Path("/etc/serverpanel-lxc-guest-access/ranges")
PROTOCOL = re.compile(r"serverpanel-lxc-v1 ([A-Za-z0-9_-]{1,2048})")
EXISTING_NAME = re.compile(r"[a-zA-Z0-9_][a-zA-Z0-9_.@-]{0,62}\$?")
NEW_NAME = re.compile(r"[a-z][a-z0-9]{0,31}")
ID = re.compile(r"[0-9a-f]{32}")
CRYPT = re.compile(r"\$6\$[./a-zA-Z0-9]{1,16}\$[./a-zA-Z0-9]{86}")
MAX_OUTPUT = 65536

GUEST = r"""
set -e
PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PATH LC_ALL=C
test "$(uname -s)" = Linux
guest="$1"
for file in /etc/machine-id /var/lib/dbus/machine-id; do
    if test -r "$file"; then
        candidate=$(cat "$file")
        if printf '%s\n' "$candidate" | grep -Eq '^[0-9a-f]{32}$'; then
            guest="$candidate"
            break
        fi
    fi
done
min=$(awk '$1=="UID_MIN" && $2 ~ /^[0-9]+$/ {print $2; exit}' /etc/login.defs 2>/dev/null || true)
max=$(awk '$1=="UID_MAX" && $2 ~ /^[0-9]+$/ {print $2; exit}' /etc/login.defs 2>/dev/null || true)
test -n "$min" || min=1000
test -n "$max" || max=60000

accounts() {
    awk -F: -v min="$min" -v max="$max" '
        ($1 == "root" && $3 == 0) || ($3 >= min && $3 <= max && $3 != 65534) { print $3 ":" $1 }
    ' /etc/passwd
}

case "$2" in
    inventory)
        printf 'guest:%s\n' "$guest"
        accounts
        if test -r /etc/shadow; then
            while IFS=: read -r name hash ignored; do
                case "$hash" in
                    '$6$'*)
                        digest=$(printf '%s' "$hash" | sha256sum)
                        printf 'digest:%s:%s\n' "$name" "${digest%% *}"
                        ;;
                esac
            done < /etc/shadow
        fi
        if command -v sshd >/dev/null; then
            sshd -T 2>/dev/null | awk '$1 == "port" {print "port:" $2}'
        fi
        ;;
    reset)
        test "$guest" = "$3"
        accounts | grep -Fqx -- "$5:$4"
        chpasswd -e
        printf 'ok\n'
        ;;
    add)
        test "$guest" = "$3"
        command -v chpasswd >/dev/null
        if command -v useradd >/dev/null; then
            useradd --create-home --shell /bin/sh -- "$4"
        else
            adduser -D -s /bin/sh "$4"
        fi
        chpasswd -e
        printf 'ok\n'
        ;;
    *) exit 2 ;;
esac
"""


def parse_ranges(contents):
    ranges = []
    for line in contents.splitlines():
        item = line.strip()
        if not item or item.startswith("#"):
            continue
        match = re.fullmatch(r"([0-9]{1,9})(?:-([0-9]{1,9}))?", item)
        if not match:
            raise ValueError("Invalid VMID range")
        first, last = int(match[1]), int(match[2] or match[1])
        if not 100 <= first <= last <= 999999999:
            raise ValueError("Invalid VMID range")
        ranges.append((first, last))
    if not ranges:
        raise ValueError("At least one VMID range is required")
    return ranges


def parse_request(original_command):
    match = PROTOCOL.fullmatch(original_command)
    if not match:
        raise ValueError("Invalid request")
    encoded = match[1]
    data = json.loads(base64.b64decode(encoded + "=" * (-len(encoded) % 4), altchars=b"-_", validate=True))
    if not isinstance(data, dict):
        raise ValueError("Invalid request")
    operation, vmid = data.get("operation"), data.get("vmid")
    if type(vmid) is not int or not 100 <= vmid <= 999999999:
        raise ValueError("Invalid VMID")
    keys = {"vmid", "operation"}
    if operation == "inventory" and data.keys() == keys:
        return data
    if operation not in ("add", "reset"):
        raise ValueError("Unsupported operation")
    keys.update(("guest_id", "username", "hash"))
    if operation == "reset":
        keys.add("uid")
        uid = data.get("uid")
        if type(uid) is not int or not 0 <= uid < 4294967295 or (uid == 0) != (data.get("username") == "root"):
            raise ValueError("Invalid account UID")
    pattern = EXISTING_NAME if operation == "reset" else NEW_NAME
    if data.keys() != keys or any(not isinstance(data[key], str) for key in ("guest_id", "username", "hash")):
        raise ValueError("Invalid account data")
    if not ID.fullmatch(data["guest_id"]) or not pattern.fullmatch(data["username"]) or not CRYPT.fullmatch(data["hash"]):
        raise ValueError("Invalid account data")
    if operation == "add" and data["username"] == "root":
        raise ValueError("Invalid account name")
    return data


def run_pct(command, password_data):
    deadline = time.monotonic() + 7
    output = bytearray()
    with subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL) as proc:
        try:
            proc.stdin.write(password_data)
            proc.stdin.close()
            with selectors.DefaultSelector() as selector:
                selector.register(proc.stdout, selectors.EVENT_READ)
                while True:
                    if not selector.select(max(0, deadline - time.monotonic())):
                        raise TimeoutError()
                    chunk = os.read(proc.stdout.fileno(), 4096)
                    if not chunk:
                        break
                    output.extend(chunk)
                    if len(output) > MAX_OUTPUT or time.monotonic() >= deadline:
                        raise ValueError("Invalid guest response")
            if proc.wait(timeout=max(0, deadline - time.monotonic())) != 0:
                raise ValueError("Guest operation failed")
        finally:
            if proc.poll() is None:
                proc.kill()
    return bytes(output)


def handle(data):
    vmid = data["vmid"]
    if not any(first <= vmid <= last for first, last in parse_ranges(RANGES.read_text())):
        raise ValueError("VMID is outside the connector policy")
    if not (LOCAL_LXC / f"{vmid}.conf").is_file():
        raise ValueError("Container is not local to this host")

    operation = data["operation"]
    fallback_id = hashlib.sha256(f"serverpanel-lxc-v1:{vmid}".encode()).hexdigest()[:32]
    command = [PCT, "exec", str(vmid), "--", "/bin/sh", "-c", GUEST, "serverpanel-lxc", fallback_id, operation]
    password_data = b""
    if operation in ("add", "reset"):
        command.extend((data["guest_id"], data["username"], str(data.get("uid", ""))))
        password_data = f'{data["username"]}:{data["hash"]}\n'.encode("ascii")
    return run_pct(command, password_data)


def main():
    try:
        request = parse_request(os.environ.get("SSH_ORIGINAL_COMMAND", ""))
        result = handle(request)
    except (ValueError, OSError, subprocess.SubprocessError, binascii.Error):
        print("ServerPanel LXC request failed", file=sys.stderr)
        return 1
    sys.stdout.buffer.write(result)
    return 0


if __name__ == "__main__":
    sys.exit(main())
