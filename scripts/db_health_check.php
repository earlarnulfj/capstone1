<?php
// Database health-check: verifies connection, insert flow, logs, validation, and transactions
// Steps covered: (1) verify DB connection, (2) validate and run insert, (3) log errors,
// (4) use transaction + error handling, (5) pre-save validation, (7) monitor after insert.

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';

$logFile = __DIR__ . '/../logs/db_health.log';

function log_line(string $message): void {
    global $logFile;
    $ts = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$ts] $message\n", FILE_APPEND);
}

header('Content-Type: application/json');

$result = [
    'connection' => null,
    'server' => null,
    'insert_attempt' => null,
    'persisted' => false,
    'soft_deleted' => false,
    'error' => null,
];

try {
    // (1) Verify database connection and configuration
    $db = (new Database())->getConnection();
    $result['connection'] = 'ok';
    log_line('DB connection established.');

    // Fetch server details for verification
    $infoStmt = $db->query("SELECT DATABASE() AS db, @@hostname AS host, @@version AS version");
    $info = $infoStmt->fetch(PDO::FETCH_ASSOC);
    $result['server'] = $info;
    log_line('Server info: ' . json_encode($info));

    // (5) Validation before save
    // Generate code within 16 chars (table constraint is VARCHAR(16))
    $code = 'hlth_' . substr(str_replace(['.', ' '], '', microtime()), -11);
    $name = 'Health Test Unit';

    // Basic validations
    if (!preg_match('/^[a-z0-9_]{2,16}$/', $code)) {
        throw new RuntimeException('Validation failed: invalid code format');
    }
    if (strlen($name) < 1 || strlen($name) > 64) {
        throw new RuntimeException('Validation failed: invalid name length');
    }

    // Ensure uniqueness (best-effort)
    $checkStmt = $db->prepare('SELECT COUNT(*) FROM unit_types WHERE code = :code LIMIT 1');
    $checkStmt->execute([':code' => $code]);
    if ((int)$checkStmt->fetchColumn() > 0) {
        $code .= substr((string)microtime(true), -2);
    }

    // (4) Use transaction and error handling
    $db->beginTransaction();
    try {
        // (2) Insert with prepared statement; minimal required columns
        $ins = $db->prepare('INSERT INTO unit_types (code, name) VALUES (:code, :name)');
        $ok  = $ins->execute([':code' => $code, ':name' => $name]);
        $result['insert_attempt'] = $ok ? 'ok' : 'failed';
        log_line('Insert executed: ' . ($ok ? 'ok' : 'failed'));

        if (!$ok) {
            $err = $ins->errorInfo();
            throw new RuntimeException('Insert failed: ' . json_encode($err));
        }

        $db->commit();
        log_line('Transaction committed.');
    } catch (Throwable $t) {
        $db->rollBack();
        log_line('Transaction rolled back due to error: ' . $t->getMessage());
        throw $t;
    }

    // (7) Monitor database after insert
    $sel = $db->prepare('SELECT id, code, name FROM unit_types WHERE code = :code LIMIT 1');
    $sel->execute([':code' => $code]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['id'])) {
        $result['persisted'] = true;
        $result['inserted'] = $row; // include minimal inserted record snapshot
        log_line('Inserted row verified: ' . json_encode($row));

        // Attempt soft delete to avoid clutter (best-effort if column exists)
        try {
            $upd = $db->prepare('UPDATE unit_types SET is_deleted = 1, deleted_at = NOW() WHERE id = :id');
            $upd->execute([':id' => (int)$row['id']]);
            $result['soft_deleted'] = true;
            log_line('Soft-deleted inserted row id=' . (int)$row['id']);
        } catch (Throwable $e) {
            // Column may not exist; ignore
            log_line('Soft delete not applied: ' . $e->getMessage());
        }
    } else {
        log_line('Inserted row not found during monitoring.');
    }
} catch (Throwable $e) {
    $result['error'] = $e->getMessage();
    log_line('Health-check error: ' . $e->getMessage());
    http_response_code(500);
}

echo json_encode($result);
?>