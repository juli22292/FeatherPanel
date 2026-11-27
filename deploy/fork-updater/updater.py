#!/usr/bin/env python3
"""Host-side updater for the juli22292 FeatherPanel Docker fork.

This service deliberately accepts no image, tag, repository, or command from
the HTTP request. Only the latest stable release of the configured repository
can be installed, using the existing Compose project and its persistent data.
"""

from __future__ import annotations

import argparse
import fcntl
import hashlib
import hmac
import json
import os
import platform
import re
import shutil
import subprocess
import tarfile
import tempfile
import threading
import time
import urllib.request
import uuid
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path


REPOSITORY = "juli22292/FeatherPanel"
SERVICES = ("backend", "frontendv2", "mcp", "async-runner")
ARCHITECTURES = {"x86_64": "amd64", "aarch64": "arm64"}
MAX_ASSET_SIZE = 2 * 1024**3 - 1
VERSION_PATTERN = re.compile(r"^v(\d+)\.(\d+)\.(\d+)(?:\.(\d+))?$")
RECORD_ID = re.compile(r"^[a-f0-9]{32}$")
VOLUME_NAME = re.compile(r"^featherpanel_[a-zA-Z0-9][a-zA-Z0-9_.-]*$")
INSTALLED_VERSION_PATTERN = re.compile(r"define\('APP_VERSION',\s*'(v\d+\.\d+\.\d+(?:\.\d+)?)'\)")


def run(command: list[str], *, env: dict[str, str] | None = None, output=None) -> str:
    result = subprocess.run(command, check=False, text=output is None, stdout=output or subprocess.PIPE,
                            stderr=subprocess.PIPE, env=env)
    if result.returncode != 0:
        detail = result.stderr.decode(errors="replace") if isinstance(result.stderr, bytes) else result.stderr
        raise RuntimeError(f"{command[0]} failed: {detail.strip()[-1000:]}")
    return result.stdout.strip() if output is None else ""


def version_parts(value: str) -> tuple[int, int, int, int]:
    match = VERSION_PATTERN.fullmatch(value)
    if not match:
        raise ValueError(f"Invalid release version: {value}")
    return tuple(int(part or 0) for part in match.groups())


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
        for path in ("boot/constants.php", "public/index.php"):
            try:
                source = run(["docker", "exec", "featherpanel_backend", "cat", f"/var/www/html/{path}"])
            except RuntimeError:
                continue
            match = INSTALLED_VERSION_PATTERN.search(source)
            if match:
                return match.group(1)
        raise RuntimeError("Could not read APP_VERSION from boot/constants.php or public/index.php")

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

    def load_release_images(self, tag: str) -> None:
        version_parts(tag)
        architecture = ARCHITECTURES.get(platform.machine())
        if not architecture:
            raise RuntimeError("GitHub image archives are unavailable for this architecture")

        request = urllib.request.Request(
            f"https://api.github.com/repos/{REPOSITORY}/releases/tags/{tag}",
            headers={"Accept": "application/vnd.github+json", "User-Agent": "FeatherPanel-Fork-Updater"},
        )
        with urllib.request.urlopen(request, timeout=15) as response:
            release = json.load(response)
        if (not isinstance(release, dict) or release.get("tag_name") != tag
                or release.get("draft") or release.get("prerelease")):
            raise RuntimeError("GitHub did not return the requested stable release")

        names = {f"featherpanel-{service}-linux-{architecture}-{tag}.tar.gz": service for service in SERVICES}
        assets = {asset["name"]: asset for asset in release.get("assets", [])
                  if isinstance(asset, dict) and isinstance(asset.get("name"), str) and asset["name"] in names}
        for name in names:
            asset = assets.get(name, {})
            size = asset.get("size")
            if (asset.get("state") != "uploaded" or not isinstance(size, int) or not 0 < size <= MAX_ASSET_SIZE
                    or not re.fullmatch(r"sha256:[0-9a-f]{64}", str(asset.get("digest")))):
                raise RuntimeError(f"The release is missing a valid Docker image archive: {name}")

        with tempfile.TemporaryDirectory(prefix="release-images-", dir=self.backup_root) as directory:
            for name, service in names.items():
                asset = assets[name]
                path = Path(directory) / name
                url = f"https://github.com/{REPOSITORY}/releases/download/{tag}/{name}"
                self.download_image(url, path, asset["size"], asset["digest"])
                expected = f"ghcr.io/juli22292/featherpanel-{service}:{tag}"
                with tarfile.open(path, "r:gz") as archive:
                    entry = archive.getmember("manifest.json")
                    if not entry.isfile() or entry.size > 1024 * 1024:
                        raise RuntimeError(f"Invalid Docker manifest: {name}")
                    with archive.extractfile(entry) as manifest_file:
                        manifest = json.load(manifest_file)
                if (not isinstance(manifest, list) or len(manifest) != 1
                        or not isinstance(manifest[0], dict) or manifest[0].get("RepoTags") != [expected]):
                    raise RuntimeError(f"The archive does not contain exactly the expected tag: {expected}")
                run(["docker", "load", "--input", str(path)])
                run(["docker", "image", "inspect", expected])
                path.unlink()

    @staticmethod
    def download_image(url: str, path: Path, size: int, digest: str) -> None:
        checksum = hashlib.sha256()
        received = 0
        with urllib.request.urlopen(url, timeout=30) as response, path.open("xb") as output:
            while chunk := response.read(1024 * 1024):
                received += len(chunk)
                if received > size:
                    raise RuntimeError("The downloaded image exceeds its published size")
                checksum.update(chunk)
                output.write(chunk)
        if received != size or "sha256:" + checksum.hexdigest() != digest:
            raise RuntimeError("The downloaded image does not match the published size and SHA-256")

    def mysql(self) -> list[str]:
        return ["docker", "exec", "-i", "featherpanel_mysql", "sh", "-c"]

    def database_name(self) -> str:
        name = run(["docker", "exec", "featherpanel_mysql", "printenv", "MARIADB_DATABASE"])
        if not re.fullmatch(r"[a-zA-Z0-9_]{1,64}", name):
            raise RuntimeError("The panel database name is unsupported")
        return name

    def migrations(self) -> set[str] | None:
        try:
            scripts = run(self.mysql() + [
                """exec mariadb -N -B -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" """
                """-e "SELECT script FROM featherpanel_migrations WHERE migrated = 'true'" """,
            ])
        except RuntimeError:
            return None
        return set(scripts.splitlines())

    def volume_mounts(self) -> dict[str, Path]:
        names = run(["docker", "volume", "ls", "--filter",
                     f"label=com.docker.compose.project={self.project}", "--format", "{{.Name}}"]).splitlines()
        mounts = {}
        for name in names:
            if name == f"{self.project}_mariadb_data":
                continue
            if not VOLUME_NAME.fullmatch(name):
                raise RuntimeError("Unexpected panel volume name")
            info = json.loads(run(["docker", "volume", "inspect", name]))
            volume = info[0]
            mount = Path(volume["Mountpoint"]).resolve(strict=True)
            if (volume["Driver"] != "local" or volume.get("Options") or mount.name != "_data"
                    or mount.parent.name != name or mount.is_mount()):
                raise RuntimeError(f"Volume {name} does not support local restoration")
            mounts[name] = mount
        if not mounts:
            raise RuntimeError("No panel data volumes found")
        return mounts

    def backup(self, current: str) -> Path:
        self.backup_root.mkdir(mode=0o700, parents=True, exist_ok=True)
        os.chmod(self.backup_root, 0o700)
        backup_dir = self.backup_root / uuid.uuid4().hex
        backup_dir.mkdir(mode=0o700)
        shutil.copy2(self.env_file, backup_dir / "panel.env")
        shutil.copy2(self.compose_file, backup_dir / "docker-compose.yml")
        (backup_dir / "installed-version.txt").write_text(current + "\n")
        database_name = self.database_name()
        dumps = ["database.sql"]
        with (backup_dir / dumps[0]).open("wb") as dump:
            subprocess.run(self.mysql() + [
                'exec mariadb-dump --single-transaction --quick --routines --events '
                '--add-drop-database -u root -p"$MARIADB_ROOT_PASSWORD" --databases "$MARIADB_DATABASE"',
            ], stdout=dump, stderr=subprocess.PIPE, check=True)
        if (backup_dir / "database.sql").stat().st_size == 0:
            raise RuntimeError("Database backup was empty")

        mounts = self.volume_mounts()
        for name, mount in mounts.items():
            filename = f"{name}.tar.gz"
            with tarfile.open(backup_dir / filename, "w:gz", compresslevel=1) as archive:
                archive.add(mount, arcname=".")
            dumps.append(filename)
        self.write_json(backup_dir / "manifest.json", {
            "version": current, "database": database_name,
            "files": {filename: self.file_digest(backup_dir / filename) for filename in dumps},
        })
        return backup_dir

    @staticmethod
    def file_digest(path: Path) -> str:
        with path.open("rb") as stream:
            return hashlib.file_digest(stream, "sha256").hexdigest()

    @staticmethod
    def write_json(path: Path, document) -> None:
        with tempfile.NamedTemporaryFile(mode="w", dir=path.parent, delete=False, encoding="utf-8") as stream:
            json.dump(document, stream)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(stream.name, path)

    def records(self) -> list[dict]:
        history = self.backup_root / "history.json"
        return json.loads(history.read_text()) if history.is_file() else []

    def store_record(self, entry: dict) -> None:
        previous = [item for item in self.records() if item["id"] != entry["id"]]
        self.write_json(self.backup_root / "history.json", [entry, *previous][:100])

    def restorable(self, history: list[dict] | None = None) -> dict | None:
        entries = self.records() if history is None else history
        source = next((item for item in entries if item["action"] == "update" and item.get("backup")), None)
        if source is None or source["result"] not in ("success", "failed"):
            return None
        if not (self.backup_root / source["backup"] / "manifest.json").is_file():
            return None
        if any(item["action"] == "restore" and item.get("source") == source["id"] and item["result"] == "success"
               for item in entries):
            return None
        try:
            active = self.installed_version()
        except RuntimeError:
            return None
        return source if active == source["to"] else None

    def history(self) -> dict:
        entries = self.records()
        available = self.restorable(entries) if not self.busy.locked() else None
        return {
            "entries": [{key: value for key, value in item.items() if key not in ("backup", "source")}
                        for item in entries],
            "busy": self.busy.locked(),
            "restore": {"id": available["id"], "version": available["from"]} if available else None,
        }

    def running_writers(self) -> list[str]:
        return [service for service in run(self.compose() + ["ps", "--status", "running", "--services"]).splitlines()
                if service != "mysql"]

    def restart_services(self, tag: str, paused: list[str]) -> None:
        other = [service for service in paused if service not in SERVICES]
        if other:
            run(self.compose() + ["start", *other])
        run(self.compose() + ["up", "-d", "--no-build", "--no-deps", "--pull", "never", *SERVICES])
        self.wait_until_healthy(tag)

    def validate_snapshot(self, entry: dict, mounts: dict[str, Path]) -> Path:
        if not RECORD_ID.fullmatch(entry["backup"]):
            raise RuntimeError("Invalid backup reference")
        directory = self.backup_root / entry["backup"]
        if directory.is_symlink() or not directory.is_dir():
            raise RuntimeError("Backup directory is unavailable")
        manifest = json.loads((directory / "manifest.json").read_text())
        filenames = {"database.sql", *(f"{name}.tar.gz" for name in mounts)}
        if (manifest["version"] != entry["from"] or manifest["database"] != self.database_name()
                or set(manifest["files"]) != filenames):
            raise RuntimeError("The backup does not match the current panel database and volumes")
        for filename in filenames:
            path = directory / filename
            if (path.is_symlink() or not path.is_file() or self.file_digest(path) != manifest["files"][filename]):
                raise RuntimeError("A backup file is missing or did not pass its integrity check")
        return directory

    def import_database(self, dump: Path) -> None:
        with dump.open("rb") as stream:
            subprocess.run(self.mysql() + [
                'exec mariadb -u root -p"$MARIADB_ROOT_PASSWORD"',
            ], stdin=stream, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, check=True)

    @staticmethod
    def restore_tar_filter(member: tarfile.TarInfo, destination: str) -> tarfile.TarInfo:
        filtered = tarfile.data_filter(member, destination)
        return filtered.replace(uid=member.uid, gid=member.gid, uname=None, gname=None,
                                mode=member.mode & 0o0777)

    def restore(self, source_id: str) -> None:
        self.backup_root.mkdir(mode=0o700, parents=True, exist_ok=True)
        with (self.backup_root / "update.lock").open("w") as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            source = self.restorable()
            if not source or source["id"] != source_id:
                raise RuntimeError("This backup is no longer the latest restorable update")
            current = source["to"]
            target = source["from"]
            entry = self.new_record("restore", current, target)
            entry["source"] = source_id
            self.store_record(entry)

            paused, staged, replaced = [], {}, {}
            safety = None
            touched_database = False
            healthy = False
            try:
                env = dict(os.environ, FEATHERPANEL_IMAGE_TAG=target)
                self.check_compose_images(target, env)
                self.check_local_images(target)
                mounts = self.volume_mounts()
                snapshot = self.validate_snapshot(source, mounts)
                for name, mount in mounts.items():
                    work = Path(tempfile.mkdtemp(prefix=".fp-restore-", dir=mount.parent))
                    staged[name] = work
                    (work / "new").mkdir()
                    with tarfile.open(snapshot / f"{name}.tar.gz", "r:gz") as archive:
                        archive.extractall(work / "new", filter=self.restore_tar_filter)

                paused = self.running_writers()
                if paused:
                    run(self.compose() + ["stop", *paused])
                safety = self.backup(current)
                entry["backup"] = safety.name
                self.store_record(entry)
                for name, work in staged.items():
                    mount = mounts[name]
                    mount.rename(work / "old")
                    replaced[name] = mount
                    (work / "new").rename(mount)
                touched_database = True
                self.import_database(snapshot / "database.sql")
                self.set_image_tag(target)
                self.restart_services(target, paused)
                healthy = True
                self.last_result = f"Restored {target}"
            except Exception:
                if paused or replaced or touched_database:
                    if paused:
                        run(self.compose() + ["stop", *paused])
                    for name, mount in reversed(list(replaced.items())):
                        work = staged[name]
                        if mount.exists():
                            mount.rename(work / "new")
                        (work / "old").rename(mount)
                    if touched_database and safety is not None:
                        self.import_database(safety / "database.sql")
                    self.set_image_tag(current)
                    self.restart_services(current, paused)
                raise
            finally:
                entry["result"] = "success" if healthy else "failed"
                entry["finished_at"] = datetime.now(timezone.utc).isoformat()
                self.store_record(entry)
                for work in staged.values():
                    if healthy or not (work / "old").exists():
                        shutil.rmtree(work)

    @staticmethod
    def new_record(action: str, current: str, target: str) -> dict:
        return {
            "id": uuid.uuid4().hex, "action": action, "from": current, "to": target,
            "result": "running", "started_at": datetime.now(timezone.utc).isoformat(),
            "finished_at": None, "migrations": [],
        }

    def check_local_images(self, tag: str) -> None:
        for service in SERVICES:
            run(["docker", "image", "inspect", f"ghcr.io/juli22292/featherpanel-{service}:{tag}"])

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

            entry = self.new_record("update", current, target)
            self.store_record(entry)
            paused = []
            backup_dir = None
            changed_tag = False
            try:
                env = dict(os.environ, FEATHERPANEL_IMAGE_TAG=target)
                self.check_compose_images(target, env)
                try:
                    run(self.compose() + ["pull", *SERVICES], env=env)
                except RuntimeError as pull_error:
                    try:
                        self.load_release_images(target)
                    except Exception as asset_error:
                        raise RuntimeError(f"Docker pull failed ({pull_error}); GitHub image archives unavailable: {asset_error}") from asset_error
                paused = self.running_writers()
                if paused:
                    run(self.compose() + ["stop", *paused])
                before = self.migrations()
                backup_dir = self.backup(current)
                entry["backup"] = backup_dir.name
                self.store_record(entry)
                self.set_image_tag(target)
                changed_tag = True
                self.restart_services(target, paused)
                after = self.migrations()
                entry["migrations"] = sorted(after - before) if before is not None and after is not None else None
                entry["result"] = "success"
                self.last_result = f"Updated {current} to {target}; backup at {backup_dir}"
            except Exception:
                entry["result"] = "failed"
                if paused and not changed_tag:
                    run(self.compose() + ["start", *paused])
                raise
            finally:
                entry["finished_at"] = datetime.now(timezone.utc).isoformat()
                self.store_record(entry)

    def start(self, restore_id: str | None = None) -> bool:
        if not self.busy.acquire(blocking=False):
            return False

        def worker() -> None:
            try:
                if restore_id is None:
                    self.update()
                else:
                    self.restore(restore_id)
            except Exception as error:
                self.last_result = f"Updater operation failed: {error}"
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

    def authorized(self) -> bool:
        expected = self.updater.token_file.read_text().strip().encode()
        provided = self.headers.get("X-Featherpanel-Update-Token", "").encode()
        if expected and hmac.compare_digest(provided, expected):
            return True
        self.respond(403, {"success": False, "message": "Invalid updater token"})
        return False

    def do_GET(self) -> None:
        if self.path == "/history":
            if self.authorized():
                self.respond(200, {"success": True, "data": self.updater.history()})
            return
        if self.path != "/health":
            self.respond(404, {"success": False, "message": "Not found"})
            return
        self.respond(200, {"service": "featherpanel-fork-updater", "protocol": 1,
                           "repository": REPOSITORY, "busy": self.updater.busy.locked(),
                           "features": ["history", "restore"]})

    def do_POST(self) -> None:
        if self.path not in ("/update", "/restore"):
            self.respond(404, {"success": False, "message": "Not found"})
            return
        if not self.authorized():
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
        except ValueError:
            self.respond(400, {"success": False, "message": "Invalid request length"})
            return
        if length > 4096 or length < 0:
            self.respond(413, {"success": False, "message": "Request too large"})
            return
        body = self.rfile.read(length)
        restore_id = None
        if self.path == "/restore":
            try:
                data = json.loads(body)
                restore_id = data["id"]
                confirmed_version = data["version"]
                if not isinstance(restore_id, str) or not RECORD_ID.fullmatch(restore_id):
                    raise ValueError("Invalid backup ID")
            except (KeyError, TypeError, ValueError):
                self.respond(400, {"success": False, "message": "Invalid backup ID"})
                return
            if self.updater.busy.locked():
                self.respond(409, {"success": False, "message": "An updater operation is already running"})
                return
            snapshot = self.updater.restorable()
            if snapshot is None or snapshot["id"] != restore_id or snapshot["from"] != confirmed_version:
                self.respond(409, {"success": False, "message": "The backup is no longer available"})
                return
        if not self.updater.start(restore_id):
            self.respond(409, {"success": False, "message": "An updater operation is already running"})
            return
        self.respond(202, {"success": True, "message": "Updater operation started", "data": {"status": "started"}})


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
