<?php
/**
 * Google OAuth Configuration
 * 
 * To set up Google OAuth:
 * 1. Go to https://console.cloud.google.com/
 * 2. Create a new project or select existing one
 * 3. Enable Google+ API
 * 4. Go to Credentials -> Create OAuth 2.0 Client ID
 * 5. Set authorized redirect URIs to: https://yourdomain.com/auth/google/callback.php
 * 6. Copy Client ID and Client Secret below
 */

// Google OAuth Configuration
require_once __DIR__ . '/app.php';

// Google OAuth Credentials
// Get Client ID and Secret from: https://console.cloud.google.com/apis/credentials
// 1. Go to your OAuth client in Google Console
// 2. Copy the Client ID (already set below)
// 3. Click "Show" next to Client Secret and copy the full secret
// 4. Replace 'YOUR_GOOGLE_CLIENT_SECRET_HERE' below with your actual secret
define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');

// Build redirect URI dynamically - this is a fallback, but should be overridden by environment variable
// The actual redirect URI should be set in Google Cloud Console and match exactly
if (getenv('GOOGLE_REDIRECT_URI')) {
    define('GOOGLE_REDIRECT_URI', getenv('GOOGLE_REDIRECT_URI'));
} else {
    // Build dynamically as fallback (but note: must match Google Console exactly!)
    // Note: This will be recalculated in login.php and callback.php to ensure consistency
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Get correct base path - if APP_BASE includes /auth, we're in a subdirectory
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

// Google OAuth scopes (what information we request from Google)
define('GOOGLE_SCOPES', [
    'openid',
    'https://www.googleapis.com/auth/userinfo.email',
    'https://www.googleapis.com/auth/userinfo.profile'
]);

