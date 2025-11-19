<?php
// admin/ajax/get_variant_data.php
// Returns real-time stock and unit price for a specific inventory variation

include_once '../../config/session.php';
require_once '../../config/database.php';

header('Content-Type: application/json');

// Access control: allow admin and staff namespaces
$isAdmin = !empty($_SESSION['admin']['user_id']);
$isStaff = !empty($_SESSION['staff']['user_id']);
if (!$isAdmin && !$isStaff) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'error' => 'unauthorized']);
    exit;
}

try {
    $db = (new Database())->getConnection();

    $inventoryId = isset($_GET['inventory_id']) ? (int)$_GET['inventory_id'] : 0;
    $variantKey  = isset($_GET['variant']) ? trim((string)$_GET['variant']) : '';
    $unitType    = isset($_GET['unit_type']) ? trim((string)$_GET['unit_type']) : null;

    if ($inventoryId <= 0 || $variantKey === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'error' => 'inventory_id and variant are required']);
        exit;
    }

    // Fetch base inventory info
    $baseStmt = $db->prepare('SELECT quantity, unit_price FROM inventory WHERE id = :id');
    $baseStmt->execute([':id' => $inventoryId]);
    $base = $baseStmt->fetch(PDO::FETCH_ASSOC);
    $baseStock = $base ? (int)($base['quantity'] ?? 0) : null;
    $baseUnitPrice = $base ? (float)($base['unit_price'] ?? 0) : null;

    // Fetch variation row
    $sql = 'SELECT unit_type, unit_price, quantity FROM inventory_variations WHERE inventory_id = :iid AND variation = :var LIMIT 1';
    if ($unitType && $unitType !== '') {
        $sql = 'SELECT unit_type, unit_price, quantity FROM inventory_variations WHERE inventory_id = :iid AND variation = :var AND unit_type = :ut LIMIT 1';
    }
    $stmt = $db->prepare($sql);
    $params = [':iid' => $inventoryId, ':var' => $variantKey];
    if ($unitType && $unitType !== '') { $params[':ut'] = $unitType; }
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        // If variation not found, try to use base inventory if no variation required
        if ($variantKey === '' || $variantKey === null) {
            echo json_encode([
                'status' => 'ok',
                'inventory_id' => $inventoryId,
                'variant' => '',
                'unit_type' => $unitType ?? 'per piece',
                'stock' => $baseStock ?? 0,
                'unit_price' => $baseUnitPrice ?? null,
                'base_stock' => $baseStock,
                'base_unit_price' => $baseUnitPrice,
                'timestamp' => time(),
            ]);
            exit;
        }
        // Variation specified but not found
        echo json_encode([
            'status' => 'not_found',
            'inventory_id' => $inventoryId,
            'variant' => $variantKey,
            'unit_type' => $unitType,
            'stock' => 0,
            'unit_price' => null,
            'base_stock' => $baseStock,
            'base_unit_price' => $baseUnitPrice,
            'timestamp' => time(),
        ]);
        exit;
    }

    // Use StockCalculator to get available stock (accounts for pending orders)
    require_once '../../models/stock_calculator.php';
    $stockCalculator = new StockCalculator($db);
    $availableStock = $stockCalculator->getAvailableStock($inventoryId, $variantKey, $row['unit_type'] ?? $unitType ?? 'per piece');
    
    echo json_encode([
        'status' => 'ok', // Changed to 'ok' for compatibility with POS JavaScript
        'inventory_id' => $inventoryId,
        'variant' => $variantKey,
        'unit_type' => $row['unit_type'] ?? $unitType,
        'stock' => $availableStock, // Use available stock instead of raw quantity
        'unit_price' => isset($row['unit_price']) ? (float)$row['unit_price'] : null,
        'base_stock' => $baseStock,
        'base_unit_price' => $baseUnitPrice,
        'timestamp' => time(),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}

?>