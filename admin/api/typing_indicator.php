<?php
header('Content-Type: application/json');
require_once '../../config/database.php';

$db = (new Database())->getConnection();

// Create typing indicators table if it doesn't exist
$createTableQuery = "
CREATE TABLE IF NOT EXISTS chat_typing_indicators (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    sender_type ENUM('admin', 'supplier') NOT NULL,
    sender_name VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_typing (supplier_id, sender_type),
    INDEX idx_supplier_id (supplier_id),
    INDEX idx_updated_at (updated_at)
)";

try {
    $db->exec($createTableQuery);
} catch (PDOException $e) {
    error_log("Error creating chat_typing_indicators table: " . $e->getMessage());
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'POST':
            handleTypingIndicator($db);
            break;
        case 'DELETE':
            handleStopTyping($db);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    error_log("Typing Indicator API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}

function handleTypingIndicator($db) {
    $supplier_id = $_POST['supplier_id'] ?? null;
    $sender_type = $_POST['sender_type'] ?? 'admin';
    $sender_name = $_POST['sender_name'] ?? '';
    
    if (!$supplier_id || !$sender_name) {
        echo json_encode(['success' => false, 'message' => 'Supplier ID and sender name required']);
        return;
    }
    
    try {
        // Insert or update typing indicator
        $stmt = $db->prepare("
            INSERT INTO chat_typing_indicators (supplier_id, sender_type, sender_name, updated_at) 
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
            sender_name = VALUES(sender_name),
            updated_at = NOW()
        ");
        $stmt->execute([$supplier_id, $sender_type, $sender_name]);
        
        echo json_encode(['success' => true, 'message' => 'Typing indicator updated']);
        
    } catch (PDOException $e) {
        error_log("Database error in handleTypingIndicator: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}

function handleStopTyping($db) {
    $supplier_id = $_REQUEST['supplier_id'] ?? null;
    $sender_type = $_REQUEST['sender_type'] ?? 'admin';
    
    if (!$supplier_id) {
        echo json_encode(['success' => false, 'message' => 'Supplier ID required']);
        return;
    }
    
    try {
        $stmt = $db->prepare("
            DELETE FROM chat_typing_indicators 
            WHERE supplier_id = ? AND sender_type = ?
        ");
        $stmt->execute([$supplier_id, $sender_type]);
        
        echo json_encode(['success' => true, 'message' => 'Typing indicator removed']);
        
    } catch (PDOException $e) {
        error_log("Database error in handleStopTyping: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
}

// Clean up old typing indicators (older than 10 seconds)
try {
    $cleanupStmt = $db->prepare("
        DELETE FROM chat_typing_indicators 
        WHERE updated_at < DATE_SUB(NOW(), INTERVAL 10 SECOND)
    ");
    $cleanupStmt->execute();
} catch (PDOException $e) {
    error_log("Error cleaning up typing indicators: " . $e->getMessage());
}
?>