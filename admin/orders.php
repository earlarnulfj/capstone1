<?php
// ====== Access control & dependencies (corrected) ======
include_once '../config/session.php';   // namespaced sessions (admin/staff)
require_once '../config/database.php';  // DB connection

// Load all model classes this page uses
require_once '../models/inventory.php';
require_once '../models/supplier.php';
require_once '../models/admin_order.php';  // Use AdminOrder instead of Order
require_once '../models/sales_transaction.php';
require_once '../models/alert_log.php';
require_once '../models/inventory_variation.php';
// If your dashboard uses more models, include them here with require_once

// ---- Admin auth guard (namespaced) ----
if (empty($_SESSION['admin']['user_id'])) {
    header("Location: ../login.php");
    exit();
}
if (($_SESSION['admin']['role'] ?? null) !== 'management') {
    header("Location: ../login.php");
    exit();
}

// ---- Instantiate dependencies ----
$db         = (new Database())->getConnection();

// Auto-create admin_orders table if it doesn't exist
try {
    $checkTable = $db->query("SHOW TABLES LIKE 'admin_orders'");
    if ($checkTable->rowCount() === 0) {
        // Table doesn't exist, create it
        $createTableSQL = "CREATE TABLE IF NOT EXISTS `admin_orders` (
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
        
        $db->exec($createTableSQL);
        
        // Optionally migrate existing orders from orders table
        $existingOrders = $db->query("SELECT COUNT(*) as count FROM orders")->fetch(PDO::FETCH_ASSOC);
        $orderCount = (int)($existingOrders['count'] ?? 0);
        
        if ($orderCount > 0) {
            $adminOrderCount = $db->query("SELECT COUNT(*) as count FROM admin_orders")->fetch(PDO::FETCH_ASSOC)['count'];
            if ($adminOrderCount == 0) {
                // Migrate existing orders
                $migrateSQL = "INSERT INTO admin_orders (inventory_id, supplier_id, user_id, quantity, is_automated, order_date, confirmation_status, confirmation_date, unit_price, unit_type, variation)
                              SELECT inventory_id, supplier_id, user_id, quantity, is_automated, order_date, confirmation_status, confirmation_date, unit_price, 
                                     COALESCE(unit_type, 'per piece'), variation
                              FROM orders";
                $db->exec($migrateSQL);
            }
        }
    }
} catch (PDOException $e) {
    error_log("Error creating admin_orders table: " . $e->getMessage());
    // Continue execution - table creation will be retried on next page load
}

$inventory  = new Inventory($db);
$supplier   = new Supplier($db);
$order      = new AdminOrder($db);  // Use AdminOrder for admin_orders table
$sales      = new SalesTransaction($db);
$alert      = new AlertLog($db);

// ====== Helper function for variation display ======
// Format variation for display: "Brand:Adidas|Size:Large|Color:Red" -> "Adidas - Large - Red" (combine values with dashes)
function formatVariationForDisplay($variation) {
    if (empty($variation)) return '';
    if (strpos($variation, '|') === false && strpos($variation, ':') === false) return $variation;
    
    $parts = explode('|', $variation);
    $values = [];
    foreach ($parts as $part) {
        $av = explode(':', trim($part), 2);
        if (count($av) === 2) {
            $values[] = trim($av[1]);
        } else {
            $values[] = trim($part);
        }
    }
    return implode(' - ', $values);
}

// Format variation with labels: "Brand:Generic|Size:Large" -> "Brand: Generic | Size: Large"
function formatVariationWithLabels($variation) {
    if (empty($variation)) return '';
    if (strpos($variation, '|') === false && strpos($variation, ':') === false) return $variation;
    
    $parts = explode('|', $variation);
    $formatted = [];
    foreach ($parts as $part) {
        $av = explode(':', trim($part), 2);
        if (count($av) === 2) {
            $formatted[] = trim($av[0]) . ': ' . trim($av[1]);
        } else {
            $formatted[] = trim($part);
        }
    }
    return implode(' | ', $formatted);
}

// ====== (Keep your existing page logic below) ======
// From here down, keep your original code (queries, computations, HTML).
// For example, if you previously computed variables like $total_inventory,
// $total_suppliers, $pending_orders, etc., leave that logic as-is.


$order = new AdminOrder($db);  // Use AdminOrder for admin_orders table
$inventory = new Inventory($db);
$supplier = new Supplier($db);

// Get all inventory items for dropdown
$inventoryStmt = $inventory->readAll();
$inventoryItems = [];
while ($row = $inventoryStmt->fetch(PDO::FETCH_ASSOC)) {
    $inventoryItems[] = $row;
}

// Get all suppliers for dropdown
$supplierStmt = $supplier->readAll();
$suppliers = [];
while ($row = $supplierStmt->fetch(PDO::FETCH_ASSOC)) {
    $suppliers[] = $row;
}

// Get all orders for calculation
$orderStmt = $order->readAll();
$orders = [];
$total_orders = 0;
while ($row = $orderStmt->fetch(PDO::FETCH_ASSOC)) {
    $orders[] = $row;
    $total_orders++;
}

// Get variation data for all inventory items - CONNECTED to admin_pos.php and alerts.php
// Stock calculation: SUM(admin_orders.quantity WHERE confirmation_status='completed') - sales
// This matches exactly how admin_pos.php and alerts.php calculate available stock
$variation_data = [];
$inventory_ids = array_unique(array_column($orders, 'inventory_id'));
if (!empty($inventory_ids)) {
    $inventory_ids = array_values(array_filter($inventory_ids, function($id) { return $id !== null && is_numeric($id); })); // Reindex, filter nulls and ensure numeric
    if (!empty($inventory_ids)) {
        $placeholders = implode(',', array_fill(0, count($inventory_ids), '?'));
        
        // Get ALL variations from TWO sources (matching admin_pos.php and alerts.php):
        // 1. COMPLETED orders from admin_orders
        // 2. ADMIN-CREATED items from inventory_variations
        
        // First, get all unique variations and their data from completed orders
        $completedVarStmt = $db->prepare("SELECT 
                                            iv.inventory_id,
                                            COALESCE(o.variation, iv.variation) as variation,
                                            COALESCE(o.unit_type, iv.unit_type, 'per piece') as unit_type,
                                            COALESCE(o.unit_price, iv.unit_price, 0) as unit_price,
                                            o.variation as order_variation
                                          FROM inventory_variations iv
                                          LEFT JOIN admin_orders o ON o.inventory_id = iv.inventory_id 
                                            AND o.variation = iv.variation 
                                            AND o.confirmation_status = 'completed'
                                          WHERE iv.inventory_id IN ($placeholders)
                                          GROUP BY iv.inventory_id, COALESCE(o.variation, iv.variation)");
        $completedVarStmt->execute($inventory_ids);
        
        // Initialize variation data map
        while ($var = $completedVarStmt->fetch(PDO::FETCH_ASSOC)) {
            $inv_id = (int)$var['inventory_id'];
            $variation_key = $var['variation'] ?? '';
            
            if (!isset($variation_data[$inv_id])) {
                $variation_data[$inv_id] = [];
            }
            
            // Calculate available stock: completed orders - sales (EXACTLY matching admin_pos.php and alerts.php)
            $stock = 0;
            $unit_price = isset($var['unit_price']) && $var['unit_price'] > 0 ? (float)$var['unit_price'] : null;
            $unit_type = !empty($var['unit_type']) ? trim($var['unit_type']) : 'per piece';
            
            // Get ordered quantity from COMPLETED orders (matching admin_pos.php)
            if (!empty($variation_key)) {
                $orderQtyStmt = $db->prepare("SELECT SUM(quantity) as total_ordered_qty 
                                              FROM admin_orders 
                                              WHERE inventory_id = ? 
                                                AND variation = ? 
                                                AND confirmation_status = 'completed'");
                $orderQtyStmt->execute([$inv_id, $variation_key]);
                $orderQtyRow = $orderQtyStmt->fetch(PDO::FETCH_ASSOC);
                $orderedQty = (int)($orderQtyRow['total_ordered_qty'] ?? 0);
                
                // Get sold quantity (matching admin_pos.php)
                $soldStmt = $db->prepare("SELECT SUM(quantity) as total_sold 
                                          FROM sales_transactions 
                                          WHERE inventory_id = ? 
                                            AND variation = ? 
                                            AND (variation IS NOT NULL AND variation != '' AND variation != 'null')");
                $soldStmt->execute([$inv_id, $variation_key]);
                $soldRow = $soldStmt->fetch(PDO::FETCH_ASSOC);
                $soldQty = (int)($soldRow['total_sold'] ?? 0);
                
                // Available stock = completed orders - sales (EXACT calculation from admin_pos.php and alerts.php)
                // Stock MUST be from completed orders ONLY - no inventory_variations fallback
                $stock = max(0, $orderedQty - $soldQty);
            }
            
            // Store variation data with calculated stock (matching admin_pos.php calculation)
            $variation_data[$inv_id][$variation_key] = [
                'variation' => $variation_key,
                'unit_type' => $unit_type,
                'unit_price' => $unit_price,
                'quantity' => $stock, // Available stock = completed orders - sales (connected to admin_pos.php and alerts.php)
                'stock' => $stock
            ];
        }
        
        // Convert associative arrays to indexed arrays for consistency with the rest of the code
        foreach ($variation_data as $inv_id => $variations) {
            $variation_data[$inv_id] = array_values($variations);
        }
    }
}

// Build variation data map per inventory for UI binding
// Only include complete variation combinations (contain '|' to indicate multiple attributes)
$inventoryVariation = new InventoryVariation($db);
$variationDataMap = [];
foreach ($inventoryItems as $item) {
    try {
        $data = $inventoryVariation->getVariationDataByInventory($item['id']);
        // Expecting keys: 'stocks', 'prices', 'units'
        $variants = [];
        if (is_array($data)) {
            $stocks = $data['stocks'] ?? [];
            $prices = $data['prices'] ?? [];
            $units  = $data['units'] ?? [];
            foreach ($stocks as $variation => $qty) {
                // Only include complete combinations (contain '|') or single values without ':'
                // Filter out individual attribute keys like "Brand:Generic" - only show full combos like "Brand:Generic|Color:Blue"
                if (!empty($variation) && (strpos($variation, '|') !== false || (strpos($variation, ':') === false && trim($variation) !== ''))) {
                    $variants[] = [
                        'variation' => (string)$variation,
                        'unit_type' => isset($units[$variation]) ? (string)$units[$variation] : '',
                        'unit_price' => isset($prices[$variation]) ? (float)$prices[$variation] : null,
                        'quantity' => (int)$qty,
                    ];
                }
            }
        }
        $variationDataMap[$item['id']] = $variants;
    } catch (Throwable $e) {
        $variationDataMap[$item['id']] = [];
    }
}

// Build variation map for each order (to populate dropdowns)
$orderVariationMap = [];
foreach ($orders as $orderRow) {
    $invId = isset($orderRow['inventory_id']) ? (int)$orderRow['inventory_id'] : 0;
    if ($invId > 0 && isset($variationDataMap[$invId])) {
        $orderVariationMap[$orderRow['id']] = $variationDataMap[$invId];
    } else {
        $orderVariationMap[$orderRow['id']] = [];
    }
}

// Process form submission
$message = '';
$messageType = '';

// Check for session messages from redirects
if (isset($_SESSION['order_delete_message'])) {
    $message = $_SESSION['order_delete_message'];
    $messageType = $_SESSION['order_delete_message_type'] ?? 'info';
    unset($_SESSION['order_delete_message']);
    unset($_SESSION['order_delete_message_type']);
}

// Debug: Log POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("=== ORDERS.PHP POST REQUEST ===");
    error_log("POST data: " . json_encode($_POST));
    error_log("Raw input: " . file_get_contents('php://input'));
    
    if (isset($_POST['action'])) {
        error_log("Action found: " . $_POST['action']);
        // Add new order
        if ($_POST['action'] === 'add') {
            $order->inventory_id = $_POST['inventory_id'];
            $order->supplier_id = $_POST['supplier_id'];
            $order->user_id = $_SESSION['admin']['user_id'];
            $order->quantity = $_POST['quantity'];
            $order->is_automated = 0;
            $order->confirmation_status = 'pending';
            // Variation-aware fields
            $order->variation = isset($_POST['variation']) ? trim($_POST['variation']) : null;
            $order->unit_type = isset($_POST['unit_type']) ? trim($_POST['unit_type']) : null;
            
            // Validate stock availability
            if ($order->inventory_id && $order->variation && $order->quantity) {
                try {
                    // Get stock for this variation
                    $available_stock = 0;
                    foreach ($variationDataMap[$order->inventory_id] as $var) {
                        if ($var['variation'] === $order->variation) {
                            $available_stock = $var['stock'] ?? 0;
                            break;
                        }
                    }
                    
                    // Check if enough stock is available
                    if ($order->quantity > $available_stock) {
                        $message = "Not enough stock available. Only {$available_stock} units left.";
                        $messageType = "danger";
                        // Skip order creation without using goto
                        $order_creation_skipped = true;
                    }
                } catch (Throwable $e) {
                    // Continue with order creation even if stock check fails
                }
            }
            
            // Only create order if not skipped due to stock issues
            $order_id = null;
            if (!isset($order_creation_skipped) || !$order_creation_skipped) {
                $order_id = $order->create();
            }
            
            if ($order_id) {
                $message = "Order was created successfully.";
                $messageType = "success";
                
                // Include notification model
                include_once '../models/notification.php';
                $notification = new Notification($db);
                
                // Send notification to supplier
                $selectedItemName = $inventoryItems[array_search($order->inventory_id, array_column($inventoryItems, 'id'))]['name'] ?? 'Item';
                $decoratedName = $selectedItemName;
                if (!empty($order->unit_type) || !empty($order->variation)) {
                    $parts = [];
                    if (!empty($order->unit_type)) { $parts[] = $order->unit_type; }
                    if (!empty($order->variation)) { $parts[] = $order->variation; }
                    $decoratedName = $selectedItemName . ' (' . implode(' / ', $parts) . ')';
                }
                $notification->createOrderNotification(
                    $order_id,
                    $order->supplier_id,
                    $decoratedName,
                    $order->quantity
                );
            } else {
                $message = "Unable to create order.";
                $messageType = "danger";
            }
        }
        
        // Cancel order
        elseif ($_POST['action'] === 'cancel') {
            $order_id = filter_var($_POST['id'], FILTER_VALIDATE_INT);
            $item_name = trim($_POST['item'] ?? '');
            
            // Validate parameters
            if (!$order_id || $order_id <= 0) {
                $message = "Invalid order ID.";
                $messageType = "danger";
            } else if (empty($item_name)) {
                $message = "Item name is required for cancellation confirmation.";
                $messageType = "danger";
            } else {
                // Check if order exists and get its current status
                $stmt = $db->prepare("SELECT o.id, i.name as item_name, o.confirmation_status 
                                    FROM admin_orders o 
                                    LEFT JOIN inventory i ON o.inventory_id = i.id 
                                    WHERE o.id = ?");
                $stmt->execute([$order_id]);
                $existing_order = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$existing_order) {
                    $message = "Order not found.";
                    $messageType = "danger";
                } else if ($existing_order['item_name'] !== $item_name) {
                    $message = "Item name mismatch. Cancellation cancelled for security.";
                    $messageType = "danger";
                } else if ($existing_order['confirmation_status'] === 'cancelled') {
                    $message = "Order is already cancelled.";
                    $messageType = "warning";
                } else if ($existing_order['confirmation_status'] === 'completed') {
                    $message = "Cannot cancel completed orders.";
                    $messageType = "warning";
                } else {
                    // Prevent cancellation after any successful delivery
                    $agg = $db->prepare("SELECT 
                        COALESCE(SUM(CASE WHEN status='delivered' THEN COALESCE(replenished_quantity,0) ELSE 0 END),0) AS delivered_qty,
                        SUM(CASE WHEN status IN ('pending','in_transit') THEN 1 ELSE 0 END) AS not_finished_count
                      FROM deliveries WHERE order_id = ?");
                    $agg->execute([$order_id]);
                    $aggRow = $agg->fetch(PDO::FETCH_ASSOC);
                    $deliveredQty = (int)($aggRow['delivered_qty'] ?? 0);
                    if ($deliveredQty > 0) {
                        // Block cancellation if any quantity has been delivered
                        $message = "Cannot cancel order after successful delivery.";
                        $messageType = "warning";
                        // Audit blocked attempt
                        $logStmt = $db->prepare("INSERT INTO sync_events(entity_type, entity_id, action, status, message, actor_id) VALUES('order', ?, 'cancel_attempt_blocked', 'blocked', 'Cancellation prevented due to delivered items', ?)");
                        $logStmt->execute([$order_id, $_SESSION['user_id'] ?? null]);
                    } else {
                        // Perform transactional cancellation with logging
                        $db->beginTransaction();
                        try {
                        $status_before = $existing_order['confirmation_status'];
                        
                        // Update order status to cancelled
                        $update_stmt = $db->prepare("UPDATE admin_orders SET confirmation_status = 'cancelled', confirmation_date = NOW() WHERE id = ? AND confirmation_status NOT IN ('cancelled', 'completed')");
                        $update_result = $update_stmt->execute([$order_id]);
                        
                        if ($update_result && $update_stmt->rowCount() > 0) {
                            // Log the cancellation to sync_events
                            $log_stmt = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                            $log_stmt->execute([
                                'order_status_change',
                                'admin_ui',
                                'supplier_system',
                                $order_id,
                                null,
                                $status_before,
                                'cancelled',
                                1,
                                "Order #{$order_id} cancelled by admin user #{$_SESSION['admin']['user_id']}"
                            ]);
                            
                            $db->commit();
                            $message = "Order for '{$item_name}' was cancelled successfully.";
                            $messageType = "success";
                        } else {
                            $db->rollBack();
                            $message = "Unable to cancel order. It may have already been cancelled or completed.";
                            $messageType = "warning";
                        }
                    } catch (Exception $e) {
                        $db->rollBack();
                        $message = "Error cancelling order: " . $e->getMessage();
                        $messageType = "danger";
                        
                        // Log the error
                        $log_stmt = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        $log_stmt->execute([
                            'order_status_change',
                            'admin_ui',
                            'supplier_system',
                            $order_id,
                            null,
                            $status_before ?? 'unknown',
                            'cancelled',
                            0,
                            "Failed to cancel order #{$order_id}: " . $e->getMessage()
                        ]);
                    }
                }
            }
        }
        
        
        // Delete order - ONLY affects admin_orders table, NOT orders table (supplier orders)
        if (isset($_POST['action']) && $_POST['action'] === 'delete') {
            error_log("=== DELETE ORDER REQUEST FROM ADMIN ===");
            error_log("POST data: " . print_r($_POST, true));
            error_log("Raw POST: " . var_export($_POST, true));
            error_log("Target table: admin_orders (NOT orders)");
            
            // Get order ID - try multiple field names
            $order_id = 0;
            if (isset($_POST['id']) && is_numeric($_POST['id'])) {
                $order_id = (int)$_POST['id'];
                error_log("Found order ID from 'id' field: $order_id");
            } elseif (isset($_POST['order_id']) && is_numeric($_POST['order_id'])) {
                $order_id = (int)$_POST['order_id'];
                error_log("Found order ID from 'order_id' field: $order_id");
            } else {
                error_log("ERROR: No valid order ID found in POST data. POST keys: " . implode(', ', array_keys($_POST)));
            }
            
            error_log("Attempting to delete order ID: $order_id from admin_orders table");
            
            if ($order_id <= 0) {
                $message = "Invalid order ID.";
                $messageType = "danger";
                error_log("Delete failed: Invalid order ID");
                $_SESSION['order_delete_message'] = $message;
                $_SESSION['order_delete_message_type'] = "danger";
                header("Location: orders.php");
                exit();
            } else {
                // IMPORTANT: Verify this order exists in admin_orders (NOT orders table)
                $checkStmt = $db->prepare("SELECT id FROM admin_orders WHERE id = ? LIMIT 1");
                $checkStmt->execute([$order_id]);
                $orderExists = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$orderExists) {
                    $message = "Order #$order_id not found in admin orders.";
                    $messageType = "danger";
                    error_log("Delete failed: Order $order_id does not exist in admin_orders table");
                    $_SESSION['order_delete_message'] = $message;
                    $_SESSION['order_delete_message_type'] = "danger";
                    header("Location: orders.php");
                    exit();
                } else {
                    // Start transaction
                    $db->beginTransaction();
                    try {
                        // Get order details first
                        $orderDetailStmt = $db->prepare("SELECT inventory_id, quantity, variation, unit_type, confirmation_status FROM admin_orders WHERE id = ?");
                        $orderDetailStmt->execute([$order_id]);
                        $orderDetails = $orderDetailStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$orderDetails) {
                            throw new Exception("Order #$order_id not found in database.");
                        }
                        
                        $inventory_id = (int)($orderDetails['inventory_id'] ?? 0);
                        $order_quantity = (int)($orderDetails['quantity'] ?? 0);
                        $variation = !empty($orderDetails['variation']) ? trim($orderDetails['variation']) : null;
                        $order_status = $orderDetails['confirmation_status'] ?? 'pending';
                        
                        // If order was completed, reverse stock in inventory_variations
                        if ($order_status === 'completed' && $inventory_id > 0) {
                            if ($variation) {
                                $varCheck = $db->prepare("SELECT id, quantity FROM inventory_variations WHERE inventory_id = ? AND variation = ? LIMIT 1");
                                $varCheck->execute([$inventory_id, $variation]);
                                $existingVar = $varCheck->fetch(PDO::FETCH_ASSOC);
                
                                if ($existingVar) {
                                    $newQty = max(0, (int)$existingVar['quantity'] - $order_quantity);
                                    if ($newQty > 0) {
                                        $updateVarStmt = $db->prepare("UPDATE inventory_variations SET quantity = ? WHERE id = ?");
                                        $updateVarStmt->execute([$newQty, (int)$existingVar['id']]);
                                    } else {
                                        $deleteVarStmt = $db->prepare("DELETE FROM inventory_variations WHERE id = ?");
                                        $deleteVarStmt->execute([(int)$existingVar['id']]);
                }
                                }
                            } else {
                                $invCheck = $db->prepare("SELECT id, quantity FROM inventory WHERE id = ? LIMIT 1");
                                $invCheck->execute([$inventory_id]);
                                $existingInv = $invCheck->fetch(PDO::FETCH_ASSOC);
                                
                                if ($existingInv) {
                                    $newQty = max(0, (int)$existingInv['quantity'] - $order_quantity);
                                    $updateStmt = $db->prepare("UPDATE inventory SET quantity = ? WHERE id = ?");
                                    $updateStmt->execute([$newQty, $inventory_id]);
                                }
                            }
                        }
                        
                        // Delete notifications
                        try {
                            $notifStmt = $db->prepare("DELETE FROM notifications WHERE order_id = ?");
                            $notifStmt->execute([$order_id]);
                        } catch (Exception $e) {
                            // Ignore
                        }
                        
                        // DELETE THE ENTIRE ORDER FROM admin_orders TABLE
                        // IMPORTANT: This deletes the complete order row, not just the item name
                        error_log("=== DELETING COMPLETE ORDER $order_id FROM admin_orders TABLE ===");
                        
                        // Verify the order exists before deletion
                        $beforeDeleteCheck = $db->prepare("SELECT id, inventory_id, quantity, item_name FROM admin_orders o LEFT JOIN inventory i ON o.inventory_id = i.id WHERE o.id = ? LIMIT 1");
                        $beforeDeleteCheck->execute([$order_id]);
                        $orderBefore = $beforeDeleteCheck->fetch(PDO::FETCH_ASSOC);
                        if (!$orderBefore) {
                            throw new Exception("Order #$order_id does not exist in admin_orders table.");
                        }
                        error_log("Order $order_id found. Will delete complete row: " . json_encode($orderBefore));
                        
                        // DELETE THE ENTIRE ORDER ROW - This removes ALL fields, not just item_name
                        // Use direct DELETE query to ensure the complete order is removed
                        $deleteStmt = $db->prepare("DELETE FROM admin_orders WHERE id = ?");
                        $deleteResult = $deleteStmt->execute([$order_id]);
                        $rowsDeleted = $deleteStmt->rowCount();
                        
                        error_log("=== DELETE QUERY EXECUTED ===");
                        error_log("Order ID: $order_id");
                        error_log("Query result: " . ($deleteResult ? 'SUCCESS' : 'FAILED'));
                        error_log("Rows deleted: $rowsDeleted");
                        
                        if (!$deleteResult) {
                            $errorInfo = $deleteStmt->errorInfo();
                            error_log("DELETE query error: " . json_encode($errorInfo));
                            throw new Exception("DELETE query execution failed for order #$order_id. Error: " . ($errorInfo[2] ?? 'Unknown error'));
                        }
                        
                        if ($rowsDeleted <= 0) {
                            error_log("WARNING: DELETE query executed but 0 rows were deleted!");
                            // Check if order still exists
                            $checkAfterDelete = $db->prepare("SELECT id FROM admin_orders WHERE id = ? LIMIT 1");
                            $checkAfterDelete->execute([$order_id]);
                            if ($checkAfterDelete->fetch()) {
                                throw new Exception("DELETE query executed but order #$order_id still exists. Deletion failed.");
                            } else {
                                // Order was deleted, just rowCount returned 0 (can happen with PDO)
                                error_log("Order was deleted but rowCount returned 0 (PDO quirk)");
                            }
                        }
                        
                        error_log("SUCCESS: Order $order_id completely deleted from admin_orders table ($rowsDeleted row(s) removed).");
                        
                        // Commit transaction to finalize deletion
                        if (!$db->commit()) {
                            throw new Exception("Failed to commit transaction for order deletion.");
                        }
                        error_log("Transaction committed - order $order_id permanently removed from database.");
                        
                        // Set success message
                        $_SESSION['order_delete_message'] = "Order #{$order_id} was deleted successfully. The entire order has been removed from the system.";
                        $_SESSION['order_delete_message_type'] = "success";
                        
                        // Redirect with cache-busting parameter to force page refresh
                        header("Location: orders.php?deleted=" . $order_id . "&t=" . time());
                        exit();
                        
                    } catch (Exception $e) {
                        $db->rollBack();
                        $errorMsg = "Error deleting order: " . $e->getMessage();
                        $message = $errorMsg;
                        $messageType = "danger";
                        error_log("DELETE ERROR: " . $e->getMessage());
                        $_SESSION['order_delete_message'] = $errorMsg;
                        $_SESSION['order_delete_message_type'] = "danger";
                        
                        // Redirect even on error to show the error message
                        header("Location: orders.php");
                        exit();
                    }
                }
            }
        }
    }
}

// ---- Sync order status based on delivery status (Real-time delivery status following) ----
// This function updates order status based on aggregated delivery status changes
try {
    // Get all orders from admin_orders that need status syncing (exclude already completed/cancelled unless checking)
    $cand = $db->query("SELECT id, quantity, confirmation_status FROM admin_orders WHERE confirmation_status NOT IN ('cancelled')");
    while ($o = $cand->fetch(PDO::FETCH_ASSOC)) {
        $orderId = (int)$o['id'];
        $quantity = (int)$o['quantity'];
        $statusBefore = $o['confirmation_status'];

        // Get comprehensive delivery status aggregation for this order
        $agg = $db->prepare("SELECT 
            COALESCE(SUM(CASE WHEN status IN ('delivered', 'completed') THEN COALESCE(replenished_quantity, quantity, 0) ELSE 0 END),0) AS delivered_qty,
            SUM(CASE WHEN status IN ('pending') THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status IN ('in_transit') THEN 1 ELSE 0 END) AS in_transit_count,
            SUM(CASE WHEN status IN ('delivered', 'completed') THEN 1 ELSE 0 END) AS delivered_count,
            SUM(CASE WHEN status IN ('cancelled') THEN 1 ELSE 0 END) AS cancelled_count,
            COUNT(*) as total_deliveries
          FROM deliveries WHERE order_id = ?");
        $agg->execute([$orderId]);
        $aggRow = $agg->fetch(PDO::FETCH_ASSOC);
        
        $deliveredQty = (int)($aggRow['delivered_qty'] ?? 0);
        $pendingCount = (int)($aggRow['pending_count'] ?? 0);
        $inTransitCount = (int)($aggRow['in_transit_count'] ?? 0);
        $deliveredCount = (int)($aggRow['delivered_count'] ?? 0);
        $cancelledCount = (int)($aggRow['cancelled_count'] ?? 0);
        $totalDeliveries = (int)($aggRow['total_deliveries'] ?? 0);

        // Never change cancelled orders
        if ($statusBefore === 'cancelled') {
            continue;
        }

        // Determine new order status based on delivery status aggregation
        $newStatus = $statusBefore; // Default: keep current status
        
        // If order has deliveries, sync status based on delivery states
        if ($totalDeliveries > 0) {
            // If any delivery is cancelled, order becomes cancelled (unless already completed)
            if ($cancelledCount > 0 && $statusBefore !== 'completed') {
                $newStatus = 'cancelled';
            }
            // If all deliveries are delivered/completed and quantity is met, order becomes completed
            else if ($pendingCount === 0 && $inTransitCount === 0 && $deliveredCount === $totalDeliveries && $deliveredQty >= $quantity) {
                $newStatus = 'completed';
            }
            // If any delivery is in_transit or delivered (but not all), order becomes confirmed
            else if (($inTransitCount > 0 || $deliveredCount > 0) && $statusBefore === 'pending') {
                $newStatus = 'confirmed';
            }
            // If all deliveries are pending and order is confirmed, keep as confirmed (or revert to pending if needed)
            else if ($pendingCount === $totalDeliveries && $inTransitCount === 0 && $deliveredCount === 0 && $statusBefore === 'confirmed') {
                // Keep as confirmed or revert based on business logic - keeping confirmed for now
                $newStatus = 'confirmed';
            }
        }
        // If order has no deliveries but is not pending, might need to check if it should be pending
        else if ($totalDeliveries === 0 && $statusBefore !== 'pending' && $statusBefore !== 'completed') {
            // If no deliveries exist, keep current status unless business logic requires pending
            // For now, we'll keep current status
        }

        // Only update if status has changed
        if ($newStatus !== $statusBefore) {
            $db->beginTransaction();
            try {
                // Update order status
                $upd = $db->prepare("UPDATE admin_orders SET confirmation_status = ?, confirmation_date = NOW() WHERE id = ? AND confirmation_status != ?");
                $upd->execute([$newStatus, $orderId, $newStatus]);
                
                // Only proceed with completion logic if status changed to 'completed'
                // IMPORTANT: Once status becomes 'completed', the item automatically appears in admin_pos.php and pos.php
                // The POS queries filter by confirmation_status = 'completed', so items are immediately available for sale
                if ($newStatus === 'completed' && $statusBefore !== 'completed') {
                    // Get order details for inventory update (only when completing)
                    $orderDetails = $db->prepare("SELECT inventory_id, quantity, variation, unit_type, unit_price FROM admin_orders WHERE id = ?");
                $orderDetails->execute([$orderId]);
                $orderData = $orderDetails->fetch(PDO::FETCH_ASSOC);
                
                // Update inventory variation stocks when order is marked as completed
                    // This ensures stock is available in POS immediately after completion
                if ($orderData && $orderData['inventory_id']) {
                    require_once __DIR__ . '/../models/inventory_variation.php';
                    
                    $invVariation = new InventoryVariation($db);
                    
                    $inventory_id = (int)$orderData['inventory_id'];
                        $qty = (int)$orderData['quantity'];
                    $variation = !empty($orderData['variation']) ? trim($orderData['variation']) : null;
                    $unit_type = !empty($orderData['unit_type']) ? trim($orderData['unit_type']) : 'per piece';
                    $unit_price = isset($orderData['unit_price']) && $orderData['unit_price'] > 0 ? (float)$orderData['unit_price'] : null;
                    
                    $stockToAdd = $qty;
                    
                    if ($variation) {
                        $varCheck = $db->prepare("SELECT id, quantity FROM inventory_variations WHERE inventory_id = :inv_id AND variation = :var LIMIT 1");
                        $varCheck->execute([
                            ':inv_id' => $inventory_id,
                            ':var' => $variation
                        ]);
                        $existingVar = $varCheck->fetch(PDO::FETCH_ASSOC);
                        
                        if ($existingVar) {
                            $updateVarStmt = $db->prepare("UPDATE inventory_variations SET quantity = quantity + :qty WHERE id = :id");
                            $updateVarStmt->execute([
                                ':qty' => $stockToAdd,
                                ':id' => (int)$existingVar['id']
                            ]);
                            
                            if ($unit_price !== null) {
                                $updatePriceStmt = $db->prepare("UPDATE inventory_variations SET unit_price = :price WHERE id = :id");
                                $updatePriceStmt->execute([
                                    ':price' => $unit_price,
                                    ':id' => (int)$existingVar['id']
                                ]);
                            }
                        } else {
                            $invCheck = $db->prepare("SELECT id FROM inventory WHERE id = :id LIMIT 1");
                            $invCheck->execute([':id' => $inventory_id]);
                            if ($invCheck->fetch()) {
                                $varCheck2 = $db->prepare("SELECT id FROM inventory_variations WHERE inventory_id = :inv_id AND variation = :var LIMIT 1");
                                $varCheck2->execute([
                                    ':inv_id' => $inventory_id,
                                    ':var' => $variation
                                ]);
                                $existingVar2 = $varCheck2->fetch(PDO::FETCH_ASSOC);
                                
                                if (!$existingVar2) {
                                    $invVariation->createVariant($inventory_id, $variation, $unit_type, $stockToAdd, $unit_price);
                                } else {
                                    $updateVarStmt = $db->prepare("UPDATE inventory_variations SET quantity = quantity + :qty WHERE id = :id");
                                    $updateVarStmt->execute([
                                        ':qty' => $stockToAdd,
                                        ':id' => (int)$existingVar2['id']
                                    ]);
                                }
                            }
                        }
                    } else {
                        $invCheck = $db->prepare("SELECT id FROM inventory WHERE id = :id LIMIT 1");
                        $invCheck->execute([':id' => $inventory_id]);
                        if ($invCheck->fetch()) {
                            $updateStmt = $db->prepare("UPDATE inventory SET quantity = quantity + :qty WHERE id = :id");
                            $updateStmt->execute([':qty' => $stockToAdd, ':id' => $inventory_id]);
                        }
                        }
                    }
                    
                    // Log completion with inventory update
                    $log = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
                    $log->execute(['order_status_sync','admin_ui','admin_system',$orderId,null,$statusBefore,'completed',1,'Order auto-completed based on delivery status: all deliveries completed and inventory stocks updated']);
                } else {
                    // Log status change (non-completion changes)
                    $log = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
                    $log->execute(['order_status_sync','admin_ui','admin_system',$orderId,null,$statusBefore,$newStatus,1,'Order status synced with delivery status: pending='.$pendingCount.', in_transit='.$inTransitCount.', delivered='.$deliveredCount.', cancelled='.$cancelledCount]);
                }
                
                $db->commit();
                error_log("Order #{$orderId} status updated from '{$statusBefore}' to '{$newStatus}' based on delivery status aggregation");
                
            } catch (Throwable $e) {
                if ($db->inTransaction()) { 
                    $db->rollBack(); 
                }
                try {
                    $log = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())");
                    $log->execute(['order_status_sync','admin_ui','admin_system',$orderId,null,$statusBefore,$statusBefore,0,'Order status sync failed: '.$e->getMessage()]);
                } catch (Throwable $ie) {
                    error_log("Failed to log sync error: " . $ie->getMessage());
                }
                error_log("Failed to sync order #{$orderId} status: " . $e->getMessage());
            }
        }
        // No status change needed - deliveries match current order status
    }
} catch (Throwable $e) {
    error_log("Error syncing order status with delivery status: " . $e->getMessage());
    // Soft-fail: don't block page render
}

// ---- Normalize delivered deliveries to completed when order is completed ----
try {
    $normStmt = $db->query("SELECT d.id AS delivery_id, d.order_id, d.status, o.confirmation_status FROM deliveries d INNER JOIN orders o ON o.id=d.order_id WHERE d.status='delivered' AND o.confirmation_status IN ('completed')");
    while ($row = $normStmt->fetch(PDO::FETCH_ASSOC)) {
        $deliveryId = (int)$row['delivery_id'];
        $orderId = (int)$row['order_id'];
        $statusBefore = $row['status'];
        $orderStatus = $row['confirmation_status'];

        $db->beginTransaction();
        try {
            // Lock order and delivery rows to ensure consistency
            $ordStmt = $db->prepare("SELECT confirmation_status FROM admin_orders WHERE id=? FOR UPDATE");
            $ordStmt->execute([$orderId]);
            $ordStatusNow = $ordStmt->fetchColumn();
            if ($ordStatusNow !== 'completed') {
                // Order no longer completed; skip
                $db->rollBack();
                continue;
            }

            $lockStmt = $db->prepare("SELECT status FROM deliveries WHERE id = ? FOR UPDATE");
            $lockStmt->execute([$deliveryId]);
            $currentStatus = $lockStmt->fetchColumn();

            // Validate transition: only delivered -> completed
            if ($currentStatus !== 'delivered') {
                // No-op: someone already changed it or invalid state
                try {
                    $log = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message) VALUES (?,?,?,?,?,?,?,?,?,?)");
                    $log->execute(['delivery_status_sync','admin_ui','admin_system',$orderId,$deliveryId,$statusBefore,$currentStatus ?? 'unknown',0,'Skip normalize: delivery not in delivered state']);
                } catch (Throwable $ie) {}
                $db->rollBack();
                continue;
            }

            // Perform update
            $updStmt = $db->prepare("UPDATE deliveries SET status='completed' WHERE id=? AND status='delivered'");
            $updStmt->execute([$deliveryId]);

            if ($updStmt->rowCount() > 0) {
                // Confirm update
                $confStmt = $db->prepare("SELECT status FROM deliveries WHERE id=?");
                $confStmt->execute([$deliveryId]);
                $afterStatus = $confStmt->fetchColumn();

                // Log result
                try {
                    $log = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message) VALUES (?,?,?,?,?,?,?,?,?,?)");
                    $log->execute(['delivery_status_sync','admin_ui','admin_system',$orderId,$deliveryId,$statusBefore,$afterStatus ?: 'unknown', ($afterStatus === 'completed') ? 1 : 0, ($afterStatus === 'completed') ? 'Normalized delivered->completed after order completion' : 'Delivery normalization failed confirmation']);
                } catch (Throwable $ie) {}

                if ($afterStatus === 'completed') {
                    $db->commit();
                } else {
                    $db->rollBack();
                }
            } else {
                // Update did not apply (race or invalid)
                try {
                    $log = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message) VALUES (?,?,?,?,?,?,?,?,?,?)");
                    $log->execute(['delivery_status_sync','admin_ui','admin_system',$orderId,$deliveryId,$statusBefore,$currentStatus ?? 'unknown',0,'Delivery update no-op (race or invalid transition)']);
                } catch (Throwable $ie) {}
                $db->rollBack();
            }
        } catch (Throwable $e2) {
            if ($db->inTransaction()) { $db->rollBack(); }
            try {
                $log = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $log->execute(['delivery_status_sync','admin_ui','admin_system',$orderId,$deliveryId,$statusBefore,$statusBefore,0,'Delivery normalization error: '.$e2->getMessage()]);
            } catch (Throwable $ie) {}
        }
    }
} catch (Throwable $e) {
    // Soft-fail: do not block page render
}

}

// Verify deletion if redirected with deleted parameter
if (isset($_GET['deleted']) && is_numeric($_GET['deleted'])) {
    $deletedId = (int)$_GET['deleted'];
    $verifyStmt = $db->prepare("SELECT COUNT(*) as cnt FROM admin_orders WHERE id = ?");
    $verifyStmt->execute([$deletedId]);
    $verifyResult = $verifyStmt->fetch(PDO::FETCH_ASSOC);
    $stillExists = (int)($verifyResult['cnt'] ?? 0);
    
    if ($stillExists > 0) {
        error_log("WARNING: Order #$deletedId still exists after deletion attempt!");
    } else {
        error_log("CONFIRMED: Order #$deletedId successfully deleted from admin_orders table.");
    }
}

// Get all orders - fresh query to ensure deleted orders don't show
$stmt = $order->readAll();
$adminOrderCount = 0;
$ordersCount = 0;
try { $adminOrderCount = (int)$db->query("SELECT COUNT(*) FROM admin_orders")->fetchColumn(); } catch (Throwable $e) { $adminOrderCount = 0; error_log('Orders page count error: ' . $e->getMessage()); }
try { $ordersCount = (int)$db->query("SELECT COUNT(*) FROM orders")->fetchColumn(); } catch (Throwable $e) { $ordersCount = 0; }
if ($adminOrderCount === 0 && $ordersCount > 0) { $message = 'Admin orders list is empty while supplier orders exist.'; $messageType = 'warning'; error_log('Diagnostic: admin_orders empty, orders count=' . $ordersCount); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders Management - Inventory & Stock Control System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
</head>
<body>
    <?php include_once 'includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include_once 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Orders Management</h1>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addOrderModal">
                        <i class="bi bi-plus-circle me-2"></i>Create New Order
                    </button>
                </div>
                
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <i class="bi bi-table me-1"></i>
                            Orders
                        </div>
                        <div></div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="ordersTable" class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        
                                        <th>Order #</th>
                                        <th>Item</th>
                                        <th>Per Unit</th>
                                        <th>Variation Ordered</th>
                                        <th>Quantity</th>
                                        <th>Supplier</th>
                                        <th>Total Price</th>
                                        <th>Status</th>
                                        <th>Created By</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($adminOrderCount === 0): ?>
                                        <tr>
                                            <td colspan="11" class="text-center text-muted">No admin orders found.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php while ($row = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                                    <tr data-order-id="<?php echo $row['id']; ?>">
                                             <td><?php echo $row['id']; ?></td>
                                             <td><?php echo $row['item_name']; ?></td>
                                             <td><?php echo htmlspecialchars($row['unit_type'] ?? 'N/A'); ?></td>
                                             <td>
                                                <?php 
                                                // Get the ordered variation (what was selected in supplier_details.php)
                                                $ordered_variation = isset($row['variation']) ? trim($row['variation']) : '';
                                                $inventory_id = isset($row['inventory_id']) ? (int)$row['inventory_id'] : 0;
                                                
                                                // Get all variations for this inventory item
                                                $available_variations = [];
                                                if ($inventory_id > 0 && isset($variationDataMap[$inventory_id])) {
                                                    $available_variations = $variationDataMap[$inventory_id];
                                                }
                                                ?>
                                                <?php if (!empty($available_variations)): ?>
                                                    <div class="variation-dropdown-container" data-order-id="<?php echo $row['id']; ?>">
                                                        <select class="form-select form-select-sm variation-combination-select" 
                                                                data-order-id="<?php echo $row['id']; ?>"
                                                                data-inventory-id="<?php echo $inventory_id; ?>"
                                                                aria-label="Select variation combination">
                                                            <?php 
                                                            // Filter to only show complete combinations and sort them
                                                            $completeVariations = array_filter($available_variations, function($var) {
                                                                $varKey = $var['variation'] ?? '';
                                                                // Only include complete combinations (contain '|') or single values without ':'
                                                                return !empty($varKey) && (strpos($varKey, '|') !== false || (strpos($varKey, ':') === false && trim($varKey) !== ''));
                                                            });
                                                            // Sort by formatted display name
                                                            usort($completeVariations, function($a, $b) {
                                                                $aFormatted = formatVariationForDisplay($a['variation'] ?? '');
                                                                $bFormatted = formatVariationForDisplay($b['variation'] ?? '');
                                                                return strcmp($aFormatted, $bFormatted);
                                                            });
                                                            ?>
                                                            <?php foreach ($completeVariations as $var): 
                                                                $varKey = $var['variation'];
                                                                $varPrice = isset($var['unit_price']) ? (float)$var['unit_price'] : 0;
                                                                $varStock = isset($var['quantity']) ? (int)$var['quantity'] : 0;
                                                                // Display as single line: "Adidas - Large - Red"
                                                                $varDisplay = formatVariationForDisplay($varKey);
                                                                $isSelected = ($ordered_variation === $varKey) ? 'selected' : '';
                                                            ?>
                                                                <option value="<?= htmlspecialchars($varKey) ?>" 
                                                                        data-price="<?= $varPrice ?>" 
                                                                        data-stock="<?= $varStock ?>"
                                                                        <?= $isSelected ?>>
                                                                    <?= htmlspecialchars($varDisplay) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <div class="variation-info mt-1" data-order-id="<?php echo $row['id']; ?>">
                                                            <?php if (!empty($ordered_variation)): 
                                                                // Find the ordered variation details
                                                                $ordered_var_data = null;
                                                                foreach ($available_variations as $var) {
                                                                    if ($var['variation'] === $ordered_variation) {
                                                                        $ordered_var_data = $var;
                                                                        break;
                                                                    }
                                                                }
                                                                if ($ordered_var_data):
                                                                    $display_price = isset($ordered_var_data['unit_price']) ? (float)$ordered_var_data['unit_price'] : 0;
                                                                    $display_stock = isset($ordered_var_data['quantity']) ? (int)$ordered_var_data['quantity'] : 0;
                                                            ?>
                                                                <div class="d-flex flex-wrap gap-1 align-items-center">
                                                                    <small class="text-muted"><strong>Price:</strong> ₱<?= number_format($display_price, 2) ?></small>
                                                                    <small class="text-muted">|</small>
                                                                    <small class="text-muted"><strong>Stock:</strong> <?= $display_stock ?> pcs</small>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php elseif (!empty($ordered_variation)): ?>
                                                    <div class="d-flex flex-column">
                                                        <span class="badge bg-info mb-1 fs-6">
                                                            <i class="bi bi-tag-fill me-1"></i><?= htmlspecialchars(formatVariationForDisplay($ordered_variation)) ?>
                                                        </span>
                                                        <small class="text-muted"><?= htmlspecialchars(formatVariationWithLabels($ordered_variation)) ?></small>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">No Variation</span>
                                                <?php endif; ?>
                                             </td>
                                             <td>
                                                <?php 
                                                $order_qty = (int)($row['quantity'] ?? 0);
                                                ?>
                                                <div class="d-flex flex-column align-items-start">
                                                    <span class="badge bg-primary fs-6 mb-1">
                                                        <i class="bi bi-box-seam me-1"></i><strong><?= $order_qty ?></strong> pcs
                                                    </span>
                                                    <small class="text-muted">Quantity Ordered</small>
                                                </div>
                                             </td>
                                             <td><?php echo $row['supplier_name']; ?></td>
                                             <td>
                                                 <div class="d-flex flex-column">
                                                     <?php 
                                                     // Calculate total price from order unit_price and quantity
                                                     $order_unit_price = isset($row['unit_price']) && $row['unit_price'] > 0 ? (float)$row['unit_price'] : 0;
                                                     $total_price = $order_unit_price * ($row['quantity'] ?? 0);
                                                     $unit_price_display = number_format($order_unit_price, 2);
                                                     $quantity_display = $row['quantity'] ?? 0;
                                                     ?>
                                                     <strong class="text-success fs-5 mb-1">₱<span class="item-total"><?= number_format($total_price, 2) ?></span></strong>
                                                     <div class="d-flex flex-wrap gap-1 align-items-center">
                                                         <small class="text-muted">Unit: ₱<?= $unit_price_display ?></small>
                                                         <small class="text-muted">×</small>
                                                         <small class="text-muted">Qty: <?= $quantity_display ?></small>
                                                     </div>
                                                 </div>
                                             </td>
                                             <td>
                                                 <?php if ($row['confirmation_status'] == 'pending'): ?>
                                                     <span class="badge bg-warning">Pending</span>
                                                 <?php elseif ($row['confirmation_status'] == 'confirmed'): ?>
                                                     <span class="badge bg-success">Confirmed</span>
                                                 <?php elseif ($row['confirmation_status'] == 'delivered'): ?>
                                                     <span class="badge bg-primary">Delivered</span>
                                                 <?php elseif ($row['confirmation_status'] == 'completed'): ?>
                                                     <span class="badge bg-info">Completed</span>
                                                 <?php else: ?>
                                                     <span class="badge bg-danger">Cancelled</span>
                                                 <?php endif; ?>
                                             </td>
                                             <td>
                                                 <?php if ($row['is_automated']): ?>
                                                     <span class="badge bg-info">Automated</span>
                                                 <?php else: ?>
                                                     <?php echo $row['username']; ?>
                                                 <?php endif; ?>
                                             </td>
                                             <td><?php echo date('M d, Y', strtotime($row['order_date'])); ?></td>
                                             <td>
                                                 <button type="button" class="btn btn-sm btn-info view-btn" 
                                                     data-id="<?php echo $row['id']; ?>"
                                                     data-inventory-id="<?php echo $row['inventory_id']; ?>"
                                                     data-item="<?php echo $row['item_name']; ?>"
                                                     data-unit="<?php echo $row['unit_type']; ?>"
                                                     data-variation="<?php echo htmlspecialchars($row['variation'] ?? ''); ?>"
                                                     data-supplier="<?php echo $row['supplier_name']; ?>"
                                                     data-quantity="<?php echo $row['quantity']; ?>"
                                                     data-status="<?php echo $row['confirmation_status']; ?>"
                                                     data-automated="<?php echo $row['is_automated']; ?>"
                                                     data-username="<?php echo $row['username']; ?>"
                                                     data-date="<?php echo date('M d, Y', strtotime($row['order_date'])); ?>"
                                                     data-bs-toggle="modal" data-bs-target="#viewOrderModal">
                                                     <i class="bi bi-eye"></i>
                                                 </button>
                                                 <?php if ($row['confirmation_status'] !== 'cancelled' && $row['confirmation_status'] !== 'completed'): ?>
                                                 <button type="button" class="btn btn-sm btn-warning cancel-btn"
                                                     data-id="<?php echo $row['id']; ?>"
                                                     data-item="<?php echo $row['item_name']; ?>"
                                                     data-bs-toggle="modal" data-bs-target="#cancelOrderModal"
                                                     title="Cancel Order">
                                                     <i class="bi bi-x-circle"></i>
                                                 </button>
                                                 <?php endif; ?>
                                                 <button type="button" class="btn btn-sm btn-danger delete-btn"
                                                     data-id="<?php echo $row['id']; ?>"
                                                     data-item="<?php echo htmlspecialchars(!empty($row['item_name']) ? $row['item_name'] : 'Order #' . $row['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                     <i class="bi bi-trash"></i>
                                                 </button>
                                             </td>
                                         </tr>
                                     <?php endwhile; ?>
                                 </tbody>
                                 <!-- Grand Total removed as requested -->
                             </table>
                         </div>
                     </div>
                 </div>
             </main>
         </div>
     </div>
     
     <!-- Add Order Modal -->
     <div class="modal fade" id="addOrderModal" tabindex="-1" aria-labelledby="addOrderModalLabel" aria-hidden="true">
         <div class="modal-dialog">
             <div class="modal-content">
                 <div class="modal-header">
                     <h5 class="modal-title" id="addOrderModalLabel">Create New Order</h5>
                     <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                 </div>
                 <form id="createOrderForm">
                     <div class="modal-body">
                         <div class="alert alert-info">
                             <i class="bi bi-info-circle me-2"></i>
                             <strong>Select a supplier</strong> to browse their products and create an order.
                         </div>
                         <div class="mb-3">
                             <label for="supplier_id_select" class="form-label">Select Supplier *</label>
                             <select class="form-select" id="supplier_id_select" name="supplier_id" required>
                                 <option value="">-- Choose a supplier --</option>
                                 <?php foreach ($suppliers as $supplier): ?>
                                     <option value="<?php echo $supplier['id']; ?>" 
                                             data-name="<?php echo htmlspecialchars($supplier['name']); ?>">
                                         <?php echo htmlspecialchars($supplier['name']); ?>
                                         <?php if (!empty($supplier['contact_phone'])): ?>
                                             (<?php echo htmlspecialchars($supplier['contact_phone']); ?>)
                                         <?php endif; ?>
                                     </option>
                                 <?php endforeach; ?>
                             </select>
                             <div class="form-text">Choose a supplier to view their products and place an order.</div>
                         </div>
                     </div>
                     <div class="modal-footer">
                         <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                         <button type="button" class="btn btn-primary" id="proceedToSupplierBtn" disabled>
                             <i class="bi bi-arrow-right me-2"></i>Proceed to Supplier Products
                         </button>
                     </div>
                 </form>
             </div>
         </div>
     </div>
     
     <!-- View Order Modal -->
     <div class="modal fade" id="viewOrderModal" tabindex="-1" aria-labelledby="viewOrderModalLabel" aria-hidden="true">
         <div class="modal-dialog">
             <div class="modal-content">
                 <div class="modal-header">
                     <h5 class="modal-title" id="viewOrderModalLabel">View Order</h5>
                     <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                 </div>
                 <div class="modal-body">
                     <p><strong>Order #:</strong> <span id="view-id"></span></p>
                     <p><strong>Item:</strong> <span id="view-item"></span></p>
                     <p><strong>Per Unit:</strong> <span id="view-unit"></span></p>
                     <p><strong>Variation:</strong> <span id="view-variation"></span></p>
                     <p><strong>Supplier:</strong> <span id="view-supplier"></span></p>
                     <p><strong>Quantity:</strong> <span id="view-quantity"></span></p>
                     <p><strong>Available Stock:</strong> <span id="view-stock"></span></p>
                     <p><strong>Status:</strong> <span id="view-status"></span></p>
                     <p><strong>Created By:</strong> <span id="view-created-by"></span></p>
                     <p><strong>Date:</strong> <span id="view-date"></span></p>
                 </div>
                 <div class="modal-footer">
                     <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                 </div>
             </div>
         </div>
     </div>
     

     <!-- Delete Order Modal -->
     <div class="modal fade" id="deleteOrderModal" tabindex="-1" aria-labelledby="deleteOrderModalLabel" aria-hidden="true">
         <div class="modal-dialog">
             <div class="modal-content">
                 <div class="modal-header">
                     <h5 class="modal-title" id="deleteOrderModalLabel">Delete Order</h5>
                     <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                 </div>
                 <form method="POST" action="orders.php" id="deleteOrderForm">
                     <div class="modal-body">
                         <input type="hidden" name="action" value="delete">
                         <input type="hidden" name="id" id="delete-id" value="">
                         <input type="hidden" name="item" id="delete-item-input" value="">
                         <p>Are you sure you want to delete the order for <strong id="delete-item-name"></strong>?</p>
                         <p class="text-danger">This action cannot be undone.</p>
                         <div class="alert alert-warning">
                             <i class="bi bi-exclamation-triangle me-2"></i>
                             <strong>Warning:</strong> This will permanently remove the order from the system.
                         </div>
                     </div>
                     <div class="modal-footer">
                         <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                         <button type="submit" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
                     </div>
                 </form>
             </div>
         </div>
     </div>
     
     <!-- Cancel Order Modal -->
     <div class="modal fade" id="cancelOrderModal" tabindex="-1" aria-labelledby="cancelOrderModalLabel" aria-hidden="true">
         <div class="modal-dialog">
             <div class="modal-content">
                 <div class="modal-header">
                     <h5 class="modal-title" id="cancelOrderModalLabel">Cancel Order</h5>
                     <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                 </div>
                 <form method="POST">
                     <div class="modal-body">
                         <input type="hidden" name="action" value="cancel">
                         <input type="hidden" name="id" id="cancel-id">
                         <input type="hidden" name="item" id="cancel-item-input">
                         <p>Are you sure you want to cancel the order for <strong id="cancel-item-name"></strong>?</p>
                         <div class="alert alert-info">
                             <i class="bi bi-info-circle me-2"></i>
                             <strong>Note:</strong> This will notify the supplier that the order has been cancelled. The order status will be updated to "Cancelled" and will be visible to the supplier.
                         </div>
                         <div class="alert alert-warning">
                             <i class="bi bi-exclamation-triangle me-2"></i>
                             <strong>Warning:</strong> Cancelled orders cannot be reactivated. You will need to create a new order if needed.
                         </div>
                     </div>
                     <div class="modal-footer">
                         <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep Order</button>
                         <button type="submit" class="btn btn-warning">Cancel Order</button>
                     </div>
                 </form>
             </div>
         </div>
     </div>
     
     <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
     <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
     <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize DataTable
           $('#ordersTable').DataTable({
                responsive: true,
                order: [[0, 'desc']]
            });
             
             // Clean URL after delete redirect (page already reloaded from server)
             const urlParams = new URLSearchParams(window.location.search);
             if (urlParams.has('deleted')) {
                 // Page already refreshed from server with new data, just clean URL
                 setTimeout(function() {
                     if (history.replaceState) {
                         history.replaceState({}, document.title, 'orders.php');
                     }
                 }, 500);
             }
            
            // Variation data map injected from PHP
            const variationDataMap = <?php echo json_encode($variationDataMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            
            function populateVariations(inventoryId) {
                const $varSelect = $('#variation');
                $varSelect.empty();
                $varSelect.append(new Option('Select Variation', ''));
                const variants = variationDataMap[inventoryId] || [];
                for (const v of variants) {
                    const text = `${v.variation} (${v.unit_type || 'N/A'}) - Stock: ${v.stock || 0}`;
                    $varSelect.append(new Option(text, v.variation));
                }
                // Require selection only when variants exist
                $varSelect.prop('required', variants.length > 0);
                // Reset dependent fields
                $('#unit_type').val('');
                $('#available_stock').val('');
            }
            
            function setFieldsFromVariation(inventoryId, variationName) {
                const variants = variationDataMap[inventoryId] || [];
                const found = variants.find(v => v.variation === variationName);
                if (found) {
                    $('#unit_type').val(found.unit_type || '');
                    $('#available_stock').val(found.stock || 0);
                    
                    // Set max quantity based on available stock
                    const maxStock = parseInt(found.stock || 0);
                    $('#quantity').attr('max', maxStock);
                    
                    // Add validation for quantity
                    $('#quantity').off('input').on('input', function() {
                        const qty = parseInt($(this).val());
                        if (qty > maxStock) {
                            alert(`Only ${maxStock} units available in stock!`);
                            $(this).val(maxStock);
                        }
                    });
                } else {
                    $('#unit_type').val('');
                    $('#available_stock').val('');
                }
            }
             
             // Format variation for display helper (matches PHP function)
             function formatVariationForDisplayJS(variation) {
                 if (!variation) return 'N/A';
                 if (variation.indexOf('|') === -1 && variation.indexOf(':') === -1) return variation;
                 const parts = variation.split('|');
                 const values = [];
                 parts.forEach(part => {
                     const av = part.split(':');
                     if (av.length === 2) {
                         values.push(av[1].trim());
                     } else {
                         values.push(part.trim());
                     }
                 });
                 return values.join(' ');
             }
             
             // View Order
             $('.view-btn').click(function() {
                 const $btn = $(this);
                 const inventoryId = parseInt($btn.data('inventory-id') || 0);
                 const rawVariation = $btn.data('variation') || '';
                 
                 $('#view-id').text($btn.data('id'));
                 $('#view-item').text($btn.data('item'));
                 $('#view-unit').text($btn.data('unit') || 'N/A');
                 const formattedVariation = formatVariationForDisplayJS(rawVariation);
                 $('#view-variation').html(formattedVariation && formattedVariation !== 'N/A' ? `<span class="badge bg-info">${formattedVariation}</span>` : 'N/A');
                 $('#view-supplier').text($btn.data('supplier'));
                 $('#view-quantity').text($btn.data('quantity'));
                 
                 // Get available stock from variationDataMap (connected to admin_pos.php and alerts.php calculation)
                 let availableStock = 'N/A';
                 if (inventoryId > 0 && variationDataMap[inventoryId]) {
                     const variants = variationDataMap[inventoryId];
                     if (rawVariation) {
                         const found = variants.find(v => v.variation === rawVariation);
                         if (found && found.stock !== undefined) {
                             availableStock = found.stock + ' pcs';
                         }
                     } else {
                         // Base stock (no variation) - sum all variation stocks or get base stock
                         let totalStock = 0;
                         variants.forEach(v => {
                             if (v.stock !== undefined) {
                                 totalStock += parseInt(v.stock || 0);
                             }
                         });
                         availableStock = totalStock > 0 ? totalStock + ' pcs' : '0 pcs';
                     }
                 }
                 $('#view-stock').text(availableStock);
                 
                 // Set status with badge
                 let status = $btn.data('status');
                 let statusBadge = '';
                 
                 if (status === 'pending') {
                     statusBadge = '<span class="badge bg-warning">Pending</span>';
                 } else if (status === 'confirmed') {
                     statusBadge = '<span class="badge bg-success">Confirmed</span>';
                 } else if (status === 'delivered') {
                     statusBadge = '<span class="badge bg-primary">Delivered</span>';
                 } else if (status === 'completed') {
                     statusBadge = '<span class="badge bg-info">Completed</span>';
                 } else {
                     statusBadge = '<span class="badge bg-danger">Cancelled</span>';
                 }
                 
                 $('#view-status').html(statusBadge);
                 
                 // Set created by
                 let isAutomated = $btn.data('automated');
                 let username = $btn.data('username');
                 
                 if (isAutomated == 1) {
                     $('#view-created-by').html('<span class="badge bg-info">Automated</span>');
                 } else {
                     $('#view-created-by').text(username);
                 }
                 
                 $('#view-date').text($btn.data('date'));
             });
             

             // Cancel Order
             $('.cancel-btn').click(function() {
                 const orderId = $(this).data('id');
                 const itemName = $(this).data('item');
                 
                 // Enhanced validation for cancel operation
                 if (!orderId || orderId <= 0) {
                     alert('Invalid order ID. Cannot proceed with cancellation.');
                     return false;
                 }
                 
                 if (!itemName || itemName.trim() === '') {
                     alert('Item name is required for cancellation confirmation.');
                     return false;
                 }
                 
                 // Set modal data
                 $('#cancel-id').val(orderId);
                 $('#cancel-item-name').text(itemName);
                 $('#cancel-item-input').val(itemName);
                 
                 console.log('Cancel operation initiated for order ID:', orderId, 'Item:', itemName);
             });

            
             
             // Delete Order - Handle button click to populate modal
             $(document).on('click', '.delete-btn', function(e) {
                 e.preventDefault();
                 e.stopPropagation();
                 
                 const button = $(this);
                 const row = button.closest('tr');
                 
                 console.log('=== DELETE BUTTON CLICKED ===');
                 console.log('Button HTML:', button[0].outerHTML);
                 
                 // Get data-id (should always work)
                 let orderId = button.attr('data-id');
                 if (!orderId) {
                     orderId = button.data('id');
                 }
                 
                 // Get data-item - try multiple methods
                 let itemName = button.attr('data-item');
                 console.log('Item name from attr(data-item):', itemName);
                 
                 // If empty, try jQuery data() method
                 if (!itemName || itemName === '' || itemName === null || itemName === undefined) {
                     itemName = button.data('item');
                     console.log('Item name from data(item):', itemName);
                 }
                 
                 // Fallback: Extract from table row if data attributes still not found
                 if ((!itemName || itemName === '' || itemName === null || itemName === undefined) && row.length) {
                     // Item name is in the third column (index 2, after checkbox and order #)
                    const itemNameCell = row.find('td').eq(1);
                     if (itemNameCell.length) {
                         itemName = itemNameCell.text().trim();
                         console.log('Item name from table cell:', itemName);
                     }
                 }
                 
                 // Another fallback: Get from checkbox in the same row (it also has data-item)
                
                 
                 // Clean up extracted values
                 if (orderId) {
                     orderId = orderId.toString().trim();
                 }
                 if (itemName) {
                     itemName = itemName.toString().trim();
                 }
                 
                 console.log('Final Order ID:', orderId);
                 console.log('Final Item Name:', itemName);
                 console.log('Order ID type:', typeof orderId);
                 console.log('Item Name type:', typeof itemName);
                 console.log('Order ID is valid:', orderId && !isNaN(parseInt(orderId)));
                 console.log('Item Name is valid:', itemName && itemName !== '');
                 
                 // Validate data
                 const orderIdNum = parseInt(orderId);
                 if (!orderId || isNaN(orderIdNum) || orderIdNum <= 0) {
                     console.error('❌ Invalid Order ID:', orderId);
                     console.error('Button attributes:', {
                         'data-id': button.attr('data-id'),
                         'data-item': button.attr('data-item'),
                         'all-attributes': button[0].attributes ? Array.from(button[0].attributes).map(a => a.name + '=' + a.value) : 'none'
                     });
                     alert('Error: Could not find order ID. Please refresh the page and try again.');
                     return false;
                 }
                 
                 if (!itemName || itemName === '' || itemName === null || itemName === undefined) {
                     console.error('❌ Missing Item Name');
                     console.error('Attempted extractions:', {
                         'from-attr': button.attr('data-item'),
                         'from-data': button.data('item'),
                         'from-table-cell': row.find('td').eq(2).text().trim(),
                         'from-checkbox': ''
                     });
                     console.error('Button element:', button[0]);
                     console.error('Row element:', row[0]);
                     
                     // Try one more time - get directly from DOM attribute
                     const rawItemAttr = button[0].getAttribute('data-item');
                     console.error('Raw DOM attribute:', rawItemAttr);
                     
                     if (rawItemAttr && rawItemAttr.trim() !== '') {
                         itemName = rawItemAttr.trim();
                         console.log('✅ Got item name from raw DOM attribute:', itemName);
                     } else {
                         // Last resort: Try to decode HTML entities if present
                         const tempDiv = document.createElement('div');
                         const attrValue = button.attr('data-item') || '';
                         tempDiv.innerHTML = attrValue;
                         const decoded = tempDiv.textContent || tempDiv.innerText || '';
                         if (decoded && decoded.trim() !== '') {
                             itemName = decoded.trim();
                             console.log('✅ Got item name after HTML decode:', itemName);
                         } else {
                             // Final fallback: Use "Order #ID" if item name truly not available
                             itemName = 'Order #' + orderIdNum;
                             console.log('⚠️ Using fallback item name:', itemName);
                         }
                     }
                 }
                 
                 // Populate modal fields
                 $('#delete-id').val(orderIdNum);
                 $('#delete-item-name').text(itemName);
                 $('#delete-item-input').val(itemName);
                 $('#confirmDeleteBtn').prop('disabled', false).html('Delete');
                 
                 console.log('Modal fields populated:', {
                     'delete-id': $('#delete-id').val(),
                     'delete-item-name': $('#delete-item-name').text(),
                     'delete-item-input': $('#delete-item-input').val()
                 });
                 
                 // Show the modal using Bootstrap 5 API
                 const deleteModalElement = document.getElementById('deleteOrderModal');
                 if (deleteModalElement) {
                     const deleteModal = new bootstrap.Modal(deleteModalElement);
                     deleteModal.show();
                     console.log('✅ Modal shown successfully');
                 } else {
                     console.error('❌ Modal element not found');
                     alert('Error: Delete modal not found. Please refresh the page.');
                     return false;
                 }
                 
                 console.log('✅ Modal populated and shown - ID:', orderIdNum, 'Item:', itemName);
             });
             
             // Also handle modal show event as backup
             $('#deleteOrderModal').on('show.bs.modal', function (event) {
                 const button = $(event.relatedTarget);
                 
                 // Only populate if not already set (in case click handler already set it)
                 if (!$('#delete-id').val() || $('#delete-id').val() === '') {
                     const orderId = button.attr('data-id') || button.data('id');
                     const itemName = button.attr('data-item') || button.data('item');
                     
                     if (orderId && parseInt(orderId) > 0 && itemName && itemName.trim() !== '') {
                         $('#delete-id').val(orderId);
                         $('#delete-item-name').text(itemName);
                         $('#delete-item-input').val(itemName);
                         $('#confirmDeleteBtn').prop('disabled', false).html('Delete');
                     }
                 }
             });
             
             // Reset modal when it's hidden
             $('#deleteOrderModal').on('hidden.bs.modal', function () {
                 $('#delete-id').val('');
                 $('#delete-item-input').val('');
                 $('#delete-item-name').text('');
                 $('#confirmDeleteBtn').prop('disabled', false).html('Delete');
             });
             
             // AJAX-based order deletion with dynamic UI updates
             $(document).on('submit', '#deleteOrderForm', function(e) {
                 e.preventDefault(); // Always prevent default form submission
                 
                 const orderId = $('#delete-id').val();
                 const itemName = $('#delete-item-input').val();
                 const $btn = $('#confirmDeleteBtn');
                 const $modal = $('#deleteOrderModal');
                 const $form = $(this);
                 
                 // Basic validation
                 if (!orderId || parseInt(orderId) <= 0) {
                     alert('Invalid order ID. Cannot delete order.');
                     return false;
                 }
                 
                 if (!itemName || itemName.trim() === '') {
                     alert('Item name is required. Cannot delete order.');
                     return false;
                 }
                 
                 // Show loading state
                 $btn.prop('disabled', true);
                 const originalBtnText = $btn.html();
                 $btn.html('<i class="bi bi-hourglass-split"></i> Deleting...');
                 
                 // Perform AJAX deletion
                 $.ajax({
                     url: 'api/delete_order.php',
                     method: 'POST',
                     dataType: 'json',
                     contentType: 'application/x-www-form-urlencoded; charset=UTF-8',
                     data: {
                         id: orderId,
                         item: itemName
                     },
                     beforeSend: function(xhr) {
                         console.log('Sending AJAX request to:', 'api/delete_order.php');
                         console.log('Data:', { id: orderId, item: itemName });
                     },
                     success: function(response) {
                         if (response.success) {
                             // Show success message
                             showAlert('success', response.message || `Order #${orderId} was deleted successfully.`);
                             
                             // Find and remove the row from the table
                             const $row = $(`tr[data-order-id="${orderId}"]`);
                             if ($row.length) {
                                 // Remove with animation
                                 $row.fadeOut(300, function() {
                                     $(this).remove();
                                     
                                     // Update DataTable if it exists
                                     const table = $('#ordersTable').DataTable();
                                     if (table) {
                                         table.row($row).remove().draw(false);
                                     }
                                     
                                     // Update bulk delete button state
                                     updateBulkDeleteOrdersButton();
                                     
                                     // Show notification if needed
                                     if (typeof showNotification === 'function') {
                                         showNotification('success', `Order #${orderId} deleted successfully`);
                                     }
                                 });
                             } else {
                                 // Row not found, reload table
                                 location.reload();
                             }
                             
                             // Close modal
                             const modal = bootstrap.Modal.getInstance($modal[0]);
                             if (modal) {
                                 modal.hide();
                             }
                         } else {
                             // Show error message
                             showAlert('danger', response.message || 'Failed to delete order. Please try again.');
                             $btn.prop('disabled', false);
                             $btn.html(originalBtnText);
                         }
                     },
                     error: function(xhr, status, error) {
                         console.error('=== DELETE AJAX ERROR ===');
                         console.error('Status:', status);
                         console.error('Error:', error);
                         console.error('HTTP Status:', xhr.status);
                         console.error('Response Text:', xhr.responseText);
                         console.error('Ready State:', xhr.readyState);
                         
                         let errorMessage = 'An error occurred while deleting the order.';
                         let errorDetails = '';
                         
                         // Try to parse JSON response
                         try {
                             const errorResponse = JSON.parse(xhr.responseText);
                             if (errorResponse.message) {
                                 errorMessage = errorResponse.message;
                             }
                             if (errorResponse.error_code) {
                                 errorDetails = ' (' + errorResponse.error_code + ')';
                             }
                         } catch (e) {
                             // Not JSON - might be HTML error page or plain text
                             console.error('Response is not JSON. Raw response:', xhr.responseText.substring(0, 200));
                             
                             // Check if it's a 404 (endpoint not found)
                             if (xhr.status === 404) {
                                 errorMessage = 'Delete endpoint not found. Please check if the API file exists.';
                             } else if (xhr.status === 403) {
                                 errorMessage = 'Access denied. You may not have permission to delete orders.';
                             } else if (xhr.status === 500) {
                                 errorMessage = 'Server error occurred. Please check server logs.';
                             } else if (xhr.status === 0) {
                                 errorMessage = 'Network error. Please check your connection or server status.';
                             } else {
                                 errorMessage = 'Error ' + xhr.status + ': ' + (error || 'Unknown error');
                             }
                         }
                         
                         // Show error message
                         showAlert('danger', errorMessage + errorDetails);
                         $btn.prop('disabled', false);
                         $btn.html(originalBtnText);
                         
                         // Log to console for debugging
                         console.error('Final error message:', errorMessage);
                     },
                     complete: function() {
                         // Re-enable button after a short delay
                         setTimeout(function() {
                             $btn.prop('disabled', false);
                             $btn.html(originalBtnText);
                         }, 1000);
                     }
                 });
                 
                 return false;
             });
             
             // Helper function to show alert messages
             function showAlert(type, message) {
                 // Remove existing alerts
                 $('.alert').remove();
                 
                 // Create alert element
                 const alertHtml = `
                     <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                         ${message}
                         <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                     </div>
                 `;
                 
                 // Insert alert at the top of main content
                 $('main .d-flex.justify-content-between').after(alertHtml);
                 
                 // Auto-dismiss after 5 seconds
                 setTimeout(function() {
                     $('.alert').fadeOut(300, function() {
                         $(this).remove();
                     });
                 }, 5000);
             }
             
             // Also handle direct button click as backup
             $(document).on('click', '#confirmDeleteBtn', function(e) {
                 // Trigger form submission
                 $('#deleteOrderForm').submit();
             });
             
             // Handle variation combination dropdown change
             $(document).on('change', '.variation-combination-select', function() {
                 const $select = $(this);
                 const orderId = $select.data('order-id');
                 const $selectedOption = $select.find('option:selected');
                 const price = parseFloat($selectedOption.data('price') || 0);
                 const stock = parseInt($selectedOption.data('stock') || 0, 10);
                 
                 // Update the variation info display for this order
                 const $infoContainer = $(`.variation-info[data-order-id="${orderId}"]`);
                 if ($infoContainer.length) {
                     $infoContainer.html(`
                         <div class="d-flex flex-wrap gap-1 align-items-center">
                             <small class="text-muted"><strong>Price:</strong> ₱${price.toFixed(2)}</small>
                             <small class="text-muted">|</small>
                             <small class="text-muted"><strong>Stock:</strong> ${stock} pcs</small>
                         </div>
                     `);
                 }
             });
             
             // Handle supplier selection - redirect to supplier_details.php
             $('#supplier_id_select').change(function() {
                 const supplierId = $(this).val();
                 const $proceedBtn = $('#proceedToSupplierBtn');
                 
                 if (supplierId && supplierId !== '') {
                     $proceedBtn.prop('disabled', false);
                 } else {
                     $proceedBtn.prop('disabled', true);
                 }
             });
             
             // Proceed to supplier details page
             $('#proceedToSupplierBtn').click(function() {
                 const supplierId = $('#supplier_id_select').val();
                 if (supplierId && supplierId !== '') {
                     // Redirect to supplier_details.php with selected supplier
                     window.location.href = 'supplier_details.php?supplier_id=' + encodeURIComponent(supplierId);
                 } else {
                     alert('Please select a supplier first.');
                 }
             });
             
             // Also allow form submission on Enter key for supplier selection
             $('#createOrderForm').on('submit', function(e) {
                 e.preventDefault();
                 $('#proceedToSupplierBtn').click();
             });
             
             // Calculate grand total
             function calculateGrandTotal() {
                 let grandTotal = 0;
                 $('.item-total').each(function() {
                     const itemTotal = parseFloat($(this).text().replace('₱', '').replace(',', '')) || 0;
                     grandTotal += itemTotal;
                 });
                 $('#grand-total').text(grandTotal.toFixed(2));
             }

             // Format currency as Philippine Peso
             function formatPeso(amount) {
                 return '₱' + amount.toFixed(2);
             }

             // Calculate initial grand total
             calculateGrandTotal();
        });
    </script>
    <script>
      (function(){
        function refreshAlertBadge(){
          $.ajax({
            url: 'ajax/get_alert_counts.php',
            method: 'GET',
            dataType: 'json'
          }).done(function(r){
            var c = parseInt((r && (r.active_stock_alerts||r.total_alerts||0)) ,10) || 0;
            var $b = $('#sidebarAlertBadge');
            if(c>0){
              if($b.length===0){
                $('a[href="alerts.php"]').append('<span class="badge bg-danger ms-1" id="sidebarAlertBadge">'+c+'</span>');
              } else {
                $b.text(c).show();
              }
            } else if($b.length){
              $b.hide();
            }
          });
        }
        setInterval(refreshAlertBadge,30000);
        $(document).on('click','.confirm-delivery-btn, .complete-delivery-btn, .cancel-btn, #proceedToSupplierBtn',function(){
          setTimeout(refreshAlertBadge,500);
        });
      })();
    </script>
     
     <!-- Notification System -->
     <script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
     <script src="assets/js/notifications.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const params = new URLSearchParams(window.location.search);
        const focusId = params.get('focus_order_id');
        if (focusId) {
            const row = document.querySelector(`#ordersTable tbody tr[data-order-id="${focusId}"]`);
            if (row) {
                row.classList.add('table-warning');
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                setTimeout(() => row.classList.remove('table-warning'), 4000);
            }
        }
    });
    </script>
</body>
</html>
