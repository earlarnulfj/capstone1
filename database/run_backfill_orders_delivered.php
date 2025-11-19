<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: text/plain; charset=utf-8');
try {
    $db = (new Database())->getConnection();
    $sqlFile = __DIR__ . '/backfill_orders_delivered.sql';
    if (!file_exists($sqlFile)) {
        http_response_code(500);
        echo "SQL file not found: " . $sqlFile;
        exit;
    }
    $sql = file_get_contents($sqlFile);
    if ($sql === false || trim($sql) === '') {
        http_response_code(500);
        echo "SQL file is empty or unreadable.";
        exit;
    }
    $affected = $db->exec($sql);
    echo "Backfill applied successfully. Rows affected: " . (int)$affected;
} catch (Exception $e) {
    http_response_code(500);
    echo "Backfill error: " . $e->getMessage();
}