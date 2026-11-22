import base64
import json
import runpy
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch


class LxcGuestAccessTests(unittest.TestCase):
    def setUp(self):
        self.helper = runpy.run_path(str(Path(__file__).with_name("guest-access.py")))
        self.parse = self.helper["parse_request"]
        self.handle = self.helper["handle"]
        self.inventory = {"operation": "inventory", "vmid": 5002}

    def command(self, payload):
        token = base64.urlsafe_b64encode(json.dumps(payload).encode()).decode().rstrip("=")
        return "serverpanel-lxc-v1 " + token

    def test_only_documented_operations_and_fields_are_accepted(self):
        self.assertEqual(self.parse(self.command(self.inventory)), self.inventory)
        for payload in [
            {"operation": "unknown", "vmid": 5002},
            {"operation": "inventory", "vmid": "5002"},
            {"operation": "inventory", "vmid": 5002, "username": "someuser"},
            {"operation": "reset", "vmid": 5002, "guest_id": "a" * 32, "username": "root", "uid": 1000, "hash": "$6$salt$" + "a" * 86},
        ]:
            with self.subTest(payload=payload["operation"]), self.assertRaises(ValueError):
                self.parse(self.command(payload))

    def test_inventory_is_read_only_and_requires_local_allowed_container(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "vmids").write_text("5000-5010\n")
            (root / "5002.conf").touch()
            (root / "5020.conf").touch()
            with patch.dict(self.handle.__globals__, {"RANGES": root / "vmids", "LOCAL_LXC": root}):
                with patch.dict(self.handle.__globals__, {"run_pct": self.assert_inventory_command}):
                    self.assertEqual(self.handle(self.inventory), b"guest response")
                for vmid in (5003, 5020):
                    with self.assertRaises(ValueError):
                        self.handle({"operation": "inventory", "vmid": vmid})

    def assert_inventory_command(self, command, password_data):
        self.assertEqual(command[:6], ["/usr/sbin/pct", "exec", "5002", "--", "/bin/sh", "-c"])
        self.assertEqual(command[-1], "inventory")
        self.assertEqual(password_data, b"")
        return b"guest response"

    def test_guest_operation_receives_only_hash_on_stdin(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "vmids").write_text("5002\n")
            (root / "5002.conf").touch()
            digest = "$6$salt$" + "a" * 86
            payload = {"operation": "add", "vmid": 5002, "guest_id": "b" * 32, "username": "guestuser", "hash": digest}
            valid = self.parse(self.command(payload))

            def check(command, password_data):
                self.assertNotIn(digest, command)
                self.assertEqual(command[-4:], ["add", "b" * 32, "guestuser", ""])
                self.assertEqual(password_data.decode(), f"guestuser:{digest}\n")
                return b"ok\n"

            with patch.dict(self.handle.__globals__, {"RANGES": root / "vmids", "LOCAL_LXC": root, "run_pct": check}):
                self.assertEqual(self.handle(valid), b"ok\n")


if __name__ == "__main__":
    unittest.main()
