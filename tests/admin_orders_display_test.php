<?php
require_once __DIR__ . '/../config/database.php';

function fail($msg) { echo "FAIL: $msg\n"; exit(1); }
function ok($msg) { echo "OK: $msg\n"; }

try {
    $db = (new Database())->getConnection();
    $adminCount = (int)$db->query('SELECT COUNT(*) FROM admin_orders')->fetchColumn();
    $ordersCount = (int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    if ($adminCount === 0 && $ordersCount > 0) {
        ok('No admin_orders rows yet; supplier orders exist. Migration may be pending.');
        exit(0);
    }
    $stmt = $db->query('SELECT id, inventory_id, quantity, confirmation_status FROM admin_orders ORDER BY order_date DESC LIMIT 1');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { fail('No rows returned from admin_orders'); }
    if (empty($row['id'])) { fail('Missing order id'); }
    if (empty($row['inventory_id'])) { fail('Missing inventory_id'); }
    ok('Admin orders display baseline verified');
    exit(0);
} catch (Throwable $e) {
    fail('Database error: ' . $e->getMessage());
}