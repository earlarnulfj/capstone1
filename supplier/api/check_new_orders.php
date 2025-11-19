<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Include database and order files
include_once '../../config/database.php';
include_once '../../models/order.php';

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Initialize order object
$order = new Order($db);

// Get supplier ID from session or parameter
session_start();
$supplier_id = isset($_GET['supplier_id']) ? $_GET['supplier_id'] : (isset($_SESSION['supplier_id']) ? $_SESSION['supplier_id'] : null);

if (!$supplier_id) {
    http_response_code(400);
    echo json_encode(array("message" => "Supplier ID is required"));
    exit;
}

// Get last check timestamp from parameter (for checking new orders since last check)
$last_check = isset($_GET['last_check']) ? $_GET['last_check'] : date('Y-m-d H:i:s', strtotime('-1 hour'));

try {
    // Query for new orders since last check
    $query = "SELECT COUNT(*) as new_count 
              FROM orders o 
              WHERE o.supplier_id = :supplier_id 
              AND o.order_date > :last_check 
              AND o.confirmation_status = 'pending'";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':supplier_id', $supplier_id);
    $stmt->bindParam(':last_check', $last_check);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $new_orders_count = $result['new_count'];
    
    // Get total pending orders count
    $query_total = "SELECT COUNT(*) as total_pending 
                    FROM orders o 
                    WHERE o.supplier_id = :supplier_id 
                    AND o.confirmation_status = 'pending'";
    
    $stmt_total = $db->prepare($query_total);
    $stmt_total->bindParam(':supplier_id', $supplier_id);
    $stmt_total->execute();
    
    $result_total = $stmt_total->fetch(PDO::FETCH_ASSOC);
    $total_pending = $result_total['total_pending'];
    
    // Response
    http_response_code(200);
    echo json_encode(array(
        "new_orders_count" => (int)$new_orders_count,
        "total_pending_orders" => (int)$total_pending,
        "has_new_orders" => $new_orders_count > 0,
        "timestamp" => date('Y-m-d H:i:s')
    ));
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array("message" => "Error checking for new orders: " . $e->getMessage()));
}
?>