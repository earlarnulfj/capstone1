<?php
session_start();
require_once '../config/database.php';
require_once '../models/order.php';
require_once '../models/notification.php';

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
$notification = new Notification($db);

// Get supplier information
$supplier_id = $_SESSION['user_id'];
$supplier_name = $_SESSION['username'];

// Create PDO connection for sidebar compatibility
$pdo = $db;

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'update_variation':
            if (isset($_POST['order_id'], $_POST['variation'])) {
                $orderId = $_POST['order_id'];
                $variation = $_POST['variation'];
                
                $query = "UPDATE orders SET variation = ? WHERE id = ?";
                $stmt = $db->prepare($query);
                $result = $stmt->execute([$variation, $orderId]);
                
                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'Variation updated successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to update variation']);
                }
                exit;
            }
            echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
            exit;
            
        case 'confirm_order':
            // Use transaction to ensure atomicity between order update and notification
            try {
                $db->beginTransaction();

                $query = "UPDATE orders SET confirmation_status = 'confirmed', confirmation_date = NOW() WHERE id = ?";
                $stmt = $db->prepare($query);
                $executed = $stmt->execute([$_POST['order_id']]);

                if ($executed) {
                    // Fetch standardized product & supplier details for payload
                    $detailsStmt = $db->prepare(
                        "SELECT o.id AS order_id, o.quantity, o.confirmation_date,
                                i.id AS product_id, i.name AS product_name,
                                s.id AS supplier_id, s.name AS supplier_name
                         FROM orders o
                         JOIN inventory i ON o.inventory_id = i.id
                         JOIN suppliers s ON o.supplier_id = s.id
                         WHERE o.id = ?"
                    );
                    $detailsStmt->execute([$_POST['order_id']]);
                    $payload = $detailsStmt->fetch(PDO::FETCH_ASSOC);

                    if ($payload) {
                        // Build standardized message (human-readable) and set admin recipient
                        $confirmationTs = $payload['confirmation_date'] ?? date('Y-m-d H:i:s');
                        $message = sprintf(
                            'Product confirmation by %s: ID %d – %s, Qty %d at %s (Order #%d)',
                            $payload['supplier_name'],
                            (int)$payload['product_id'],
                            $payload['product_name'],
                            (int)$payload['quantity'],
                            $confirmationTs,
                            (int)$payload['order_id']
                        );

                        // Configure notification for management (admin) with duplicate prevention
                        $notification->type = 'product_confirmation';
                        $notification->channel = 'web';
                        $notification->recipient_type = 'management';
                        $notification->recipient_id = 1; // Admin (management) user ID
                        $notification->order_id = $payload['order_id'];
                        $notification->alert_id = null;
                        $notification->message = $message;
                        $notification->status = 'pending';

                        // Prevent duplicates within 5 minutes for same order/type/recipient
                        $created = $notification->createWithDuplicateCheck(true, 5);
                        if ($created === false) {
                            // Duplicate prevented; proceed without error
                            $db->commit();
                            echo json_encode(['success' => true, 'message' => 'Order confirmed successfully (duplicate notification prevented)']);
                            exit();
                        }

                        // Commit transaction after successful notification creation
                        $db->commit();

                        // SYNC: Update admin_orders table to match orders table status
                        // This ensures admin orders reflect supplier-side confirmation
                        syncOrderStatusToAdminOrders($db, $_POST['order_id'], 'confirmed');

                        echo json_encode(['success' => true, 'message' => 'Order confirmed successfully']);
                        exit();
                    } else {
                        // Missing payload details; rollback and log
                        $db->rollBack();
                        error_log('[Notification] Payload fetch failed for order_id=' . $_POST['order_id'] . "\n", 3, __DIR__ . '/../logs/notification.log');
                        echo json_encode(['success' => false, 'message' => 'Failed to fetch order details']);
                        exit();
                    }
                } else {
                    $db->rollBack();
                    echo json_encode(['success' => false, 'message' => 'Failed to confirm order']);
                    exit();
                }
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                error_log('[Notification] Exception during confirm_order: ' . $e->getMessage() . "\n", 3, __DIR__ . '/../logs/notification.log');
                echo json_encode(['success' => false, 'message' => 'Error confirming order']);
                exit();
            }
            
        case 'cancel_order':
            // Use direct SQL query to properly set cancellation status
            $query = "UPDATE orders SET confirmation_status = 'cancelled', confirmation_date = NOW() WHERE id = ?";
            $stmt = $db->prepare($query);
            
            if ($stmt->execute([$_POST['order_id']])) {
                // SYNC: Update admin_orders table to match orders table status
                syncOrderStatusToAdminOrders($db, $_POST['order_id'], 'cancelled');
                
                // Add notification for admin
                $notification->type = 'order_cancelled';
                $notification->channel = 'web';
                $notification->recipient_type = 'admin';
                $notification->recipient_id = 1; // Admin user ID
                $notification->order_id = $_POST['order_id'];
                $notification->message = "Supplier {$supplier_name} cancelled order #{$_POST['order_id']}";
                $notification->status = 'pending';
                $notification->create();
                
                echo json_encode(['success' => true, 'message' => 'Order cancelled successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to cancel order']);
            }
            exit();
            
        case 'mark_pending':
            $order->id = $_POST['order_id'];
            $order->confirmation_status = 'pending';
            
            // Use a custom query for pending status to set confirmation_date to NULL
            $query = "UPDATE orders SET confirmation_status = 'pending', confirmation_date = NULL WHERE id = ?";
            $stmt = $db->prepare($query);
            
            if ($stmt->execute([$_POST['order_id']])) {
                // SYNC: Update admin_orders table to match orders table status
                syncOrderStatusToAdminOrders($db, $_POST['order_id'], 'pending');
                
                // Add notification for admin
                $notification->type = 'order_pending';
                $notification->channel = 'web';
                $notification->recipient_type = 'admin';
                $notification->recipient_id = 1; // Admin user ID
                $notification->order_id = $_POST['order_id'];
                $notification->message = "Supplier {$supplier_name} marked order #{$_POST['order_id']} as pending";
                $notification->status = 'pending';
                $notification->create();
                
                echo json_encode(['success' => true, 'message' => 'Order marked as pending successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to mark order as pending']);
            }
            exit();
            
        case 'reactivate_order':
            $order->id = $_POST['order_id'];
            $order->confirmation_status = 'pending';
            
            // Use a custom query for reactivation to set confirmation_date to NULL
            $query = "UPDATE orders SET confirmation_status = 'pending', confirmation_date = NULL WHERE id = ?";
            $stmt = $db->prepare($query);
            
            if ($stmt->execute([$_POST['order_id']])) {
                // SYNC: Update admin_orders table to match orders table status
                syncOrderStatusToAdminOrders($db, $_POST['order_id'], 'pending');
                
                // Add notification for admin
                $notification->type = 'order_reactivated';
                $notification->channel = 'web';
                $notification->recipient_type = 'admin';
                $notification->recipient_id = 1; // Admin user ID
                $notification->order_id = $_POST['order_id'];
                $notification->message = "Supplier {$supplier_name} reactivated order #{$_POST['order_id']}";
                $notification->status = 'pending';
                $notification->create();
                
                echo json_encode(['success' => true, 'message' => 'Order reactivated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to reactivate order']);
            }
            exit();
            
        case 'bulk_delete':
            // Bulk DELETE orders from database (permanent deletion)
            try {
                $order_ids = $_POST['order_ids'] ?? [];
                if (empty($order_ids) || !is_array($order_ids)) {
                    echo json_encode(['success' => false, 'message' => 'No orders selected for deletion.']);
                    exit();
                }
                
                // Validate all orders belong to this supplier
                $valid_ids = [];
                foreach ($order_ids as $oid) {
                    $oid = (int)$oid;
                    if ($oid > 0) {
                        $checkStmt = $db->prepare("SELECT id FROM orders WHERE id = ? AND supplier_id = ? LIMIT 1");
                        $checkStmt->execute([$oid, $supplier_id]);
                        if ($checkStmt->fetchColumn()) {
                            $valid_ids[] = $oid;
                        }
                    }
                }
                
                if (empty($valid_ids)) {
                    echo json_encode(['success' => false, 'message' => 'No valid orders selected for deletion.']);
                    exit();
                }
                
                $db->beginTransaction();
                $deleted_count = 0;
                
                foreach ($valid_ids as $order_id) {
                    try {
                        // Delete related records first (to handle foreign key constraints)
                        // 1. Delete notifications related to this order
                        try {
                            $delNotifStmt = $db->prepare("DELETE FROM notifications WHERE order_id = ?");
                            $delNotifStmt->execute([$order_id]);
                        } catch (Exception $e) {
                            error_log("Warning: Could not delete notifications for order #{$order_id}: " . $e->getMessage());
                        }
                        
                        // 2. Delete deliveries related to this order
                        try {
                            $delDelivStmt = $db->prepare("DELETE FROM deliveries WHERE order_id = ?");
                            $delDelivStmt->execute([$order_id]);
                        } catch (Exception $e) {
                            error_log("Warning: Could not delete deliveries for order #{$order_id}: " . $e->getMessage());
                        }
                        
                        // 3. Delete payments related to this order
                        try {
                            $delPayStmt = $db->prepare("DELETE FROM payments WHERE order_id = ?");
                            $delPayStmt->execute([$order_id]);
                        } catch (Exception $e) {
                            error_log("Warning: Could not delete payments for order #{$order_id}: " . $e->getMessage());
                        }
                        
                        // 4. Delete the order itself from orders table
                        // CRITICAL: Only delete from orders table, NOT from admin_orders table
                        // This ensures supplier deletions are independent from admin orders
                        // Admin orders (admin_orders table) are managed separately in admin/orders.php
                        $delOrderStmt = $db->prepare("DELETE FROM orders WHERE id = ? AND supplier_id = ?");
                        if ($delOrderStmt->execute([$order_id, $supplier_id])) {
                            $deleted_count++;
                            error_log("Success: Deleted order #{$order_id} and all related records from database");
                        } else {
                            error_log("Warning: Failed to delete order #{$order_id} from orders table");
                        }
                    } catch (Exception $e) {
                        error_log("Error deleting order #{$order_id}: " . $e->getMessage());
                        // Continue with next order
                    }
                }
                
                $db->commit();
                echo json_encode(['success' => true, 'message' => "{$deleted_count} order(s) deleted permanently from database."]);
            } catch (Exception $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                error_log("Error in bulk_delete: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            exit();
            
        case 'get_new_orders_count':
            // Get count of new orders for this supplier
            $stmt = $db->prepare("SELECT COUNT(*) as count FROM orders WHERE supplier_id = ? AND confirmation_status = 'pending' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $stmt->execute([$supplier_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['count' => $result['count']]);
            exit();
    }
}

// Get all orders for this supplier
$orders_stmt = $order->readBySupplier($supplier_id);
$orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_orders = count($orders);
$pending_orders = array_filter($orders, function($o) { return $o['confirmation_status'] === 'pending'; });
$confirmed_orders = array_filter($orders, function($o) { return $o['confirmation_status'] === 'confirmed'; });
$cancelled_orders = array_filter($orders, function($o) { return $o['confirmation_status'] === 'cancelled'; });

$pending_count = count($pending_orders);
$confirmed_count = count($confirmed_orders);
$cancelled_count = count($cancelled_orders);

// Calculate total value
$total_value = array_sum(array_map(function($o) { 
    return ($o['unit_price'] ?? 0) * $o['quantity']; 
}, $orders));

// Get variation data for all inventory items
$variation_data = [];
$inventory_ids = array_unique(array_column($orders, 'inventory_id'));
if (!empty($inventory_ids)) {
    $inventory_ids = array_values(array_filter($inventory_ids, function($id) { return $id !== null; })); // Reindex and filter nulls
    if (!empty($inventory_ids)) {
        $placeholders = implode(',', array_fill(0, count($inventory_ids), '?'));
        $var_stmt = $db->prepare("SELECT inventory_id, variation, unit_type, quantity as stock FROM inventory_variations WHERE inventory_id IN ($placeholders)");
        $var_stmt->execute($inventory_ids);
        while ($var = $var_stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!isset($variation_data[$var['inventory_id']])) {
                $variation_data[$var['inventory_id']] = [];
            }
            $variation_data[$var['inventory_id']][] = $var;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders - <?php echo htmlspecialchars($supplier_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <style>
        body {
            min-height: 100vh;
        }

        .sidebar {
            min-height: 100vh;
        }

        .main-content {
            background: rgba(255, 255, 255, 0.95);
            min-height: 100vh;
            padding: 30px;
            backdrop-filter: blur(10px);
        }

        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 30px;
            border-radius: 15px;
            margin-bottom: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .page-header h2 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .page-header .subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            border: none;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            margin-bottom: 25px;
        }

        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
        }

        .stats-card .card-body {
            padding: 0;
        }

        .stats-card h3 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stats-card .text-muted {
            font-size: 0.95rem;
            font-weight: 500;
        }

        .card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .card-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 2px solid #dee2e6;
            border-radius: 15px 15px 0 0 !important;
            padding: 20px 25px;
        }

        .card-header h5 {
            margin: 0;
            font-weight: 600;
            color: #2c3e50;
        }

        .table {
            margin-bottom: 0;
        }

        .table thead th {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: none;
            font-weight: 600;
            color: #2c3e50;
            padding: 15px;
            font-size: 0.9rem;
        }

        .table tbody tr {
            transition: all 0.3s ease;
        }

        .table tbody tr:hover {
            background-color: rgba(102, 126, 234, 0.05);
            transform: scale(1.01);
        }

        .table tbody td {
            padding: 15px;
            vertical-align: middle;
            border-color: #f1f3f4;
        }

        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            justify-content: center;
        }

        .action-buttons .btn {
            margin: 2px;
            font-size: 0.8rem;
            padding: 6px 12px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .action-buttons .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        /* Enhanced product and customer info styling */
        .product-details, .customer-info, .order-id-cell {
            line-height: 1.4;
        }

        .product-name, .customer-name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 4px;
        }

        .product-meta, .customer-info small {
            display: block;
            color: #718096;
            font-size: 0.8rem;
        }

        .order-id-cell strong {
            font-size: 1.1rem;
            font-weight: 700;
        }

        /* Status badge improvements */
        .status-badge {
            padding: 8px 16px;
            border-radius: 25px;
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }

        .status-pending {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: white;
        }

        .status-confirmed {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .status-completed {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
        }

        .status-cancelled {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        /* Enhanced button styles */
        .btn-sm {
            font-size: 0.8rem;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            border: none;
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border: none;
        }

        .btn-warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            border: none;
        }

        .btn-info {
            background: linear-gradient(135deg, #06b6d4, #0891b2);
            border: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: none;
        }

        /* Chat button styling */
        .chat-btn {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            border: none;
            color: white;
        }

        /* DataTables Search Enhancement */
        .dataTables_wrapper .dataTables_filter {
            margin-bottom: 20px;
        }
        
        .dataTables_wrapper .dataTables_filter label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
        }
        
        .dataTables_wrapper .dataTables_filter input {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 15px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .dataTables_wrapper .dataTables_filter input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            outline: none;
        }
        
        .dataTables_wrapper .dataTables_length {
            margin-bottom: 20px;
        }
        
        .dataTables_wrapper .dataTables_length select {
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            padding: 5px 10px;
            margin: 0 5px;
        }

        /* Notification styles */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .notification.show {
            animation: slideInRight 0.5s ease;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* New order indicator */
        .new-order-indicator {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 9999;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 15px 25px;
            border-radius: 50px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: translateX(-50%) scale(1); }
            50% { transform: translateX(-50%) scale(1.05); }
            100% { transform: translateX(-50%) scale(1); }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }

            .page-header {
                padding: 20px 15px;
                margin-bottom: 20px;
            }

            .page-header h2 {
                font-size: 1.6rem;
            }

            .action-buttons {
                flex-direction: column;
                gap: 8px;
            }

            .action-buttons .btn {
                font-size: 0.75rem;
                padding: 8px 12px;
            }

            .table thead th,
            .table tbody td {
                padding: 10px 8px;
                font-size: 0.8rem;
            }

            .stats-card h3 {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
<?php include_once 'includes/header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include_once 'includes/sidebar.php'; ?>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 main-content">
                <div class="page-header">
                    <h2><i class="bi bi-cart3 me-3"></i>Order Management</h2>
                    <p class="subtitle mb-0">Manage your incoming orders and track their status</p>
                </div>

                <!-- New Order Indicator (hidden by default) -->
                <div id="newOrderIndicator" class="new-order-indicator" style="display: none;">
                    <i class="bi bi-bell-fill me-2"></i>
                    <span id="newOrderText">New orders received!</span>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="card-body text-center">
                                <h3 class="text-primary"><?php echo $total_orders; ?></h3>
                                <p class="text-muted mb-0">Total Orders</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="card-body text-center">
                                <h3 class="text-warning"><?php echo $pending_count; ?></h3>
                                <p class="text-muted mb-0">Pending Orders</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="card-body text-center">
                                <h3 class="text-success"><?php echo $confirmed_count; ?></h3>
                                <p class="text-muted mb-0">Confirmed Orders</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="card-body text-center">
                                <h3 class="text-info">₱<?php echo number_format($total_value, 2); ?></h3>
                                <p class="text-muted mb-0">Total Value</p>
                            </div>
                        </div>
                    </div>
                </div>



                <!-- Orders Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>All Orders</h5>
                        <button type="button" class="btn btn-danger btn-sm" id="bulkDeleteBtn" style="display: none;">
                            <i class="bi bi-trash me-1"></i>Delete Selected (<span id="selectedCount">0</span>)
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="ordersTable">
                                <thead>
                                    <tr>
                                        <th width="50">
                                            <input type="checkbox" id="selectAllOrders" title="Select All">
                                        </th>
                                        <th>Order ID</th>
                                        <th>Product Details</th>
                                        <th>Unit Type</th>
                                        <th>Variation</th>
                                        <th>Customer Info</th>
                                        <th>Quantity</th>
                                        <th>Total Price</th>
                                        <th>Order Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($orders)): ?>
                                    <tr>
                                        <td colspan="11" class="text-center py-5">
                                            <div class="text-muted">
                                                <i class="bi bi-inbox" style="font-size: 3rem;"></i>
                                                <p class="mt-3 mb-0 fs-5">No orders available</p>
                                                <p class="text-muted">Orders will appear here when customers place them</p>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <?php foreach ($orders as $order_item): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="order-checkbox" value="<?php echo (int)($order_item['id'] ?? 0); ?>" data-order-id="<?php echo (int)($order_item['id'] ?? 0); ?>">
                                        </td>
                                        <td class="order-id-cell">
                                            <strong>#<?php echo $order_item['id']; ?></strong>
                                            <small class="d-block text-muted">
                                                <?php echo date('M d', strtotime($order_item['order_date'])); ?>
                                            </small>
                                        </td>
                                        <td class="product-details">
                                            <div class="product-name"><?php echo htmlspecialchars($order_item['item_name'] ?? $order_item['inventory_name'] ?? 'N/A'); ?></div>
                                            <small class="product-meta">
                                                ID: <?php echo $order_item['inventory_id'] ?? 'N/A'; ?>
                                                <?php if (isset($order_item['category'])): ?>
                                                | <?php echo htmlspecialchars($order_item['category']); ?>
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                        <td><?php echo htmlspecialchars($order_item['unit_type'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?= htmlspecialchars(formatVariationForDisplay($order_item['variation'] ?? 'N/A')) ?>
                                        </td>
                                        <td class="customer-info">
                                            <div class="customer-name"><?php echo htmlspecialchars($order_item['customer_name'] ?? 'N/A'); ?></div>
                                            <small>
                                                ID: <?php echo $order_item['customer_id'] ?? 'N/A'; ?><br>
                                                <?php echo htmlspecialchars($order_item['customer_email'] ?? 'N/A'); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <strong><?php echo $order_item['quantity']; ?></strong>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <strong class="text-success fs-6">₱<?php echo number_format(($order_item['unit_price'] ?? 0) * $order_item['quantity'], 2); ?></strong>
                                                <small class="text-muted">(₱<?php echo number_format($order_item['unit_price'] ?? 0, 2); ?> × <?= $order_item['quantity'] ?>)</small>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo date('M d, Y H:i', strtotime($order_item['order_date'])); ?>
                                        </td>
                                        <td>
                                            <?php if ($order_item['confirmation_status'] == 'pending'): ?>
                                                <span class="status-badge status-pending">Pending</span>
                                            <?php elseif ($order_item['confirmation_status'] == 'confirmed'): ?>
                                                <span class="status-badge status-confirmed">Confirmed</span>
                                            <?php elseif ($order_item['confirmation_status'] == 'completed'): ?>
                                                <span class="status-badge status-completed">Completed</span>
                                            <?php else: ?>
                                                <span class="status-badge status-cancelled">Cancelled</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <?php if ($order_item['confirmation_status'] === 'pending'): ?>
                                                    <button type="button" class="btn btn-sm btn-success" 
                                                            onclick="confirmOrder(<?php echo $order_item['id']; ?>)"
                                                            title="Confirm this order">
                                                        <i class="bi bi-check me-1"></i>Confirm
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-danger" 
                                                            onclick="cancelOrder(<?php echo $order_item['id']; ?>)"
                                                            title="Cancel this order">
                                                        <i class="bi bi-x me-1"></i>Cancel
                                                    </button>
                                                <?php elseif ($order_item['confirmation_status'] === 'confirmed'): ?>
                                                    <button type="button" class="btn btn-sm btn-warning" 
                                                            onclick="markAsPending(<?php echo $order_item['id']; ?>)"
                                                            title="Mark as pending">
                                                        <i class="bi bi-clock me-1"></i>Mark Pending
                                                    </button>
                                                <?php elseif ($order_item['confirmation_status'] === 'completed'): ?>
                                                    <span class="badge bg-success">
                                                        <i class="bi bi-check-circle me-1"></i>Order Completed
                                                    </span>
                                                <?php elseif ($order_item['confirmation_status'] === 'cancelled'): ?>
                                                    <button type="button" class="btn btn-sm btn-warning" 
                                                            onclick="reactivateOrder(<?php echo $order_item['id']; ?>)"
                                                            title="Reactivate this order">
                                                        <i class="bi bi-arrow-clockwise me-1"></i>Reactivate
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <button type="button" class="btn btn-sm chat-btn" 
                                                        onclick="openOrderChat(<?php echo $order_item['id']; ?>)"
                                                        title="Chat about this order">
                                                    <i class="bi bi-chat-dots me-1"></i>Chat
                                                </button>
                                                
                                                <button type="button" class="btn btn-sm btn-info" 
                                                        onclick="viewOrderDetails(<?php echo $order_item['id']; ?>)"
                                                        title="View order details">
                                                    <i class="bi bi-eye me-1"></i>Details
                                                </button>
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
            // Multi-select functionality
            const selectAllCheckbox = $('#selectAllOrders');
            const orderCheckboxes = $('.order-checkbox');
            const bulkDeleteBtn = $('#bulkDeleteBtn');
            const selectedCountSpan = $('#selectedCount');
            
            // Update selected count and show/hide bulk delete button
            function updateSelection() {
                const checked = $('.order-checkbox:checked').length;
                selectedCountSpan.text(checked);
                if (checked > 0) {
                    bulkDeleteBtn.show();
                } else {
                    bulkDeleteBtn.hide();
                }
                
                // Update select all checkbox state
                const total = orderCheckboxes.length;
                selectAllCheckbox.prop('indeterminate', checked > 0 && checked < total);
                selectAllCheckbox.prop('checked', checked === total && total > 0);
            }
            
            // Select all checkbox handler
            selectAllCheckbox.on('change', function() {
                orderCheckboxes.prop('checked', $(this).is(':checked'));
                updateSelection();
            });
            
            // Individual checkbox handler
            $(document).on('change', '.order-checkbox', function() {
                updateSelection();
            });
            
            // Bulk delete handler
            bulkDeleteBtn.on('click', function() {
                const selected = $('.order-checkbox:checked').map(function() {
                    return $(this).val();
                }).get();
                
                if (selected.length === 0) {
                    alert('Please select at least one order to delete.');
                    return;
                }
                
                const orderIds = $('.order-checkbox:checked').map(function() {
                    return $(this).data('order-id');
                }).get().join(', #');
                
                if (confirm(`⚠️ WARNING: This will PERMANENTLY DELETE ${selected.length} order(s) from the database!\n\nOrder IDs: #${orderIds}\n\nThis action cannot be undone. All related records (notifications, deliveries, payments) will also be deleted.\n\nAre you absolutely sure you want to proceed?`)) {
                    $.post('', {
                        action: 'bulk_delete',
                        order_ids: selected
                    }, function(response) {
                        if (response && response.success) {
                            showNotification(response.message || 'Orders cancelled successfully!', 'success');
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            showNotification('Failed to delete orders: ' + (response.message || 'Unknown error'), 'error');
                        }
                    }, 'json').fail(function(xhr, status, error) {
                        console.error('AJAX Error:', status, error);
                        showNotification('Error deleting orders. Please try again.', 'error');
                    });
                }
            });
            
            // Initialize selection state
            updateSelection();
            
            // Initialize DataTable with enhanced search (guard against placeholder colspan row)
            const $ordersTable = $('#ordersTable');
            const hasRows = $ordersTable.find('tbody tr').length > 0;
            const hasColspanPlaceholder = $ordersTable.find('tbody tr td[colspan]').length > 0;

            if ($ordersTable.length && hasRows && !hasColspanPlaceholder) {
                $ordersTable.DataTable({
                    responsive: true,
                    pageLength: 25,
                    order: [[8, 'desc']], // Sort by order date descending (column 8, 0-indexed, adjusted for checkbox column)
                    columnDefs: [
                        { orderable: false, targets: [0, 10] } // Disable sorting for checkbox and actions columns
                    ],
                    language: {
                        search: "Search Orders:",
                        searchPlaceholder: "Search by order ID, product, customer...",
                        lengthMenu: "Show _MENU_ orders per page",
                        info: "Showing _START_ to _END_ of _TOTAL_ orders",
                        infoEmpty: "No orders found",
                        infoFiltered: "(filtered from _MAX_ total orders)",
                        zeroRecords: "No matching orders found"
                    },
                    dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                         '<"row"<"col-sm-12"tr>>' +
                         '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
                    initComplete: function() {
                        // Style the search input
                        $('.dataTables_filter input').addClass('form-control').css({
                            'width': '300px',
                            'margin-left': '10px'
                        });
                        $('.dataTables_filter label').css({
                            'font-weight': '600',
                            'color': '#2c3e50'
                        });
                        $('.dataTables_length select').addClass('form-select');
                    }
                });
            }
            
            // Check for new orders every 30 seconds
            setInterval(checkForNewOrders, 30000);
            
            // Initial check
            checkForNewOrders();
        });

        function confirmOrder(orderId) {
            if (confirm('Are you sure you want to confirm this order?')) {
                // Disable the button to prevent double-clicks
                $(`button[onclick="confirmOrder(${orderId})"]`).prop('disabled', true);
                
                $.post('', {
                    action: 'confirm_order',
                    order_id: orderId
                }, function(response) {
                    if (response && response.success) {
                        showNotification('Order confirmed successfully!', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showNotification('Failed to confirm order: ' + (response.message || 'Unknown error'), 'error');
                        $(`button[onclick="confirmOrder(${orderId})"]`).prop('disabled', false);
                    }
                }, 'json').fail(function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    $(`button[onclick="confirmOrder(${orderId})"]`).prop('disabled', false);
                });
            }
        }

        function cancelOrder(orderId) {
            if (confirm('Are you sure you want to cancel this order?')) {
                // Disable the button to prevent double-clicks
                $(`button[onclick="cancelOrder(${orderId})"]`).prop('disabled', true);
                
                $.post('', {
                    action: 'cancel_order',
                    order_id: orderId
                }, function(response) {
                    if (response && response.success) {
                        showNotification('Order cancelled successfully!', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showNotification('Failed to cancel order: ' + (response.message || 'Unknown error'), 'error');
                        $(`button[onclick="cancelOrder(${orderId})"]`).prop('disabled', false);
                    }
                }, 'json').fail(function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    showNotification('Error cancelling order. Please try again.', 'error');
                    $(`button[onclick="cancelOrder(${orderId})"]`).prop('disabled', false);
                });
            }
        }

        function markAsPending(orderId) {
            if (confirm('Are you sure you want to mark this order as pending?')) {
                // Disable the button to prevent double-clicks
                $(`button[onclick="markAsPending(${orderId})"]`).prop('disabled', true);
                
                $.post('', {
                    action: 'mark_pending',
                    order_id: orderId
                }, function(response) {
                    if (response && response.success) {
                        showNotification('Order marked as pending successfully!', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showNotification('Failed to mark order as pending: ' + (response.message || 'Unknown error'), 'error');
                        $(`button[onclick="markAsPending(${orderId})"]`).prop('disabled', false);
                    }
                }, 'json').fail(function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    $(`button[onclick="markAsPending(${orderId})"]`).prop('disabled', false);
                });
            }
        }

        function openOrderChat(orderId) {
            // Open chat window for specific order
            const chatUrl = `chat.php?order_id=${orderId}&type=order`;
            window.open(chatUrl, 'OrderChat', 'width=800,height=600,scrollbars=yes,resizable=yes');
        }

        function openAdminChat() {
            // Open general chat with admin
            const chatUrl = 'chat.php?type=admin';
            window.open(chatUrl, 'AdminChat', 'width=800,height=600,scrollbars=yes,resizable=yes');
        }

        function viewOrderDetails(orderId) {
            window.location.href = `order_details.php?id=${orderId}`;
        }

        function checkForNewOrders() {
            $.post('', {
                action: 'get_new_orders_count'
            }, function(response) {
                if (response.count > 0) {
                    showNewOrderIndicator(response.count);
                }
            }, 'json').fail(function() {
                console.log('Failed to check for new orders');
            });
        }

        function showNewOrderIndicator(count) {
            const indicator = document.getElementById('newOrderIndicator');
            const text = document.getElementById('newOrderText');
            
            if (count === 1) {
                text.textContent = 'New order received!';
            } else {
                text.textContent = `${count} new orders received!`;
            }
            
            indicator.style.display = 'block';
            
            // Hide after 10 seconds
            setTimeout(() => {
                indicator.style.display = 'none';
            }, 10000);
        }

        function showNotification(message, type) {
            const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
            const icon = type === 'success' ? 'bi-check-circle' : 'bi-exclamation-triangle';
            
            const notification = $(`
                <div class="notification alert ${alertClass} alert-dismissible fade show" role="alert">
                    <i class="bi ${icon} me-2"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `);
            
            $('body').append(notification);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                notification.alert('close');
            }, 5000);
        }

        function reactivateOrder(orderId) {
            if (confirm('Are you sure you want to reactivate this order?')) {
                // Disable the button to prevent double-clicks
                $(`button[onclick="reactivateOrder(${orderId})"]`).prop('disabled', true);
                
                $.post('', {
                    action: 'reactivate_order',
                    order_id: orderId
                }, function(response) {
                    if (response && response.success) {
                        showNotification('Order reactivated successfully!', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showNotification('Failed to reactivate order: ' + (response.message || 'Unknown error'), 'error');
                        $(`button[onclick="reactivateOrder(${orderId})"]`).prop('disabled', false);
                    }
                }, 'json').fail(function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    showNotification('Error reactivating order. Please try again.', 'error');
                    $(`button[onclick="reactivateOrder(${orderId})"]`).prop('disabled', false);
                });
            }
        }

        function refreshOrders() {
            location.reload();
        }
    </script>
</body>
</html>