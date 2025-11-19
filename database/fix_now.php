<?php
/**
 * QUICK FIX - Add Missing Database Columns
 * Run this file to automatically add the missing columns
 * 
 * Open in browser: http://localhost/haha/database/fix_now.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Quick Database Fix</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
        .success { background: #d4edda; padding: 15px; border-radius: 5px; color: #155724; margin: 10px 0; }
        .error { background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24; margin: 10px 0; }
        .info { background: #d1ecf1; padding: 15px; border-radius: 5px; color: #0c5460; margin: 10px 0; }
        h1 { color: #007bff; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>🔧 Quick Database Fix</h1>
    
<?php

include_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // SQL statements to add columns (will fail if column exists, so we catch and continue)
    $sqlStatements = [
        "ALTER TABLE `users` ADD COLUMN `first_name` VARCHAR(100) NULL AFTER `email`",
        "ALTER TABLE `users` ADD COLUMN `middle_name` VARCHAR(100) NULL AFTER `first_name`",
        "ALTER TABLE `users` ADD COLUMN `last_name` VARCHAR(100) NULL AFTER `middle_name`",
        "ALTER TABLE `users` ADD COLUMN `address` TEXT NULL AFTER `phone`",
        "ALTER TABLE `users` ADD COLUMN `city` VARCHAR(100) NULL AFTER `address`",
        "ALTER TABLE `users` ADD COLUMN `province` VARCHAR(100) NULL AFTER `city`",
        "ALTER TABLE `users` ADD COLUMN `postal_code` VARCHAR(20) NULL AFTER `province`",
        "ALTER TABLE `users` ADD COLUMN `profile_picture` VARCHAR(255) NULL AFTER `postal_code`",
        "ALTER TABLE `suppliers` ADD COLUMN `city` VARCHAR(100) NULL AFTER `address`",
        "ALTER TABLE `suppliers` ADD COLUMN `province` VARCHAR(100) NULL AFTER `city`",
        "ALTER TABLE `suppliers` ADD COLUMN `postal_code` VARCHAR(20) NULL AFTER `province`"
    ];
    
    $successCount = 0;
    $errorCount = 0;
    $alreadyExists = 0;
    
    echo "<h3>Adding missing columns...</h3>";
    echo "<ul>";
    
    foreach ($sqlStatements as $sql) {
        try {
            $db->exec($sql);
            echo "<li style='color: green;'>✓ Successfully executed</li>";
            $successCount++;
        } catch (PDOException $e) {
            $errorCode = $e->getCode();
            $errorMsg = $e->getMessage();
            
            // Error 1060 = Duplicate column name (column already exists)
            if (strpos($errorMsg, 'Duplicate column name') !== false || 
                strpos($errorMsg, 'already exists') !== false ||
                $errorCode == 1060) {
                echo "<li style='color: orange;'>⚠ Column already exists (skipped)</li>";
                $alreadyExists++;
            } else {
                echo "<li style='color: red;'>✗ Error: " . htmlspecialchars($errorMsg) . "</li>";
                $errorCount++;
            }
        }
    }
    
    echo "</ul>";
    
    // Summary
    if ($errorCount == 0 && $successCount > 0) {
        echo "<div class='success'>";
        echo "<strong>✅ Success!</strong> Added $successCount column(s). ";
        if ($alreadyExists > 0) {
            echo "$alreadyExists column(s) already existed. ";
        }
        echo "<br><br>Your database is now ready. You can try registering again!";
        echo "</div>";
    } elseif ($errorCount == 0 && $alreadyExists > 0) {
        echo "<div class='info'>";
        echo "<strong>ℹ️ Info:</strong> All columns already exist. Your database is ready!";
        echo "</div>";
    } else {
        echo "<div class='error'>";
        echo "<strong>❌ Some errors occurred:</strong> $errorCount error(s). Please check the errors above.";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<strong>❌ Database Connection Error:</strong> " . htmlspecialchars($e->getMessage());
    echo "<br><br>Please check your database settings in <code>config/database.php</code>";
    echo "</div>";
}

?>

    <hr style="margin: 30px 0;">
    <p><strong>Next Steps:</strong></p>
    <ol>
        <li>If you see "Success!" above, try registering again.</li>
        <li>If you see errors, you may need to run the SQL manually in phpMyAdmin.</li>
        <li>After fixing, you can delete this file for security.</li>
    </ol>
    
</body>
</html>

