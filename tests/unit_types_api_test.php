<?php
// Smoke tests for Unit Types and Unit Type Variations APIs
header('Content-Type: text/plain');

// Create unit type (use form POST fallback)
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['code' => 'apitest_' . rand(1000,9999), 'name' => 'API Test'];
ob_start(); require __DIR__ . '/../api/unit_types.php'; $out = ob_get_clean();
$created = json_decode($out, true);
if (!is_array($created) || !isset($created['id'])) { echo "FAIL: create unit type\n"; exit(1);} 
$utId = (int)$created['id'];

// List unit types
$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start(); require __DIR__ . '/../api/unit_types.php'; $listOut = ob_get_clean();
$list = json_decode($listOut, true);
if (!is_array($list)) { echo "FAIL: list unit types\n"; exit(1);} 

// Create variation (form POST fallback)
$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['unit_type_id' => $utId, 'attribute' => 'Size', 'value' => 'Large'];
ob_start(); require __DIR__ . '/../api/unit_type_variations.php'; $vOut = ob_get_clean();
$vCreated = json_decode($vOut, true);
if (!is_array($vCreated) || !isset($vCreated['id'])) { echo "FAIL: create variation\n"; exit(1);} 

// List variations by unit type
$_GET = ['unit_type_id' => $utId];
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start(); require __DIR__ . '/../api/unit_type_variations.php'; $vListOut = ob_get_clean();
$vList = json_decode($vListOut, true);
if (!is_array($vList)) { echo "FAIL: list variations\n"; exit(1);} 

echo "OK: Unit types and variations API smoke tests passed\n";
exit(0);