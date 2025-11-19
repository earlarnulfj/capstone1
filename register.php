<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include_once 'config/database.php';
include_once 'models/user.php';
include_once 'config/app.php';

// Check if user is already logged in
$is_logged_in = isset($_SESSION['user_id']);
$current_user_role = $is_logged_in ? $_SESSION['role'] : null;
$current_username = $is_logged_in ? $_SESSION['username'] : null;

$database = new Database();
$db = $database->getConnection();
$user = new User($db);

$register_error = '';
$register_success = '';

// Handle saving form data before Google verification
if (isset($_GET['save_form_data']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Save form data to session for retrieval after Google verification
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
        'role' => trim($_POST['role'] ?? ''),
        'password' => $_POST['password'] ?? '',
        'confirm_password' => $_POST['confirm_password'] ?? ''
    ];
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// Handle role from URL parameter (from login page sign up button)
$preSelectedRole = $_GET['role'] ?? null;
if ($preSelectedRole && in_array($preSelectedRole, ['staff', 'management', 'supplier'])) {
    // Pre-select the role if coming from a login page
    if (!isset($_POST['role']) && !isset($_SESSION['google_register_data'])) {
        $_SESSION['pre_selected_role'] = $preSelectedRole;
    }
}

// Handle Google OAuth data (from callback)
$googleData = null;
if (isset($_GET['google_auth']) && isset($_SESSION['google_register_data'])) {
    $googleData = $_SESSION['google_register_data'];
    // Merge with saved form data if available (user filled form, then verified with Google)
    if (isset($_SESSION['temp_registration_data'])) {
        $googleData = array_merge($_SESSION['temp_registration_data'], $googleData);
        // Keep Google's email and picture, but use form data for address fields
        $googleData['email'] = $_SESSION['google_register_data']['email']; // Keep Google email
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
    $role = $_POST['role'] ?? 'staff';
    $phone = $_POST['phone'] ?? '';
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $postal_code = trim($_POST['postal_code'] ?? '');
    
    // Check if this is a Google registration (password might be optional)
    $isGoogleRegistration = isset($_SESSION['google_register_data']);
    
    // Validate required fields (including name requirements and address for deliveries)
    // Password is optional for Google registrations
    $passwordRequired = !$isGoogleRegistration;
    if (empty($first_name) || empty($last_name) || empty($username) || empty($email) || empty($role) || 
        empty($phone) || empty($address) || empty($city) || empty($province) || empty($postal_code) ||
        ($passwordRequired && (empty($password) || empty($confirm_password)))) {
        $register_error = "Please fill in all required fields (including address information for deliveries).";
    } else if (!empty($password) && $password !== $confirm_password) {
        $register_error = "Passwords do not match.";
    } else if (!in_array($role, ['staff', 'management', 'supplier'])) {
        $register_error = "Invalid role selected.";
    } else if (!empty($password) && strlen($password) < 6) {
        $register_error = "Password must be at least 6 characters long.";
    } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $register_error = "Please enter a valid email address.";
    } else if (empty($phone) || !preg_match('/^\d{11}$/', $phone)) {
        $register_error = "Phone number is required and must be exactly 11 digits.";
    } else {
        // Check if username already exists
        if ($user->usernameExists($username)) {
            $register_error = "Username already exists. Please choose another.";
        } else if ($user->emailExists($email)) {
            $register_error = "Email already exists. Please use another email address.";
        } else {
            // Create user
            // If password is empty (Google registration without password), generate a random one
            $finalPassword = $password;
            if (empty($finalPassword) && $isGoogleRegistration) {
                // Generate a random password (user can still login with Google)
                $finalPassword = bin2hex(random_bytes(16));
            }
            
            try {
                // Get profile picture from Google data if available
                $profile_picture = null;
                if ($isGoogleRegistration && isset($_SESSION['google_register_data']['picture'])) {
                    $profile_picture = $_SESSION['google_register_data']['picture'];
                }
                
                if ($user->create($username, $finalPassword, $role, $email, $phone, $first_name, $middle_name, $last_name, $address, $city, $province, $postal_code, $profile_picture)) {
                    if (isset($_SESSION['google_register_data'])) {
                        unset($_SESSION['google_register_data']);
                    }
                    $base = defined('APP_BASE') ? APP_BASE : '';
                    if ($base === '/' || $base === '\\') $base = '';
                    $loginPath = $role === 'management' ? '/admin/login.php' : ($role === 'supplier' ? '/supplier/login.php' : '/staff/login.php');
                    header('Location: ' . $base . $loginPath . '?success=1');
                    exit;
                } else {
                    $register_error = "Registration failed. Please check the error logs or try again.";
                }
            } catch (Exception $e) {
                $register_error = "Registration failed: " . $e->getMessage();
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
    <title>Register - Inventory & Stock Control System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-light">
    <div class="container">
        <div class="row justify-content-center mt-5">
            <div class="col-md-6 col-lg-5">
                <div class="card shadow">
                    <div class="card-body p-5">
                        <h2 class="text-center mb-4">Create Account</h2>
                        

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
                        
                        <!-- Google Registration Options -->
                        <div class="mb-4">
                            <div class="d-flex align-items-center mb-3">
                                <hr class="flex-grow-1">
                                <span class="mx-2 text-muted">Or</span>
                                <hr class="flex-grow-1">
                            </div>
                            <div class="text-center text-muted mb-3">
                                <small>Register with Google</small>
                            </div>
                            <div class="d-grid gap-2">
                                <a href="<?php echo APP_BASE; ?>/auth/google/register.php?role=staff" class="btn btn-outline-danger">
                                    <svg width="18" height="18" class="me-2" viewBox="0 0 24 24">
                                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                                    </svg>
                                    Register as Staff with Google
                                </a>
                                <a href="<?php echo APP_BASE; ?>/auth/google/register.php?role=management" class="btn btn-outline-danger">
                                    <svg width="18" height="18" class="me-2" viewBox="0 0 24 24">
                                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                                    </svg>
                                    Register as Admin with Google
                                </a>
                                <a href="<?php echo APP_BASE; ?>/auth/google/register.php?role=supplier" class="btn btn-outline-danger">
                                    <svg width="18" height="18" class="me-2" viewBox="0 0 24 24">
                                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                                    </svg>
                                    Register as Supplier with Google
                                </a>
                            </div>
                            <div class="mt-2 text-center">
                                <small class="text-muted">Google will auto-fill your information</small>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <h5 class="text-center mb-3">Or Fill Out the Form Manually</h5>
                        
                        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" id="registrationForm">
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
                                       value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>"
                                       placeholder="11-digit mobile number" required>
                                <div class="form-text">Phone number must be exactly 11 digits.</div>
                            </div>
                            
                            <!-- Address Fields for Delivery -->
                            <div class="mb-3">
                                <label for="address" class="form-label">Street Address <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="address" name="address" rows="2" required placeholder="House/Unit number, Street name"><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : (isset($googleData) ? '' : ''); ?></textarea>
                                <div class="form-text">This address will be used for deliveries and your profile.</div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="city" class="form-label">City <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="city" name="city" 
                                               value="<?php echo isset($_POST['city']) ? htmlspecialchars($_POST['city']) : ''; ?>" 
                                               required maxlength="100" placeholder="City">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="province" class="form-label">Province/State <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="province" name="province" 
                                               value="<?php echo isset($_POST['province']) ? htmlspecialchars($_POST['province']) : ''; ?>" 
                                               required maxlength="100" placeholder="Province or State">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="postal_code" class="form-label">Postal Code <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="postal_code" name="postal_code" 
                                               value="<?php echo isset($_POST['postal_code']) ? htmlspecialchars($_POST['postal_code']) : ''; ?>" 
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
                            <div class="mb-3">
                                <label for="role" class="form-label">Account Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="role" name="role" required <?php echo ($googleData ? 'disabled' : ''); ?>>
                                    <option value="">Select your role...</option>
                                    <?php
                                    // Determine selected role: POST > Google data > pre-selected from URL > empty
                                    $selectedRole = '';
                                    if (isset($_POST['role'])) {
                                        $selectedRole = $_POST['role'];
                                    } elseif (isset($googleData['role'])) {
                                        $selectedRole = $googleData['role'] === 'admin' ? 'management' : $googleData['role'];
                                    } elseif (isset($_SESSION['pre_selected_role'])) {
                                        $selectedRole = $_SESSION['pre_selected_role'];
                                    }
                                    ?>
                                    <option value="staff" <?php echo ($selectedRole === 'staff') ? 'selected' : ''; ?>>
                                        Staff Member
                                    </option>
                                    <option value="management" <?php echo ($selectedRole === 'management') ? 'selected' : ''; ?>>
                                        Management
                                    </option>
                                    <option value="supplier" <?php echo ($selectedRole === 'supplier') ? 'selected' : ''; ?>>
                                        Supplier
                                    </option>
                                </select>
                                <?php if ($googleData): ?>
                                    <input type="hidden" name="role" value="<?php echo htmlspecialchars($googleData['role'] === 'admin' ? 'management' : $googleData['role']); ?>">
                                    <div class="form-text text-info">
                                        <i class="bi bi-lock-fill me-1"></i>Role selected via Google registration
                                    </div>
                                <?php elseif ($preSelectedRole): ?>
                                    <div class="form-text text-info">
                                        <i class="bi bi-info-circle me-1"></i>Role selected from login page
                                    </div>
                                <?php endif; ?>
                                <div class="form-text">
                                    <small>
                                        <strong>Staff:</strong> Access to POS and inventory management<br>
                                        <strong>Management:</strong> Full administrative access<br>
                                        <strong>Supplier:</strong> Manage products, orders, and deliveries
                                    </small>
                                </div>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg">Create Account</button>
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
                                    <button type="button" class="btn btn-outline-success" id="verifyWithGoogleBtn" onclick="verifyWithGoogle()">
                                        <svg width="18" height="18" class="me-2" viewBox="0 0 24 24">
                                            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                                            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                                            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                                            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                                        </svg>
                                        Verify & Complete with Google
                                    </button>
                                </div>
                                <div class="mt-2 text-center">
                                    <small class="text-muted">Fill your information first, then verify with Google to complete registration</small>
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
        // Enhanced form validation
        document.getElementById('registrationForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const role = document.getElementById('role').value;
            const firstNameEl = document.getElementById('first_name');
            const lastNameEl = document.getElementById('last_name');
            const phoneEl = document.getElementById('phone');
            const phoneVal = phoneEl.value.trim();
            
            // Clear previous custom validity
            document.getElementById('confirm_password').setCustomValidity('');
            document.getElementById('role').setCustomValidity('');
            firstNameEl.setCustomValidity('');
            lastNameEl.setCustomValidity('');
            phoneEl.setCustomValidity('');
            
            // Password confirmation validation
            if (password !== confirmPassword) {
                document.getElementById('confirm_password').setCustomValidity('Passwords do not match');
                e.preventDefault();
                return false;
            }
            
            // Role selection validation
            if (!role) {
                document.getElementById('role').setCustomValidity('Please select an account type');
                e.preventDefault();
                return false;
            }
            
            // Name fields required
            if (!firstNameEl.value.trim()) {
                firstNameEl.setCustomValidity('First name is required');
                e.preventDefault();
                return false;
            }
            if (!lastNameEl.value.trim()) {
                lastNameEl.setCustomValidity('Last name is required');
                e.preventDefault();
                return false;
            }
            
            // Phone validation: required, must be exactly 11 digits
            const digitsOnly = /^\d{11}$/;
            if (!digitsOnly.test(phoneVal)) {
                phoneEl.setCustomValidity('Phone is required and must be exactly 11 digits');
                e.preventDefault();
                return false;
            }
            
            // Address validation: all address fields are required
            const addressEl = document.getElementById('address');
            const cityEl = document.getElementById('city');
            const provinceEl = document.getElementById('province');
            const postalCodeEl = document.getElementById('postal_code');
            
            addressEl.setCustomValidity('');
            cityEl.setCustomValidity('');
            provinceEl.setCustomValidity('');
            postalCodeEl.setCustomValidity('');
            
            if (!addressEl.value.trim()) {
                addressEl.setCustomValidity('Street address is required');
                e.preventDefault();
                return false;
            }
            if (!cityEl.value.trim()) {
                cityEl.setCustomValidity('City is required');
                e.preventDefault();
                return false;
            }
            if (!provinceEl.value.trim()) {
                provinceEl.setCustomValidity('Province/State is required');
                e.preventDefault();
                return false;
            }
            if (!postalCodeEl.value.trim()) {
                postalCodeEl.setCustomValidity('Postal code is required');
                e.preventDefault();
                return false;
            }
            
            return true;
        });
        
        // Enforce numeric-only input and length on phone
        document.getElementById('phone').addEventListener('input', function() {
            // Strip non-digits and limit to 11 characters
            this.value = this.value.replace(/\D/g, '').slice(0, 11);
        });
        
        // Visual feedback for address fields
        ['address', 'city', 'province', 'postal_code'].forEach(function(fieldId) {
            const field = document.getElementById(fieldId);
            if (field) {
                field.addEventListener('blur', function() {
                    if (this.value.trim()) {
                        this.classList.add('is-valid');
                        this.classList.remove('is-invalid');
                    } else {
                        this.classList.add('is-invalid');
                        this.classList.remove('is-valid');
                    }
                });
            }
        });
        
        // Google verification function - saves form data and redirects to Google
        window.verifyWithGoogle = function() {
            const form = document.getElementById('registrationForm');
            const formData = new FormData(form);
            
            // Validate required fields before allowing Google verification
            const firstName = formData.get('first_name')?.trim();
            const lastName = formData.get('last_name')?.trim();
            const email = formData.get('email')?.trim();
            const phone = formData.get('phone')?.trim();
            const address = formData.get('address')?.trim();
            const city = formData.get('city')?.trim();
            const province = formData.get('province')?.trim();
            const postalCode = formData.get('postal_code')?.trim();
            const role = formData.get('role')?.trim();
            
            if (!firstName || !lastName || !email || !phone || !address || !city || !province || !postalCode || !role) {
                alert('Please fill in all required fields before verifying with Google.');
                return;
            }
            
            // Validate phone format
            if (!/^\d{11}$/.test(phone)) {
                alert('Phone number must be exactly 11 digits.');
                return;
            }
            
            // Validate email
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                alert('Please enter a valid email address.');
                return;
            }
            
            // Store form data in session via AJAX before redirecting to Google
            fetch('<?php echo APP_BASE; ?>/register.php?save_form_data=1', {
                method: 'POST',
                body: formData
            }).then(function(response) {
                // Map role for Google registration
                let googleRole = role;
                if (role === 'management') {
                    googleRole = 'management';
                }
                
                // Redirect to Google registration
                window.location.href = '<?php echo APP_BASE; ?>/auth/google/register.php?role=' + encodeURIComponent(googleRole);
            }).catch(function(error) {
                console.error('Error saving form data:', error);
                alert('An error occurred. Please try again.');
            });
        };
        
        // Real-time password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            
            if (password !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
                this.classList.add('is-invalid');
            } else {
                this.setCustomValidity('');
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
            }
        });
        
        // Password strength indicator
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strengthText = this.parentNode.querySelector('.form-text');
            
            if (password.length < 6) {
                strengthText.textContent = 'Password must be at least 6 characters long.';
                strengthText.className = 'form-text text-danger';
            } else if (password.length < 8) {
                strengthText.textContent = 'Password strength: Weak';
                strengthText.className = 'form-text text-warning';
            } else if (password.match(/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/)) {
                strengthText.textContent = 'Password strength: Strong';
                strengthText.className = 'form-text text-success';
            } else {
                strengthText.textContent = 'Password strength: Medium';
                strengthText.className = 'form-text text-info';
            }
        });
        
        // Role selection change handler
        document.getElementById('role').addEventListener('change', function() {
            const roleDescriptions = {
                'staff': 'Staff members can access the POS system and manage inventory.',
                'management': 'Management has full administrative access to all system features.',
                'supplier': 'Suppliers can manage their products, orders, and delivery schedules.'
            };
            
            const helpText = this.parentNode.querySelector('.form-text small');
            if (this.value && roleDescriptions[this.value]) {
                helpText.innerHTML = '<strong>' + this.options[this.selectedIndex].text + ':</strong> ' + 
                                   roleDescriptions[this.value];
            } else {
                helpText.innerHTML = '<strong>Staff:</strong> Access to POS and inventory management<br>' +
                                   '<strong>Management:</strong> Full administrative access<br>' +
                                   '<strong>Supplier:</strong> Manage products, orders, and deliveries';
            }
        });
    </script>
</body>
</html>
