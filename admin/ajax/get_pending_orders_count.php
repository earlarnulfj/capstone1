<?php
// AJAX endpoint to get pending orders count
include_once '../../config/session.php';
require_once '../../config/database.php';

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
    
    // Get pending orders count from admin_orders
    $pendingStmt = $db->prepare("SELECT COUNT(*) as count FROM admin_orders WHERE confirmation_status = 'pending'");
    $pendingStmt->execute();
    $pendingResult = $pendingStmt->fetch(PDO::FETCH_ASSOC);
    $pendingOrdersCount = (int)($pendingResult['count'] ?? 0);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'pending_orders_count' => $pendingOrdersCount
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error', 'message' => $e->getMessage()]);
}

