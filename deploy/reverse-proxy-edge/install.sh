#!/usr/bin/env bash
# One-time setup for the administrator of the public NGINX host.
set -euo pipefail
test "$(id -u)" = 0 || { echo 'Run as root on the public NGINX host' >&2; exit 1; }
test "$#" = 1 || { echo 'Usage: bash install.sh <policy.json>' >&2; exit 2; }
base="$(dirname "$(realpath "$0")")"
for tool in nginx certbot dig python3 systemctl; do
    command -v "$tool" >/dev/null || { echo "Missing tool: $tool" >&2; exit 1; }
done
python3 - "$1" <<'PY'
import ipaddress, json, sys, grp
with open(sys.argv[1]) as file:
    policy = json.load(file)
assert policy['networks'] and all(ipaddress.ip_network(network).prefixlen > 0 for network in policy['networks'])
assert policy['ports'] and all(1 <= lo <= hi <= 65535 for lo, hi in policy['ports'])
grp.getgrnam(policy.get('socket_group', 'www-data'))
PY
nginx -t
if ! nginx -T 2>/dev/null | grep -F '/etc/nginx/conf.d/*.conf' >/dev/null; then
    echo 'The NGINX http block must include /etc/nginx/conf.d/*.conf' >&2
    exit 1
fi

install -d -m 0750 /etc/serverpanel-edge
install -d -m 0755 /var/lib/serverpanel-edge/challenges/.well-known/acme-challenge
install -d -m 0755 /run/serverpanel-edge
install -m 0600 "$1" /etc/serverpanel-edge/config.json
install -m 0755 "$base/edge.py" /etc/serverpanel-edge/edge.py
install -d -m 0755 /etc/letsencrypt/renewal-hooks/deploy
install -m 0755 "$base/reload-nginx" /etc/letsencrypt/renewal-hooks/deploy/serverpanel-edge
install -m 0644 "$base/serverpanel-edge.service" /etc/systemd/system/serverpanel-edge.service

systemctl daemon-reload
systemctl enable --now certbot.timer
systemctl enable --now serverpanel-edge
systemctl restart serverpanel-edge
systemctl is-active --quiet serverpanel-edge
echo 'Connector running. Mount /run/serverpanel-edge in the panel backend, then configure System → Reverse Proxy.'
