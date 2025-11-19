<?php
include_once '../config/session.php';
include_once '../config/database.php';
include_once '../models/user.php';
include_once '../config/app.php';

// Enforce HTTPS for authentication endpoints (skip on localhost during development)
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$isLocalHost = (bool)preg_match('/^(localhost|127\.0\.0\.1|\[::1\]|.*\.local)(:\d+)?$/i', $host);
// Skip HTTPS redirect on localhost to prevent redirect loops
if (!$isLocalHost && (($_SERVER['HTTPS'] ?? 'off') !== 'on') && (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https')) {
    // Only redirect once - check if we already tried
    if (!isset($_GET['_https_redirect'])) {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/admin/login.php';
        $separator = strpos($requestUri, '?') !== false ? '&' : '?';
        $redirectUrl = 'https://' . $host . $requestUri . $separator . '_https_redirect=1';
        header('Location: ' . $redirectUrl);
        exit();
    }
}

// Ensure CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Calculate root APP_BASE (since we're in /admin/, APP_BASE would be '/admin' or '/haha/admin', but we need the root)
$rootBase = defined('APP_BASE') ? APP_BASE : '';
// Get script directory to determine if we're in a subfolder
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
// Remove /admin, /staff, or /supplier from the end
if (preg_match('#/(admin|staff|supplier)$#', $rootBase)) {
    $rootBase = preg_replace('#/(admin|staff|supplier)$#', '', $rootBase);
} elseif (preg_match('#/(admin|staff|supplier)$#', $scriptDir)) {
    // If script is in /haha/admin/, remove /admin to get /haha
    $rootBase = preg_replace('#/(admin|staff|supplier)$#', '', $scriptDir);
    // Convert backslashes and normalize
    $rootBase = str_replace('\\', '/', $rootBase);
    if ($rootBase === '/' || $rootBase === '\\' || $rootBase === '.' || empty($rootBase)) {
        $rootBase = '';
    }
}

$db   = (new Database())->getConnection();
$user = new User($db);

// Simple session-based rate limit (10 attempts / 10 minutes)
if (!isset($_SESSION['rate_limit'])) $_SESSION['rate_limit'] = [];
if (!isset($_SESSION['rate_limit']['admin_login'])) $_SESSION['rate_limit']['admin_login'] = [];
// Purge old entries
$_SESSION['rate_limit']['admin_login'] = array_filter(
    $_SESSION['rate_limit']['admin_login'],
    function ($ts) { return $ts > (time() - 600); }
);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $errors[] = 'Invalid request. Please refresh and try again.';
    } else {
        // Rate limit check
        if (count($_SESSION['rate_limit']['admin_login']) >= 10) {
            $errors[] = 'Too many attempts. Please wait a few minutes and try again.';
        } else {
            $email = trim($_POST['email'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $remember = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            } elseif ($password === '') {
                $errors[] = 'Password is required.';
            } else {
                // Attempt login by email - only for admin (management role)
                // Note: Each browser session is independent, so multiple admins can log in simultaneously
                // from different browsers/devices. Logout only affects the current browser session.
                $result = $user->loginByEmail($email, $password);
                if ($result['success'] ?? false) {
                    // Verify it's actually an admin account
                    if ($result['role'] === 'admin') {
                        // Optional remember-me: extend session cookie lifetime to 30 days
                        if ($remember) {
                            if (session_status() === PHP_SESSION_ACTIVE) {
                                setcookie(session_name(), session_id(), [
                                    'expires'  => time() + 60 * 60 * 24 * 30,
                                    'path'     => '/',
                                    'secure'   => true,
                                    'httponly' => true,
                                    'samesite' => 'Lax',
                                ]);
                            }
                        }
                        // Redirect to admin dashboard (use relative path from admin folder)
                        header('Location: dashboard.php');
                        exit();
                    } else {
                        $errors[] = 'This account is not authorized for Admin access.';
                        $_SESSION['rate_limit']['admin_login'][] = time();
                    }
                } else {
                    // Map errors
                    $err = $result['error'] ?? 'unknown';
                    if ($err === 'invalid_email') {
                        $errors[] = 'Please enter a valid email address.';
                    } elseif ($err === 'not_found') {
                        $errors[] = 'No admin account found for that email address.';
                    } elseif ($err === 'invalid_password') {
                        $errors[] = 'The password you entered is incorrect.';
                    } elseif ($err === 'locked') {
                        $errors[] = 'Your account is temporarily locked due to multiple failed attempts. Try again later.';
                    } else {
                        $errors[] = 'Unable to sign in. Please try again.';
                    }
                    $_SESSION['rate_limit']['admin_login'][] = time();
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin Login - Inventory Management System</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
  <style>
    .login-card {
      border: none;
      border-radius: 15px;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
      transition: transform 0.3s ease;
    }
    .login-card:hover {
      transform: translateY(-2px);
    }
    .form-control {
      border-radius: 10px;
      border: 2px solid #e9ecef;
      transition: all 0.3s ease;
    }
    .form-control:focus {
      border-color: #0d6efd;
      box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);
    }
    .btn-primary {
      border-radius: 10px;
      padding: 12px 20px;
      font-weight: 600;
      transition: all 0.3s ease;
      background: linear-gradient(135deg, #0d6efd, #0056b3);
      border: none;
    }
    .btn-primary:hover {
      transform: translateY(-1px);
      box-shadow: 0 5px 15px rgba(13, 110, 253, 0.4);
    }
  </style>
</head>
<body class="bg-light">
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-md-5">
        <div class="card login-card">
          <div class="card-body p-4">
            <h3 class="mb-3 text-center">
              <i class="bi bi-shield-lock me-2"></i>Admin Login
            </h3>

            <?php if (!empty($errors)): ?>
              <div class="alert alert-danger">
                <?php foreach ($errors as $e): ?>
                  <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['success'])): ?>
              <div class="alert alert-success">
                Registration successful! You can now login with your Admin account.
              </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['error'])): ?>
              <div class="alert alert-danger">
                <div><?= htmlspecialchars($_GET['error']) ?></div>
                <?php if (strpos($_GET['error'], 'Google') !== false || strpos($_GET['error'], 'OAuth') !== false): ?>
                  <div class="mt-2">
                    <a href="<?php echo $rootBase; ?>/auth/google/debug.php" class="btn btn-sm btn-outline-danger">
                      <i class="bi bi-bug me-1"></i>Debug Google OAuth
                    </a>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>

            <form method="POST" action="" id="loginForm" novalidate>
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>" />

              <div class="mb-3">
                <label class="form-label">Email</label>
                <input name="email" type="email" class="form-control" required autocomplete="username" />
                <div class="form-text">Use your admin email address.</div>
              </div>

              <div class="mb-3">
                <label class="form-label">Password</label>
                <input name="password" type="password" class="form-control" required autocomplete="current-password" />
              </div>

              <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="remember_me" name="remember_me" value="1" />
                <label class="form-check-label" for="remember_me">Remember me</label>
              </div>

              <div class="d-grid">
                <button type="submit" class="btn btn-primary btn-lg" id="loginBtn">
                  <i class="bi bi-box-arrow-in-right me-2"></i>Log In
                </button>
              </div>
            </form>

            <div class="mt-4">
              <div class="d-flex align-items-center mb-3">
                <hr class="flex-grow-1">
                <span class="mx-2 text-muted">Or</span>
                <hr class="flex-grow-1">
              </div>
              <div class="text-center text-muted mb-3">
                <small>Sign in with Google</small>
              </div>
              <div class="d-grid">
                <a href="<?php echo $rootBase; ?>/auth/google/login.php?role=admin" class="btn btn-outline-danger">
                  <svg width="18" height="18" class="me-2" viewBox="0 0 24 24">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                  </svg>
                  Sign in with Google
                </a>
              </div>
            </div>

            <div class="mt-4">
              <div class="d-flex align-items-center mb-3">
                <hr class="flex-grow-1">
                <span class="mx-2 text-muted">Or</span>
                <hr class="flex-grow-1">
              </div>
              <div class="text-center text-muted mb-3">
                <small>New to the system?</small>
              </div>
              <div class="d-grid">
                <a href="<?php echo $rootBase; ?>/auth/google/register.php?role=management" class="btn btn-outline-primary">
                  <svg width="18" height="18" class="me-2" viewBox="0 0 24 24">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                  </svg>
                  Sign up with Google
                </a>
              </div>
            </div>

            <div class="mt-3 text-center">
              <a href="<?php echo $rootBase; ?>/forgot-password.php">Forgot Password?</a>
              <span class="mx-2">|</span>
              <a href="register.php">Sign Up</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const loginBtn = document.getElementById('loginBtn');
      const form = document.getElementById('loginForm');
      if (!form) return;

      form.addEventListener('submit', function(e) {
        const email = form.querySelector('input[name="email"]').value.trim();
        const password = form.querySelector('input[name="password"]').value;
        const emailValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        if (!emailValid || !password) {
          e.preventDefault();
          alert('Please provide a valid email and password.');
          return false;
        }
        loginBtn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Signing In...';
        loginBtn.disabled = true;
      });
    });
  </script>
</body>
</html>

