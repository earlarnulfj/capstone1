<?php
// Consolidated registration with role-based success redirects
// Copy of root register with post-success redirect logic
// Note: Ensure env variables are set for email/SMS before testing

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include_once 'config/database.php';
include_once 'models/user.php';
include_once 'config/app.php';

$database = new Database();
$db = $database->getConnection();
$user = new User($db);

$register_error = '';
$register_success = '';

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
        'role' => trim($_POST['role'] ?? ''),
        'password' => $_POST['password'] ?? '',
        'confirm_password' => $_POST['confirm_password'] ?? ''
    ];
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

$preSelectedRole = $_GET['role'] ?? null;
if ($preSelectedRole && in_array($preSelectedRole, ['staff', 'management', 'supplier'])) {
    if (!isset($_POST['role']) && !isset($_SESSION['google_register_data'])) {
        $_SESSION['pre_selected_role'] = $preSelectedRole;
    }
}

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
    
    $isGoogleRegistration = isset($_SESSION['google_register_data']);
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
                    if (isset($_SESSION['google_register_data'])) { unset($_SESSION['google_register_data']); }
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

echo "Optimized register.php ready";