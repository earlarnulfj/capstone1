<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';
require_once '../config/session.php';
require_once '../models/notification.php';
require_once '../models/supplier.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();
$notification = new Notification($db);
$supplier = new Supplier($db);

// Security function to validate supplier session
function validateSupplierSession($db) {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        return false;
    }
    
    if ($_SESSION['role'] !== 'supplier') {
        return false;
    }
    
    // Verify supplier exists in database
    $query = "SELECT id FROM suppliers WHERE id = :supplier_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':supplier_id', $_SESSION['user_id']);
    $stmt->execute();
    
    return $stmt->rowCount() > 0;
}

// Security function to sanitize input
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

// Main API handler
try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'POST':
            // Validate supplier session
            if (!validateSupplierSession($db)) {
                http_response_code(401);
                echo json_encode([
                    'success' => false,
                    'message' => 'Unauthorized: Invalid supplier session'
                ]);
                exit();
            }
            
            // Get and validate input data
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                $input = $_POST;
            }
            
            $action = sanitizeInput($input['action'] ?? '');
            
            switch ($action) {
                case 'send_notification':
                    // Validate required fields
                    $required_fields = ['type', 'message', 'priority'];
                    foreach ($required_fields as $field) {
                        if (empty($input[$field])) {
                            http_response_code(400);
                            echo json_encode([
                                'success' => false,
                                'message' => "Missing required field: {$field}"
                            ]);
                            exit();
                        }
                    }
                    
                    // Sanitize input data
                    $type = sanitizeInput($input['type']);
                    $message = sanitizeInput($input['message']);
                    $priority = sanitizeInput($input['priority']);
                    $order_id = !empty($input['order_id']) ? sanitizeInput($input['order_id']) : null;
                    $supplier_id = $_SESSION['user_id'];
                    
                    // Validate notification type
                    $allowed_types = [
                        'order_confirmation',
                        'delivery_status_update',
                        'inventory_update',
                        'delivery_arrival',
                        'delivery_confirmation',
                        'supplier_message'
                    ];
                    
                    if (!in_array($type, $allowed_types)) {
                        http_response_code(400);
                        echo json_encode([
                            'success' => false,
                            'message' => 'Invalid notification type'
                        ]);
                        exit();
                    }
                    
                    // Get supplier information for context
                    $supplier_query = "SELECT name, company_name FROM suppliers WHERE id = :supplier_id";
                    $supplier_stmt = $db->prepare($supplier_query);
                    $supplier_stmt->bindParam(':supplier_id', $supplier_id);
                    $supplier_stmt->execute();
                    $supplier_info = $supplier_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $supplier_name = $supplier_info['company_name'] ?? $supplier_info['name'] ?? 'Unknown Supplier';
                    
                    // Enhance message with supplier context
                    $enhanced_message = "[{$supplier_name}] {$message}";
                    
                    // Create notification for admin/management
                    $notification->type = $type;
                    $notification->channel = 'web';
                    $notification->recipient_type = 'management';
                    $notification->recipient_id = null; // Broadcast to all management users
                    $notification->order_id = $order_id;
                    $notification->alert_id = null;
                    $notification->message = $enhanced_message;
                    $notification->status = 'sent';
                    
                    $notification_id = $notification->create();
                    
                    if ($notification_id) {
                        // Log the notification relay for audit purposes
                        $audit_query = "INSERT INTO notification_audit (notification_id, supplier_id, action, timestamp) 
                                       VALUES (:notification_id, :supplier_id, 'relay_to_admin', NOW())";
                        $audit_stmt = $db->prepare($audit_query);
                        $audit_stmt->bindParam(':notification_id', $notification_id);
                        $audit_stmt->bindParam(':supplier_id', $supplier_id);
                        $audit_stmt->execute();
                        
                        echo json_encode([
                            'success' => true,
                            'message' => 'Notification sent to admin successfully',
                            'notification_id' => $notification_id
                        ]);
                    } else {
                        http_response_code(500);
                        echo json_encode([
                            'success' => false,
                            'message' => 'Failed to create notification'
                        ]);
                    }
                    break;
                    
                case 'get_notification_status':
                    $notification_id = sanitizeInput($input['notification_id'] ?? '');
                    
                    if (empty($notification_id)) {
                        http_response_code(400);
                        echo json_encode([
                            'success' => false,
                            'message' => 'Missing notification ID'
                        ]);
                        exit();
                    }
                    
                    // Get notification status
                    $status_query = "SELECT status, sent_at FROM notifications WHERE id = :notification_id";
                    $status_stmt = $db->prepare($status_query);
                    $status_stmt->bindParam(':notification_id', $notification_id);
                    $status_stmt->execute();
                    
                    if ($status_stmt->rowCount() > 0) {
                        $status_data = $status_stmt->fetch(PDO::FETCH_ASSOC);
                        echo json_encode([
                            'success' => true,
                            'status' => $status_data['status'],
                            'sent_at' => $status_data['sent_at']
                        ]);
                    } else {
                        http_response_code(404);
                        echo json_encode([
                            'success' => false,
                            'message' => 'Notification not found'
                        ]);
                    }
                    break;
                    
                case 'notify_post_completion_click':
                    $order_id = !empty($input['order_id']) ? (int)sanitizeInput($input['order_id']) : 0;
                    $action_name = sanitizeInput($input['action_name'] ?? '');
                    $supplier_id = (int)($_SESSION['user_id'] ?? 0);
                    if (!$order_id || $action_name === '') {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'Missing order_id or action_name']);
                        exit();
                    }
                    // Verify order belongs to supplier and is completed
                    $ord = $db->prepare("SELECT supplier_id, confirmation_status FROM orders WHERE id = :oid");
                    $ord->bindValue(':oid', $order_id, PDO::PARAM_INT);
                    $ord->execute();
                    $row = $ord->fetch(PDO::FETCH_ASSOC);
                    if (!$row || (int)$row['supplier_id'] !== $supplier_id) {
                        http_response_code(403);
                        echo json_encode(['success' => false, 'message' => 'Unauthorized order access']);
                        exit();
                    }
                    if (strtolower($row['confirmation_status']) !== 'completed') {
                        // Not in completed chain; ignore silently
                        echo json_encode(['success' => true, 'message' => 'Order not completed; no notification sent', 'ignored' => true]);
                        exit();
                    }
                    // Build message and create notification with duplicate prevention
                    $supplier_query = "SELECT name, company_name FROM suppliers WHERE id = :supplier_id";
                    $supplier_stmt = $db->prepare($supplier_query);
                    $supplier_stmt->bindParam(':supplier_id', $supplier_id);
                    $supplier_stmt->execute();
                    $supplier_info = $supplier_stmt->fetch(PDO::FETCH_ASSOC);
                    $supplier_name = $supplier_info['company_name'] ?? $supplier_info['name'] ?? 'Unknown Supplier';

                    $notification->type = 'order_status_update';
                    $notification->channel = 'in_app';
                    $notification->recipient_type = 'management';
                    $notification->recipient_id = 1; // default admin id
                    $notification->order_id = $order_id;
                    $notification->alert_id = null;
                    $notification->message = "[{$supplier_name}] Post-completion activity: " . $action_name . " on Order #" . $order_id;
                    $notification->status = 'sent';

                    $created = $notification->createWithDuplicateCheck(true, 30);
                    if ($created === false) {
                        echo json_encode(['success' => true, 'message' => 'Duplicate suppressed', 'deduped' => true]);
                        exit();
                    }
                    // Audit log
                    try {
                        $log = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message) VALUES (?,?,?,?,?,?,?,?,?,?)");
                        $log->execute(['notification_chain','supplier_ui','admin_system',$order_id,null,'completed','completed',1,'Post-completion click notification: ' . $action_name]);
                    } catch (Throwable $e) {}
                    echo json_encode(['success' => true, 'notification_id' => $created]);
                    break;
                    
                default:
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Invalid action'
                    ]);
                    break;
            }
            break;
            
        case 'GET':
            // Validate supplier session for GET requests
            if (!validateSupplierSession($db)) {
                http_response_code(401);
                echo json_encode([
                    'success' => false,
                    'message' => 'Unauthorized: Invalid supplier session'
                ]);
                exit();
            }
            
            $action = sanitizeInput($_GET['action'] ?? '');
            
            switch ($action) {
                case 'get_recent_notifications':
                    $supplier_id = $_SESSION['user_id'];
                    $limit = min(50, max(1, intval($_GET['limit'] ?? 10)));
                    
                    // Get recent notifications sent by this supplier
                    $recent_query = "SELECT n.*, 
                                           CASE WHEN n.status = 'read' THEN 'delivered' 
                                                WHEN n.status = 'sent' THEN 'pending' 
                                                ELSE 'failed' END as delivery_status
                                    FROM notifications n
                                    WHERE n.type IN ('order_confirmation', 'delivery_status_update', 'inventory_update', 'delivery_arrival', 'delivery_confirmation', 'supplier_message')
                                    AND n.message LIKE CONCAT('%[', (SELECT COALESCE(company_name, name) FROM suppliers WHERE id = :supplier_id), ']%')
                                    ORDER BY n.sent_at DESC
                                    LIMIT :limit";
                    
                    $recent_stmt = $db->prepare($recent_query);
                    $recent_stmt->bindParam(':supplier_id', $supplier_id);
                    $recent_stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                    $recent_stmt->execute();
                    
                    $notifications = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    echo json_encode([
                        'success' => true,
                        'notifications' => $notifications,
                        'count' => count($notifications)
                    ]);
                    break;
                    
                default:
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Invalid action for GET request'
                    ]);
                    break;
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'message' => 'Method not allowed'
            ]);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error: ' . $e->getMessage()
    ]);
}
?>