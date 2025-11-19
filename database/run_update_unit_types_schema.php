<?php
require_once __DIR__ . '/../config/database.php';

function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c");
    $stmt->execute([':t' => $table, ':c' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function addColumnIfMissing(PDO $pdo, string $table, string $ddl): void {
    try { $pdo->exec($ddl); } catch (Throwable $e) { /* ignore if already exists or unsupported */ }
}

try {
    $db = (new Database())->getConnection();

    // unit_types columns
    if (!columnExists($db, 'unit_types', 'description')) addColumnIfMissing($db, 'unit_types', "ALTER TABLE unit_types ADD COLUMN description TEXT NULL");
    if (!columnExists($db, 'unit_types', 'metadata')) addColumnIfMissing($db, 'unit_types', "ALTER TABLE unit_types ADD COLUMN metadata TEXT NULL");
    if (!columnExists($db, 'unit_types', 'is_deleted')) addColumnIfMissing($db, 'unit_types', "ALTER TABLE unit_types ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0");
    if (!columnExists($db, 'unit_types', 'deleted_at')) addColumnIfMissing($db, 'unit_types', "ALTER TABLE unit_types ADD COLUMN deleted_at DATETIME NULL");
    if (!columnExists($db, 'unit_types', 'updated_at')) addColumnIfMissing($db, 'unit_types', "ALTER TABLE unit_types ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

    // unit_types indexes
    try { $db->exec("ALTER TABLE unit_types ADD INDEX idx_unit_types_name (name)"); } catch (Throwable $e) {}
    // Enforce unique names to prevent duplicates (best-effort)
    try { $db->exec("ALTER TABLE unit_types ADD UNIQUE KEY uniq_unit_types_name (name)"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE unit_types ADD INDEX idx_unit_types_is_deleted (is_deleted)"); } catch (Throwable $e) {}

    // unit_type_variations columns
    if (!columnExists($db, 'unit_type_variations', 'description')) addColumnIfMissing($db, 'unit_type_variations', "ALTER TABLE unit_type_variations ADD COLUMN description TEXT NULL");
    if (!columnExists($db, 'unit_type_variations', 'metadata')) addColumnIfMissing($db, 'unit_type_variations', "ALTER TABLE unit_type_variations ADD COLUMN metadata TEXT NULL");
    if (!columnExists($db, 'unit_type_variations', 'is_deleted')) addColumnIfMissing($db, 'unit_type_variations', "ALTER TABLE unit_type_variations ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0");
    if (!columnExists($db, 'unit_type_variations', 'deleted_at')) addColumnIfMissing($db, 'unit_type_variations', "ALTER TABLE unit_type_variations ADD COLUMN deleted_at DATETIME NULL");
    if (!columnExists($db, 'unit_type_variations', 'updated_at')) addColumnIfMissing($db, 'unit_type_variations', "ALTER TABLE unit_type_variations ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

    // unit_type_variations indexes
    try { $db->exec("ALTER TABLE unit_type_variations ADD INDEX idx_utv_unit_type_id (unit_type_id)"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE unit_type_variations ADD INDEX idx_utv_attribute (attribute)"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE unit_type_variations ADD INDEX idx_utv_is_deleted (is_deleted)"); } catch (Throwable $e) {}

    echo "Unit types schema updated successfully\n";
} catch (Throwable $e) {
    echo "Error updating unit types schema: " . $e->getMessage() . "\n";
    exit(1);
}
?>