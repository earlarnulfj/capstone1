<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = (new Database())->getConnection();
    $sql = file_get_contents(__DIR__ . '/drop_unit_types.sql');
    if ($sql === false) {
        throw new Exception('Failed to read drop_unit_types.sql');
    }
    $db->exec($sql);
    echo "Unit types schema dropped successfully\n";
} catch (Throwable $e) {
    echo "Error dropping unit types schema: " . $e->getMessage() . "\n";
    exit(1);
}