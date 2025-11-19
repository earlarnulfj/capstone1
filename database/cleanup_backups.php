<?php
// CLI-only script to delete database backup tables
// It will drop any tables in the current DB whose name contains 'backup'
// Usage: php database/cleanup_backups.php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI only";
    exit(1);
}

require_once __DIR__ . '/../config/database.php';

try {
    $db = (new Database())->getConnection();

    // Find tables with 'backup' in their name (case insensitive)
    $stmt = $db->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND LOWER(table_name) LIKE '%backup%'");
    $stmt->execute();
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($tables)) {
        echo "No backup tables found. Nothing to delete." . PHP_EOL;
        exit(0);
    }

    // Disable FK checks to avoid dependency errors
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');

    foreach ($tables as $t) {
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $t);
        if ($safe !== $t) {
            // Skip suspicious names
            echo "Skipping unsafe table name: {$t}" . PHP_EOL;
            continue;
        }
        $db->exec("DROP TABLE IF EXISTS `{$safe}`");
        echo "Dropped table: {$safe}" . PHP_EOL;
    }

    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    echo "Backup table cleanup completed." . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    try { if (isset($db)) { $db->exec('SET FOREIGN_KEY_CHECKS = 1'); } } catch (Throwable $ignored) {}
    fwrite(STDERR, "Cleanup failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}