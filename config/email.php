<?php
// SMTP configuration for password reset emails.
// Fill in with your credentials. For Gmail, generate an App Password.
return [
    // Provider presets: 'gmail' or leave empty to use custom host or ENV
    'provider'   => 'gmail',

    // For Gmail provider, host/port/secure are preset; only username/password needed
    'username'   => 'vpvillanueva.chmsu@gmail.com',
    'password'   => 'lxzo yzth vbjq onpd',

    // Optional custom SMTP (used if provider is empty and these are provided)
    'host'       => 'smtp.gmail.com',
    'port'       => 587,
    'secure'     => 'tls', // 'tls' or 'ssl'

    // Sender details
    'from_email' => 'vpvillanueva.chmsu@gmail.com',
    'from_name'  => 'Inventory System',
];