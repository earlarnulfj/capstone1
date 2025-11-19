<?php
// Basic integration smoke test for supplier catalog delete API
session_start();
$_SESSION['supplier'] = ['user_id' => 1];
$_SESSION['csrf_token'] = 'testtoken';

// Use POST fallback for input
$_POST = ['id' => 1, 'csrf_token' => 'testtoken'];

ob_start();
require __DIR__ . '/../supplier/api/delete_catalog_item.php';
$out = ob_get_clean();
echo $out, "\n";
json_decode($out, true);
if (json_last_error() !== JSON_ERROR_NONE) { exit(1); }
exit(0);