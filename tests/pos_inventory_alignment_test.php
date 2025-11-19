<?php
// Verify that Admin Ordered Products in inventory.php aligns with products shown in admin_pos.php
// This is a lightweight backend test that compares item sets by inventory_id

require_once __DIR__ . '/../config/database.php';

function fail($msg) { echo "FAIL: $msg\n"; exit(1); }
function ok($msg) { echo "OK: $msg\n"; }

try {
    $db = (new Database())->getConnection();
    // Assume an admin user exists; pick latest admin user_id from admin sessions if available
    // Fallback to any orders without user filter for broad consistency check
    $adminId = null;
    if (!empty($_SESSION['admin']['user_id'])) { $adminId = (int)$_SESSION['admin']['user_id']; }

    // POS baseline: distinct inventory IDs from COMPLETED admin_orders
    $posSql = "SELECT DISTINCT inventory_id FROM admin_orders WHERE inventory_id IS NOT NULL AND confirmation_status = 'completed'";
    $posStmt = $db->prepare($posSql);
    $posStmt->execute();
    $posIds = $posStmt->fetchAll(PDO::FETCH_COLUMN);
    $posIds = array_map('intval', $posIds);

    // Inventory Admin Ordered Products: filter by admin if available, include confirmed/completed
    $invSql = "SELECT DISTINCT i.id
               FROM admin_orders ao
               INNER JOIN inventory i ON i.id = ao.inventory_id
               WHERE ao.confirmation_status IN ('confirmed','completed')";
    if ($adminId) { $invSql .= " AND ao.user_id = :admin_id"; }
    $invStmt = $db->prepare($invSql);
    if ($adminId) { $invStmt->bindValue(':admin_id', $adminId, PDO::PARAM_INT); }
    $invStmt->execute();
    $invIds = $invStmt->fetchAll(PDO::FETCH_COLUMN);
    $invIds = array_map('intval', $invIds);

    if (!is_array($posIds) || !is_array($invIds)) { fail('Query results are not arrays'); }
    if (empty($posIds)) { ok('No POS items from completed admin_orders; skipping subset check'); exit(0); }

    // Inventory IDs should be a subset of POS IDs (admin filter narrows further)
    $diff = array_values(array_diff($invIds, $posIds));
    if (!empty($diff)) { fail('Inventory Admin Ordered Products includes IDs not present in POS baseline: ' . implode(',', $diff)); }

    // Basic field presence check for one sample ID
    $sampleId = isset($invIds[0]) ? (int)$invIds[0] : 0;
    if ($sampleId > 0) {
        $chk = $db->prepare("SELECT sku, name, category FROM inventory WHERE id = ?");
        $chk->execute([$sampleId]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['name'])) { fail('Missing name for sample inventory item'); }
    }

    ok('Admin POS and Inventory alignment verified');
    exit(0);
} catch (Throwable $e) {
    fail('Database error: ' . $e->getMessage());
}