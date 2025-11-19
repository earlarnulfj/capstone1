<?php
// AJAX endpoint to get alert IDs for a specific inventory item and variation
include_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../models/alert_log.php';

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
    $alert = new AlertLog($db);
    
    $inventoryId = isset($_GET['inventory_id']) ? intval($_GET['inventory_id']) : 0;
    $variation = isset($_GET['variation']) ? trim($_GET['variation']) : null;
    
    if ($inventoryId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid inventory ID']);
        exit();
    }
    
    // Build query to find matching unresolved alerts
    $sql = "SELECT id FROM alert_logs 
            WHERE inventory_id = :inv_id 
              AND is_resolved = 0";
    
    $params = [':inv_id' => $inventoryId];
    
    if ($variation !== null && $variation !== '') {
        $sql .= " AND variation = :variation";
        $params[':variation'] = $variation;
    } else {
        $sql .= " AND (variation IS NULL OR variation = '' OR variation = 'null')";
    }
    
    $sql .= " ORDER BY alert_date DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    $alertIds = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $alertIds[] = (int)$row['id'];
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'alert_ids' => $alertIds,
        'count' => count($alertIds)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error', 'message' => $e->getMessage()]);
}

