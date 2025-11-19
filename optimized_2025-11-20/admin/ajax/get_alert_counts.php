<?php
include_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../models/alert_log.php';

requireManagementAuth();

try {
    $db = (new Database())->getConnection();
    $alert = new AlertLog($db);

    $activeCount = 0;
    try {
        require_once __DIR__ . '/calculate_active_stock_alerts.php';
        if (function_exists('calculateActiveStockAlerts')) {
            $activeCount = (int)calculateActiveStockAlerts($db);
        }
    } catch (Throwable $t) {
        $activeCount = 0;
    }

    $_SESSION['active_stock_alerts_count'] = $activeCount;

    $totalAlerts = (int)$alert->getCount();

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
    } catch (Throwable $t) {
        $variationCount = 0;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'active_stock_alerts' => $activeCount,
        'total_alerts' => $totalAlerts,
        'variation_count' => $variationCount,
    ]);
} catch (Throwable $t) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}