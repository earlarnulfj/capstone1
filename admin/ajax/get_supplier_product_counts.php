<?php
header('Content-Type: application/json');

try {
    require_once '../../config/database.php';
    $db = (new Database())->getConnection();

    // Count from supplier_catalog table with is_deleted = 0 filter (matching supplier_details.php)
    $stmt = $db->query("SELECT supplier_id, COUNT(*) AS cnt FROM supplier_catalog WHERE COALESCE(is_deleted, 0) = 0 GROUP BY supplier_id");
    $counts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $counts[(int)$row['supplier_id']] = (int)$row['cnt'];
    }

    echo json_encode(['success' => true, 'counts' => $counts]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}