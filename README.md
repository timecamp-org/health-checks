# Health Checks

This repo contains small health-check utilities.

## PHP LDAP Login

Run the LDAP test page:

```
cp php-ldap-login/.env.sample php-ldap-login/.env
php -S 127.0.0.1:8000 -t php-ldap-login
```

Then open `http://127.0.0.1:8000/`.
