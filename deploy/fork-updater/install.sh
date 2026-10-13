#!/usr/bin/env bash
# Install the host-only updater used by this fork's Docker releases.
set -euo pipefail

if [ "$#" -lt 2 ] || [ "$#" -gt 3 ]; then
    echo "Usage: sudo bash install.sh <fork docker-compose.yml> <panel env file> [compose project]" >&2
    exit 2
fi
if [ "$(id -u)" -ne 0 ]; then
    echo "Run this setup as root so it can access Docker volumes and install a systemd service." >&2
    exit 1
fi

compose_file="$(realpath "$1")"
env_file="$(realpath "$2")"
project="${3:-featherpanel}"
updater_dir='/etc/featherpanel/fork-updater'
service_file='/etc/systemd/system/featherpanel-fork-updater.service'
if [ "$project" != 'featherpanel' ]; then
    echo "This updater requires the featherpanel Compose project" >&2
    exit 1
fi
for tool in docker python3 openssl systemctl realpath; do
    command -v "$tool" >/dev/null || { echo "Missing tool: $tool" >&2; exit 1; }
done
if systemctl is-active --quiet featherpanel-docker-updater.service; then
    echo "The upstream Docker updater is active. Stop and review it before installing this fork updater." >&2
    exit 1
fi
for image in backend frontendv2 mcp async-runner; do
    if ! grep -Fq "ghcr.io/juli22292/featherpanel-${image}:" "$compose_file"; then
        echo "Compose does not use the fork's ${image} image" >&2
        exit 1
    fi
done

gateway="$(docker network inspect bridge --format '{{(index .IPAM.Config 0).Gateway}}')"
if [ -z "$gateway" ]; then
    echo "Could not find Docker's host-gateway address" >&2
    exit 1
fi

token="$(awk -F= '/^DOCKER_UPDATER_TOKEN=/{print $2; exit}' "$env_file" | tr -d '"' | tr -d "'")"
if [ -z "$token" ]; then
    token="$(openssl rand -hex 32)"
    printf '\nDOCKER_UPDATER_TOKEN=%s\n' "$token" >>"$env_file"
fi

install -d -m 700 "$updater_dir"
install -m 700 "$(dirname "$(realpath "$0")")/updater.py" "$updater_dir/updater.py"
printf '%s\n' "$token" >"$updater_dir/token"
chmod 600 "$updater_dir/token"

python3 - "$updater_dir/config.json" "$compose_file" "$env_file" "$project" "$gateway" "$updater_dir/token" <<'PY'
import json
import os
import sys
config_path, compose_file, env_file, project, gateway, token_file = sys.argv[1:]
config = {
    "repository": "juli22292/FeatherPanel",
    "compose_file": compose_file,
    "env_file": env_file,
    "project": project,
    "bind_host": gateway,
    "port": 9418,
    "token_file": token_file,
    "backup_root": os.path.join(os.path.dirname(env_file), "fork-update-backups"),
}
with open(config_path, "w", encoding="utf-8") as output:
    json.dump(config, output)
    output.write("\n")
os.chmod(config_path, 0o600)
PY

cat >"$service_file" <<'SERVICE'
[Unit]
Description=FeatherPanel fork updater on Docker bridge
After=docker.service
Requires=docker.service

[Service]
Type=simple
ExecStart=/usr/bin/python3 /etc/featherpanel/fork-updater/updater.py --config /etc/featherpanel/fork-updater/config.json
Restart=on-failure
RestartSec=3
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
SERVICE

systemctl daemon-reload
systemctl enable --now featherpanel-fork-updater.service
systemctl restart featherpanel-fork-updater.service
echo "Fork updater installed on the Docker bridge at ${gateway}:9418."
echo "Recreate the panel backend with its Compose file to load DOCKER_UPDATER_TOKEN."
