<?php
header('Content-Type: application/json');

try {
    require_once '../../config/database.php';
@require_once '../../config/database_pos.php';
require_once '../../models/inventory_variation.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => 'Invalid method']);
        exit;
    }

    $inventoryId = isset($_POST['inventory_id']) ? (int)$_POST['inventory_id'] : 0;
    $unitTypeRaw  = isset($_POST['unit_type']) ? trim(strtolower($_POST['unit_type'])) : 'per piece';
    $keys         = isset($_POST['variation_price_keys']) ? $_POST['variation_price_keys'] : [];
    $vals         = isset($_POST['variation_price_vals']) ? $_POST['variation_price_vals'] : [];

    if (!$inventoryId) {
        echo json_encode(['success' => false, 'error' => 'Missing inventory_id']);
        exit;
    }
    if (!is_array($keys) || !is_array($vals) || count($keys) !== count($vals)) {
        echo json_encode(['success' => false, 'error' => 'Invalid variation price payload']);
        exit;
    }

    $validUnitTypes = ['per piece','per kilo','per box','per meter','per gallon','per bag','per sheet'];
    $unitType = in_array($unitTypeRaw, $validUnitTypes, true) ? $unitTypeRaw : 'per piece';

    $db = (class_exists('DatabasePOS') ? (new DatabasePOS())->getConnection() : (new Database())->getConnection());
$invVar = new InventoryVariation($db);

    // Build existing variants for this unit type to decide on create vs update
    $existingRows = $invVar->getByInventory($inventoryId);
    $existingSet = [];
    foreach ($existingRows as $r) {
        if (strtolower($r['unit_type']) === $unitType) {
            $existingSet[$r['variation']] = true;
        }
    }

    $resultMap = [];
    for ($i = 0; $i < count($keys); $i++) {
        $variation = (string)$keys[$i];
        $priceRaw  = $vals[$i];
        // Accept numeric strings; normalize to float >= 0
        if ($priceRaw === '' || $priceRaw === null) { continue; }
        $price = (float)$priceRaw;
        if ($price < 0) { continue; }
        // If missing, create the variant first with initial qty 0 and set price
        if (!isset($existingSet[$variation])) {
            $invVar->createVariant($inventoryId, $variation, $unitType, 0, $price);
            $existingSet[$variation] = true;
        } else {
            $invVar->updatePrice($inventoryId, $variation, $unitType, $price);
        }
        $resultMap[$variation] = (float)number_format($price, 2, '.', '');
    }

    // Logging for variation price updates
    $logDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs';
    $logFile = $logDir . DIRECTORY_SEPARATOR . 'variation_sync.log';
    if (!is_dir($logDir)) { @mkdir($logDir, 0777, true); }
    $who = !empty($_SESSION['admin']['user_id']) ? 'admin' : (!empty($_SESSION['staff']['user_id']) ? 'staff' : 'unknown');
    $logMsg = sprintf('[%s] update_variation_prices inventory_id=%d unit_type=%s updates=%s by=%s', date('Y-m-d H:i:s'), $inventoryId, $unitType, json_encode($resultMap), $who);
    @error_log($logMsg . "\n", 3, $logFile);

    echo json_encode(['success' => true, 'updated' => $resultMap]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}