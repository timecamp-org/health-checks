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
