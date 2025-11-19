<?php
// Test script for password reset functionality
include_once 'config/database.php';
include_once 'models/user.php';

$database = new Database();
$db = $database->getConnection();
$user = new User($db);

echo "<h2>Testing Password Reset Functionality</h2>";

// Test 1: Initiate password reset for existing email
echo "<h3>Test 1: Initiate Password Reset</h3>";
$test_email = "vpvillanueva.chmsu@gmail.com"; // Use an email that exists in your users table
$result = $user->initiatePasswordReset($test_email);

if ($result['success']) {
    echo "<p style='color: green;'>✅ SUCCESS: " . $result['message'] . "</p>";
    if (isset($result['verification_code'])) {
        echo "<p><strong>Verification Code:</strong> " . $result['verification_code'] . "</p>";
        
        // Test 2: Verify the code
        echo "<h3>Test 2: Verify Code</h3>";
        $verify_result = $user->verifyResetCode($test_email, $result['verification_code']);
        
        if ($verify_result['success']) {
            echo "<p style='color: green;'>✅ SUCCESS: " . $verify_result['message'] . "</p>";
            echo "<p><strong>Reset Token:</strong> " . $verify_result['token'] . "</p>";
            
            // Test 3: Reset password
            echo "<h3>Test 3: Reset Password</h3>";
            $new_password = "newpassword123";
            $reset_result = $user->resetPassword($test_email, $verify_result['token'], $new_password);
            
            if ($reset_result['success']) {
                echo "<p style='color: green;'>✅ SUCCESS: " . $reset_result['message'] . "</p>";
            } else {
                echo "<p style='color: red;'>❌ ERROR: " . $reset_result['message'] . "</p>";
            }
        } else {
            echo "<p style='color: red;'>❌ ERROR: " . $verify_result['message'] . "</p>";
        }
    }
} else {
    echo "<p style='color: red;'>❌ ERROR: " . $result['message'] . "</p>";
}

// Test with non-existent email
echo "<h3>Test 4: Non-existent Email</h3>";
$fake_email = "nonexistent@example.com";
$result2 = $user->initiatePasswordReset($fake_email);

if (!$result2['success']) {
    echo "<p style='color: green;'>✅ EXPECTED: " . $result2['message'] . "</p>";
} else {
    echo "<p style='color: red;'>❌ UNEXPECTED: Should have failed for non-existent email</p>";
}

echo "<h3>Database Check</h3>";
echo "<p>Checking password_reset_tokens table...</p>";

try {
    $query = "SELECT COUNT(*) as count FROM password_reset_tokens";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p>Total tokens in database: " . $row['count'] . "</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Database error: " . $e->getMessage() . "</p>";
}
?>