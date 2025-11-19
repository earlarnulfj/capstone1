<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/unit_type.php';
try {
  $db = (new Database())->getConnection();
  $ut = new UnitType($db);
  $id = $ut->create('diag_' . rand(1000,9999), 'Diag Name', 'desc', ['a' => 1]);
  if ($id === false) { echo "create=false\n"; exit(1); }
  echo "created id: $id\n";
} catch (Throwable $e) {
  echo 'error: ' . $e->getMessage() . "\n";
  exit(1);
}
?>