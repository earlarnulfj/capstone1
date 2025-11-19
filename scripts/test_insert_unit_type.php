<?php
require_once __DIR__ . '/../config/database.php';
try {
  $db = (new Database())->getConnection();
  $sql = "INSERT INTO unit_types (code, name) VALUES (:code, :name)";
  $stmt = $db->prepare($sql);
  $code = 'tmp_' . rand(1000,9999);
  $name = 'Tmp Name';
  $stmt->bindParam(':code', $code);
  $stmt->bindParam(':name', $name);
  $ok = $stmt->execute();
  echo $ok ? "ok\n" : "fail\n";
} catch (Throwable $e) {
  echo 'error: ' . $e->getMessage() . "\n";
  exit(1);
}
?>