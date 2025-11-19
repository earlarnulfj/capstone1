<?php
/**
 * AJAX Endpoint for Order Deletion
 * Handles comprehensive order deletion with logging and integrity checks
 */

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../models/admin_order.php';
require_once '../../lib/audit.php';

// Set error handler BEFORE any output
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../logs/php_errors.log');

// Set JSON header
header('Content-Type: application/json; charset=UTF-8');

// Buffer output to prevent any accidental output
ob_start();

// Authentication and Authorization Check
if (empty($_SESSION['admin']['user_id']) || (($_SESSION['admin']['role'] ?? null) !== 'management')) {
    ob_clean();
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized: Access denied. Management role required.',
        'error_code' => 'AUTH_REQUIRED'
    ]);
    ob_end_flush();
    exit;
}

$user_id = $_SESSION['admin']['user_id'];
$username = $_SESSION['admin']['username'] ?? 'Unknown User';

// Get input data (supports both JSON and form data)
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$order_id = isset($data['id']) ? (int)$data['id'] : 0;
$item_name = isset($data['item']) ? trim($data['item']) : '';

// Validate input
if ($order_id <= 0) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid order ID provided.',
        'error_code' => 'INVALID_ORDER_ID'
    ]);
    ob_end_flush();
    exit;
}

if (empty($item_name)) {
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Item name is required for deletion confirmation.',
        'error_code' => 'MISSING_ITEM_NAME'
    ]);
    ob_end_flush();
    exit;
}

try {
    // Convert warnings/notices into exceptions so they are caught and returned as JSON
    set_error_handler(function($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) { return false; }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    $db = (new Database())->getConnection();
    $order = new AdminOrder($db);

    // Step 1: Verify order exists and get complete order details for logging
    $checkStmt = $db->prepare("
        SELECT 
            o.id,
            o.inventory_id,
            o.supplier_id,
            o.user_id,
            o.quantity,
            o.is_automated,
            o.order_date,
            o.confirmation_status,
            o.confirmation_date,
            o.unit_price,
            o.unit_type,
            o.variation,
            i.name as item_name,
            s.name as supplier_name,
            u.username
        FROM admin_orders o
        LEFT JOIN inventory i ON o.inventory_id = i.id
        LEFT JOIN suppliers s ON o.supplier_id = s.id
        LEFT JOIN users u ON o.user_id = u.id
        WHERE o.id = ?
        LIMIT 1
    ");
    $checkStmt->execute([$order_id]);
    $orderDetails = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$orderDetails) {
        ob_clean();
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => "Order #{$order_id} not found in admin orders.",
            'error_code' => 'ORDER_NOT_FOUND'
        ]);
        ob_end_flush();
        exit;
    }

    // Step 2: Verify item name matches (security check)
    $actual_item_name = $orderDetails['item_name'] ?? '';
    if ($actual_item_name !== $item_name && 'Order #' . $order_id !== $item_name) {
        // Log suspicious activity
        audit_log_event($db, 'order_deletion_attempt', 'order', $order_id, 'rejected', false, 
            "Item name mismatch. Expected: '{$actual_item_name}', Provided: '{$item_name}'", 
            [
                'source' => 'admin_ui',
                'target' => 'admin_orders',
                'order_id' => $order_id,
                'user_id' => $user_id,
                'username' => $username
            ]
        );
        
        ob_clean();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Item name mismatch. Deletion cancelled for security.',
            'error_code' => 'ITEM_NAME_MISMATCH'
        ]);
        ob_end_flush();
        exit;
    }

    // Step 3: Check if order can be deleted (business rules)
    $confirmation_status = $orderDetails['confirmation_status'] ?? 'pending';
    
    // Prevent deletion of completed orders (they affect inventory)
    if ($confirmation_status === 'completed') {
        // Check if order has deliveries that were delivered
        $deliveryCheck = $db->prepare("
            SELECT COUNT(*) as delivered_count
            FROM deliveries 
            WHERE order_id = ? 
            AND status IN ('delivered', 'completed')
        ");
        $deliveryCheck->execute([$order_id]);
        $deliveredCount = (int)$deliveryCheck->fetchColumn();
        
        if ($deliveredCount > 0) {
            audit_log_event($db, 'order_deletion_attempt', 'order', $order_id, 'rejected', false,
                "Cannot delete completed order with delivered items. Delivered count: {$deliveredCount}",
                [
                    'source' => 'admin_ui',
                    'target' => 'admin_orders',
                    'order_id' => $order_id,
                    'user_id' => $user_id,
                    'username' => $username,
                    'status_before' => $confirmation_status
                ]
            );
            
            ob_clean();
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Cannot delete completed orders that have been delivered. Cancel the order before deletion if needed.',
                'error_code' => 'COMPLETED_ORDER_RESTRICTION'
            ]);
            ob_end_flush();
            exit;
        }
    }

    // Step 4: Check for related deliveries
    $deliveryStmt = $db->prepare("SELECT COUNT(*) FROM deliveries WHERE order_id = ?");
    $deliveryStmt->execute([$order_id]);
    $delivery_count = (int)$deliveryStmt->fetchColumn();

    // Step 5: Prepare order details for logging (before deletion)
    $orderDataForLog = [
        'id' => $orderDetails['id'],
        'inventory_id' => $orderDetails['inventory_id'],
        'supplier_id' => $orderDetails['supplier_id'],
        'user_id' => $orderDetails['user_id'],
        'quantity' => $orderDetails['quantity'],
        'is_automated' => $orderDetails['is_automated'],
        'order_date' => $orderDetails['order_date'],
        'confirmation_status' => $orderDetails['confirmation_status'],
        'confirmation_date' => $orderDetails['confirmation_date'],
        'unit_price' => $orderDetails['unit_price'],
        'unit_type' => $orderDetails['unit_type'],
        'variation' => $orderDetails['variation'],
        'item_name' => $orderDetails['item_name'],
        'supplier_name' => $orderDetails['supplier_name'],
        'created_by_username' => $orderDetails['username']
    ];

    // Step 6: Begin transaction for atomic deletion
    $db->beginTransaction();
    
    try {
        $inventory_id = (int)($orderDetails['inventory_id'] ?? 0);
        $order_quantity = (int)($orderDetails['quantity'] ?? 0);
        $variation = !empty($orderDetails['variation']) ? trim($orderDetails['variation']) : null;
        $unit_type = !empty($orderDetails['unit_type']) ? trim($orderDetails['unit_type']) : 'per piece';
        $order_status = $orderDetails['confirmation_status'] ?? 'pending';

        // Step 7: Handle inventory reversal if order was completed
        if ($order_status === 'completed' && $inventory_id > 0) {
            if ($variation) {
                // Decrement stock for this variation
                $varCheck = $db->prepare("SELECT id, quantity FROM inventory_variations WHERE inventory_id = ? AND variation = ? LIMIT 1");
                $varCheck->execute([$inventory_id, $variation]);
                $existingVar = $varCheck->fetch(PDO::FETCH_ASSOC);
                
                if ($existingVar) {
                    $newQty = max(0, (int)$existingVar['quantity'] - $order_quantity);
                    if ($newQty > 0) {
                        $updateVarStmt = $db->prepare("UPDATE inventory_variations SET quantity = ? WHERE id = ?");
                        $updateVarStmt->execute([$newQty, (int)$existingVar['id']]);
                    } else {
                        // If stock reaches 0, delete the variation record
                        $deleteVarStmt = $db->prepare("DELETE FROM inventory_variations WHERE id = ?");
                        $deleteVarStmt->execute([(int)$existingVar['id']]);
                    }
                }
            } else {
                // No variation - update base inventory quantity
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

        // Step 8: Delete related notifications
        try {
            $notifStmt = $db->prepare("DELETE FROM notifications WHERE order_id = ?");
            $notifStmt->execute([$order_id]);
        } catch (Exception $e) {
            // Ignore if notifications table doesn't exist
            error_log("Note: Could not delete notifications for order {$order_id}: " . $e->getMessage());
        }

        // Step 9: Delete the order from admin_orders table
        $deleteStmt = $db->prepare("DELETE FROM admin_orders WHERE id = ?");
        $deleteResult = $deleteStmt->execute([$order_id]);
        $rowsDeleted = $deleteStmt->rowCount();

        if (!$deleteResult) {
            $errorInfo = $deleteStmt->errorInfo();
            throw new Exception("DELETE query execution failed. Error: " . ($errorInfo[2] ?? 'Unknown error'));
        }

        // Step 10: Verify deletion was successful
        $verifyStmt = $db->prepare("SELECT COUNT(*) FROM admin_orders WHERE id = ?");
        $verifyStmt->execute([$order_id]);
        $stillExists = (int)$verifyStmt->fetchColumn();

        if ($stillExists > 0) {
            throw new Exception("Order deletion failed verification. Order still exists in database.");
        }

        // Step 11: Commit transaction
        $db->commit();

        // Step 12: Comprehensive logging after successful deletion
        $logMessage = sprintf(
            "Order #%d deleted successfully by user #%d (%s). Details: Item: %s, Quantity: %d, Status: %s, Supplier: %s, Variation: %s, Unit Type: %s, Unit Price: %s, Order Date: %s, Delivery Count: %d",
            $order_id,
            $user_id,
            $username,
            $orderDetails['item_name'] ?? 'N/A',
            $order_quantity,
            $order_status,
            $orderDetails['supplier_name'] ?? 'N/A',
            $variation ?? 'None',
            $unit_type,
            number_format((float)($orderDetails['unit_price'] ?? 0), 2),
            $orderDetails['order_date'] ?? 'N/A',
            $delivery_count
        );

        audit_log_event($db, 'order_deletion', 'order', $order_id, 'deleted', true, $logMessage, [
            'source' => 'admin_ui',
            'target' => 'admin_orders',
            'order_id' => $order_id,
            'user_id' => $user_id,
            'username' => $username,
            'deleted_by' => $username,
            'order_details' => json_encode($orderDataForLog),
            'status_before' => $order_status,
            'status_after' => 'deleted',
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        // Step 13: Return success response
        ob_clean();
        echo json_encode([
            'success' => true,
            'message' => "Order #{$order_id} was deleted successfully.",
            'order_id' => $order_id,
            'item_name' => $orderDetails['item_name'] ?? 'Unknown',
            'rows_deleted' => $rowsDeleted,
            'deleted_at' => date('Y-m-d H:i:s'),
            'deleted_by' => $username
        ]);
        ob_end_flush();

    } catch (Exception $e) {
        // Rollback transaction on error
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        // Log the error
        $errorMessage = sprintf(
            "Order deletion failed for order #%d by user #%d (%s). Error: %s",
            $order_id,
            $user_id,
            $username,
            $e->getMessage()
        );

        audit_log_event($db, 'order_deletion', 'order', $order_id, 'failed', false, $errorMessage, [
            'source' => 'admin_ui',
            'target' => 'admin_orders',
            'order_id' => $order_id,
            'user_id' => $user_id,
            'username' => $username,
            'error' => $e->getMessage(),
            'order_details' => json_encode($orderDataForLog),
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        ob_clean();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error deleting order: ' . $e->getMessage(),
            'error_code' => 'DELETION_FAILED',
            'order_id' => $order_id
        ]);
        ob_end_flush();
    }

} catch (PDOException $e) {
    // Database connection error
    ob_clean();
    error_log("Database error in delete_order.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection error. Please try again later.',
        'error_code' => 'DATABASE_ERROR',
        'debug' => (defined('DEBUG') && DEBUG) ? $e->getMessage() : null
    ]);
    ob_end_flush();
    exit;
} catch (Exception $e) {
    // General error
    ob_clean();
    error_log("General error in delete_order.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected error occurred. Please check the logs.',
        'error_code' => 'UNEXPECTED_ERROR',
        'debug' => (defined('DEBUG') && DEBUG) ? $e->getMessage() : null
    ]);
    ob_end_flush();
    exit;
} catch (Throwable $e) {
    // Catch any other errors (Error, TypeError, etc.)
    ob_clean();
    error_log("Fatal error in delete_order.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'A fatal error occurred. Please check the logs.',
        'error_code' => 'FATAL_ERROR',
        'debug' => (defined('DEBUG') && DEBUG) ? $e->getMessage() : null
    ]);
    ob_end_flush();
    exit;
}
