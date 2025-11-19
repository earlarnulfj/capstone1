<?php
// This API endpoint allows suppliers to confirm orders

include_once '../config/database.php';
include_once '../models/order.php';
include_once '../models/delivery.php';
include_once '../models/notification.php';

header('Content-Type: application/json');

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($data['order_id']) || !isset($data['status'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

$order_id = intval($data['order_id']);
$status = $data['status'];

// Validate status
if (!in_array($status, ['confirmed', 'cancelled'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid status. Must be "confirmed" or "cancelled"']);
    exit;
}

// Update order status
$database = new Database();
$db = $database->getConnection();

$order = new Order($db);
$delivery = new Delivery($db);
$notification = new Notification($db);

// Start transaction
$db->beginTransaction();

try {
    // Update order status
    if ($order->updateStatus($order_id, $status)) {
        // If confirmed, create delivery record
        if ($status === 'confirmed') {
            $delivery->order_id = $order_id;
            $delivery->status = 'pending';
            $delivery->latitude = 0; // Default values
            $delivery->longitude = 0;
            $delivery->replenished_quantity = 0;
            
            $delivery_id = $delivery->create();
            
            if (!$delivery_id) {
                throw new Exception("Failed to create delivery record");
            }
        }
        
        // Get order details for notification
        $order->id = $order_id;
        $order->readOne();
        
        // Create notification for management
        $message = "Order #{$order_id} has been {$status} by the supplier.";
        
        $notification->type = 'order_confirmation';
        $notification->channel = 'system';
        $notification->recipient_type = 'management';
        $notification->recipient_id = 0; // All management users
        $notification->order_id = $order_id;
        $notification->alert_id = null;
        $notification->message = $message;
        $notification->status = 'sent';
        
        $notification->create();
        
        // Commit transaction
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'order_id' => $order_id,
            'status' => $status,
            'delivery_id' => $status === 'confirmed' ? $delivery_id : null,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } else {
        throw new Exception("Failed to update order status");
    }
} catch (Exception $e) {
    // Rollback transaction
    $db->rollBack();
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
