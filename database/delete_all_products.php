<?php
// CLI-only script to delete ALL products and tightly related records
// This removes admin inventory and supplier catalog items, their variations,
// orders/deliveries/payments tied to products, alert logs, sales transactions,
// and product images in uploads/products.
// Usage: php database/delete_all_products.php

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
    echo "Starting full products purge..." . PHP_EOL;
    log_line('Starting full products purge');

    // Disable FK checks for bulk deletes across related tables
    try { $db->exec('SET FOREIGN_KEY_CHECKS = 0'); } catch (Throwable $e) {}

    // Helper: count table rows
    $count = function($table) use ($db) {
        try {
            $stmt = $db->query("SELECT COUNT(*) FROM `{$table}`");
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) { return 0; }
    };

    $deleted = [];

    // 1) Notifications referencing alert_logs
    $notifBefore = $count('notifications');
    try {
        $db->exec("DELETE FROM notifications");
        $deleted['notifications'] = $notifBefore;
    } catch (Throwable $e) { $deleted['notifications'] = 0; }

    // 2) Deliveries and payments (children of orders)
    $delivBefore = $count('deliveries');
    try { $db->exec("DELETE FROM deliveries"); $deleted['deliveries'] = $delivBefore; } catch (Throwable $e) { $deleted['deliveries'] = 0; }
    $payBefore = $count('payments');
    try { $db->exec("DELETE FROM payments"); $deleted['payments'] = $payBefore; } catch (Throwable $e) { $deleted['payments'] = 0; }

    // 3) Sales transactions (child of inventory)
    $salesBefore = $count('sales_transactions');
    try { $db->exec("DELETE FROM sales_transactions"); $deleted['sales_transactions'] = $salesBefore; } catch (Throwable $e) { $deleted['sales_transactions'] = 0; }

    // 4) Inventory variations (child of inventory)
    $invVarBefore = $count('inventory_variations');
    try { $db->exec("DELETE FROM inventory_variations"); $deleted['inventory_variations'] = $invVarBefore; } catch (Throwable $e) { $deleted['inventory_variations'] = 0; }

    // 5) Orders (may reference inventory)
    $ordersBefore = $count('orders');
    try { $db->exec("DELETE FROM orders"); $deleted['orders'] = $ordersBefore; } catch (Throwable $e) { $deleted['orders'] = 0; }

    // 6) Alert logs
    $alertsBefore = $count('alert_logs');
    try { $db->exec("DELETE FROM alert_logs"); $deleted['alert_logs'] = $alertsBefore; } catch (Throwable $e) { $deleted['alert_logs'] = 0; }

    // 7) Supplier product variations (child of supplier_catalog)
    $spvBefore = $count('supplier_product_variations');
    try { $db->exec("DELETE FROM supplier_product_variations"); $deleted['supplier_product_variations'] = $spvBefore; } catch (Throwable $e) { $deleted['supplier_product_variations'] = 0; }

    // 8) Supplier catalog (supplier products)
    $scBefore = $count('supplier_catalog');
    try { $db->exec("DELETE FROM supplier_catalog"); $deleted['supplier_catalog'] = $scBefore; } catch (Throwable $e) { $deleted['supplier_catalog'] = 0; }

    // 9) Inventory (admin products)
    $invBefore = $count('inventory');
    try { $db->exec("DELETE FROM inventory"); $deleted['inventory'] = $invBefore; } catch (Throwable $e) { $deleted['inventory'] = 0; }

    // Re-enable FK checks
    try { $db->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (Throwable $e) {}

    // 10) Delete product images in uploads/products
    $uploadsDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products';
    $deletedImages = 0;
    if (is_dir($uploadsDir)) {
        $dh = opendir($uploadsDir);
        if ($dh) {
            while (($file = readdir($dh)) !== false) {
                if ($file === '.' || $file === '..') { continue; }
                $full = $uploadsDir . DIRECTORY_SEPARATOR . $file;
                if (is_file($full)) { @unlink($full); $deletedImages++; }
            }
            closedir($dh);
        }
    }

    // Summary output
    echo "Products purge completed." . PHP_EOL;
    echo "Deleted counts:" . PHP_EOL;
    foreach ($deleted as $tbl => $cnt) {
        echo " - {$tbl}: {$cnt}" . PHP_EOL;
    }
    echo " - product_images: {$deletedImages}" . PHP_EOL;

    log_line('Full products purge completed: ' . json_encode($deleted) . ", images=" . $deletedImages);
    exit(0);
} catch (Throwable $e) {
    log_line('Full products purge failed: ' . $e->getMessage());
    fwrite(STDERR, "Purge failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

?>