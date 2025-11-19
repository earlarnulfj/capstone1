<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Include database and order files
include_once '../../config/database.php';
include_once '../../models/order.php';

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Initialize order object
$order = new Order($db);

// Get posted data
$data = json_decode(file_get_contents("php://input"));

// Check if required data is provided
if (!empty($data->order_id) && !empty($data->status)) {
    
    // Validate status
    $valid_statuses = ['pending', 'confirmed', 'cancelled'];
    if (!in_array($data->status, $valid_statuses)) {
        http_response_code(400);
        echo json_encode(array("message" => "Invalid status. Must be: pending, confirmed, or cancelled"));
        exit;
    }
    
    // Update order status
    if ($order->updateStatus($data->order_id, $data->status)) {
        http_response_code(200);
        echo json_encode(array(
            "message" => "Order status updated successfully",
            "order_id" => $data->order_id,
            "new_status" => $data->status
        ));
    } else {
        http_response_code(503);
        echo json_encode(array("message" => "Unable to update order status"));
    }
} else {
    http_response_code(400);
    echo json_encode(array("message" => "Incomplete data. Order ID and status are required"));
}
?>