<?php
header('Content-Type: application/json');
require_once '../../config/database.php';

// Create chat_messages table if it doesn't exist
$db = (new Database())->getConnection();

$createTableQuery = "
CREATE TABLE IF NOT EXISTS chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    admin_id INT DEFAULT NULL,
    sender_type ENUM('admin', 'supplier') NOT NULL,
    sender_name VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_supplier_id (supplier_id),
    INDEX idx_created_at (created_at),
    INDEX idx_is_read (is_read)
)";

try {
    $db->exec($createTableQuery);
} catch (PDOException $e) {
    error_log("Error creating chat_messages table: " . $e->getMessage());
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGetMessages($db);
            break;
        case 'POST':
            handleSendMessage($db);
            break;
        case 'DELETE':
            handleClearHistory($db);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    error_log("Chat API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}

function handleGetMessages($db) {
    $supplier_id = $_GET['supplier_id'] ?? null;
    
    if (!$supplier_id) {
        echo json_encode(['success' => false, 'message' => 'Supplier ID required']);
        return;
    }
    
    try {
        $stmt = $db->prepare("
            SELECT cm.id, cm.supplier_id, cm.admin_id, cm.sender_type, cm.sender_name, cm.message, 
                   DATE_FORMAT(cm.created_at, '%Y-%m-%d %H:%i:%s') as created_at,
                   COALESCE(cms.status, 'sent') as status,
                   DATE_FORMAT(cms.updated_at, '%Y-%m-%d %H:%i:%s') as status_updated_at
            FROM chat_messages cm
            LEFT JOIN chat_message_status cms ON cm.id = cms.message_id
            WHERE cm.supplier_id = ?
            ORDER BY cm.created_at ASC
        ");
        $stmt->execute([$supplier_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Mark supplier messages as read for admin
        $updateStmt = $db->prepare("
            UPDATE chat_messages 
            SET is_read = TRUE 
            WHERE supplier_id = ? AND sender_type = 'supplier' AND is_read = FALSE
        ");
        $updateStmt->execute([$supplier_id]);
        
        echo json_encode([
            'success' => true, 
            'messages' => $messages,
            'count' => count($messages)
        ]);
        
    } catch (PDOException $e) {
        error_log("Database error in handleGetMessages: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}

function handleSendMessage($db) {
    $supplier_id = $_POST['supplier_id'] ?? null;
    $message = trim($_POST['message'] ?? '');
    $sender_type = $_POST['sender_type'] ?? 'admin';
    
    if (!$supplier_id || !$message) {
        echo json_encode(['success' => false, 'message' => 'Supplier ID and message required']);
        return;
    }
    
    if (strlen($message) > 500) {
        echo json_encode(['success' => false, 'message' => 'Message too long (max 500 characters)']);
        return;
    }
    
    try {
        // Get sender name
        if ($sender_type === 'admin') {
            // For admin, we can use a default admin name or get from session
            session_start();
            $admin_id = $_SESSION['admin']['user_id'] ?? 1;
            $stmt = $db->prepare("SELECT username FROM users WHERE id = ? AND role = 'admin'");
            $stmt->execute([$admin_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $sender_name = $user ? $user['username'] : 'Admin';
        } else {
            // This would be called from supplier side
            $stmt = $db->prepare("SELECT username, name FROM suppliers WHERE id = ? AND status = 'active'");
            $stmt->execute([$supplier_id]);
            $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$supplier) {
                echo json_encode(['success' => false, 'message' => 'Invalid supplier']);
                return;
            }
            
            $sender_name = $supplier['name'] ?: $supplier['username'];
            $admin_id = null;
        }
        
        // Insert message
        $stmt = $db->prepare("
            INSERT INTO chat_messages (supplier_id, admin_id, sender_type, sender_name, message, is_read) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $is_read = ($sender_type === 'admin') ? false : true; // Admin messages start as unread for supplier
        $stmt->execute([$supplier_id, $admin_id ?? null, $sender_type, $sender_name, $message, $is_read]);
        
        $message_id = $db->lastInsertId();
        
        // Initialize message status as 'sent'
        try {
            $statusStmt = $db->prepare("
                INSERT INTO chat_message_status (message_id, status, updated_at) 
                VALUES (?, 'sent', NOW())
            ");
            $statusStmt->execute([$message_id]);
        } catch (PDOException $e) {
            error_log("Error setting initial message status: " . $e->getMessage());
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Message sent successfully',
            'message_id' => $message_id,
            'status' => 'sent'
        ]);
        
    } catch (PDOException $e) {
        error_log("Database error in handleSendMessage: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to send message']);
    }
}

function handleClearHistory($db) {
    // Handle JSON input for DELETE requests
    $input = json_decode(file_get_contents('php://input'), true);
    $supplier_id = $input['supplier_id'] ?? $_REQUEST['supplier_id'] ?? null;
    
    if (!$supplier_id) {
        echo json_encode(['success' => false, 'message' => 'Supplier ID required']);
        return;
    }
    
    try {
        $stmt = $db->prepare("DELETE FROM chat_messages WHERE supplier_id = ?");
        $stmt->execute([$supplier_id]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Chat history cleared successfully',
            'deleted_count' => $stmt->rowCount()
        ]);
        
    } catch (PDOException $e) {
        error_log("Database error in handleClearHistory: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to clear chat history']);
    }
}
?>