<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: text/plain; charset=utf-8');
try {
    $db = (new Database())->getConnection();
    $sqlFile = __DIR__ . '/add_sync_events_table.sql';
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
    $db->exec($sql);
    echo "Migration applied successfully. sync_events table created.";
} catch (Exception $e) {
    http_response_code(500);
    echo "Migration error: " . $e->getMessage();
}