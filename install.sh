#!/usr/bin/env bash
# Local-only installer for the juli22292 FeatherPanel fork (AGPL-3.0-or-later).
set -euo pipefail

repository='juli22292/FeatherPanel'
install_dir="${FEATHERPANEL_INSTALL_DIR:-$HOME/FeatherPanel}"
secrets_file="${FEATHERPANEL_SECRETS_FILE:-$HOME/.config/featherpanel/installer.env}"

for tool in curl git openssl docker; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        printf 'Missing required tool: %s\n' "$tool" >&2
        exit 1
    fi
done
docker compose version >/dev/null
docker info >/dev/null

if [ -e "$install_dir" ]; then
    printf 'Installation directory already exists: %s\nNothing was overwritten.\n' "$install_dir" >&2
    exit 1
fi

if ! release_json="$(curl -fsSL -H 'Accept: application/vnd.github+json' \
    "https://api.github.com/repos/$repository/releases/latest")"; then
    printf 'No stable release could be retrieved from %s yet.\n' "$repository" >&2
    exit 1
fi
tag="$(printf '%s\n' "$release_json" \
    | sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p')"
if [[ ! "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    printf 'No stable versioned release is available in %s yet.\n' "$repository" >&2
    exit 1
fi

git clone --depth 1 --branch "$tag" "https://github.com/$repository.git" "$install_dir"

if [ ! -e "$secrets_file" ]; then
    umask 077
    mkdir -p "$(dirname "$secrets_file")"
    cat > "$secrets_file" <<EOF
FEATHERPANEL_IMAGE_TAG=$tag
FEATHERPANEL_BIND_ADDRESS=127.0.0.1
FEATHERPANEL_PANEL_PORT=4831
MARIADB_DATABASE=featherpanel
MARIADB_USER=featherpanel
MARIADB_ROOT_PASSWORD=$(openssl rand -hex 24)
MARIADB_PASSWORD=$(openssl rand -hex 24)
REDIS_PASSWORD=$(openssl rand -hex 24)
EOF
    chmod 600 "$secrets_file"
fi

docker compose --project-name featherpanel --env-file "$secrets_file" \
    -f "$install_dir/docker-compose.yml" up -d

printf '\nFeatherPanel %s is starting at http://localhost:4831\n' "$tag"
printf 'Your local configuration is stored at %s\n' "$secrets_file"
