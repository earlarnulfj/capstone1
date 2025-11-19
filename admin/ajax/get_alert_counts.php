<?php
// AJAX endpoint to get alert and variation counts
include_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../models/alert_log.php';
require_once '../../models/inventory.php';
require_once '../../models/inventory_variation.php';

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

    // Calculate Active Stock Alerts count directly (not just from session)
    // This ensures the count is always accurate even if alerts.php hasn't been visited
    require_once __DIR__ . '/calculate_active_stock_alerts.php';
    $activeStockAlerts = calculateActiveStockAlerts($db);
    
    // Update session for consistency
    $_SESSION['active_stock_alerts_count'] = $activeStockAlerts;

    // Total alerts (all, regardless of resolved status) - kept for backward compatibility
    $totalAlerts = (int)$alert->getCount();

    // Variation count: number of variations currently low (quantity <= reorder_threshold)
    $variationCount = 0;
    try {
        $stmtCheck = $db->prepare("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'inventory_variations'");
        $stmtCheck->execute();
        $existsRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        $hasVariations = isset($existsRow['cnt']) ? ((int)$existsRow['cnt'] > 0) : false;
        if ($hasVariations) {
            $sqlVarCount = "SELECT COUNT(*) AS vcnt
                            FROM inventory_variations v
                            JOIN inventory i ON v.inventory_id = i.id
                            WHERE v.quantity <= i.reorder_threshold";
            $stmtVar = $db->prepare($sqlVarCount);
            $stmtVar->execute();
            $row = $stmtVar->fetch(PDO::FETCH_ASSOC);
            $variationCount = (int)($row['vcnt'] ?? 0);
        }
    } catch (Exception $e) {
        $variationCount = 0;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'active_stock_alerts' => $activeStockAlerts, // This is the count for Active Stock Alerts
        'total_alerts' => $totalAlerts, // Backward compatibility
        'variation_count' => $variationCount,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}