<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';
require_once 'models/user.php';

$db = (new Database())->getConnection();
$user = new User($db);

echo "Testing login functionality...\n\n";

// Test cases
$testCases = [
    ['username' => 'admin', 'password' => 'admin123', 'role' => 'admin'],
    ['username' => 'contact@techsupplies.com', 'password' => 'supplier123', 'role' => 'supplier'],
    ['username' => 'sales@officeequip.com', 'password' => 'supplier123', 'role' => 'supplier'],
    ['username' => 'info@electronicswholesale.com', 'password' => 'supplier123', 'role' => 'supplier']
];

foreach ($testCases as $test) {
    echo "Testing {$test['role']} login: {$test['username']}\n";
    
    if ($user->loginAsRole($test['username'], $test['password'], $test['role'])) {
        echo "✅ SUCCESS: Login successful for {$test['username']} as {$test['role']}\n";
        
        // Show session data
        if (isset($_SESSION[$test['role']])) {
            echo "   Session data: " . json_encode($_SESSION[$test['role']]) . "\n";
        }
    } else {
        echo "❌ FAILED: Login failed for {$test['username']} as {$test['role']}\n";
    }
    echo "\n";
    
    // Clear session for next test
    session_destroy();
    session_start();
}

echo "Login testing completed!\n";
?>