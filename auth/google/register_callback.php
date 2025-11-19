<?php
/**
 * Google OAuth Registration Callback Handler
 * Handles the redirect from Google after authentication and auto-fills registration form
 */
session_start();
require_once '../../config/google_oauth.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../models/user.php';
require_once '../../config/app.php';

// Fix APP_BASE - it's calculated from the script location, but we need the root
// If APP_BASE includes '/auth/google', remove it
$appBase = defined('APP_BASE') ? APP_BASE : '';
if (strpos($appBase, '/auth') !== false) {
    $appBase = dirname(dirname($appBase)); // Go up two levels from /auth/google
}
if ($appBase === '/' || $appBase === '\\') {
    $appBase = '';
}

// Check for errors from Google
if (isset($_GET['error'])) {
    $error = 'Google authentication failed: ' . htmlspecialchars($_GET['error']);
    header('Location: ' . $appBase . '/register.php?error=' . urlencode($error));
    exit;
}

// Verify state token (CSRF protection)
if (!isset($_GET['state']) || !isset($_SESSION['google_oauth_state']) || $_GET['state'] !== $_SESSION['google_oauth_state']) {
    $error = 'Invalid security token. Please try again.';
    unset($_SESSION['google_oauth_state']);
    header('Location: ' . $appBase . '/register.php?error=' . urlencode($error));
    exit;
}

// Get authorization code
$code = $_GET['code'] ?? '';
if (empty($code)) {
    $error = 'Authorization code not received from Google.';
    header('Location: ' . $appBase . '/register.php?error=' . urlencode($error));
    exit;
}

// Build redirect URI (must match the one used in register.php)
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
// Use the corrected appBase for redirect path
$redirectPath = ($appBase !== '') ? $appBase . '/auth/google/register_callback.php' : '/auth/google/register_callback.php';
$redirectUri = $protocol . '://' . $host . $redirectPath;

// Exchange authorization code for access token
$tokenUrl = 'https://oauth2.googleapis.com/token';
$tokenData = [
    'code' => $code,
    'client_id' => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri' => $redirectUri, // Use the same redirect URI as in the authorization request
    'grant_type' => 'authorization_code'
];

$ch = curl_init($tokenUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($tokenData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
$tokenResponse = curl_exec($ch);
$tokenHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($tokenHttpCode !== 200) {
    // Log detailed error for debugging (server-side only)
    $errorMessage = 'Unknown error';
    if ($tokenResponse) {
        $errorData = json_decode($tokenResponse, true);
        if ($errorData) {
            $errorMessage = $errorData['error'] ?? 'Unknown error';
            $errorDescription = $errorData['error_description'] ?? '';
            error_log('Google OAuth token error (HTTP ' . $tokenHttpCode . '): ' . $errorMessage . ' - ' . $errorDescription);
            
            // Provide helpful error message based on common issues
            if ($errorMessage === 'invalid_client' || $errorMessage === 'unauthorized_client') {
                $userError = 'Google OAuth Client Secret is missing or incorrect. Please check config/google_oauth.php';
            } elseif ($errorMessage === 'redirect_uri_mismatch') {
                $userError = 'Redirect URI mismatch. Please check that the redirect URI in Google Console matches: ' . $redirectUri;
            } else {
                $userError = 'Google authentication failed: ' . ($errorDescription ?: $errorMessage);
            }
        } else {
            error_log('Google OAuth token error (HTTP ' . $tokenHttpCode . '): ' . $tokenResponse);
            $userError = 'Google authentication failed. Please check your configuration.';
        }
    } else {
        $userError = 'Google authentication failed. Please check your configuration.';
    }
    
    header('Location: ' . $appBase . '/register.php?error=' . urlencode($userError));
    exit;
}

$tokenData = json_decode($tokenResponse, true);
if (!isset($tokenData['access_token'])) {
    $error = 'Invalid response from Google.';
    header('Location: ' . $appBase . '/register.php?error=' . urlencode($error));
    exit;
}

$accessToken = $tokenData['access_token'];

// Get user info from Google
$userInfoUrl = 'https://www.googleapis.com/oauth2/v2/userinfo?access_token=' . urlencode($accessToken);
$ch = curl_init($userInfoUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$userInfoResponse = curl_exec($ch);
$userInfoHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($userInfoHttpCode !== 200) {
    $error = 'Failed to get user information from Google.';
    header('Location: ' . $appBase . '/register.php?error=' . urlencode($error));
    exit;
}

$userInfo = json_decode($userInfoResponse, true);
if (!isset($userInfo['email'])) {
    $error = 'Email address not provided by Google.';
    header('Location: ' . $appBase . '/register.php?error=' . urlencode($error));
    exit;
}

$googleEmail = $userInfo['email'];
$googleName = $userInfo['name'] ?? '';
$googleGivenName = $userInfo['given_name'] ?? '';
$googleFamilyName = $userInfo['family_name'] ?? '';
$googlePicture = $userInfo['picture'] ?? '';

// Clean up OAuth state
unset($_SESSION['google_oauth_state']);
$targetRole = $_SESSION['google_register_role'] ?? 'staff';
unset($_SESSION['google_register_role']);

// Check if user already exists
$db = (new Database())->getConnection();
$user = new User($db);

// Check if email already exists in users table
$stmt = $db->prepare("SELECT id, username, email, role FROM users WHERE email = :email LIMIT 1");
$stmt->bindParam(':email', $googleEmail);
$stmt->execute();
$existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if email exists in suppliers table
$stmtSupplier = $db->prepare("SELECT id, username, email FROM suppliers WHERE email = :email LIMIT 1");
$stmtSupplier->bindParam(':email', $googleEmail);
$stmtSupplier->execute();
$existingSupplier = $stmtSupplier->fetch(PDO::FETCH_ASSOC);

if ($existingUser || $existingSupplier) {
    // User already exists - redirect to login with message
    $error = 'An account with this Google email already exists. Please use the login page instead.';
    header('Location: ' . $appBase . '/register.php?error=' . urlencode($error));
    exit;
}

// Extract name parts from Google name
$nameParts = explode(' ', $googleName, 3);
$firstName = $googleGivenName ?: ($nameParts[0] ?? '');
$lastName = $googleFamilyName ?: ($nameParts[count($nameParts) - 1] ?? '');
$middleName = '';
if (count($nameParts) === 3 && !$googleGivenName && !$googleFamilyName) {
    $middleName = $nameParts[1] ?? '';
} elseif (count($nameParts) > 2 && $googleGivenName && $googleFamilyName) {
    $middleName = implode(' ', array_slice($nameParts, 1, -1));
}

// Generate unique username from email (take part before @)
$usernameBase = explode('@', $googleEmail)[0];
$username = $usernameBase;
$counter = 1;
// Ensure username is unique
while ($user->usernameExists($username)) {
    $username = $usernameBase . $counter;
    $counter++;
}

// Store Google info in session to pre-fill registration form
// Merge with any saved form data from the registration form
$savedFormData = $_SESSION['temp_registration_data'] ?? [];
$_SESSION['google_register_data'] = array_merge([
    'email' => $googleEmail,
    'first_name' => $firstName,
    'middle_name' => $middleName,
    'last_name' => $lastName,
    'username' => $username,
    'role' => $targetRole,
    'picture' => $googlePicture,
    'google_name' => $googleName,
    'address' => $savedFormData['address'] ?? '', // Use saved form data
    'city' => $savedFormData['city'] ?? '', // Use saved form data
    'province' => $savedFormData['province'] ?? '', // Use saved form data
    'postal_code' => $savedFormData['postal_code'] ?? '', // Use saved form data
    'phone' => $savedFormData['phone'] ?? '', // Use saved form data
    'password' => $savedFormData['password'] ?? '', // Use saved form data
    'confirm_password' => $savedFormData['confirm_password'] ?? '' // Use saved form data
], $savedFormData);

// Override with Google data (email, names, picture should come from Google)
$_SESSION['google_register_data']['email'] = $googleEmail;
$_SESSION['google_register_data']['first_name'] = $firstName;
$_SESSION['google_register_data']['middle_name'] = $middleName;
$_SESSION['google_register_data']['last_name'] = $lastName;
$_SESSION['google_register_data']['picture'] = $googlePicture;

// Clear temporary form data
unset($_SESSION['temp_registration_data']);

// Redirect back to appropriate registration form based on role
if ($targetRole === 'management' || $targetRole === 'admin') {
    $redirectUrl = $appBase . '/admin/register.php?google_auth=1';
} elseif ($targetRole === 'staff') {
    $redirectUrl = $appBase . '/staff/register.php?google_auth=1';
} elseif ($targetRole === 'supplier') {
    $redirectUrl = $appBase . '/supplier/register.php?google_auth=1';
} else {
    $redirectUrl = $appBase . '/register.php?google_auth=1&role=' . urlencode($targetRole);
}
header('Location: ' . $redirectUrl);
exit;

