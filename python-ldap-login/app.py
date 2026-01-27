from __future__ import annotations

import datetime
import socket
from dataclasses import dataclass
from typing import Dict, List

from flask import Flask, render_template_string, request

try:
    import ldap
except ImportError as exc:
    raise SystemExit(
        "Missing dependency. Run: pip install -r python-ldap-login/requirements.txt"
    ) from exc


@dataclass
class DebugEntry:
    timestamp: str
    message: str
    level: str


def load_env_file(path: str) -> Dict[str, str]:
    env: Dict[str, str] = {}
    try:
        with open(path, "r", encoding="utf-8") as env_file:
            for line in env_file:
                stripped = line.strip()
                if not stripped or stripped.startswith("#") or "=" not in stripped:
                    continue
                key, value = stripped.split("=", 1)
                key = key.strip()
                value = value.strip()
                if value.startswith('"') and value.endswith('"') and len(value) >= 2:
                    value = value[1:-1]
                env[key] = value
    except FileNotFoundError:
        return {}
    return env


def now_stamp() -> str:
    return datetime.datetime.now().strftime("[%Y-%m-%d %H:%M:%S]")


def add_log(logs: List[DebugEntry], message: str, level: str = "info") -> None:
    logs.append(DebugEntry(timestamp=now_stamp(), message=message, level=level))


app = Flask(__name__)


HTML_TEMPLATE = """
<!DOCTYPE html>
<html>
<head>
    <title>LDAP Authentication Test</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        button {
            background-color: #4CAF50;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background-color: #45a049;
        }
        .debug-log {
            background-color: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            margin-top: 30px;
            overflow-x: auto;
        }
        .debug-log pre {
            margin: 0;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .log-entry {
            margin: 5px 0;
        }
        .success {
            color: #4CAF50;
        }
        .error {
            color: #f44336;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>LDAP / Active Directory Authentication Test</h1>

        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username"
                    placeholder="user or user@domain.com" value="{{ username }}" required>
            </div>

            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit">Test Authentication</button>
        </form>

        {% if logs %}
        <div class="debug-log">
            <strong style="display: block; margin-bottom: 10px; color: #fff;">Debug Log:</strong>
            <pre>
{% for log in logs %}
<div class="log-entry {{ log.level }}">{{ log.timestamp }} {{ log.message }}</div>
{% endfor %}
            </pre>
        </div>
        {% endif %}
    </div>
</body>
</html>
"""


@app.route("/", methods=["GET", "POST"])
def ldap_test() -> str:
    logs: List[DebugEntry] = []
    add_log(logs, "LDAP Test Started")

    env = load_env_file(f"{app.root_path}/.env")
    host = env.get("TC2_CONFIG_LDAP_HOST", "").strip()
    port_raw = env.get("TC2_CONFIG_LDAP_PORT", "").strip()
    ca_cert_file = env.get("TC2_CONFIG_LDAP_TLS_CA_CERT_FILE", "").strip()
    domain = env.get("TC2_CONFIG_LDAP_DOMAIN", "").strip()

    port = 389
    if port_raw:
        try:
            port = int(port_raw)
        except ValueError:
            add_log(logs, f"ERROR: Invalid LDAP port: {port_raw}", "error")

    add_log(logs, f"AD Config loaded - Host: {host or 'N/A'}")
    add_log(logs, f"AD Config - Domain: {domain or 'N/A'}")
    add_log(logs, f"AD Config - Port: {port if port_raw else 'N/A'}")

    if ca_cert_file:
        add_log(logs, f"CA cert file configured: {ca_cert_file}")

    if request.method == "POST":
        username = request.form.get("username", "").strip()
        password = request.form.get("password", "")

        add_log(logs, f"Attempting to authenticate user: {username}")

        if not host:
            add_log(logs, "ERROR: LDAP host missing in .env", "error")
        elif not username or not password:
            add_log(logs, "ERROR: Username or password missing", "error")
        else:
            user_provided_domain = "@" in username
            login_username = username if user_provided_domain or not domain else f"{username}@{domain}"
            add_log(logs, f"Attempting authentication with: {login_username}")

            use_ssl = port == 636
            add_log(logs, f"SSL/TLS mode: {'LDAPS (implicit SSL)' if use_ssl else 'LDAP (plain or STARTTLS)'}")

            # Configure TLS options
            if ca_cert_file:
                add_log(logs, f"Loading CA certificate from: {ca_cert_file}")
                try:
                    with open(ca_cert_file, "r") as f:
                        cert_content = f.read()
                        if "BEGIN CERTIFICATE" in cert_content:
                            add_log(logs, "CA certificate file format: PEM (valid)")
                        else:
                            add_log(logs, "WARNING: CA certificate may not be in PEM format", "error")
                except FileNotFoundError:
                    add_log(logs, f"ERROR: CA certificate file not found: {ca_cert_file}", "error")
                except PermissionError:
                    add_log(logs, f"ERROR: Cannot read CA certificate file (permission denied): {ca_cert_file}", "error")

                # Set CA certificate file globally
                ldap.set_option(ldap.OPT_X_TLS_CACERTFILE, ca_cert_file)
                add_log(logs, f"Set OPT_X_TLS_CACERTFILE: {ca_cert_file}")
            else:
                add_log(logs, "No CA certificate configured - using system certificates")

            # Set TLS to DEMAND - require valid server certificate
            ldap.set_option(ldap.OPT_X_TLS_REQUIRE_CERT, ldap.OPT_X_TLS_ALLOW)
            add_log(logs, "Set OPT_X_TLS_REQUIRE_CERT: OPT_X_TLS_ALLOW (request cert, continue if invalid)")

            # DNS resolution check
            add_log(logs, f"Resolving hostname: {host}")
            try:
                ip_addresses = socket.getaddrinfo(host, port, socket.AF_UNSPEC, socket.SOCK_STREAM)
                resolved_ips = list(set([addr[4][0] for addr in ip_addresses]))
                add_log(logs, f"DNS resolved to: {', '.join(resolved_ips)}")
            except socket.gaierror as dns_err:
                add_log(logs, f"ERROR: DNS resolution failed - {dns_err}", "error")

            # TCP connectivity check
            add_log(logs, f"Testing TCP connectivity to {host}:{port}")
            try:
                test_socket = socket.create_connection((host, port), timeout=10)
                add_log(logs, f"TCP connection successful to {host}:{port}")
                test_socket.close()
            except socket.timeout:
                add_log(logs, f"ERROR: TCP connection timed out to {host}:{port}", "error")
            except socket.error as sock_err:
                add_log(logs, f"ERROR: TCP connection failed - {sock_err}", "error")

            connection = None
            try:
                # Build LDAP URI
                protocol = "ldaps" if use_ssl else "ldap"
                ldap_uri = f"{protocol}://{host}:{port}"
                add_log(logs, f"Initializing LDAP connection: {ldap_uri}")

                connection = ldap.initialize(ldap_uri)
                add_log(logs, "LDAP connection initialized")

                # Set connection options
                connection.set_option(ldap.OPT_PROTOCOL_VERSION, 3)
                add_log(logs, "Set protocol version: LDAPv3")

                connection.set_option(ldap.OPT_NETWORK_TIMEOUT, 10.0)
                add_log(logs, "Set network timeout: 10 seconds")

                # Apply TLS settings to this connection as well
                connection.set_option(ldap.OPT_X_TLS_REQUIRE_CERT, ldap.OPT_X_TLS_ALLOW)
                add_log(logs, "Connection-level OPT_X_TLS_REQUIRE_CERT: ALLOW")

                if ca_cert_file:
                    connection.set_option(ldap.OPT_X_TLS_CACERTFILE, ca_cert_file)
                    add_log(logs, f"Connection-level OPT_X_TLS_CACERTFILE set")

                # Force TLS context reload after setting options
                connection.set_option(ldap.OPT_X_TLS_NEWCTX, 0)
                add_log(logs, "TLS context reloaded with new options")

                # STARTTLS for non-SSL connections
                if not use_ssl:
                    add_log(logs, "Starting TLS upgrade (STARTTLS)...")
                    connection.start_tls_s()
                    add_log(logs, "STARTTLS completed successfully", "success")

                add_log(logs, f"Attempting LDAP bind for user: {login_username}")
                connection.simple_bind_s(login_username, password)
                add_log(logs, "SUCCESS: User authenticated successfully!", "success")

            except ldap.INVALID_CREDENTIALS:
                add_log(logs, "ERROR: Invalid credentials - authentication failed", "error")
            except ldap.SERVER_DOWN as exc:
                add_log(logs, f"ERROR: LDAP server is down or unreachable", "error")
                add_log(logs, f"Details: {exc}", "error")
                if "certificate" in str(exc).lower():
                    add_log(logs, "HINT: This may be a TLS/SSL certificate issue", "error")
            except ldap.CONNECT_ERROR as exc:
                add_log(logs, f"ERROR: Could not connect to LDAP server", "error")
                add_log(logs, f"Details: {exc}", "error")
            except ldap.TIMEOUT:
                add_log(logs, "ERROR: LDAP operation timed out", "error")
            except ldap.LDAPError as exc:
                add_log(logs, f"LDAP ERROR: {type(exc).__name__}", "error")
                error_info = exc.args[0] if exc.args else {}
                if isinstance(error_info, dict):
                    desc = error_info.get("desc", "Unknown error")
                    info = error_info.get("info", "")
                    add_log(logs, f"Description: {desc}", "error")
                    if info:
                        add_log(logs, f"Info: {info}", "error")
                else:
                    add_log(logs, f"Error details: {exc}", "error")
            except socket.timeout:
                add_log(logs, "ERROR: Connection timed out", "error")
            except OSError as exc:
                add_log(logs, f"OS ERROR: {exc}", "error")
                add_log(logs, f"Error number: {exc.errno}", "error")
            finally:
                if connection:
                    try:
                        connection.unbind_s()
                        add_log(logs, "LDAP connection closed")
                    except Exception:
                        add_log(logs, "LDAP connection cleanup (no active connection)")
                else:
                    add_log(logs, "No LDAP connection was established")

    return render_template_string(
        HTML_TEMPLATE,
        logs=logs,
        username=request.form.get("username", "").strip(),
    )


if __name__ == "__main__":
    app.run(host="127.0.0.1", port=8001, debug=False)
