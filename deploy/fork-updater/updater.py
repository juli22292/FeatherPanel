#!/usr/bin/env python3
"""Host-side updater for the juli22292 FeatherPanel Docker fork.

This service deliberately accepts no image, tag, repository, or command from
the HTTP request. Only the latest stable release of the configured repository
can be installed, using the existing Compose project and its persistent data.
"""

from __future__ import annotations

import argparse
import fcntl
import hmac
import json
import os
import re
import shutil
import subprocess
import tarfile
import tempfile
import threading
import time
import urllib.request
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path


REPOSITORY = "juli22292/FeatherPanel"
SERVICES = ("backend", "frontendv2", "mcp", "async-runner")
VERSION_PATTERN = re.compile(r"^v(\d+)\.(\d+)\.(\d+)$")
INSTALLED_VERSION_PATTERN = re.compile(r"define\('APP_VERSION',\s*'(v\d+\.\d+\.\d+)'\)")


def run(command: list[str], *, env: dict[str, str] | None = None, output=None) -> str:
    result = subprocess.run(command, check=False, text=output is None, stdout=output or subprocess.PIPE,
                            stderr=subprocess.PIPE, env=env)
    if result.returncode != 0:
        detail = result.stderr.decode(errors="replace") if isinstance(result.stderr, bytes) else result.stderr
        raise RuntimeError(f"{command[0]} failed: {detail.strip()[-1000:]}")
    return result.stdout.strip() if output is None else ""


def version_parts(value: str) -> tuple[int, int, int]:
    match = VERSION_PATTERN.fullmatch(value)
    if not match:
        raise ValueError(f"Invalid release version: {value}")
    return tuple(map(int, match.groups()))


class Updater:
    def __init__(self, config: dict):
        if config.get("repository") != REPOSITORY:
            raise ValueError("Updater repository must be juli22292/FeatherPanel")
        self.project = config["project"]
        if self.project != "featherpanel":
            raise ValueError("This updater requires the featherpanel Compose project")
        self.compose_file = Path(config["compose_file"]).resolve(strict=True)
        self.env_file = Path(config["env_file"]).resolve(strict=True)
        self.backup_root = Path(config["backup_root"]).resolve()
        self.token_file = Path(config["token_file"]).resolve(strict=True)
        self.bind_host = config["bind_host"]
        self.port = int(config.get("port", 9418))
        self.busy = threading.Lock()
        self.last_result = "idle"

    def compose(self) -> list[str]:
        return ["docker", "compose", "--project-name", self.project,
                "--env-file", str(self.env_file), "-f", str(self.compose_file)]

    def installed_version(self) -> str:
        source = run(["docker", "exec", "featherpanel_backend", "cat", "/var/www/html/public/index.php"])
        match = INSTALLED_VERSION_PATTERN.search(source)
        if not match:
            raise RuntimeError("Could not read the installed panel version")
        return match.group(1)

    def latest_release(self) -> str:
        request = urllib.request.Request(
            f"https://api.github.com/repos/{REPOSITORY}/releases?per_page=100",
            headers={"Accept": "application/vnd.github+json", "User-Agent": "FeatherPanel-Fork-Updater"},
        )
        with urllib.request.urlopen(request, timeout=15) as response:
            releases = json.load(response)
        if not isinstance(releases, list):
            raise RuntimeError("GitHub did not return a release list")
        versions = [item["tag_name"] for item in releases if isinstance(item, dict)
                    and not item.get("draft") and not item.get("prerelease")
                    and VERSION_PATTERN.fullmatch(str(item.get("tag_name", "")))]
        if not versions:
            raise RuntimeError("No stable GitHub release is available")
        return max(versions, key=version_parts)

    def check_compose_images(self, tag: str, env: dict[str, str]) -> None:
        images = set(run(self.compose() + ["config", "--images"], env=env).splitlines())
        expected = {f"ghcr.io/juli22292/featherpanel-{service}:{tag}" for service in SERVICES}
        if not expected.issubset(images) or any("mythicalltd/featherpanel" in image for image in images):
            raise RuntimeError("Compose must use this fork's four published images")

    def backup(self, current: str) -> Path:
        self.backup_root.mkdir(mode=0o700, parents=True, exist_ok=True)
        os.chmod(self.backup_root, 0o700)
        backup_dir = self.backup_root / datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S")
        backup_dir.mkdir(mode=0o700)
        shutil.copy2(self.env_file, backup_dir / "panel.env")
        shutil.copy2(self.compose_file, backup_dir / "docker-compose.yml")
        (backup_dir / "installed-version.txt").write_text(current + "\n")

        with (backup_dir / "database.sql").open("wb") as dump:
            subprocess.run(["docker", "exec", "featherpanel_mysql", "sh", "-c",
                            'exec mariadb-dump --single-transaction --quick --routines --events -u root '
                            '-p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'],
                           stdout=dump, stderr=subprocess.PIPE, check=True)

        volumes = run(["docker", "volume", "ls", "--filter",
                       f"label=com.docker.compose.project={self.project}", "--format", "{{.Name}}"])
        names = [name for name in volumes.splitlines() if name]
        if not names:
            raise RuntimeError("No persistent Compose volumes were found")
        for name in names:
            # The live MariaDB data directory cannot be snapshotted consistently;
            # database.sql above is the recoverable database backup.
            if name.endswith("_mariadb_data"):
                continue
            mountpoint = Path(run(["docker", "volume", "inspect", "--format", "{{.Mountpoint}}", name]))
            with tarfile.open(backup_dir / f"{name}.tar.gz", "w:gz") as archive:
                archive.add(mountpoint, arcname=".")
        return backup_dir

    def set_image_tag(self, tag: str) -> None:
        original = self.env_file.read_text()
        lines = original.splitlines()
        replaced = False
        for index, line in enumerate(lines):
            if line.startswith("FEATHERPANEL_IMAGE_TAG="):
                lines[index] = f"FEATHERPANEL_IMAGE_TAG={tag}"
                replaced = True
        if not replaced:
            lines.append(f"FEATHERPANEL_IMAGE_TAG={tag}")
        stat = self.env_file.stat()
        with tempfile.NamedTemporaryFile(mode="w", dir=self.env_file.parent, delete=False) as temporary:
            temporary.write("\n".join(lines) + "\n")
            temporary.flush()
            os.fsync(temporary.fileno())
            os.chmod(temporary.name, stat.st_mode & 0o777)
            os.chown(temporary.name, stat.st_uid, stat.st_gid)
            temporary_name = temporary.name
        os.replace(temporary_name, self.env_file)

    def wait_until_healthy(self, tag: str) -> None:
        for _ in range(60):
            try:
                if self.installed_version() == tag and all(
                    run(["docker", "inspect", "--format", "{{.State.Health.Status}}",
                         f"featherpanel_{service}"]) == "healthy"
                    for service in ("backend", "frontendv2", "mcp")
                ):
                    return
            except (subprocess.CalledProcessError, RuntimeError):
                pass
            time.sleep(3)
        raise RuntimeError(f"Panel {tag} did not become healthy within three minutes")

    def update(self) -> None:
        self.backup_root.mkdir(mode=0o700, parents=True, exist_ok=True)
        with (self.backup_root / "update.lock").open("w") as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            current_image = run(["docker", "inspect", "--format", "{{.Config.Image}}", "featherpanel_backend"])
            if not current_image.startswith("ghcr.io/juli22292/featherpanel-backend:"):
                raise RuntimeError("The running backend is not a published image from this fork")
            current = self.installed_version()
            target = self.latest_release()
            if version_parts(target) <= version_parts(current):
                self.last_result = f"Already at {current}; latest stable release is {target}"
                return

            env = dict(os.environ, FEATHERPANEL_IMAGE_TAG=target)
            self.check_compose_images(target, env)
            run(self.compose() + ["pull", *SERVICES], env=env)
            backup_dir = self.backup(current)
            self.set_image_tag(target)
            try:
                run(self.compose() + ["up", "-d", "--no-build", "--no-deps", *SERVICES])
                self.wait_until_healthy(target)
            except Exception as error:
                raise RuntimeError(f"Backup preserved at {backup_dir}: {error}") from error
            self.last_result = f"Updated {current} to {target}; backup at {backup_dir}"

    def start(self) -> bool:
        if not self.busy.acquire(blocking=False):
            return False

        def worker() -> None:
            try:
                self.update()
            except Exception as error:
                self.last_result = f"Update failed: {error}"
            finally:
                print(self.last_result, flush=True)
                self.busy.release()

        threading.Thread(target=worker, daemon=False, name="featherpanel-update").start()
        return True


class Handler(BaseHTTPRequestHandler):
    updater: Updater

    def log_message(self, format: str, *args) -> None:
        # Avoid logging request headers, particularly the updater token.
        pass

    def respond(self, status: int, data: dict) -> None:
        payload = json.dumps(data).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def do_GET(self) -> None:
        if self.path != "/health":
            self.respond(404, {"success": False, "message": "Not found"})
            return
        self.respond(200, {"service": "featherpanel-fork-updater", "protocol": 1,
                           "repository": REPOSITORY, "busy": self.updater.busy.locked(),
                           "last_result": self.updater.last_result})

    def do_POST(self) -> None:
        if self.path != "/update":
            self.respond(404, {"success": False, "message": "Not found"})
            return
        expected = self.updater.token_file.read_text().strip()
        provided = self.headers.get("X-Featherpanel-Update-Token", "")
        if not expected or not hmac.compare_digest(provided, expected):
            self.respond(403, {"success": False, "message": "Invalid updater token"})
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
        except ValueError:
            self.respond(400, {"success": False, "message": "Invalid request length"})
            return
        if length > 4096 or length < 0:
            self.respond(413, {"success": False, "message": "Request too large"})
            return
        self.rfile.read(length)
        if not self.updater.start():
            self.respond(409, {"success": False, "message": "An update is already running"})
            return
        self.respond(202, {"success": True, "message": "Fork update started", "data": {"status": "started"}})


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", required=True)
    args = parser.parse_args()
    with open(args.config, encoding="utf-8") as config_file:
        updater = Updater(json.load(config_file))
    Handler.updater = updater
    ThreadingHTTPServer((updater.bind_host, updater.port), Handler).serve_forever()


if __name__ == "__main__":
    main()
