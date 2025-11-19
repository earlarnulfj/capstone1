<?php
// Test: Ensure all unit types renderable and mapping covers codes
require_once __DIR__ . '/../lib/unit_variations.php';

function assert_true($cond, $msg) { if (!$cond) { echo "FAIL: $msg\n"; exit(1);} }

// Initial display correctness: every code has a normalized name
foreach ($UNIT_TYPE_CODE_MAP as $code => $norm) {
    assert_true(is_string($code) && $code !== '', "Empty unit code");
    assert_true(is_string($norm) && strpos($norm, 'per ') === 0, "Normalized unit type invalid for '$code'");
}

// Edge cases: special characters not allowed in code map keys
foreach (array_keys($UNIT_TYPE_CODE_MAP) as $code) {
    assert_true(preg_match('/^[A-Za-z0-9]+$/', $code) === 1, "Invalid characters in unit code '$code'");
}

// Variation options exist for some representative codes
foreach (['pc','L','bar'] as $code) {
    $opts = get_unit_variation_options($code);
    assert_true(is_array($opts), "Options not array for code '$code'");
}

echo "OK: Unit types display mapping tests passed\n";
exit(0);