<?php
session_start();
require_once '../../config/app.php';
require_once '../../config/database.php';
require_once '../../lib/deletion_service.php';
require_once '../../lib/audit.php';
require_once '../../lib/sync_helpers.php';

header('Content-Type: application/json');

// Auth
if (empty($_SESSION['supplier']) || empty($_SESSION['supplier']['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
$supplierId = (int)($_SESSION['supplier']['user_id'] ?? $_SESSION['user_id']);

// Input
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { $data = $_POST; }
$id = isset($data['id']) ? (int)$data['id'] : 0;
$forceDelete = isset($data['force_delete']) && (string)$data['force_delete'] === '1';
$csrfToken = $data['csrf_token'] ?? '';

// CSRF
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

try {
    $db = (new Database())->getConnection();
    $serviceResult = DeletionService::deleteSupplierProduct($db, $supplierId, [
        'catalog_id' => $id,
        'actor_role' => 'supplier',
        'actor_id' => $supplierId
    ]);

    if ($serviceResult['success']) {
        audit_log_event($db, 'supplier_catalog_hard_delete', 'supplier_catalog', $id, 'deleted', true, 'Hard-deleted via central service', ['source'=>'supplier_ui','context'=>$serviceResult['context']]);
        echo json_encode(['success' => true, 'hard_deleted' => true, 'message' => $serviceResult['message'], 'context' => $serviceResult['context']]);
    } else {
        audit_log_event($db, 'supplier_catalog_hard_delete', 'supplier_catalog', $id, 'deleted', false, 'Delete failed via central service: '.$serviceResult['message'], ['source'=>'supplier_ui']);
        echo json_encode(['success' => false, 'message' => $serviceResult['message']]);
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}