<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: text/plain; charset=utf-8');
try {
    $db = (new Database())->getConnection();
    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'inventory_variations'");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $exists = isset($row['cnt']) ? ((int)$row['cnt'] > 0) : 0;
    echo $exists ? "inventory_variations exists" : "inventory_variations missing";
} catch (Exception $e) {
    http_response_code(500);
    echo "Check error: " . $e->getMessage();
}