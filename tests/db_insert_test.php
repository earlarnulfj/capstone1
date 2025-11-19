<?php
// Minimal unit test for DB insert operation: insert into unit_types and verify persistence
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function assertTrue(bool $cond, string $msg): void {
    if (!$cond) {
        echo "FAIL: $msg\n";
        exit(1);
    }
}

function pass(string $msg): void {
    echo "PASS: $msg\n";
}

try {
    $db = (new Database())->getConnection();
    pass('DB connection ok');

    // Keep code length <= 16 (schema constraint)
    $code = 'ut_' . substr(str_replace(['.', ' '], '', microtime()), -13);
    $name = 'Unit Test Insert';

    // Basic validations
    assertTrue((bool)preg_match('/^[a-z0-9_]{2,16}$/', $code), 'invalid code format');
    assertTrue(strlen($name) >= 1 && strlen($name) <= 64, 'invalid name length');

    // Use transaction
    $db->beginTransaction();
    $ins = $db->prepare('INSERT INTO unit_types (code, name) VALUES (:code, :name)');
    $ok  = $ins->execute([':code' => $code, ':name' => $name]);
    assertTrue($ok, 'Insert execute failed');
    $db->commit();
    pass('Insert committed');

    // Verify persistence
    $sel = $db->prepare('SELECT id, code, name FROM unit_types WHERE code = :code LIMIT 1');
    $sel->execute([':code' => $code]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    assertTrue((bool)$row && isset($row['id']), 'Inserted row not found');
    pass('Row found: id=' . (int)$row['id']);

    // Cleanup (soft delete if available)
    try {
        $upd = $db->prepare('UPDATE unit_types SET is_deleted = 1, deleted_at = NOW() WHERE id = :id');
        $upd->execute([':id' => (int)$row['id']]);
        pass('Soft-deleted test row');
    } catch (Throwable $e) {
        // If columns do not exist, ignore cleanup
    }

    echo "\nALL TESTS PASSED\n";
    exit(0);
} catch (Throwable $e) {
    echo 'FAIL: Exception - ' . $e->getMessage() . "\n";
    exit(1);
}
?>