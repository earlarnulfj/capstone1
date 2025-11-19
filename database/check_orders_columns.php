<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: text/plain; charset=utf-8');
try {
    $db = (new Database())->getConnection();
    $stmt = $db->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'orders' AND column_name IN ('unit_type','variation') ORDER BY column_name");
    $stmt->execute();
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo 'Present: ' . (empty($cols) ? 'none' : implode(',', $cols));
} catch (Exception $e) {
    http_response_code(500);
    echo "Check error: " . $e->getMessage();
}