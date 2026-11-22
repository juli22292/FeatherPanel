#!/usr/bin/env python3
"""Integration check. Run in a disposable container with NGINX and OpenSSL."""
import http.client
import ipaddress
import json
from pathlib import Path
import socket
import ssl
import subprocess
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from edge import Edge, Handler, Server, certificate_name, run

PUBLIC_IP = "203.0.113.20"
DOMAIN = "example.test"
SERVER_UUID = "edcc3cd8-fa9f-4fd6-9815-e422d9176ab3"


class Application(BaseHTTPRequestHandler):
    def do_GET(self):
        assert self.headers["Host"] == DOMAIN
        assert self.headers["X-Forwarded-Proto"] == "https"
        self.send_response(200)
        self.end_headers()
        self.wfile.write(b"server is available")

    def log_message(self, *_args):
        pass


class UnixHttp(http.client.HTTPConnection):
    def __init__(self, path):
        super().__init__("localhost", timeout=9)
        self.path = path

    def connect(self):
        self.sock = socket.socket(socket.AF_UNIX)
        self.sock.settimeout(self.timeout)
        self.sock.connect(str(self.path))


def api(path, route, payload=None):
    connection = UnixHttp(path)
    connection.request("GET" if payload is None else "POST", "/v1/" + route,
                       json.dumps(payload) if payload is not None else None)
    response = connection.getresponse()
    result = (response.status, json.loads(response.read()))
    connection.close()
    return result


def http_reaches_token():
    for _ in range(20):
        connection = http.client.HTTPConnection("127.0.0.1", 80, timeout=1)
        connection.request("GET", "/.well-known/acme-challenge/test", headers={"Host": DOMAIN})
        reply = connection.getresponse()
        data = reply.read()
        connection.close()
        if reply.status == 200 and data == b"test token":
            return
        time.sleep(0.1)
    raise AssertionError("HTTP-01 token did not reach the public vhost")


def https_reaches_application(cert):
    context = ssl.create_default_context(cafile=str(cert))
    for _ in range(20):
        try:
            with socket.create_connection(("127.0.0.1", 443), timeout=1) as connection:
                with context.wrap_socket(connection, server_hostname=DOMAIN) as stream:
                    stream.sendall(f"GET / HTTP/1.1\r\nHost: {DOMAIN}\r\nConnection: close\r\n\r\n".encode())
                    data = b""
                    while True:
                        part = stream.recv(4096)
                        if not part:
                            break
                        data += part
                    assert b"HTTP/1.1 200" in data and b"server is available" in data, data
                    return
        except OSError:
            time.sleep(0.1)
    raise AssertionError("HTTPS did not reach the selected allocation")


def test():
    conf = Path("/etc/nginx/conf.d")
    config = conf / (certificate_name(DOMAIN) + ".conf")
    assert not config.exists(), "Run only in a disposable environment"
    with tempfile.TemporaryDirectory(prefix="serverpanel-edge-test-") as scratch:
        root = Path(scratch)
        root.chmod(0o755)
        certs = root / "letsencrypt" / "live"
        cert_dir = certs / certificate_name(DOMAIN)
        renewal = certs.parent / "renewal"
        renewal.mkdir(parents=True)
        socket_path = root / "api.sock"
        application = ThreadingHTTPServer(("0.0.0.0", 0), Application)
        threading.Thread(target=application.serve_forever, daemon=True).start()
        destination = socket.gethostbyname(socket.gethostname())
        port = application.server_port
        network = str(ipaddress.ip_network(destination + "/32"))
        proof = ""
        fail_certificate = True

        def resolver(name, kind):
            if kind == "TXT":
                return [f'"{proof}"'] if name == "_serverpanel-proxy." + DOMAIN else []
            return [PUBLIC_IP] if kind == "A" else []

        def command(*args, timeout=15):
            if args[0] == "/usr/bin/systemctl":
                return run("/usr/sbin/nginx", "-s", "reload", timeout=timeout)
            if args[0] == "/usr/bin/certbot":
                if args[1] == "delete":
                    cert_dir.joinpath("fullchain.pem").unlink()
                    cert_dir.joinpath("privkey.pem").unlink()
                    (renewal / (certificate_name(DOMAIN) + ".conf")).unlink()
                    return "", ""
                if fail_certificate:
                    raise RuntimeError("Intentional certificate failure")
                token = root / "challenges/.well-known/acme-challenge/test"
                token.write_text("test token")
                token.chmod(0o644)
                http_reaches_token()
                cert_dir.mkdir(parents=True)
                run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
                    "-subj", "/CN=" + DOMAIN, "-addext", "subjectAltName=DNS:" + DOMAIN,
                    "-keyout", str(cert_dir / "privkey.pem"), "-out", str(cert_dir / "fullchain.pem"))
                (renewal / (certificate_name(DOMAIN) + ".conf")).touch()
                return "", ""
            return run(*args, timeout=timeout)

        edge = Edge({"networks": [network], "ports": [[port, port]], "reserved_names": ["reserved.test"]},
                    state=root, conf_dir=conf, certs=certs, command=command, resolver=resolver)
        nginx = subprocess.Popen(
            ["/usr/sbin/nginx", "-g", "daemon off;"],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        try:
            for _ in range(20):
                try:
                    with socket.create_connection(("127.0.0.1", 80), timeout=0.1):
                        break
                except OSError:
                    time.sleep(0.1)
            assert nginx.poll() is None
            with Server(str(socket_path), Handler) as http:
                http.edge = edge
                threading.Thread(target=http.serve_forever, daemon=True).start()
                threading.Thread(target=edge.work, daemon=True).start()
                incoming = {"server": SERVER_UUID, "domain": DOMAIN, "public_ips": [PUBLIC_IP]}
                status, response = api(socket_path, "verify", incoming)
                assert status == 200 and not response["verified"]
                proof = response["txt_value"]
                assert api(socket_path, "verify", incoming)[1]["verified"]
                assert api(socket_path, "verify", {**incoming, "domain": "reserved.test"})[0] == 409
                create = {**incoming, "id": 42, "ip": destination, "port": port, "email": "test@example.test"}
                assert api(socket_path, "create", {**create, "ip": "127.0.0.1"})[0] == 422
                assert api(socket_path, "create", {**create, "domain": "bad;directive.test"})[0] == 422
                assert api(socket_path, "create", create)[0] == 200

                def wait_for_state(expected):
                    for _ in range(100):
                        result = api(socket_path, "status/" + SERVER_UUID)[1]
                        if result["states"].get("42") == expected:
                            return
                        time.sleep(0.1)
                    raise AssertionError(result)

                wait_for_state("failed")
                assert not config.exists()

                fail_certificate = False
                assert api(socket_path, "create", create)[0] == 200
                wait_for_state("active")
                assert api(socket_path, "create", create)[1]["status"] == "active"
                https_reaches_application(cert_dir / "fullchain.pem")
                assert api(socket_path, "delete", {**incoming, "id": 43})[0] == 409
                assert api(socket_path, "delete", {**incoming, "id": 42})[1]["deleted"]
                assert not config.exists() and not (cert_dir / "fullchain.pem").exists()
                assert not api(socket_path, "status/" + SERVER_UUID)[1]["states"]
                http.shutdown()
        finally:
            application.shutdown()
            nginx.terminate()
            nginx.wait(timeout=5)


if __name__ == "__main__":
    test()
    print("Verified DNS ownership, rejection, rollback, retry, HTTP-01, HTTPS upstream and removal")
