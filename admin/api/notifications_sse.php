<?php
// Server-Sent Events for Admin Notifications (management)
// Streams newly created notifications to the admin UI in real-time

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// Disable output buffering
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', false);
ob_implicit_flush(true);

require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../models/notification.php';

// Auth guard: only management/admin users
if (empty($_SESSION['admin']['user_id']) || (($_SESSION['admin']['role'] ?? null) !== 'management')) {
    http_response_code(401);
    echo "event: error\n";
    echo "data: {\"error\": \"Unauthorized\"}\n\n";
    exit();
}

// Close the session immediately to avoid session locking during long-lived SSE
session_write_close();

$database = new Database();
$db = $database->getConnection();
$notification = new Notification($db);

// Helper to send SSE events
function sseSend($event, $data) {
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data) . "\n\n";
    @ob_flush();
    @flush();
}

// Get last_notification_id from query (fallback to latest id)
$lastId = isset($_GET['last_notification_id']) ? (int)$_GET['last_notification_id'] : 0;
if ($lastId <= 0) {
    try {
        $stmt = $db->query("SELECT IFNULL(MAX(id),0) AS max_id FROM notifications WHERE recipient_type='management'");
        $lastId = (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $lastId = 0;
    }
}

$heartbeatInterval = 25; // seconds
$lastHeartbeat = time();

sseSend('connected', ['status' => 'ok', 'last_id' => $lastId]);

// Main loop
while (true) {
    try {
        // Fetch new notifications for management
        $stmt = $db->prepare("SELECT id, type, channel, recipient_type, recipient_id, order_id, message, status, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at FROM notifications WHERE recipient_type='management' AND id > :lastId ORDER BY id ASC");
        $stmt->bindValue(':lastId', $lastId, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $lastId = max($lastId, (int)$row['id']);
            sseSend('new_notification', $row);
        }

        // Heartbeat to keep connection alive
        if (time() - $lastHeartbeat >= $heartbeatInterval) {
            sseSend('heartbeat', ['ts' => time()]);
            $lastHeartbeat = time();
        }

        // Sleep briefly to avoid tight loop
        usleep(500000); // 0.5s
    } catch (Throwable $e) {
        sseSend('error', ['message' => $e->getMessage()]);
        // Backoff on error
        sleep(2);
    }
}