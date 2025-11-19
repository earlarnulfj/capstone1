<?php
/**
 * Google OAuth Registration Handler
 * Initiates Google OAuth flow for new user registration
 */
session_start();
require_once '../../config/google_oauth.php';
require_once '../../config/app.php';

// Get the role the user wants to register as (admin, staff, or supplier)
$role = $_GET['role'] ?? 'staff'; // Default to staff if not specified
if (!in_array($role, ['admin', 'staff', 'supplier'])) {
    $role = 'staff';
}

// Store registration info in session for callback
$_SESSION['google_register_role'] = $role;

// Generate state token for CSRF protection
$_SESSION['google_oauth_state'] = bin2hex(random_bytes(32));

// Fix APP_BASE - it's calculated from the script location, but we need the root
$appBase = defined('APP_BASE') ? APP_BASE : '';
if (strpos($appBase, '/auth') !== false) {
    $appBase = dirname(dirname($appBase)); // Go up two levels from /auth/google
}
if ($appBase === '/' || $appBase === '\\') {
    $appBase = '';
}
// Ensure APP_BASE has leading slash if not empty
if ($appBase !== '' && substr($appBase, 0, 1) !== '/') {
    $appBase = '/' . $appBase;
}

// Validate credentials (non-blocking - allow flow to proceed)
$clientId = trim(GOOGLE_CLIENT_ID ?? '');
$clientSecret = trim(GOOGLE_CLIENT_SECRET ?? '');

// Log warning if credentials appear incomplete, but don't block the flow
if (empty($clientId) || empty($clientSecret) || 
    strpos($clientId, 'YOUR_GOOGLE') !== false || 
    strpos($clientSecret, 'YOUR_GOOGLE') !== false) {
    error_log('Warning: Google OAuth credentials may not be fully configured.');
}

// Build redirect URI - ensure it's absolute and matches Google Console settings
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
// Use the corrected appBase for redirect path
$redirectPath = ($appBase !== '') ? $appBase . '/auth/google/register_callback.php' : '/auth/google/register_callback.php';
$redirectUri = $protocol . '://' . $host . $redirectPath;

// Build scope string (must be space-separated)
$scopes = [
    'openid',
    'https://www.googleapis.com/auth/userinfo.email',
    'https://www.googleapis.com/auth/userinfo.profile'
];
$scopeString = implode(' ', $scopes);

// Build Google OAuth URL
$params = [
    'client_id' => GOOGLE_CLIENT_ID,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => $scopeString,
    'state' => $_SESSION['google_oauth_state'],
    'access_type' => 'offline',
    'prompt' => 'consent'
];

// Validate redirect URI is not empty
if (empty($redirectUri)) {
    header('Location: ' . $appBase . '/register.php?error=' . urlencode('Redirect URI is empty. Please check your configuration.'));
    exit;
}

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);

// Redirect to Google
header('Location: ' . $authUrl);
exit;

