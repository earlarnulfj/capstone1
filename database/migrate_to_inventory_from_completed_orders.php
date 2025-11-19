<?php
/**
 * Migration script to populate inventory_from_completed_orders table
 * This script syncs all existing completed orders to the new inventory table
 * Run this once after creating the new table
 */

require_once __DIR__ . '/../config/database.php';

try {
    $db = (new Database())->getConnection();
    
    echo "Starting migration to inventory_from_completed_orders table...\n";
    
    // Ensure the new table exists
    $createSQL = file_get_contents(__DIR__ . '/create_inventory_from_completed_orders.sql');
    if ($createSQL) {
        $db->exec($createSQL);
        echo "✓ Created inventory_from_completed_orders table\n";
    }
    
    require_once __DIR__ . '/../models/inventory.php';
    $inventory = new Inventory($db);
    
    // Sync all completed orders from admin_orders
    $hasAdminOrders = false;
    try {
        $chk = $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'admin_orders'");
        $hasAdminOrders = (bool)$chk->fetchColumn();
    } catch (Exception $e) { $hasAdminOrders = false; }
    
    if ($hasAdminOrders) {
        $ordersStmt = $db->query("SELECT id FROM admin_orders 
                                  WHERE confirmation_status = 'completed' 
                                  AND inventory_id IS NOT NULL 
                                  AND inventory_id > 0");
        $count = 0;
        while ($order = $ordersStmt->fetch(PDO::FETCH_ASSOC)) {
            if ($inventory->syncCompletedOrderToInventory((int)$order['id'], 'admin_orders')) {
                $count++;
            }
        }
        echo "✓ Synced {$count} completed orders from admin_orders table\n";
    }
    
    // Sync all completed orders from orders table
    $hasOrders = false;
    try {
        $chk = $db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'orders'");
        $hasOrders = (bool)$chk->fetchColumn();
    } catch (Exception $e) { $hasOrders = false; }
    
    if ($hasOrders) {
        $ordersStmt = $db->query("SELECT id FROM orders 
                                  WHERE confirmation_status = 'completed' 
                                  AND inventory_id IS NOT NULL 
                                  AND inventory_id > 0");
        $count = 0;
        while ($order = $ordersStmt->fetch(PDO::FETCH_ASSOC)) {
            if ($inventory->syncCompletedOrderToInventory((int)$order['id'], 'orders')) {
                $count++;
            }
        }
        echo "✓ Synced {$count} completed orders from orders table\n";
    }
    
    echo "\nMigration completed successfully!\n";
    echo "All completed orders have been synced to inventory_from_completed_orders table.\n";
    echo "The inventory.php pages will now display products from this new table.\n";
    
} catch (Exception $e) {
    echo "Error during migration: " . $e->getMessage() . "\n";
    exit(1);
}

