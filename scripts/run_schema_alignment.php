<?php
require_once __DIR__ . '/../config/database.php';

echo "Running schema alignment...\n";
$db = (new Database())->getConnection();

$sqlFile = __DIR__ . '/schema_alignment.sql';
if (!file_exists($sqlFile)) {
    echo "Schema SQL file not found: $sqlFile\n";
    exit(1);
}

$sql = file_get_contents($sqlFile);
// Split on semicolons that end statements
$statements = array_filter(array_map('trim', explode(';', $sql)), function($s){ return strlen($s) > 0; });
$okCount = 0; $errCount = 0;
foreach ($statements as $stmtSql) {
    try {
        $stmt = $db->prepare($stmtSql);
        $stmt->execute();
        $okCount++;
        echo "OK: " . substr($stmtSql, 0, 100) . "...\n";
    } catch (Throwable $e) {
        $errCount++;
        echo "ERR: " . $e->getMessage() . " for SQL: " . substr($stmtSql, 0, 100) . "...\n";
        // Continue to next statement
    }
}

echo "Schema alignment completed. Success: $okCount, Errors: $errCount\n";