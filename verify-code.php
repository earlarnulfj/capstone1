<?php
session_start();
include_once 'config/database.php';
include_once 'models/user.php';
include_once 'config/app.php';
// Load PHPMailer and email config for resend capability
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
// Resolve PHPMailer src path explicitly
$phpMailerSrc = __DIR__ . '/PHPMailer-master/PHPMailer-master/src';
if (!is_dir($phpMailerSrc)) {
    $alt = __DIR__ . '/PHPMailer-master/src';
    if (is_dir($alt)) {
        $phpMailerSrc = $alt;
    } else {
        // Do not hard fail here; resend will show a friendly error
        $phpMailerSrc = '';
    }
}
if ($phpMailerSrc) {
    require_once $phpMailerSrc . '/Exception.php';
    require_once $phpMailerSrc . '/PHPMailer.php';
    require_once $phpMailerSrc . '/SMTP.php';
}
// Load email config if available
$GLOBALS['emailConfig'] = file_exists(__DIR__ . '/config/email.php') ? include __DIR__ . '/config/email.php' : [];
// Minimal email result logging
function logEmailResult(array $info): void {
    $logFile = __DIR__ . '/logs/email_log.txt';
    $line = date('Y-m-d H:i:s') . ' | ' . ($info['status'] ?? 'unknown') . ' | to=' . ($info['to'] ?? '') . ' | ' . ($info['message'] ?? '') . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
}
// Email sender (same behavior as forgot-password) for resends
function resendVerificationEmail(string $toEmail, string $code): array {
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        logEmailResult(['status' => 'error', 'to' => $toEmail, 'message' => 'phpmailer_missing']);
        return ['success' => false, 'message' => 'Email service is temporarily unavailable. Please try again later.'];
    }
    $mail = new PHPMailer(true);
    $cfg = $GLOBALS['emailConfig'];
    try {
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 20;
        $fromName = $cfg['from_name'] ?? getenv('EMAIL_FROM_NAME') ?: 'Inventory & Stock Control System';
        $fromEmail = $cfg['from_email'] ?? '';
        $provider  = strtolower($cfg['provider'] ?? 'gmail');
        $configured = false;
        // Gmail
        if ($provider === 'gmail') {
            $gmailUser = $cfg['username'] ?? getenv('GMAIL_USER') ?: '';
            $gmailPass = $cfg['password'] ?? getenv('GMAIL_APP_PASSWORD') ?: '';
            if ($gmailPass) { $gmailPass = preg_replace('/\s+/', '', $gmailPass); }
            $fromEmail = $fromEmail ?: $gmailUser;
            if ($gmailUser && $gmailPass) {
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = $gmailUser;
                $mail->Password   = $gmailPass;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;
                $configured = true;
            }
        }
        // Mailtrap
        if (!$configured && ($provider === 'mailtrap' || getenv('MAILTRAP_USERNAME'))) {
            $mtUser = $cfg['username'] ?? getenv('MAILTRAP_USERNAME') ?: '';
            $mtPass = $cfg['password'] ?? getenv('MAILTRAP_PASSWORD') ?: '';
            $mtHost = $cfg['host'] ?? getenv('MAILTRAP_HOST') ?: 'smtp.mailtrap.io';
            $mtPort = (int)($cfg['port'] ?? getenv('MAILTRAP_PORT') ?: 587);
            $fromEmail = $fromEmail ?: 'no-reply@inventory.local';
            if ($mtUser && $mtPass) {
                $mail->isSMTP();
                $mail->Host       = $mtHost;
                $mail->SMTPAuth   = true;
                $mail->Username   = $mtUser;
                $mail->Password   = $mtPass;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = $mtPort;
                $configured = true;
            }
        }
        // Custom SMTP
        if (!$configured && !empty($cfg['host'])) {
            $mail->isSMTP();
            $mail->Host       = $cfg['host'];
            $mail->SMTPAuth   = !empty($cfg['username']);
            if (!empty($cfg['username'])) { $mail->Username = $cfg['username']; }
            if (!empty($cfg['password'])) { $mail->Password = $cfg['password']; }
            $secure = strtolower($cfg['secure'] ?? 'tls');
            $mail->SMTPSecure = $secure === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)($cfg['port'] ?? 587);
            $fromEmail = $fromEmail ?: ($cfg['from_email'] ?? 'no-reply@localhost');
            $configured = true;
        }
        // Fallback mail()
        if (!$configured) {
            $mail->isMail();
            $fromEmail = $fromEmail ?: 'no-reply@localhost';
        }

        $mail->SMTPDebug  = 0;
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail);
        $mail->Subject = 'Password Reset Verification Code';
        $mail->isHTML(true);
        $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $mail->Body = '<p>Hello,</p>' .
                      '<p>Use the verification code below to reset your password:</p>' .
                      '<h2 style="letter-spacing:2px;margin:16px 0;">' . $safeCode . '</h2>' .
                      '<p>Instructions:</p>' .
                      '<ol>' .
                        '<li>Return to the site and choose "Enter Verification Code".</li>' .
                        '<li>Enter the 6-digit code above to verify your identity.</li>' .
                        '<li>Create a new password when prompted.</li>' .
                      '</ol>' .
                      '<p class="text-danger" style="margin-top:12px;">Security notice: This code expires in 15 minutes. Never share this code with anyone.</p>' .
                      '<p>If you did not request a password reset, you can safely ignore this email.</p>' .
                      '<p>Regards,<br>Inventory & Stock Control System</p>';
        $mail->AltBody = "Password Reset Verification Code: $safeCode\nInstructions:\n1) Go back to the site and choose 'Enter Verification Code'\n2) Enter this 6-digit code\n3) Reset your password\nSecurity: Expires in 15 minutes. Do not share this code.";
        $mail->send();
        logEmailResult(['status' => 'success', 'to' => $toEmail, 'message' => 'sent']);
        return ['success' => true, 'message' => 'Verification email sent'];
    } catch (Exception $e) {
        $rawError = $mail->ErrorInfo ?: $e->getMessage();
        logEmailResult(['status' => 'error', 'to' => $toEmail, 'message' => $rawError]);
        $userMessage = 'We couldn\'t send the verification email. Please try again later.';
        if (stripos($rawError, 'Could not authenticate') !== false || stripos($rawError, '535') !== false) {
            $userMessage = 'Email service authentication failed. Please verify SMTP username and password in config/email.php.';
        } elseif (stripos($rawError, 'Mailer Error') !== false || stripos($rawError, 'could not connect') !== false) {
            $userMessage = 'Email service connection failed. Check SMTP host/port or use Mailtrap for local testing.';
        }
        return ['success' => false, 'message' => $userMessage];
    }
}

// Redirect to forgot password if no email in session
if (!isset($_SESSION['reset_email'])) {
    header("Location: forgot-password.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();
$user = new User($db);

$error_message = '';
$success_message = '';
$email = $_SESSION['reset_email'];
// Countdown configuration
$cooldownSeconds = 60;
$sentAt = isset($_SESSION['code_sent_at']) ? (int)$_SESSION['code_sent_at'] : 0;
$now = time();
$remainingSeconds = max(0, $cooldownSeconds - max(0, $now - $sentAt));

// Simple rate limiting for verification attempts per session/email
if (!isset($_SESSION['code_attempts'])) {
    $_SESSION['code_attempts'] = [];
}
if (!isset($_SESSION['code_attempts'][$email])) {
    $_SESSION['code_attempts'][$email] = [
        'count' => 0,
        'first' => time()
    ];
}

// Process verification code form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle resend action first
    if (isset($_POST['resend']) && $_POST['resend'] === '1') {
        if ($remainingSeconds > 0) {
            $error_message = 'Please wait ' . $remainingSeconds . ' seconds before resending the code.';
        } else {
            // Initiate a new reset code and send
            $result = $user->initiatePasswordReset($email);
            if (!empty($result['success']) && !empty($result['verification_code'])) {
                $sendResult = resendVerificationEmail($email, $result['verification_code']);
                if ($sendResult['success']) {
                    $_SESSION['code_sent_at'] = time();
                    $remainingSeconds = $cooldownSeconds;
                    $success_message = 'A new verification code has been sent. Please check your email.';
                } else {
                    $error_message = $sendResult['message'] ?? 'We couldn\'t send the verification email. Please try again.';
                }
            } else {
                // If throttled or user not found, respond generically
                $error_message = $result['message'] ?? 'We could not process a new code at the moment. Please try again later.';
            }
        }
    } else {
    $verification_code = $_POST['verification_code'] ?? '';
    
    if (empty($verification_code)) {
        $error_message = "Please enter the verification code.";
    } elseif (!preg_match('/^\d{6}$/', $verification_code)) {
        $error_message = "Verification code must be 6 digits.";
    } else {
        // Enforce attempt limit: max 5 attempts within 15 minutes
        $attempt = &$_SESSION['code_attempts'][$email];
        $attemptWindow = 15 * 60; // seconds
        if (time() - $attempt['first'] > $attemptWindow) {
            // Reset window
            $attempt['count'] = 0;
            $attempt['first'] = time();
        }
        if ($attempt['count'] >= 5) {
            $error_message = 'Too many verification attempts. Please request a new code.';
        } else {
            $attempt['count']++;
            // Verify the code
            $result = $user->verifyResetCode($email, $verification_code);
            
            if ($result['success']) {
                // Store the reset token in session for next step
                $_SESSION['reset_token'] = $result['token'];
                // Clear attempts on success
                unset($_SESSION['code_attempts'][$email]);
                header("Location: reset-password.php");
                exit();
            } else {
                $error_message = $result['message'];
            }
        }
    }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Code - Inventory Management System</title>
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
        .verification-input {
            font-size: 1.5rem;
            text-align: center;
            letter-spacing: 0.5rem;
            font-weight: bold;
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
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="card">
                    <div class="card-header">
                        <h3 class="mb-0">
                            <i class="bi bi-shield-check me-2"></i>Verify Code
                        </h3>
                        <p class="mb-0 mt-2 opacity-75">Enter the verification code sent to your email</p>
                    </div>
                    <div class="card-body p-4">
                        <!-- Progress Steps -->
                        <div class="progress-steps">
                            <div class="step completed">1</div>
                            <div class="step-line completed"></div>
                            <div class="step active">2</div>
                            <div class="step-line"></div>
                            <div class="step pending">3</div>
                        </div>
                        
                        <?php if (!empty($error_message)): ?>
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error_message; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($success_message)): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle-fill me-2"></i><?php echo $success_message; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="text-center mb-4">
                            <p class="text-muted">
                                <i class="bi bi-envelope me-2"></i>We sent a 6-digit verification code to:
                            </p>
                            <strong class="text-primary"><?php echo htmlspecialchars($email); ?></strong>
                        </div>
                        
                        <form method="POST" action="<?php echo APP_BASE; ?>/verify-code.php" id="verifyCodeForm">
                            <div class="mb-4">
                                <label for="verification_code" class="form-label text-center d-block">
                                    <i class="bi bi-key me-2"></i>Verification Code
                                </label>
                                <input type="text" class="form-control verification-input" id="verification_code" 
                                       name="verification_code" maxlength="6" pattern="\d{6}" 
                                       placeholder="000000" required autocomplete="off">
                                <div class="form-text text-center">Enter the 6-digit code from your email</div>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary" id="verifyBtn">
                                    <i class="bi bi-check-circle me-2"></i>Verify Code
                                </button>
                            </div>
                        </form>

                        <div class="mt-4 text-center">
                            <p class="text-muted mb-2">Didn't receive the code?</p>
                            <form method="POST" action="<?php echo APP_BASE; ?>/verify-code.php" class="d-inline" id="resendForm">
                                <input type="hidden" name="resend" value="1" />
                                <button type="submit" class="btn btn-outline-secondary" id="resendBtn">
                                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" id="resendSpinner" style="display:none"></span>
                                    <i class="bi bi-arrow-repeat me-2" id="resendIcon"></i>
                                    Resend Code
                                </button>
                            </form>
                            <div class="form-text mt-2" id="countdownText">
                                Please wait <span id="countdownSeconds"><?php echo (int)$remainingSeconds; ?></span>s before resending.
                            </div>
                            <div class="mt-3">
                                <a href="<?php echo APP_BASE; ?>/forgot-password.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-arrow-left me-2"></i>Back to Email Entry
                                </a>
                            </div>
                        </div>
                        
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
        // Countdown logic
        (function() {
            var remaining = <?php echo (int)$remainingSeconds; ?>;
            var cooldown = <?php echo (int)$cooldownSeconds; ?>;
            var resendBtn = document.getElementById('resendBtn');
            var countdownSeconds = document.getElementById('countdownSeconds');
            var countdownText = document.getElementById('countdownText');
            var spinner = document.getElementById('resendSpinner');
            var icon = document.getElementById('resendIcon');

            function setResendState(disabled) {
                if (disabled) {
                    resendBtn.setAttribute('disabled', 'disabled');
                    resendBtn.classList.add('disabled');
                    spinner.style.display = 'inline-block';
                    icon.style.display = 'none';
                } else {
                    resendBtn.removeAttribute('disabled');
                    resendBtn.classList.remove('disabled');
                    spinner.style.display = 'none';
                    icon.style.display = 'inline-block';
                }
            }

            function updateCountdown() {
                if (remaining > 0) {
                    setResendState(true);
                    countdownSeconds.textContent = remaining;
                    remaining--;
                } else {
                    setResendState(false);
                    countdownText.textContent = 'You can now resend a new code.';
                }
            }

            // Initialize
            updateCountdown();
            var interval = setInterval(function() {
                updateCountdown();
                if (remaining < 0) {
                    clearInterval(interval);
                }
            }, 1000);
        })();

        // Auto-format verification code input
        document.getElementById('verification_code').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, ''); // Remove non-digits
            if (value.length > 6) {
                value = value.substring(0, 6);
            }
            e.target.value = value;
        });

        // Auto-submit when 6 digits are entered
        document.getElementById('verification_code').addEventListener('input', function(e) {
            if (e.target.value.length === 6) {
                // Small delay to allow user to see the complete code
                setTimeout(() => {
                    document.getElementById('verifyCodeForm').submit();
                }, 500);
            }
        });

        // Focus on input when page loads
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('verification_code').focus();
        });
    </script>
</body>
</html>