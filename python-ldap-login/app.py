from __future__ import annotations

import datetime
import ssl
from dataclasses import dataclass
from typing import Dict, List

from flask import Flask, render_template_string, request

try:
    from ldap3 import Connection, Server, Tls
    from ldap3.core.exceptions import LDAPException
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
            tls_config = None
            if ca_cert_file:
                tls_config = Tls(ca_certs_file=ca_cert_file, validate=ssl.CERT_REQUIRED)

            try:
                server = Server(host, port=port, use_ssl=use_ssl, tls=tls_config)
                connection = Connection(
                    server,
                    user=login_username,
                    password=password,
                    auto_bind=False,
                )

                if not connection.open():
                    add_log(logs, "ERROR: Could not connect to LDAP server", "error")
                else:
                    add_log(logs, f"LDAP connection established ({host}:{port})")

                    if tls_config and not use_ssl:
                        if connection.start_tls():
                            add_log(logs, "TLS started successfully")
                        else:
                            add_log(
                                logs,
                                f"ERROR: Could not start TLS - {connection.last_error}",
                                "error",
                            )

                    if connection.bind():
                        add_log(logs, "SUCCESS: User authenticated successfully!", "success")
                    else:
                        diagnostic = connection.result.get("message") or connection.last_error
                        add_log(
                            logs,
                            f"ERROR: Authentication failed - {diagnostic}",
                            "error",
                        )
            except LDAPException as exc:
                add_log(logs, f"EXCEPTION: {exc}", "error")
            except OSError as exc:
                add_log(logs, f"EXCEPTION: {exc}", "error")
            finally:
                try:
                    connection.unbind()
                    add_log(logs, "LDAP connection closed")
                except Exception:
                    pass

    return render_template_string(
        HTML_TEMPLATE,
        logs=logs,
        username=request.form.get("username", "").strip(),
    )


if __name__ == "__main__":
    app.run(host="127.0.0.1", port=8001, debug=False)
