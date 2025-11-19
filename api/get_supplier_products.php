<?php
// ====== Access control & dependencies ======
include_once '../config/session.php';
require_once '../config/database.php';
require_once '../models/inventory.php';

// ---- Admin auth guard ----
if (empty($_SESSION['admin']['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

// Check if supplier_id is provided
if (!isset($_GET['supplier_id']) || !is_numeric($_GET['supplier_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid supplier ID']);
    exit;
}

$supplier_id = intval($_GET['supplier_id']);

try {
    // DB connection
    $db = (new Database())->getConnection();
    $inventory = new Inventory($db);
    
    // Get products for this supplier
    $stmt = $inventory->readBySupplier($supplier_id);
    $products = [];

    // Compute ordered inventory IDs (non-cancelled orders)
    $orderedInventoryIds = [];
    try {
        $orderedStmt = $db->query("SELECT DISTINCT inventory_id FROM orders WHERE confirmation_status <> 'cancelled'");
        while ($or = $orderedStmt->fetch(PDO::FETCH_ASSOC)) { $orderedInventoryIds[] = (int)$or['inventory_id']; }
    } catch (PDOException $e) {
        // If query fails, fall back to showing nothing rather than everything
        $orderedInventoryIds = [];
    }
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!in_array((int)$row['id'], $orderedInventoryIds, true)) { continue; }
        $products[] = [
            'id' => $row['id'],
            'name' => htmlspecialchars($row['name']),
            'sku' => htmlspecialchars($row['sku']),
            'category' => htmlspecialchars($row['category']),
            'unit_price' => number_format($row['unit_price'], 2),
            'quantity' => $row['quantity'],
            'reorder_threshold' => $row['reorder_threshold'],
            'location' => htmlspecialchars($row['location'] ?? ''),
            'description' => htmlspecialchars($row['description'] ?? ''),
            'status' => $row['quantity'] > 0 ? 'In Stock' : 'Out of Stock',
            'stock_status' => $row['quantity'] <= $row['reorder_threshold'] ? 'low' : 'normal'
        ];
    }
    
    // Set content type to JSON
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'products' => $products,
        'total_count' => count($products)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>