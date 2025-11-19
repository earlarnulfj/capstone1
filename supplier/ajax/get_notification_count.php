<?php
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

include_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../models/notification.php';

// Ensure supplier is authenticated
if (empty($_SESSION['supplier']['user_id']) && (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supplier')) {
    echo json_encode(['count' => 0]);
    exit;
}

try {
    $db = (new Database())->getConnection();
    $notification = new Notification($db);
    $supplier_id = (int)($_SESSION['supplier']['user_id'] ?? $_SESSION['user_id']);

    // Direct query to ensure accuracy - only count notifications that actually exist and are unread
    $stmt = $db->prepare("SELECT COUNT(*) as count 
                          FROM notifications 
                          WHERE recipient_type = 'supplier' 
                            AND recipient_id = :supplier_id 
                            AND (is_read = 0 OR is_read IS NULL)
                            AND (status IS NULL OR status != 'read')
                          ");
    $stmt->bindParam(':supplier_id', $supplier_id, PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $count = (int)($result['count'] ?? 0);

    echo json_encode(['count' => $count]);
} catch (Throwable $e) {
    error_log('Supplier get_notification_count error: ' . $e->getMessage());
    echo json_encode(['count' => 0]);
}