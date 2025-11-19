<?php
session_start();
require_once '../config/database.php';
require_once '../models/order.php';
require_once '../models/delivery.php';
require_once '../models/payment.php';

// Removed: delivery-based auto-completion helper is no longer used

// Check if user is logged in as supplier
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'supplier') {
    header("Location: ../login.php");
    exit();
}

// Helper function to sync order status from orders table to admin_orders table
// This ensures admin orders reflect supplier-side status changes
function syncOrderStatusToAdminOrders($db, $order_id, $new_status) {
    try {
        // Get order details from orders table to match with admin_orders
        $orderStmt = $db->prepare("SELECT inventory_id, supplier_id, quantity, variation, unit_type, order_date, confirmation_date 
                                   FROM orders WHERE id = ? LIMIT 1");
        $orderStmt->execute([$order_id]);
        $orderData = $orderStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$orderData) {
            error_log("syncOrderStatusToAdminOrders: Order #{$order_id} not found in orders table");
            return false;
        }
        
        // Find matching order in admin_orders based on key fields
        // Match by inventory_id, supplier_id, quantity, variation, and similar order_date
        $matchStmt = $db->prepare("SELECT id FROM admin_orders 
                                    WHERE inventory_id = ? 
                                      AND supplier_id = ? 
                                      AND quantity = ? 
                                      AND (variation = ? OR (variation IS NULL AND ? IS NULL))
                                      AND ABS(TIMESTAMPDIFF(MINUTE, order_date, ?)) <= 5
                                    ORDER BY ABS(TIMESTAMPDIFF(MINUTE, order_date, ?)) ASC
                                    LIMIT 1");
        $matchStmt->execute([
            $orderData['inventory_id'],
            $orderData['supplier_id'],
            $orderData['quantity'],
            $orderData['variation'],
            $orderData['variation'],
            $orderData['order_date'],
            $orderData['order_date']
        ]);
        $adminOrderMatch = $matchStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($adminOrderMatch) {
            // Update admin_orders status to match orders table
            $adminOrderId = (int)$adminOrderMatch['id'];
            $updateStmt = $db->prepare("UPDATE admin_orders 
                                       SET confirmation_status = ?, 
                                           confirmation_date = ? 
                                       WHERE id = ?");
            $updateStmt->execute([
                $new_status,
                $orderData['confirmation_date'],
                $adminOrderId
            ]);
            
            error_log("Synced order status to admin_orders: Order #{$order_id} (orders) -> Admin Order #{$adminOrderId} (admin_orders), Status: {$new_status}");
            return true;
        } else {
            // No exact match found - try to find by ID if they match
            $directMatchStmt = $db->prepare("SELECT id FROM admin_orders WHERE id = ? LIMIT 1");
            $directMatchStmt->execute([$order_id]);
            $directMatch = $directMatchStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($directMatch) {
                // IDs match - direct update
                $updateStmt = $db->prepare("UPDATE admin_orders 
                                           SET confirmation_status = ?, 
                                               confirmation_date = ? 
                                           WHERE id = ?");
                $updateStmt->execute([
                    $new_status,
                    $orderData['confirmation_date'],
                    $order_id
                ]);
                error_log("Synced order status to admin_orders by ID: Order #{$order_id}, Status: {$new_status}");
                return true;
            } else {
                error_log("syncOrderStatusToAdminOrders: No matching admin order found for order #{$order_id}");
                return false;
            }
        }
    } catch (Exception $e) {
        error_log("syncOrderStatusToAdminOrders error for order #{$order_id}: " . $e->getMessage());
        return false;
    }
}

// ====== Helper function for variation display ======
// Format variation for display: "Color:Red|Size:Small" -> "Red Small" (combine values only)
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
    return implode(' ', $values);
}

$database = new Database();
$db = $database->getConnection();
$order = new Order($db);
$delivery = new Delivery($db);
$payment = new Payment($db);

// Get supplier information
$supplier_id = $_SESSION['user_id'];
$supplier_name = $_SESSION['username'];

// Create PDO connection for sidebar compatibility
$pdo = $db;

// Handle export request (GET or POST)
if (isset($_GET['action']) && $_GET['action'] === 'export_deliveries') {
    // Export deliveries data as CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="supplier_deliveries_' . date('Y-m-d') . '.csv"');
    
    // Add BOM for UTF-8 Excel compatibility
    echo "\xEF\xBB\xBF";
    
    $output = fopen('php://output', 'w');
    
    // Header information
    fputcsv($output, ['Supplier Delivery Management Export']);
    fputcsv($output, ['Supplier: ' . $supplier_name]);
    fputcsv($output, ['Export Date: ' . date('Y-m-d H:i:s')]);
    fputcsv($output, []); // Empty row
    
    // Get orders for export
    $exportStmt = $order->readBySupplier($supplier_id);
    $exportOrders = [];
    while ($row = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
        // Map workflow status
        $workflow_status = '';
        switch ($row['confirmation_status']) {
            case 'pending':
                $workflow_status = 'to_pay';
                break;
            case 'confirmed':
                $delivery_check = $db->prepare("SELECT status FROM deliveries WHERE order_id = ?");
                $delivery_check->execute([$row['id']]);
                $delivery_row = $delivery_check->fetch(PDO::FETCH_ASSOC);
                
                if (!$delivery_row) {
                    $workflow_status = 'to_ship';
                } else {
                    switch ($delivery_row['status']) {
                        case 'pending':
                            $workflow_status = 'to_ship';
                            break;
                        case 'in_transit':
                            $workflow_status = 'to_receive';
                            break;
                        case 'delivered':
                            $workflow_status = 'completed';
                            break;
                    }
                }
                break;
            case 'delivered':
                $workflow_status = 'completed';
                break;
            case 'completed':
                $workflow_status = 'completed';
                break;
            case 'cancelled':
                $workflow_status = 'cancelled';
                break;
            default:
                $workflow_status = $row['confirmation_status'];
        }
        $row['workflow_status'] = $workflow_status;
        $exportOrders[] = $row;
    }
    
    // Calculate statistics
    $total_orders = count($exportOrders);
    $to_pay = count(array_filter($exportOrders, function($o) { return $o['workflow_status'] === 'to_pay'; }));
    $to_ship = count(array_filter($exportOrders, function($o) { return $o['workflow_status'] === 'to_ship'; }));
    $to_receive = count(array_filter($exportOrders, function($o) { return $o['workflow_status'] === 'to_receive'; }));
    $completed = count(array_filter($exportOrders, function($o) { return $o['workflow_status'] === 'completed'; }));
    $cancelled = count(array_filter($exportOrders, function($o) { return $o['workflow_status'] === 'cancelled'; }));
    $total_revenue = array_sum(array_map(function($o) {
        return $o['workflow_status'] === 'completed' ? $o['quantity'] * ($o['unit_price'] ?? 0) : 0;
    }, $exportOrders));
    
    // Statistics section
    fputcsv($output, ['Statistics']);
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Total Orders', $total_orders]);
    fputcsv($output, ['To Pay', $to_pay]);
    fputcsv($output, ['To Ship', $to_ship]);
    fputcsv($output, ['To Receive', $to_receive]);
    fputcsv($output, ['Completed', $completed]);
    fputcsv($output, ['Cancelled', $cancelled]);
    fputcsv($output, ['Total Revenue', '₱' . number_format($total_revenue, 2)]);
    fputcsv($output, []); // Empty row
    
    // Orders section
    fputcsv($output, ['Orders']);
    fputcsv($output, ['Order ID', 'Item', 'Variation', 'Quantity', 'Unit Type', 'Unit Price', 'Total', 'Status', 'Order Date']);
    
    foreach ($exportOrders as $order_item) {
        $variation = !empty($order_item['variation']) ? formatVariationForDisplay($order_item['variation']) : 'N/A';
        $total = ($order_item['quantity'] ?? 0) * ($order_item['unit_price'] ?? 0);
        
        fputcsv($output, [
            $order_item['id'] ?? '',
            $order_item['inventory_name'] ?? 'N/A',
            $variation,
            $order_item['quantity'] ?? 0,
            $order_item['unit_type'] ?? 'N/A',
            number_format($order_item['unit_price'] ?? 0, 2),
            number_format($total, 2),
            ucfirst(str_replace('_', ' ', $order_item['workflow_status'] ?? 'N/A')),
            $order_item['order_date'] ? date('Y-m-d', strtotime($order_item['order_date'])) : 'N/A'
        ]);
    }
    
    fclose($output);
    exit();
}

// Handle AJAX requests for status updates
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'update_order_status':
            $order_id = (int)$_POST['order_id'];
            $new_status = $_POST['status'];
            
            // Validate status whitelist
            $allowedOrderStatuses = ['pending','confirmed','cancelled','received','completed','delivered'];
            if (!in_array($new_status, $allowedOrderStatuses, true)) {
                echo json_encode(['success' => false, 'message' => 'Invalid order status']);
                exit();
            }
            // Ensure supplier owns the order
            $ownStmt = $db->prepare("SELECT supplier_id FROM orders WHERE id = ?");
            $ownStmt->execute([$order_id]);
            $ownRow = $ownStmt->fetch(PDO::FETCH_ASSOC);
            if (!$ownRow || (int)$ownRow['supplier_id'] !== (int)$supplier_id) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized: Order does not belong to supplier']);
                exit();
            }
            
            // Prevent cancelling after delivery/completion
            if ($new_status === 'cancelled') {
                $stmt = $db->prepare("SELECT confirmation_status FROM orders WHERE id = ?");
                $stmt->execute([$order_id]);
                $current = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$current) {
                    echo json_encode(['success' => false, 'message' => 'Order not found']);
                    exit();
                }
                if (in_array($current['confirmation_status'], ['completed', 'delivered'], true)) {
                    // Audit blocked attempt
                    $log = $db->prepare("INSERT INTO sync_events(entity_type, entity_id, action, status, message, actor_id) VALUES('order', ?, 'cancel_attempt_blocked', 'blocked', 'Supplier cancellation blocked: completed/delivered', ?)");
                    $log->execute([$order_id, $supplier_id]);
                    echo json_encode(['success' => false, 'message' => 'Cannot cancel a completed or delivered order']);
                    exit();
                }
                $agg = $db->prepare("SELECT COALESCE(SUM(CASE WHEN status='delivered' THEN COALESCE(replenished_quantity,0) ELSE 0 END),0) AS delivered_qty FROM deliveries WHERE order_id = ?");
                $agg->execute([$order_id]);
                $deliveredQty = (int)($agg->fetchColumn() ?? 0);
                if ($deliveredQty > 0) {
                    $log = $db->prepare("INSERT INTO sync_events(entity_type, entity_id, action, status, message, actor_id) VALUES('order', ?, 'cancel_attempt_blocked', 'blocked', 'Supplier cancellation prevented due to delivered items', ?)");
                    $log->execute([$order_id, $supplier_id]);
                    echo json_encode(['success' => false, 'message' => 'Cannot cancel order after successful delivery']);
                    exit();
                }
            }
            
            $order->id = $order_id;
            $order->confirmation_status = $new_status;
            if ($order->updateStatus()) {
                // SYNC: Update admin_orders table to match orders table status
                // This ensures admin orders reflect supplier-side changes
                syncOrderStatusToAdminOrders($db, $order_id, $new_status);
                
                // Create notification for admin about status change
                $status_display = ucfirst(str_replace('_', ' ', $new_status));
                $notification_message = "Order #" . $order_id . " status changed to: " . $status_display . " by supplier " . $supplier_name;
                
                // Send notification to admin
                $stmt = $db->prepare("INSERT INTO notifications (type, channel, recipient_type, recipient_id, order_id, message, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $type = 'order_status_update';
                $channel = 'system';
                $recipient_type = 'management';
                $recipient_id = 1; // Admin user ID
                $notification_status = 'sent';
                $stmt->execute([$type, $channel, $recipient_type, $recipient_id, $order_id, $notification_message, $notification_status]);
                
                echo json_encode(['success' => true, 'message' => 'Order status updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update order status']);
            }
            exit();
            
        case 'create_delivery':
            $delivery->order_id = $_POST['order_id'];
            $delivery->status = 'in_transit';
            $delivery->delivery_date = date('Y-m-d H:i:s');

            // Permission: supplier must own the order and order must be confirmed
            $ownStmt = $db->prepare("SELECT supplier_id, confirmation_status FROM orders WHERE id = ?");
            $ownStmt->execute([$_POST['order_id']]);
            $ownRow = $ownStmt->fetch(PDO::FETCH_ASSOC);
            if (!$ownRow || (int)$ownRow['supplier_id'] !== (int)$supplier_id) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized: Order does not belong to supplier']);
                exit();
            }
            if ($ownRow['confirmation_status'] !== 'confirmed') {
                echo json_encode(['success' => false, 'message' => 'Order must be confirmed before shipping']);
                exit();
            }

            if ($delivery->create()) {
                // SYNC: Delivery created - this may affect order status in admin_orders
                // The delivery status will be synced when admin/orders.php checks delivery statuses
                // For now, we just ensure the delivery exists for admin tracking
                
                // Create notification for admin about delivery creation (To Ship status)
                $notification_message = "Order #" . $_POST['order_id'] . " is ready to ship - delivery created by supplier " . $supplier_name;
                
                // Send notification to admin
                $stmt = $db->prepare("INSERT INTO notifications (type, channel, recipient_type, recipient_id, order_id, message, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $type = 'delivery_created';
                $channel = 'system';
                $recipient_type = 'management';
                $recipient_id = 1; // Admin user ID
                $notification_status = 'sent';
                $stmt->execute([$type, $channel, $recipient_type, $recipient_id, $_POST['order_id'], $notification_message, $notification_status]);

                // Audit log
                try {
                    $log = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message) VALUES (?,?,?,?,?,?,?,?,?,?)");
                    $log->execute([
                        'delivery_created',
                        'supplier_ui',
                        'admin_system',
                        (int)$_POST['order_id'],
                        null,
                        $ownRow['confirmation_status'],
                        $ownRow['confirmation_status'],
                        1,
                        'Delivery record created by supplier'
                    ]);
                } catch (Throwable $e) {}
                
                echo json_encode(['success' => true, 'message' => 'Delivery created successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to create delivery']);
            }
            exit();
            
            /* Delivery status updates removed: UI no longer changes delivery states. */
            
        case 'mark_order_received':
            $order->id = $_POST['order_id'];
            $order->confirmation_status = 'received';
            $order->confirmation_date = date('Y-m-d H:i:s');
            
            // Permission check: supplier must own the order
            $ownStmt = $db->prepare("SELECT supplier_id FROM orders WHERE id = ?");
            $ownStmt->execute([$_POST['order_id']]);
            $ownRow = $ownStmt->fetch(PDO::FETCH_ASSOC);
            if (!$ownRow || (int)$ownRow['supplier_id'] !== (int)$supplier_id) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized: Order does not belong to supplier']);
                exit();
            }
            
            if ($order->updateStatus()) {
                // SYNC: Update admin_orders table to match orders table status
                syncOrderStatusToAdminOrders($db, $_POST['order_id'], 'received');
                
                // Create notification for admin about order received
                $notification_message = "Order #" . $_POST['order_id'] . " has been received by supplier " . $supplier_name;
                $stmt = $db->prepare("INSERT INTO notifications (type, channel, recipient_type, recipient_id, order_id, message, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $type = 'order_received';
                $channel = 'system';
                $recipient_type = 'management';
                $recipient_id = 1; // Admin user ID
                $status = 'sent';
                $stmt->execute([$type, $channel, $recipient_type, $recipient_id, $_POST['order_id'], $notification_message, $status]);
                
                echo json_encode(['success' => true, 'message' => 'Order marked as received successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to mark order as received']);
            }
            exit();
            
        case 'mark_order_completed':
            $order->id = $_POST['order_id'];
            $order->confirmation_status = 'completed';
            $order->confirmation_date = date('Y-m-d H:i:s');
            
            // Permission and transactional update: ensure supplier owns order; also update deliveries to completed
            try {
                $db->beginTransaction();
                $ownStmt = $db->prepare("SELECT supplier_id, confirmation_status FROM orders WHERE id = ? FOR UPDATE");
                $ownStmt->execute([$_POST['order_id']]);
                $ownRow = $ownStmt->fetch(PDO::FETCH_ASSOC);
                if (!$ownRow || (int)$ownRow['supplier_id'] !== (int)$supplier_id) {
                    $db->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Unauthorized: Order does not belong to supplier']);
                    exit();
                }
                $statusBefore = $ownRow['confirmation_status'];
                
                $updatedOrder = $order->updateStatus();
                if ($updatedOrder) {
                    // Normalize deliveries to completed for this order
                    $updDel = $db->prepare("UPDATE deliveries SET status='completed' WHERE order_id=? AND status IN ('pending','in_transit','delivered')");
                    $updDel->execute([$_POST['order_id']]);
                    
                    // SYNC: Update admin_orders table to match orders table status
                    // This ensures admin orders reflect the completion
                    syncOrderStatusToAdminOrders($db, $_POST['order_id'], 'completed');
                    
                    $db->commit();
                    
                    // Notification
                    $notification_message = "Order #" . $_POST['order_id'] . " has been completed by supplier " . $supplier_name;
                    $stmt = $db->prepare("INSERT INTO notifications (type, channel, recipient_type, recipient_id, order_id, message, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $type = 'order_completed';
                    $channel = 'system';
                    $recipient_type = 'management';
                    $recipient_id = 1; // Admin user ID
                    $status = 'sent';
                    $stmt->execute([$type, $channel, $recipient_type, $recipient_id, $_POST['order_id'], $notification_message, $status]);
                    
                    // Audit logging
                    try {
                        $log = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message) VALUES (?,?,?,?,?,?,?,?,?,?)");
                        $log->execute([
                            'order_completed',
                            'supplier_ui',
                            'admin_system',
                            (int)$_POST['order_id'],
                            null,
                            $statusBefore,
                            'completed',
                            1,
                            'Order completed and deliveries normalized to completed'
                        ]);
                    } catch (Throwable $e) {}
                    
                    echo json_encode(['success' => true, 'message' => 'Order marked as completed successfully']);
                } else {
                    $db->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Failed to mark order as completed']);
                }
            } catch (Throwable $e) {
                try {
                    $db->rollBack();
                } catch (Throwable $ie) {}
                // Best-effort audit log
                try {
                    $log = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message) VALUES (?,?,?,?,?,?,?,?,?,?)");
                    $log->execute([
                        'order_completed_error',
                        'supplier_ui',
                        'admin_system',
                        (int)$_POST['order_id'],
                        null,
                        null,
                        null,
                        0,
                        'Completion failed: ' . $e->getMessage()
                    ]);
                } catch (Throwable $ie) {}
                echo json_encode(['success' => false, 'message' => 'Error completing order']);
            }
            exit();
            
        case 'transition_to_view':
            // Auto-complete order when transitioning to View state
            $order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;

            try {
                $db->beginTransaction();

                // Verify ownership and fetch current status under lock
                $ownStmt = $db->prepare("SELECT supplier_id, confirmation_status FROM orders WHERE id = ? FOR UPDATE");
                $ownStmt->execute([$order_id]);
                $ownRow = $ownStmt->fetch(PDO::FETCH_ASSOC);

                if (!$ownRow) {
                    $db->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Order not found']);
                    exit();
                }

                if ((int)$ownRow['supplier_id'] !== (int)$supplier_id) {
                    $db->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Permission denied for this order']);
                    exit();
                }

                $statusBefore = $ownRow['confirmation_status'];
                $statusAfter = 'completed';

                if (in_array($statusBefore, ['cancelled', 'completed'], true)) {
                    // Commit transaction early (no status change), then best-effort log
                    $db->commit();
                    try {
                        $logStmt = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $logStmt->execute([
                            'view_state_completion_attempt',
                            'supplier_ui',
                            'admin_system',
                            $order_id,
                            null,
                            $statusBefore,
                            $statusAfter,
                            0,
                            'No-op: order already ' . $statusBefore
                        ]);
                    } catch (Throwable $logErr) { /* logging failure is non-fatal */ }

                    echo json_encode(['success' => false, 'message' => 'Order is already ' . $statusBefore]);
                    exit();
                }

                // Update to completed
                $updStmt = $db->prepare("UPDATE orders SET confirmation_status = 'completed', confirmation_date = NOW() WHERE id = ? AND confirmation_status NOT IN ('completed', 'cancelled')");
                $updStmt->execute([$order_id]);
                $updated = $updStmt->rowCount() > 0;

                // SYNC: Update admin_orders table to match orders table status
                if ($updated) {
                    syncOrderStatusToAdminOrders($db, $order_id, 'completed');
                }

                // Commit status change first; logging is best-effort and non-fatal
                $db->commit();

                // Log the transition outside the transaction
                try {
                    $logStmt = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $logStmt->execute([
                        'view_transition_complete',
                        'supplier_ui',
                        'admin_system',
                        $order_id,
                        null,
                        $statusBefore,
                        $statusAfter,
                        $updated ? 1 : 0,
                        $updated ? 'Auto-completed via view transition' : 'No change: status update constrained'
                    ]);
                } catch (Throwable $logErr) { /* logging failure is non-fatal */ }

                echo json_encode(['success' => $updated, 'message' => $updated ? 'Order auto-completed on view' : 'Order status unchanged']);
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                // Best-effort log of failure; avoid throwing if table/logging fails
                try {
                    $logStmt = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $logStmt->execute([
                        'view_transition_error',
                        'supplier_ui',
                        'admin_system',
                        $order_id,
                        null,
                        null,
                        null,
                        0,
                        'System error: ' . $e->getMessage()
                    ]);
                } catch (Throwable $ignore) { /* swallow logging failure */ }

                echo json_encode(['success' => false, 'message' => 'System error during view transition']);
            }
            exit();
            
        case 'get_notifications':
            // Get recent notifications for this supplier
            $stmt = $db->prepare("SELECT * FROM notifications WHERE recipient_type = 'supplier' AND recipient_id = ? ORDER BY id DESC LIMIT 10");
            $stmt->execute([$supplier_id]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'notifications' => $notifications]);
            exit();
            
        case 'mark_notification_read':
            $notification_id = (int)($_POST['notification_id'] ?? 0);
            $stmt = $db->prepare("UPDATE notifications SET status = 'read', is_read = 1, read_at = NOW() WHERE id = ? AND recipient_type = 'supplier' AND recipient_id = ?");
            $success = $stmt->execute([$notification_id, $supplier_id]);
            
            echo json_encode(['success' => $success]);
            exit();
            
        case 'delete_notification':
            $notification_id = (int)($_POST['notification_id'] ?? 0);
            
            // Verify ownership
            $checkStmt = $db->prepare("SELECT id FROM notifications WHERE id = ? AND recipient_type = 'supplier' AND recipient_id = ?");
            $checkStmt->execute([$notification_id, $supplier_id]);
            if (!$checkStmt->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Notification not found or unauthorized']);
                exit();
            }
            
            $stmt = $db->prepare("DELETE FROM notifications WHERE id = ? AND recipient_type = 'supplier' AND recipient_id = ?");
            $success = $stmt->execute([$notification_id, $supplier_id]);
            
            echo json_encode(['success' => $success, 'message' => $success ? 'Notification deleted successfully' : 'Failed to delete notification']);
            exit();
            
        case 'get_notification_details':
            $notification_id = (int)($_POST['notification_id'] ?? $_GET['notification_id'] ?? 0);
            
            $stmt = $db->prepare("SELECT * FROM notifications WHERE id = ? AND recipient_type = 'supplier' AND recipient_id = ?");
            $stmt->execute([$notification_id, $supplier_id]);
            $notification = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($notification) {
                // Mark as read if unread
                if (empty($notification['is_read']) || $notification['status'] !== 'read') {
                    $updateStmt = $db->prepare("UPDATE notifications SET status = 'read', is_read = 1, read_at = NOW() WHERE id = ?");
                    $updateStmt->execute([$notification_id]);
                }
                echo json_encode(['success' => true, 'notification' => $notification]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Notification not found']);
            }
            exit();
    }
}

// Get orders for this supplier with enhanced status mapping
$stmt = $order->readBySupplier($supplier_id);
$orders = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Map order status to Shopee-like workflow
    $workflow_status = '';
    switch ($row['confirmation_status']) {
        case 'pending':
            $workflow_status = 'to_pay';
            break;
        case 'confirmed':
            // Check if delivery exists
            $delivery_check = $db->prepare("SELECT status FROM deliveries WHERE order_id = ?");
            $delivery_check->execute([$row['id']]);
            $delivery_row = $delivery_check->fetch(PDO::FETCH_ASSOC);
            
            if (!$delivery_row) {
                $workflow_status = 'to_ship';
            } else {
                switch ($delivery_row['status']) {
                    case 'pending':
                        $workflow_status = 'to_ship';
                        break;
                    case 'in_transit':
                        $workflow_status = 'to_receive';
                        break;
                    case 'delivered':
                        $workflow_status = 'completed';
                        break;
                }
            }
            break;
        case 'delivered':
            // If order confirmation status itself is 'delivered', treat as completed in workflow
            $workflow_status = 'completed';
            break;
        case 'completed':
            $workflow_status = 'completed';
            break;
        case 'cancelled':
            $workflow_status = 'cancelled';
            break;
    }
    
    $row['workflow_status'] = $workflow_status;
    $orders[] = $row;
}

// Get deliveries for this supplier
$stmt = $delivery->readBySupplier($supplier_id);
$deliveries = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $deliveries[] = $row;
}

// Calculate statistics
$total_orders = count($orders);
$to_pay = count(array_filter($orders, function($o) { return $o['workflow_status'] === 'to_pay'; }));
$to_ship = count(array_filter($orders, function($o) { return $o['workflow_status'] === 'to_ship'; }));
$to_receive = count(array_filter($orders, function($o) { return $o['workflow_status'] === 'to_receive'; }));
$completed = count(array_filter($orders, function($o) { return $o['workflow_status'] === 'completed'; }));
$cancelled = count(array_filter($orders, function($o) { return $o['workflow_status'] === 'cancelled'; }));

// Calculate total revenue from completed orders
$total_revenue = array_sum(array_map(function($o) {
    return $o['workflow_status'] === 'completed' ? $o['quantity'] * ($o['unit_price'] ?? 0) : 0;
}, $orders));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Management - <?php echo htmlspecialchars($supplier_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <style>
        .sidebar {
            min-height: 100vh;
        }
        .nav-link {
            color: #212529;
            transition: all 0.3s;
        }
        .nav-link:hover, .nav-link.active {
            color: #0d6efd;
            background-color: rgba(13, 110, 253, 0.08);
            border-radius: 5px;
        }
        .card {
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            transition: transform 0.2s;
        }
        .card:hover {
            transform: translateY(-2px);
        }
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.875rem;
        }
        .status-to_pay { background: #fff3cd; color: #856404; }
        .status-to_ship { background: #cce5ff; color: #004085; }
        .status-to_receive { background: #e2e3e5; color: #383d41; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        
        .workflow-tabs .nav-link {
            color: #495057;
            border: 1px solid #dee2e6;
            margin-right: 5px;
            border-radius: 10px 10px 0 0;
        }
        .workflow-tabs .nav-link.active {
            background: white;
            border-bottom-color: white;
        }
        
        .action-buttons .btn {
            margin: 2px;
        }
        
        .real-time-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #28a745;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        /* Alignment tweaks */
        .table th, .table td { vertical-align: middle; }
        
        /* Workflow tabs icon/text alignment */
        .workflow-tabs .nav-link { display: flex; align-items: center; gap: 6px; }
        
        /* All Orders table */
        #allOrdersTable th:nth-child(5), #allOrdersTable td:nth-child(5) { text-align: center; }
        #allOrdersTable th:nth-child(6), #allOrdersTable td:nth-child(6) { text-align: right; }
        #allOrdersTable th:nth-child(7), #allOrdersTable td:nth-child(7) { text-align: right; }
        #allOrdersTable th:nth-child(8), #allOrdersTable td:nth-child(8) { text-align: center; }
        #allOrdersTable th:nth-child(10), #allOrdersTable td:nth-child(10) { text-align: center; }
        
        /* To Pay table */
        #toPayTable th:nth-child(3), #toPayTable td:nth-child(3) { text-align: center; }
        #toPayTable th:nth-child(4), #toPayTable td:nth-child(4) { text-align: right; }
        #toPayTable th:nth-child(6), #toPayTable td:nth-child(6) { text-align: center; }
        
        /* To Ship table */
        #toShipTable th:nth-child(5), #toShipTable td:nth-child(5) { text-align: center; }
        #toShipTable th:nth-child(6), #toShipTable td:nth-child(6) { text-align: right; }
        #toShipTable th:nth-child(9), #toShipTable td:nth-child(9) { text-align: center; }
        
        /* To Receive table */
        #toReceiveTable th:nth-child(5), #toReceiveTable td:nth-child(5) { text-align: center; }
        #toReceiveTable th:nth-child(6), #toReceiveTable td:nth-child(6) { text-align: right; }
        #toReceiveTable th:nth-child(8), #toReceiveTable td:nth-child(8) { text-align: center; }
        #toReceiveTable th:nth-child(9), #toReceiveTable td:nth-child(9) { text-align: center; }
        
        /* Completed table */
        #completedTable th:nth-child(5), #completedTable td:nth-child(5) { text-align: center; }
        #completedTable th:nth-child(6), #completedTable td:nth-child(6) { text-align: right; }
        #completedTable th:nth-child(8), #completedTable td:nth-child(8) { text-align: center; }
        
        /* Cancelled table */
        #cancelledTable th:nth-child(5), #cancelledTable td:nth-child(5) { text-align: center; }
        #cancelledTable th:nth-child(6), #cancelledTable td:nth-child(6) { text-align: right; }
        #cancelledTable th:nth-child(9), #cancelledTable td:nth-child(9) { text-align: center; }

        /* Stats cards design */
        .stats-card .card-body { 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            justify-content: center; 
            min-height: 120px;
            padding-top: 16px;
            padding-bottom: 16px;
        }
        .stats-card .label { 
            font-size: 0.875rem; 
            font-weight: 600; 
            opacity: 0.95;
        }
        .stats-card .value { 
            font-size: clamp(0.95rem, 1.8vw, 1.25rem); 
            font-weight: 700; 
            line-height: 1.1; 
            text-align: center;
            max-width: 100%;
            white-space: nowrap;
        }
    </style>
</head>
<body>
<?php include_once 'includes/header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include_once 'includes/sidebar.php'; ?>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-2">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-1 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="bi bi-truck me-2"></i>Delivery Management
                        <span class="real-time-indicator ms-2"></span>
                        
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="dropdown me-2">
                            <button class="btn btn-outline-secondary position-relative" type="button" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-bell"></i>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="notificationBadge" style="display: none;">
                                    0
                                </span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationDropdown" style="width: 300px; max-height: 400px; overflow-y: auto;">
                                <li><h6 class="dropdown-header">Recent Notifications</h6></li>
                                <div id="notificationList">
                                    <li><span class="dropdown-item-text text-muted">No new notifications</span></li>
                                </div>
                            </ul>
                        </div>
                        <button type="button" class="btn btn-primary me-2" onclick="refreshData()">
                            <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="exportData()">
                            <i class="bi bi-download me-1"></i>Export
                        </button>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-2">
                        <div class="card stats-card text-white bg-warning h-100">
                            <div class="card-body text-center">
                                <div class="label">To Pay</div>
                                <div class="value"><?php echo $to_pay; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="card stats-card text-white bg-info h-100">
                            <div class="card-body text-center">
                                <div class="label">To Ship</div>
                                <div class="value"><?php echo $to_ship; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="card stats-card text-white bg-secondary h-100">
                            <div class="card-body text-center">
                                <div class="label">To Receive</div>
                                <div class="value"><?php echo $to_receive; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="card stats-card text-white bg-success h-100">
                            <div class="card-body text-center">
                                <div class="label">Completed</div>
                                <div class="value"><?php echo $completed; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="card stats-card text-white bg-danger h-100">
                            <div class="card-body text-center">
                                <div class="label">Cancelled</div>
                                <div class="value"><?php echo $cancelled; ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="card stats-card text-white bg-dark h-100">
                            <div class="card-body text-center">
                                <div class="label">Revenue</div>
                                <div class="value">₱<?php echo number_format($total_revenue, 2); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Workflow Tabs -->
                <ul class="nav nav-tabs workflow-tabs mb-4" id="workflowTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="all-tab" data-bs-toggle="tab" data-bs-target="#all" type="button" role="tab">
                            <i class="bi bi-list me-2"></i>All Orders (<?php echo $total_orders; ?>)
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="to-pay-tab" data-bs-toggle="tab" data-bs-target="#to-pay" type="button" role="tab">
                            <i class="bi bi-credit-card me-2"></i>To Pay (<?php echo $to_pay; ?>)
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="to-ship-tab" data-bs-toggle="tab" data-bs-target="#to-ship" type="button" role="tab">
                            <i class="bi bi-box-seam me-2"></i>To Ship (<?php echo $to_ship; ?>)
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="to-receive-tab" data-bs-toggle="tab" data-bs-target="#to-receive" type="button" role="tab">
                            <i class="bi bi-truck me-2"></i>To Receive (<?php echo $to_receive; ?>)
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="completed-tab" data-bs-toggle="tab" data-bs-target="#completed" type="button" role="tab">
                            <i class="bi bi-check-circle me-2"></i>Completed (<?php echo $completed; ?>)
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="cancelled-tab" data-bs-toggle="tab" data-bs-target="#cancelled" type="button" role="tab">
                            <i class="bi bi-x-circle me-2"></i>Cancelled (<?php echo $cancelled; ?>)
                        </button>
                    </li>
                </ul>

<?php if (isset($_GET['error'])): 
    $error = $_GET['error'];
    $errorMap = [
        'order_not_found' => 'Order not found or you do not have access.',
        'order_read_failed' => 'We could not retrieve order details. Please try again.',
        'delivery_read_failed' => 'We could not retrieve delivery details. Please try again.'
    ];
    $errorMsg = $errorMap[$error] ?? 'Unable to open order details at this time.';
?>
<div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($errorMsg); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

                <div class="tab-content" id="workflowTabsContent">
                    <!-- All Orders Tab -->
                    <div class="tab-pane fade show active" id="all" role="tabpanel">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-list me-2"></i>All Orders
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover" id="allOrdersTable">
                                        <thead>
                                            <tr>
                                                <th>Order ID</th>
                                                <th>Item</th>
                                                <th>Variation & Quantity</th>
                                                <th>Per Unit</th>
                                                <th>Total</th>
                                                <th>Status</th>
                                                <th>Order Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($orders)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-4">
                                                    <div class="text-muted">
                                                        <i class="bi bi-inbox" style="font-size: 2rem;"></i>
                                                        <p class="mt-2 mb-0">No orders available</p>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($orders as $order_item): ?>
                                            <tr>
                                                <td><strong>#<?php echo $order_item['id']; ?></strong></td>
                                                <td><?php echo htmlspecialchars($order_item['inventory_name'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <?php if (!empty($order_item['variation'])): ?>
                                                            <span class="badge bg-info mb-1"><?= htmlspecialchars(formatVariationForDisplay($order_item['variation'])) ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted">No Variation</span>
                                                        <?php endif; ?>
                                                        <div class="mt-1">
                                                            <span class="badge bg-primary">Qty Ordered: <strong><?= $order_item['quantity'] ?></strong></span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($order_item['unit_type'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <strong class="text-success fs-6">₱<?php echo number_format($order_item['quantity'] * ($order_item['unit_price'] ?? 0), 2); ?></strong>
                                                        <small class="text-muted">(₱<?php echo number_format($order_item['unit_price'] ?? 0, 2); ?> × <?= $order_item['quantity'] ?>)</small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $order_item['workflow_status']; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $order_item['workflow_status'])); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($order_item['order_date'])); ?></td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <?php if ($order_item['workflow_status'] === 'to_pay'): ?>
                                                        <button class="btn btn-success btn-sm" onclick="confirmPayment(<?php echo $order_item['id']; ?>)">
                                                            <i class="bi bi-check"></i> Confirm Payment
                                                        </button>
                                                        <button class="btn btn-danger btn-sm" onclick="cancelOrder(<?php echo $order_item['id']; ?>)">
                                                            <i class="bi bi-x"></i> Cancel
                                                        </button>
                                                        <?php elseif ($order_item['workflow_status'] === 'to_ship'): ?>
                                                        <button class="btn btn-info btn-sm" onclick="shipOrder(<?php echo $order_item['id']; ?>)" data-order-id="<?php echo $order_item['id']; ?>">
                                                            <i class="bi bi-truck"></i> Ship Order
                                                        </button>
                                                        <button class="btn btn-danger btn-sm" onclick="cancelOrder(<?php echo $order_item['id']; ?>)">
                                                            <i class="bi bi-x"></i> Cancel
                                                        </button>
                                                        <button class="btn btn-success btn-sm mark-completed-btn" style="display:none" onclick="markOrderCompleted(<?php echo $order_item['id']; ?>)" data-order-id="<?php echo $order_item['id']; ?>">
                                                            <i class="bi bi-check-all"></i> Mark Completed
                                                        </button>
                                                        <?php elseif ($order_item['workflow_status'] === 'to_receive'): ?>
                                                        <button class="btn btn-success btn-sm" onclick="markOrderCompleted(<?php echo $order_item['id']; ?>)">
                                                            <i class="bi bi-check-all"></i> Mark Completed
                                                        </button>
                                                        <?php endif; ?>
                                                        <?php if ($order_item['workflow_status'] === 'completed'): ?>
                                                        <button class="btn btn-outline-primary btn-sm view-order-btn" data-order-id="<?php echo $order_item['id']; ?>" onclick="viewOrderDetails(<?php echo $order_item['id']; ?>, this)">
                                                            <i class="bi bi-eye"></i> View
                                                        </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- To Pay Tab -->
                    <div class="tab-pane fade" id="to-pay" role="tabpanel">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-credit-card me-2"></i>Orders Awaiting Payment Confirmation
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover" id="toPayTable">
                                        <thead>
                                            <tr>
                                                <th>Order ID</th>
                                                <th>Item</th>
                                                <th>Quantity</th>
                                                <th>Total Amount</th>
                                                <th>Order Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $to_pay_orders = array_filter($orders, function($o) { return $o['workflow_status'] === 'to_pay'; });
                                            if (empty($to_pay_orders)): 
                                            ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-4">
                                                    <div class="text-muted">
                                                        <i class="bi bi-credit-card" style="font-size: 2rem;"></i>
                                                        <p class="mt-2 mb-0">No orders awaiting payment</p>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($to_pay_orders as $order_item): ?>
                                            <tr>
                                                <td><strong>#<?php echo $order_item['id']; ?></strong></td>
                                                <td><?php echo htmlspecialchars($order_item['inventory_name'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <span class="badge bg-primary"><?php echo $order_item['quantity']; ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <strong class="text-success fs-6">₱<?php echo number_format($order_item['quantity'] * ($order_item['unit_price'] ?? 0), 2); ?></strong>
                                                        <small class="text-muted">(₱<?php echo number_format($order_item['unit_price'] ?? 0, 2); ?> × <?= $order_item['quantity'] ?>)</small>
                                                    </div>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($order_item['order_date'])); ?></td>
                                                <td>
                                                    <button class="btn btn-success btn-sm" onclick="confirmPayment(<?php echo $order_item['id']; ?>)">
                                                        <i class="bi bi-check"></i> Confirm Payment
                                                    </button>
                                                    <button class="btn btn-danger btn-sm" onclick="cancelOrder(<?php echo $order_item['id']; ?>)">
                                                        <i class="bi bi-x"></i> Cancel
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- To Ship Tab -->
                    <div class="tab-pane fade" id="to-ship" role="tabpanel">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-box-seam me-2"></i>Orders Ready to Ship
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover" id="toShipTable">
                                        <thead>
                                            <tr>
                                                <th>Order ID</th>
                                                <th>Item</th>
                                                <th>Per Unit</th>
                                                <th>Variation</th>
                                                <th>Quantity</th>
                                                <th>Total Amount</th>
                                                <th>Delivery Address</th>
                                                <th>Confirmed Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $to_ship_orders = array_filter($orders, function($o) { return $o['workflow_status'] === 'to_ship'; });
                                            if (empty($to_ship_orders)): 
                                            ?>
                                            <tr>
                                                <td colspan="9" class="text-center py-4">
                                                    <div class="text-muted">
                                                        <i class="bi bi-box-seam" style="font-size: 2rem;"></i>
                                                        <p class="mt-2 mb-0">No orders ready to ship</p>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($to_ship_orders as $order_item): ?>
                                            <tr>
                                                <td><strong>#<?php echo $order_item['id']; ?></strong></td>
                                                <td><?php echo htmlspecialchars($order_item['inventory_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($order_item['unit_type'] ?? 'N/A'); ?></td>
                                                <td><?php echo (isset($order_item['variation']) && $order_item['variation'] !== '') ? htmlspecialchars(formatVariationForDisplay($order_item['variation'])) : 'N/A'; ?></td>
                                                <td>
                                                    <span class="badge bg-primary"><?php echo $order_item['quantity']; ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <strong class="text-success fs-6">₱<?php echo number_format($order_item['quantity'] * ($order_item['unit_price'] ?? 0), 2); ?></strong>
                                                        <small class="text-muted">(₱<?php echo number_format($order_item['unit_price'] ?? 0, 2); ?> × <?= $order_item['quantity'] ?>)</small>
                                                    </div>
                                                </td>
                                                <td>Store Pickup</td>
                                                <td><?php echo $order_item['confirmation_date'] ? date('M j, Y', strtotime($order_item['confirmation_date'])) : 'N/A'; ?></td>
                                                <td>
                                                    <button class="btn btn-info btn-sm" onclick="shipOrder(<?php echo $order_item['id']; ?>)" data-order-id="<?php echo $order_item['id']; ?>">
                                                        <i class="bi bi-truck"></i> Ship Order
                                                    </button>
                                                    <button class="btn btn-danger btn-sm" onclick="cancelOrder(<?php echo $order_item['id']; ?>)">
                                                        <i class="bi bi-x"></i> Cancel
                                                    </button>
                                                    <button class="btn btn-success btn-sm mark-completed-btn" style="display:none" onclick="markOrderCompleted(<?php echo $order_item['id']; ?>)" data-order-id="<?php echo $order_item['id']; ?>">
                                                        <i class="bi bi-check-all"></i> Mark Completed
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- To Receive Tab -->
                    <div class="tab-pane fade" id="to-receive" role="tabpanel">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-truck me-2"></i>Orders in Transit
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover" id="toReceiveTable">
                                        <thead>
                                            <tr>
                                                <th>Order ID</th>
                                                <th>Item</th>
                                                <th>Per Unit</th>
                                                <th>Variation</th>
                                                <th>Quantity</th>
                                                <th>Total Amount</th>
                                                <th>Shipped Date</th>
                                                <th>Tracking</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $to_receive_orders = array_filter($orders, function($o) { return $o['workflow_status'] === 'to_receive'; });
                                            if (empty($to_receive_orders)): 
                                            ?>
                                            <tr>
                                                <td colspan="9" class="text-center py-4">
                                                    <div class="text-muted">
                                                        <i class="bi bi-truck" style="font-size: 2rem;"></i>
                                                        <p class="mt-2 mb-0">No orders in transit</p>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($to_receive_orders as $order_item): ?>
                                            <tr>
                                                <td><strong>#<?php echo $order_item['id']; ?></strong></td>
                                                <td><?php echo htmlspecialchars($order_item['inventory_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($order_item['unit_type'] ?? 'N/A'); ?></td>
                                                <td><?php echo (isset($order_item['variation']) && $order_item['variation'] !== '') ? htmlspecialchars(formatVariationForDisplay($order_item['variation'])) : 'N/A'; ?></td>
                                                <td>
                                                    <span class="badge bg-primary"><?php echo $order_item['quantity']; ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <strong class="text-success fs-6">₱<?php echo number_format($order_item['quantity'] * ($order_item['unit_price'] ?? 0), 2); ?></strong>
                                                        <small class="text-muted">(₱<?php echo number_format($order_item['unit_price'] ?? 0, 2); ?> × <?= $order_item['quantity'] ?>)</small>
                                                    </div>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($order_item['order_date'])); ?></td>
                                                <td><span class="badge bg-info">In Transit</span></td>
                                                <td>
                                                    <button class="btn btn-success btn-sm" onclick="markOrderCompleted(<?php echo $order_item['id']; ?>)">
                                                        <i class="bi bi-check-all"></i> Mark Completed
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Completed Tab -->
                    <div class="tab-pane fade" id="completed" role="tabpanel">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-check-circle me-2"></i>Completed Orders
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover" id="completedTable">
                                        <thead>
                                            <tr>
                                                <th>Order ID</th>
                                                <th>Item</th>
                                                <th>Per Unit</th>
                                                <th>Variation</th>
                                                <th>Quantity</th>
                                                <th>Total Amount</th>
                                                <th>Completed Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $completed_orders = array_filter($orders, function($o) { return $o['workflow_status'] === 'completed'; });
                                            if (empty($completed_orders)): 
                                            ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-4">
                                                    <div class="text-muted">
                                                        <i class="bi bi-check-circle" style="font-size: 2rem;"></i>
                                                        <p class="mt-2 mb-0">No completed orders</p>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($completed_orders as $order_item): ?>
                                            <tr>
                                                <td><strong>#<?php echo $order_item['id']; ?></strong></td>
                                                <td><?php echo htmlspecialchars($order_item['inventory_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($order_item['unit_type'] ?? 'N/A'); ?></td>
                                                <td><?php echo (isset($order_item['variation']) && $order_item['variation'] !== '') ? htmlspecialchars(formatVariationForDisplay($order_item['variation'])) : 'N/A'; ?></td>
                                                <td>
                                                    <span class="badge bg-primary"><?php echo $order_item['quantity']; ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <strong class="text-success fs-6">₱<?php echo number_format($order_item['quantity'] * ($order_item['unit_price'] ?? 0), 2); ?></strong>
                                                        <small class="text-muted">(₱<?php echo number_format($order_item['unit_price'] ?? 0, 2); ?> × <?= $order_item['quantity'] ?>)</small>
                                                    </div>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($order_item['order_date'])); ?></td>
                                                <td></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Cancelled Tab -->
                    <div class="tab-pane fade" id="cancelled" role="tabpanel">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-x-circle me-2"></i>Cancelled Orders
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover" id="cancelledTable">
                                        <thead>
                                            <tr>
                                                <th>Order ID</th>
                                                <th>Item</th>
                                                <th>Per Unit</th>
                                                <th>Variation</th>
                                                <th>Quantity</th>
                                                <th>Total Amount</th>
                                                <th>Order Date</th>
                                                <th>Reason</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $cancelled_orders = array_filter($orders, function($o) { return $o['workflow_status'] === 'cancelled'; });
                                            if (empty($cancelled_orders)): 
                                            ?>
                                            <tr>
                                                <td colspan="9" class="text-center py-4">
                                                    <div class="text-muted">
                                                        <i class="bi bi-x-circle" style="font-size: 2rem;"></i>
                                                        <p class="mt-2 mb-0">No cancelled orders</p>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($cancelled_orders as $order_item): ?>
                                            <tr>
                                                <td><strong>#<?php echo $order_item['id']; ?></strong></td>
                                                <td><?php echo htmlspecialchars($order_item['inventory_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($order_item['unit_type'] ?? 'N/A'); ?></td>
                                                <td><?php echo (isset($order_item['variation']) && $order_item['variation'] !== '') ? htmlspecialchars(formatVariationForDisplay($order_item['variation'])) : 'N/A'; ?></td>
                                                <td>
                                                    <span class="badge bg-primary"><?php echo $order_item['quantity']; ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <strong class="text-success fs-6">₱<?php echo number_format($order_item['quantity'] * ($order_item['unit_price'] ?? 0), 2); ?></strong>
                                                        <small class="text-muted">(₱<?php echo number_format($order_item['unit_price'] ?? 0, 2); ?> × <?= $order_item['quantity'] ?>)</small>
                                                    </div>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($order_item['order_date'])); ?></td>
                                                <td><span class="text-muted">Cancelled by supplier</span></td>
                                                <td>
                                                    <button class="btn btn-outline-primary btn-sm view-order-btn" data-order-id="<?php echo $order_item['id']; ?>" onclick="viewOrderDetails(<?php echo $order_item['id']; ?>, this)">
                                                        <i class="bi bi-eye"></i> View Details
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Initialize DataTables for all tables
            const tables = [
                { id: '#allOrdersTable', orderColumn: 6 },
                { id: '#toPayTable', orderColumn: 4 },
                { id: '#toShipTable', orderColumn: 6 },
                { id: '#toReceiveTable', orderColumn: 5 },
                { id: '#completedTable', orderColumn: 6 },
                { id: '#cancelledTable', orderColumn: 5 }
            ];
            
            tables.forEach(function(table) {
                const $table = $(table.id);
                if ($table.length > 0 && $table.find('tbody tr').length > 0 && !$table.find('tbody tr td[colspan]').length) {
                    $table.DataTable({
                        responsive: true,
                        pageLength: 10,
                        order: [[table.orderColumn, 'desc']],
                        language: {
                            emptyTable: "No orders available",
                            zeroRecords: "No matching orders found"
                        },
                        columnDefs: [
                            { orderable: false, targets: -1 } // Disable ordering on last column (Actions)
                        ]
                    });
                }
            });
            
            // Auto-refresh every 30 seconds
            setInterval(refreshData, 30000);
            
            // Load notifications on page load
            loadNotifications();
            
            // Poll for new notifications every 10 seconds
            setInterval(loadNotifications, 10000);
        });

        function refreshData() {
            location.reload();
        }

        function triggerRealtime() {
            if (window.RealtimeService && typeof window.RealtimeService.pollNow === 'function') {
                window.RealtimeService.pollNow();
            }
        }

        function notifyPostCompletionClick(orderId, actionName) {
            try {
                $.ajax({
                    url: '../api/supplier_notifications.php',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        action: 'notify_post_completion_click',
                        order_id: orderId,
                        action_name: actionName
                    })
                }).done(function(resp){
                    if (resp && resp.success && !resp.ignored) {
                        try { if (typeof triggerRealtime === 'function') triggerRealtime(); } catch (_) {}
                    }
                }).fail(function(){ /* silently ignore to avoid blocking UI */ });
            } catch (e) { /* noop */ }
        }

        function exportData() {
            // Show loading state
            const exportBtn = $('button[onclick="exportData()"]');
            const originalText = exportBtn.html();
            exportBtn.prop('disabled', true).html('<i class="bi bi-hourglass-split me-1"></i>Exporting...');
            
            // Use PHP endpoint for reliable export
            window.location.href = 'deliveries.php?action=export_deliveries';
            
            // Reset button after delay
            setTimeout(function() {
                exportBtn.prop('disabled', false).html(originalText);
            }, 2000);
        }

        function confirmPayment(orderId) {
            if (confirm('Confirm that payment has been received for this order?')) {
                $.post('deliveries.php', {
                    action: 'update_order_status',
                    order_id: orderId,
                    status: 'confirmed'
                }, function(response) {
                    if (response.success) {
                        showNotification('Payment confirmed successfully!', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showNotification('Error: ' + response.message, 'error');
                    }
                }, 'json');
            }
        }

        function shipOrder(orderId) {
            notifyPostCompletionClick(orderId, 'shipOrder_click');
            if (confirm('Mark this order as shipped?')) {
                $.post('deliveries.php', {
                    action: 'create_delivery',
                    order_id: orderId
                }, function(response) {
                    if (response.success) {
                        showNotification('Delivery created successfully!', 'success');
                        const $completedBtn = $(`.mark-completed-btn[data-order-id="${orderId}"]`);
                        if ($completedBtn.length) { $completedBtn.show(); }
                        const $shipBtn = $(`button[data-order-id="${orderId}"][onclick^="shipOrder"]`);
                        if ($shipBtn.length) { $shipBtn.prop('disabled', true).addClass('disabled'); }
                        triggerRealtime();
                    } else {
                        showNotification('Error: ' + response.message, 'error');
                    }
                }, 'json');
            }
        }

        // markDelivered removed: delivery status updates are no longer supported in supplier UI

        function cancelOrder(orderId) {
            notifyPostCompletionClick(orderId, 'cancelOrder_click');
            const reason = prompt('Please provide a reason for cancellation:');
            if (reason) {
                $.post('deliveries.php', {
                    action: 'update_order_status',
                    order_id: orderId,
                    status: 'cancelled'
                }, function(response) {
                    if (response.success) {
                        showNotification('Order cancelled successfully!', 'success');
                        triggerRealtime();
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showNotification('Error: ' + response.message, 'error');
                    }
                }, 'json');
            }
        }

        function markOrderReceived(orderId) {
            notifyPostCompletionClick(orderId, 'markOrderReceived_click');
            if (confirm('Mark this order as received?')) {
                $.post('deliveries.php', {
                    action: 'mark_order_received',
                    order_id: orderId
                }, function(response) {
                    if (response.success) {
                        showNotification('Order marked as received! Admin has been notified.', 'success');
                        triggerRealtime();
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showNotification('Error: ' + response.message, 'error');
                    }
                }, 'json');
            }
        }

        function markOrderCompleted(orderId) {
            notifyPostCompletionClick(orderId, 'markOrderCompleted_click');
            if (confirm('Mark this order as completed?')) {
                $.post('deliveries.php', {
                    action: 'mark_order_completed',
                    order_id: orderId
                }, function(response) {
                    if (response.success) {
                        showNotification('Order marked as completed! Admin has been notified.', 'success');
                        triggerRealtime();
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showNotification('Error: ' + response.message, 'error');
                    }
                }, 'json');
            }
        }

        function viewOrderDetails(orderId, btn) {
    try {
        var $btn = btn ? $(btn) : null;
        if ($btn && !$btn.data('loading')) {
            $btn.data('loading', true);
            $btn.prop('disabled', true);
            $btn.data('origHtml', $btn.html());
            $btn.html('<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Opening...');
        }
        $.post('deliveries.php', { action: 'transition_to_view', order_id: orderId }, function(response) {
            if (response && response.success) {
                if (typeof showNotification === 'function') {
                    showNotification('Order auto-completed on view.', 'success');
                }
            } else {
                if (typeof showNotification === 'function') {
                    var msg = response && response.message ? response.message : 'Unknown error';
                    showNotification('View transition error: ' + msg, 'error');
                }
            }
            try {
                window.open('order_details.php?id=' + encodeURIComponent(orderId), '_blank', 'noopener');
            } catch (e) {
                window.location.href = 'order_details.php?id=' + encodeURIComponent(orderId);
            }
            if (response && response.success) {
                setTimeout(() => location.reload(), 500);
            }
        }, 'json').fail(function() {
            try {
                window.open('order_details.php?id=' + encodeURIComponent(orderId), '_blank', 'noopener');
            } catch (e) {
                window.location.href = 'order_details.php?id=' + encodeURIComponent(orderId);
            }
        }).always(function() {
            if ($btn) {
                setTimeout(function() {
                    $btn.prop('disabled', false);
                    var origHtml = $btn.data('origHtml');
                    if (origHtml) $btn.html(origHtml);
                    $btn.data('loading', false);
                }, 600);
            }
        });
    } catch (err) {
        if (typeof showNotification === 'function') {
            showNotification('Unexpected error while opening details.', 'error');
        }
        window.location.href = 'order_details.php?id=' + encodeURIComponent(orderId);
    }
}

        function loadNotifications() {
            $.post('deliveries.php', {
                action: 'get_notifications'
            }, function(response) {
                if (response.success && response.notifications) {
                    updateNotificationDropdown(response.notifications);
                }
            }, 'json').fail(function() {
                console.log('Failed to load notifications');
            });
        }
        
        function updateNotificationDropdown(notifications) {
            const $notificationList = $('#notificationList');
            const $notificationBadge = $('#notificationBadge');
            
            if (notifications.length === 0) {
                $notificationList.html('<li><span class="dropdown-item-text text-muted">No new notifications</span></li>');
                $notificationBadge.hide();
                return;
            }
            
            // Count unread notifications
            const unreadCount = notifications.filter(n => n.status !== 'read').length;
            
            if (unreadCount > 0) {
                $notificationBadge.text(unreadCount).show();
            } else {
                $notificationBadge.hide();
            }
            
            // Build notification list
            let notificationHtml = '';
            notifications.forEach(function(notification) {
                const isUnread = notification.status !== 'read' && (!notification.is_read || notification.is_read == 0);
                const timeAgo = formatTimeAgo(notification.sent_at || notification.created_at || notification.sent_at);
                
                notificationHtml += `
                    <li>
                        <div class="dropdown-item-text">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div class="flex-grow-1" style="cursor: pointer;" onclick="viewNotificationDetails(${notification.id})">
                                    <div class="small ${isUnread ? 'fw-bold' : ''}">${escapeHtml(notification.message || 'No message')}</div>
                                    <div class="text-muted small">${timeAgo}</div>
                                </div>
                                <div class="d-flex gap-1">
                                    ${isUnread ? '<span class="badge bg-primary rounded-pill">New</span>' : ''}
                                    <button class="btn btn-sm btn-outline-danger p-1" onclick="event.stopPropagation(); deleteNotification(${notification.id})" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                `;
            });
            
            $notificationList.html(notificationHtml);
        }
        
        function markNotificationRead(notificationId) {
            $.post('deliveries.php', {
                action: 'mark_notification_read',
                notification_id: notificationId
            }, function(response) {
                if (response.success) {
                    loadNotifications(); // Refresh notifications
                }
            }, 'json');
        }
        
        function viewNotificationDetails(notificationId) {
            // Close dropdown
            const dropdown = bootstrap.Dropdown.getInstance(document.getElementById('notificationDropdown'));
            if (dropdown) {
                dropdown.hide();
            }
            
            $.post('deliveries.php', {
                action: 'get_notification_details',
                notification_id: notificationId
            }, function(response) {
                if (response.success && response.notification) {
                    const notif = response.notification;
                    $('#notificationDetailModalLabel').text('Notification Details');
                    $('#notificationModalType').text(notif.type ? notif.type.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) : 'Notification');
                    $('#notificationModalMessage').text(notif.message || 'No message');
                    $('#notificationModalDate').text(notif.created_at ? new Date(notif.created_at).toLocaleString() : (notif.sent_at ? new Date(notif.sent_at).toLocaleString() : 'N/A'));
                    $('#notificationModalChannel').text(notif.channel ? notif.channel.charAt(0).toUpperCase() + notif.channel.slice(1) : 'System');
                    $('#notificationModalOrderId').text(notif.order_id ? '#' + notif.order_id : 'N/A');
                    
                    const notificationModal = new bootstrap.Modal(document.getElementById('notificationDetailModal'));
                    notificationModal.show();
                    
                    // Refresh notifications after viewing
                    loadNotifications();
                } else {
                    showNotification('Failed to load notification details', 'error');
                }
            }, 'json').fail(function() {
                showNotification('Error loading notification', 'error');
            });
        }
        
        function deleteNotification(notificationId) {
            if (confirm('Are you sure you want to delete this notification?')) {
                $.post('deliveries.php', {
                    action: 'delete_notification',
                    notification_id: notificationId
                }, function(response) {
                    if (response.success) {
                        showNotification('Notification deleted successfully', 'success');
                        loadNotifications(); // Refresh notifications
                    } else {
                        showNotification(response.message || 'Failed to delete notification', 'error');
                    }
                }, 'json').fail(function() {
                    showNotification('Error deleting notification', 'error');
                });
            }
        }
        
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text ? text.replace(/[&<>"']/g, m => map[m]) : '';
        }
        
        function formatTimeAgo(dateString) {
            const now = new Date();
            const date = new Date(dateString);
            const diffInSeconds = Math.floor((now - date) / 1000);
            
            if (diffInSeconds < 60) {
                return 'Just now';
            } else if (diffInSeconds < 3600) {
                const minutes = Math.floor(diffInSeconds / 60);
                return `${minutes} minute${minutes > 1 ? 's' : ''} ago`;
            } else if (diffInSeconds < 86400) {
                const hours = Math.floor(diffInSeconds / 3600);
                return `${hours} hour${hours > 1 ? 's' : ''} ago`;
            } else {
                const days = Math.floor(diffInSeconds / 86400);
                return `${days} day${days > 1 ? 's' : ''} ago`;
            }
        }

        function showNotification(message, type) {
            const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
            const notification = `
                <div class="alert ${alertClass} alert-dismissible fade show position-fixed" 
                     style="top: 20px; right: 20px; z-index: 9999;" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            $('body').append(notification);
            
            // Auto-remove after 3 seconds
            setTimeout(() => {
                $('.alert').fadeOut();
            }, 3000);
        }
    </script>

    <!-- Notification Detail Modal -->
    <div class="modal fade" id="notificationDetailModal" tabindex="-1" aria-labelledby="notificationDetailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="notificationDetailModalLabel">Notification Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Type:</label>
                        <div id="notificationModalType">-</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Message:</label>
                        <div id="notificationModalMessage" class="border rounded p-2 bg-light">-</div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Date:</label>
                            <div id="notificationModalDate">-</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Channel:</label>
                            <div id="notificationModalChannel">-</div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Order ID:</label>
                        <div id="notificationModalOrderId">-</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>