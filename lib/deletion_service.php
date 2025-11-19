<?php
// Centralized deletion service for supplier-linked products
// Performs an atomic hard delete across supplier_catalog, inventory, and dependent tables.

require_once __DIR__ . '/../models/inventory.php';
require_once __DIR__ . '/../models/supplier_catalog.php';
require_once __DIR__ . '/../models/order.php';
require_once __DIR__ . '/../models/payment.php';
require_once __DIR__ . '/../models/sales_transaction.php';
require_once __DIR__ . '/../models/alert_log.php';
require_once __DIR__ . '/../models/delivery.php';
require_once __DIR__ . '/../models/inventory_variation.php';
require_once __DIR__ . '/../models/supplier_product_variation.php';

class DeletionService {
    public static function deleteSupplierProduct(PDO $db, int $supplierId, array $opts): array {
        $result = [
            'success' => false,
            'message' => '',
            'context' => []
        ];

        $actorRole = $opts['actor_role'] ?? 'supplier';
        $actorId   = isset($opts['actor_id']) ? (int)$opts['actor_id'] : null;
        $catalogId = isset($opts['catalog_id']) ? (int)$opts['catalog_id'] : 0;
        $sku       = isset($opts['sku']) ? trim($opts['sku']) : '';

        try {
            if ($supplierId <= 0) {
                throw new Exception('Invalid supplier context.');
            }
            if ($catalogId <= 0 && $sku === '') {
                throw new Exception('Missing product identifier (catalog_id or sku).');
            }

            // Resolve supplier_catalog row and verify ownership
            $lockSql = "SELECT id, sku, supplier_id FROM supplier_catalog WHERE ";
            if ($catalogId > 0) {
                $lockSql .= "id = :cid AND supplier_id = :sid LIMIT 1";
            } else {
                $lockSql .= "sku = :sku AND supplier_id = :sid LIMIT 1";
            }
            $stmt = $db->prepare($lockSql);
            $params = [':sid' => $supplierId];
            if ($catalogId > 0) { $params[':cid'] = $catalogId; } else { $params[':sku'] = $sku; }
            $stmt->execute($params);
            $catalogRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$catalogRow) {
                throw new Exception('Product not found for this supplier.');
            }
            $resolvedSku = $catalogRow['sku'];
            $resolvedCid = (int)$catalogRow['id'];

            // Begin transaction
            if (!$db->inTransaction()) { $db->beginTransaction(); }

            // Lock catalog row
            try {
                $db->prepare('SELECT id FROM supplier_catalog WHERE id = ? FOR UPDATE')->execute([$resolvedCid]);
            } catch (Throwable $e) { /* best-effort lock */ }

            // Locate matching admin inventory row for this supplier
            $invStmt = $db->prepare('SELECT id FROM inventory WHERE sku = :sku AND supplier_id = :sid LIMIT 1');
            $invStmt->execute([':sku' => $resolvedSku, ':sid' => $supplierId]);
            $invId = (int)($invStmt->fetchColumn() ?: 0);
            if ($invId > 0) {
                try { $db->prepare('SELECT id FROM inventory WHERE id = ? FOR UPDATE')->execute([$invId]); } catch (Throwable $e) {}
            }

            // Authorization: only the owning supplier may delete
            if ($actorRole !== 'supplier' || ($actorId !== null && $actorId !== $supplierId)) {
                throw new Exception('Unauthorized deletion attempt.');
            }

            // Delete dependent rows first to satisfy FK integrity
            if ($invId > 0) {
                // Deliveries (linked via orders)
                $db->prepare('DELETE FROM deliveries WHERE order_id IN (SELECT id FROM orders WHERE inventory_id = :iid)')->execute([':iid' => $invId]);
                // Payments linked via orders
                $db->prepare('DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE inventory_id = :iid)')->execute([':iid' => $invId]);
                // Sales transactions
                $db->prepare('DELETE FROM sales_transactions WHERE inventory_id = :iid')->execute([':iid' => $invId]);
                // Notifications tied to orders
                try {
                    $db->prepare('DELETE FROM notifications WHERE order_id IN (SELECT id FROM orders WHERE inventory_id = :iid)')->execute([':iid' => $invId]);
                } catch (Throwable $e) { /* ignore if schema differs */ }
                // Alerts
                try {
                    $db->prepare('DELETE FROM alert_logs WHERE inventory_id = :iid')->execute([':iid' => $invId]);
                } catch (Throwable $e) { /* ignore */ }
                // Notifications tied to alerts
                try {
                    $db->prepare('DELETE FROM notifications WHERE alert_id IN (SELECT id FROM alert_logs WHERE inventory_id = :iid)')->execute([':iid' => $invId]);
                } catch (Throwable $e) { /* ignore */ }
                // Variations
                try {
                    $db->prepare('DELETE FROM inventory_variations WHERE inventory_id = :iid')->execute([':iid' => $invId]);
                } catch (Throwable $e) { /* ignore */ }
                // Orders (remove after dependents)
                $db->prepare('DELETE FROM orders WHERE inventory_id = :iid')->execute([':iid' => $invId]);
            }

            // Supplier-side variations
            try {
                $db->prepare('DELETE FROM supplier_product_variations WHERE product_id = :pid')->execute([':pid' => $resolvedCid]);
            } catch (Throwable $e) { /* ignore */ }

            // Delete inventory row
            if ($invId > 0) {
                $db->prepare('DELETE FROM inventory WHERE id = :iid')->execute([':iid' => $invId]);
            }

            // Delete catalog row
            $db->prepare('DELETE FROM supplier_catalog WHERE id = :cid AND supplier_id = :sid')->execute([':cid' => $resolvedCid, ':sid' => $supplierId]);

            // Verification
            $verify = [
                'inventory' => 0,
                'orders' => 0,
                'deliveries' => 0,
                'payments' => 0,
                'sales_transactions' => 0,
                'alert_logs' => 0,
                'inventory_variations' => 0,
                'supplier_catalog' => 0,
                'supplier_product_variations' => 0,
            ];
            if ($invId > 0) {
                $stmt = $db->prepare('SELECT COUNT(*) FROM inventory WHERE id = ?'); $stmt->execute([$invId]); $verify['inventory'] = (int)$stmt->fetchColumn();
                $stmt = $db->prepare('SELECT COUNT(*) FROM orders WHERE inventory_id = ?'); $stmt->execute([$invId]); $verify['orders'] = (int)$stmt->fetchColumn();
                $stmt = $db->prepare('SELECT COUNT(*) FROM deliveries WHERE order_id IN (SELECT id FROM orders WHERE inventory_id = ?)'); $stmt->execute([$invId]); $verify['deliveries'] = (int)$stmt->fetchColumn();
                $stmt = $db->prepare('SELECT COUNT(*) FROM payments WHERE order_id IN (SELECT id FROM orders WHERE inventory_id = ?)'); $stmt->execute([$invId]); $verify['payments'] = (int)$stmt->fetchColumn();
                $stmt = $db->prepare('SELECT COUNT(*) FROM sales_transactions WHERE inventory_id = ?'); $stmt->execute([$invId]); $verify['sales_transactions'] = (int)$stmt->fetchColumn();
                try { $stmt = $db->prepare('SELECT COUNT(*) FROM alert_logs WHERE inventory_id = ?'); $stmt->execute([$invId]); $verify['alert_logs'] = (int)$stmt->fetchColumn(); } catch (Throwable $e) {}
                try { $stmt = $db->prepare('SELECT COUNT(*) FROM inventory_variations WHERE inventory_id = ?'); $stmt->execute([$invId]); $verify['inventory_variations'] = (int)$stmt->fetchColumn(); } catch (Throwable $e) {}
            }
            $stmt = $db->prepare('SELECT COUNT(*) FROM supplier_catalog WHERE id = ?'); $stmt->execute([$resolvedCid]); $verify['supplier_catalog'] = (int)$stmt->fetchColumn();
            try { $stmt = $db->prepare('SELECT COUNT(*) FROM supplier_product_variations WHERE product_id = ?'); $stmt->execute([$resolvedCid]); $verify['supplier_product_variations'] = (int)$stmt->fetchColumn(); } catch (Throwable $e) {}

            foreach ($verify as $k => $v) {
                if ($v > 0) {
                    throw new Exception('Verification failed: residual records in ' . $k);
                }
            }

            // Commit
            $db->commit();

            // Logging (file + DB best-effort)
            try {
                $logDir = __DIR__ . '/../logs';
                if (!is_dir($logDir)) { @mkdir($logDir, 0777, true); }
                $line = json_encode([
                    'ts' => date('c'),
                    'event' => 'product_hard_delete',
                    'actor_role' => $actorRole,
                    'actor_id' => $actorId,
                    'supplier_id' => $supplierId,
                    'catalog_id' => $resolvedCid,
                    'sku' => $resolvedSku,
                    'inventory_id' => $invId,
                    'success' => true
                ]);
                @file_put_contents($logDir . '/sync_events.log', $line . PHP_EOL, FILE_APPEND);
            } catch (Throwable $e) { /* ignore */ }
            try {
                $stmtSE = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message) VALUES (?,?,?,?,?,?,?,?,?)");
                $stmtSE->execute(['product_hard_delete','supplier_ui','admin_inventory',null,null,'existing','deleted',1,'Deleted product ' . $resolvedSku . ' for supplier ' . $supplierId]);
            } catch (Throwable $e) { /* ignore */ }

            $result['success'] = true;
            $result['message'] = 'Product deleted successfully.';
            $result['context'] = ['catalog_id' => $resolvedCid, 'inventory_id' => $invId, 'sku' => $resolvedSku];
            return $result;
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            try {
                $logDir = __DIR__ . '/../logs';
                if (!is_dir($logDir)) { @mkdir($logDir, 0777, true); }
                $line = json_encode([
                    'ts' => date('c'),
                    'event' => 'product_hard_delete',
                    'actor_role' => $actorRole,
                    'actor_id' => $actorId,
                    'supplier_id' => $supplierId,
                    'catalog_id' => $catalogId,
                    'sku' => $sku,
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
                @file_put_contents($logDir . '/sync_events.log', $line . PHP_EOL, FILE_APPEND);
            } catch (Throwable $e2) { /* ignore */ }
            $result['success'] = false;
            $result['message'] = $e->getMessage();
            return $result;
        }
    }
}