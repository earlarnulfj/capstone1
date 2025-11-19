<?php
/**
 * Script to recreate inventory table aligned with admin_orders
 * This will:
 * 1. Drop existing inventory_variations table (if exists)
 * 2. Drop existing inventory table (if exists)
 * 3. Create new inventory table aligned with admin_orders structure
 * 4. Create new inventory_variations table
 * 
 * WARNING: This will DELETE all existing inventory data!
 * Make sure to backup your database before running this script.
 */

require_once __DIR__ . '/../config/database.php';

header('Content-Type: text/plain');

echo "========================================\n";
echo "RECREATE INVENTORY TABLE\n";
echo "========================================\n\n";

try {
    $db = (new Database())->getConnection();
    
    echo "Step 1: Checking current inventory table...\n";
    
    // Check if inventory table exists
    $checkInventory = $db->query("SHOW TABLES LIKE 'inventory'");
    $inventoryExists = $checkInventory->rowCount() > 0;
    
    if ($inventoryExists) {
        $countStmt = $db->query("SELECT COUNT(*) as count FROM inventory");
        $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
        $itemCount = (int)($countRow['count'] ?? 0);
        echo "   - Found existing inventory table with {$itemCount} items\n";
    } else {
        echo "   - No existing inventory table found\n";
    }
    
    // Check if inventory_variations table exists
    $checkVariations = $db->query("SHOW TABLES LIKE 'inventory_variations'");
    $variationsExists = $checkVariations->rowCount() > 0;
    
    if ($variationsExists) {
        $varCountStmt = $db->query("SELECT COUNT(*) as count FROM inventory_variations");
        $varCountRow = $varCountStmt->fetch(PDO::FETCH_ASSOC);
        $varCount = (int)($varCountRow['count'] ?? 0);
        echo "   - Found existing inventory_variations table with {$varCount} variations\n";
    } else {
        echo "   - No existing inventory_variations table found\n";
    }
    
    echo "\nStep 2: Dropping existing tables...\n";
    
    // Disable foreign key checks temporarily
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // Drop inventory_variations first (has foreign key to inventory)
    if ($variationsExists) {
        try {
            $db->exec("DROP TABLE IF EXISTS `inventory_variations`");
            echo "   ✓ Dropped inventory_variations table\n";
        } catch (Exception $e) {
            echo "   ✗ Error dropping inventory_variations: " . $e->getMessage() . "\n";
        }
    }
    
    // Drop inventory table
    if ($inventoryExists) {
        try {
            $db->exec("DROP TABLE IF EXISTS `inventory`");
            echo "   ✓ Dropped inventory table\n";
        } catch (Exception $e) {
            echo "   ✗ Error dropping inventory: " . $e->getMessage() . "\n";
        }
    }
    
    // Re-enable foreign key checks
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    echo "\nStep 3: Creating new inventory table...\n";
    
    // Create new inventory table aligned with admin_orders structure
    // This table structure matches exactly what's used in admin/orders.php
    $createInventorySQL = "CREATE TABLE `inventory` (
      `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'Primary key - matches admin_orders.inventory_id',
      `sku` VARCHAR(50) DEFAULT NULL COMMENT 'Product SKU',
      `name` VARCHAR(100) NOT NULL COMMENT 'Product name - displayed as item_name in orders.php',
      `description` TEXT DEFAULT NULL COMMENT 'Product description',
      `category` VARCHAR(50) DEFAULT NULL COMMENT 'Product category',
      `reorder_threshold` INT(11) NOT NULL DEFAULT 0 COMMENT 'Reorder threshold for alerts',
      `unit_type` VARCHAR(50) NOT NULL DEFAULT 'per piece' COMMENT 'Unit type - matches admin_orders.unit_type',
      `quantity` INT(11) NOT NULL DEFAULT 0 COMMENT 'Total quantity from completed orders - matches admin_orders.quantity aggregation',
      `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Unit price - matches admin_orders.unit_price',
      `supplier_id` INT(11) DEFAULT NULL COMMENT 'Supplier ID - matches admin_orders.supplier_id',
      `location` VARCHAR(100) DEFAULT NULL COMMENT 'Storage location',
      `image_url` VARCHAR(255) DEFAULT NULL COMMENT 'Product image URL',
      `image_path` VARCHAR(255) DEFAULT NULL COMMENT 'Product image file path',
      `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Soft delete flag: 0 = active, 1 = deleted',
      `last_updated` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
      `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Creation timestamp',
      PRIMARY KEY (`id`),
      KEY `idx_supplier_id` (`supplier_id`) COMMENT 'Index for JOIN with suppliers table',
      KEY `idx_sku` (`sku`) COMMENT 'Index for SKU lookups',
      KEY `idx_category` (`category`) COMMENT 'Index for category filtering',
      KEY `idx_is_deleted` (`is_deleted`) COMMENT 'Index for soft delete filtering',
      KEY `idx_name` (`name`) COMMENT 'Index for name searches',
      KEY `idx_unit_type` (`unit_type`) COMMENT 'Index for unit type filtering',
      KEY `idx_unit_price` (`unit_price`) COMMENT 'Index for price queries'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    $db->exec($createInventorySQL);
    echo "   ✓ Created new inventory table\n";
    
    echo "\nStep 4: Creating new inventory_variations table...\n";
    
    // Create new inventory_variations table
    $createVariationsSQL = "CREATE TABLE `inventory_variations` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `inventory_id` INT(11) NOT NULL,
      `variation` VARCHAR(255) DEFAULT NULL COMMENT 'Product variation from admin_orders (e.g., Brand:Adidas|Size:Large|Color:Red)',
      `unit_type` VARCHAR(50) NOT NULL DEFAULT 'per piece',
      `quantity` INT(11) NOT NULL DEFAULT 0 COMMENT 'Quantity for this specific variation from completed orders',
      `unit_price` DECIMAL(10,2) DEFAULT NULL COMMENT 'Unit price for this variation',
      `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
      `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `unique_inventory_variation` (`inventory_id`, `variation`(100)),
      KEY `idx_inventory_id` (`inventory_id`),
      KEY `idx_variation` (`variation`(100)),
      CONSTRAINT `fk_inventory_variations_inventory` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    $db->exec($createVariationsSQL);
    echo "   ✓ Created new inventory_variations table\n";
    
    echo "\nStep 5: Verifying table structure...\n";
    
    // Verify inventory table
    $inventoryColumns = $db->query("SHOW COLUMNS FROM inventory")->fetchAll(PDO::FETCH_ASSOC);
    echo "   - Inventory table has " . count($inventoryColumns) . " columns:\n";
    foreach ($inventoryColumns as $col) {
        echo "     * {$col['Field']} ({$col['Type']})\n";
    }
    
    // Verify inventory_variations table
    $variationsColumns = $db->query("SHOW COLUMNS FROM inventory_variations")->fetchAll(PDO::FETCH_ASSOC);
    echo "   - Inventory_variations table has " . count($variationsColumns) . " columns:\n";
    foreach ($variationsColumns as $col) {
        echo "     * {$col['Field']} ({$col['Type']})\n";
    }
    
    echo "\n========================================\n";
    echo "✓ SUCCESS: Inventory tables recreated!\n";
    echo "========================================\n\n";
    
    echo "Next steps:\n";
    echo "1. Go to admin/orders.php and mark some orders as 'completed'\n";
    echo "2. The sync process will automatically populate the inventory table\n";
    echo "3. Check admin/inventory.php to see the synced products\n\n";
    
} catch (Exception $e) {
    echo "\n========================================\n";
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    echo "========================================\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

