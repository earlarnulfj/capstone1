<?php
// Create supplier_catalog and supplier_product_variations tables and migrate existing supplier-linked inventory rows
require_once __DIR__ . '/../config/database.php';

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t");
    $stmt->execute([':t' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    $db = (new Database())->getConnection();
    $pdo = $db;

    // 1) Create tables if not exist (DDL autocommit)
    $sql = file_get_contents(__DIR__ . '/add_supplier_catalog.sql');
    if ($sql === false) { throw new RuntimeException('Failed to read add_supplier_catalog.sql'); }
    $pdo->exec($sql);

    // 2) Migrate existing supplier products from inventory into supplier_catalog
    //    Use INSERT IGNORE to avoid duplicates on unique (supplier_id, sku)
    $pdo->exec("INSERT IGNORE INTO supplier_catalog (
        supplier_id, sku, name, description, category, unit_price, unit_type,
        supplier_quantity, reorder_threshold, location, image_path, image_url,
        status, is_deleted, source_inventory_id
    )
    SELECT 
        i.supplier_id, i.sku, i.name, i.description, i.category, i.unit_price,
        'per piece', i.supplier_quantity, i.reorder_threshold, i.location, i.image_path, i.image_url,
        'active', 0, i.id
    FROM inventory i
    WHERE i.supplier_id IS NOT NULL AND TRIM(COALESCE(i.sku,'')) <> ''
    ");

    // 3) Migrate variations mapped by source_inventory_id
    //    Note: if inventory_variations table is missing, skip gracefully
    try {
        $pdo->exec("INSERT IGNORE INTO supplier_product_variations (product_id, variation, unit_type, unit_price, stock)
        SELECT sc.id, iv.variation, COALESCE(iv.unit_type, 'per piece'), iv.unit_price, 0
        FROM inventory_variations iv
        JOIN supplier_catalog sc ON sc.source_inventory_id = iv.inventory_id");
    } catch (Exception $e) { /* ignore if inventory_variations missing */ }

    echo "Supplier catalog tables ensured and migration completed.\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>