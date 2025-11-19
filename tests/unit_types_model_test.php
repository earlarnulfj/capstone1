<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/unit_type.php';
require_once __DIR__ . '/../models/unit_type_variation.php';

function assert_true($cond, $msg){ if(!$cond){ echo "FAIL: $msg\n"; exit(1);} }

$db = (new Database())->getConnection();
$utModel = new UnitType($db);
$uvModel = new UnitTypeVariation($db);

// Create unit type
$code = 'test_code_' . rand(1000,9999);
$name = 'Test Unit ' . rand(1000,9999);
$id = $utModel->create($code, $name, 'desc', ['example' => true]);
assert_true($id !== false && $id > 0, 'Create unit type failed');

// Read back
$row = $utModel->readById((int)$id);
assert_true($row && $row['code'] === $code && $row['name'] === $name, 'Read unit type mismatch');

// Update name
$ok = $utModel->update((int)$id, ['name' => $name . ' Updated']);
assert_true($ok === true, 'Update unit type failed');

// Soft delete and restore
assert_true($utModel->softDelete((int)$id) === true, 'Soft delete failed');
assert_true($utModel->restore((int)$id) === true, 'Restore failed');

// Create variation
$vid = $uvModel->create((int)$id, 'Color', 'Red', 'red desc', ['rgb' => '#f00']);
assert_true($vid !== false && $vid > 0, 'Create variation failed');

// Rename variation value
$count = $uvModel->renameValue((int)$id, 'Color', 'Red', 'Crimson');
assert_true($count !== false && $count >= 1, 'Rename value failed');

echo "OK: Unit types and variations model tests passed\n";
exit(0);