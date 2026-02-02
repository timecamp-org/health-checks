<?php

declare(strict_types=1);

function loadEnvFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $env = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        $value = trim($parts[1]);

        if ($value !== '' && $value[0] === '"' && substr($value, -1) === '"') {
            $value = substr($value, 1, -1);
        }

        $env[$key] = $value;
    }

    return $env;
}

function parseBool(string $value): bool
{
    $normalized = strtolower(trim($value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function parsePort(string $value): ?int
{
    if ($value === '') {
        return null;
    }

    if (!ctype_digit($value)) {
        return null;
    }

    return (int) $value;
}

function connectLdap(array $adConfig, callable $addLog)
{
    $port = $adConfig['port'];
    $host = $adConfig['host'];
    if ($host === '') {
        $addLog('ERROR: LDAP host missing in configuration.');
        return false;
    }

    if ($port !== null) {
        $addLog('Connecting to: ' . $host . ':' . $port);
        return @ldap_connect($host, $port);
    }

    $addLog('Connecting to: ' . $host);
    return @ldap_connect($host);
}

function getLdapUserPrincipal($ad, array $adConfig, string $value, string $field = 'samaccountname'): ?string
{
    $dn = $adConfig['dn'];
    if ($dn === '') {
        return null;
    }

    $attrSearchUsers = [
        'sAMAccountName',
        'distinguishedname',
        'userprincipalname',
        'memberOf',
        'userAccountControl',
        'mail',
    ];

    ldap_set_option($ad, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ad, LDAP_OPT_REFERRALS, 0);

    $bindUser = $adConfig['user'];
    $bindPass = $adConfig['pass'];
    $bindDomain = $adConfig['domain'];
    $bindWithDomain = $bindUser !== '' && $bindDomain !== '' ? $bindUser . '@' . $bindDomain : '';

    if ($bindUser === '' || $bindPass === '') {
        return null;
    }

    if (@ldap_bind($ad, $bindUser, $bindPass) || ($bindWithDomain !== '' && @ldap_bind($ad, $bindWithDomain, $bindPass))) {
        $ldapSearchUsers = "($field=$value)";
        $searchUsers = @ldap_search($ad, $dn, $ldapSearchUsers, $attrSearchUsers);
        if ($searchUsers === false) {
            return null;
        }
        $result = ldap_get_entries($ad, $searchUsers);
        if ($result['count'] > 0 && isset($result[0]['userprincipalname'][0])) {
            return $result[0]['userprincipalname'][0];
        }
    }

    return null;
}

function authenticateLdapUser($ad, string $username, string $password): array
{
    if (@ldap_bind($ad, $username, $password)) {
        return [true, ''];
    }

    $error = "Couldn't authenticate LDAP user $username. " . formatLdapErrorDetails($ad);

    return [false, $error];
}

function formatLdapErrorDetails($ad): string
{
    $error = ldap_error($ad);
    $errno = ldap_errno($ad);
    $diagnostic = '';
    $errorString = '';

    ldap_get_option($ad, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diagnostic);
    if (defined('LDAP_OPT_ERROR_STRING')) {
        ldap_get_option($ad, LDAP_OPT_ERROR_STRING, $errorString);
    }

    $parts = [];
    if ($error !== '') {
        $parts[] = $error;
    }
    if ($errno !== 0) {
        $parts[] = 'errno=' . $errno;
    }
    if ($diagnostic !== '') {
        $parts[] = 'diagnostic=' . $diagnostic;
    }
    if ($errorString !== '' && $errorString !== $error) {
        $parts[] = 'error_string=' . $errorString;
    }

    return implode('; ', $parts);
}

function logCaCertDetails(string $path, callable $addLog): void
{
    if ($path === '') {
        $addLog('No CA certificate configured - using system certificates');
        return;
    }

    if (!is_file($path)) {
        $addLog('ERROR: CA certificate file not found: ' . $path);
        return;
    }

    if (!is_readable($path)) {
        $addLog('ERROR: Cannot read CA certificate file (permission denied): ' . $path);
        return;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        $addLog('ERROR: Failed reading CA certificate file: ' . $path);
        return;
    }

    if (strpos($contents, 'BEGIN CERTIFICATE') !== false) {
        $addLog('CA certificate file format: PEM (valid)');
    } else {
        $addLog('WARNING: CA certificate may not be in PEM format');
    }
}

function testDnsResolution(string $host, callable $addLog): void
{
    $addLog('Resolving hostname: ' . $host);

    $ips = [];
    if (function_exists('dns_get_record')) {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                } elseif (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
    }

    if (empty($ips) && function_exists('gethostbynamel')) {
        $resolved = @gethostbynamel($host);
        if (is_array($resolved)) {
            $ips = array_merge($ips, $resolved);
        }
    }

    $ips = array_values(array_unique(array_filter($ips)));
    if (empty($ips)) {
        $addLog('ERROR: DNS resolution failed');
        return;
    }

    $addLog('DNS resolved to: ' . implode(', ', $ips));
}

function testTcpConnectivity(string $host, int $port, callable $addLog): void
{
    $addLog('Testing TCP connectivity to ' . $host . ':' . $port);

    $errno = 0;
    $errstr = '';
    $start = microtime(true);
    $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 10);
    $elapsedMs = (microtime(true) - $start) * 1000;

    if ($socket) {
        fclose($socket);
        $addLog('TCP connection successful to ' . $host . ':' . $port . sprintf(' (%.0fms)', $elapsedMs));
        return;
    }

    if ($errno === 110) {
        $addLog('ERROR: TCP connection timed out to ' . $host . ':' . $port . sprintf(' (%.0fms)', $elapsedMs));
        return;
    }

    $detail = $errstr !== '' ? $errstr : 'Unknown socket error';
    $addLog('ERROR: TCP connection failed - ' . $detail . ' (errno=' . $errno . ')');
}

function runFailureDiagnostics(array $adConfig, callable $addLog, string $label): void
{
    $addLog('--- Additional diagnostics (' . $label . ') ---');

    $isLdaps = $adConfig['port'] === 636;
    $tlsAllowInsecure = $adConfig['tls_allow_insecure'];

    $addLog('TLS mode: ' . ($isLdaps ? 'LDAPS (implicit SSL)' : 'LDAP (plain, no TLS)'));
    logCaCertDetails($adConfig['ca_cert_file'], $addLog);

    if ($adConfig['ca_cert_file'] !== '') {
        $addLog('Planned OPT_X_TLS_CACERTFILE: ' . $adConfig['ca_cert_file']);
        $addLog('Planned OPT_X_TLS_REQUIRE_CERT: HARD');
    } elseif ($isLdaps && $tlsAllowInsecure) {
        $addLog('Planned OPT_X_TLS_REQUIRE_CERT: ALLOW');
    } elseif ($isLdaps) {
        $addLog('Planned OPT_X_TLS_REQUIRE_CERT: HARD (default)');
    } else {
        $addLog('TLS disabled for plain LDAP connection');
    }

    $portForChecks = $adConfig['port'] ?? 389;
    if ($adConfig['port'] === null) {
        $addLog('No LDAP port configured, using default 389 for connectivity checks.');
    }

    if ($adConfig['host'] !== '') {
        testDnsResolution($adConfig['host'], $addLog);
        testTcpConnectivity($adConfig['host'], $portForChecks, $addLog);
    }
}

function checkAdCredential(string $username, string $password, array $adConfig, callable $addLog): bool
{
    $isLdaps = $adConfig['port'] === 636;
    $tlsAllowInsecure = $adConfig['tls_allow_insecure'];

    if ($adConfig['ca_cert_file'] !== '') {
        ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_HARD);
        ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, $adConfig['ca_cert_file']);
    } elseif ($isLdaps && $tlsAllowInsecure) {
        ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_ALLOW);
        $addLog('TLS allow insecure enabled for LDAPS.');
    }

    $ad = connectLdap($adConfig, $addLog);
    if (!$ad) {
        $addLog('ERROR: Could not connect to LDAP server');
        return false;
    }

    // $addLog('LDAP connection established');

    ldap_set_option($ad, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ad, LDAP_OPT_REFERRALS, 0);
    $addLog('Set protocol version: LDAPv3');
    $addLog('Set referrals: off');
    if (defined('LDAP_OPT_NETWORK_TIMEOUT')) {
        ldap_set_option($ad, LDAP_OPT_NETWORK_TIMEOUT, 10);
        $addLog('Set network timeout: 10 seconds');
    }

    if ($adConfig['ca_cert_file'] !== '') {
        if (!ldap_start_tls($ad)) {
            $addLog('ERROR: Could not start TLS - ' . formatLdapErrorDetails($ad));
            return false;
        }
        $addLog('TLS started successfully');
    }

    $userProvidedLoginWithAt = strpos($username, '@') !== false;
    $errorMessages = [];

    if (!$userProvidedLoginWithAt) {
        [$ok, $error] = authenticateLdapUser($ad, $username . '@' . $adConfig['domain'], $password);
        if ($ok) {
            return true;
        }
        if ($error !== '') {
            $errorMessages[] = $error;
        }

        $rawPassword = $_POST['password'] ?? '';
        if ($rawPassword !== '') {
            [$ok, $error] = authenticateLdapUser($ad, $username . '@' . $adConfig['domain'], $rawPassword);
            if ($ok) {
                return true;
            }
            if ($error !== '') {
                $errorMessages[] = $error;
            }
        }
    }

    if ($userProvidedLoginWithAt) {
        [$ok, $error] = authenticateLdapUser($ad, $username, $password);
        if ($ok) {
            return true;
        }
        if ($error !== '') {
            $errorMessages[] = $error;
        }

        $rawPassword = $_POST['password'] ?? '';
        if ($rawPassword !== '') {
            [$ok, $error] = authenticateLdapUser($ad, $username, $rawPassword);
            if ($ok) {
                return true;
            }
            if ($error !== '') {
                $errorMessages[] = $error;
            }
        }
    }

    if (!$userProvidedLoginWithAt) {
        $trimmedPassword = substr($password, 0, -1);
        [$ok, $error] = authenticateLdapUser($ad, $username . '@' . $adConfig['domain'], $trimmedPassword);
        if ($ok) {
            return true;
        }
        if ($error !== '') {
            $errorMessages[] = $error;
        }
    }

    $userPrincipal = getLdapUserPrincipal($ad, $adConfig, $username);
    if ($userPrincipal) {
        [$ok, $error] = authenticateLdapUser($ad, $userPrincipal, $password);
        if ($ok) {
            return true;
        }
        if ($error !== '') {
            $errorMessages[] = $error;
        }
    }

    if ($userProvidedLoginWithAt) {
        $userPrincipal = getLdapUserPrincipal($ad, $adConfig, $username, 'mail');
        if ($userPrincipal) {
            [$ok, $error] = authenticateLdapUser($ad, $userPrincipal, $password);
            if ($ok) {
                return true;
            }
            if ($error !== '') {
                $errorMessages[] = $error;
            }
        }
    }

    if (!empty($errorMessages)) {
        $addLog('ERROR: Authentication failed - ' . implode(' | ', $errorMessages));
    }

    return false;
}

function promptLine(string $label): string
{
    if (function_exists('readline')) {
        $value = readline($label);
        return trim($value ?? '');
    }

    echo $label;
    $value = fgets(STDIN);
    return trim($value ?? '');
}

function promptPassword(string $label): string
{
    if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
        echo $label;
        $sttyMode = shell_exec('stty -g');
        shell_exec('stty -echo');
        $value = fgets(STDIN);
        if (is_string($sttyMode)) {
            shell_exec('stty ' . trim($sttyMode));
        } else {
            shell_exec('stty echo');
        }
        echo PHP_EOL;
        return trim($value ?? '');
    }

    return promptLine($label);
}

$addLog = function (string $message): void {
    echo date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
};

$addLog('LDAP CLI Test Started');

if (!function_exists('ldap_connect')) {
    $addLog('ERROR: PHP LDAP extension is not enabled.');
    exit(1);
}

$env = loadEnvFile(__DIR__ . '/.env');
$portRaw = trim($env['TC2_CONFIG_LDAP_PORT'] ?? '');
$port = parsePort($portRaw);

if ($portRaw !== '' && $port === null) {
    $addLog('ERROR: Invalid LDAP port: ' . $portRaw);
}

$adConfig = [
    'host' => trim($env['TC2_CONFIG_LDAP_HOST'] ?? ''),
    'port' => $port,
    'dn' => trim($env['TC2_CONFIG_LDAP_DN'] ?? ''),
    'domain' => trim($env['TC2_CONFIG_LDAP_DOMAIN'] ?? ''),
    'user' => trim($env['TC2_CONFIG_LDAP_USERNAME'] ?? ''),
    'pass' => trim($env['TC2_CONFIG_LDAP_PASSWORD'] ?? ''),
    'ca_cert_file' => trim($env['TC2_CONFIG_LDAP_TLS_CA_CERT_FILE'] ?? ''),
    'tls_allow_insecure' => parseBool($env['TC2_CONFIG_LDAP_TLS_ALLOW_INSECURE'] ?? ''),
];

$addLog('AD Config loaded - Host: ' . ($adConfig['host'] !== '' ? $adConfig['host'] : 'N/A'));
$addLog('AD Config - Domain: ' . ($adConfig['domain'] !== '' ? $adConfig['domain'] : 'N/A'));
$addLog('AD Config - Port: ' . ($adConfig['port'] !== null ? $adConfig['port'] : 'N/A'));

if ($adConfig['ca_cert_file'] !== '') {
    $addLog('CA cert file configured: ' . $adConfig['ca_cert_file']);
    if (!is_file($adConfig['ca_cert_file'])) {
        $addLog('WARNING: CA cert file not found on disk.');
    }
}

$username = promptLine('Username: ');
$password = promptPassword('Password: ');

$addLog('Attempting to authenticate user: ' . $username);

if ($adConfig['host'] === '' && $alternativeConfig['host'] === '') {
    $addLog('ERROR: LDAP host missing in .env');
    exit(1);
}

if ($username === '' || $password === '') {
    $addLog('ERROR: Username or password missing');
    exit(1);
}

$result = checkAdCredential($username, $password, $adConfig, $addLog);
if ($result) {
    $addLog('SUCCESS: User authenticated successfully!');
    exit(0);
}

$addLog('Running post-failure diagnostics.');
runFailureDiagnostics($adConfig, $addLog, 'primary config');

$addLog('ERROR: Authentication failed.');
exit(2);
