<?php
/**
 * Migration script to create admin_orders table
 * Run this once to set up the admin_orders table
 */

require_once __DIR__ . '/../config/database.php';

try {
    $db = (new Database())->getConnection();
    
    echo "Creating admin_orders table...\n";
    
    // Create the admin_orders table
    $sql = "CREATE TABLE IF NOT EXISTS `admin_orders` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `inventory_id` int(11) DEFAULT NULL,
      `supplier_id` int(11) DEFAULT NULL,
      `user_id` int(11) DEFAULT NULL,
      `quantity` int(11) NOT NULL,
      `is_automated` tinyint(1) DEFAULT 0,
      `order_date` datetime DEFAULT current_timestamp(),
      `confirmation_status` enum('pending','confirmed','cancelled','delivered','completed') DEFAULT 'pending',
      `confirmation_date` datetime DEFAULT NULL,
      `unit_price` decimal(10,2) DEFAULT 0.00,
      `unit_type` VARCHAR(50) NOT NULL DEFAULT 'per piece',
      `variation` VARCHAR(255) NULL DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `inventory_id` (`inventory_id`),
      KEY `supplier_id` (`supplier_id`),
      KEY `user_id` (`user_id`),
      KEY `confirmation_status` (`confirmation_status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    $db->exec($sql);
    
    echo "✓ admin_orders table created successfully!\n\n";
    
    // Optional: Migrate existing orders from orders table
    echo "Checking for existing orders to migrate...\n";
    $checkStmt = $db->query("SELECT COUNT(*) as count FROM orders");
    $checkResult = $checkStmt->fetch(PDO::FETCH_ASSOC);
    $orderCount = (int)($checkResult['count'] ?? 0);
    
    if ($orderCount > 0) {
        $adminOrderCount = $db->query("SELECT COUNT(*) as count FROM admin_orders")->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($adminOrderCount == 0) {
            echo "Found {$orderCount} existing orders. Migrating to admin_orders...\n";
            
            $migrateSql = "INSERT INTO admin_orders (inventory_id, supplier_id, user_id, quantity, is_automated, order_date, confirmation_status, confirmation_date, unit_price, unit_type, variation)
                          SELECT inventory_id, supplier_id, user_id, quantity, is_automated, order_date, confirmation_status, confirmation_date, unit_price, 
                                 COALESCE(unit_type, 'per piece'), variation
                          FROM orders";
            
            $db->exec($migrateSql);
            $migrated = $db->query("SELECT COUNT(*) as count FROM admin_orders")->fetch(PDO::FETCH_ASSOC)['count'];
            echo "✓ Migrated {$migrated} orders to admin_orders table!\n\n";
        } else {
            echo "admin_orders table already has {$adminOrderCount} orders. Skipping migration.\n\n";
        }
    } else {
        echo "No existing orders found. admin_orders table is ready for new orders.\n\n";
    }
    
    echo "Setup complete! You can now use the admin orders system.\n";
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>

