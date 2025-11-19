<?php
header('Content-Type: application/json');

// Namespaced sessions and access control
include_once '../../config/session.php';

try {
    // Only allow admin and staff. Explicitly block supplier.
    $isAdmin = !empty($_SESSION['admin']['user_id']);
    $isStaff = !empty($_SESSION['staff']['user_id']);
    $isSupplier = !empty($_SESSION['supplier']['user_id']);

    if (!$isAdmin && !$isStaff) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    if ($isSupplier) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden for suppliers']);
        exit;
    }

    // Monitor file changes for admin/inventory.php and aggregated admin cache versions
    $rootDir = dirname(__DIR__, 2);
    $adminInventoryFile = $rootDir . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'inventory.php';
    $versionsFile = $rootDir . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'admin_inventory' . DIRECTORY_SEPARATOR . 'versions.json';

    $inventoryMtime = file_exists($adminInventoryFile) ? (int)filemtime($adminInventoryFile) : 0;

    $latestVersion = 0;
    $versionsChecksum = null;
    if (file_exists($versionsFile)) {
        $raw = file_get_contents($versionsFile);
        $versions = json_decode($raw, true);
        if (is_array($versions)) {
            foreach ($versions as $invId => $ver) {
                $latestVersion = max($latestVersion, (int)$ver);
            }
        }
        $versionsChecksum = md5($raw);
    }

    // Client-provided last seen state
    $lastVersionParam = isset($_GET['last_version']) ? (int)$_GET['last_version'] : 0;
    $lastMtimeParam   = isset($_GET['last_mtime']) ? (int)$_GET['last_mtime'] : 0;

    $changed = ($inventoryMtime > $lastMtimeParam) || ($latestVersion > $lastVersionParam);

    // Logging
    $logDir = $rootDir . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDir)) { @mkdir($logDir, 0777, true); }
    $logFile = $logDir . DIRECTORY_SEPARATOR . 'sync_events.log';
    $who = $isAdmin ? 'admin' : 'staff';
    $logMsg = sprintf('[%s] inventory_change_feed mtime=%d latest_version=%d changed=%s by=%s', date('Y-m-d H:i:s'), $inventoryMtime, $latestVersion, $changed ? 'yes' : 'no', $who);
    @error_log($logMsg . "\n", 3, $logFile);

    echo json_encode([
        'success' => true,
        'inventory_mtime' => $inventoryMtime,
        'latest_version' => $latestVersion,
        'checksum' => $versionsChecksum,
        'changed' => $changed,
    ]);
} catch (Throwable $e) {
    $rootDir = dirname(__DIR__, 2);
    $logDir = $rootDir . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDir)) { @mkdir($logDir, 0777, true); }
    $logFile = $logDir . DIRECTORY_SEPARATOR . 'sync_events.log';
    @error_log('[' . date('Y-m-d H:i:s') . '] inventory_change_feed error: ' . $e->getMessage() . "\n", 3, $logFile);

    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}