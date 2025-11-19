<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/unit_type_variation.php';

$db = (new Database())->getConnection();
$model = new UnitTypeVariation($db);

function readJsonBody(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (is_array($data)) { return $data; }
    // Fallback to form POST for test harness or non-JSON clients
    if (!empty($_POST)) { return $_POST; }
    return [];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? null;

try {
    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $unitTypeId = isset($_GET['unit_type_id']) ? (int)$_GET['unit_type_id'] : 0;
        $includeDeleted = isset($_GET['include_deleted']) && ($_GET['include_deleted'] === '1' || $_GET['include_deleted'] === 'true');
        if ($id > 0) {
            $row = $model->readById($id);
            if (!$row) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
            echo json_encode($row); exit;
        }
        if ($unitTypeId > 0) {
            echo json_encode($model->readByUnitType($unitTypeId, $includeDeleted)); exit;
        }
        http_response_code(400); echo json_encode(['error' => 'unit_type_id required for list']); exit;
    }

    if ($method === 'POST' && $action === 'restore') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
        $ok = $model->restore($id);
        echo json_encode(['success' => (bool)$ok]); exit;
    }

    if ($method === 'POST' && $action === 'rename') {
        $data = readJsonBody();
        $unitTypeId = (int)($data['unit_type_id'] ?? 0);
        $attribute = trim((string)($data['attribute'] ?? ''));
        $currentValue = trim((string)($data['current_value'] ?? ''));
        $newValue = trim((string)($data['new_value'] ?? ''));
        if ($unitTypeId <= 0 || $attribute === '' || $currentValue === '' || $newValue === '') {
            http_response_code(400); echo json_encode(['error' => 'unit_type_id, attribute, current_value, new_value required']); exit;
        }
        $count = $model->renameValue($unitTypeId, $attribute, $currentValue, $newValue);
        echo json_encode(['updated' => (int)$count]); exit;
    }

    if ($method === 'POST') {
        $data = readJsonBody();
        $unitTypeId = (int)($data['unit_type_id'] ?? 0);
        $attribute = trim((string)($data['attribute'] ?? ''));
        $value = trim((string)($data['value'] ?? ''));
        if ($unitTypeId <= 0 || $attribute === '' || $value === '') { http_response_code(400); echo json_encode(['error' => 'unit_type_id, attribute and value are required']); exit; }
        $id = $model->create($unitTypeId, $attribute, $value, $data['description'] ?? null, $data['metadata'] ?? null);
        if (!$id) { http_response_code(500); echo json_encode(['error' => 'Failed to create']); exit; }
        http_response_code(201);
        echo json_encode(['id' => (int)$id, 'unit_type_id' => $unitTypeId, 'attribute' => $attribute, 'value' => $value]); exit;
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
        $data = readJsonBody();
        $ok = $model->update($id, $data);
        echo json_encode(['success' => (bool)$ok]); exit;
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $hard = isset($_GET['hard']) && ($_GET['hard'] === '1' || $_GET['hard'] === 'true');
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
        $ok = $hard ? $model->deleteHard($id) : $model->softDelete($id);
        echo json_encode(['success' => (bool)$ok]); exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'message' => $e->getMessage()]);
}
?>