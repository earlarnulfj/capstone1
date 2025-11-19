<?php
// Basic integration smoke test for admin inventory delete API
session_start();
$_SESSION['admin'] = ['user_id' => 1, 'role' => 'management'];
$_SESSION['csrf_token'] = 'testtoken';

// Use POST fallback for input
$_POST = ['id' => 1, 'force_delete' => '0', 'csrf_token' => 'testtoken'];

ob_start();
// Directly include the endpoint
require __DIR__ . '/../admin/api/delete_inventory_item.php';
$out = ob_get_clean();
echo $out, "\n";
// Consider any JSON output a pass for smoke test
json_decode($out, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    exit(1);
}
exit(0);