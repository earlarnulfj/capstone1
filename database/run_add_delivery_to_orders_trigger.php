<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: text/plain; charset=utf-8');
try {
    $db = (new Database())->getConnection();
    $sqlFile = __DIR__ . '/add_delivery_to_orders_trigger.sql';
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
    // PDO->exec won't handle DELIMITER; split by DELIMITER blocks safely.
    $statements = preg_split('/DELIMITER \$\$|\$\$\nDELIMITER ;/m', $sql);
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        $db->exec($stmt);
    }
    echo "Trigger applied successfully: sync_order_on_delivery_delivered.";
} catch (Exception $e) {
    http_response_code(500);
    echo "Migration error: " . $e->getMessage();
}