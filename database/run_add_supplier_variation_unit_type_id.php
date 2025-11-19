<?php
// Run migration to add unit_type_id to supplier_product_variations
require_once __DIR__ . '/../config/database.php';

try {
    $db = (new Database())->getConnection();
    if (!$db) { throw new Exception('DB connection not available'); }

    $sqlFile = __DIR__ . '/add_supplier_variation_unit_type_id.sql';
    if (!file_exists($sqlFile)) { throw new Exception('Migration file not found: ' . $sqlFile); }
    $sql = file_get_contents($sqlFile);
    if ($sql === false || trim($sql) === '') { throw new Exception('Failed to read migration SQL'); }

    // Execute multiple statements safely
    $db->exec($sql);
    echo "Migration add_supplier_variation_unit_type_id applied successfully\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>