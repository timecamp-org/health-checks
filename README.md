# Health Checks

This repo contains small health-check utilities.

## PHP LDAP Login

Run the LDAP test page:

```
cp php-ldap-login/.env.sample php-ldap-login/.env
php -S 127.0.0.1:8000 -t php-ldap-login
```

Then open `http://127.0.0.1:8000/`.

## Python LDAP Login

Run the LDAP test page:

```
python3 -m venv .venv
source .venv/bin/activate
pip install -r python-ldap-login/requirements.txt
cp python-ldap-login/.env.sample python-ldap-login/.env
python python-ldap-login/app.py
```

Then open `http://127.0.0.1:8001/`.
