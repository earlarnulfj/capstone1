<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include_once '../config/database.php';
include_once '../models/user.php';
include_once '../config/app.php';
// Calculate root APP_BASE first
$rootBase = defined('APP_BASE') ? APP_BASE : '';
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
if (preg_match('#/(admin|staff|supplier)$#', $rootBase)) {
    $rootBase = preg_replace('#/(admin|staff|supplier)$#', '', $rootBase);
} elseif (preg_match('#/(admin|staff|supplier)$#', $scriptDir)) {
    $rootBase = preg_replace('#/(admin|staff|supplier)$#', '', $scriptDir);
    $rootBase = str_replace('\\', '/', $rootBase);
    if ($rootBase === '/' || $rootBase === '\\' || $rootBase === '.' || empty($rootBase)) {
        $rootBase = '';
    }
}

include_once '../database/check_columns.php';

// Check if database columns exist, if not show helpful message (after $rootBase is calculated)
if (!checkDatabaseColumns() && empty($register_error)) {
    $register_error = "Database setup required: Missing profile columns. Please run: <a href='" . $rootBase . "/database/fix_now.php' target='_blank'>Database Migration Tool</a> or visit <code>database/fix_now.php</code> in your browser.";
}

$database = new Database();
$db = $database->getConnection();
$user = new User($db);

// Initialize error variables (must be before column check)
$register_error = '';
$register_success = '';

// Handle saving form data before Google verification
if (isset($_GET['save_form_data']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['temp_registration_data'] = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'middle_name' => trim($_POST['middle_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'username' => trim($_POST['username'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'city' => trim($_POST['city'] ?? ''),
        'province' => trim($_POST['province'] ?? ''),
        'postal_code' => trim($_POST['postal_code'] ?? ''),
        'role' => 'supplier', // Fixed for supplier
        'password' => $_POST['password'] ?? '',
        'confirm_password' => $_POST['confirm_password'] ?? ''
    ];
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// Handle Google OAuth data (from callback)
$googleData = null;
if (isset($_GET['google_auth']) && isset($_SESSION['google_register_data'])) {
    $googleData = $_SESSION['google_register_data'];
    if (isset($_SESSION['temp_registration_data'])) {
        $googleData = array_merge($_SESSION['temp_registration_data'], $googleData);
        $googleData['email'] = $_SESSION['google_register_data']['email'];
        $googleData['picture'] = $_SESSION['google_register_data']['picture'] ?? null;
        unset($_SESSION['temp_registration_data']);
    }
}

// Process registration form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $email = $_POST['email'] ?? '';
    $role = 'supplier'; // Fixed for supplier registration
    $phone = $_POST['phone'] ?? '';
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $postal_code = trim($_POST['postal_code'] ?? '');
    
    $isGoogleRegistration = isset($_SESSION['google_register_data']);
    $passwordRequired = !$isGoogleRegistration;
    
    if (empty($first_name) || empty($last_name) || empty($username) || empty($email) || 
        empty($phone) || empty($address) || empty($city) || empty($province) || empty($postal_code) ||
        ($passwordRequired && (empty($password) || empty($confirm_password)))) {
        $register_error = "Please fill in all required fields (including address information for deliveries).";
    } else if (!empty($password) && $password !== $confirm_password) {
        $register_error = "Passwords do not match.";
    } else if (!empty($password) && strlen($password) < 6) {
        $register_error = "Password must be at least 6 characters long.";
    } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $register_error = "Please enter a valid email address.";
    } else if (empty($phone) || !preg_match('/^\d{11}$/', $phone)) {
        $register_error = "Phone number is required and must be exactly 11 digits.";
    } else {
        if ($user->usernameExists($username)) {
            $register_error = "Username already exists. Please choose another.";
        } else if ($user->emailExists($email)) {
            $register_error = "Email already exists. Please use another email address.";
        } else {
            $finalPassword = $password;
            if (empty($finalPassword) && $isGoogleRegistration) {
                $finalPassword = bin2hex(random_bytes(16));
            }
            
            try {
                $profile_picture = null;
                if ($isGoogleRegistration && isset($_SESSION['google_register_data']['picture'])) {
                    $profile_picture = $_SESSION['google_register_data']['picture'];
                }
                
                if ($user->create($username, $finalPassword, $role, $email, $phone, $first_name, $middle_name, $last_name, $address, $city, $province, $postal_code, $profile_picture)) {
                    if (isset($_SESSION['google_register_data'])) {
                        unset($_SESSION['google_register_data']);
                    }
                    // Redirect to supplier login page after successful registration
                    header('Location: login.php?success=1');
                    exit;
                } else {
                    $register_error = "Registration failed. Please check the error logs or try again.";
                }
            } catch (PDOException $e) {
                $errorMsg = $e->getMessage();
                $errorCode = $e->getCode();
                
                // Check for duplicate entry errors first
                if ($errorCode == 23000 || strpos($errorMsg, '1062') !== false || strpos($errorMsg, 'Duplicate entry') !== false) {
                    if (strpos($errorMsg, 'username') !== false) {
                        $register_error = "Username already exists. Please choose a different username.";
                    } elseif (strpos($errorMsg, 'email') !== false) {
                        $register_error = "Email address already exists. Please use a different email or try logging in instead.";
                    } else {
                        $register_error = "A record with this information already exists. Please check your username and email.";
                    }
                } elseif (strpos($errorMsg, "Unknown column") !== false || strpos($errorMsg, "Column") !== false || strpos($errorMsg, "1054") !== false) {
                    $register_error = "Database schema error: Missing required columns. <strong>Quick Fix:</strong> <a href='" . $rootBase . "/database/fix_now.php' target='_blank' class='btn btn-sm btn-primary'>Run Database Migration</a> or visit <code>database/fix_now.php</code> in your browser.";
                } else {
                    $register_error = "Registration failed: " . htmlspecialchars($errorMsg);
                }
                error_log("Registration PDO error: " . $errorMsg);
            } catch (Exception $e) {
                $errorMsg = $e->getMessage();
                
                // Check for duplicate entry errors first
                if (strpos($errorMsg, 'Username already exists') !== false || 
                    strpos($errorMsg, 'Email address already exists') !== false ||
                    strpos($errorMsg, 'Duplicate entry') !== false ||
                    strpos($errorMsg, '1062') !== false) {
                    $register_error = htmlspecialchars($errorMsg);
                } elseif (strpos($errorMsg, "Unknown column") !== false || strpos($errorMsg, "Column") !== false || strpos($errorMsg, "1054") !== false) {
                    $register_error = "Database schema error: Missing required columns. <strong>Quick Fix:</strong> <a href='" . $rootBase . "/database/fix_now.php' target='_blank' class='btn btn-sm btn-primary'>Run Database Migration</a> or visit <code>database/fix_now.php</code> in your browser.";
                } else {
                    $register_error = "Registration failed: " . htmlspecialchars($errorMsg);
                }
                error_log("Registration error: " . $errorMsg);
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
    <title>Supplier Registration - Inventory & Stock Control System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?php echo $rootBase; ?>/assets/css/style.css">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center mt-5">
            <div class="col-md-6 col-lg-5">
                <div class="card shadow">
                    <div class="card-body p-5">
                        <h2 class="text-center mb-4">
                            <i class="bi bi-truck text-warning me-2"></i>Supplier Registration
                        </h2>

                        <?php if (!empty($register_error)): ?>
                            <div class="alert alert-danger"><?php echo $register_error; ?></div>
                        <?php endif; ?>
                        
                        <?php if (!empty($register_success)): ?>
                            <div class="alert alert-success"><?php echo $register_success; ?></div>
                        <?php endif; ?>
                        
                        <?php if (isset($_GET['error'])): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($_GET['error']); ?></div>
                        <?php endif; ?>
                        
                        <?php if ($googleData): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Google Account Connected!</strong> Your information has been pre-filled. Please review and complete the form.
                                <?php if (!empty($googleData['picture'])): ?>
                                    <img src="<?php echo htmlspecialchars($googleData['picture']); ?>" alt="Profile" class="rounded-circle ms-2" width="32" height="32">
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="" id="registrationForm">
                            <!-- Name Fields -->
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="first_name" name="first_name"
                                               value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : (isset($googleData['first_name']) ? htmlspecialchars($googleData['first_name']) : ''); ?>" required maxlength="100">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="middle_name" class="form-label">Middle Name</label>
                                        <input type="text" class="form-control" id="middle_name" name="middle_name"
                                               value="<?php echo isset($_POST['middle_name']) ? htmlspecialchars($_POST['middle_name']) : (isset($googleData['middle_name']) ? htmlspecialchars($googleData['middle_name']) : ''); ?>" maxlength="100">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="last_name" name="last_name"
                                               value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : (isset($googleData['last_name']) ? htmlspecialchars($googleData['last_name']) : ''); ?>" required maxlength="100">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="username" name="username" 
                                       value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : (isset($googleData['username']) ? htmlspecialchars($googleData['username']) : ''); ?>" 
                                       required minlength="3" maxlength="50">
                                <div class="form-text">Username must be 3-50 characters long.</div>
                            </div>
                            <div class="mb-3">
                                <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : (isset($googleData['email']) ? htmlspecialchars($googleData['email']) : ''); ?>" 
                                       required <?php echo ($googleData ? 'readonly' : ''); ?>>
                                <?php if ($googleData): ?>
                                    <div class="form-text text-info">
                                        <i class="bi bi-lock-fill me-1"></i>Email from your Google account
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control" id="phone" name="phone"
                                       inputmode="numeric" pattern="[0-9]{11}" maxlength="11"
                                       value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : (isset($googleData['phone']) ? htmlspecialchars($googleData['phone']) : ''); ?>"
                                       placeholder="11-digit mobile number" required>
                                <div class="form-text">Phone number must be exactly 11 digits.</div>
                            </div>
                            
                            <!-- Address Fields -->
                            <div class="mb-3">
                                <label for="address" class="form-label">Street Address <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="address" name="address" rows="2" required placeholder="House/Unit number, Street name"><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : (isset($googleData['address']) ? htmlspecialchars($googleData['address']) : ''); ?></textarea>
                                <div class="form-text">This address will be used for deliveries and your profile.</div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="city" class="form-label">City <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="city" name="city" 
                                               value="<?php echo isset($_POST['city']) ? htmlspecialchars($_POST['city']) : (isset($googleData['city']) ? htmlspecialchars($googleData['city']) : ''); ?>" 
                                               required maxlength="100" placeholder="City">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="province" class="form-label">Province/State <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="province" name="province" 
                                               value="<?php echo isset($_POST['province']) ? htmlspecialchars($_POST['province']) : (isset($googleData['province']) ? htmlspecialchars($googleData['province']) : ''); ?>" 
                                               required maxlength="100" placeholder="Province or State">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="postal_code" class="form-label">Postal Code <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="postal_code" name="postal_code" 
                                               value="<?php echo isset($_POST['postal_code']) ? htmlspecialchars($_POST['postal_code']) : (isset($googleData['postal_code']) ? htmlspecialchars($googleData['postal_code']) : ''); ?>" 
                                               required maxlength="20" placeholder="Postal/ZIP code">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="password" class="form-label">Password <?php echo ($googleData ? '' : '<span class="text-danger">*</span>'); ?></label>
                                <input type="password" class="form-control" id="password" name="password" 
                                       <?php echo ($googleData ? '' : 'required'); ?> minlength="6">
                                <div class="form-text">
                                    <?php if ($googleData): ?>
                                        <i class="bi bi-info-circle me-1"></i>Optional: You can login with Google, but setting a password allows traditional login too.
                                    <?php else: ?>
                                        Password must be at least 6 characters long.
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm Password <?php echo ($googleData ? '' : '<span class="text-danger">*</span>'); ?></label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" <?php echo ($googleData ? '' : 'required'); ?>>
                            </div>
                            
                            <input type="hidden" name="role" value="supplier">
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-warning btn-lg">Create Supplier Account</button>
                            </div>
                        </form>
                        
                        <!-- Google Verification Option -->
                        <?php if (!isset($googleData)): ?>
                            <div class="mt-4">
                                <div class="d-flex align-items-center mb-3">
                                    <hr class="flex-grow-1">
                                    <span class="mx-2 text-muted">Or</span>
                                    <hr class="flex-grow-1">
                                </div>
                                <div class="text-center text-muted mb-3">
                                    <small>After filling the form above, verify with Google</small>
                                </div>
                                <div class="d-grid">
                                    <button type="button" class="btn btn-outline-success" onclick="verifyWithGoogle()">
                                        <svg width="18" height="18" class="me-2" viewBox="0 0 24 24">
                                            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                                            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                                            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                                            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                                        </svg>
                                        Verify & Complete with Google
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="mt-3 text-center">
                            <span>Already have an account?</span>
                            <a href="login.php">Login</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function verifyWithGoogle() {
            const form = document.getElementById('registrationForm');
            const formData = new FormData(form);
            
            const firstName = formData.get('first_name')?.trim();
            const lastName = formData.get('last_name')?.trim();
            const email = formData.get('email')?.trim();
            const phone = formData.get('phone')?.trim();
            const address = formData.get('address')?.trim();
            const city = formData.get('city')?.trim();
            const province = formData.get('province')?.trim();
            const postalCode = formData.get('postal_code')?.trim();
            
            if (!firstName || !lastName || !email || !phone || !address || !city || !province || !postalCode) {
                alert('Please fill in all required fields before verifying with Google.');
                return;
            }
            
            if (!/^\d{11}$/.test(phone)) {
                alert('Phone number must be exactly 11 digits.');
                return;
            }
            
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                alert('Please enter a valid email address.');
                return;
            }
            
            fetch('?save_form_data=1', {
                method: 'POST',
                body: formData
            }).then(function(response) {
                window.location.href = '<?php echo $rootBase; ?>/auth/google/register.php?role=supplier';
            }).catch(function(error) {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        }
        
        document.getElementById('phone').addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 11);
        });
    </script>
</body>
</html>

