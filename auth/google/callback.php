<?php
/**
 * Google OAuth Callback Handler
 * Handles the redirect from Google after authentication
 */
session_start();
require_once '../../config/google_oauth.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../models/user.php';
require_once '../../config/app.php';

$errors = [];
$db = (new Database())->getConnection();
$user = new User($db);

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
    $errors[] = 'Google authentication failed: ' . htmlspecialchars($_GET['error']);
    // Redirect to appropriate login page based on stored role, or default to staff
    $targetRole = $_SESSION['google_oauth_role'] ?? 'staff';
    if ($targetRole === 'admin') {
        $redirectUrl = $appBase . '/admin/login.php?error=' . urlencode($errors[0]);
    } elseif ($targetRole === 'staff') {
        $redirectUrl = $appBase . '/staff/login.php?error=' . urlencode($errors[0]);
    } elseif ($targetRole === 'supplier') {
        $redirectUrl = $appBase . '/supplier/login.php?error=' . urlencode($errors[0]);
    } else {
        $redirectUrl = $appBase . '/login.php?error=' . urlencode($errors[0]);
    }
    header('Location: ' . $redirectUrl);
    exit;
}

// Verify state token (CSRF protection)
if (!isset($_GET['state']) || !isset($_SESSION['google_oauth_state']) || $_GET['state'] !== $_SESSION['google_oauth_state']) {
    $errors[] = 'Invalid security token. Please try again.';
    unset($_SESSION['google_oauth_state']);
    // Redirect to appropriate login page based on stored role, or default to staff
    $targetRole = $_SESSION['google_oauth_role'] ?? 'staff';
    if ($targetRole === 'admin') {
        $redirectUrl = $appBase . '/admin/login.php?error=' . urlencode($errors[0]);
    } elseif ($targetRole === 'staff') {
        $redirectUrl = $appBase . '/staff/login.php?error=' . urlencode($errors[0]);
    } elseif ($targetRole === 'supplier') {
        $redirectUrl = $appBase . '/supplier/login.php?error=' . urlencode($errors[0]);
    } else {
        $redirectUrl = $appBase . '/login.php?error=' . urlencode($errors[0]);
    }
    header('Location: ' . $redirectUrl);
    exit;
}

// Get authorization code
$code = $_GET['code'] ?? '';
if (empty($code)) {
    $errors[] = 'Authorization code not received from Google.';
    // Redirect to appropriate login page based on stored role, or default to staff
    $targetRole = $_SESSION['google_oauth_role'] ?? 'staff';
    if ($targetRole === 'admin') {
        $redirectUrl = $appBase . '/admin/login.php?error=' . urlencode($errors[0]);
    } elseif ($targetRole === 'staff') {
        $redirectUrl = $appBase . '/staff/login.php?error=' . urlencode($errors[0]);
    } elseif ($targetRole === 'supplier') {
        $redirectUrl = $appBase . '/supplier/login.php?error=' . urlencode($errors[0]);
    } else {
        $redirectUrl = $appBase . '/login.php?error=' . urlencode($errors[0]);
    }
    header('Location: ' . $redirectUrl);
    exit;
}

// Build redirect URI (must match the one used in login.php)
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
// Use the corrected appBase for redirect path
$redirectPath = ($appBase !== '') ? $appBase . '/auth/google/callback.php' : '/auth/google/callback.php';
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
    
    $errors[] = $userError;
    // Redirect to appropriate login page based on stored role, or default to staff
    $targetRole = $_SESSION['google_oauth_role'] ?? 'staff';
    if ($targetRole === 'admin') {
        $redirectUrl = $appBase . '/admin/login.php?error=' . urlencode($userError);
    } elseif ($targetRole === 'staff') {
        $redirectUrl = $appBase . '/staff/login.php?error=' . urlencode($userError);
    } elseif ($targetRole === 'supplier') {
        $redirectUrl = $appBase . '/supplier/login.php?error=' . urlencode($userError);
    } else {
        $redirectUrl = $appBase . '/login.php?error=' . urlencode($userError);
    }
    header('Location: ' . $redirectUrl);
    exit;
}

$tokenData = json_decode($tokenResponse, true);
if (!isset($tokenData['access_token'])) {
    $errors[] = 'Invalid response from Google.';
    // Redirect to appropriate login page based on stored role, or default to staff
    $targetRole = $_SESSION['google_oauth_role'] ?? 'staff';
    if ($targetRole === 'admin') {
        $redirectUrl = $appBase . '/admin/login.php?error=' . urlencode($errors[0]);
    } elseif ($targetRole === 'staff') {
        $redirectUrl = $appBase . '/staff/login.php?error=' . urlencode($errors[0]);
    } elseif ($targetRole === 'supplier') {
        $redirectUrl = $appBase . '/supplier/login.php?error=' . urlencode($errors[0]);
    } else {
        $redirectUrl = $appBase . '/login.php?error=' . urlencode($errors[0]);
    }
    header('Location: ' . $redirectUrl);
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
    $errors[] = 'Failed to get user information from Google.';
    // Redirect to appropriate login page based on stored role, or default to staff
    $targetRole = $_SESSION['google_oauth_role'] ?? 'staff';
    if ($targetRole === 'admin') {
        $redirectUrl = $appBase . '/admin/login.php?error=' . urlencode($errors[0]);
    } elseif ($targetRole === 'staff') {
        $redirectUrl = $appBase . '/staff/login.php?error=' . urlencode($errors[0]);
    } elseif ($targetRole === 'supplier') {
        $redirectUrl = $appBase . '/supplier/login.php?error=' . urlencode($errors[0]);
    } else {
        $redirectUrl = $appBase . '/login.php?error=' . urlencode($errors[0]);
    }
    header('Location: ' . $redirectUrl);
    exit;
}

$userInfo = json_decode($userInfoResponse, true);
if (!isset($userInfo['email'])) {
    $errors[] = 'Email address not provided by Google.';
    // Redirect to appropriate login page based on stored role, or default to staff
    $targetRole = $_SESSION['google_oauth_role'] ?? 'staff';
    if ($targetRole === 'admin') {
        $redirectUrl = $appBase . '/admin/login.php?error=' . urlencode($errors[0]);
    } elseif ($targetRole === 'staff') {
        $redirectUrl = $appBase . '/staff/login.php?error=' . urlencode($errors[0]);
    } elseif ($targetRole === 'supplier') {
        $redirectUrl = $appBase . '/supplier/login.php?error=' . urlencode($errors[0]);
    } else {
        $redirectUrl = $appBase . '/login.php?error=' . urlencode($errors[0]);
    }
    header('Location: ' . $redirectUrl);
    exit;
}

$googleEmail = $userInfo['email'];
$googleName = $userInfo['name'] ?? $userInfo['given_name'] ?? 'User';
$googlePicture = $userInfo['picture'] ?? '';

// Clean up OAuth state
unset($_SESSION['google_oauth_state']);
$targetRole = $_SESSION['google_oauth_role'] ?? 'staff';
unset($_SESSION['google_oauth_role']);

// Attempt to log in user with Google email
try {
    // Check if user exists in users table by email
    $stmt = $db->prepare("SELECT id, username, email, role, password_hash FROM users WHERE email = :email LIMIT 1");
    $stmt->bindParam(':email', $googleEmail);
    $stmt->execute();
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($userRow) {
        // User exists - log them in (password not required for Google auth)
        $dbRole = $userRow['role'];
        
        // Validate that the user's database role matches the target role from login page
        // Map targetRole ('admin' from URL) to database role ('management')
        $expectedDbRole = null;
        if ($targetRole === 'admin' && $dbRole === 'management') {
            $expectedDbRole = 'management';
        } elseif ($targetRole === 'staff' && $dbRole === 'staff') {
            $expectedDbRole = 'staff';
        } elseif ($targetRole === 'supplier' && $dbRole === 'supplier') {
            $expectedDbRole = 'supplier';
        }
        
        // If role mismatch, redirect to appropriate login page with error
        if ($expectedDbRole === null) {
            $errorMsg = 'This account cannot access the ' . ucfirst($targetRole) . ' portal. ';
            if ($dbRole === 'management') {
                $errorMsg .= 'Please use the Admin login page instead.';
                $redirectUrl = $appBase . '/admin/login.php?error=' . urlencode($errorMsg);
            } elseif ($dbRole === 'staff') {
                $errorMsg .= 'Please use the Staff login page instead.';
                $redirectUrl = $appBase . '/staff/login.php?error=' . urlencode($errorMsg);
            } elseif ($dbRole === 'supplier') {
                $errorMsg .= 'Please use the Supplier login page instead.';
                $redirectUrl = $appBase . '/supplier/login.php?error=' . urlencode($errorMsg);
            } else {
                $errorMsg = 'Invalid account type. Please contact administrator.';
                $redirectUrl = $appBase . '/login.php?error=' . urlencode($errorMsg);
            }
            header('Location: ' . $redirectUrl);
            exit;
        }
        
        // Map database role to session namespace
        if ($dbRole === 'management') {
            $_SESSION['admin'] = [
                'user_id' => $userRow['id'],
                'username' => $userRow['username'],
                'email' => $userRow['email'],
                'role' => 'management',
                'auth_method' => 'google'
            ];
            $user->logAuthAttempt('admin', $userRow['id'], $userRow['username'], 'login_success', 'Google OAuth login');
            header('Location: ' . $appBase . '/admin/dashboard.php');
            exit;
        } elseif ($dbRole === 'staff') {
            $_SESSION['staff'] = [
                'user_id' => $userRow['id'],
                'username' => $userRow['username'],
                'email' => $userRow['email'],
                'role' => 'staff',
                'auth_method' => 'google'
            ];
            $user->logAuthAttempt('staff', $userRow['id'], $userRow['username'], 'login_success', 'Google OAuth login');
            header('Location: ' . $appBase . '/staff/pos.php');
            exit;
        } elseif ($dbRole === 'supplier') {
            // Find supplier ID by username
            $stmtSupplier = $db->prepare("SELECT id FROM suppliers WHERE username = :username LIMIT 1");
            $stmtSupplier->bindParam(':username', $userRow['username']);
            $stmtSupplier->execute();
            $supplierResult = $stmtSupplier->fetch(PDO::FETCH_ASSOC);
            $supplierId = $supplierResult ? (int)$supplierResult['id'] : null;
            $effectiveSupplierId = $supplierId ?? $userRow['id'];
            
            $_SESSION['supplier'] = [
                'user_id' => $effectiveSupplierId,
                'username' => $userRow['username'],
                'name' => $userRow['username'],
                'email' => $userRow['email'],
                'role' => 'supplier',
                'auth_method' => 'google'
            ];
            $_SESSION['user_id'] = $effectiveSupplierId;
            $_SESSION['username'] = $userRow['username'];
            $_SESSION['role'] = 'supplier';
            $user->logAuthAttempt('supplier', $effectiveSupplierId, $userRow['username'], 'login_success', 'Google OAuth login');
            header('Location: ' . $appBase . '/supplier/dashboard.php');
            exit;
        }
    } else {
        // Check suppliers table
        $stmt = $db->prepare("SELECT id, name, username, email, status FROM suppliers WHERE email = :email AND status = 'active' LIMIT 1");
        $stmt->bindParam(':email', $googleEmail);
        $stmt->execute();
        $supplierRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($supplierRow) {
            // Validate targetRole matches supplier
            if ($targetRole !== 'supplier') {
                $errorMsg = 'This supplier account cannot access the ' . ucfirst($targetRole) . ' portal. Please use the Supplier login page instead.';
                header('Location: ' . $appBase . '/supplier/login.php?error=' . urlencode($errorMsg));
                exit;
            }
            
            $_SESSION['supplier'] = [
                'user_id' => $supplierRow['id'],
                'username' => $supplierRow['username'],
                'name' => $supplierRow['name'],
                'email' => $supplierRow['email'],
                'role' => 'supplier',
                'auth_method' => 'google'
            ];
            $_SESSION['user_id'] = $supplierRow['id'];
            $_SESSION['username'] = $supplierRow['username'];
            $_SESSION['role'] = 'supplier';
            $user->logAuthAttempt('supplier', $supplierRow['id'], $supplierRow['username'], 'login_success', 'Google OAuth login');
            header('Location: ' . $appBase . '/supplier/dashboard.php');
            exit;
        }
    }

    // User not found - redirect to appropriate login page based on targetRole
    $errorMsg = 'No account found for Google email: ' . htmlspecialchars($googleEmail) . '. Please register first.';
    if ($targetRole === 'admin') {
        $redirectUrl = $appBase . '/admin/login.php?error=' . urlencode($errorMsg);
    } elseif ($targetRole === 'staff') {
        $redirectUrl = $appBase . '/staff/login.php?error=' . urlencode($errorMsg);
    } elseif ($targetRole === 'supplier') {
        $redirectUrl = $appBase . '/supplier/login.php?error=' . urlencode($errorMsg);
    } else {
        $redirectUrl = $appBase . '/login.php?error=' . urlencode($errorMsg);
    }
    header('Location: ' . $redirectUrl);
    exit;

} catch (Exception $e) {
    error_log('Google OAuth callback error: ' . $e->getMessage());
    $errors[] = 'An error occurred during login. Please try again.';
    // Redirect to appropriate login page based on stored role, or default to staff
    $targetRole = $_SESSION['google_oauth_role'] ?? 'staff';
    if ($targetRole === 'admin') {
        $redirectUrl = $appBase . '/admin/login.php?error=' . urlencode($errors[0]);
    } elseif ($targetRole === 'staff') {
        $redirectUrl = $appBase . '/staff/login.php?error=' . urlencode($errors[0]);
    } elseif ($targetRole === 'supplier') {
        $redirectUrl = $appBase . '/supplier/login.php?error=' . urlencode($errors[0]);
    } else {
        $redirectUrl = $appBase . '/login.php?error=' . urlencode($errors[0]);
    }
    header('Location: ' . $redirectUrl);
    exit;
}

