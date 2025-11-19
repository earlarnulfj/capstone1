<?php
session_start();
require_once '../../config/app.php';
require_once '../../config/database.php';
require_once '../../models/inventory.php';
require_once '../../lib/audit.php';
require_once '../../lib/sync_helpers.php';

header('Content-Type: application/json');
// Ensure PHP does not print HTML errors/warnings that break JSON responses
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');

// Authn/authz
if (empty($_SESSION['admin']['user_id']) || ($_SESSION['admin']['role'] ?? null) !== 'management') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Input
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { $data = $_POST; }

$id = isset($data['id']) ? (int)$data['id'] : 0;
$forceDelete = isset($data['force_delete']) && (string)$data['force_delete'] === '1';
$csrfToken = $data['csrf_token'] ?? '';

// CSRF
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

try {
    // Convert warnings/notices into exceptions so they are caught and returned as JSON
    set_error_handler(function($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) { return false; }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    $db = (new Database())->getConnection();
    // Fetch counts and existing row
    $stmt = $db->prepare("SELECT * FROM inventory WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        echo json_encode(['success' => false, 'message' => 'Item not found']);
        exit;
    }

    // Order counts
    $stmt = $db->prepare("SELECT 
        SUM(CASE WHEN confirmation_status = 'pending' THEN 1 ELSE 0 END) AS pending_orders,
        SUM(CASE WHEN confirmation_status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_orders
        FROM orders WHERE inventory_id = ?");
    $stmt->execute([$id]);
    $orderCounts = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['pending_orders'=>0,'confirmed_orders'=>0];

    // Delivered count
    $stmt = $db->prepare("SELECT COUNT(*) FROM deliveries d JOIN orders o ON d.order_id = o.id WHERE o.inventory_id = ? AND d.status = 'delivered'");
    $stmt->execute([$id]);
    $deliveredCount = (int)$stmt->fetchColumn();

    // Sales count
    $stmt = $db->prepare("SELECT COUNT(*) FROM sales_transactions WHERE inventory_id = ?");
    $stmt->execute([$id]);
    $salesCount = (int)$stmt->fetchColumn();

    // Soft-delete path when constraints and no force
    if (!$forceDelete && (($orderCounts['confirmed_orders'] ?? 0) > 0 || $deliveredCount > 0 || $salesCount > 0)) {
        // Ensure column exists
        try {
            $chk = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory' AND COLUMN_NAME = 'is_deleted'");
            $chk->execute();
            $hasCol = (bool)$chk->fetchColumn();
            if (!$hasCol) { $db->exec("ALTER TABLE inventory ADD COLUMN is_deleted TINYINT(1) DEFAULT 0"); }
        } catch (Throwable $e) { /* ignore */ }
        $stmt = $db->prepare("UPDATE inventory SET is_deleted = 1 WHERE id = ?");
        $stmt->execute([$id]);
        audit_log_event($db, 'inventory_soft_delete', 'inventory', $id, 'archived', true, 'Soft-deleted due to existing related records', ['source'=>'admin_ui','target'=>'pos_clients']);
        echo json_encode(['success' => true, 'soft_deleted' => true, 'message' => 'Item archived due to related records.']);
        exit;
    }

    // Hard delete path with cascade cleanup
    $db->beginTransaction();
    try {
        // Row lock
        try { $lock = $db->prepare("SELECT id FROM inventory WHERE id = ? FOR UPDATE"); $lock->execute([$id]); } catch (Throwable $e) {}

        // Cancel pending orders
        if ((int)($orderCounts['pending_orders'] ?? 0) > 0) {
            $stmt = $db->prepare("UPDATE orders SET confirmation_status = 'cancelled' WHERE inventory_id = ? AND confirmation_status = 'pending'");
            $stmt->execute([$id]);
        }
        // Collect target orders (force=all, else only cancelled)
        if ($forceDelete) {
            $stmt = $db->prepare("SELECT id FROM orders WHERE inventory_id = ?");
        } else {
            $stmt = $db->prepare("SELECT id FROM orders WHERE inventory_id = ? AND confirmation_status = 'cancelled'");
        }
        $stmt->execute([$id]);
        $orderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($orderIds)) {
            $ph = implode(',', array_fill(0, count($orderIds), '?'));
            $stmt = $db->prepare("DELETE FROM notifications WHERE order_id IN ($ph)");
            $stmt->execute($orderIds);
            $stmt = $db->prepare("DELETE FROM deliveries WHERE order_id IN ($ph)");
            $stmt->execute($orderIds);
            $stmt = $db->prepare("DELETE FROM payments WHERE order_id IN ($ph)");
            $stmt->execute($orderIds);
            $stmt = $db->prepare("DELETE FROM orders WHERE id IN ($ph)");
            $stmt->execute($orderIds);
        }

        // Sales transactions
        if ($forceDelete && $salesCount > 0) {
            $stmt = $db->prepare("DELETE FROM sales_transactions WHERE inventory_id = ?");
            $stmt->execute([$id]);
        }

        // Alerts and notifications referencing alerts
        $stmt = $db->prepare("SELECT id FROM alert_logs WHERE inventory_id = ?");
        $stmt->execute([$id]);
        $alertIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($alertIds)) {
            $ph = implode(',', array_fill(0, count($alertIds), '?'));
            $stmt = $db->prepare("DELETE FROM notifications WHERE alert_id IN ($ph)");
            $stmt->execute($alertIds);
        }
        $stmt = $db->prepare("DELETE FROM alert_logs WHERE inventory_id = ?");
        $stmt->execute([$id]);

        // Variations
        try { $stmt = $db->prepare("DELETE FROM inventory_variations WHERE inventory_id = ?"); $stmt->execute([$id]); } catch (Throwable $e) {}

        // Delete inventory row
        $stmt = $db->prepare("DELETE FROM inventory WHERE id = ?");
        $ok = $stmt->execute([$id]);
        if (!$ok) { throw new Exception('Delete failed'); }

        $db->commit();

        // Real-time sync bump (triggers admin change feed consumers)
        bump_admin_inventory_version($db, (int)$id, $existing['unit_type'] ?? 'per piece', isset($existing['unit_price']) ? (float)$existing['unit_price'] : null);

        // Best-effort: mark the change record as deleted without changing version again
        try {
            $baseCacheDir = dirname(__DIR__, 2) . '/cache/admin_inventory';
            $changesFile = $baseCacheDir . "/changes/inventory_{$id}.json";
            if (file_exists($changesFile)) {
                $raw = file_get_contents($changesFile);
                $rec = json_decode($raw, true);
                if (!is_array($rec)) { $rec = []; }
                $rec['deleted'] = true;
                $rec['timestamp'] = date('c');
                @file_put_contents($changesFile, json_encode($rec, JSON_PRETTY_PRINT));
            }
        } catch (Throwable $e) { /* ignore cache write failures */ }

        // Best-effort: remove product image from uploads directory if present
        try {
            $imgPath = trim((string)($existing['image_path'] ?? ''));
            if ($imgPath !== '') {
                $uploadsDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products';
                $fullPath = $uploadsDir . DIRECTORY_SEPARATOR . basename($imgPath);
                if (is_file($fullPath)) { @unlink($fullPath); }
            }
        } catch (Throwable $e) { /* ignore filesystem cleanup failures */ }

        audit_log_event($db, 'inventory_crud_sync', 'inventory', (int)$id, 'deleted', true, 'Inventory item deleted: ' . ($existing['name'] ?? ''), ['source'=>'admin_ui','target'=>'pos_clients']);
        echo json_encode(['success' => true, 'message' => 'Inventory item deleted successfully']);
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        audit_log_event($db, 'inventory_crud_sync', 'inventory', (int)$id, 'deleted', false, 'Delete exception: ' . $e->getMessage(), ['source'=>'admin_ui','target'=>'pos_clients']);
        echo json_encode(['success' => false, 'message' => 'Deletion failed: ' . $e->getMessage()]);
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}