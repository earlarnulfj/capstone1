<?php
// CLI-only script to purge dirty products data and orphaned variations/images
// Usage: php database/cleanup_dirty_products.php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI only";
    exit(1);
}

require_once __DIR__ . '/../config/database.php';

function log_line($msg) {
    try {
        $root = dirname(__DIR__);
        $logDir = $root . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) { @mkdir($logDir, 0777, true); }
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'cleanup_inventory.log';
        @error_log('[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", 3, $logFile);
    } catch (Throwable $e) { /* ignore */ }
}

try {
    $db = (new Database())->getConnection();
    echo "Starting dirty products cleanup..." . PHP_EOL;
    log_line('Starting dirty products cleanup');

    // 1) Delete orphan supplier_product_variations (no matching product in supplier_catalog)
    $orphanCount = 0;
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM supplier_product_variations spv LEFT JOIN supplier_catalog sc ON sc.id = spv.product_id WHERE sc.id IS NULL");
        $orphanCount = (int)$stmt->fetchColumn();
    } catch (Throwable $e) { $orphanCount = 0; }
    if ($orphanCount > 0) {
        try {
            $del = $db->prepare("DELETE spv FROM supplier_product_variations spv LEFT JOIN supplier_catalog sc ON sc.id = spv.product_id WHERE sc.id IS NULL");
            $del->execute();
            echo "Deleted orphan variations: {$orphanCount}" . PHP_EOL;
            log_line("Deleted orphan supplier_product_variations: {$orphanCount}");
        } catch (Throwable $e) {
            echo "Failed to delete orphan variations: " . $e->getMessage() . PHP_EOL;
            log_line('Failed to delete orphan variations: ' . $e->getMessage());
        }
    } else {
        echo "No orphan variations found." . PHP_EOL;
    }

    // 2) Hard-delete soft-deleted or deprecated supplier_catalog records
    $softDeletedCount = 0;
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM supplier_catalog WHERE is_deleted = 1 OR status = 'deprecated'");
        $softDeletedCount = (int)$stmt->fetchColumn();
    } catch (Throwable $e) { $softDeletedCount = 0; }
    if ($softDeletedCount > 0) {
        try {
            $aff = $db->exec("DELETE FROM supplier_catalog WHERE is_deleted = 1 OR status = 'deprecated'");
            echo "Purged soft-deleted/deprecated products: {$aff}" . PHP_EOL;
            log_line("Purged soft-deleted/deprecated supplier_catalog rows: {$aff}");
        } catch (Throwable $e) {
            echo "Failed to purge soft-deleted/deprecated products: " . $e->getMessage() . PHP_EOL;
            log_line('Failed to purge soft-deleted/deprecated products: ' . $e->getMessage());
        }
    } else {
        echo "No soft-deleted/deprecated products found." . PHP_EOL;
    }

    // 3) Delete known sample seeded SKUs (from supplier/products.php seed)
    $sampleSkus = [
        'HW-NAIL-001','HW-HAM-001','HW-PNT-001','HW-CEM-001',
        'HW-WIR-001','HW-TIL-001','HW-PWD-001','HW-ROP-001'
    ];
    $sampleCount = 0;
    try {
        $ph = implode(',', array_fill(0, count($sampleSkus), '?'));
        $chk = $db->prepare("SELECT COUNT(*) FROM supplier_catalog WHERE sku IN ($ph)");
        $chk->execute($sampleSkus);
        $sampleCount = (int)$chk->fetchColumn();
    } catch (Throwable $e) { $sampleCount = 0; }
    if ($sampleCount > 0) {
        try {
            $ph = implode(',', array_fill(0, count($sampleSkus), '?'));
            $del = $db->prepare("DELETE FROM supplier_catalog WHERE sku IN ($ph)");
            $del->execute($sampleSkus);
            echo "Removed sample seeded products: {$sampleCount}" . PHP_EOL;
            log_line("Removed sample seeded SKUs from supplier_catalog: {$sampleCount}");
        } catch (Throwable $e) {
            echo "Failed to remove sample SKUs: " . $e->getMessage() . PHP_EOL;
            log_line('Failed to remove sample SKUs: ' . $e->getMessage());
        }
    } else {
        echo "No sample seeded products present." . PHP_EOL;
    }

    // 4) Delete malformed supplier_catalog rows (blank name/sku)
    $malformedCount = 0;
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM supplier_catalog WHERE TRIM(COALESCE(name,'')) = '' OR TRIM(COALESCE(sku,'')) = ''");
        $malformedCount = (int)$stmt->fetchColumn();
    } catch (Throwable $e) { $malformedCount = 0; }
    if ($malformedCount > 0) {
        try {
            $aff = $db->exec("DELETE FROM supplier_catalog WHERE TRIM(COALESCE(name,'')) = '' OR TRIM(COALESCE(sku,'')) = ''");
            echo "Deleted malformed supplier_catalog rows: {$aff}" . PHP_EOL;
            log_line("Deleted malformed supplier_catalog rows: {$aff}");
        } catch (Throwable $e) {
            echo "Failed to delete malformed rows: " . $e->getMessage() . PHP_EOL;
            log_line('Failed to delete malformed supplier_catalog rows: ' . $e->getMessage());
        }
    } else {
        echo "No malformed supplier_catalog rows found." . PHP_EOL;
    }

    // 5) Orphan product image cleanup in uploads/products (not referenced by inventory or supplier_catalog)
    // Build set of referenced filenames
    $referenced = [];
    try {
        $q = $db->query("SELECT image_path FROM inventory WHERE image_path IS NOT NULL AND image_path <> ''
                          UNION SELECT image_path FROM supplier_catalog WHERE image_path IS NOT NULL AND image_path <> ''");
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
            $p = $row['image_path'];
            if (!is_string($p) || $p === '') { continue; }
            $basename = basename($p);
            if ($basename) { $referenced[$basename] = true; }
        }
    } catch (Throwable $e) { /* ignore */ }

    $uploadsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products';
    $deletedImages = 0;
    if (is_dir($uploadsDir)) {
        $dh = opendir($uploadsDir);
        if ($dh) {
            while (($file = readdir($dh)) !== false) {
                if ($file === '.' || $file === '..') { continue; }
                $full = $uploadsDir . DIRECTORY_SEPARATOR . $file;
                if (!is_file($full)) { continue; }
                // Delete if not referenced
                if (!isset($referenced[$file])) {
                    @unlink($full);
                    $deletedImages++;
                }
            }
            closedir($dh);
        }
    }
    echo "Deleted orphan product images: {$deletedImages}" . PHP_EOL;
    log_line("Deleted orphan product images: {$deletedImages}");

    echo "Dirty products cleanup completed." . PHP_EOL;
    log_line('Dirty products cleanup completed');
    exit(0);
} catch (Throwable $e) {
    log_line('Cleanup failed: ' . $e->getMessage());
    fwrite(STDERR, "Cleanup failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

?>