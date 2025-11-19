<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/webhook.php';
header('Content-Type: application/json; charset=utf-8');

function respond($code, $payload) {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

try {
    // Validate signature
    $rawBody = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
    global $WEBHOOK_SECRET;
    if (!$WEBHOOK_SECRET || $WEBHOOK_SECRET === 'CHANGE_ME_32_CHAR_SECRET') {
        error_log('Webhook secret is not set properly');
    }
    $expected = hash_hmac('sha256', $rawBody ?: '', $WEBHOOK_SECRET);
    if (!hash_equals($expected, $signature)) {
        respond(401, ['error' => 'Invalid signature']);
    }

    $data = json_decode($rawBody, true);
    if (!is_array($data)) {
        respond(400, ['error' => 'Invalid JSON']);
    }

    $orderId = isset($data['order_id']) ? (int)$data['order_id'] : 0;
    $status = isset($data['status']) ? strtolower(trim($data['status'])) : '';
    $sourceSystem = isset($data['source_system']) ? trim($data['source_system']) : 'external_system';

    if ($orderId <= 0 || $status !== 'delivered') {
        respond(400, ['error' => 'Missing order_id or invalid status']);
    }

    $db = (new Database())->getConnection();
    $db->beginTransaction();

    // Validate order existence
    $stmt = $db->prepare('SELECT id, confirmation_status FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        $db->rollBack();
        // Log failed sync attempt
        $log = $db->prepare('INSERT INTO sync_events (event_type, source_system, target_system, order_id, status_before, status_after, success, message) VALUES (?,?,?,?,?,?,?,?)');
        $log->execute(['delivery_status_update', $sourceSystem, 'admin_system', $orderId, null, 'delivered', 0, 'Order not found']);
        respond(404, ['error' => 'Order not found']);
    }

    $before = $order['confirmation_status'];

    // Update orders to delivered
    $stmt = $db->prepare('UPDATE orders SET confirmation_status = "delivered", confirmation_date = IFNULL(confirmation_date, NOW()) WHERE id = ?');
    $stmt->execute([$orderId]);

    // Update deliveries status to delivered if any delivery exists (optional, keeps data consistent)
    $stmt = $db->prepare('UPDATE deliveries SET status = "delivered", updated_at = NOW() WHERE order_id = ?');
    $stmt->execute([$orderId]);

    // Log success
    $log = $db->prepare('INSERT INTO sync_events (event_type, source_system, target_system, order_id, status_before, status_after, success, message) VALUES (?,?,?,?,?,?,?,?)');
    $log->execute(['delivery_status_update', $sourceSystem, 'admin_system', $orderId, $before, 'delivered', 1, 'Webhook: order marked delivered']);

    $db->commit();
    respond(200, ['ok' => true]);
} catch (Exception $e) {
    // Best-effort file log
    try {
        $logPath = __DIR__ . '/../logs/sync.log';
        if (!is_dir(dirname($logPath))) {
            @mkdir(dirname($logPath), 0777, true);
        }
        @file_put_contents($logPath, date('c') . ' webhook error: ' . $e->getMessage() . "\n", FILE_APPEND);
    } catch (Throwable $ignored) {}
    respond(500, ['error' => 'Internal error']);
}