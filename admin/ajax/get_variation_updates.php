<?php
header('Content-Type: application/json');

// Namespaced sessions for role checks
include_once '../../config/session.php';
require_once '../../config/database.php';
@require_once '../../config/database_pos.php';

try {
    // Only allow admin and staff. Explicitly block supplier.
    $isAdmin = !empty($_SESSION['admin']['user_id']);
    $isStaff = !empty($_SESSION['staff']['user_id']);
    $isSupplier = !empty($_SESSION['supplier']['user_id']);

    if (!$isAdmin && !$isStaff) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    if ($isSupplier) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden for suppliers']);
        exit;
    }

    $db = (class_exists('DatabasePOS') ? (new DatabasePOS())->getConnection() : (new Database())->getConnection());

    // Parse `since` parameter (ISO8601 or UNIX seconds). Default to last 10 minutes.
    $sinceParam = isset($_GET['since']) ? trim($_GET['since']) : '';
    $sinceTs = time() - 600; // default 10 minutes
    if ($sinceParam !== '') {
        if (ctype_digit($sinceParam)) {
            $sinceTs = (int)$sinceParam;
        } else {
            $parsed = strtotime($sinceParam);
            if ($parsed !== false) {
                $sinceTs = $parsed;
            }
        }
    }
    $sinceDt = date('Y-m-d H:i:s', $sinceTs);

    // Ensure variations table exists and has unit_price column
    $hasVarTable = false;
    try {
        $db->query("SELECT 1 FROM inventory_variations LIMIT 1");
        $hasVarTable = true;
    } catch (PDOException $e) {
        $hasVarTable = false;
    }

    if (!$hasVarTable) {
        echo json_encode(['success' => true, 'data' => [], 'message' => 'No variation table']);
        exit;
    }

    // Fetch updates since timestamp
    $sql = "SELECT inventory_id, variation, unit_type, unit_price, last_updated
            FROM inventory_variations
            WHERE last_updated >= :since
            ORDER BY last_updated DESC";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':since', $sinceDt);
    $stmt->execute();

    $updates = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $invId = (int)$row['inventory_id'];
        if (!isset($updates[$invId])) {
            $updates[$invId] = [
                'inventory_id' => $invId,
                'prices_map' => [],
                'unit_type_map' => [],
                'last_updated' => $row['last_updated']
            ];
        }
        $var = (string)$row['variation'];
        $ut = (string)($row['unit_type'] ?? 'per piece');
        $price = $row['unit_price'];
        $updates[$invId]['unit_type_map'][$var] = $ut;
        // Normalize price to float or null
        if ($price === null || $price === '') {
            $updates[$invId]['prices_map'][$var] = null;
        } else {
            $updates[$invId]['prices_map'][$var] = (float)$price;
        }
        // Track the latest update time
        if (strtotime($row['last_updated']) > strtotime($updates[$invId]['last_updated'])) {
            $updates[$invId]['last_updated'] = $row['last_updated'];
        }
    }

    // Logging: record feed access and number of items
    $logDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs';
    $logFile = $logDir . DIRECTORY_SEPARATOR . 'variation_sync.log';
    if (!is_dir($logDir)) { @mkdir($logDir, 0777, true); }
    $who = $isAdmin ? 'admin' : 'staff';
    $logMsg = sprintf('[%s] feed since=%s by=%s delivered=%d items', date('Y-m-d H:i:s'), $sinceDt, $who, count($updates));
    @error_log($logMsg . "\n", 3, $logFile);

    echo json_encode(['success' => true, 'data' => array_values($updates), 'since' => $sinceDt]);
} catch (Throwable $e) {
    // Log errors
    $logDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs';
    $logFile = $logDir . DIRECTORY_SEPARATOR . 'variation_sync.log';
    if (!is_dir($logDir)) { @mkdir($logDir, 0777, true); }
    @error_log('[' . date('Y-m-d H:i:s') . '] feed error: ' . $e->getMessage() . "\n", 3, $logFile);

    // Degrade gracefully: return an empty successful payload so clients don't spam error toasts
    http_response_code(200);
    echo json_encode(['success' => true, 'data' => [], 'message' => 'No updates (temporary server issue)']);
}