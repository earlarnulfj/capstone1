<?php
/**
 * Simple Database Migration Runner
 * Run this file via browser to add missing database columns
 * 
 * Access via: http://localhost/haha/database/run_migration.php
 */

// Enable error display for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Migration - Add Profile Fields</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #007bff;
            padding-bottom: 10px;
        }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            border: 1px solid #c3e6cb;
            margin: 10px 0;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            border: 1px solid #f5c6cb;
            margin: 10px 0;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            padding: 15px;
            border-radius: 5px;
            border: 1px solid #bee5eb;
            margin: 10px 0;
        }
        .button {
            display: inline-block;
            background: #007bff;
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
            border: none;
            cursor: pointer;
            font-size: 16px;
        }
        .button:hover {
            background: #0056b3;
        }
        ul {
            list-style: none;
            padding-left: 0;
        }
        li {
            padding: 8px;
            margin: 5px 0;
            border-left: 4px solid #ddd;
            padding-left: 15px;
        }
        .exists {
            border-left-color: #28a745;
            background: #f0f9f0;
        }
        .added {
            border-left-color: #007bff;
            background: #e7f3ff;
        }
        .failed {
            border-left-color: #dc3545;
            background: #ffeaea;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>🗄️ Database Migration: Add Profile Fields</h1>
    
<?php

include_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "<p>Checking and adding required columns...</p>";
    
    // Check users table columns
    $stmt = $db->query("SHOW COLUMNS FROM users");
    $existingColumns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existingColumns[] = $row['Field'];
    }
    
    echo "<h3>Users Table:</h3>";
    echo "<ul>";
    
    $columnsToAdd = [
        'first_name' => "ALTER TABLE `users` ADD COLUMN `first_name` VARCHAR(100) NULL AFTER `email`",
        'middle_name' => "ALTER TABLE `users` ADD COLUMN `middle_name` VARCHAR(100) NULL AFTER `first_name`",
        'last_name' => "ALTER TABLE `users` ADD COLUMN `last_name` VARCHAR(100) NULL AFTER `middle_name`",
        'address' => "ALTER TABLE `users` ADD COLUMN `address` TEXT NULL AFTER `phone`",
        'city' => "ALTER TABLE `users` ADD COLUMN `city` VARCHAR(100) NULL AFTER `address`",
        'province' => "ALTER TABLE `users` ADD COLUMN `province` VARCHAR(100) NULL AFTER `city`",
        'postal_code' => "ALTER TABLE `users` ADD COLUMN `postal_code` VARCHAR(20) NULL AFTER `province`",
        'profile_picture' => "ALTER TABLE `users` ADD COLUMN `profile_picture` VARCHAR(255) NULL AFTER `postal_code`"
    ];
    
    $addedColumns = 0;
    $failedColumns = [];
    
    foreach ($columnsToAdd as $columnName => $sql) {
        if (in_array($columnName, $existingColumns)) {
            echo "<li class='exists'>✓ Column '<strong>$columnName</strong>' already exists</li>";
        } else {
            try {
                $db->exec($sql);
                echo "<li class='added'>✓ Added column '<strong>$columnName</strong>'</li>";
                $addedColumns++;
            } catch (PDOException $e) {
                $errorMsg = $e->getMessage();
                echo "<li class='failed'>✗ Failed to add column '<strong>$columnName</strong>': " . htmlspecialchars($errorMsg) . "</li>";
                $failedColumns[] = $columnName;
            }
        }
    }
    
    echo "</ul>";
    
    // Check suppliers table columns
    $stmt = $db->query("SHOW COLUMNS FROM suppliers");
    $existingSupplierColumns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existingSupplierColumns[] = $row['Field'];
    }
    
    echo "<h3>Suppliers Table:</h3>";
    echo "<ul>";
    
    $supplierColumnsToAdd = [
        'city' => "ALTER TABLE `suppliers` ADD COLUMN `city` VARCHAR(100) NULL AFTER `address`",
        'province' => "ALTER TABLE `suppliers` ADD COLUMN `province` VARCHAR(100) NULL AFTER `city`",
        'postal_code' => "ALTER TABLE `suppliers` ADD COLUMN `postal_code` VARCHAR(20) NULL AFTER `province`"
    ];
    
    foreach ($supplierColumnsToAdd as $columnName => $sql) {
        if (in_array($columnName, $existingSupplierColumns)) {
            echo "<li class='exists'>✓ Column '<strong>$columnName</strong>' already exists</li>";
        } else {
            try {
                $db->exec($sql);
                echo "<li class='added'>✓ Added column '<strong>$columnName</strong>'</li>";
                $addedColumns++;
            } catch (PDOException $e) {
                $errorMsg = $e->getMessage();
                echo "<li class='failed'>✗ Failed to add column '<strong>$columnName</strong>': " . htmlspecialchars($errorMsg) . "</li>";
                $failedColumns[] = $columnName;
            }
        }
    }
    
    echo "</ul>";
    
    // Final status message
    if (count($failedColumns) > 0) {
        echo "<div class='error'>";
        echo "<strong>⚠️ Some columns failed to add:</strong> " . implode(', ', $failedColumns);
        echo "<br><br>Please check the error messages above and ensure you have proper database permissions.";
        echo "</div>";
    } elseif ($addedColumns > 0) {
        echo "<div class='success'>";
        echo "<strong>✅ Success!</strong> Added $addedColumns new column(s). The database is now ready for registration with profile fields.";
        echo "<br><br>You can now use Google Sign Up and Sign In features.";
        echo "</div>";
    } else {
        echo "<div class='info'>";
        echo "<strong>ℹ️ Info:</strong> All required columns already exist. No changes needed.";
        echo "<br><br>Your database is ready for registration.";
        echo "</div>";
    }
    
} catch (PDOException $e) {
    echo "<div class='error'>";
    echo "<strong>❌ Database Error:</strong> " . htmlspecialchars($e->getMessage());
    echo "<br><br>Please check your database connection settings in <code>config/database.php</code>";
    echo "</div>";
} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<strong>❌ Error:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}

?>

    <div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #eee;">
        <p><strong>Next Steps:</strong></p>
        <ol>
            <li>If all columns were added successfully, try registering again.</li>
            <li>If you see errors, check your database permissions and try running the SQL file manually in phpMyAdmin.</li>
            <li>After successful migration, you can delete this file for security.</li>
        </ol>
    </div>
    
</div>
</body>
</html>

