<?php
/**
 * Google OAuth Configuration (env-only credentials)
 */
require_once __DIR__ . '/app.php';

define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');

if (getenv('GOOGLE_REDIRECT_URI')) {
    define('GOOGLE_REDIRECT_URI', getenv('GOOGLE_REDIRECT_URI'));
} else {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = defined('APP_BASE') ? APP_BASE : '';
    if (strpos($base, '/auth') !== false) {
        $base = dirname(dirname($base));
    }
    if ($base === '/' || $base === '\\') {
        $base = '';
    }
    $redirectPath = ($base !== '') ? $base . '/auth/google/callback.php' : '/auth/google/callback.php';
    define('GOOGLE_REDIRECT_URI', $protocol . '://' . $host . $redirectPath);
}

define('GOOGLE_SCOPES', [
    'openid',
    'https://www.googleapis.com/auth/userinfo.email',
    'https://www.googleapis.com/auth/userinfo.profile'
]);