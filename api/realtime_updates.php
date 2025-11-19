<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

session_start();
require_once '../config/database.php';
require_once '../models/order.php';
require_once '../models/delivery.php';
require_once '../models/payment.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$database = new Database();
$db = $database->getConnection();
$order = new Order($db);
$delivery = new Delivery($db);
$payment = new Payment($db);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            handleGetRequest($action, $order, $delivery, $payment);
            break;
        case 'POST':
            handlePostRequest($action, $order, $delivery, $payment);
            break;
        case 'PUT':
            handlePutRequest($action, $order, $delivery, $payment);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

function handleGetRequest($action, $order, $delivery, $payment) {
    switch ($action) {
        case 'orders':
            $stmt = $order->readAll();
            $orders = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $orders[] = $row;
            }
            echo json_encode(['success' => true, 'data' => $orders]);
            break;
            
        case 'deliveries':
            $stmt = $delivery->readAll();
            $deliveries = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $deliveries[] = $row;
            }
            echo json_encode(['success' => true, 'data' => $deliveries]);
            break;
            
        case 'payments':
            $stmt = $payment->readAll();
            $payments = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $payments[] = $row;
            }
            echo json_encode(['success' => true, 'data' => $payments]);
            break;
            
        case 'stats':
            $stats = getSystemStats($order, $delivery, $payment);
            echo json_encode(['success' => true, 'data' => $stats]);
            break;
            
        case 'supplier_orders':
            if ($_SESSION['role'] === 'supplier') {
                $supplier_id = $_SESSION['user_id'];
                $stmt = $order->getOrdersBySupplier($supplier_id);
                $orders = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $orders[] = $row;
                }
                echo json_encode(['success' => true, 'data' => $orders]);
            } else {
                http_response_code(403);
                echo json_encode(['error' => 'Access denied']);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
}

function handlePostRequest($action, $order, $delivery, $payment) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'update_order_status':
            if (isset($input['order_id']) && isset($input['status'])) {
                $order->id = $input['order_id'];
                $order->confirmation_status = $input['status'];
                
                if ($order->updateStatus()) {
                    // Log the status change
                    logStatusChange('order', $input['order_id'], $input['status'], $_SESSION['user_id']);
                    echo json_encode(['success' => true, 'message' => 'Order status updated']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to update order status']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
            }
            break;
            
        case 'update_delivery_status':
            if (isset($input['delivery_id']) && isset($input['status'])) {
                $delivery->id = $input['delivery_id'];
                $delivery->status = $input['status'];
                
                if ($delivery->updateStatus()) {
                    // Log the status change
                    logStatusChange('delivery', $input['delivery_id'], $input['status'], $_SESSION['user_id']);
                    echo json_encode(['success' => true, 'message' => 'Delivery status updated']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to update delivery status']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
            }
            break;
            
        case 'process_payment':
            if (isset($input['order_id']) && isset($input['amount']) && isset($input['method'])) {
                $payment->order_id = $input['order_id'];
                $payment->amount = $input['amount'];
                $payment->payment_method = $input['method'];
                $payment->payment_status = 'pending';
                $payment->transaction_reference = generateTransactionReference();
                
                if ($payment_id = $payment->create()) {
                    // Process payment based on method
                    if ($input['method'] === 'gcash') {
                        $result = $payment->processGCashPayment($input['amount'], $payment->transaction_reference);
                        if ($result['success']) {
                            $payment->updateStatus($payment_id, 'completed');
                        }
                    }
                    
                    echo json_encode(['success' => true, 'payment_id' => $payment_id, 'message' => 'Payment processed']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to process payment']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
            }
            break;
            
        case 'cancel_order':
            if (isset($input['order_id']) && isset($input['reason'])) {
                $order->id = $input['order_id'];
                $order->confirmation_status = 'cancelled';
                
                if ($order->updateStatus()) {
                    // Log cancellation
                    logStatusChange('order', $input['order_id'], 'cancelled', $_SESSION['user_id'], $input['reason']);
                    echo json_encode(['success' => true, 'message' => 'Order cancelled successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to cancel order']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
}

function handlePutRequest($action, $order, $delivery, $payment) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'confirm_receipt':
            if (isset($input['order_id'])) {
                $order->id = $input['order_id'];
                $order->confirmation_status = 'completed';
                
                if ($order->updateStatus()) {
                    echo json_encode(['success' => true, 'message' => 'Receipt confirmed']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to confirm receipt']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Missing order ID']);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
}

function getSystemStats($order, $delivery, $payment) {
    $stats = [];
    
    // Order statistics
    $stmt = $order->readAll();
    $orders = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $orders[] = $row;
    }
    
    $stats['orders'] = [
        'total' => count($orders),
        'pending' => count(array_filter($orders, function($o) { return $o['confirmation_status'] === 'pending'; })),
        'confirmed' => count(array_filter($orders, function($o) { return $o['confirmation_status'] === 'confirmed'; })),
        'completed' => count(array_filter($orders, function($o) { return $o['confirmation_status'] === 'completed'; })),
        'cancelled' => count(array_filter($orders, function($o) { return $o['confirmation_status'] === 'cancelled'; }))
    ];
    
    // Delivery statistics
    $stmt = $delivery->readAll();
    $deliveries = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $deliveries[] = $row;
    }
    
    $stats['deliveries'] = [
        'total' => count($deliveries),
        'pending' => count(array_filter($deliveries, function($d) { return $d['status'] === 'pending'; })),
        'in_transit' => count(array_filter($deliveries, function($d) { return $d['status'] === 'in_transit'; })),
        'delivered' => count(array_filter($deliveries, function($d) { return $d['status'] === 'delivered'; }))
    ];
    
    // Payment statistics
    $stmt = $payment->readAll();
    $payments = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $payments[] = $row;
    }
    
    $stats['payments'] = [
        'total' => count($payments),
        'pending' => count(array_filter($payments, function($p) { return $p['payment_status'] === 'pending'; })),
        'completed' => count(array_filter($payments, function($p) { return $p['payment_status'] === 'completed'; })),
        'failed' => count(array_filter($payments, function($p) { return $p['payment_status'] === 'failed'; }))
    ];
    
    return $stats;
}

function logStatusChange($type, $id, $status, $user_id, $reason = null) {
    global $db;
    
    $query = "INSERT INTO status_logs (type, item_id, status, user_id, reason, created_at) 
              VALUES (:type, :item_id, :status, :user_id, :reason, NOW())";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':item_id', $id);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':reason', $reason);
        $stmt->execute();
    } catch (Exception $e) {
        // Log error but don't fail the main operation
        error_log("Failed to log status change: " . $e->getMessage());
    }
}

function generateTransactionReference() {
    return 'TXN' . date('Ymd') . strtoupper(substr(uniqid(), -6));
}
?>