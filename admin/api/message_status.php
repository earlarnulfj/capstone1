<?php
header('Content-Type: application/json');
require_once '../../config/database.php';

$db = (new Database())->getConnection();

// Create message status table if it doesn't exist
$createTableQuery = "
CREATE TABLE IF NOT EXISTS chat_message_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    status ENUM('sent', 'delivered', 'read') NOT NULL DEFAULT 'sent',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_message_status (message_id),
    FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    INDEX idx_message_id (message_id),
    INDEX idx_status (status)
)";

try {
    $db->exec($createTableQuery);
} catch (PDOException $e) {
    error_log("Error creating chat_message_status table: " . $e->getMessage());
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'POST':
            handleUpdateMessageStatus($db);
            break;
        case 'GET':
            handleGetMessageStatus($db);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    error_log("Message Status API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}

function handleUpdateMessageStatus($db) {
    $message_id = $_POST['message_id'] ?? null;
    $status = $_POST['status'] ?? 'sent';
    
    if (!$message_id) {
        echo json_encode(['success' => false, 'message' => 'Message ID required']);
        return;
    }
    
    // Validate status
    $validStatuses = ['sent', 'delivered', 'read'];
    if (!in_array($status, $validStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        return;
    }
    
    try {
        // Insert or update message status
        $stmt = $db->prepare("
            INSERT INTO chat_message_status (message_id, status, updated_at) 
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
            status = CASE 
                WHEN VALUES(status) = 'read' THEN 'read'
                WHEN VALUES(status) = 'delivered' AND status != 'read' THEN 'delivered'
                WHEN VALUES(status) = 'sent' AND status = 'sent' THEN 'sent'
                ELSE status
            END,
            updated_at = NOW()
        ");
        $stmt->execute([$message_id, $status]);
        
        echo json_encode(['success' => true, 'message' => 'Message status updated', 'status' => $status]);
        
    } catch (PDOException $e) {
        error_log("Database error in handleUpdateMessageStatus: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}

function handleGetMessageStatus($db) {
    $supplier_id = $_GET['supplier_id'] ?? null;
    $message_ids = $_GET['message_ids'] ?? null;
    
    if (!$supplier_id && !$message_ids) {
        echo json_encode(['success' => false, 'message' => 'Supplier ID or message IDs required']);
        return;
    }
    
    try {
        if ($message_ids) {
            // Get status for specific messages
            $messageIdArray = explode(',', $message_ids);
            $placeholders = str_repeat('?,', count($messageIdArray) - 1) . '?';
            
            $stmt = $db->prepare("
                SELECT cms.message_id, cms.status, cms.updated_at
                FROM chat_message_status cms
                WHERE cms.message_id IN ($placeholders)
            ");
            $stmt->execute($messageIdArray);
        } else {
            // Get status for all messages in a conversation
            $stmt = $db->prepare("
                SELECT cms.message_id, cms.status, cms.updated_at
                FROM chat_message_status cms
                INNER JOIN chat_messages cm ON cms.message_id = cm.id
                WHERE cm.supplier_id = ?
                ORDER BY cm.created_at DESC
                LIMIT 50
            ");
            $stmt->execute([$supplier_id]);
        }
        
        $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true, 
            'statuses' => $statuses,
            'count' => count($statuses)
        ]);
        
    } catch (PDOException $e) {
        error_log("Database error in handleGetMessageStatus: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}

function markMessagesAsDelivered($db, $supplier_id, $sender_type) {
    try {
        // Mark all unread messages from the other party as delivered
        $otherSenderType = $sender_type === 'admin' ? 'supplier' : 'admin';
        
        $stmt = $db->prepare("
            INSERT INTO chat_message_status (message_id, status, updated_at)
            SELECT cm.id, 'delivered', NOW()
            FROM chat_messages cm
            LEFT JOIN chat_message_status cms ON cm.id = cms.message_id
            WHERE cm.supplier_id = ? 
            AND cm.sender_type = ?
            AND (cms.status IS NULL OR cms.status = 'sent')
            ON DUPLICATE KEY UPDATE 
            status = CASE WHEN status = 'sent' THEN 'delivered' ELSE status END,
            updated_at = NOW()
        ");
        $stmt->execute([$supplier_id, $otherSenderType]);
        
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log("Error marking messages as delivered: " . $e->getMessage());
        return 0;
    }
}

function markMessagesAsRead($db, $supplier_id, $sender_type) {
    try {
        // Mark all messages from the other party as read
        $otherSenderType = $sender_type === 'admin' ? 'supplier' : 'admin';
        
        $stmt = $db->prepare("
            INSERT INTO chat_message_status (message_id, status, updated_at)
            SELECT cm.id, 'read', NOW()
            FROM chat_messages cm
            WHERE cm.supplier_id = ? 
            AND cm.sender_type = ?
            ON DUPLICATE KEY UPDATE 
            status = 'read',
            updated_at = NOW()
        ");
        $stmt->execute([$supplier_id, $otherSenderType]);
        
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log("Error marking messages as read: " . $e->getMessage());
        return 0;
    }
}

// Auto-mark messages as delivered when the API is accessed
if ($method === 'GET' && isset($_GET['supplier_id']) && isset($_GET['mark_delivered'])) {
    $sender_type = $_GET['sender_type'] ?? 'admin';
    markMessagesAsDelivered($db, $_GET['supplier_id'], $sender_type);
}

// Auto-mark messages as read when specifically requested
if ($method === 'POST' && isset($_POST['mark_read']) && isset($_POST['supplier_id'])) {
    $sender_type = $_POST['sender_type'] ?? 'admin';
    $readCount = markMessagesAsRead($db, $_POST['supplier_id'], $sender_type);
    echo json_encode(['success' => true, 'message' => 'Messages marked as read', 'count' => $readCount]);
    exit;
}
?>