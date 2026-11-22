#!/usr/bin/env python3
"""Private, UNIX-socket connector for ServerPanel's public NGINX."""
import fcntl
import grp
import hashlib
import hmac
import ipaddress
import json
import logging
import os
from pathlib import Path
import re
import shlex
import socketserver
import subprocess
import tempfile
import threading
from http.server import BaseHTTPRequestHandler

DOMAIN = re.compile(r"(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]")
UUID = re.compile(r"[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}")
EMAIL = re.compile(r"[A-Za-z0-9.!#$%&'*+=?^_`{|}~@/-]+")
STATES = {"queued", "working", "active", "failed", "deleting"}
CONFIG = Path("/etc/serverpanel-edge/config.json")
STATE = Path("/var/lib/serverpanel-edge")
NGINX = Path("/etc/nginx/conf.d")
CERTS = Path("/etc/letsencrypt/live")
SOCKET = Path("/run/serverpanel-edge/api.sock")
LOG = logging.getLogger("serverpanel-edge")


class Rejected(Exception):
    def __init__(self, status):
        self.status = status


def valid_domain(value):
    if not isinstance(value, str) or len(value) > 234 or not DOMAIN.fullmatch(value):
        raise Rejected(422)
    return value


def valid_server(value):
    if not isinstance(value, str) or not UUID.fullmatch(value):
        raise Rejected(422)
    return value


def parse_integer(value, maximum):
    if type(value) is not int or not 1 <= value <= maximum:
        raise Rejected(422)
    return value


def atomic_write(path, data, mode):
    with tempfile.NamedTemporaryFile(dir=path.parent, delete=False) as output:
        temporary = Path(output.name)
        try:
            os.fchmod(output.fileno(), mode)
            output.write(data)
            output.flush()
            os.fsync(output.fileno())
            os.replace(temporary, path)
            directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
            try:
                os.fsync(directory)
            finally:
                os.close(directory)
        finally:
            temporary.unlink(missing_ok=True)


def run(*command, timeout=15):
    result = subprocess.run(command, capture_output=True, text=True, check=False, timeout=timeout)
    if result.returncode != 0:
        raise RuntimeError(f"{command[0]} exited with {result.returncode}: {result.stderr[-1000:].strip()}")
    return result.stdout, result.stderr


def dns_rows(name, record):
    rows, _ = run("/usr/bin/dig", "+short", "+time=2", "+tries=1", name, record, timeout=3)
    return rows.splitlines()


def certificate_name(domain):
    return "serverpanel-edge-" + hashlib.sha256(domain.encode()).hexdigest()[:24]


def owned_marker(row):
    return f"# ServerPanel edge {row['id']} {row['server']} {row['domain']}\n"


def render(row, tls, root, cert_dir):
    domain = row["domain"]
    address = ipaddress.ip_address(row["ip"])
    host = f"[{address}]" if address.version == 6 else str(address)
    challenge = f"""
    location ^~ /.well-known/acme-challenge/ {{
        default_type text/plain;
        root {root};
        try_files $uri =404;
    }}"""
    prefix = owned_marker(row)
    fallback = "return 301 https://$host$request_uri;" if tls else "return 404;"
    http = f"""server {{
    listen 80;
    listen [::]:80;
    server_name {domain};
    {challenge}
    location / {{ {fallback} }}
}}
"""
    if not tls:
        return (prefix + http).encode()
    variable = "$sp_connection_" + str(row["id"])
    return (prefix + http + f"""map $http_upgrade {variable} {{
    default upgrade;
    '' close;
}}
server {{
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name {domain};
    ssl_certificate {cert_dir}/fullchain.pem;
    ssl_certificate_key {cert_dir}/privkey.pem;
    location / {{
        proxy_pass http://{host}:{row['port']};
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection {variable};
    }}
}}
""").encode()


class Edge:
    def __init__(self, policy, state=STATE, conf_dir=NGINX, certs=CERTS, command=run, resolver=dns_rows):
        self.networks = [ipaddress.ip_network(item) for item in policy["networks"]]
        self.ports = [(parse_integer(lo, 65535), parse_integer(hi, 65535)) for lo, hi in policy["ports"]]
        if not self.networks or not self.ports or any(lo > hi for lo, hi in self.ports):
            raise ValueError("Set allowed backend networks and port ranges")
        self.reserved = [valid_domain(item.lstrip(".")) for item in policy.get("reserved_names", [])]
        self.conf = conf_dir
        self.certs = certs
        self.command = command
        self.resolve = resolver
        self.state_file = state / "proxies.json"
        self.webroot = state / "challenges"
        for directory in (self.webroot, self.webroot / ".well-known", self.webroot / ".well-known" / "acme-challenge"):
            directory.mkdir(exist_ok=True, mode=0o755)
            directory.chmod(0o755)
        secret = state / "proof-key"
        if not secret.exists():
            atomic_write(secret, os.urandom(32), 0o600)
        self.key = secret.read_bytes()
        if len(self.key) != 32:
            raise ValueError("Invalid proof key")
        self.changed = threading.Condition()
        self.operations = threading.Lock()
        self.proxies = json.loads(self.state_file.read_text()) if self.state_file.exists() else {}
        for domain, row in self.proxies.items():
            if valid_domain(domain) != row["domain"] or row["status"] not in STATES:
                raise ValueError("Invalid managed proxy state")
            if row["status"] == "working":
                row["status"] = "queued"
        self.save()

    def save(self):
        atomic_write(self.state_file, json.dumps(self.proxies, sort_keys=True).encode(), 0o600)

    def proof(self, server, domain):
        return "serverpanel-" + hmac.new(self.key, f"{server}:{domain}".encode(), hashlib.sha256).hexdigest()

    def verify(self, payload):
        server = valid_server(payload.get("server"))
        domain = valid_domain(payload.get("domain"))
        if any(domain == name or domain.endswith("." + name) for name in self.reserved):
            raise Rejected(409)
        ips = payload.get("public_ips")
        if not isinstance(ips, list) or not 1 <= len(ips) <= 2:
            raise Rejected(422)
        try:
            expected = {ipaddress.ip_address(address) for address in ips if isinstance(address, str)}
        except ValueError as error:
            raise Rejected(422) from error
        if len(expected) != len(ips):
            raise Rejected(422)

        target = "_serverpanel-proxy." + domain
        token = self.proof(server, domain)
        try:
            actual = set()
            complete = True
            for family in ("A", "AAAA"):
                for line in self.resolve(domain, family):
                    try:
                        actual.add(ipaddress.ip_address(line))
                    except ValueError:
                        if not line.endswith("."):
                            complete = False
            txt = self.resolve(target, "TXT")
            owned = any(hmac.compare_digest("".join(shlex.split(line)), token) for line in txt if line.startswith('"'))
            routed = bool(actual) and actual.issubset(expected) and complete
        except (RuntimeError, subprocess.TimeoutExpired, ValueError):
            owned = routed = False
        return {"verified": owned and routed, "address_verified": routed, "ownership_verified": owned,
                "txt_name": target, "txt_value": token}

    def queue(self, payload):
        domain = valid_domain(payload.get("domain"))
        server = valid_server(payload.get("server"))
        identity = parse_integer(payload.get("id"), 2147483647)
        port = parse_integer(payload.get("port"), 65535)
        ip = payload.get("ip")
        email = payload.get("email")
        try:
            address = ipaddress.ip_address(ip) if isinstance(ip, str) and "%" not in ip else None
        except ValueError as error:
            raise Rejected(422) from error
        if isinstance(address, ipaddress.IPv6Address) and address.ipv4_mapped:
            raise Rejected(422)
        if (not address or address.is_loopback or address.is_unspecified or address.is_link_local or address.is_multicast
                or not any(address in network for network in self.networks)
                or not any(lo <= port <= hi for lo, hi in self.ports)
                or not isinstance(email, str) or len(email) > 254 or not EMAIL.fullmatch(email)
                or not email[0].isalnum() or email.count("@") != 1):
            raise Rejected(422)
        with self.changed:
            row = self.proxies.get(domain)
            if row and (row["id"], row["server"], row["ip"], row["port"]) != (identity, server, str(address), port):
                raise Rejected(409)
            if row and row["status"] in {"queued", "working", "active"}:
                return {"status": row["status"]}
            if row and row["status"] == "deleting":
                raise Rejected(409)
            if sum(item["status"] == "queued" for item in self.proxies.values()) >= 50:
                raise Rejected(409)
            row = {"id": identity, "domain": domain, "server": server, "ip": str(address), "port": port,
                   "public_ips": payload.get("public_ips"), "email": email, "status": "queued"}
            self.proxies[domain] = row
            self.save()
            self.changed.notify()
            return {"status": row["status"]}

    def site(self, row):
        return self.conf / (certificate_name(row["domain"]) + ".conf")

    def read_site(self, row):
        file = self.site(row)
        if file.is_symlink():
            raise Rejected(409)
        if not file.exists():
            return None
        old = file.read_bytes()
        if not old.startswith(owned_marker(row).encode()):
            raise Rejected(409)
        return old

    def ensure_available(self, row):
        config, _ = self.command("/usr/sbin/nginx", "-T")
        origin = ""
        domain = row["domain"]
        for line in config.splitlines():
            if line.startswith("# configuration file "):
                origin = line[21:].removesuffix(":")
            if origin == str(self.site(row)):
                continue
            match = re.match(r"\s*server_name\s+([^;]+);", line)
            if match:
                for name in match.group(1).lower().split():
                    name = name.strip("'\"")
                    if (name == domain or (name.startswith(("*.", ".")) and
                            (domain.endswith("." + name.lstrip("*.")) or domain == name.lstrip("*.")))
                            or (name.endswith(".*") and domain.startswith(name[:-1]))):
                        raise Rejected(409)

    def change_site(self, row, data):
        file = self.site(row)
        before = self.read_site(row)
        try:
            if data is None:
                file.unlink(missing_ok=True)
            else:
                atomic_write(file, data, 0o644)
            config_result = self.command("/usr/sbin/nginx", "-t")
            if re.search(r'conflicting server name "' + re.escape(row["domain"]) + '"', config_result[1]):
                raise Rejected(409)
            self.command("/usr/bin/systemctl", "reload", "nginx")
        except Exception:
            if before is None:
                file.unlink(missing_ok=True)
            else:
                atomic_write(file, before, 0o644)
            self.command("/usr/sbin/nginx", "-t")
            self.command("/usr/bin/systemctl", "reload", "nginx")
            raise

    def remove_certificate(self, row):
        name = certificate_name(row["domain"])
        if (self.certs.parent / "renewal" / (name + ".conf")).is_file():
            self.command("/usr/bin/certbot", "delete", "--non-interactive", "--cert-name", name, timeout=45)

    def provision(self, row):
        if not self.verify(row)["verified"]:
            raise Rejected(422)
        self.ensure_available(row)
        previous = self.read_site(row)
        name = certificate_name(row["domain"])
        cert_dir = self.certs / name
        # A crashed or failed attempt may have left the HTTP challenge vhost behind.
        cert_existed = (cert_dir / "fullchain.pem").is_file()
        try:
            self.change_site(row, render(row, False, self.webroot, cert_dir))
            self.command("/usr/bin/certbot", "certonly", "--non-interactive", "--agree-tos",
                         "--email", row["email"], "--webroot", "-w", str(self.webroot), "--cert-name", name,
                         "-d", row["domain"], "--keep-until-expiring", timeout=180)
            self.change_site(row, render(row, True, self.webroot, cert_dir))
        except Exception:
            # Only restore a pre-existing TLS vhost. An interrupted HTTP challenge is disposable.
            self.change_site(row, previous if previous and b"ssl_certificate " in previous else None)
            if not cert_existed:
                self.remove_certificate(row)
            raise

    def delete(self, payload):
        domain = valid_domain(payload.get("domain"))
        server = valid_server(payload.get("server"))
        identity = parse_integer(payload.get("id"), 2147483647)
        if not self.operations.acquire(blocking=False):
            raise Rejected(409)
        try:
            with self.changed:
                row = self.proxies.get(domain)
                if not row:
                    return {"deleted": True}
                if (row["id"], row["server"]) != (identity, server):
                    raise Rejected(409)
                row["status"] = "deleting"
                self.save()
            self.change_site(row, None)
            self.remove_certificate(row)
            with self.changed:
                del self.proxies[domain]
                self.save()
            return {"deleted": True}
        finally:
            self.operations.release()

    def work(self):
        while True:
            with self.changed:
                self.changed.wait_for(lambda: any(row["status"] == "queued" for row in self.proxies.values()))
            with self.operations:
                with self.changed:
                    row = next((row for row in self.proxies.values() if row["status"] == "queued"), None)
                    if row is None:
                        continue
                    row["status"] = "working"
                    self.save()
                try:
                    self.provision(row)
                    status = "active"
                except Exception:
                    LOG.exception("Provisioning failed for %s", row["domain"])
                    status = "failed"
                with self.changed:
                    row["status"] = status
                    self.save()

    def status(self, server):
        valid_server(server)
        with self.changed:
            return {"states": {str(row["id"]): row["status"] for row in self.proxies.values() if row["server"] == server}}


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *_args):
        pass

    def setup(self):
        self.request.settimeout(15)
        super().setup()

    def dispatch(self):
        status = 200
        try:
            edge = self.server.edge
            if self.command == "GET" and self.path == "/v1/health":
                result = {"ready": True}
            elif self.command == "GET" and self.path.startswith("/v1/status/"):
                result = edge.status(self.path.removeprefix("/v1/status/"))
            elif self.command == "POST":
                operation = {"/v1/verify": edge.verify, "/v1/create": edge.queue, "/v1/delete": edge.delete}.get(self.path)
                if operation is None:
                    raise Rejected(404)
                size = int(self.headers.get("Content-Length", "0"))
                if not 0 < size <= 4096:
                    raise Rejected(400)
                payload = json.loads(self.rfile.read(size))
                if not isinstance(payload, dict):
                    raise Rejected(400)
                result = operation(payload)
            else:
                raise Rejected(404)
        except Rejected as error:
            status, result = error.status, {"error": True}
        except (ValueError, TimeoutError):
            status, result = 400, {"error": True}
        except Exception:
            LOG.exception("Connector request failed")
            status, result = 503, {"error": True}
        body = json.dumps(result).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    do_GET = do_POST = dispatch


class Server(socketserver.ThreadingUnixStreamServer):
    daemon_threads = True
    request_queue_size = 24


def main():
    logging.basicConfig(level=logging.INFO)
    os.umask(0o077)
    policy = json.loads(CONFIG.read_text())
    with (SOCKET.parent / "instance.lock").open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        SOCKET.unlink(missing_ok=True)
        edge = Edge(policy)
        with Server(str(SOCKET), Handler) as server:
            server.edge = edge
            os.chown(SOCKET, 0, grp.getgrnam(policy.get("socket_group", "www-data")).gr_gid)
            os.chmod(SOCKET, 0o660)
            worker = threading.Thread(target=edge.work, daemon=True)
            worker.start()
            server.serve_forever()


if __name__ == "__main__":
    main()
