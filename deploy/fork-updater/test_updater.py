import importlib.util
import io
import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch


spec = importlib.util.spec_from_file_location("fork_updater", Path(__file__).with_name("updater.py"))
updater = importlib.util.module_from_spec(spec)
spec.loader.exec_module(updater)


class ForkUpdaterTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        root = Path(self.temp.name)
        compose = root / "docker-compose.yml"
        compose.write_text("services: {}\n")
        env = root / "panel.env"
        env.write_text("MARIADB_PASSWORD=keep-me\nFEATHERPANEL_IMAGE_TAG=v1.4.2\n")
        os.chmod(env, 0o600)
        token = root / "token"
        token.write_text("secret\n")
        self.instance = updater.Updater({
            "repository": updater.REPOSITORY,
            "project": "featherpanel",
            "compose_file": str(compose),
            "env_file": str(env),
            "backup_root": str(root / "backups"),
            "token_file": str(token),
            "bind_host": "127.0.0.1",
        })

    def test_latest_release_uses_highest_stable_semantic_version(self):
        releases = [
            {"tag_name": "v1.4.3", "draft": False, "prerelease": False},
            {"tag_name": "v2.0.0", "draft": True},
            {"tag_name": "v1.4.4", "prerelease": True},
            {"tag_name": "v1.4.2"},
            {"tag_name": "v1.4.3.1"},
            {"tag_name": "v1.4.3.2", "prerelease": True},
        ]
        with patch.object(updater.urllib.request, "urlopen", return_value=io.BytesIO(json.dumps(releases).encode())):
            self.assertEqual(self.instance.latest_release(), "v1.4.3.1")

    def test_three_and_four_part_versions_sort_numerically(self):
        self.assertLess(updater.version_parts("v1.4.3"), updater.version_parts("v1.4.3.1"))
        self.assertLess(updater.version_parts("v1.4.3.9"), updater.version_parts("v1.4.3.10"))
        self.assertEqual(updater.version_parts("v1.4.3"), updater.version_parts("v1.4.3.0"))
        with self.assertRaises(ValueError):
            updater.version_parts("v1.4.3.1.1")

    def test_compose_must_use_all_four_fork_images(self):
        expected = "\n".join(
            f"ghcr.io/juli22292/featherpanel-{service}:v1.4.3" for service in updater.SERVICES
        )
        with patch.object(updater, "run", return_value=expected):
            self.instance.check_compose_images("v1.4.3", {})
        with patch.object(updater, "run", return_value=expected + "\nghcr.io/mythicalltd/featherpanel-backend:latest"):
            with self.assertRaisesRegex(RuntimeError, "four published images"):
                self.instance.check_compose_images("v1.4.3", {})

    def test_update_backs_up_before_recreating_application_containers(self):
        events = []

        def record_run(command, **_kwargs):
            events.append(command)
            if command[:2] == ["docker", "inspect"]:
                return "ghcr.io/juli22292/featherpanel-backend:v1.4.3"
            return ""

        with patch.object(updater, "run", side_effect=record_run):
            with patch.object(self.instance, "installed_version", return_value="v1.4.3"):
                with patch.object(self.instance, "latest_release", return_value="v1.4.3.1"):
                    with patch.object(self.instance, "check_compose_images"):
                        with patch.object(self.instance, "backup", side_effect=lambda _current: events.append("backup") or Path("/backup")):
                            with patch.object(self.instance, "set_image_tag", side_effect=lambda _tag: events.append("tag")):
                                with patch.object(self.instance, "wait_until_healthy"):
                                    self.instance.update()

        pull = next(index for index, event in enumerate(events) if isinstance(event, list) and "pull" in event)
        up = next(index for index, event in enumerate(events) if isinstance(event, list) and "up" in event)
        self.assertLess(pull, events.index("backup"))
        self.assertLess(events.index("backup"), events.index("tag"))
        self.assertLess(events.index("tag"), up)
        self.assertIn("--no-deps", events[up])
        self.assertNotIn("down", events[up])

    def test_update_refuses_an_official_running_image(self):
        with patch.object(updater, "run", return_value="ghcr.io/mythicalltd/featherpanel-backend:v1.4.2"):
            with patch.object(self.instance, "backup") as backup:
                with self.assertRaisesRegex(RuntimeError, "not a published image from this fork"):
                    self.instance.update()
                backup.assert_not_called()

    def test_tag_update_preserves_secrets_and_file_mode(self):
        self.instance.set_image_tag("v1.4.3")
        contents = self.instance.env_file.read_text()
        self.assertIn("MARIADB_PASSWORD=keep-me", contents)
        self.assertIn("FEATHERPANEL_IMAGE_TAG=v1.4.3", contents)
        self.assertNotIn("FEATHERPANEL_IMAGE_TAG=v1.4.2", contents)
        self.assertEqual(self.instance.env_file.stat().st_mode & 0o777, 0o600)


if __name__ == "__main__":
    unittest.main()
