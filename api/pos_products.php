<?php
// Standardized POS Products API
// Provides a secure, consistent product feed for admin/staff POS

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../config/session.php';
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../models/inventory.php';

    // Role-based access: allow admin or staff only
    $isAdmin = !empty($_SESSION['admin']['user_id']);
    $isStaff = !empty($_SESSION['staff']['user_id']);
    if (!$isAdmin && !$isStaff) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }

    $db = (new Database())->getConnection();
    $inventory = new Inventory($db);

    // Query options
    $orderedOnly = isset($_GET['ordered_only']) ? (trim($_GET['ordered_only']) !== '0') : true;
    $markup = isset($_GET['markup']) ? max(0.0, (float)$_GET['markup']) : 0.30; // default 30%

    // Build set of ordered inventory IDs if requested
    $orderedIds = [];
    if ($orderedOnly) {
        $stmtOrdered = $db->query("SELECT DISTINCT inventory_id FROM orders WHERE confirmation_status <> 'cancelled'");
        while ($row = $stmtOrdered->fetch(PDO::FETCH_ASSOC)) {
            $orderedIds[(int)$row['inventory_id']] = true;
        }
    }

    $stmt = $inventory->readAllIncludingDeliveries();
    $products = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) { continue; }
        if ($orderedOnly && empty($orderedIds[$id])) { continue; }

        $name = (string)($row['name'] ?? '');
        $category = (string)($row['category'] ?? '');
        $unitPrice = (float)($row['unit_price'] ?? 0);
        $sellingPrice = round($unitPrice * (1 + $markup), 2);
        $quantity = (int)($row['quantity'] ?? 0);
        $reorder = (int)($row['reorder_threshold'] ?? 0);

        $img = trim((string)($row['image_url'] ?? ''));
        if ($img === '') {
            $img = trim((string)($row['image_path'] ?? ''));
        }
        if ($img === '') {
            $img = 'assets/img/placeholder.svg';
        }

        $products[] = [
            'id' => $id,
            'name' => $name,
            'category' => $category,
            'unit_price' => $unitPrice,
            'selling_price' => $sellingPrice,
            'quantity' => $quantity,
            'reorder_threshold' => $reorder,
            'image' => $img
        ];
    }

    echo json_encode([
        'success' => true,
        'role' => $isAdmin ? 'admin' : 'staff',
        'ordered_only' => $orderedOnly,
        'markup' => $markup,
        'count' => count($products),
        'products' => $products
    ], JSON_UNESCAPED_SLASHES);
    // Best-effort logging for observability
    try {
        $logLine = sprintf("%s\tpos_products\trole=%s\tordered_only=%d\tcount=%d\n", date('c'), ($isAdmin?'admin':'staff'), $orderedOnly?1:0, count($products));
        @file_put_contents(__DIR__ . '/../logs/sync_events.log', $logLine, FILE_APPEND);
    } catch (Throwable $e) {}
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    try {
        $logLine = sprintf("%s\tpos_products_error\tmsg=%s\n", date('c'), str_replace(["\n","\r"], ' ', $e->getMessage()));
        @file_put_contents(__DIR__ . '/../logs/sync_events.log', $logLine, FILE_APPEND);
    } catch (Throwable $ie) {}
}