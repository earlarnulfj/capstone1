<?php
// lib/audit.php
// Helper for audit logging and sync events

function audit_log_event(PDO $db, string $eventType, string $entity, int $entityId, string $action, bool $success, string $message = '', array $extra = []): void {
    // File log (best-effort)
    try {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) { @mkdir($logDir, 0777, true); }
        $line = json_encode([
            'ts' => date('c'),
            'event' => $eventType,
            'entity' => $entity,
            'entity_id' => $entityId,
            'action' => $action,
            'success' => $success,
            'message' => $message,
            'extra' => $extra
        ]);
        @file_put_contents($logDir . '/sync_events.log', $line . PHP_EOL, FILE_APPEND);
    } catch (Throwable $e) { /* ignore */ }

    // DB log (if table exists)
    try {
        $sql = "INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message) VALUES (?,?,?,?,?,?,?,?,?)";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $eventType,
            $extra['source'] ?? 'ui',
            $extra['target'] ?? 'database',
            $extra['order_id'] ?? null,
            $extra['delivery_id'] ?? null,
            $extra['status_before'] ?? 'existing',
            $extra['status_after'] ?? $action,
            $success ? 1 : 0,
            $message
        ]);
    } catch (Throwable $e) { /* table may not exist; ignore */ }
}