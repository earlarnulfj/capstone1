<?php
// Automated test: validate unit type selection mapping and variation keys
require_once __DIR__ . '/../lib/unit_variations.php';

function assert_true($cond, $msg) {
    if (!$cond) { echo "FAIL: $msg\n"; exit(1); }
}

// Rule: unit_type_code must map to normalized unit_type
foreach ($UNIT_TYPE_CODE_MAP as $code => $norm) {
    $mapped = unit_code_to_type($code);
    assert_true($mapped === $norm, "Code '$code' does not map to '$norm'");
}

// Rule: posted variations must exist under the selected unit type's attribute options
function variation_is_allowed($code, $key) {
    $allowed = get_unit_variation_options($code);
    $parts = explode(':', (string)$key, 2);
    $attr = $parts[0] ?? '';
    $val = $parts[1] ?? '';
    return $attr !== '' && $val !== '' && isset($allowed[$attr]) && in_array($val, $allowed[$attr], true);
}

// Positive cases
assert_true(variation_is_allowed('L', 'Color:Blue'), "Expected Color:Blue allowed for 'L'");
assert_true(variation_is_allowed('bar', 'Size:12mm'), "Expected Size:12mm allowed for 'bar'");
assert_true(variation_is_allowed('pc', 'Type:Claw Hammer'), "Expected Type:Claw Hammer allowed for 'pc'");

// Negative cases
assert_true(!variation_is_allowed('bar', 'Color:Blue'), "Unexpected Color:Blue allowed for 'bar'");
assert_true(!variation_is_allowed('L', 'Size:12mm'), "Unexpected Size:12mm allowed for 'L'");
assert_true(!variation_is_allowed('pc', 'Brand:'), "Empty value should be invalid");

echo "OK: Unit type selection and variation mapping tests passed\n";
exit(0);