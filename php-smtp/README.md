# PHP SMTP Health Check

Send a test email via SMTP using PHPMailer and `.env` configuration.

## Setup

```sh
composer require phpmailer/phpmailer
cp .env.sample .env
```

## Run

```sh
php index.php
```

The script prompts only for the recipient email. All other values (SMTP, from, subject, message, optional attachment) come from `.env`.
