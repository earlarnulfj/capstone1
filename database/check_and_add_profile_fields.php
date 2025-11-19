<?php
/**
 * Database Migration Script
 * This script checks if the required profile fields exist in the database
 * and adds them if they are missing.
 */

include_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "<h2>Database Migration: Adding User Profile Fields</h2>";
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
    foreach ($columnsToAdd as $columnName => $sql) {
        if (in_array($columnName, $existingColumns)) {
            echo "<li style='color: green;'>&#10003; Column '$columnName' already exists</li>";
        } else {
            try {
                $db->exec($sql);
                echo "<li style='color: blue;'>&#10003; Added column '$columnName'</li>";
                $addedColumns++;
            } catch (PDOException $e) {
                echo "<li style='color: red;'>&#10007; Failed to add column '$columnName': " . $e->getMessage() . "</li>";
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
            echo "<li style='color: green;'>&#10003; Column '$columnName' already exists</li>";
        } else {
            try {
                $db->exec($sql);
                echo "<li style='color: blue;'>&#10003; Added column '$columnName'</li>";
                $addedColumns++;
            } catch (PDOException $e) {
                echo "<li style='color: red;'>&#10007; Failed to add column '$columnName': " . $e->getMessage() . "</li>";
            }
        }
    }
    
    echo "</ul>";
    
    if ($addedColumns > 0) {
        echo "<div style='background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; border-radius: 5px; margin-top: 20px;'>";
        echo "<strong>Success!</strong> Added $addedColumns new column(s). The database is now ready for registration with profile fields.";
        echo "</div>";
    } else {
        echo "<div style='background: #d1ecf1; padding: 15px; border: 1px solid #bee5eb; border-radius: 5px; margin-top: 20px;'>";
        echo "<strong>Info:</strong> All required columns already exist. No changes needed.";
        echo "</div>";
    }
    
} catch (PDOException $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border: 1px solid #f5c6cb; border-radius: 5px;'>";
    echo "<strong>Error:</strong> " . $e->getMessage();
    echo "</div>";
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border: 1px solid #f5c6cb; border-radius: 5px;'>";
    echo "<strong>Error:</strong> " . $e->getMessage();
    echo "</div>";
}
?>

