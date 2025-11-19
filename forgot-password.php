<?php
session_start();
include_once 'config/database.php';
include_once 'models/user.php';
include_once 'config/app.php';

// Add PHPMailer (local vendor) for secure email sending
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Resolve PHPMailer src path explicitly to the provided folder
$phpMailerSrc = __DIR__ . '/PHPMailer-master/PHPMailer-master/src';
if (!is_dir($phpMailerSrc)) {
    // Fallback for alternative layout
    $alt = __DIR__ . '/PHPMailer-master/src';
    if (is_dir($alt)) {
        $phpMailerSrc = $alt;
    } else {
        die('PHPMailer src directory not found at ' . $phpMailerSrc);
    }
}
require_once $phpMailerSrc . '/Exception.php';
require_once $phpMailerSrc . '/PHPMailer.php';
require_once $phpMailerSrc . '/SMTP.php';

// Load email config if available
$GLOBALS['emailConfig'] = file_exists(__DIR__ . '/config/email.php') ? include __DIR__ . '/config/email.php' : [];

// Minimal email result logging
function logEmailResult(array $info): void {
    $logFile = __DIR__ . '/logs/email_log.txt';
    $line = date('Y-m-d H:i:s') . ' | ' . ($info['status'] ?? 'unknown') . ' | to=' . ($info['to'] ?? '') . ' | ' . ($info['message'] ?? '') . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
}

// Secure email sender using PHPMailer with config/ENV and graceful fallback
function sendVerificationEmail(string $toEmail, string $code): array {
    $mail = new PHPMailer(true);
    $cfg = $GLOBALS['emailConfig'];
    try {
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 20;
        $fromName = $cfg['from_name'] ?? getenv('EMAIL_FROM_NAME') ?: 'Inventory & Stock Control System';
        $fromEmail = $cfg['from_email'] ?? '';
        $provider  = strtolower($cfg['provider'] ?? 'gmail');

        $configured = false;
        // Provider: Gmail via App Password
        if ($provider === 'gmail') {
            $gmailUser = $cfg['username'] ?? getenv('GMAIL_USER') ?: '';
            $gmailPass = $cfg['password'] ?? getenv('GMAIL_APP_PASSWORD') ?: '';
            // Normalize Google App Password (remove spaces commonly shown as groups)
            if ($gmailPass) {
                $gmailPass = preg_replace('/\s+/', '', $gmailPass);
            }
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

        // Provider: Mailtrap (helpful for local dev)
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

        // Provider: PHP mail() (last resort; may not work on Windows)
        if (!$configured && $provider === 'mail') {
            $mail->isMail();
            $fromEmail = $fromEmail ?: 'no-reply@localhost';
            $configured = true;
        }

        // Custom SMTP fallback if config provides host/port/secure
        if (!$configured && !empty($cfg['host'])) {
            $mail->isSMTP();
            $mail->Host       = $cfg['host'];
            $mail->SMTPAuth   = !empty($cfg['username']);
            if (!empty($cfg['username'])) {
                $mail->Username = $cfg['username'];
            }
            if (!empty($cfg['password'])) {
                $mail->Password = $cfg['password'];
            }
            $secure = strtolower($cfg['secure'] ?? 'tls');
            $mail->SMTPSecure = $secure === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)($cfg['port'] ?? 587);
            $fromEmail = $fromEmail ?: ($cfg['from_email'] ?? 'no-reply@localhost');
            $configured = true;
        }

        // Final fallback: mail()
        if (!$configured) {
            $mail->isMail();
            $fromEmail = $fromEmail ?: 'no-reply@localhost';
        }

        $mail->SMTPDebug  = 0; // Disable debug output in production

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
        // Map technical errors to user-friendly messages
        $userMessage = 'We couldn\'t send the verification email. Please try again later.';
        if (stripos($rawError, 'Could not authenticate') !== false || stripos($rawError, '535') !== false) {
            $userMessage = 'Email service authentication failed. Please verify SMTP username and password in config/email.php.';
        } elseif (stripos($rawError, 'Mailer Error') !== false || stripos($rawError, 'could not connect') !== false) {
            $userMessage = 'Email service connection failed. Check SMTP host/port or use Mailtrap for local testing.';
        }
        return ['success' => false, 'message' => $userMessage];
    }
}

// Allow access to forgot password form for both logged-in and non-logged-in users
// This is useful for users who want to reset passwords for other accounts

$database = new Database();
$db = $database->getConnection();
$user = new User($db);

$error_message = '';
$success_message = '';
$verification_code = '';
$show_demo_code = false; // Keep disabled for production; do not expose codes
// Track whether a code was actually generated and sent
$code_sent = false;

// Process forgot password form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    
    if (empty($email)) {
        $error_message = "Please enter your email address.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Please enter a valid email address.";
    } else {
        // Initiate password reset (model stores code securely server-side)
        $result = $user->initiatePasswordReset($email);
        
        if (!empty($result['success'])) {
            // If throttled or generic success without code, still show success to user
            $code = $result['verification_code'] ?? '';
            if ($code) {
                $sendResult = sendVerificationEmail($email, $code);
                if ($sendResult['success']) {
                    $success_message = "If the account exists, a verification code has been sent to the provided email.";
                    $_SESSION['reset_email'] = $email;
                    // Track cooldown start for resend timer (60s)
                    $_SESSION['code_sent_at'] = time();
                    $code_sent = true;
                } else {
                    $error_message = $sendResult['message'] ?? "We couldn't send the verification email. Please try again.";
                }
            } else {
                // Throttled or generic success path: do not send email, but respond generically
                $success_message = "If the account exists, a verification code has been sent to the provided email.";
                // Do NOT set reset_email or code_sent flag to avoid verification without a valid code
                $code_sent = false;
            }
        } else {
            $success_message = "If the account exists, a verification code has been sent to the provided email.";
            // No code was generated; keep code_sent false
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Inventory & Stock Control System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo APP_BASE; ?>/assets/css/style.css">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center mt-5">
            <div class="col-md-6 col-lg-5">
                <div class="card shadow">
                    <div class="card-body p-5">
                        <h2 class="text-center mb-4">Forgot Password</h2>
                        
                        
                        <?php if (!empty($error_message)): ?>
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error_message; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($success_message)): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle-fill me-2"></i><?php echo $success_message; ?>
                            </div>
                            <?php if ($code_sent): ?>
                                <div class="d-grid gap-2 mt-3">
                                    <a href="<?php echo APP_BASE; ?>/verify-code.php" class="btn btn-success">
                                        <i class="bi bi-arrow-right me-2"></i>Enter Verification Code
                                    </a>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mt-3">If your email is registered, you will receive a verification code shortly. Otherwise, please check the email address and try again.</p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="text-muted mb-4">
                                <i class="bi bi-info-circle me-2"></i>Enter your email address and we'll send you a verification code to reset your password.
                            </p>
                            
                            <form method="POST" action="<?php echo APP_BASE; ?>/forgot-password.php" id="forgotPasswordForm">
                                <div class="mb-3">
                                    <label for="email" class="form-label">
                                        <i class="bi bi-envelope me-2"></i>Email Address
                                    </label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           placeholder="Enter your email address" required>
                                </div>
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary" id="resetBtn">
                                        <i class="bi bi-send me-2"></i>Send Verification Code
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                        
                        <div class="mt-3 text-center">
                            <a href="<?php echo APP_BASE; ?>/login.php">Back to Login</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
