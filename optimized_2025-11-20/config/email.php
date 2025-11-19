<?php
// SMTP configuration for password reset emails.
// All credentials must be provided via environment variables.
// This file intentionally contains no secrets.
return [
    'provider'   => getenv('SMTP_PROVIDER') ?: 'gmail',

    // Credentials must come from environment. If missing, mailing should be disabled by caller.
    'username'   => getenv('SMTP_USERNAME') ?: '',
    'password'   => getenv('SMTP_PASSWORD') ?: '',

    // Optional custom SMTP (used if provider is empty and these are provided)
    'host'       => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
    'port'       => (int)(getenv('SMTP_PORT') ?: 587),
    'secure'     => getenv('SMTP_SECURE') ?: 'tls',

    // Sender details
    'from_email' => getenv('SMTP_FROM_EMAIL') ?: (getenv('SMTP_USERNAME') ?: ''),
    'from_name'  => getenv('SMTP_FROM_NAME') ?: 'Inventory System',
];