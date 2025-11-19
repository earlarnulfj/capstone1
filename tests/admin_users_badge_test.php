<?php
// Simple unit-like test for supplier badge rendering logic

function renderRoleBadge(array $row): string {
    if (($row['role'] ?? null) === 'management') {
        return '<span class="badge bg-primary">Management</span>';
    } elseif (($row['role'] ?? null) === 'supplier') {
        $label = htmlspecialchars($row['supplier_badge'] ?? 'Supplier');
        return '<span class="badge bg-success supplier-badge"><i class="bi bi-award me-1"></i>' . $label . '</span>';
    }
    return '<span class="badge bg-secondary">Staff</span>';
}

$tests = [
    ['input' => ['role' => 'management'], 'expectContains' => 'Management'],
    ['input' => ['role' => 'supplier', 'supplier_badge' => 'Preferred Supplier'], 'expectContains' => 'Preferred Supplier'],
    ['input' => ['role' => 'supplier'], 'expectContains' => 'Supplier'],
    ['input' => ['role' => 'staff'], 'expectContains' => 'Staff'],
];

$passed = 0; $failed = 0; $results = [];
foreach ($tests as $i => $t) {
    $html = renderRoleBadge($t['input']);
    $ok = strpos($html, $t['expectContains']) !== false;
    $results[] = [ 'case' => $i+1, 'ok' => $ok, 'html' => $html ];
    $ok ? $passed++ : $failed++;
}

header('Content-Type: application/json');
echo json_encode([ 'passed' => $passed, 'failed' => $failed, 'results' => $results ], JSON_PRETTY_PRINT);