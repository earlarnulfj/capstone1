<?php
// Remove all data in deliveries table (admin-only; CLI allowed for maintenance)
include_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

$isCli = (PHP_SAPI === 'cli');
$isAuthorized = $isCli || (!empty($_SESSION['admin']['user_id']) && (($_SESSION['admin']['role'] ?? '') === 'management'));

if (!$isAuthorized) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

try {
    $db = (new Database())->getConnection();
    $db->beginTransaction();
    $deleted = $db->exec('DELETE FROM deliveries');
    $db->commit();
    echo json_encode(['success' => true, 'deleted' => (int)$deleted]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal Server Error']);
}
?>