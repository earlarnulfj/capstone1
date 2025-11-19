<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/unit_type.php';
require_once __DIR__ . '/../models/unit_type_variation.php';

$db = (new Database())->getConnection();
$unitTypeModel = new UnitType($db);
$variationModel = new UnitTypeVariation($db);

function readJsonBody(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (is_array($data)) { return $data; }
    if (!empty($_POST)) { return $_POST; }
    return [];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? null;

function resolveUnitTypeId(PDO $db, UnitType $unitTypeModel, array $source): int {
    $unitTypeId = (int)($source['unit_type_id'] ?? 0);
    if ($unitTypeId > 0) { return $unitTypeId; }
    $code = trim((string)($source['unit_type_code'] ?? ''));
    if ($code !== '') {
        $u = $unitTypeModel->getByCode($code);
        if ($u && isset($u['id'])) { return (int)$u['id']; }
    }
    $name = trim((string)($source['unit_type'] ?? ''));
    if ($name !== '') {
        $stmt = $db->prepare("SELECT id FROM unit_types WHERE name = :name LIMIT 1");
        $stmt->bindParam(':name', $name);
        $stmt->execute();
        $id = $stmt->fetchColumn();
        if ($id) { return (int)$id; }
    }
    return 0;
}

try {
    if ($method === 'GET') {
        $search = $_GET['search'] ?? '';
        $unitTypeId = isset($_GET['unit_type_id']) ? (int)$_GET['unit_type_id'] : 0;
        if ($unitTypeId <= 0) {
            // Also accept unit_type_code or unit_type name via GET
            $unitTypeId = resolveUnitTypeId($db, $unitTypeModel, $_GET);
        }
        
        if ($action === 'attributes') {
            // Get all unique attributes across all unit types, sorted alphabetically
            $sql = "SELECT DISTINCT attribute FROM unit_type_variations WHERE is_deleted = 0";
            $params = [];
            
            if ($search !== '') {
                $sql .= " AND attribute LIKE :search";
                $params[':search'] = '%' . $search . '%';
            }
            
            $sql .= " ORDER BY attribute ASC";
            
            $stmt = $db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            
            $attributes = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $attributes[] = $row['attribute'];
            }
            
            echo json_encode([
                'success' => true,
                'attributes' => $attributes,
                'total' => count($attributes)
            ]);
            exit;
        }
        
        if ($action === 'options') {
            // Get all options for a specific attribute
            $attribute = $_GET['attribute'] ?? '';
            if ($attribute === '') {
                http_response_code(400);
                echo json_encode(['error' => 'attribute parameter is required']);
                exit;
            }
            
            $sql = "SELECT DISTINCT value FROM unit_type_variations WHERE attribute = :attribute AND is_deleted = 0";
            $params = [':attribute' => $attribute];
            
            if ($search !== '') {
                $sql .= " AND value LIKE :search";
                $params[':search'] = '%' . $search . '%';
            }
            
            $sql .= " ORDER BY value ASC";
            
            $stmt = $db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            
            $options = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $options[] = $row['value'];
            }
            
            echo json_encode([
                'success' => true,
                'attribute' => $attribute,
                'options' => $options,
                'total' => count($options)
            ]);
            exit;
        }

        if ($action === 'attributes_for_unit') {
            // Get distinct attributes for a given unit type
            if ($unitTypeId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'unit_type_id or unit_type_code is required']);
                exit;
            }
            $sql = "SELECT DISTINCT attribute FROM unit_type_variations WHERE unit_type_id = :unit_type_id AND is_deleted = 0";
            $params = [':unit_type_id' => $unitTypeId];
            if ($search !== '') {
                $sql .= " AND attribute LIKE :search";
                $params[':search'] = '%' . $search . '%';
            }
            $sql .= " ORDER BY attribute ASC";
            $stmt = $db->prepare($sql);
            foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
            $stmt->execute();
            $attributes = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $attributes[] = $row['attribute']; }
            echo json_encode(['success' => true, 'attributes' => $attributes, 'total' => count($attributes)]);
            exit;
        }

        if ($action === 'options_for_attribute_by_unit') {
            // Get options for a specific attribute limited to a unit type
            $attribute = $_GET['attribute'] ?? '';
            if ($attribute === '') {
                http_response_code(400);
                echo json_encode(['error' => 'attribute parameter is required']);
                exit;
            }
            if ($unitTypeId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'unit_type_id or unit_type_code is required']);
                exit;
            }
            $sql = "SELECT DISTINCT value FROM unit_type_variations WHERE unit_type_id = :unit_type_id AND attribute = :attribute AND is_deleted = 0";
            $params = [':unit_type_id' => $unitTypeId, ':attribute' => $attribute];
            if ($search !== '') {
                $sql .= " AND value LIKE :search";
                $params[':search'] = '%' . $search . '%';
            }
            $sql .= " ORDER BY value ASC";
            $stmt = $db->prepare($sql);
            foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
            $stmt->execute();
            $options = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $options[] = $row['value']; }
            echo json_encode(['success' => true, 'attribute' => $attribute, 'options' => $options, 'total' => count($options)]);
            exit;
        }

        if ($action === 'attribute_options_by_unit') {
            // Group attribute options for a given unit type
            // Resolve unit_type_id from unit_type_code if needed
            if ($unitTypeId <= 0) {
                $unitTypeId = resolveUnitTypeId($db, $unitTypeModel, $_GET);
            }
            if ($unitTypeId <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'unit_type_id or unit_type_code is required', 'received' => $_GET]);
                exit;
            }
            $sql = "SELECT attribute, value FROM unit_type_variations WHERE unit_type_id = :unit_type_id AND is_deleted = 0";
            $params = [':unit_type_id' => $unitTypeId];
            if ($search !== '') {
                $sql .= " AND (attribute LIKE :search OR value LIKE :search2)";
                $params[':search'] = '%' . $search . '%';
                $params[':search2'] = '%' . $search . '%';
            }
            $sql .= " ORDER BY attribute ASC, value ASC";
            $stmt = $db->prepare($sql);
            foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
            $stmt->execute();
            $attributeOptions = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $attr = $row['attribute'];
                if (!isset($attributeOptions[$attr])) { $attributeOptions[$attr] = []; }
                $attributeOptions[$attr][] = $row['value'];
            }
            echo json_encode(['success' => true, 'attribute_options' => $attributeOptions, 'total_attributes' => count($attributeOptions)]);
            exit;
        }
        
        if ($action === 'attribute_options') {
            // Get all attributes with their options grouped
            $sql = "SELECT attribute, value FROM unit_type_variations WHERE is_deleted = 0";
            $params = [];
            
            if ($search !== '') {
                $sql .= " AND (attribute LIKE :search OR value LIKE :search2)";
                $params[':search'] = '%' . $search . '%';
                $params[':search2'] = '%' . $search . '%';
            }
            
            $sql .= " ORDER BY attribute ASC, value ASC";
            
            $stmt = $db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            
            $attributeOptions = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $attr = $row['attribute'];
                if (!isset($attributeOptions[$attr])) {
                    $attributeOptions[$attr] = [];
                }
                $attributeOptions[$attr][] = $row['value'];
            }
            
            echo json_encode([
                'success' => true,
                'attribute_options' => $attributeOptions,
                'total_attributes' => count($attributeOptions)
            ]);
            exit;
        }
        
        // Default: return all unit types with their variations
        $unitTypes = $unitTypeModel->readAll();
        $result = [];
        
        foreach ($unitTypes as $unitType) {
            $variations = $variationModel->readByUnitType($unitType['id']);
            $result[] = [
                'id' => $unitType['id'],
                'code' => $unitType['code'],
                'name' => $unitType['name'],
                'variations' => $variations
            ];
        }
        
        echo json_encode([
            'success' => true,
            'unit_types' => $result
        ]);
        exit;
    }
    
    if ($method === 'POST') {
        $data = readJsonBody();
        $action = $data['action'] ?? $_GET['action'] ?? null;
        
        if ($action === 'add_attribute_option') {
            // Add a new attribute-option combination
            $unitTypeId = resolveUnitTypeId($db, $unitTypeModel, $data);
            $attribute = trim((string)($data['attribute'] ?? ''));
            // Accept 'value' or 'option' alias from clients
            $value = trim((string)($data['value'] ?? $data['option'] ?? ''));
            
            if ($unitTypeId <= 0 || $attribute === '' || $value === '') {
                http_response_code(400);
                echo json_encode(['error' => 'unit_type_id, attribute and value are required']);
                exit;
            }
            
            // Check if combination already exists
            $stmt = $db->prepare("SELECT id FROM unit_type_variations WHERE unit_type_id = :unit_type_id AND attribute = :attribute AND value = :value AND is_deleted = 0");
            $stmt->execute([
                ':unit_type_id' => $unitTypeId,
                ':attribute' => $attribute,
                ':value' => $value
            ]);
            
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(['error' => 'Attribute-option combination already exists']);
                exit;
            }
            
            $id = $variationModel->create($unitTypeId, $attribute, $value, $data['description'] ?? null, $data['metadata'] ?? null);
            
            if (!$id) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to create attribute-option combination']);
                exit;
            }
            
            http_response_code(201);
            echo json_encode([
                'success' => true,
                'id' => (int)$id,
                'unit_type_id' => $unitTypeId,
                'attribute' => $attribute,
                'value' => $value
            ]);
            exit;
        }
        
        if ($action === 'bulk_add') {
            // Add multiple attribute-option combinations
            $unitTypeId = resolveUnitTypeId($db, $unitTypeModel, $data);
            $combinations = $data['combinations'] ?? [];
            
            if ($unitTypeId <= 0 || !is_array($combinations) || empty($combinations)) {
                http_response_code(400);
                echo json_encode(['error' => 'unit_type_id and combinations array are required']);
                exit;
            }
            
            $db->beginTransaction();
            $created = [];
            $errors = [];
            
            try {
                foreach ($combinations as $combo) {
                    $attribute = trim((string)($combo['attribute'] ?? ''));
                    $value = trim((string)($combo['value'] ?? ''));
                    
                    if ($attribute === '' || $value === '') {
                        $errors[] = "Invalid attribute or value in combination";
                        continue;
                    }
                    
                    // Check if combination already exists
                    $stmt = $db->prepare("SELECT id FROM unit_type_variations WHERE unit_type_id = :unit_type_id AND attribute = :attribute AND value = :value AND is_deleted = 0");
                    $stmt->execute([
                        ':unit_type_id' => $unitTypeId,
                        ':attribute' => $attribute,
                        ':value' => $value
                    ]);
                    
                    if ($stmt->fetch()) {
                        $errors[] = "Combination {$attribute}:{$value} already exists";
                        continue;
                    }
                    
                    $id = $variationModel->create($unitTypeId, $attribute, $value, $combo['description'] ?? null, $combo['metadata'] ?? null);
                    
                    if ($id) {
                        $created[] = [
                            'id' => (int)$id,
                            'attribute' => $attribute,
                            'value' => $value
                        ];
                    } else {
                        $errors[] = "Failed to create combination {$attribute}:{$value}";
                    }
                }
                
                $db->commit();
                
                echo json_encode([
                    'success' => true,
                    'created' => $created,
                    'errors' => $errors,
                    'total_created' => count($created),
                    'total_errors' => count($errors)
                ]);
                exit;
                
            } catch (Exception $e) {
                $db->rollBack();
                http_response_code(500);
                echo json_encode(['error' => 'Transaction failed: ' . $e->getMessage()]);
                exit;
            }
        }
    }

    if ($method === 'PUT') {
        $data = readJsonBody();
        $action = $data['action'] ?? $_GET['action'] ?? null;
        if ($action === 'rename_option') {
            $unitTypeId = resolveUnitTypeId($db, $unitTypeModel, $data);
            $attribute = trim((string)($data['attribute'] ?? ''));
            $current = trim((string)($data['current'] ?? ''));
            $new = trim((string)($data['new'] ?? ''));
            if ($unitTypeId <= 0 || $attribute === '' || $current === '' || $new === '') {
                http_response_code(400);
                echo json_encode(['error' => 'unit_type, attribute, current and new are required']);
                exit;
            }
            // Prevent duplicates
            $dup = $variationModel->getByUnique($unitTypeId, $attribute, $new);
            if ($dup) {
                http_response_code(409);
                echo json_encode(['error' => 'Target option already exists']);
                exit;
            }
            $changed = $variationModel->renameValue($unitTypeId, $attribute, $current, $new);
            if ($changed === false) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to rename option']);
                exit;
            }
            echo json_encode(['success' => true, 'updated' => (int)$changed]);
            exit;
        }

        if ($action === 'rename_attribute') {
            $unitTypeId = resolveUnitTypeId($db, $unitTypeModel, $data);
            $current = trim((string)($data['current'] ?? ''));
            $new = trim((string)($data['new'] ?? ''));
            if ($unitTypeId <= 0 || $current === '' || $new === '') {
                http_response_code(400);
                echo json_encode(['error' => 'unit_type and current/new attribute names are required']);
                exit;
            }
            // Prevent duplicates: if any row exists with target attribute name, block
            $stmt = $db->prepare("SELECT 1 FROM unit_type_variations WHERE unit_type_id = :uid AND attribute = :attr AND is_deleted = 0 LIMIT 1");
            $stmt->execute([':uid' => $unitTypeId, ':attr' => $new]);
            if ($stmt->fetchColumn()) {
                http_response_code(409);
                echo json_encode(['error' => 'Target attribute already exists']);
                exit;
            }
            // Rename all rows for this attribute
            $upd = $db->prepare("UPDATE unit_type_variations SET attribute = :new WHERE unit_type_id = :uid AND attribute = :cur AND is_deleted = 0");
            $ok = $upd->execute([':new' => $new, ':uid' => $unitTypeId, ':cur' => $current]);
            if (!$ok) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to rename attribute']);
                exit;
            }
            echo json_encode(['success' => true, 'updated' => $upd->rowCount()]);
            exit;
        }

        if ($action === 'reorder_options') {
            $unitTypeId = resolveUnitTypeId($db, $unitTypeModel, $data);
            $attribute = trim((string)($data['attribute'] ?? ''));
            $order = $data['order'] ?? [];
            if ($unitTypeId <= 0 || $attribute === '' || !is_array($order) || empty($order)) {
                http_response_code(400);
                echo json_encode(['error' => 'unit_type, attribute and order array are required']);
                exit;
            }
            // Ensure no duplicates and no empties
            $seen = [];
            foreach ($order as $v) {
                $val = trim((string)$v);
                if ($val === '' || isset($seen[$val])) {
                    http_response_code(422);
                    echo json_encode(['error' => 'Order contains empty or duplicate values']);
                    exit;
                }
                $seen[$val] = true;
            }
            // Update metadata->position for each value in order
            $db->beginTransaction();
            try {
                $pos = 1;
                $upd = $db->prepare("UPDATE unit_type_variations SET metadata = JSON_SET(COALESCE(metadata, '{}'), '$.position', :pos) WHERE unit_type_id = :uid AND attribute = :attr AND value = :val AND is_deleted = 0");
                foreach ($order as $v) {
                    $val = trim((string)$v);
                    $ok = $upd->execute([':pos' => $pos, ':uid' => $unitTypeId, ':attr' => $attribute, ':val' => $val]);
                    if (!$ok) { throw new Exception('Failed to update position for ' . $val); }
                    $pos++;
                }
                $db->commit();
                echo json_encode(['success' => true, 'updated' => count($order)]);
                exit;
            } catch (Throwable $e) {
                $db->rollBack();
                http_response_code(500);
                echo json_encode(['error' => 'Failed to reorder options', 'message' => $e->getMessage()]);
                exit;
            }
        }
    }

    if ($method === 'DELETE') {
        // Soft delete by id or by unique combination
        $data = readJsonBody();
        $action = $data['action'] ?? $_GET['action'] ?? null;
        if ($action === 'delete_attribute_option') {
            $id = (int)($data['id'] ?? 0);
            if ($id <= 0) {
                $unitTypeId = resolveUnitTypeId($db, $unitTypeModel, $data);
                $attribute = trim((string)($data['attribute'] ?? ''));
                $value = trim((string)($data['value'] ?? $data['option'] ?? ''));
                if ($unitTypeId <= 0 || $attribute === '' || $value === '') {
                    http_response_code(400);
                    echo json_encode(['error' => 'id or (unit_type, attribute, value) required']);
                    exit;
                }
                $row = $variationModel->getByUnique($unitTypeId, $attribute, $value);
                $id = $row ? (int)$row['id'] : 0;
            }
            if ($id <= 0) {
                http_response_code(404);
                echo json_encode(['error' => 'Attribute option not found']);
                exit;
            }
            $ok = $variationModel->softDelete($id);
            if (!$ok) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to delete attribute option']);
                exit;
            }
            echo json_encode(['success' => true, 'deleted_id' => $id]);
            exit;
        }
    }
    
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'message' => $e->getMessage()]);
}
?>