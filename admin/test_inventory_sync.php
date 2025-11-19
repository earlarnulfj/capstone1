<?php
// Simple diagnostic script to check inventory sync
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/inventory.php';

header('Content-Type: text/plain');

echo "=== INVENTORY SYNC DIAGNOSTIC ===\n\n";

try {
    $inventory = new Inventory($db);
    
    // Check completed orders
    echo "1. Checking completed orders...\n";
    try {
        $stmt = $db->query("SELECT COUNT(*) as count FROM admin_orders WHERE confirmation_status = 'completed'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalCompleted = (int)($row['count'] ?? 0);
        echo "   - Total completed orders in admin_orders: {$totalCompleted}\n";
        
        $stmt2 = $db->query("SELECT COUNT(*) as count FROM admin_orders WHERE confirmation_status = 'completed' AND inventory_id IS NOT NULL AND inventory_id > 0");
        $row2 = $stmt2->fetch(PDO::FETCH_ASSOC);
        $withInventoryId = (int)($row2['count'] ?? 0);
        echo "   - Completed orders WITH inventory_id: {$withInventoryId}\n";
        echo "   - Completed orders WITHOUT inventory_id: " . ($totalCompleted - $withInventoryId) . "\n";
        
        if ($totalCompleted > 0) {
            echo "\n   Sample completed orders:\n";
            $sampleStmt = $db->query("SELECT id, inventory_id, quantity, confirmation_status FROM admin_orders WHERE confirmation_status = 'completed' LIMIT 5");
            while ($sample = $sampleStmt->fetch(PDO::FETCH_ASSOC)) {
                echo "   - Order #{$sample['id']}: inventory_id={$sample['inventory_id']}, quantity={$sample['quantity']}\n";
            }
        }
    } catch (Exception $e) {
        echo "   ERROR: " . $e->getMessage() . "\n";
    }
    
    // Run sync
    echo "\n2. Running sync...\n";
    $inventory->syncAllCompletedOrdersToInventory();
    echo "   Sync completed.\n";
    
    // Check again after sync
    echo "\n3. Checking after sync...\n";
    try {
        $stmt3 = $db->query("SELECT COUNT(*) as count FROM admin_orders WHERE confirmation_status = 'completed' AND inventory_id IS NOT NULL AND inventory_id > 0");
        $row3 = $stmt3->fetch(PDO::FETCH_ASSOC);
        $withInventoryIdAfter = (int)($row3['count'] ?? 0);
        echo "   - Completed orders WITH inventory_id after sync: {$withInventoryIdAfter}\n";
    } catch (Exception $e) {
        echo "   ERROR: " . $e->getMessage() . "\n";
    }
    
    // Check inventory items
    echo "\n4. Checking inventory items...\n";
    try {
        $stmt4 = $db->query("SELECT COUNT(*) as count FROM inventory");
        $row4 = $stmt4->fetch(PDO::FETCH_ASSOC);
        $totalInventory = (int)($row4['count'] ?? 0);
        echo "   - Total items in inventory table: {$totalInventory}\n";
        
        // Check items linked to completed orders
        $stmt5 = $db->query("SELECT COUNT(DISTINCT i.id) as count 
                            FROM inventory i 
                            INNER JOIN admin_orders o ON o.inventory_id = i.id 
                            WHERE o.confirmation_status = 'completed'");
        $row5 = $stmt5->fetch(PDO::FETCH_ASSOC);
        $linkedItems = (int)($row5['count'] ?? 0);
        echo "   - Inventory items linked to completed orders: {$linkedItems}\n";
        
        if ($linkedItems > 0) {
            echo "\n   Sample linked inventory items:\n";
            $sampleStmt2 = $db->query("SELECT i.id, i.name, i.sku, COUNT(o.id) as order_count
                                      FROM inventory i 
                                      INNER JOIN admin_orders o ON o.inventory_id = i.id 
                                      WHERE o.confirmation_status = 'completed'
                                      GROUP BY i.id
                                      LIMIT 5");
            while ($sample2 = $sampleStmt2->fetch(PDO::FETCH_ASSOC)) {
                echo "   - Item #{$sample2['id']}: {$sample2['name']} (SKU: {$sample2['sku']}), {$sample2['order_count']} orders\n";
            }
        }
    } catch (Exception $e) {
        echo "   ERROR: " . $e->getMessage() . "\n";
    }
    
    // Test the readAllFromCompletedOrders query
    echo "\n5. Testing readAllFromCompletedOrders()...\n";
    try {
        $stmt6 = $inventory->readAllFromCompletedOrders();
        $results = $stmt6->fetchAll(PDO::FETCH_ASSOC);
        $resultCount = count($results);
        echo "   - Query returned {$resultCount} rows\n";
        
        if ($resultCount > 0) {
            echo "\n   Sample results:\n";
            foreach (array_slice($results, 0, 5) as $result) {
                echo "   - ID: {$result['id']}, Name: {$result['name']}, SKU: {$result['sku']}\n";
            }
        }
    } catch (Exception $e) {
        echo "   ERROR: " . $e->getMessage() . "\n";
    }
    
    echo "\n=== DIAGNOSTIC COMPLETE ===\n";
    
} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

