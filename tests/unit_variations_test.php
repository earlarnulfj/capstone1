<?php
require_once __DIR__ . '/../lib/unit_variations.php';

function assert_true($cond, $msg) {
    if (!$cond) {
        echo "FAIL: $msg\n";
        exit(1);
    }
}

// Verify unit code map contains all expected keys
$expectedCodes = ['pc','set','box','pack','bag','roll','bar','sheet','m','L','gal','tube','btl','can','sack'];
foreach ($expectedCodes as $code) {
    assert_true(isset($UNIT_TYPE_CODE_MAP[$code]), "Missing unit code: $code");
}

// Verify variation options include examples
$piece = get_unit_variation_options('pc');
assert_true(isset($piece['Type']), 'Piece unit missing Type attribute');
assert_true(in_array('Claw Hammer',$piece['Type']), 'Piece unit missing Claw Hammer');
assert_true(in_array('Sledge Hammer',$piece['Type']), 'Piece unit missing Sledge Hammer');

$liter = get_unit_variation_options('L');
assert_true(isset($liter['Color']) && isset($liter['Brand']), 'Liter unit missing Color/Brand');

$bar = get_unit_variation_options('bar');
assert_true(isset($bar['Size']), 'Bar unit missing Size');
assert_true(in_array('10mm',$bar['Size']) && in_array('12mm',$bar['Size']) && in_array('16mm',$bar['Size']), 'Bar size options incorrect');

echo "OK: unit variation mappings verified\n";