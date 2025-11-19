<?php
/**
 * Check if database columns exist
 * Returns true if all columns exist, false otherwise
 */

include_once '../config/database.php';

function checkDatabaseColumns() {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Check users table columns
        $stmt = $db->query("SHOW COLUMNS FROM users");
        $existingColumns = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existingColumns[] = $row['Field'];
        }
        
        $requiredColumns = ['first_name', 'last_name', 'address', 'city', 'province', 'postal_code', 'profile_picture'];
        $missingColumns = [];
        
        foreach ($requiredColumns as $col) {
            if (!in_array($col, $existingColumns)) {
                $missingColumns[] = $col;
            }
        }
        
        return empty($missingColumns);
    } catch (Exception $e) {
        return false;
    }
}

