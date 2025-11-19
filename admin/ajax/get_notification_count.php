<?php
// AJAX endpoint to get unread notification count
include_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../models/notification.php';

// Admin auth guard
if (empty($_SESSION['admin']['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if (($_SESSION['admin']['role'] ?? null) !== 'management') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

try {
    $db = (new Database())->getConnection();
    $notification = new Notification($db);
    
    $unreadCount = $notification->getUnreadCount('management', 1);
    
    header('Content-Type: application/json');
    echo json_encode(['count' => (int)$unreadCount]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?>