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

$debugLog = [];
$addLog = function (string $message) use (&$debugLog): void {
    $debugLog[] = date('[Y-m-d H:i:s] ') . $message;
};

$addLog('LDAP Test Started');

if (!function_exists('ldap_connect')) {
    $addLog('ERROR: PHP LDAP extension is not enabled.');
}

$env = loadEnvFile(__DIR__ . '/.env');
$adConfig = [
    'host' => $env['TC2_CONFIG_LDAP_HOST'] ?? '',
    'port' => $env['TC2_CONFIG_LDAP_PORT'] ?? '',
    'alternative_host' => $env['TC2_CONFIG_LDAP_HOST_OPTIONAL'] ?? '',
    'dn' => $env['TC2_CONFIG_LDAP_DN'] ?? '',
    'domain' => $env['TC2_CONFIG_LDAP_DOMAIN'] ?? '',
    'user' => $env['TC2_CONFIG_LDAP_USERNAME'] ?? '',
    'pass' => $env['TC2_CONFIG_LDAP_PASSWORD'] ?? '',
    'ca_cert_file' => $env['TC2_CONFIG_LDAP_TLS_CA_CERT_FILE'] ?? '',
];

$addLog('AD Config loaded - Host: ' . ($adConfig['host'] !== '' ? $adConfig['host'] : 'N/A'));
$addLog('AD Config - Domain: ' . ($adConfig['domain'] !== '' ? $adConfig['domain'] : 'N/A'));
$addLog('AD Config - Port: ' . ($adConfig['port'] !== '' ? $adConfig['port'] : 'N/A'));

if ($adConfig['ca_cert_file'] !== '') {
    ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_HARD);
    ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, $adConfig['ca_cert_file']);
    $addLog('CA cert file configured: ' . $adConfig['ca_cert_file']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('ldap_connect')) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $addLog('Attempting to authenticate user: ' . htmlspecialchars($username));

    try {
        if ($adConfig['host'] === '') {
            $addLog('ERROR: LDAP host missing in .env');
        } else {
            if ($adConfig['port'] !== '') {
                $ad = ldap_connect($adConfig['host'], (int)$adConfig['port']);
                $addLog('Connecting to: ' . $adConfig['host'] . ':' . $adConfig['port']);
            } else {
                $ad = ldap_connect($adConfig['host']);
                $addLog('Connecting to: ' . $adConfig['host']);
            }

            if (!$ad) {
                $addLog('ERROR: Could not connect to LDAP server');
            } else {
                $addLog('LDAP connection established');

                ldap_set_option($ad, LDAP_OPT_PROTOCOL_VERSION, 3);
                ldap_set_option($ad, LDAP_OPT_REFERRALS, 0);
                $addLog('LDAP options set (Protocol v3, Referrals off)');

                if ($adConfig['ca_cert_file'] !== '') {
                    if (!ldap_start_tls($ad)) {
                        $addLog('ERROR: Could not start TLS - ' . ldap_error($ad));
                    } else {
                        $addLog('TLS started successfully');
                    }
                }

                $userProvidedLoginWithAt = strpos($username, '@') !== false;
                $loginUsername = $userProvidedLoginWithAt || $adConfig['domain'] === ''
                    ? $username
                    : $username . '@' . $adConfig['domain'];

                $addLog('Attempting authentication with: ' . htmlspecialchars($loginUsername));

                if (!ldap_bind($ad, $loginUsername, $password)) {
                    $error = "Couldn't authenticate LDAP user " . htmlspecialchars($loginUsername) . '. ';
                    $error .= ldap_error($ad);

                    $err = '';
                    ldap_get_option($ad, LDAP_OPT_DIAGNOSTIC_MESSAGE, $err);
                    if ($err) {
                        $error .= ' | Diagnostic: ' . $err;
                    }

                    $addLog('ERROR: Authentication failed - ' . $error);
                } else {
                    $addLog('SUCCESS: User authenticated successfully!');
                }

                ldap_close($ad);
                $addLog('LDAP connection closed');
            }
        }
    } catch (Throwable $e) {
        $addLog('EXCEPTION: ' . $e->getMessage());
        $addLog('Trace: ' . $e->getTraceAsString());
    }
}

?>
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
                <input type="text" id="username" name="username" placeholder="user or user@domain.com" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit">Test Authentication</button>
        </form>

        <?php if (!empty($debugLog)): ?>
        <div class="debug-log">
            <strong style="display: block; margin-bottom: 10px; color: #fff;">Debug Log:</strong>
            <pre><?php
            foreach ($debugLog as $log) {
                $class = '';
                if (stripos($log, 'ERROR') !== false || stripos($log, 'EXCEPTION') !== false) {
                    $class = 'error';
                } elseif (stripos($log, 'SUCCESS') !== false) {
                    $class = 'success';
                }
                echo '<div class="log-entry ' . $class . '">' . htmlspecialchars($log) . '</div>';
            }
            ?></pre>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
