<?php
// Snapshot monitor after insert operations for unit_types
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$out = [ 'total_unit_types' => null, 'recent_health' => [], 'recent_any' => [] ];

try {
    $db = (new Database())->getConnection();

    $out['total_unit_types'] = (int)$db->query('SELECT COUNT(*) FROM unit_types')->fetchColumn();

    $stmt1 = $db->query("SELECT id, code, name, is_deleted, created_at FROM unit_types WHERE code LIKE 'hlth_%' OR code LIKE 'ut_%' ORDER BY id DESC LIMIT 5");
    while ($r = $stmt1->fetch(PDO::FETCH_ASSOC)) { $out['recent_health'][] = $r; }

    $stmt2 = $db->query("SELECT id, code, name, is_deleted, created_at FROM unit_types ORDER BY id DESC LIMIT 5");
    while ($r = $stmt2->fetch(PDO::FETCH_ASSOC)) { $out['recent_any'][] = $r; }

    echo json_encode($out);
    exit(0);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([ 'error' => $e->getMessage() ]);
    exit(1);
}
?>