<?php
// This script should be run as a cron job to check inventory levels and create automated orders

include_once '../config/database.php';
include_once '../models/inventory.php';
include_once '../models/supplier.php';
include_once '../models/order.php';
include_once '../models/alert_log.php';
include_once '../models/notification.php';

$database = new Database();
$db = $database->getConnection();

$inventory = new Inventory($db);
$supplier = new Supplier($db);
$order = new Order($db);
$alert = new AlertLog($db);
$notification = new Notification($db);

// Get low stock items
$stmt = $inventory->getLowStock();

$ordersCreated = 0;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Check if there's already an unresolved alert for this item
    if (!$alert->alertExists($row['id'], 'reorder')) {
        // Create alert
        $alert->inventory_id = $row['id'];
        $alert->alert_type = 'reorder';
        $alert->is_resolved = 0;
        $alert_id = $alert->create();
        
        if ($alert_id) {
            // Calculate order quantity (reorder to reach twice the threshold)
            $order_quantity = ($row['reorder_threshold'] * 2) - $row['quantity'];
            
            // Create automated order
            $order_id = $order->createAutomatedOrder(
                $row['id'],
                $row['supplier_id'],
                $order_quantity
            );
            
            if ($order_id) {
                $ordersCreated++;
                
                // Send notification to supplier
                $notification->createOrderNotification(
                    $order_id,
                    $row['supplier_id'],
                    $row['name'],
                    $order_quantity
                );
            }
        }
    }
}

echo "Automated stock check completed. Created {$ordersCreated} new orders.\n";
?>
