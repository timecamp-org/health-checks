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

function parseInt(string $value): ?int
{
    if ($value === '' || !ctype_digit($value)) {
        return null;
    }

    return (int) $value;
}

function parseList(string $value): array
{
    if ($value === '') {
        return [];
    }

    $items = array_map('trim', explode(',', $value));
    return array_values(array_filter($items, fn(string $item) => $item !== ''));
}

function isValidEmail(string $value): bool
{
    return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
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

function getSSLContextOptionsForSkippingPeerVerification(): array
{
    return [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];
}

function loadSmtpConfig(array $env): array
{
    return [
        'protocol' => strtolower(trim($env['TC2_CONFIG_SMTP_PROTOCOL'] ?? 'smtp')),
        'smtp_host' => trim($env['TC2_CONFIG_SMTP_HOST'] ?? ''),
        'smtp_user' => trim($env['TC2_CONFIG_SMTP_USER'] ?? ''),
        'smtp_pass' => trim($env['TC2_CONFIG_SMTP_PASS'] ?? ''),
        'smtp_port' => parseInt(trim($env['TC2_CONFIG_SMTP_PORT'] ?? '')),
        'smtp_timeout' => parseInt(trim($env['TC2_CONFIG_SMTP_TIMEOUT'] ?? '')),
        'smtp_crypto' => trim($env['TC2_CONFIG_SMTP_CRYPTO'] ?? ''),
        'smtp_user_in_from_field' => parseBool($env['TC2_CONFIG_SMTP_USER_IN_FROM_FIELD'] ?? ''),
        'allowed_email_from' => parseList(trim($env['TC2_CONFIG_ALLOWED_EMAIL_FROM'] ?? '')),
        'email_team' => trim($env['TC2_CONFIG_EMAIL_TEAM'] ?? ''),
        'auth_email_from_name' => trim($env['TC2_CONFIG_AUTH_EMAIL_FROM_NAME'] ?? ''),
        'skip_ssl_certificate_verification' => parseBool($env['TC2_CONFIG_SKIP_SSL_CERT_VERIFICATION'] ?? ''),
        'use_exchange' => parseBool($env['TC2_CONFIG_USE_EXCHANGE'] ?? ''),
        'tag_custom_header' => trim($env['TC2_CONFIG_TAG_CUSTOM_HEADER'] ?? ''),
    ];
}

function loadMessageConfig(array $env): array
{
    return [
        'subject' => trim($env['TC2_CONFIG_SMTP_SUBJECT'] ?? ''),
        'message' => trim($env['TC2_CONFIG_SMTP_MESSAGE'] ?? ''),
        'email_from' => trim($env['TC2_CONFIG_SMTP_FROM_EMAIL'] ?? ''),
        'name_from' => trim($env['TC2_CONFIG_SMTP_FROM_NAME'] ?? ''),
        'reply_to' => trim($env['TC2_CONFIG_SMTP_REPLY_TO'] ?? ''),
        'tag' => trim($env['TC2_CONFIG_SMTP_TAG'] ?? ''),
        'attachment' => trim($env['TC2_CONFIG_SMTP_ATTACHMENT'] ?? ''),
        'is_html' => parseBool($env['TC2_CONFIG_SMTP_IS_HTML'] ?? ''),
    ];
}

function sendEmailWithPhpMailer(array $smtpConfig, array $payload, callable $addLog): bool
{
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        $addLog('ERROR: PHPMailer not available. Run "composer require phpmailer/phpmailer" in php-smtp.');
        return false;
    }

    $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->CharSet = 'UTF-8';
    if ($payload['is_html']) {
        $mailer->Encoding = 'base64';
        $mailer->AltBody = 'To view the message, please use an HTML compatible email viewer!';
    }
    $mailer->isHTML($payload['is_html']);

    $fromEmail = $smtpConfig['email_team'] !== '' ? $smtpConfig['email_team'] : $smtpConfig['smtp_user'];
    $fromName = $smtpConfig['auth_email_from_name'] !== '' ? $smtpConfig['auth_email_from_name'] : 'SMTP Health Check';

    if (!empty($smtpConfig['allowed_email_from']) && $payload['email_from'] !== '' && $payload['name_from'] !== '') {
        if (in_array($payload['email_from'], $smtpConfig['allowed_email_from'], true)) {
            $fromEmail = $payload['email_from'];
            $fromName = $payload['name_from'];
        } else {
            $addLog('WARNING: Provided From email is not in allowed list. Using default From.');
        }
    } elseif ($payload['email_from'] !== '' || $payload['name_from'] !== '') {
        $fromEmail = $payload['email_from'] !== '' ? $payload['email_from'] : $fromEmail;
        $fromName = $payload['name_from'] !== '' ? $payload['name_from'] : $fromName;
    }

    if ($smtpConfig['smtp_user_in_from_field'] && $smtpConfig['smtp_user'] !== '') {
        $fromEmail = $smtpConfig['smtp_user'];
    }

    $mailer->setFrom($fromEmail, $fromName);
    $mailer->Subject = $payload['subject'];
    $mailer->Body = $payload['message'];

    if ($payload['tag'] !== '' && $smtpConfig['tag_custom_header'] !== '') {
        $mailer->addCustomHeader($smtpConfig['tag_custom_header'] . ': ' . $payload['tag']);
    }

    if ($payload['reply_to'] !== '') {
        $mailer->addReplyTo($payload['reply_to']);
    }

    if ($smtpConfig['protocol'] === 'smtp') {
        $mailer->isSMTP();
        $mailer->SMTPAuth = false;
        $mailer->Host = $smtpConfig['smtp_host'];

        if ($smtpConfig['smtp_crypto'] !== '') {
            $mailer->SMTPSecure = $smtpConfig['smtp_crypto'];
        }

        if ($smtpConfig['smtp_port'] !== null) {
            $mailer->Port = $smtpConfig['smtp_port'];
        }

        if ($smtpConfig['smtp_timeout'] !== null) {
            $mailer->Timeout = $smtpConfig['smtp_timeout'];
        }

        if ($smtpConfig['smtp_user'] !== '') {
            $mailer->SMTPAuth = true;
            $mailer->Username = $smtpConfig['smtp_user'];
            if ($smtpConfig['smtp_pass'] !== '') {
                $mailer->Password = $smtpConfig['smtp_pass'];
            }
        }

        if ($smtpConfig['use_exchange']) {
            $mailer->AuthType = 'NTLM';
            $mailer->SMTPOptions = getSSLContextOptionsForSkippingPeerVerification();
            $mailer->SMTPAuth = false;
            $mailer->SMTPSecure = false;
        }
    }

    if ($smtpConfig['skip_ssl_certificate_verification']) {
        $mailer->SMTPOptions = getSSLContextOptionsForSkippingPeerVerification();
    }

    $mailer->addAddress($payload['email_to'], $payload['email_to']);

    if ($payload['attachment'] !== '') {
        $mailer->addAttachment($payload['attachment']);
    }

    try {
        $mailer->send();
        $addLog('SUCCESS: Email sent to ' . $payload['email_to']);
        return true;
    } catch (PHPMailer\PHPMailer\Exception $e) {
        $addLog('ERROR: PHPMailer exception - ' . $e->getMessage());
        return false;
    }
}

$addLog = function (string $message): void {
    echo date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
};

if (PHP_SAPI !== 'cli') {
    $addLog('ERROR: This script must be run from the command line.');
    exit(1);
}

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoloadPath)) {
    $addLog('ERROR: PHPMailer autoload not found. Run "composer require phpmailer/phpmailer" in php-smtp.');
    exit(1);
}
require $autoloadPath;

$env = loadEnvFile(__DIR__ . '/.env');
$smtpConfig = loadSmtpConfig($env);
$messageConfig = loadMessageConfig($env);

$addLog('SMTP Health Check Started');
$addLog('SMTP Config loaded - Host: ' . ($smtpConfig['smtp_host'] !== '' ? $smtpConfig['smtp_host'] : 'N/A'));
$addLog('SMTP Config - Port: ' . ($smtpConfig['smtp_port'] !== null ? (string) $smtpConfig['smtp_port'] : 'N/A'));
$addLog('SMTP Config - Crypto: ' . ($smtpConfig['smtp_crypto'] !== '' ? $smtpConfig['smtp_crypto'] : 'none'));
$addLog('SMTP Config - Protocol: ' . ($smtpConfig['protocol'] !== '' ? $smtpConfig['protocol'] : 'N/A'));

$recipient = promptLine('Recipient email: ');
if (!isValidEmail($recipient)) {
    $addLog('ERROR: Recipient email missing or invalid.');
    exit(2);
}

if ($smtpConfig['protocol'] === 'smtp' && $smtpConfig['smtp_host'] === '') {
    $addLog('ERROR: SMTP host missing in .env (TC2_CONFIG_SMTP_HOST).');
    exit(2);
}

$fallbackFrom = $smtpConfig['email_team'] !== '' ? $smtpConfig['email_team'] : $smtpConfig['smtp_user'];
if ($messageConfig['email_from'] === '' && $fallbackFrom === '') {
    $addLog('ERROR: From email missing. Set TC2_CONFIG_EMAIL_TEAM or TC2_CONFIG_SMTP_USER.');
    exit(2);
}

if ($messageConfig['reply_to'] !== '' && !isValidEmail($messageConfig['reply_to'])) {
    $addLog('ERROR: Reply-To email is invalid.');
    exit(2);
}

if ($messageConfig['email_from'] !== '' && !isValidEmail($messageConfig['email_from'])) {
    $addLog('ERROR: From email is invalid.');
    exit(2);
}

if ($messageConfig['attachment'] !== '' && !is_file($messageConfig['attachment'])) {
    $addLog('ERROR: Attachment file not found: ' . $messageConfig['attachment']);
    exit(2);
}

$subject = $messageConfig['subject'];
if ($subject === '') {
    $subject = 'SMTP health check ' . date('Y-m-d H:i:s');
}

$message = $messageConfig['message'];
if ($message === '') {
    $message = 'This is a test email from the PHP SMTP health check.';
}

$payload = [
    'email_to' => $recipient,
    'subject' => $subject,
    'message' => $message,
    'email_from' => $messageConfig['email_from'],
    'name_from' => $messageConfig['name_from'],
    'reply_to' => $messageConfig['reply_to'],
    'tag' => $messageConfig['tag'],
    'attachment' => $messageConfig['attachment'],
    'is_html' => $messageConfig['is_html'],
];

$success = sendEmailWithPhpMailer($smtpConfig, $payload, $addLog);
exit($success ? 0 : 3);
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

function parseInt(string $value): ?int
{
    if ($value === '' || !ctype_digit($value)) {
        return null;
    }

    return (int) $value;
}

function parseList(string $value): array
{
    if ($value === '') {
        return [];
    }

    $items = array_map('trim', explode(',', $value));
    return array_values(array_filter($items, fn(string $item) => $item !== ''));
}

function isValidEmail(string $value): bool
{
    return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
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

function getSSLContextOptionsForSkippingPeerVerification(): array
{
    return [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];
}

function loadSmtpConfig(array $env): array
{
    return [
        'protocol' => strtolower(trim($env['TC2_CONFIG_SMTP_PROTOCOL'] ?? 'smtp')),
        'smtp_host' => trim($env['TC2_CONFIG_SMTP_HOST'] ?? ''),
        'smtp_user' => trim($env['TC2_CONFIG_SMTP_USER'] ?? ''),
        'smtp_pass' => trim($env['TC2_CONFIG_SMTP_PASS'] ?? ''),
        'smtp_port' => parseInt(trim($env['TC2_CONFIG_SMTP_PORT'] ?? '')),
        'smtp_timeout' => parseInt(trim($env['TC2_CONFIG_SMTP_TIMEOUT'] ?? '')),
        'smtp_crypto' => trim($env['TC2_CONFIG_SMTP_CRYPTO'] ?? ''),
        'smtp_user_in_from_field' => parseBool($env['TC2_CONFIG_SMTP_USER_IN_FROM_FIELD'] ?? ''),
        'allowed_email_from' => parseList(trim($env['TC2_CONFIG_ALLOWED_EMAIL_FROM'] ?? '')),
        'email_team' => trim($env['TC2_CONFIG_EMAIL_TEAM'] ?? ''),
        'auth_email_from_name' => trim($env['TC2_CONFIG_AUTH_EMAIL_FROM_NAME'] ?? ''),
        'skip_ssl_certificate_verification' => parseBool($env['TC2_CONFIG_SKIP_SSL_CERT_VERIFICATION'] ?? ''),
        'use_exchange' => parseBool($env['TC2_CONFIG_USE_EXCHANGE'] ?? ''),
        'tag_custom_header' => trim($env['TC2_CONFIG_TAG_CUSTOM_HEADER'] ?? ''),
    ];
}

function loadMessageConfig(array $env): array
{
    return [
        'subject' => trim($env['TC2_CONFIG_SMTP_SUBJECT'] ?? ''),
        'message' => trim($env['TC2_CONFIG_SMTP_MESSAGE'] ?? ''),
        'email_from' => trim($env['TC2_CONFIG_SMTP_FROM_EMAIL'] ?? ''),
        'name_from' => trim($env['TC2_CONFIG_SMTP_FROM_NAME'] ?? ''),
        'reply_to' => trim($env['TC2_CONFIG_SMTP_REPLY_TO'] ?? ''),
        'tag' => trim($env['TC2_CONFIG_SMTP_TAG'] ?? ''),
        'attachment' => trim($env['TC2_CONFIG_SMTP_ATTACHMENT'] ?? ''),
        'is_html' => parseBool($env['TC2_CONFIG_SMTP_IS_HTML'] ?? ''),
    ];
}

function sendEmailWithPhpMailer(array $smtpConfig, array $payload, callable $addLog): bool
{
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        $addLog('ERROR: PHPMailer not available. Run "composer require phpmailer/phpmailer" in php-smtp.');
        return false;
    }

    $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->CharSet = 'UTF-8';
    if ($payload['is_html']) {
        $mailer->Encoding = 'base64';
        $mailer->AltBody = 'To view the message, please use an HTML compatible email viewer!';
    }
    $mailer->isHTML($payload['is_html']);

    $fromEmail = $smtpConfig['email_team'] !== '' ? $smtpConfig['email_team'] : $smtpConfig['smtp_user'];
    $fromName = $smtpConfig['auth_email_from_name'] !== '' ? $smtpConfig['auth_email_from_name'] : 'SMTP Health Check';

    if (!empty($smtpConfig['allowed_email_from']) && $payload['email_from'] !== '' && $payload['name_from'] !== '') {
        if (in_array($payload['email_from'], $smtpConfig['allowed_email_from'], true)) {
            $fromEmail = $payload['email_from'];
            $fromName = $payload['name_from'];
        } else {
            $addLog('WARNING: Provided From email is not in allowed list. Using default From.');
        }
    } elseif ($payload['email_from'] !== '' || $payload['name_from'] !== '') {
        $fromEmail = $payload['email_from'] !== '' ? $payload['email_from'] : $fromEmail;
        $fromName = $payload['name_from'] !== '' ? $payload['name_from'] : $fromName;
    }

    if ($smtpConfig['smtp_user_in_from_field'] && $smtpConfig['smtp_user'] !== '') {
        $fromEmail = $smtpConfig['smtp_user'];
    }

    $mailer->setFrom($fromEmail, $fromName);
    $mailer->Subject = $payload['subject'];
    $mailer->Body = $payload['message'];

    if ($payload['tag'] !== '' && $smtpConfig['tag_custom_header'] !== '') {
        $mailer->addCustomHeader($smtpConfig['tag_custom_header'] . ': ' . $payload['tag']);
    }

    if ($payload['reply_to'] !== '') {
        $mailer->addReplyTo($payload['reply_to']);
    }

    if ($smtpConfig['protocol'] === 'smtp') {
        $mailer->isSMTP();
        $mailer->SMTPAuth = false;
        $mailer->Host = $smtpConfig['smtp_host'];

        if ($smtpConfig['smtp_crypto'] !== '') {
            $mailer->SMTPSecure = $smtpConfig['smtp_crypto'];
        }

        if ($smtpConfig['smtp_port'] !== null) {
            $mailer->Port = $smtpConfig['smtp_port'];
        }

        if ($smtpConfig['smtp_timeout'] !== null) {
            $mailer->Timeout = $smtpConfig['smtp_timeout'];
        }

        if ($smtpConfig['smtp_user'] !== '') {
            $mailer->SMTPAuth = true;
            $mailer->Username = $smtpConfig['smtp_user'];
            if ($smtpConfig['smtp_pass'] !== '') {
                $mailer->Password = $smtpConfig['smtp_pass'];
            }
        }

        if ($smtpConfig['use_exchange']) {
            $mailer->AuthType = 'NTLM';
            $mailer->SMTPOptions = getSSLContextOptionsForSkippingPeerVerification();
            $mailer->SMTPAuth = false;
            $mailer->SMTPSecure = false;
        }
    }

    if ($smtpConfig['skip_ssl_certificate_verification']) {
        $mailer->SMTPOptions = getSSLContextOptionsForSkippingPeerVerification();
    }

    $mailer->addAddress($payload['email_to'], $payload['email_to']);

    if ($payload['attachment'] !== '') {
        $mailer->addAttachment($payload['attachment']);
    }

    try {
        $mailer->send();
        $addLog('SUCCESS: Email sent to ' . $payload['email_to']);
        return true;
    } catch (PHPMailer\PHPMailer\Exception $e) {
        $addLog('ERROR: PHPMailer exception - ' . $e->getMessage());
        return false;
    }
}

$addLog = function (string $message): void {
    echo date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
};

if (PHP_SAPI !== 'cli') {
    $addLog('ERROR: This script must be run from the command line.');
    exit(1);
}

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoloadPath)) {
    $addLog('ERROR: PHPMailer autoload not found. Run "composer require phpmailer/phpmailer" in php-smtp.');
    exit(1);
}
require $autoloadPath;

$env = loadEnvFile(__DIR__ . '/.env');
$smtpConfig = loadSmtpConfig($env);
$messageConfig = loadMessageConfig($env);

$addLog('SMTP Health Check Started');
$addLog('SMTP Config loaded - Host: ' . ($smtpConfig['smtp_host'] !== '' ? $smtpConfig['smtp_host'] : 'N/A'));
$addLog('SMTP Config - Port: ' . ($smtpConfig['smtp_port'] !== null ? (string) $smtpConfig['smtp_port'] : 'N/A'));
$addLog('SMTP Config - Crypto: ' . ($smtpConfig['smtp_crypto'] !== '' ? $smtpConfig['smtp_crypto'] : 'none'));
$addLog('SMTP Config - Protocol: ' . ($smtpConfig['protocol'] !== '' ? $smtpConfig['protocol'] : 'N/A'));

$recipient = promptLine('Recipient email: ');
if (!isValidEmail($recipient)) {
    $addLog('ERROR: Recipient email missing or invalid.');
    exit(2);
}

if ($smtpConfig['protocol'] === 'smtp' && $smtpConfig['smtp_host'] === '') {
    $addLog('ERROR: SMTP host missing in .env (TC2_CONFIG_SMTP_HOST).');
    exit(2);
}

$fallbackFrom = $smtpConfig['email_team'] !== '' ? $smtpConfig['email_team'] : $smtpConfig['smtp_user'];
if ($messageConfig['email_from'] === '' && $fallbackFrom === '') {
    $addLog('ERROR: From email missing. Set TC2_CONFIG_EMAIL_TEAM or TC2_CONFIG_SMTP_USER.');
    exit(2);
}

if ($messageConfig['reply_to'] !== '' && !isValidEmail($messageConfig['reply_to'])) {
    $addLog('ERROR: Reply-To email is invalid.');
    exit(2);
}

if ($messageConfig['email_from'] !== '' && !isValidEmail($messageConfig['email_from'])) {
    $addLog('ERROR: From email is invalid.');
    exit(2);
}

if ($messageConfig['attachment'] !== '' && !is_file($messageConfig['attachment'])) {
    $addLog('ERROR: Attachment file not found: ' . $messageConfig['attachment']);
    exit(2);
}

$subject = $messageConfig['subject'];
if ($subject === '') {
    $subject = 'SMTP health check ' . date('Y-m-d H:i:s');
}

$message = $messageConfig['message'];
if ($message === '') {
    $message = 'This is a test email from the PHP SMTP health check.';
}

$payload = [
    'email_to' => $recipient,
    'subject' => $subject,
    'message' => $message,
    'email_from' => $messageConfig['email_from'],
    'name_from' => $messageConfig['name_from'],
    'reply_to' => $messageConfig['reply_to'],
    'tag' => $messageConfig['tag'],
    'attachment' => $messageConfig['attachment'],
    'is_html' => $messageConfig['is_html'],
];

$success = sendEmailWithPhpMailer($smtpConfig, $payload, $addLog);
exit($success ? 0 : 3);
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

function parseInt(string $value): ?int
{
    if ($value === '' || !ctype_digit($value)) {
        return null;
    }

    return (int) $value;
}

function parseList(string $value): array
{
    if ($value === '') {
        return [];
    }

    $items = array_map('trim', explode(',', $value));
    return array_values(array_filter($items, fn(string $item) => $item !== ''));
}

function isValidEmail(string $value): bool
{
    return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

function getSSLContextOptionsForSkippingPeerVerification(): array
{
    return [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];
}

function loadSmtpConfig(array $env): array
{
    return [
        'protocol' => strtolower(trim($env['TC2_CONFIG_SMTP_PROTOCOL'] ?? 'smtp')),
        'smtp_host' => trim($env['TC2_CONFIG_SMTP_HOST'] ?? ''),
        'smtp_user' => trim($env['TC2_CONFIG_SMTP_USER'] ?? ''),
        'smtp_pass' => trim($env['TC2_CONFIG_SMTP_PASS'] ?? ''),
        'smtp_port' => parseInt(trim($env['TC2_CONFIG_SMTP_PORT'] ?? '')),
        'smtp_timeout' => parseInt(trim($env['TC2_CONFIG_SMTP_TIMEOUT'] ?? '')),
        'smtp_crypto' => trim($env['TC2_CONFIG_SMTP_CRYPTO'] ?? ''),
        'smtp_user_in_from_field' => parseBool($env['TC2_CONFIG_SMTP_USER_IN_FROM_FIELD'] ?? ''),
        'allowed_email_from' => parseList(trim($env['TC2_CONFIG_ALLOWED_EMAIL_FROM'] ?? '')),
        'email_team' => trim($env['TC2_CONFIG_EMAIL_TEAM'] ?? ''),
        'auth_email_from_name' => trim($env['TC2_CONFIG_AUTH_EMAIL_FROM_NAME'] ?? ''),
        'skip_ssl_certificate_verification' => parseBool($env['TC2_CONFIG_SKIP_SSL_CERT_VERIFICATION'] ?? ''),
        'use_exchange' => parseBool($env['TC2_CONFIG_USE_EXCHANGE'] ?? ''),
        'tag_custom_header' => trim($env['TC2_CONFIG_TAG_CUSTOM_HEADER'] ?? ''),
    ];
}

function sendEmailWithPhpMailer(array $smtpConfig, array $payload, callable $addLog): bool
{
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        $addLog('ERROR: PHPMailer not available. Run "composer require phpmailer/phpmailer" in php-smtp.');
        return false;
    }

    $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->CharSet = 'UTF-8';
    if ($payload['is_html']) {
        $mailer->Encoding = 'base64';
        $mailer->AltBody = 'To view the message, please use an HTML compatible email viewer!';
    }
    $mailer->isHTML($payload['is_html']);

    $fromEmail = $smtpConfig['email_team'] !== '' ? $smtpConfig['email_team'] : $smtpConfig['smtp_user'];
    $fromName = $smtpConfig['auth_email_from_name'] !== '' ? $smtpConfig['auth_email_from_name'] : 'SMTP Health Check';

    if (!empty($smtpConfig['allowed_email_from']) && $payload['email_from'] !== '' && $payload['name_from'] !== '') {
        if (in_array($payload['email_from'], $smtpConfig['allowed_email_from'], true)) {
            $fromEmail = $payload['email_from'];
            $fromName = $payload['name_from'];
        } else {
            $addLog('WARNING: Provided From email is not in allowed list. Using default From.');
        }
    } elseif ($payload['email_from'] !== '' || $payload['name_from'] !== '') {
        $fromEmail = $payload['email_from'] !== '' ? $payload['email_from'] : $fromEmail;
        $fromName = $payload['name_from'] !== '' ? $payload['name_from'] : $fromName;
    }

    if ($smtpConfig['smtp_user_in_from_field'] && $smtpConfig['smtp_user'] !== '') {
        $fromEmail = $smtpConfig['smtp_user'];
    }

    $mailer->setFrom($fromEmail, $fromName);
    $mailer->Subject = $payload['subject'];
    $mailer->Body = $payload['message'];

    if ($payload['tag'] !== '' && $smtpConfig['tag_custom_header'] !== '') {
        $mailer->addCustomHeader($smtpConfig['tag_custom_header'] . ': ' . $payload['tag']);
    }

    if ($payload['reply_to'] !== '') {
        $mailer->addReplyTo($payload['reply_to']);
    }

    if ($smtpConfig['protocol'] === 'smtp') {
        $mailer->isSMTP();
        $mailer->SMTPAuth = false;
        $mailer->Host = $smtpConfig['smtp_host'];

        if ($smtpConfig['smtp_crypto'] !== '') {
            $mailer->SMTPSecure = $smtpConfig['smtp_crypto'];
        }

        if ($smtpConfig['smtp_port'] !== null) {
            $mailer->Port = $smtpConfig['smtp_port'];
        }

        if ($smtpConfig['smtp_timeout'] !== null) {
            $mailer->Timeout = $smtpConfig['smtp_timeout'];
        }

        if ($smtpConfig['smtp_user'] !== '') {
            $mailer->SMTPAuth = true;
            $mailer->Username = $smtpConfig['smtp_user'];
            if ($smtpConfig['smtp_pass'] !== '') {
                $mailer->Password = $smtpConfig['smtp_pass'];
            }
        }

        if ($smtpConfig['use_exchange']) {
            $mailer->AuthType = 'NTLM';
            $mailer->SMTPOptions = getSSLContextOptionsForSkippingPeerVerification();
            $mailer->SMTPAuth = false;
            $mailer->SMTPSecure = false;
        }
    }

    if ($smtpConfig['skip_ssl_certificate_verification']) {
        $mailer->SMTPOptions = getSSLContextOptionsForSkippingPeerVerification();
    }

    $mailer->addAddress(
        $payload['email_to'],
        $payload['email_to_name'] !== '' ? $payload['email_to_name'] : $payload['email_to']
    );

    if ($payload['attachment'] !== '') {
        $mailer->addAttachment($payload['attachment']);
    }

    try {
        $mailer->send();
        $addLog('SUCCESS: Email sent to ' . $payload['email_to']);
        return true;
    } catch (PHPMailer\PHPMailer\Exception $e) {
        $addLog('ERROR: PHPMailer exception - ' . $e->getMessage());
        return false;
    }
}

$debugLog = [];
$addLog = function (string $message) use (&$debugLog): void {
    $debugLog[] = date('[Y-m-d H:i:s] ') . $message;
};

$addLog('SMTP Health Check Started');

$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (is_file($autoloadPath)) {
    require $autoloadPath;
} else {
    $addLog('WARNING: PHPMailer autoload not found. Run "composer require phpmailer/phpmailer" in php-smtp.');
}

$env = loadEnvFile(__DIR__ . '/.env');
$smtpConfig = loadSmtpConfig($env);

$addLog('SMTP Config loaded - Host: ' . ($smtpConfig['smtp_host'] !== '' ? $smtpConfig['smtp_host'] : 'N/A'));
$addLog('SMTP Config - Port: ' . ($smtpConfig['smtp_port'] !== null ? (string) $smtpConfig['smtp_port'] : 'N/A'));
$addLog('SMTP Config - Crypto: ' . ($smtpConfig['smtp_crypto'] !== '' ? $smtpConfig['smtp_crypto'] : 'none'));
$addLog('SMTP Config - Protocol: ' . ($smtpConfig['protocol'] !== '' ? $smtpConfig['protocol'] : 'N/A'));

$formValues = [
    'email_to' => '',
    'email_to_name' => '',
    'subject' => 'SMTP health check ' . date('Y-m-d H:i:s'),
    'message' => "This is a test email from the PHP SMTP health check.",
    'email_from' => $smtpConfig['email_team'],
    'name_from' => $smtpConfig['auth_email_from_name'],
    'reply_to' => '',
    'tag' => '',
    'attachment' => '',
    'is_html' => false,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formValues = [
        'email_to' => trim($_POST['email_to'] ?? ''),
        'email_to_name' => trim($_POST['email_to_name'] ?? ''),
        'subject' => trim($_POST['subject'] ?? ''),
        'message' => trim($_POST['message'] ?? ''),
        'email_from' => trim($_POST['email_from'] ?? ''),
        'name_from' => trim($_POST['name_from'] ?? ''),
        'reply_to' => trim($_POST['reply_to'] ?? ''),
        'tag' => trim($_POST['tag'] ?? ''),
        'attachment' => trim($_POST['attachment'] ?? ''),
        'is_html' => parseBool($_POST['is_html'] ?? ''),
    ];

    $hasError = false;

    if (!isValidEmail($formValues['email_to'])) {
        $addLog('ERROR: Recipient email missing or invalid.');
        $hasError = true;
    }

    if ($formValues['email_from'] !== '' && !isValidEmail($formValues['email_from'])) {
        $addLog('ERROR: From email is invalid.');
        $hasError = true;
    }

    if ($formValues['reply_to'] !== '' && !isValidEmail($formValues['reply_to'])) {
        $addLog('ERROR: Reply-To email is invalid.');
        $hasError = true;
    }

    if ($formValues['attachment'] !== '' && !is_file($formValues['attachment'])) {
        $addLog('ERROR: Attachment file not found: ' . $formValues['attachment']);
        $hasError = true;
    }

    if ($smtpConfig['protocol'] === 'smtp' && $smtpConfig['smtp_host'] === '') {
        $addLog('ERROR: SMTP host missing in .env (TC2_CONFIG_SMTP_HOST).');
        $hasError = true;
    }

    $fallbackFrom = $smtpConfig['email_team'] !== '' ? $smtpConfig['email_team'] : $smtpConfig['smtp_user'];
    if ($formValues['email_from'] === '' && $fallbackFrom === '') {
        $addLog('ERROR: From email missing. Set TC2_CONFIG_EMAIL_TEAM or TC2_CONFIG_SMTP_USER.');
        $hasError = true;
    }

    if (!$hasError) {
        sendEmailWithPhpMailer($smtpConfig, $formValues, $addLog);
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>SMTP Email Test</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 900px;
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
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 16px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }
        input[type="text"],
        input[type="email"],
        textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        textarea {
            min-height: 120px;
            resize: vertical;
        }
        .inline-group {
            display: flex;
            gap: 16px;
        }
        .inline-group .form-group {
            flex: 1;
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
        .config-summary {
            background-color: #f0f4ff;
            border: 1px solid #d7e0ff;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            color: #2a3c78;
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
        .warning {
            color: #ffb74d;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>SMTP / PHPMailer Test</h1>

        <div class="config-summary">
            <strong>Current SMTP Configuration:</strong><br>
            Host: <?php echo htmlspecialchars($smtpConfig['smtp_host'] ?: 'N/A'); ?><br>
            Port: <?php echo htmlspecialchars($smtpConfig['smtp_port'] !== null ? (string) $smtpConfig['smtp_port'] : 'N/A'); ?><br>
            Crypto: <?php echo htmlspecialchars($smtpConfig['smtp_crypto'] ?: 'none'); ?><br>
            Protocol: <?php echo htmlspecialchars($smtpConfig['protocol'] ?: 'N/A'); ?>
        </div>

        <form method="POST" action="">
            <div class="inline-group">
                <div class="form-group">
                    <label for="email_to">To (email):</label>
                    <input type="email" id="email_to" name="email_to" value="<?php echo htmlspecialchars($formValues['email_to']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="email_to_name">To (name):</label>
                    <input type="text" id="email_to_name" name="email_to_name" value="<?php echo htmlspecialchars($formValues['email_to_name']); ?>">
                </div>
            </div>

            <div class="inline-group">
                <div class="form-group">
                    <label for="email_from">From (email):</label>
                    <input type="email" id="email_from" name="email_from" value="<?php echo htmlspecialchars($formValues['email_from']); ?>">
                </div>
                <div class="form-group">
                    <label for="name_from">From (name):</label>
                    <input type="text" id="name_from" name="name_from" value="<?php echo htmlspecialchars($formValues['name_from']); ?>">
                </div>
            </div>

            <div class="inline-group">
                <div class="form-group">
                    <label for="reply_to">Reply-To:</label>
                    <input type="email" id="reply_to" name="reply_to" value="<?php echo htmlspecialchars($formValues['reply_to']); ?>">
                </div>
                <div class="form-group">
                    <label for="tag">Tag:</label>
                    <input type="text" id="tag" name="tag" value="<?php echo htmlspecialchars($formValues['tag']); ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="subject">Subject:</label>
                <input type="text" id="subject" name="subject" value="<?php echo htmlspecialchars($formValues['subject']); ?>" required>
            </div>

            <div class="form-group">
                <label for="message">Message:</label>
                <textarea id="message" name="message" required><?php echo htmlspecialchars($formValues['message']); ?></textarea>
            </div>

            <div class="inline-group">
                <div class="form-group">
                    <label for="attachment">Attachment path (optional):</label>
                    <input type="text" id="attachment" name="attachment" value="<?php echo htmlspecialchars($formValues['attachment']); ?>">
                </div>
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="is_html" name="is_html" value="1" <?php echo $formValues['is_html'] ? 'checked' : ''; ?>>
                    <label for="is_html">Send as HTML</label>
                </div>
            </div>

            <button type="submit">Send Email</button>
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
                } elseif (stripos($log, 'WARNING') !== false) {
                    $class = 'warning';
                }
                echo '<div class="log-entry ' . $class . '">' . htmlspecialchars($log) . '</div>';
            }
            ?></pre>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
