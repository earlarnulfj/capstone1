<?php
header('Content-Type: application/json');
session_start();

try {
    require_once '../../config/database.php';
    @require_once '../../config/database_pos.php';
    require_once '../../models/inventory_variation.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => 'Invalid method']);
        exit;
    }

    // Check admin auth
    if (empty($_SESSION['admin']['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $inventoryId = isset($_POST['inventory_id']) ? (int)$_POST['inventory_id'] : 0;
    $unitTypeRaw = isset($_POST['unit_type']) ? trim(strtolower($_POST['unit_type'])) : 'per piece';
    $priceKeys = isset($_POST['variation_price_keys']) ? $_POST['variation_price_keys'] : [];
    $priceVals = isset($_POST['variation_price_vals']) ? $_POST['variation_price_vals'] : [];
    $stockKeys = isset($_POST['variation_stock_keys']) ? $_POST['variation_stock_keys'] : [];
    $stockVals = isset($_POST['variation_stock_vals']) ? $_POST['variation_stock_vals'] : [];

    if (!$inventoryId) {
        echo json_encode(['success' => false, 'error' => 'Missing inventory_id']);
        exit;
    }

    $validUnitTypes = ['per piece','per kilo','per box','per meter','per gallon','per bag','per sheet'];
    $unitType = in_array($unitTypeRaw, $validUnitTypes, true) ? $unitTypeRaw : 'per piece';

    $db = (class_exists('DatabasePOS') ? (new DatabasePOS())->getConnection() : (new Database())->getConnection());
    $invVar = new InventoryVariation($db);

    // Build existing variants for this unit type
    $existingRows = $invVar->getByInventory($inventoryId);
    $existingSet = [];
    foreach ($existingRows as $r) {
        if (strtolower($r['unit_type']) === $unitType) {
            $existingSet[$r['variation']] = true;
        }
    }

    $resultMap = ['prices' => [], 'stocks' => []];
    
    // Start transaction to ensure atomic updates and immediate commit
    $db->beginTransaction();
    try {
        // Update prices
        if (is_array($priceKeys) && is_array($priceVals) && count($priceKeys) === count($priceVals)) {
            for ($i = 0; $i < count($priceKeys); $i++) {
                $variation = (string)$priceKeys[$i];
                $priceRaw = $priceVals[$i];
                if ($priceRaw === '' || $priceRaw === null) { continue; }
                $price = (float)$priceRaw;
                if ($price < 0) { continue; }
                
                // Create variant if missing
                if (!isset($existingSet[$variation])) {
                    $invVar->createVariant($inventoryId, $variation, $unitType, 0, $price);
                    $existingSet[$variation] = true;
                } else {
                    $invVar->updatePrice($inventoryId, $variation, $unitType, $price);
                }
                $resultMap['prices'][$variation] = (float)number_format($price, 2, '.', '');
            }
        }
        
        // Update stocks
        if (is_array($stockKeys) && is_array($stockVals) && count($stockKeys) === count($stockVals)) {
            for ($i = 0; $i < count($stockKeys); $i++) {
                $variation = (string)$stockKeys[$i];
                $stockRaw = $stockVals[$i];
                if ($stockRaw === '' || $stockRaw === null) { continue; }
                $stock = (int)$stockRaw;
                if ($stock < 0) { $stock = 0; }
                
                // Create variant if missing
                if (!isset($existingSet[$variation])) {
                    $invVar->createVariant($inventoryId, $variation, $unitType, $stock, null);
                    $existingSet[$variation] = true;
                } else {
                    $invVar->updateStock($inventoryId, $variation, $unitType, $stock);
                }
                $resultMap['stocks'][$variation] = $stock;
            }
        }
        
        // Commit transaction to ensure changes are persisted immediately
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    // Logging
    $logDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs';
    $logFile = $logDir . DIRECTORY_SEPARATOR . 'variation_sync.log';
    if (!is_dir($logDir)) { @mkdir($logDir, 0777, true); }
    $who = !empty($_SESSION['admin']['user_id']) ? 'admin' : 'unknown';
    $logMsg = sprintf('[%s] update_variation_prices_stock inventory_id=%d unit_type=%s updates=%s by=%s', 
        date('Y-m-d H:i:s'), $inventoryId, $unitType, json_encode($resultMap), $who);
    @error_log($logMsg . "\n", 3, $logFile);

    echo json_encode(['success' => true, 'updated' => $resultMap]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

