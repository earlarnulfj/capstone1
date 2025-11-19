<?php
session_start();
include_once 'config/database.php';
include_once 'models/user.php';
include_once 'config/app.php';

// Redirect to forgot password if no email or token in session
if (!isset($_SESSION['reset_email']) || !isset($_SESSION['reset_token'])) {
    header("Location: forgot-password.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();
$user = new User($db);

$error_message = '';
$success_message = '';
$email = $_SESSION['reset_email'];
$token = $_SESSION['reset_token'];

// Process password reset form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($new_password)) {
        $error_message = "Please enter a new password.";
    } elseif (strlen($new_password) < 6) {
        $error_message = "Password must be at least 6 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $error_message = "Passwords do not match.";
    } else {
        // Reset the password using verified token
        $result = $user->resetPassword($token, $new_password);
        
        if ($result['success']) {
            $success_message = $result['message'];
            // Clear session data
            unset($_SESSION['reset_email']);
            unset($_SESSION['reset_token']);
            
            // Redirect to login after a short delay
            header("refresh:3;url=" . APP_BASE . "/login.php");
        } else {
            $error_message = $result['message'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Inventory Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            text-align: center;
            padding: 2rem;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 25px;
            padding: 12px 30px;
            font-weight: 600;
        }
        .btn-outline-secondary {
            border-radius: 25px;
            padding: 12px 30px;
            font-weight: 600;
        }
        .progress-steps {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
        }
        .step {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 10px;
            font-weight: bold;
            color: white;
        }
        .step.completed {
            background-color: #28a745;
        }
        .step.active {
            background-color: #007bff;
        }
        .step.pending {
            background-color: #6c757d;
        }
        .step-line {
            width: 50px;
            height: 2px;
            background-color: #dee2e6;
            margin-top: 19px;
        }
        .step-line.completed {
            background-color: #28a745;
        }
        .password-strength {
            height: 5px;
            border-radius: 3px;
            margin-top: 5px;
            transition: all 0.3s ease;
        }
        .strength-weak { background-color: #dc3545; }
        .strength-medium { background-color: #ffc107; }
        .strength-strong { background-color: #28a745; }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="card">
                    <div class="card-header">
                        <h3 class="mb-0">
                            <i class="bi bi-key me-2"></i>Reset Password
                        </h3>
                        <p class="mb-0 mt-2 opacity-75">Create a new secure password</p>
                    </div>
                    <div class="card-body p-4">
                        <!-- Progress Steps -->
                        <div class="progress-steps">
                            <div class="step completed">1</div>
                            <div class="step-line completed"></div>
                            <div class="step completed">2</div>
                            <div class="step-line completed"></div>
                            <div class="step active">3</div>
                        </div>
                        
                        <?php if (!empty($error_message)): ?>
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error_message; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($success_message)): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle-fill me-2"></i><?php echo $success_message; ?>
                                <hr>
                                <div class="d-flex align-items-center">
                                    <div class="spinner-border spinner-border-sm me-2" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <small>Redirecting to login page...</small>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-center mb-4">
                                <p class="text-muted">
                                    <i class="bi bi-person-check me-2"></i>Account verified for:
                                </p>
                                <strong class="text-primary"><?php echo htmlspecialchars($email); ?></strong>
                            </div>
                            
                            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" id="resetPasswordForm">
                                <div class="mb-3">
                                    <label for="new_password" class="form-label">
                                        <i class="bi bi-lock me-2"></i>New Password
                                    </label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="new_password" 
                                               name="new_password" placeholder="Enter new password" required>
                                        <button class="btn btn-outline-secondary" type="button" id="togglePassword1">
                                            <i class="bi bi-eye" id="eyeIcon1"></i>
                                        </button>
                                    </div>
                                    <div class="password-strength" id="passwordStrength"></div>
                                    <div class="form-text">
                                        <small id="passwordHelp">Password must be at least 6 characters long</small>
                                    </div>
                                </div>
                                
                                <div class="mb-4">
                                    <label for="confirm_password" class="form-label">
                                        <i class="bi bi-lock-fill me-2"></i>Confirm Password
                                    </label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="confirm_password" 
                                               name="confirm_password" placeholder="Confirm new password" required>
                                        <button class="btn btn-outline-secondary" type="button" id="togglePassword2">
                                            <i class="bi bi-eye" id="eyeIcon2"></i>
                                        </button>
                                    </div>
                                    <div class="form-text">
                                        <small id="confirmHelp"></small>
                                    </div>
                                </div>
                                
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary" id="resetBtn" disabled>
                                        <i class="bi bi-check-circle me-2"></i>Reset Password
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                        
                        <div class="mt-3 text-center">
                            <a href="<?php echo APP_BASE; ?>/login.php" class="text-decoration-none">
                                <i class="bi bi-arrow-left me-1"></i>Back to Login
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password visibility toggle
        function setupPasswordToggle(toggleId, inputId, iconId) {
            document.getElementById(toggleId).addEventListener('click', function() {
                const passwordInput = document.getElementById(inputId);
                const eyeIcon = document.getElementById(iconId);
                
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    eyeIcon.className = 'bi bi-eye-slash';
                } else {
                    passwordInput.type = 'password';
                    eyeIcon.className = 'bi bi-eye';
                }
            });
        }

        setupPasswordToggle('togglePassword1', 'new_password', 'eyeIcon1');
        setupPasswordToggle('togglePassword2', 'confirm_password', 'eyeIcon2');

        // Password strength checker
        function checkPasswordStrength(password) {
            const strengthBar = document.getElementById('passwordStrength');
            const helpText = document.getElementById('passwordHelp');
            
            if (password.length === 0) {
                strengthBar.style.width = '0%';
                strengthBar.className = 'password-strength';
                helpText.textContent = 'Password must be at least 6 characters long';
                return 0;
            }
            
            let strength = 0;
            let feedback = [];
            
            if (password.length >= 6) strength += 1;
            if (password.length >= 8) strength += 1;
            if (/[a-z]/.test(password)) strength += 1;
            if (/[A-Z]/.test(password)) strength += 1;
            if (/[0-9]/.test(password)) strength += 1;
            if (/[^A-Za-z0-9]/.test(password)) strength += 1;
            
            if (strength <= 2) {
                strengthBar.style.width = '33%';
                strengthBar.className = 'password-strength strength-weak';
                helpText.textContent = 'Weak password';
                helpText.style.color = '#dc3545';
            } else if (strength <= 4) {
                strengthBar.style.width = '66%';
                strengthBar.className = 'password-strength strength-medium';
                helpText.textContent = 'Medium strength password';
                helpText.style.color = '#ffc107';
            } else {
                strengthBar.style.width = '100%';
                strengthBar.className = 'password-strength strength-strong';
                helpText.textContent = 'Strong password';
                helpText.style.color = '#28a745';
            }
            
            return strength;
        }

        // Password matching checker
        function checkPasswordMatch() {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const confirmHelp = document.getElementById('confirmHelp');
            const resetBtn = document.getElementById('resetBtn');
            
            if (confirmPassword.length === 0) {
                confirmHelp.textContent = '';
                confirmHelp.style.color = '';
                resetBtn.disabled = true;
                return false;
            }
            
            if (password === confirmPassword) {
                confirmHelp.textContent = 'Passwords match';
                confirmHelp.style.color = '#28a745';
                resetBtn.disabled = password.length < 6;
                return true;
            } else {
                confirmHelp.textContent = 'Passwords do not match';
                confirmHelp.style.color = '#dc3545';
                resetBtn.disabled = true;
                return false;
            }
        }

        // Event listeners
        document.getElementById('new_password').addEventListener('input', function(e) {
            checkPasswordStrength(e.target.value);
            checkPasswordMatch();
        });

        document.getElementById('confirm_password').addEventListener('input', function(e) {
            checkPasswordMatch();
        });

        // Focus on first input when page loads
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('new_password').focus();
        });
    </script>
</body>
</html>