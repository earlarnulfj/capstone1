<?php
// Drop and recreate supplier_catalog and supplier_product_variations tables
require_once __DIR__ . '/../config/database.php';

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t");
    $stmt->execute([':t' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    $db = (new Database())->getConnection();
    $pdo = $db;

    // Execute reset DDL (DROP + CREATE)
    $sql = file_get_contents(__DIR__ . '/reset_supplier_catalog.sql');
    if ($sql === false) { throw new RuntimeException('Failed to read reset_supplier_catalog.sql'); }
    $pdo->exec($sql);

    // Quick check: ensure tables exist
    $okCatalog = tableExists($pdo, 'supplier_catalog');
    $okVariations = tableExists($pdo, 'supplier_product_variations');
    if (!$okCatalog || !$okVariations) {
        throw new RuntimeException('Reset incomplete: tables missing after recreate');
    }

    echo "Supplier catalog reset complete. Tables recreated fresh.\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>