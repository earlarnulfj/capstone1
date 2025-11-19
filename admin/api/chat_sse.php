<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Cache-Control');

require_once '../../config/database.php';

// Prevent timeout
set_time_limit(0);
ini_set('max_execution_time', 0);

$supplier_id = $_GET['supplier_id'] ?? null;
$last_message_id = $_GET['last_message_id'] ?? 0;

if (!$supplier_id) {
    echo "event: error\n";
    echo "data: " . json_encode(['error' => 'Supplier ID required']) . "\n\n";
    exit;
}

$db = (new Database())->getConnection();

// Function to send SSE data
function sendSSE($event, $data) {
    echo "event: $event\n";
    echo "data: " . json_encode($data) . "\n\n";
    ob_flush();
    flush();
}

// Send initial connection confirmation
sendSSE('connected', ['status' => 'connected', 'supplier_id' => $supplier_id]);

$lastCheck = time();
$heartbeatInterval = 30; // Send heartbeat every 30 seconds

while (true) {
    try {
        // Check for new messages
        $stmt = $db->prepare("
            SELECT id, supplier_id, admin_id, sender_type, sender_name, message, 
                   DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') as created_at,
                   is_read
            FROM chat_messages 
            WHERE supplier_id = ? AND id > ?
            ORDER BY created_at ASC
        ");
        $stmt->execute([$supplier_id, $last_message_id]);
        $newMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($newMessages)) {
            foreach ($newMessages as $message) {
                sendSSE('new_message', $message);
                $last_message_id = max($last_message_id, $message['id']);
            }
        }
        
        // Check for typing indicators
        $stmt = $db->prepare("
            SELECT sender_type, sender_name, updated_at
            FROM chat_typing_indicators 
            WHERE supplier_id = ? AND updated_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)
        ");
        $stmt->execute([$supplier_id]);
        $typingUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($typingUsers)) {
            sendSSE('typing', ['users' => $typingUsers]);
        }
        
        // Send heartbeat periodically
        if (time() - $lastCheck >= $heartbeatInterval) {
            sendSSE('heartbeat', ['timestamp' => time()]);
            $lastCheck = time();
        }
        
        // Check if client disconnected
        if (connection_aborted()) {
            break;
        }
        
        sleep(1); // Check every second
        
    } catch (Exception $e) {
        sendSSE('error', ['error' => 'Server error occurred']);
        error_log("SSE Error: " . $e->getMessage());
        break;
    }
}
?>