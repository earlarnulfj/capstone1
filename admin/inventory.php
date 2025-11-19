<?php
/**
 * Separation Policy: Admin inventory pricing changes are decoupled from supplier data.
 *
 * - Admin changes are explicitly saved, validated, and tracked before any sync.
 * - A separate admin-side cache at `cache/admin_inventory` records versioned change history.
 * - Supplier-facing code and storage under `supplier/` must not consume admin caches.
 * - Access control: only `$_SESSION['admin']` may write to admin caches; suppliers are blocked.
 * - This page writes best‑effort change records; failures here do not affect core workflows.
 *
 * Do not introduce direct reads/writes between `admin/*` and `supplier/*`.
 * Any synchronization to POS should read from admin-authorized endpoints only.
 */
// ====== Access control & dependencies (corrected) ======
include_once '../config/session.php';   // namespaced sessions (admin/staff)
require_once '../config/database.php';  // DB connection
require_once '../models/alert_log.php';
require_once '../models/inventory_variation.php';

// Load all model classes this page uses
require_once '../models/inventory.php';
require_once '../models/supplier.php';
require_once '../models/order.php';
require_once '../models/sales_transaction.php';
require_once '../models/alert_log.php';
// If your dashboard uses more models, include them here with require_once

// ---- Admin auth guard (namespaced) ----
if (empty($_SESSION['admin']['user_id'])) {
    header("Location: ../login.php");
    exit();
}
if (($_SESSION['admin']['role'] ?? null) !== 'management') {
    header("Location: ../login.php");
    exit();
}

// ---- CSRF token setup ----
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['csrf_token'] = sha1(uniqid('csrf', true));
    }
}

// ---- Instantiate dependencies ----
try {
    $db = (new Database())->getConnection();
} catch (Throwable $e) {
    http_response_code(503);
    echo '<div style="padding:12px;margin:16px;border:1px solid #e33;color:#a00;background:#fee">'
        . 'Database connection failed. Please ensure MySQL is running and configured. '
        . htmlspecialchars($e->getMessage())
        . '</div>';
    exit;
}
$inventory  = new Inventory($db);
$supplier   = new Supplier($db);
$order      = new Order($db);
$sales      = new SalesTransaction($db);
$alert      = new AlertLog($db);
$invVariation = new InventoryVariation($db);

// Initialize unit type and variation mappings for admin inventory
require_once '../lib/unit_variations.php';
$UNIT_TYPE_CODE_MAP = [];
// Augment unit type code map from database
try {
    $stmtUtAll = $db->query("SELECT code, name FROM unit_types WHERE COALESCE(is_deleted,0)=0");
    while ($row = $stmtUtAll->fetch(PDO::FETCH_ASSOC)) {
        $c = isset($row['code']) ? trim((string)$row['code']) : '';
        $n = isset($row['name']) ? trim((string)$row['name']) : '';
        if ($c !== '' && $n !== '') {
            // Normalize to 'per <name>' for consistency with backend
            $norm = 'per ' . strtolower($n);
            $UNIT_TYPE_CODE_MAP[$c] = $norm;
        }
    }
} catch (Throwable $e) { /* best-effort; non-fatal if unit_types is empty */ }

// --- Preflight: Clean up duplicates in inventory ---
try {
    $db->beginTransaction();
    
    // 1. Remove products without SKU (empty or null)
    $deletedNoSku = $db->exec("DELETE FROM inventory WHERE (sku IS NULL OR sku = '') AND COALESCE(is_deleted, 0) = 0");
    if ($deletedNoSku > 0) {
        error_log("Cleaned up {$deletedNoSku} inventory items without SKU");
    }
    
    // 2. Remove duplicate products with same SKU + supplier_id (keep the oldest one, delete newer duplicates)
    // First, get all duplicates grouped by SKU + supplier_id
    $duplicatesStmt = $db->query("
        SELECT sku, supplier_id, COUNT(*) as cnt, MIN(id) as keep_id, GROUP_CONCAT(id ORDER BY id) as all_ids
        FROM inventory 
        WHERE sku IS NOT NULL AND sku != '' AND supplier_id IS NOT NULL AND COALESCE(is_deleted, 0) = 0
        GROUP BY sku, supplier_id 
        HAVING cnt > 1
    ");
    
    $duplicatesDeleted = 0;
    while ($dup = $duplicatesStmt->fetch(PDO::FETCH_ASSOC)) {
        $keepId = (int)$dup['keep_id'];
        $allIds = explode(',', $dup['all_ids']);
        $idsToDelete = array_filter($allIds, function($id) use ($keepId) {
            return (int)$id !== $keepId;
        });
        
        if (!empty($idsToDelete)) {
            // Delete inventory variations for duplicates first
            $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
            try {
                $delVarsStmt = $db->prepare("DELETE FROM inventory_variations WHERE inventory_id IN ($placeholders)");
                $delVarsStmt->execute($idsToDelete);
            } catch (Exception $e) {
                error_log("Warning: Could not delete variations for duplicate inventory items: " . $e->getMessage());
            }
            
            // Delete duplicate inventory items (keep the oldest one)
            $delStmt = $db->prepare("DELETE FROM inventory WHERE id IN ($placeholders)");
            $delStmt->execute($idsToDelete);
            $duplicatesDeleted += $delStmt->rowCount();
        }
    }
    
    if ($duplicatesDeleted > 0) {
        error_log("Cleaned up {$duplicatesDeleted} duplicate inventory items (same SKU + supplier_id)");
    }
    
    // 3. Remove products with duplicate SKU but no supplier_id (keep one, delete others)
    $duplicatesNoSupplierStmt = $db->query("
        SELECT sku, COUNT(*) as cnt, MIN(id) as keep_id, GROUP_CONCAT(id ORDER BY id) as all_ids
        FROM inventory 
        WHERE sku IS NOT NULL AND sku != '' AND supplier_id IS NULL AND COALESCE(is_deleted, 0) = 0
        GROUP BY sku 
        HAVING cnt > 1
    ");
    
    $duplicatesNoSupplierDeleted = 0;
    while ($dup = $duplicatesNoSupplierStmt->fetch(PDO::FETCH_ASSOC)) {
        $keepId = (int)$dup['keep_id'];
        $allIds = explode(',', $dup['all_ids']);
        $idsToDelete = array_filter($allIds, function($id) use ($keepId) {
            return (int)$id !== $keepId;
        });
        
        if (!empty($idsToDelete)) {
            // Delete inventory variations for duplicates first
            $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
            try {
                $delVarsStmt = $db->prepare("DELETE FROM inventory_variations WHERE inventory_id IN ($placeholders)");
                $delVarsStmt->execute($idsToDelete);
            } catch (Exception $e) {
                error_log("Warning: Could not delete variations for duplicate inventory items: " . $e->getMessage());
            }
            
            // Delete duplicate inventory items
            $delStmt = $db->prepare("DELETE FROM inventory WHERE id IN ($placeholders)");
            $delStmt->execute($idsToDelete);
            $duplicatesNoSupplierDeleted += $delStmt->rowCount();
        }
    }
    
    if ($duplicatesNoSupplierDeleted > 0) {
        error_log("Cleaned up {$duplicatesNoSupplierDeleted} duplicate inventory items (same SKU, no supplier_id)");
    }
    
    $db->commit();
    
    if ($deletedNoSku > 0 || $duplicatesDeleted > 0 || $duplicatesNoSupplierDeleted > 0) {
        error_log("Inventory cleanup completed: Removed {$deletedNoSku} items without SKU, {$duplicatesDeleted} duplicates with supplier_id, {$duplicatesNoSupplierDeleted} duplicates without supplier_id");
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    error_log("Warning: Inventory cleanup failed: " . $e->getMessage());
}

// NOTE: Inventory now ONLY shows products from completed orders in admin/orders.php
// Products from supplier/products.php are NOT synced to inventory
// They only appear in supplier/supplier_details.php
// All inventory data comes from completed orders in admin_orders table



$inventory = new Inventory($db);
$supplier = new Supplier($db);

// Get all suppliers for dropdown
$suppliers = $supplier->readAll();
$suppliersArr = [];
while ($row = $suppliers->fetch(PDO::FETCH_ASSOC)) {
    $suppliersArr[] = $row;
}

// Process form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // CSRF validation for form posts (non-AJAX)
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!$isAjax && (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken))) {
            $message = "Security check failed. Please refresh and try again.";
            $messageType = "danger";
        } else {
            // Handle unit type operations
            if ($_POST['action'] === 'add_unit_type') {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    $code = isset($_POST['code']) ? trim((string)$_POST['code']) : '';
                    $name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
                    if ($code === '' || $name === '') {
                        echo json_encode(['success' => false, 'message' => 'Code and name are required']);
                        exit;
                    }
                    if (!preg_match('/^[A-Za-z0-9]{1,16}$/', $code)) {
                        echo json_encode(['success' => false, 'message' => 'Invalid code format']);
                        exit;
                    }
                    try {
                        $db->beginTransaction();
                        $chk = $db->prepare("SELECT COUNT(*) AS c FROM unit_types WHERE code = :c");
                        $chk->execute([':c' => $code]);
                        $cntRow = $chk->fetch(PDO::FETCH_ASSOC);
                        if ((int)($cntRow['c'] ?? 0) > 0) {
                            $db->rollBack();
                            echo json_encode(['success' => false, 'message' => 'Code already exists']);
                            exit;
                        }
                        $stmtIns = $db->prepare("INSERT INTO unit_types (code, name) VALUES (:c, :n)");
                        $stmtIns->execute([':c' => $code, ':n' => $name]);
                        $db->commit();
                        $norm = 'per ' . strtolower($name);
                        echo json_encode(['success' => true, 'code' => $code, 'name' => $name, 'normalized' => $norm]);
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) { $db->rollBack(); }
                        echo json_encode(['success' => false, 'message' => 'Add error: ' . $e->getMessage()]);
                    }
                    exit;
                }
            } elseif ($_POST['action'] === 'update_unit_type') {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    $code = isset($_POST['code']) ? trim((string)$_POST['code']) : '';
                    $name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
                    if ($code === '' || $name === '') {
                        echo json_encode(['success' => false, 'message' => 'Code and name are required']);
                        exit;
                    }
                    try {
                        $db->beginTransaction();
                        $stmtInfo = $db->prepare("SELECT id FROM unit_types WHERE code = :c LIMIT 1");
                        $stmtInfo->execute([':c' => $code]);
                        $row = $stmtInfo->fetch(PDO::FETCH_ASSOC);
                        $utId = isset($row['id']) ? (int)$row['id'] : 0;
                        if (!$utId) {
                            $db->rollBack();
                            echo json_encode(['success' => false, 'message' => 'Unit type not found']);
                            exit;
                        }
                        $stmtUpd = $db->prepare("UPDATE unit_types SET name = :n WHERE id = :id");
                        $stmtUpd->execute([':n' => $name, ':id' => $utId]);
                        $db->commit();
                        $norm = 'per ' . strtolower($name);
                        echo json_encode(['success' => true, 'code' => $code, 'name' => $name, 'normalized' => $norm]);
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) { $db->rollBack(); }
                        echo json_encode(['success' => false, 'message' => 'Update error: ' . $e->getMessage()]);
                    }
                    exit;
                }
            } elseif ($_POST['action'] === 'delete_unit_type') {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    $code = isset($_POST['code']) ? trim((string)$_POST['code']) : '';
                    if ($code === '') {
                        echo json_encode(['success' => false, 'message' => 'Missing unit type code']);
                        exit;
                    }
                    try {
                        $db->beginTransaction();
                        $stmtInfo = $db->prepare("SELECT id, name FROM unit_types WHERE code = :c LIMIT 1");
                        $stmtInfo->execute([':c' => $code]);
                        $row = $stmtInfo->fetch(PDO::FETCH_ASSOC);
                        $utId = isset($row['id']) ? (int)$row['id'] : 0;
                        if (!$utId) {
                            $db->rollBack();
                            echo json_encode(['success' => false, 'message' => 'Unit type not found']);
                            exit;
                        }
                        $normalized = 'per ' . strtolower($row['name'] ?? '');
                        $fallback = 'per piece';
                        try {
                            $stmtUpdCatalog = $db->prepare("UPDATE inventory SET unit_type = :fallback WHERE unit_type = :norm");
                            $stmtUpdCatalog->execute([':fallback' => $fallback, ':norm' => $normalized]);
                        } catch (Throwable $e) {}
                        try {
                            $stmtUpdVariations = $db->prepare("UPDATE inventory_variations SET unit_type = :fallback WHERE unit_type = :norm");
                            $stmtUpdVariations->execute([':fallback' => $fallback, ':norm' => $normalized]);
                        } catch (Throwable $e) {}
                        $stmtDelUnit = $db->prepare("DELETE FROM unit_types WHERE id = :id");
                        $stmtDelUnit->execute([':id' => $utId]);
                        $db->commit();
                        echo json_encode(['success' => true, 'code' => $code]);
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) { $db->rollBack(); }
                        echo json_encode(['success' => false, 'message' => 'Delete error: ' . $e->getMessage()]);
                    }
                    exit;
                }
            }
            // Update inventory item - only reorder threshold
            elseif ($_POST['action'] === 'update') {
                // Only allow editing reorder threshold to avoid duplication and source type changes
                $inventory->id = (int)($_POST['id'] ?? 0);
                $reorder_threshold = $_POST['reorder_threshold'] ?? 0;

                // Validate reorder threshold
                if (!is_numeric($reorder_threshold) || (int)$reorder_threshold < 0) {
                    $message = "Error: Reorder threshold must be a non-negative integer.";
                    $messageType = "danger";
                }
                else {
                    try {
                        $db->beginTransaction();
                        
                        // Get current inventory item to preserve supplier_id and other fields
                        $currentStmt = $db->prepare("SELECT supplier_id FROM inventory WHERE id = ?");
                        $currentStmt->execute([(int)$inventory->id]);
                        $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$current) {
                            throw new Exception("Inventory item not found.");
                        }
                        
                        // Only update reorder threshold, preserve supplier_id to maintain source type
                        $updateStmt = $db->prepare("UPDATE inventory SET reorder_threshold = ? WHERE id = ?");
                        if ($updateStmt->execute([(int)$reorder_threshold, (int)$inventory->id])) {
                            $db->commit();
                            $message = "Reorder threshold updated successfully.";
                            $messageType = "success";
                        } else {
                            $db->rollBack();
                            $message = "Unable to update reorder threshold.";
                            $messageType = "danger";
                        }
                    } catch (Exception $e) {
                        $db->rollBack();
                        $message = "Unable to update inventory item.";
                        $messageType = "danger";
                        try {
                            $log = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message) VALUES (?,?,?,?,?,?,?,?,?)");
                            $log->execute(['inventory_crud_sync','admin_ui','pos_clients',null,null,'existing','updated',0,'Update exception for item ID ' . (int)$inventory->id . ': ' . $e->getMessage()]);
                        } catch (Throwable $ie) { }
                    }
                }
            }
            // Delete inventory item
            elseif ($_POST['action'] === 'delete') {
                // Enhanced validation for delete operation
                $item_id = filter_var($_POST['id'], FILTER_VALIDATE_INT);
                $item_name = trim($_POST['name'] ?? '');
                
                // Validate that this request is coming from the correct file
                $current_script = basename($_SERVER['SCRIPT_NAME']);
                if ($current_script !== 'inventory.php') {
                    $message = "Unauthorized delete operation.";
                    $messageType = "danger";
                }
                // Validate required parameters
                else if (!$item_id || $item_id <= 0) {
                    $message = "Invalid inventory item ID.";
                    $messageType = "danger";
                }
                // Check if item exists before deletion
                else {
                    $inventory->id = $item_id;
                    
                    // Check if item exists in inventory table
                    $stmt = $db->prepare("SELECT name FROM inventory WHERE id = ?");
                    $stmt->execute([$item_id]);
                    $existing_item = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$existing_item) {
                        $message = "Inventory item not found.";
                        $messageType = "danger";
                    }
                    // CRITICAL: When deleting inventory item, do NOT affect admin_orders table
                    // Orders in admin/orders.php must remain intact and independent
                    // Only check for sales transactions to determine if we should archive vs delete
                    else {
                        // Check for sales transactions (these should prevent deletion)
                        $stmt = $db->prepare("SELECT COUNT(*) FROM sales_transactions WHERE inventory_id = ?");
                        $stmt->execute([$item_id]);
                        $sales_count = $stmt->fetchColumn();
                        
                        // Check if there are completed orders in admin_orders (for informational purposes only)
                        // We will NOT delete these orders - they remain independent
                        $adminOrdersStmt = $db->prepare("SELECT COUNT(*) FROM admin_orders WHERE inventory_id = ? AND confirmation_status = 'completed'");
                        $adminOrdersStmt->execute([$item_id]);
                        $admin_orders_count = (int)$adminOrdersStmt->fetchColumn();
                        
                        // Allow force delete to override protection when explicitly requested
                        $forceDelete = isset($_POST['force_delete']) && $_POST['force_delete'] === '1';
                        
                        // If there are sales transactions and not force deleting, archive the item
                        // This preserves sales history while hiding the item
                        if (!$forceDelete && $sales_count > 0) {
                            // Soft-delete: archive the item without touching orders or sales history
                            try {
                                // Add is_deleted column if it doesn't exist
                                $hasDeletedCol = false;
                                try {
                                    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory' AND COLUMN_NAME = 'is_deleted'");
                                    $stmt->execute();
                                    $hasDeletedCol = $stmt->fetchColumn() > 0;
                                } catch (Exception $e) { $hasDeletedCol = false; }

                                if (!$hasDeletedCol) {
                                    try {
                                        $db->exec("ALTER TABLE inventory ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0, ADD INDEX idx_is_deleted (is_deleted)");
                                    } catch (Exception $e) {}
                                }

                                $stmt = $db->prepare("UPDATE inventory SET is_deleted = 1 WHERE id = ?");
                                $stmt->execute([$item_id]);

                                $deletedName = isset($existing_item['name']) ? $existing_item['name'] : $item_name;
                                $admin_orders_info = $admin_orders_count > 0 ? " ({$admin_orders_count} order(s) in admin/orders.php remain intact)" : "";
                                $message = "Inventory item '{$deletedName}' was archived and hidden. Sales history retained.{$admin_orders_info}";
                                $messageType = "success";
                            } catch (Exception $e) {
                                $message = "Unable to archive inventory item due to a database error.";
                                $messageType = "danger";
                            }
                        }
                        else {
                            try {
                                $db->beginTransaction();
                                // Row-level lock to serialize concurrent deletion
                                try {
                                    $lock = $db->prepare("SELECT id FROM inventory WHERE id = ? FOR UPDATE");
                                    $lock->execute([$item_id]);
                                } catch (Throwable $e) { /* proceed even if lock fails */ }
                                
                                // CRITICAL: Do NOT touch admin_orders table
                                // Orders in admin/orders.php must remain completely independent
                                // We only clean up inventory-related data, not orders

                                // If force delete, also remove sales transactions for this inventory
                                if ($forceDelete && (int)$sales_count > 0) {
                                    $stmt = $db->prepare("DELETE FROM sales_transactions WHERE inventory_id = ?");
                                    $stmt->execute([$item_id]);
                                }

                                // Clean up alert notifications referencing this inventory's alerts
                                $stmt = $db->prepare("SELECT id FROM alert_logs WHERE inventory_id = ?");
                                $stmt->execute([$item_id]);
                                $alertIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                if (!empty($alertIds)) {
                                    $ph = implode(',', array_fill(0, count($alertIds), '?'));
                                    $stmt = $db->prepare("DELETE FROM notifications WHERE alert_id IN ($ph)");
                                    $stmt->execute($alertIds);
                                }

                                // Delete alert logs for this inventory item
                                $stmt = $db->prepare("DELETE FROM alert_logs WHERE inventory_id = ?");
                                $stmt->execute([$item_id]);

                                // Delete variation rows for this inventory
                                try {
                                    $stmt = $db->prepare("DELETE FROM inventory_variations WHERE inventory_id = ?");
                                    $stmt->execute([$item_id]);
                                } catch (Exception $e) {}

                                // Finally delete the inventory item directly
                                // NOTE: This does NOT affect admin_orders table - orders remain intact
                                $stmt = $db->prepare("DELETE FROM inventory WHERE id = ?");
                                $deleted = $stmt->execute([$item_id]);

                                if ($deleted) {
                                    $db->commit();
                                    $deletedName = isset($existing_item['name']) ? $existing_item['name'] : $item_name;
                                    $admin_orders_info = $admin_orders_count > 0 ? " ({$admin_orders_count} order(s) in admin/orders.php remain intact)" : "";
                                    $force_info = (!empty($forceDelete) && $forceDelete && $sales_count > 0) ? " (also removed sales history)" : "";
                                    $message = "Inventory item '{$deletedName}' was deleted successfully.{$force_info}{$admin_orders_info}";
                                    $messageType = "success";
                                    try {
                                        $log = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message) VALUES (?,?,?,?,?,?,?,?,?)");
                                        $log->execute(['inventory_crud_sync','admin_ui','pos_clients',null,null,'existing','deleted',1,'Inventory item deleted: ' . $deletedName . ' (ID: ' . $item_id . ') - admin_orders remain intact']);
                                    } catch (Throwable $e) { }
                                } else {
                                    $db->rollBack();
                                    $message = "Unable to delete inventory item. The item may have associated records or there was a database error.";
                                    $messageType = "danger";
                                    try {
                                        $log = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message) VALUES (?,?,?,?,?,?,?,?,?)");
                                        $log->execute(['inventory_crud_sync','admin_ui','pos_clients',null,null,'existing','deleted',0,'Delete failed for item ID ' . $item_id]);
                                    } catch (Throwable $e) { }
                                }
                            } catch (Exception $e) {
                                if ($db->inTransaction()) { $db->rollBack(); }
                                // Fallback: archive item to avoid user-facing failure when constraints prevent hard delete
                                try {
                                    $hasDeletedCol = false;
                                    try {
                                        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory' AND COLUMN_NAME = 'is_deleted'");
                                        $stmt->execute();
                                        $hasDeletedCol = $stmt->fetchColumn() > 0;
                                    } catch (Exception $e2) { $hasDeletedCol = false; }
                                    if (!$hasDeletedCol) {
                                        try { $db->exec("ALTER TABLE inventory ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0, ADD INDEX idx_is_deleted (is_deleted)"); } catch (Exception $e3) {}
                                    }
                                    $stmt = $db->prepare("UPDATE inventory SET is_deleted = 1 WHERE id = ?");
                                    $stmt->execute([$item_id]);
                                    $deletedName = isset($existing_item['name']) ? $existing_item['name'] : $item_name;
                                    $message = "Deletion could not be completed fully due to related records. Item '{$deletedName}' has been archived instead.";
                                    $messageType = "warning";
                                    try {
                                        $log = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message) VALUES (?,?,?,?,?,?,?,?,?)");
                                        $log->execute(['inventory_soft_delete','admin_ui','pos_clients',null,null,'existing','archived',1,'Inventory item archived: ' . $deletedName . ' (ID: ' . $item_id . ')']);
                                    } catch (Throwable $e) { }
                                } catch (Exception $ef) {
                                    $message = "Deletion failed due to an unexpected error.";
                                    $messageType = "danger";
                                    try {
                                        $log = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message) VALUES (?,?,?,?,?,?,?,?,?)");
                                        $log->execute(['inventory_crud_sync','admin_ui','pos_clients',null,null,'existing','deleted',0,'Delete exception for item ID ' . $item_id . ': ' . $e->getMessage()]);
                                    } catch (Throwable $ie) { }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

// ====== Helper functions for variation display ======
// Format variation for display: "Brand:Adidas|Size:Large|Color:Red" -> "Adidas - Large - Red" (combine values with dashes)
function formatVariationForDisplay($variation) {
    if (empty($variation)) return '';
    if (strpos($variation, '|') === false && strpos($variation, ':') === false) return $variation;
    
    $parts = explode('|', $variation);
    $values = [];
    foreach ($parts as $part) {
        $av = explode(':', trim($part), 2);
        if (count($av) === 2) {
            $values[] = trim($av[1]);
        } else {
            $values[] = trim($part);
        }
    }
    return implode(' - ', $values);
}

// Format variation with labels: "Brand:Generic|Size:Large" -> "Brand: Generic | Size: Large"
function formatVariationWithLabels($variation) {
    if (empty($variation)) return '';
    if (strpos($variation, '|') === false && strpos($variation, ':') === false) return $variation;
    
    $parts = explode('|', $variation);
    $formatted = [];
    foreach ($parts as $part) {
        $av = explode(':', trim($part), 2);
        if (count($av) === 2) {
            $formatted[] = trim($av[0]) . ': ' . trim($av[1]);
        } else {
            $formatted[] = trim($part);
        }
    }
    return implode(' | ', $formatted);
}

// Get all inventory items from completed orders only (not from supplier catalog)
// NOTE: Inventory now only shows products from completed orders
// Products from supplier/products.php are NOT synced to inventory
// They only appear in supplier/supplier_details.php

// IMPORTANT: Sync ALL completed orders to inventory table first
// This ensures all completed orders are stored in the inventory database
// The readAllFromCompletedOrders() method will also call sync, but we do it here explicitly
// to ensure data is always up-to-date when the page loads
try {
    // Sync all completed orders from both admin_orders and orders tables
    $inventory->syncAllCompletedOrdersToInventory();
    
} catch (Exception $e) {
    error_log("Error syncing completed orders to inventory on page load: " . $e->getMessage());
}

// Query active items from completed orders - reads from inventory table (existing table)
// This method also calls syncAllCompletedOrdersToInventory() internally for double safety
$stmt = $inventory->readAllFromCompletedOrders();

// Get fresh statement for iteration
$stmt = $inventory->readAllFromCompletedOrders();
// Compute ordered inventory IDs (non-cancelled orders) from both admin_orders and orders tables
$orderedInventoryIds = [];
try {
    $hasAdminOrders = (bool)$db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'admin_orders'")->fetchColumn();
    $hasOrders = (bool)$db->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'orders'")->fetchColumn();
    
    $queries = [];
    if ($hasAdminOrders) {
        $queries[] = "SELECT DISTINCT inventory_id FROM admin_orders WHERE confirmation_status <> 'cancelled' AND inventory_id IS NOT NULL";
    }
    if ($hasOrders) {
        $queries[] = "SELECT DISTINCT inventory_id FROM orders WHERE confirmation_status <> 'cancelled' AND inventory_id IS NOT NULL";
    }
    
    if (count($queries) > 0) {
        $query = count($queries) > 1 ? "(" . implode(") UNION DISTINCT (", $queries) . ")" : $queries[0];
        $orderedStmt = $db->query($query);
        while ($or = $orderedStmt->fetch(PDO::FETCH_ASSOC)) { 
            $orderedInventoryIds[] = (int)$or['inventory_id']; 
        }
    }
} catch (Exception $e) {
    // Fallback to orders table only
    try {
        $orderedStmt = $db->query("SELECT DISTINCT inventory_id FROM orders WHERE confirmation_status <> 'cancelled' AND inventory_id IS NOT NULL");
        while ($or = $orderedStmt->fetch(PDO::FETCH_ASSOC)) { 
            $orderedInventoryIds[] = (int)$or['inventory_id']; 
        }
    } catch (Exception $e2) {
        // Ignore if tables don't exist
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - Inventory & Stock Control System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/add_form.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <script src="assets/js/notification-badge.js"></script>
    <style>
      .shp-variations { background: #fff; }
      .shp-variations__header .shp-label { font-weight: 600; color: #6b7280; font-size: 0.9rem; }
      .shp-variations__controls .shp-control-group { display: flex; flex-wrap: wrap; gap: 0.5rem; }
      .btn-check + .shp-chip {
        padding: 0.375rem 0.75rem;
        font-size: 0.875rem;
        border: 1px solid #dee2e6;
        background: #fff;
        color: #495057;
        transition: all 0.15s ease-in-out;
      }
      .btn-check:checked + .shp-chip {
        background: #0d6efd;
        border-color: #0d6efd;
        color: #fff;
      }
      .btn-check:focus-visible + .shp-chip { outline: 2px solid #0d6efd; outline-offset: 2px; }
      .shp-chip:hover { border-color: #9ca3af; }
      .shp-name-echo { padding: 0.375rem 0.75rem; font-weight: 500; color: #495057; background: #f8f9fa; border-radius: 0.375rem; }
      @media (max-width: 768px) {
        .btn-check + .shp-chip { width: 100%; justify-content: center; }
      }
    </style>
</head>
<body>
    <?php include_once 'includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include_once 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-2">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-1 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Inventory Management</h1>
                </div>
                
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <i class="bi bi-table me-1"></i>
                            Inventory Items
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="inventoryTable" class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>SKU</th>
                                        <th>Name</th>
                                        <th>Category</th>
                                        <th>Reorder Threshold</th>
                                        <th>Unit Type</th>
                                        <th>Variations</th>
                                        <th>Stocks</th>
                                        <th>Supplier</th>
                                        <th>Location</th>
                                        <th>Source</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                                        <?php
                                        // Use inventory_id from the new table to get variations
                                        $inventory_id = isset($row['inventory_id']) ? (int)$row['inventory_id'] : (int)$row['id'];
                                        $__vars_row = $invVariation->getByInventory($inventory_id);
                                        $__names_row = [];
                                        $__price_map = [];
                                        foreach ($__vars_row as $__vr) { 
                                            $__names_row[] = $__vr['variation']; 
                                            $__price_map[$__vr['variation']] = isset($__vr['unit_price']) ? (float)$__vr['unit_price'] : null;
                                        }
                                        $__display_row = !empty($__names_row) ? implode(', ', array_slice($__names_row, 0, 6)) : '';
                                        $__unit_type_row = (!empty($__vars_row) && isset($__vars_row[0]['unit_type'])) ? $__vars_row[0]['unit_type'] : ($row['unit_type'] ?? 'Per Piece');
                                        $__price_map_json = htmlspecialchars(json_encode($__price_map), ENT_QUOTES);
                                        // Stocks map aligned with unit type (lower-case for lookup)
                                        $__stock_map = [];
                                        try { $__stock_map = $invVariation->getStocksMap($inventory_id, strtolower($__unit_type_row ?: 'per piece')); } catch (Throwable $e) { $__stock_map = []; }
                                        $__stock_map_json = htmlspecialchars(json_encode($__stock_map), ENT_QUOTES);
                                        
                                        // Get all completed orders for this inventory item (one entry per order)
                                        // CRITICAL: Only fetch from admin_orders table (admin/orders.php)
                                        // Do NOT fetch from orders table or any other source
                                        $__completed_orders_map = [];
                                        try {
                                            // Get ONLY completed orders from admin_orders table
                                            $ordersStmt = $db->prepare("SELECT id, variation, quantity, unit_type, order_date
                                                                      FROM admin_orders 
                                                                      WHERE inventory_id = ? 
                                                                      AND confirmation_status = 'completed' 
                                                                      ORDER BY order_date DESC");
                                            $ordersStmt->execute([$inventory_id]);
                                            while ($orderRow = $ordersStmt->fetch(PDO::FETCH_ASSOC)) {
                                                $orderId = 'admin_' . (int)$orderRow['id'];
                                                // Add ALL orders - each order ID is unique
                                                $__completed_orders_map[$orderId] = [
                                                    'id' => (int)$orderRow['id'],
                                                    'variation' => $orderRow['variation'] ?? '',
                                                    'quantity' => (int)$orderRow['quantity'],
                                                    'unit_type' => $orderRow['unit_type'] ?? 'per piece',
                                                    'order_date' => $orderRow['order_date'] ?? null
                                                ];
                                            }
                                            
                                            // DO NOT fetch from orders table - we only want data from admin/orders.php
                                            
                                            // Convert map to array
                                            $__completed_orders = array_values($__completed_orders_map);
                                        } catch (Exception $e) {
                                            error_log("Error getting completed orders from admin_orders: " . $e->getMessage());
                                            $__completed_orders = [];
                                        }
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['sku'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($row['name'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($row['category'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($row['reorder_threshold'] ?? 0); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($__unit_type_row); ?></span></td>
                                            <td>
                                                <?php if (!empty($__completed_orders)) { ?>
                                                    <?php foreach ($__completed_orders as $order) { 
                                                        $ordered_variation = isset($order['variation']) ? trim($order['variation']) : '';
                                                    ?>
                                                        <div class="mb-3">
                                                            <?php if (!empty($ordered_variation)): ?>
                                                                <div class="d-flex flex-column">
                                                                    <span class="badge bg-info mb-1 fs-6">
                                                                        <i class="bi bi-tag-fill me-1"></i><?= htmlspecialchars(formatVariationForDisplay($ordered_variation)) ?>
                                                                    </span>
                                                                    <small class="text-muted"><?= htmlspecialchars(formatVariationWithLabels($ordered_variation)) ?></small>
                                                                </div>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary">No Variation</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php } ?>
                                                <?php } else { echo '<span class="text-muted">—</span>'; } ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($__completed_orders)) { ?>
                                                    <?php foreach ($__completed_orders as $order) { 
                                                        $order_qty = (int)($order['quantity'] ?? 0);
                                                    ?>
                                                        <div class="mb-3">
                                                            <div class="d-flex flex-column align-items-start">
                                                                <span class="badge bg-primary fs-6 mb-1">
                                                                    <i class="bi bi-box-seam me-1"></i><strong><?= $order_qty ?></strong> pcs
                                                                </span>
                                                                <small class="text-muted">Quantity Ordered</small>
                                                            </div>
                                                        </div>
                                                    <?php } ?>
                                                <?php } else { echo '<span class="text-muted">—</span>'; } ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['supplier_name'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($row['location'] ?? ''); ?></td>
                                            <td>
                                                <span class="badge <?php echo ($row['source_type'] === 'Admin Created') ? 'bg-primary' : 'bg-success'; ?>">
                                                    <?php echo htmlspecialchars($row['source_type'] ?? 'From Completed Order'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-info view-btn" 
                                                    data-id="<?php echo $row['id']; ?>"
                                                    data-sku="<?php echo htmlspecialchars($row['sku'] ?? ''); ?>"
                                                    data-name="<?php echo htmlspecialchars($row['name'] ?? ''); ?>"
                                                    data-description="<?php echo htmlspecialchars($row['description'] ?? ''); ?>"
                                                    data-reorder="<?php echo $row['reorder_threshold'] ?? 0; ?>"
                                                    data-supplier="<?php echo $row['supplier_id'] ?? ''; ?>"
                                                    data-category="<?php echo htmlspecialchars($row['category'] ?? ''); ?>"
                                                    data-location="<?php echo htmlspecialchars($row['location'] ?? ''); ?>"
                                                    data-source="<?php echo htmlspecialchars($row['source_type'] ?? 'From Completed Order'); ?>"
                                                    data-variations="<?php echo htmlspecialchars($__display_row); ?>"
                                                    data-unit_type="<?php echo htmlspecialchars($__unit_type_row); ?>"
                                                    data-variation_prices="<?php echo $__price_map_json; ?>"
                                                    data-variation_stocks="<?php echo $__stock_map_json; ?>"
                                                    data-bs-toggle="modal" data-bs-target="#viewInventoryModal">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-primary edit-btn" 
                                                    data-id="<?php echo $row['id']; ?>"
                                                    data-sku="<?php echo htmlspecialchars($row['sku'] ?? ''); ?>"
                                                    data-name="<?php echo htmlspecialchars($row['name'] ?? ''); ?>"
                                                    data-description="<?php echo htmlspecialchars($row['description'] ?? ''); ?>"
                                                    data-reorder="<?php echo $row['reorder_threshold'] ?? 0; ?>"
                                                    data-supplier="<?php echo $row['supplier_id'] ?? ''; ?>"
                                                    data-category="<?php echo htmlspecialchars($row['category'] ?? ''); ?>"
                                                    data-location="<?php echo htmlspecialchars($row['location'] ?? ''); ?>"
                                                    data-source="<?php echo htmlspecialchars($row['source_type'] ?? 'From Completed Order'); ?>"
                                                    data-variations="<?php echo htmlspecialchars($__display_row); ?>"
                                                    data-unit_type="<?php echo htmlspecialchars($__unit_type_row); ?>"
                                                    data-variation_prices="<?php echo $__price_map_json; ?>"
                                                    data-variation_stocks="<?php echo $__stock_map_json; ?>"
                                                    data-bs-toggle="modal" data-bs-target="#editInventoryModal">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-danger delete-btn" 
                                                    data-id="<?php echo $row['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars(!empty($row['name']) ? $row['name'] : ($row['sku'] ?? 'Item #' . $row['id'])); ?>"
                                                    data-bs-toggle="modal" data-bs-target="#deleteInventoryModal">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- View Inventory Modal -->
    <div class="modal fade" id="viewInventoryModal" tabindex="-1" aria-labelledby="viewInventoryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewInventoryModalLabel">View Inventory Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <p><strong>SKU:</strong> <span id="view-sku"></span></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Name:</strong> <span id="view-name"></span></p>
                        </div>
                    </div>
                    <div class="mb-3">
                        <p><strong>Description:</strong> <span id="view-description"></span></p>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <p><strong>Reorder Threshold:</strong> <span id="view-reorder"></span></p>
                        </div>
                        <div class="col-md-8">
                            <div class="row g-2 align-items-center">
                                <div class="col-md-6">
                                    <label class="form-label mb-0">Variation</label>
                                    <select id="view-variation-select" class="form-select form-select-sm" aria-label="Select variation"></select>
                                </div>
                                <div class="col-md-6">
                                    <span class="badge bg-light text-dark me-2">Price ₱<span id="view-selected-price">0.00</span></span>
                                    <span class="badge bg-light text-dark">Stock <span id="view-selected-stock">0</span></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <p><strong>Category:</strong> <span id="view-category"></span></p>
                        </div>
                        <div class="col-md-4">
                            <p><strong>Supplier:</strong> <span id="view-supplier"></span></p>
                        </div>
                        <div class="col-md-4">
                            <p><strong>Location:</strong> <span id="view-location"></span></p>
                        </div>
                    </div>
                    <div class="mb-3">
                        <p><strong>Variations:</strong></p>
                        <div id="view-variation-list"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit Inventory Modal -->
    <div class="modal fade" id="editInventoryModal" tabindex="-1" aria-labelledby="editInventoryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editInventoryModalLabel">Edit Inventory Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="editInventoryForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" id="edit-id">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>
                            You can only edit the reorder threshold. Other fields are preserved to maintain product integrity and avoid duplication.
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit-reorder_threshold" class="form-label">Reorder Threshold *</label>
                            <input type="number" class="form-control" id="edit-reorder_threshold" name="reorder_threshold" min="0" required>
                            <small class="text-muted">Set the minimum stock level before a reorder alert is triggered.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Update Reorder Threshold
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Unit Type Management Modal -->
    <div class="modal fade" id="unitTypeManageModal" tabindex="-1" aria-labelledby="unitTypeManageLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="unitTypeManageLabel">Manage Unit Type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="unitTypeManageStatus" class="visually-hidden" aria-live="polite"></div>
                    <div class="mb-2">
                        <span class="badge bg-primary" id="selectedUnitBadge">Selected: -</span>
                    </div>
                    <!-- Add Section -->
                    <div id="unitManageAddSection" class="d-none">
                        <div class="mb-2">
                            <label for="unitAddCode" class="form-label">Code</label>
                            <input type="text" class="form-control" id="unitAddCode" placeholder="e.g., pkt">
                            <div class="invalid-feedback" id="unitAddCodeError"></div>
                        </div>
                        <div class="mb-2">
                            <label for="unitAddName" class="form-label">Name</label>
                            <input type="text" class="form-control" id="unitAddName" placeholder="e.g., Packet">
                            <div class="invalid-feedback" id="unitAddNameError"></div>
                        </div>
                    </div>
                    <!-- Edit Section -->
                    <div id="unitManageEditSection" class="d-none">
                        <div class="mb-2">
                            <label for="unitEditName" class="form-label">New Name</label>
                            <input type="text" class="form-control" id="unitEditName" placeholder="e.g., Bundle">
                            <div class="invalid-feedback" id="unitEditNameError"></div>
                        </div>
                    </div>
                    <!-- Delete Section -->
                    <div id="unitManageDeleteSection" class="d-none">
                        <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Confirm deletion of this unit type. This will remove it from selection.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary d-none" id="unitManageSaveBtn">
                        <span class="spinner-border spinner-border-sm d-none" id="unitManageLoading" role="status" aria-hidden="true"></span>
                        <span id="unitManageSaveText">Save</span>
                    </button>
                    <button type="button" class="btn btn-danger d-none" id="unitManageDeleteBtn">
                        <span class="spinner-border spinner-border-sm d-none" id="unitManageDeleteLoading" role="status" aria-hidden="true"></span>
                        <span id="unitManageDeleteText">Delete</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Variation Options Management Modal -->
    <div class="modal fade" id="variationManageModal" tabindex="-1" aria-labelledby="variationManageLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="variationManageLabel">Manage Variation Options</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="variationManageStatus" class="visually-hidden" aria-live="polite"></div>
                    <div class="mb-2">
                        <span class="badge bg-primary" id="variationSelectedUnitBadge">Unit: -</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2" id="variationModeSwitch" aria-label="Choose action">
                        <small class="text-muted">Action</small>
                        <div class="btn-group btn-group-sm" role="group" aria-label="Variation manage actions">
                            <input type="radio" class="btn-check" name="variationManageMode" id="vmAdd" autocomplete="off">
                            <label class="btn btn-outline-primary" for="vmAdd">Add</label>
                            <input type="radio" class="btn-check" name="variationManageMode" id="vmEdit" autocomplete="off">
                            <label class="btn btn-outline-primary" for="vmEdit">Edit</label>
                            <input type="radio" class="btn-check" name="variationManageMode" id="vmDelete" autocomplete="off">
                            <label class="btn btn-outline-danger" for="vmDelete">Delete</label>
                        </div>
                    </div>
                    <div id="variationManageAddSection" class="d-none">
                        <div class="mb-3">
                            <label for="variationExistingAttrSelect" class="form-label">Choose Existing Attribute</label>
                            <div class="input-group">
                                <select id="variationExistingAttrSelect" class="form-select" aria-label="Existing attributes"></select>
                                <button type="button" class="btn btn-outline-secondary" id="variationAttrRefreshBtn">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                            </div>
                            <small id="variationAttrLoading" class="text-muted">Loading attributes…</small>
                        </div>
                        <div class="mb-3">
                            <label for="variationAddAttrName" class="form-label">New Attribute</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="variationAddAttrName" placeholder="e.g., Brand">
                                <button type="button" class="btn btn-outline-primary" id="variationAddAttrBtn">Add Attribute</button>
                            </div>
                            <div class="invalid-feedback" id="variationAddAttrError"></div>
                            <small class="text-muted">Attribute names must be unique and non-empty.</small>
                        </div>
                        <div class="mb-3" id="optionManagementSection" style="display: none;">
                            <label class="form-label">Options for Selected Attribute</label>
                            <div class="border rounded p-3 bg-light">
                                <div id="selectedAttributeName" class="fw-bold text-primary mb-2"></div>
                                <div id="optionsList" class="mb-3"></div>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-success" id="addOptionBtn">
                                        <i class="bi bi-plus-circle me-1"></i>Add Option
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="clearOptionsBtn">
                                        <i class="bi bi-x-circle me-1"></i>Clear All
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3" id="optionsPreview" style="display: none;">
                            <label class="form-label">Preview</label>
                            <div class="border rounded p-2 bg-white" style="min-height: 60px;">
                                <div id="previewContent" class="text-muted">No options added yet</div>
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Attributes & Options</label>
                            <div id="variationAddTreeContainer" class="list-group" style="max-height: 280px; overflow:auto"></div>
                        </div>
                    </div>
                    <div id="variationManageEditSection" class="d-none">
                        <div class="alert alert-info mb-2">
                            <i class="bi bi-info-circle me-2"></i>
                            Select attributes or options to edit. Checked items become editable inline.
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="small text-muted">Selected <span class="badge bg-secondary" id="variationEditSelectedCount">0</span></div>
                            <div>
                                <button type="button" class="btn btn-sm btn-outline-secondary me-2" id="variationEditSelectAll">Select All</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="variationEditClear">Clear</button>
                            </div>
                        </div>
                        <div id="variationEditTreeContainer" class="list-group" style="max-height: 280px; overflow:auto"></div>
                    </div>
                    <div id="variationManageDeleteSection" class="d-none">
                        <div class="alert alert-info mb-2">
                            <i class="bi bi-info-circle me-2"></i>
                            Select attributes to delete all their options, or select individual options to delete only those.
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="small text-muted">Selected <span class="badge bg-secondary" id="variationDeleteSelectedCount">0</span></div>
                            <div>
                                <button type="button" class="btn btn-sm btn-outline-secondary me-2" id="variationDeleteSelectAll">Select All</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="variationDeleteClear">Clear</button>
                            </div>
                        </div>
                        <div id="variationDeleteTreeContainer" class="list-group" style="max-height: 280px; overflow:auto"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary d-none" id="variationManageSaveBtn">
                        <span class="spinner-border spinner-border-sm d-none" id="variationManageLoading" role="status" aria-hidden="true"></span>
                        <span id="variationManageSaveText">Save</span>
                    </button>
                    <button type="button" class="btn btn-danger d-none" id="variationManageDeleteBtn">
                        <span class="spinner-border spinner-border-sm d-none" id="variationManageDeleteLoading" role="status" aria-hidden="true"></span>
                        <span id="variationManageDeleteText">Delete Selected</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Inventory Modal -->
    <div class="modal fade" id="deleteInventoryModal" tabindex="-1" aria-labelledby="deleteInventoryModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteInventoryModalLabel">Delete Inventory Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="deleteInventoryForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="id" id="delete-id">
                        <input type="hidden" name="name" id="delete-name-input">
                        <p>Are you sure you want to delete the item: <strong id="delete-item-name"></strong>?</p>
                        <p class="text-danger">This action cannot be undone.</p>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>Warning:</strong> This will permanently remove the inventory item from the system.
                        </div>
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" value="1" id="force-delete" name="force_delete">
                    <label class="form-check-label" for="force-delete">
                        Force delete and remove related confirmed orders and sales history
                    </label>
                    <small class="text-muted d-block">Use only if you understand this will erase order and sales records linked to this item.</small>
                </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script src="../js/unit_utils.js"></script>
    <script>
        // Unit type and variation mappings for admin inventory
        const UNIT_TYPE_CODE_MAP = <?php echo json_encode($UNIT_TYPE_CODE_MAP); ?>;
        const UNIT_VARIATION_OPTIONS = <?php echo json_encode($UNIT_VARIATION_OPTIONS); ?>;
        const CSRF_TOKEN = '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>';
        
        // Mutable maps for client-side management
        const UNIT_TYPE_MAP = { ...UNIT_TYPE_CODE_MAP };
        // Start with empty map - will be loaded from database
        const VARIATION_OPTIONS_MAP = {};
        
        function displayNameFromNormalized(norm) {
            const base = (norm || '').replace(/^per\s+/i, '').trim();
            return base ? base.replace(/\b\w/g, c => c.toUpperCase()) : 'Piece';
        }
        
        // Render variation attributes for the selected unit type
        function renderUnitVariations(unitCode) {
            const container = document.getElementById('unitVariationContainer');
            if (!container) return;
            
            // Ensure container is visible
            container.style.display = '';
            container.classList.remove('d-none');
            
            const priceContainer = document.getElementById('variationPriceContainer');
            if (priceContainer) { priceContainer.innerHTML = ''; }
            container.innerHTML = '';
            const opts = VARIATION_OPTIONS_MAP[unitCode] || {};
            
            // Check if there are any variations
            const hasVariations = Object.keys(opts).length > 0;
            
            if (!hasVariations || (Object.keys(opts).length === 1 && Object.keys(opts)[0] === 'Name')) {
                // No variations available - show helpful message
                const emptyMsg = document.createElement('div');
                emptyMsg.className = 'alert alert-info mb-0';
                emptyMsg.innerHTML = '<i class="bi bi-info-circle me-2"></i>No variations are available for this unit type. Click "Add" above to create variation options, or select a different unit type.';
                container.appendChild(emptyMsg);
                return;
            }
            
            const wrapper = document.createElement('div');
            wrapper.className = 'shp-variations border rounded p-3';
            
            const title = document.createElement('label');
            title.className = 'form-label mb-2';
            title.textContent = 'Variations';
            wrapper.appendChild(title);
            
            // Determine display order: Name, Brand, Type, then others
            const originalAttrs = Object.keys(opts);
            const prioritized = [];
            if (originalAttrs.includes('Brand')) prioritized.push('Brand');
            if (originalAttrs.includes('Type')) prioritized.push('Type');
            const others = originalAttrs.filter(a => !prioritized.includes(a));
            const displayAttrs = ['Name', ...prioritized, ...others];
            
            // Header row
            const headerRow = document.createElement('div');
            headerRow.className = 'shp-variations__header row gx-3';
            displayAttrs.forEach(attr => {
                const col = document.createElement('div');
                col.className = 'col-12 col-md';
                const lbl = document.createElement('div');
                lbl.className = 'shp-label mb-1';
                lbl.textContent = attr;
                col.appendChild(lbl);
                headerRow.appendChild(col);
            });
            wrapper.appendChild(headerRow);
            
            // Controls row
            const controlsRow = document.createElement('div');
            controlsRow.className = 'shp-variations__controls row gx-3';
            displayAttrs.forEach(attr => {
                const col = document.createElement('div');
                col.className = 'col-12 col-md';
                const group = document.createElement('div');
                group.className = 'shp-control-group';
                
                if (attr === 'Name') {
                    const nameEcho = document.createElement('div');
                    nameEcho.className = 'shp-name-echo';
                    nameEcho.id = 'shpProductNameEcho';
                    const nameInput = document.getElementById('productName');
                    const updateEcho = () => { nameEcho.textContent = (nameInput?.value?.trim() || '—'); };
                    updateEcho();
                    nameInput?.addEventListener('input', updateEcho);
                    group.appendChild(nameEcho);
                } else {
                    const values = opts[attr] || [];
                    values.forEach((val, idx) => {
                        const id = `var_${attr.replace(/\s+/g,'_')}_${idx}`;
                        const check = document.createElement('input');
                        check.type = 'checkbox';
                        check.className = 'btn-check';
                        check.id = id;
                        check.name = `variation_attrs[${attr}][]`;
                        check.value = val;
                        const label = document.createElement('label');
                        label.className = 'btn btn-outline-secondary shp-chip';
                        label.setAttribute('for', id);
                        label.setAttribute('role', 'button');
                        label.setAttribute('aria-pressed', 'false');
                        label.textContent = val;
                        group.appendChild(check);
                        group.appendChild(label);
                    });
                }
                col.appendChild(group);
                controlsRow.appendChild(col);
            });
            wrapper.appendChild(controlsRow);
            container.appendChild(wrapper);
            
            // Update aria-pressed on toggle
            container.querySelectorAll('input[type="checkbox"][name^="variation_attrs["]').forEach(cb => {
                cb.addEventListener('change', (e) => {
                    const label = container.querySelector(`label[for="${cb.id}"]`);
                    if (label) { label.setAttribute('aria-pressed', cb.checked ? 'true' : 'false'); }
                    onVariationAttrChange(e);
                });
            });
        }
        
        // Handle variation attribute changes - show/hide price inputs
        function onVariationAttrChange(evt) {
            const cb = evt.target;
            if (!cb || cb.type !== 'checkbox') return;
            const container = document.getElementById('variationPriceContainer');
            if (!container) return;
            
            // Build variation key from checkbox
            const name = cb.getAttribute('name') || '';
            const m = name.match(/^variation_attrs\[(.+)\]\[\]$/);
            if (!m) return;
            const attr = m[1];
            const val = cb.value;
            const key = `${attr}:${val}`;
            
            if (cb.checked) {
                // Check if price input already exists
                const existing = container.querySelector(`input.var-price[data-key="${CSS.escape(key)}"]`);
                if (existing) { 
                    existing.disabled = false; 
                    existing.closest('.col-md-6')?.classList.remove('d-none');
                    return; 
                }
                // Create price input block
                const col = document.createElement('div');
                col.className = 'col-md-6';
                const label = document.createElement('label');
                label.className = 'form-label small';
                label.textContent = `${attr}: ${val}`;
                col.appendChild(label);
                const inputGroup = document.createElement('div');
                inputGroup.className = 'input-group input-group-sm';
                const span = document.createElement('span');
                span.className = 'input-group-text';
                span.textContent = '₱';
                const priceInput = document.createElement('input');
                priceInput.type = 'number';
                priceInput.className = 'form-control var-price';
                priceInput.setAttribute('data-key', key);
                priceInput.name = 'variation_prices[' + key + ']';
                priceInput.step = '0.01';
                priceInput.min = '0';
                priceInput.placeholder = 'Price';
                inputGroup.appendChild(span);
                inputGroup.appendChild(priceInput);
                col.appendChild(inputGroup);
                container.appendChild(col);
            } else {
                // Hide price input
                const existing = container.querySelector(`input.var-price[data-key="${CSS.escape(key)}"]`);
                if (existing) {
                    existing.value = '';
                    existing.closest('.col-md-6')?.classList.add('d-none');
                }
            }
        }
        
        // Unit type management functions
        function normalizedFromName(name) {
            return 'per ' + (name || '').trim().toLowerCase();
        }
        
        async function reloadUnitTypesFromDB() {
            try {
                const response = await fetch('../api/unit_types.php', {
                    method: 'GET',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await response.json();
                if (Array.isArray(data)) {
                    Object.keys(UNIT_TYPE_MAP).forEach(k => delete UNIT_TYPE_MAP[k]);
                    data.forEach(u => {
                        if (u.code && u.name) {
                            const norm = 'per ' + u.name.toLowerCase();
                            UNIT_TYPE_MAP[u.code] = norm;
                        }
                    });
                    return true;
                }
                return false;
            } catch (err) {
                console.error('Failed to reload unit types', err);
                return false;
            }
        }
        
        function renderUnitTypesInto(containerId, isEdit = false) {
            const container = document.getElementById(containerId);
            if (!container) return;
            container.innerHTML = '';
            const row = document.createElement('div');
            row.className = 'row g-2';
            Object.keys(UNIT_TYPE_MAP).forEach(code => {
                const norm = UNIT_TYPE_MAP[code];
                const labelName = displayNameFromNormalized(norm);
                const col = document.createElement('div');
                col.className = 'col-auto';
                const checkWrap = document.createElement('div');
                checkWrap.className = 'form-check';
                const input = document.createElement('input');
                input.className = 'form-check-input';
                input.type = 'radio';
                input.name = 'unit_type_code';
                input.id = (isEdit ? 'editUnitCode_' : 'unitCode_') + code;
                input.value = code;
                const label = document.createElement('label');
                label.className = 'form-check-label';
                label.setAttribute('for', input.id);
                label.textContent = `${labelName} (${code})`;
                checkWrap.appendChild(input);
                checkWrap.appendChild(label);
                col.appendChild(checkWrap);
                row.appendChild(col);
            });
            container.appendChild(row);
            
            // Bind change listeners for variation rendering
            document.querySelectorAll(`#${containerId} input[type="radio"]`).forEach(r => {
                r.addEventListener('change', async () => {
                    if (!r.checked) return;
                    const code = r.value;
                    hydratedUnitCodes.delete(code); // Force refresh
                    if (containerId === 'unitTypeRadios') {
                        await hydrateUnitVariations(code, true);
                        renderUnitVariations(code);
                    }
                });
            });
        }
        
        let unitManageModalInst = null;
        function setUnitManageMode(mode) {
            const add = document.getElementById('unitManageAddSection');
            const edit = document.getElementById('unitManageEditSection');
            const del = document.getElementById('unitManageDeleteSection');
            const saveBtn = document.getElementById('unitManageSaveBtn');
            const delBtn = document.getElementById('unitManageDeleteBtn');
            if (add) add.classList.toggle('d-none', mode !== 'add');
            if (edit) edit.classList.toggle('d-none', mode !== 'edit');
            if (del) del.classList.toggle('d-none', mode !== 'delete');
            if (saveBtn) saveBtn.classList.toggle('d-none', !(mode === 'add' || mode === 'edit'));
            if (delBtn) delBtn.classList.toggle('d-none', mode !== 'delete');
            const title = document.getElementById('unitTypeManageLabel');
            if (title) {
                if (mode === 'add') title.textContent = 'Add Unit Type';
                else if (mode === 'edit') title.textContent = 'Edit Unit Type';
                else if (mode === 'delete') title.textContent = 'Delete Unit Type';
                else title.textContent = 'Manage Unit Type';
            }
        }
        
        function openUnitManageModal(mode) {
            const modalEl = document.getElementById('unitTypeManageModal');
            if (!modalEl) return;
            if (!unitManageModalInst) unitManageModalInst = new bootstrap.Modal(modalEl);
            setUnitManageMode(mode);
            const badge = document.getElementById('selectedUnitBadge');
            const code = getSelectedUnitCode('unitTypeRadios');
            const norm = UNIT_TYPE_MAP[code];
            const name = norm ? displayNameFromNormalized(norm) : '-';
            if (badge) badge.textContent = `Selected: ${name} (${code || '-'})`;
            const editName = document.getElementById('unitEditName');
            if (editName) editName.value = name !== '-' ? name : '';
            const addCode = document.getElementById('unitAddCode');
            const addName = document.getElementById('unitAddName');
            if (addCode) { addCode.value = ''; addCode.classList.remove('is-invalid'); }
            if (addName) { addName.value = ''; addName.classList.remove('is-invalid'); }
            unitManageModalInst.show();
            
            const saveBtn = document.getElementById('unitManageSaveBtn');
            const delBtn = document.getElementById('unitManageDeleteBtn');
            if (saveBtn) {
                saveBtn.onclick = () => {
                    if (mode === 'add') {
                        const codeInput = document.getElementById('unitAddCode');
                        const nameInput = document.getElementById('unitAddName');
                        const codeVal = (codeInput?.value || '').trim();
                        const nameVal = (nameInput?.value || '').trim();
                        const normalized = normalizedFromName(nameVal);
                        let valid = true;
                        if (!/^[A-Za-z0-9]{1,16}$/.test(codeVal)) {
                            valid = false; codeInput?.classList.add('is-invalid');
                        } else if (UNIT_TYPE_MAP[codeVal]) {
                            valid = false; codeInput?.classList.add('is-invalid');
                        } else { codeInput?.classList.remove('is-invalid'); }
                        if (!nameVal) {
                            valid = false; nameInput?.classList.add('is-invalid');
                        } else { nameInput?.classList.remove('is-invalid'); }
                        if (!valid) return;
                        toggleManageLoading('save', true);
                        const form = new FormData();
                        form.append('action', 'add_unit_type');
                        form.append('ajax', '1');
                        form.append('csrf_token', CSRF_TOKEN);
                        form.append('code', codeVal);
                        form.append('name', nameVal);
                        fetch('', { method: 'POST', body: form, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(r => r.json())
                        .then(async data => {
                            if (data && data.success) {
                                await reloadUnitTypesFromDB();
                                renderUnitTypesInto('unitTypeRadios', false);
                                unitManageModalInst.hide();
                                toggleManageLoading('save', false);
                                alert(`Unit type ${codeVal} added.`);
                            } else {
                                toggleManageLoading('save', false);
                                alert(data?.message || 'Add failed.');
                            }
                        })
                        .catch(err => {
                            toggleManageLoading('save', false);
                            alert('Network error: ' + err);
                        });
                    } else if (mode === 'edit' && code) {
                        const nameInput = document.getElementById('unitEditName');
                        const newName = (nameInput?.value || '').trim();
                        if (!newName) {
                            nameInput?.classList.add('is-invalid');
                            return;
                        }
                        nameInput?.classList.remove('is-invalid');
                        toggleManageLoading('save', true);
                        const form = new FormData();
                        form.append('action', 'update_unit_type');
                        form.append('ajax', '1');
                        form.append('csrf_token', CSRF_TOKEN);
                        form.append('code', code);
                        form.append('name', newName);
                        fetch('', { method: 'POST', body: form, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(r => r.json())
                        .then(async data => {
                            if (data && data.success) {
                                await reloadUnitTypesFromDB();
                                renderUnitTypesInto('unitTypeRadios', false);
                                unitManageModalInst.hide();
                                toggleManageLoading('save', false);
                                alert(`Unit type ${code} updated.`);
                            } else {
                                toggleManageLoading('save', false);
                                alert(data?.message || 'Update failed.');
                            }
                        })
                        .catch(err => {
                            toggleManageLoading('save', false);
                            alert('Network error: ' + err);
                        });
                    }
                };
            }
            if (delBtn && code) {
                delBtn.onclick = () => {
                    const ok = confirm(`Delete unit type "${code}"?`);
                    if (!ok) return;
                    toggleManageLoading('delete', true);
                    const prevName = UNIT_TYPE_MAP[code] || null;
                    delete UNIT_TYPE_MAP[code];
                    renderUnitTypesInto('unitTypeRadios', false);
                    const form = new FormData();
                    form.append('action', 'delete_unit_type');
                    form.append('ajax', '1');
                    form.append('csrf_token', CSRF_TOKEN);
                    form.append('code', code);
                    fetch('', { method: 'POST', body: form, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(r => r.json())
                    .then(async data => {
                        if (data && data.success) {
                            await reloadUnitTypesFromDB();
                            renderUnitTypesInto('unitTypeRadios', false);
                            unitManageModalInst.hide();
                            toggleManageLoading('delete', false);
                            alert(`Unit type ${code} deleted.`);
                        } else {
                            if (prevName) { UNIT_TYPE_MAP[code] = prevName; }
                            renderUnitTypesInto('unitTypeRadios', false);
                            toggleManageLoading('delete', false);
                            alert(data?.message || 'Delete failed.');
                        }
                    })
                    .catch(err => {
                        if (prevName) { UNIT_TYPE_MAP[code] = prevName; }
                        renderUnitTypesInto('unitTypeRadios', false);
                        toggleManageLoading('delete', false);
                        alert('Network error: ' + err);
                    });
                };
            }
        }
        
        function toggleManageLoading(which, isLoading) {
            if (which === 'save') {
                const btn = document.getElementById('unitManageSaveBtn');
                const sp = document.getElementById('unitManageLoading');
                const tx = document.getElementById('unitManageSaveText');
                if (btn) btn.disabled = isLoading;
                if (sp) sp.classList.toggle('d-none', !isLoading);
                if (tx) tx.textContent = isLoading ? 'Saving...' : 'Save';
            } else {
                const btn = document.getElementById('unitManageDeleteBtn');
                const sp = document.getElementById('unitManageDeleteLoading');
                const tx = document.getElementById('unitManageDeleteText');
                if (btn) btn.disabled = isLoading;
                if (sp) sp.classList.toggle('d-none', !isLoading);
                if (tx) tx.textContent = isLoading ? 'Deleting...' : 'Delete';
            }
        }
        
        function getSelectedUnitCode(containerId) {
            const sel = document.querySelector(`#${containerId} input[type="radio"]:checked`);
            return sel ? sel.value : null;
        }
        
        // Variation management helpers
        const hydratedUnitCodes = new Set();
        async function hydrateUnitVariations(unitCode, forceRefresh = false) {
            try {
                if (!unitCode) {
                    // If no unit code, ensure container shows a message
                    const container = document.getElementById('unitVariationContainer');
                    if (container && container.innerHTML.trim() === '') {
                        container.innerHTML = '<div class="text-muted p-3 text-center">Please select a unit type above to view available variations...</div>';
                        container.style.display = '';
                        container.classList.remove('d-none');
                    }
                    return;
                }
                if (!forceRefresh && hydratedUnitCodes.has(unitCode)) return;
                const url = `../api/attributes.php?action=attribute_options_by_unit&unit_type_code=${encodeURIComponent(unitCode)}`;
                const resp = await fetch(url, { method: 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await resp.json();
                if (data && data.success && data.attribute_options) {
                    VARIATION_OPTIONS_MAP[unitCode] = data.attribute_options;
                    hydratedUnitCodes.add(unitCode);
                } else {
                    // If no variations found, set empty object
                    VARIATION_OPTIONS_MAP[unitCode] = {};
                    hydratedUnitCodes.add(unitCode);
                }
            } catch (err) {
                console.error('Failed to hydrate unit variations', err);
                // On error, set empty object so rendering can show appropriate message
                VARIATION_OPTIONS_MAP[unitCode] = {};
                hydratedUnitCodes.add(unitCode);
            }
        }
        
        let selectedAttribute = '';
        let attributeCache = [];
        let variationEditState = { attrs: new Map(), options: new Map() };
        let variationDeleteState = { attrs: new Set(), options: new Map() };
        let currentVariationContext = '';
        let currentVariationMode = '';
        let currentVariationUnitCode = '';
        let variationManageModalInst = null;
        
        function setVariationManageStatus(type, message) {
            const status = document.getElementById('variationManageStatus');
            if (!status) return;
            status.classList.remove('visually-hidden', 'alert-success', 'alert-danger', 'alert-warning');
            status.classList.add('alert', type === 'success' ? 'alert-success' : type === 'warning' ? 'alert-warning' : 'alert-danger');
            status.textContent = message;
        }
        
        function showStatus(containerId, type, message) {
            const el = document.getElementById(containerId);
            if (!el) return;
            el.classList.remove('visually-hidden');
            el.className = type === 'success' ? 'alert alert-success' : type === 'warning' ? 'alert alert-warning' : 'alert alert-danger';
            el.textContent = message;
            setTimeout(() => { try { el.className = 'visually-hidden'; el.textContent = ''; } catch(_){} }, 2500);
        }
        
        
        function toggleVariationLoading(which, isLoading) {
            if (which === 'save') {
                const btn = document.getElementById('variationManageSaveBtn');
                const sp = document.getElementById('variationManageLoading');
                const tx = document.getElementById('variationManageSaveText');
                if (btn) btn.disabled = isLoading;
                if (sp) sp.classList.toggle('d-none', !isLoading);
                if (tx) tx.textContent = isLoading ? 'Saving...' : 'Save';
            } else {
                const btn = document.getElementById('variationManageDeleteBtn');
                const sp = document.getElementById('variationManageDeleteLoading');
                const tx = document.getElementById('variationManageDeleteText');
                if (btn) btn.disabled = isLoading;
                if (sp) sp.classList.toggle('d-none', !isLoading);
                if (tx) tx.textContent = isLoading ? 'Deleting...' : 'Delete Selected';
            }
        }
        
        function setVariationManageMode(mode) {
            const add = document.getElementById('variationManageAddSection');
            const edit = document.getElementById('variationManageEditSection');
            const del = document.getElementById('variationManageDeleteSection');
            const saveBtn = document.getElementById('variationManageSaveBtn');
            const delBtn = document.getElementById('variationManageDeleteBtn');
            if (add) add.classList.toggle('d-none', mode !== 'add');
            if (edit) edit.classList.toggle('d-none', mode !== 'edit');
            if (del) del.classList.toggle('d-none', mode !== 'delete');
            if (saveBtn) saveBtn.classList.toggle('d-none', !(mode === 'add' || mode === 'edit'));
            if (delBtn) delBtn.classList.toggle('d-none', mode !== 'delete');
            const title = document.getElementById('variationManageLabel');
            if (title) {
                if (mode === 'add') title.textContent = 'Add Variation Option';
                else if (mode === 'edit') title.textContent = 'Edit Attributes / Options';
                else if (mode === 'delete') title.textContent = 'Delete Attributes / Options';
            }
        }
        
        
        function populateVariationModal(unitCode, mode) {
            const badge = document.getElementById('variationSelectedUnitBadge');
            const norm = UNIT_TYPE_MAP[unitCode];
            const name = norm ? displayNameFromNormalized(norm) : '-';
            if (badge) badge.textContent = `Unit: ${name} (${unitCode || '-'})`;
            selectedAttribute = '';
            
            if (mode === 'add') {
                renderVariationAddTree(unitCode);
                setupOptionManagementEventHandlers(unitCode);
                const addAttrBtn = document.getElementById('variationAddAttrBtn');
                if (addAttrBtn) addAttrBtn.onclick = async () => await handleAddAttribute(unitCode);
                
                // Load attributes for dropdown
                (async () => {
                    const select = document.getElementById('variationExistingAttrSelect');
                    const loading = document.getElementById('variationAttrLoading');
                    if (loading) loading.textContent = 'Loading attributes…';
                    if (select) {
                        const attrs = await loadAttributesFromAPI();
                        const merged = getAllKnownAttributes(unitCode);
                        const list = (Array.isArray(attrs) && attrs.length) ? attrs : merged;
                        select.innerHTML = '';
                        const ph = document.createElement('option');
                        ph.value = '';
                        ph.textContent = '— Select an attribute —';
                        select.appendChild(ph);
                        list.forEach(a => {
                            const o = document.createElement('option');
                            o.value = a;
                            o.textContent = a;
                            select.appendChild(o);
                        });
                        if (loading) loading.textContent = list.length ? 'Select an attribute to add options.' : 'No attributes found. Add a new one below.';
                        select.onchange = () => {
                            selectedAttribute = (select.value || '').trim();
                            initializeOptionManagementForAttribute(selectedAttribute, unitCode);
                            const addNameInput = document.getElementById('variationAddAttrName');
                            if (addNameInput) {
                                addNameInput.value = '';
                                addNameInput.classList.remove('is-invalid');
                                const err = document.getElementById('variationAddAttrError');
                                if (err) err.textContent = '';
                            }
                        };
                        const refreshBtn = document.getElementById('variationAttrRefreshBtn');
                        if (refreshBtn) refreshBtn.onclick = async () => {
                            if (loading) loading.textContent = 'Refreshing…';
                            await loadAttributesFromAPI();
                            const updated = getAllKnownAttributes(unitCode);
                            select.innerHTML = '';
                            const ph2 = document.createElement('option');
                            ph2.value = '';
                            ph2.textContent = '— Select an attribute —';
                            select.appendChild(ph2);
                            updated.forEach(a => {
                                const o = document.createElement('option');
                                o.value = a;
                                o.textContent = a;
                                select.appendChild(o);
                            });
                            if (loading) loading.textContent = 'Select an attribute to add options.';
                        };
                    }
                })();
                
                const addNameInput = document.getElementById('variationAddAttrName');
                if (addNameInput) {
                    addNameInput.addEventListener('input', () => {
                        selectedAttribute = (addNameInput.value || '').trim();
                        initializeOptionManagementForAttribute(selectedAttribute, unitCode);
                        const err = document.getElementById('variationAddAttrError');
                        if (err) err.textContent = '';
                        addNameInput.classList.remove('is-invalid');
                    });
                }
            }
            if (mode === 'edit') {
                resetVariationEditState();
                renderVariationEditTree(unitCode);
            }
            if (mode === 'delete') {
                resetVariationDeleteState();
                renderVariationDeleteTree(unitCode);
            }
        }
        
        async function openVariationManageModal(mode) {
            // Try to get unit code from edit form first, fallback to checking for any selected
            let unitCode = getSelectedUnitCode('editUnitTypeRadios');
            if (!unitCode) {
                unitCode = getSelectedUnitCode('unitTypeRadios');
            }
            if (!unitCode) { alert('Please select a unit type first.'); return; }
            const modalEl = document.getElementById('variationManageModal');
            if (!modalEl) return;
            if (!variationManageModalInst) variationManageModalInst = new bootstrap.Modal(modalEl);
            setVariationManageMode(mode);
            currentVariationContext = 'editForm';
            currentVariationMode = mode;
            currentVariationUnitCode = unitCode;
            hydratedUnitCodes.delete(unitCode); // Force refresh before opening
            await hydrateUnitVariations(unitCode, true);
            populateVariationModal(unitCode, mode);
            variationManageModalInst.show();
            
            // Wire mode switch radios
            try {
                const vmAdd = document.getElementById('vmAdd');
                const vmEdit = document.getElementById('vmEdit');
                const vmDelete = document.getElementById('vmDelete');
                if (vmAdd && vmEdit && vmDelete) {
                    vmAdd.checked = (mode === 'add');
                    vmEdit.checked = (mode === 'edit');
                    vmDelete.checked = (mode === 'delete');
                    const setMode = async (m) => {
                        currentVariationMode = m;
                        setVariationManageMode(m);
                        hydratedUnitCodes.delete(unitCode); // Force refresh
                        await hydrateUnitVariations(unitCode, true);
                        populateVariationModal(unitCode, m);
                    };
                    vmAdd.onchange = () => setMode('add');
                    vmEdit.onchange = () => setMode('edit');
                    vmDelete.onchange = () => setMode('delete');
                }
            } catch (_) {}
            
            // Save and Delete handlers
            const saveBtn = document.getElementById('variationManageSaveBtn');
            const delBtn = document.getElementById('variationManageDeleteBtn');
            
            if (saveBtn) {
                saveBtn.onclick = async () => {
                    const mode = currentVariationMode;
                    if (mode === 'add') {
                        const addNameInput = document.getElementById('variationAddAttrName');
                        const attr = (selectedAttribute || (addNameInput ? addNameInput.value : '') || '').trim();
                        if (!attr) {
                            if (addNameInput) {
                                addNameInput.classList.add('is-invalid');
                                const err = document.getElementById('variationAddAttrError');
                                if (err) err.textContent = 'Please enter an attribute name first.';
                            }
                            return;
                        }
                        const options = collectOptions();
                        if (!options || options.length === 0) {
                            setVariationManageStatus('warning', 'Please add at least one option.');
                            return;
                        }
                        const existingList = VARIATION_OPTIONS_MAP[unitCode]?.[attr] || [];
                        const deduped = options.filter(v => !existingList.includes(v));
                        if (deduped.length === 0) {
                            setVariationManageStatus('warning', 'All options already exist.');
                            return;
                        }
                        toggleVariationLoading('save', true);
                        for (const opt of deduped) {
                            try { await saveAttributeOption(attr, opt, unitCode); } catch (_) {}
                        }
                        hydratedUnitCodes.delete(unitCode); // Force refresh
                        await hydrateUnitVariations(unitCode, true);
                        variationManageModalInst.hide();
                        toggleVariationLoading('save', false);
                        alert('Variations added and saved to database.');
                    } else if (mode === 'edit') {
                        const attrRenames = [];
                        variationEditState.attrs.forEach((newName, oldName) => {
                            const nn = (newName || '').trim();
                            if (nn && nn !== oldName) attrRenames.push({ current: oldName, new: nn });
                        });
                        const optionRenames = [];
                        variationEditState.options.forEach((map, a) => {
                            map.forEach((nn, cur) => {
                                const val = (nn || '').trim();
                                if (val && val !== cur) optionRenames.push({ attribute: a, current: cur, new: val });
                            });
                        });
                        if (attrRenames.length === 0 && optionRenames.length === 0) {
                            setVariationManageStatus('warning', 'No changes selected.');
                            return;
                        }
                        for (const { current, new: nn } of attrRenames) {
                            const exists = Object.prototype.hasOwnProperty.call(VARIATION_OPTIONS_MAP[unitCode] || {}, nn);
                            if (exists) { setVariationManageStatus('danger', `Attribute "${nn}" already exists.`); return; }
                        }
                        for (const { attribute, current, new: nn } of optionRenames) {
                            const list = VARIATION_OPTIONS_MAP[unitCode]?.[attribute] || [];
                            if (list.includes(nn)) { setVariationManageStatus('danger', `Option "${nn}" already exists under ${attribute}.`); return; }
                        }
                        toggleVariationLoading('save', true);
                        try {
                            for (const ren of attrRenames) {
                                const body = { action: 'rename_attribute', unit_type_code: unitCode, current: ren.current, new: ren.new };
                                const r = await fetch('../api/attributes.php', {
                                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
                                });
                                const data = await r.json();
                                if (!data?.success) throw new Error(data?.error || 'Failed to rename attribute');
                                const arr = VARIATION_OPTIONS_MAP[unitCode]?.[ren.current] || [];
                                if (!VARIATION_OPTIONS_MAP[unitCode]) VARIATION_OPTIONS_MAP[unitCode] = {};
                                VARIATION_OPTIONS_MAP[unitCode][ren.new] = arr;
                                try { delete VARIATION_OPTIONS_MAP[unitCode][ren.current]; } catch(_){}
                            }
                            for (const ren of optionRenames) {
                                const body = { action: 'rename_option', unit_type_code: unitCode, attribute: ren.attribute, current: ren.current, new: ren.new };
                                const r1 = await fetch('../api/attributes.php', {
                                    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
                                });
                                const d1 = await r1.json();
                                if (!d1?.success) throw new Error(d1?.error || 'Failed to rename option');
                                const arr = VARIATION_OPTIONS_MAP[unitCode]?.[ren.attribute] || [];
                                const idx = arr.indexOf(ren.current);
                                if (idx >= 0) arr[idx] = ren.new;
                            }
                            hydratedUnitCodes.delete(unitCode); // Force refresh
                            await hydrateUnitVariations(unitCode, true);
                            variationManageModalInst.hide();
                            toggleVariationLoading('save', false);
                            alert('Edits applied and saved to database.');
                        } catch (err) {
                            console.error(err);
                            alert('Error saving edits: ' + String(err));
                            toggleVariationLoading('save', false);
                        }
                    }
                };
            }
            
            if (delBtn) {
                delBtn.onclick = async () => {
                    const opts = VARIATION_OPTIONS_MAP[unitCode] || {};
                    const selectedVars = [];
                    variationDeleteState.attrs.forEach(attr => {
                        (opts[attr] || []).forEach(val => selectedVars.push(attr + ':' + val));
                    });
                    variationDeleteState.options.forEach((set, attr) => {
                        set.forEach(val => selectedVars.push(attr + ':' + val));
                    });
                    if (selectedVars.length === 0) { alert('Select attributes or options to delete.'); return; }
                    const attrCount = variationDeleteState.attrs.size;
                    let optCount = 0;
                    variationDeleteState.options.forEach(set => optCount += set.size);
                    const ok = confirm(`Delete ${attrCount} attribute(s) and ${optCount} option(s)? This can be undone.`);
                    if (!ok) return;
                    toggleVariationLoading('delete', true);
                    try {
                        for (const attr of Array.from(variationDeleteState.attrs)) {
                            const opts_list = VARIATION_OPTIONS_MAP[unitCode]?.[attr] || [];
                            for (const val of opts_list) {
                                try {
                                    const body = { action: 'delete_attribute_option', unit_type_code: unitCode, attribute: attr, value: val };
                                    const r = await fetch('../api/attributes.php', {
                                        method: 'DELETE',
                                        headers: { 'Content-Type': 'application/json' },
                                        body: JSON.stringify(body)
                                    });
                                    const data = await r.json();
                                    if (data && data.success) {
                                        const arr = VARIATION_OPTIONS_MAP[unitCode]?.[attr] || [];
                                        const idx = arr.indexOf(val);
                                        if (idx >= 0) { arr.splice(idx, 1); }
                                    }
                                } catch (err) {
                                    console.error('Error deleting option', err);
                                }
                            }
                            if ((VARIATION_OPTIONS_MAP[unitCode]?.[attr] || []).length === 0) {
                                try { delete VARIATION_OPTIONS_MAP[unitCode][attr]; } catch(_){}
                            }
                        }
                        for (const [attr, optionSet] of Array.from(variationDeleteState.options.entries())) {
                            for (const val of Array.from(optionSet)) {
                                try {
                                    const body = { action: 'delete_attribute_option', unit_type_code: unitCode, attribute: attr, value: val };
                                    const r = await fetch('../api/attributes.php', {
                                        method: 'DELETE',
                                        headers: { 'Content-Type': 'application/json' },
                                        body: JSON.stringify(body)
                                    });
                                    const data = await r.json();
                                    if (data && data.success) {
                                        const arr = VARIATION_OPTIONS_MAP[unitCode]?.[attr] || [];
                                        const idx = arr.indexOf(val);
                                        if (idx >= 0) { arr.splice(idx, 1); }
                                        if (arr.length === 0) { try { delete VARIATION_OPTIONS_MAP[unitCode][attr]; } catch(_){} }
                                    }
                                } catch (err) {
                                    console.error('Error deleting option', err);
                                }
                            }
                        }
                        hydratedUnitCodes.delete(unitCode); // Force refresh
                        await hydrateUnitVariations(unitCode, true);
                        selectedAttribute = '';
                        variationManageModalInst.hide();
                        toggleVariationLoading('delete', false);
                        alert('Selected variations deleted from database.');
                    } catch (err) {
                        console.error('Error in delete operation', err);
                        toggleVariationLoading('delete', false);
                        alert('Error deleting variations. Some may not have been removed.');
                    }
                };
            }
        }
        
        function renderVariationAddTree(unitCode) {
            const container = document.getElementById('variationAddTreeContainer');
            if (!container) return;
            container.innerHTML = '';
            const opts = VARIATION_OPTIONS_MAP[unitCode] || {};
            const attrs = Object.keys(opts);
            if (attrs.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'list-group-item text-muted';
                empty.textContent = 'No attributes yet. Add one above to get started.';
                container.appendChild(empty);
                return;
            }
            attrs.forEach(attr => {
                const liAttr = document.createElement('div');
                liAttr.className = 'list-group-item';
                const attrRow = document.createElement('div');
                attrRow.className = 'd-flex justify-content-between align-items-center mb-2';
                const title = document.createElement('div');
                title.innerHTML = `<span class="fw-semibold">${attr}</span>`;
                const badge = document.createElement('div');
                badge.className = 'badge bg-light text-dark';
                badge.textContent = `${(opts[attr]||[]).length} options`;
                attrRow.appendChild(title); attrRow.appendChild(badge);
                liAttr.appendChild(attrRow);
                const optList = document.createElement('div');
                optList.className = 'mb-2 ps-3';
                (opts[attr] || []).forEach(val => {
                    const chip = document.createElement('span');
                    chip.className = 'badge bg-secondary me-2 mb-2';
                    chip.textContent = val;
                    optList.appendChild(chip);
                });
                liAttr.appendChild(optList);
                const addOptRow = document.createElement('div');
                addOptRow.className = 'input-group input-group-sm';
                const addInput = document.createElement('input');
                addInput.type = 'text';
                addInput.className = 'form-control';
                addInput.placeholder = 'Add option...';
                const addBtn = document.createElement('button');
                addBtn.type = 'button';
                addBtn.className = 'btn btn-outline-success';
                addBtn.textContent = 'Add';
                addBtn.onclick = async () => {
                    const val = addInput.value.trim();
                    if (!val) return;
                    const existing = opts[attr] || [];
                    if (existing.includes(val)) {
                        alert('Option already exists.');
                        return;
                    }
                    await saveAttributeOption(attr, val, unitCode);
                    hydratedUnitCodes.delete(unitCode); // Force refresh
                    await hydrateUnitVariations(unitCode, true);
                    renderVariationAddTree(unitCode);
                    renderUnitVariations(unitCode);
                    addInput.value = '';
                    setVariationManageStatus('success', `Option "${val}" added to ${attr}.`);
                };
                addOptRow.appendChild(addInput);
                addOptRow.appendChild(addBtn);
                liAttr.appendChild(addOptRow);
                container.appendChild(liAttr);
            });
        }
        
        async function loadOptionsForAttribute(attribute, unitCode) {
            try {
                const endpoint = unitCode
                    ? `../api/attributes.php?action=options_for_attribute_by_unit&attribute=${encodeURIComponent(attribute)}&unit_type_code=${encodeURIComponent(unitCode)}`
                    : `../api/attributes.php?action=options&attribute=${encodeURIComponent(attribute)}`;
                const response = await fetch(endpoint, {
                    method: 'GET',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await response.json();
                if (data.success) {
                    return Array.isArray(data.options) ? data.options : [];
                }
                return [];
            } catch (err) {
                console.error('Error loading options for attribute:', err);
                return [];
            }
        }
        
        async function loadAttributesFromAPI() {
            try {
                const response = await fetch('../api/attributes.php?action=attributes', {
                    method: 'GET',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await response.json();
                if (data && data.success && Array.isArray(data.attributes)) {
                    return data.attributes;
                }
                return [];
            } catch (err) {
                console.error('Error loading attributes:', err);
                return [];
            }
        }
        
        function getAllKnownAttributes(unitCode) {
            const fromMap = Object.keys(VARIATION_OPTIONS_MAP[unitCode] || {});
            const merged = [...new Set([...attributeCache, ...fromMap])];
            return merged.sort((a, b) => a.localeCompare(b));
        }
        
        function addOptionInput(value = '') {
            const list = document.getElementById('optionsList');
            if (!list) return;
            const row = document.createElement('div');
            row.className = 'input-group input-group-sm mb-2 option-row';
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control option-input';
            input.placeholder = 'e.g., 750ml';
            input.value = value;
            const btnWrap = document.createElement('div');
            btnWrap.className = 'input-group-text p-0 border-0 bg-transparent';
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-outline-danger btn-sm';
            removeBtn.innerHTML = '<i class="bi bi-trash"></i>';
            removeBtn.addEventListener('click', () => {
                try { row.remove(); updateOptionsPreview(); } catch (_) {}
            });
            btnWrap.appendChild(removeBtn);
            row.appendChild(input);
            row.appendChild(btnWrap);
            list.appendChild(row);
            input.addEventListener('input', () => {
                input.classList.remove('is-invalid');
                updateOptionsPreview();
            });
            updateOptionsPreview();
        }
        
        function clearAllOptions() {
            const list = document.getElementById('optionsList');
            if (!list) return;
            list.innerHTML = '';
            updateOptionsPreview();
        }
        
        function collectOptions() {
            const inputs = Array.from(document.querySelectorAll('#optionsList .option-input'));
            const vals = inputs
                .map(i => (i.value || '').trim())
                .filter(v => v !== '');
            const seen = new Set();
            const unique = [];
            vals.forEach(v => { if (!seen.has(v)) { seen.add(v); unique.push(v); } });
            const counts = vals.reduce((acc, v) => { acc[v] = (acc[v]||0) + 1; return acc; }, {});
            inputs.forEach(i => {
                const v = (i.value || '').trim();
                if (v && counts[v] > 1) i.classList.add('is-invalid'); else i.classList.remove('is-invalid');
            });
            return unique;
        }
        
        function updateOptionsPreview() {
            const preview = document.getElementById('optionsPreview');
            const content = document.getElementById('previewContent');
            if (!preview || !content) return;
            const options = collectOptions();
            if (options.length === 0) {
                preview.style.display = 'none';
                content.textContent = 'No options added yet';
                return;
            }
            preview.style.display = '';
            content.innerHTML = '';
            options.forEach(v => {
                const chip = document.createElement('span');
                chip.className = 'badge bg-secondary me-2 mb-2';
                chip.textContent = v;
                content.appendChild(chip);
            });
        }
        
        async function initializeOptionManagementForAttribute(attribute, unitCode) {
            const section = document.getElementById('optionManagementSection');
            const nameEl = document.getElementById('selectedAttributeName');
            const list = document.getElementById('optionsList');
            if (!section || !nameEl || !list) return;
            nameEl.textContent = attribute ? `Attribute: ${attribute}` : '';
            section.style.display = attribute ? '' : 'none';
            clearAllOptions();
            if (!attribute) { updateOptionsPreview(); return; }
            const existing = (VARIATION_OPTIONS_MAP[unitCode]?.[attribute] || []);
            if (existing.length === 0) {
                try {
                    const fromApi = await loadOptionsForAttribute(attribute, unitCode);
                    fromApi.forEach(v => addOptionInput(v));
                } catch (_) { /* ignore */ }
            } else {
                existing.forEach(v => addOptionInput(v));
            }
            updateOptionsPreview();
        }
        
        function setupOptionManagementEventHandlers(unitCode) {
            const addBtn = document.getElementById('addOptionBtn');
            const clearBtn = document.getElementById('clearOptionsBtn');
            if (addBtn) addBtn.onclick = () => addOptionInput('');
            if (clearBtn) clearBtn.onclick = () => clearAllOptions();
        }
        
        async function saveAttributeOption(attribute, option, unitCode) {
            try {
                const formData = new FormData();
                formData.append('action', 'add_attribute_option');
                formData.append('attribute', attribute);
                formData.append('value', option);
                if (unitCode) { formData.append('unit_type_code', unitCode); }
                const response = await fetch('../api/attributes.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await response.json();
                if (data && data.success) {
                    if (!attributeCache.includes(attribute)) {
                        attributeCache.push(attribute);
                        attributeCache.sort();
                    }
                    return true;
                } else {
                    console.error('Failed to save attribute option:', data?.error || data?.message);
                    return false;
                }
            } catch (error) {
                console.error('Error saving attribute option:', error);
                return false;
            }
        }
        
        async function handleAddAttribute(unitCode) {
            const input = document.getElementById('variationAddAttrName');
            const err = document.getElementById('variationAddAttrError');
            if (!input || !err) return;
            const name = (input.value || '').trim();
            if (!name) { input.classList.add('is-invalid'); err.textContent = 'Attribute name is required.'; return; }
            const opts = VARIATION_OPTIONS_MAP[unitCode] || {};
            if (Object.keys(opts).includes(name)) { input.classList.add('is-invalid'); err.textContent = 'Attribute already exists.'; return; }
            input.classList.remove('is-invalid'); err.textContent = '';
            if (!VARIATION_OPTIONS_MAP[unitCode]) VARIATION_OPTIONS_MAP[unitCode] = {};
            VARIATION_OPTIONS_MAP[unitCode][name] = [];
            if (!attributeCache.includes(name)) {
                attributeCache.push(name);
                attributeCache.sort((a,b) => a.localeCompare(b));
            }
            // Refresh from DB to get latest state
            hydratedUnitCodes.delete(unitCode); // Force refresh
            await hydrateUnitVariations(unitCode, true);
            selectedAttribute = name;
            initializeOptionManagementForAttribute(selectedAttribute, unitCode);
            renderVariationAddTree(unitCode);
            rerenderVariations(unitCode);
            setVariationManageStatus('success', 'Attribute added. Add options below to persist for edits.');
            input.value = '';
        }
        
        function rerenderVariations(unitCode) {
            renderUnitVariations(unitCode);
        }
        
        function updateDeleteSelectionSummary() {
            const badge = document.getElementById('variationDeleteSelectedCount');
            if (!badge) return;
            let count = variationDeleteState.attrs.size;
            variationDeleteState.options.forEach(set => { count += set.size; });
            badge.textContent = String(count);
        }
        
        function resetVariationDeleteState() {
            variationDeleteState = { attrs: new Set(), options: new Map() };
            updateDeleteSelectionSummary();
        }
        
        function renderVariationDeleteTree(unitCode) {
            const container = document.getElementById('variationDeleteTreeContainer');
            if (!container) return;
            container.innerHTML = '';
            const opts = VARIATION_OPTIONS_MAP[unitCode] || {};
            Object.keys(opts).forEach(attr => {
                const liAttr = document.createElement('div');
                liAttr.className = 'list-group-item';
                const attrRow = document.createElement('div');
                attrRow.className = 'd-flex justify-content-between align-items-center';
                const left = document.createElement('div');
                left.className = 'form-check';
                const cbAttr = document.createElement('input');
                cbAttr.type = 'checkbox';
                cbAttr.className = 'form-check-input';
                cbAttr.id = `del-attr-${attr}`;
                cbAttr.title = 'Delete attribute and all its options';
                const lblAttr = document.createElement('label');
                lblAttr.className = 'form-check-label fw-semibold';
                lblAttr.setAttribute('for', cbAttr.id);
                lblAttr.textContent = attr;
                left.appendChild(cbAttr); left.appendChild(lblAttr);
                const right = document.createElement('div');
                right.className = 'badge bg-light text-dark';
                right.textContent = `${(opts[attr]||[]).length} options`;
                attrRow.appendChild(left); attrRow.appendChild(right);
                liAttr.appendChild(attrRow);
                const optsWrap = document.createElement('div');
                optsWrap.className = 'mt-2 ps-4';
                (opts[attr] || []).forEach(val => {
                    const optRow = document.createElement('div');
                    optRow.className = 'form-check';
                    const cbOpt = document.createElement('input');
                    cbOpt.type = 'checkbox';
                    cbOpt.className = 'form-check-input';
                    cbOpt.id = `del-opt-${attr}-${val}`;
                    cbOpt.title = 'Delete only this option';
                    const lblOpt = document.createElement('label');
                    lblOpt.className = 'form-check-label';
                    lblOpt.setAttribute('for', cbOpt.id);
                    lblOpt.textContent = val;
                    optRow.appendChild(cbOpt); optRow.appendChild(lblOpt);
                    optsWrap.appendChild(optRow);
                    cbOpt.addEventListener('change', () => {
                        if (variationDeleteState.attrs.has(attr)) {
                            variationDeleteState.attrs.delete(attr);
                            cbAttr.checked = false;
                            liAttr.classList.remove('border', 'border-danger');
                        }
                        let set = variationDeleteState.options.get(attr);
                        if (!set) { set = new Set(); variationDeleteState.options.set(attr, set); }
                        if (cbOpt.checked) set.add(val); else set.delete(val);
                        updateDeleteSelectionSummary();
                    });
                });
                liAttr.appendChild(optsWrap);
                cbAttr.addEventListener('change', () => {
                    if (cbAttr.checked) {
                        variationDeleteState.attrs.add(attr);
                        variationDeleteState.options.delete(attr);
                        optsWrap.querySelectorAll('input[type="checkbox"]').forEach(c => { c.checked = false; });
                        liAttr.classList.add('border', 'border-danger');
                    } else {
                        variationDeleteState.attrs.delete(attr);
                        liAttr.classList.remove('border', 'border-danger');
                    }
                    updateDeleteSelectionSummary();
                });
                container.appendChild(liAttr);
            });
            const btnAll = document.getElementById('variationDeleteSelectAll');
            const btnClear = document.getElementById('variationDeleteClear');
            if (btnAll) btnAll.onclick = () => {
                resetVariationDeleteState();
                container.querySelectorAll('input[id^="del-attr-"]').forEach(cb => { cb.checked = true; cb.dispatchEvent(new Event('change')); });
            };
            if (btnClear) btnClear.onclick = () => {
                container.querySelectorAll('input[type="checkbox"]').forEach(cb => { cb.checked = false; });
                resetVariationDeleteState();
            };
        }
        
        function resetVariationEditState() {
            variationEditState = { attrs: new Map(), options: new Map() };
            updateEditSelectionSummary();
        }
        
        function updateEditSelectionSummary() {
            const badge = document.getElementById('variationEditSelectedCount');
            if (!badge) return;
            let count = 0;
            variationEditState.attrs.forEach(() => { count += 1; });
            variationEditState.options.forEach(map => { count += map.size; });
            badge.textContent = String(count);
        }
        
        function renderVariationEditTree(unitCode) {
            const container = document.getElementById('variationEditTreeContainer');
            if (!container) return;
            container.innerHTML = '';
            const opts = VARIATION_OPTIONS_MAP[unitCode] || {};
            const attrs = Object.keys(opts);
            if (attrs.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'list-group-item text-muted';
                empty.textContent = 'No attributes yet. Add one in Add mode first.';
                container.appendChild(empty);
                return;
            }
            attrs.forEach(attr => {
                const liAttr = document.createElement('div');
                liAttr.className = 'list-group-item';
                const row = document.createElement('div');
                row.className = 'd-flex justify-content-between align-items-center gap-2';
                const left = document.createElement('div');
                left.className = 'd-flex align-items-center gap-2';
                const formCheck = document.createElement('div');
                formCheck.className = 'form-check';
                const cbAttr = document.createElement('input');
                cbAttr.type = 'checkbox';
                cbAttr.className = 'form-check-input';
                cbAttr.id = `edit-attr-${attr}`;
                cbAttr.title = 'Edit attribute name';
                const lbl = document.createElement('label');
                lbl.className = 'form-check-label fw-semibold';
                lbl.setAttribute('for', cbAttr.id);
                lbl.textContent = attr;
                formCheck.appendChild(cbAttr); formCheck.appendChild(lbl);
                const inputAttr = document.createElement('input');
                inputAttr.type = 'text';
                inputAttr.className = 'form-control form-control-sm';
                inputAttr.style.maxWidth = '220px';
                inputAttr.placeholder = 'New attribute name';
                inputAttr.value = attr;
                inputAttr.disabled = true;
                left.appendChild(formCheck); left.appendChild(inputAttr);
                const right = document.createElement('div');
                right.className = 'badge bg-light text-dark';
                right.textContent = `${(opts[attr]||[]).length} options`;
                row.appendChild(left); row.appendChild(right);
                liAttr.appendChild(row);
                const optsWrap = document.createElement('div');
                optsWrap.className = 'mt-2 ps-4';
                (opts[attr] || []).forEach(val => {
                    const optRow = document.createElement('div');
                    optRow.className = 'd-flex align-items-center gap-2';
                    const fc = document.createElement('div'); fc.className = 'form-check';
                    const cbOpt = document.createElement('input'); cbOpt.type = 'checkbox'; cbOpt.className = 'form-check-input'; cbOpt.id = `edit-opt-${attr}-${val}`; cbOpt.title = 'Edit option name';
                    const lblOpt = document.createElement('label'); lblOpt.className = 'form-check-label'; lblOpt.setAttribute('for', cbOpt.id); lblOpt.textContent = val;
                    fc.appendChild(cbOpt); fc.appendChild(lblOpt);
                    const inputVal = document.createElement('input'); inputVal.type = 'text'; inputVal.className = 'form-control form-control-sm'; inputVal.style.maxWidth = '220px'; inputVal.placeholder = 'New option name'; inputVal.value = val; inputVal.disabled = true;
                    optRow.appendChild(fc); optRow.appendChild(inputVal);
                    optsWrap.appendChild(optRow);
                    cbOpt.addEventListener('change', () => {
                        inputVal.disabled = !cbOpt.checked;
                        optRow.classList.toggle('border', cbOpt.checked);
                        optRow.classList.toggle('border-primary', cbOpt.checked);
                        optRow.classList.toggle('bg-light', cbOpt.checked);
                        let map = variationEditState.options.get(attr);
                        if (!map) { map = new Map(); variationEditState.options.set(attr, map); }
                        if (cbOpt.checked) { map.set(val, inputVal.value.trim()); } else { map.delete(val); }
                        updateEditSelectionSummary();
                    });
                    inputVal.addEventListener('input', () => {
                        const map = variationEditState.options.get(attr);
                        if (map && map.has(val)) { map.set(val, inputVal.value.trim()); }
                        updateEditSelectionSummary();
                    });
                    cbOpt.disabled = !variationEditState.attrs.has(attr);
                });
                liAttr.appendChild(optsWrap);
                cbAttr.addEventListener('change', () => {
                    inputAttr.disabled = !cbAttr.checked;
                    if (cbAttr.checked) {
                        variationEditState.attrs.set(attr, inputAttr.value.trim());
                        liAttr.classList.add('border', 'border-primary', 'bg-light');
                        optsWrap.querySelectorAll('input[type="checkbox"]').forEach(c => { c.disabled = false; });
                    } else {
                        variationEditState.attrs.delete(attr);
                        liAttr.classList.remove('border', 'border-primary', 'bg-light');
                        optsWrap.querySelectorAll('input[type="checkbox"]').forEach(c => { c.checked = false; c.disabled = true; c.dispatchEvent(new Event('change')); });
                    }
                    updateEditSelectionSummary();
                });
                inputAttr.addEventListener('input', () => {
                    if (variationEditState.attrs.has(attr)) { variationEditState.attrs.set(attr, inputAttr.value.trim()); }
                    updateEditSelectionSummary();
                });
                container.appendChild(liAttr);
            });
            const btnAll = document.getElementById('variationEditSelectAll');
            const btnClear = document.getElementById('variationEditClear');
            if (btnAll) btnAll.onclick = () => {
                resetVariationEditState();
                container.querySelectorAll('input[id^="edit-attr-"]').forEach(cb => { cb.checked = true; cb.dispatchEvent(new Event('change')); });
                container.querySelectorAll('input[id^="edit-opt-"]').forEach(cb => { cb.checked = true; cb.dispatchEvent(new Event('change')); });
            };
            if (btnClear) btnClear.onclick = () => {
                container.querySelectorAll('input[type="checkbox"]').forEach(cb => { cb.checked = false; cb.dispatchEvent(new Event('change')); });
                resetVariationEditState();
            };
        }
        
        // Listen to unit type radio selection (for edit form only)
        document.addEventListener('DOMContentLoaded', function() {
            // Edit form unit type management buttons
            const btnEditAdd = document.getElementById('btnEditUnitTypeAdd');
            const btnEditEdit = document.getElementById('btnEditUnitTypeEdit');
            const btnEditDel = document.getElementById('btnEditUnitTypeDelete');
            if (btnEditAdd) btnEditAdd.addEventListener('click', () => openUnitManageModal('add'));
            if (btnEditEdit) btnEditEdit.addEventListener('click', () => openUnitManageModal('edit'));
            if (btnEditDel) btnEditDel.addEventListener('click', () => openUnitManageModal('delete'));
            
            // Edit form variation management buttons
            const btnEditVariationAdd = document.getElementById('btnEditVariationAdd');
            const btnEditVariationEdit = document.getElementById('btnEditVariationEdit');
            const btnEditVariationDelete = document.getElementById('btnEditVariationDelete');
            if (btnEditVariationAdd) btnEditVariationAdd.addEventListener('click', () => openVariationManageModal('add'));
            if (btnEditVariationEdit) btnEditVariationEdit.addEventListener('click', () => openVariationManageModal('edit'));
            if (btnEditVariationDelete) btnEditVariationDelete.addEventListener('click', () => openVariationManageModal('delete'));
        });
    </script>
    <script>
        // Variation price helpers
        function syncVarPriceInputs(containerSelector){
            var $c = $(containerSelector);
            $c.find('.variation-price').each(function(){
                var key = ($(this).data('variant') || '').toString();
                // Escape quotes and backslashes for safe attribute selector matching
                var keyEsc = key.replace(/(["'\\])/g, '\\$1');
                var $chk = $c.find('input.form-check-input[name="variations[]"][value="'+keyEsc+'"]');
                if ($chk.length === 0) {
                    // Fallback: match by exact value via filter
                    $chk = $c.find('input.form-check-input[name="variations[]"]').filter(function(){ return ($(this).val() || '') === key; });
                }
                var enabled = $chk.is(':checked');
                $(this).prop('disabled', !enabled);
                if (!enabled) { $(this).val(''); }
            });
        }
        function prefillVarPriceInputs(containerSelector, priceMap){
            var $c = $(containerSelector);
            priceMap = priceMap || {};
            $c.find('.variation-price').each(function(){
                var key = ($(this).data('variant') || '').toString();
                if (Object.prototype.hasOwnProperty.call(priceMap, key) && priceMap[key] != null) {
                    var val = parseFloat(priceMap[key]);
                    if (!isNaN(val)) { $(this).val(val.toFixed(2)); }
                }
            });
        }
        $(document).ready(function() {
            // Improve accessibility for variation price inputs
            $('.variation-price').attr('inputmode','decimal').each(function(){ $(this).attr('aria-label', (($(this).data('variant')||'') + ' price')); });
            // Initialize DataTable
            $('#inventoryTable').DataTable({
                responsive: true,
                order: [[0, 'asc']]
            });
            
            // View Item
            $('.view-btn').click(function() {
                $('#view-sku').text($(this).data('sku'));
                $('#view-name').text($(this).data('name'));
                $('#view-description').text($(this).data('description'));
                $('#view-reorder').text($(this).data('reorder'));
                $('#view-category').text($(this).data('category'));
                $('#view-location').text($(this).data('location'));
                
                // Get supplier name
                var supplierId = $(this).data('supplier');
                var supplierName = '';
                <?php foreach ($suppliersArr as $supplier): ?>
                    if (supplierId == <?php echo $supplier['id']; ?>) {
                        supplierName = '<?php echo $supplier['name']; ?>';
                    }
                <?php endforeach; ?>
                $('#view-supplier').text(supplierName);
                var storedUnitType = $(this).data('unit_type');
                var autoUnitType = unitUtils.getAutoUnitType($(this).data('name'), $(this).data('category'));
                $('#view-unit-type').text(storedUnitType || autoUnitType);
                $('#view-source').text($(this).data('source'));

                // Build variation dropdown and list
                var priceRaw = $(this).attr('data-variation_prices') || '';
                var stockRaw = $(this).attr('data-variation_stocks') || '';
                var priceMap = {}, stockMap = {};
                if (priceRaw) { try { priceMap = JSON.parse(priceRaw.replace(/&quot;/g, '"')); } catch(e){} }
                if (stockRaw) { try { stockMap = JSON.parse(stockRaw.replace(/&quot;/g, '"')); } catch(e){} }
                var reorder = parseInt($(this).data('reorder'), 10) || 0;
                var $sel = $('#view-variation-select');
                $sel.empty();
                var listHtml = '';
                Object.keys(priceMap).forEach(function(name){
                    var price = parseFloat(priceMap[name] || 0);
                    var stock = parseInt((stockMap[name] || 0), 10);
                    var lowStock = stock <= reorder;
                    var stockClass = stock > 0 ? (lowStock ? 'bg-warning' : 'bg-success') : 'bg-danger';
                    var lowClass = lowStock ? ' text-warning' : '';
                    $sel.append('<option value="'+name.replace(/"/g,'&quot;')+'" data-price="'+price.toFixed(2)+'" data-stock="'+stock+'" class="'+lowClass+'">'+name+' — ₱'+price.toFixed(2)+' (Stock: '+stock+')</option>');
                    listHtml += '<div class="d-flex justify-content-between align-items-center mb-2 p-2 border rounded">' +
                                '<span class="fw-semibold">'+name+'</span>' +
                                '<div class="d-flex align-items-center gap-2">' +
                                '<span class="text-muted">₱'+price.toFixed(2)+'</span>' +
                                '<span class="badge '+stockClass+' text-white">Stock: '+stock+'</span>' +
                                '</div></div>';
                });
                $('#view-variation-list').html(listHtml || '<span class="text-muted">No variations</span>');
                // Set selected info
                var $opt = $sel.find('option').first();
                var selPrice = $opt.length ? $opt.data('price') : '0.00';
                var selStock = $opt.length ? $opt.data('stock') : '0';
                $('#view-selected-price').text(selPrice);
                $('#view-selected-stock').text(selStock);
                $sel.off('change').on('change', function(){
                    var $o = $(this).find('option:selected');
                    $('#view-selected-price').text(($o.data('price')||'0.00'));
                    $('#view-selected-stock').text(($o.data('stock')||'0'));
                });
            });
            
            // Edit Item
            $('.edit-btn').off('click').on('click', function() {
                // Only populate reorder threshold - other fields are preserved
                $('#edit-id').val($(this).data('id'));
                $('#edit-reorder_threshold').val($(this).data('reorder'));
            });

            // Delete Item - Enhanced validation
            $('.delete-btn').click(function() {
                var itemId = $(this).data('id');
                var itemName = $(this).data('name') || $(this).attr('data-name') || '';
                
                // Validate that we have required data
                if (!itemId || itemId <= 0) {
                    alert('Error: Missing item ID. Please refresh the page and try again.');
                    return false;
                }
                
                // If name is missing, try to get it from the row or use a default
                if (!itemName || itemName.trim() === '') {
                    // Try to get name from the table row
                    var $row = $(this).closest('tr');
                    var nameCell = $row.find('td').eq(1).text().trim(); // Second column is usually name
                    itemName = nameCell || 'Item #' + itemId;
                    // Update the data attribute for future use
                    $(this).attr('data-name', itemName);
                }
                
                // Validate that this is a valid inventory page operation
                if (window.location.pathname.indexOf('inventory.php') === -1) {
                    alert('Error: This operation is only allowed on the inventory page.');
                    return false;
                }
                
                // Set the modal data
                $('#delete-id').val(itemId);
                $('#delete-name-input').val(itemName); // Hidden input for validation
                $('#delete-item-name').text(itemName);
            });

            // Also populate delete modal via Bootstrap event for dynamic rows
            $('#deleteInventoryModal').on('show.bs.modal', function (event) {
                var button = $(event.relatedTarget);
                if (!button || !button.hasClass('delete-btn')) return;
                var itemId = parseInt(button.data('id') || button.attr('data-id'), 10);
                var itemName = (button.data('name') || button.attr('data-name') || '').toString().trim();
                
                // If name is missing, try to get it from the row
                if (!itemName || itemName.length === 0) {
                    var $row = button.closest('tr');
                    var nameCell = $row.find('td').eq(1).text().trim(); // Second column is usually name
                    itemName = nameCell || 'Item #' + itemId;
                }
                
                if (!itemId || itemId <= 0) {
                    alert('Error: Missing item ID. Please refresh the page and try again.');
                    event.preventDefault();
                    return;
                }
                $('#delete-id').val(itemId);
                $('#delete-name-input').val(itemName);
                $('#delete-item-name').text(itemName);
            });
            
            // Real-time inventory refresh
            function refreshInventoryTable() {
                // Add timestamp to prevent caching
                var cacheBuster = '?_=' + new Date().getTime();
                $.ajax({
                    url: 'ajax/refresh_inventory.php' + cacheBuster,
                    type: 'GET',
                    dataType: 'json',
                    cache: false,
                    success: function(response) {
                        if (response.success) {
                            // Clear existing table data
                            var table = $('#inventoryTable').DataTable();
                            table.clear();
                            
                            // Add new data
                            response.data.forEach(function(item) {
                                var sourceClass = item.source_type === 'Admin Created' ? 'bg-primary' : 'bg-success';
                                var rowClass = '';
                                var unitBadge = '<span class="badge bg-secondary">' + ( (item.unit_type ? (item.unit_type.charAt(0).toUpperCase() + item.unit_type.slice(1)) : 'Per piece').replace(/\b(\w)/g, function(m){return m.toUpperCase();}) ) + '</span>';
                                var variationsDisplay = '';
                                var vp = item.variation_prices ? JSON.stringify(item.variation_prices).replace(/\"/g, '&quot;') : '';
                                // Ensure we get the latest stock values from variation_stocks
                                var vs = item.variation_stocks ? JSON.stringify(item.variation_stocks).replace(/\"/g, '&quot;') : '{}';
                                // Build dropdown from both prices and stocks to ensure all variations are included
                                var allVariations = new Set();
                                if (item.variation_prices) {
                                    Object.keys(item.variation_prices).forEach(function(k) { allVariations.add(k); });
                                }
                                if (item.variation_stocks) {
                                    Object.keys(item.variation_stocks).forEach(function(k) { allVariations.add(k); });
                                }
                                
                                // Build variations and stocks from completed orders (one entry per order)
                                var variationsDisplay = '';
                                var stockDisplay = '';
                                
                                if (item.completed_orders && Array.isArray(item.completed_orders) && item.completed_orders.length > 0) {
                                    item.completed_orders.forEach(function(order) {
                                        var vName = order.variation || '';
                                        
                                        // Parse variation for display
                                        var displayName = 'No Variation';
                                        var variationDetail = '';
                                        
                                        if (vName) {
                                            // Parse variation to get detailed breakdown
                                            var variationParts = [];
                                            if (vName.indexOf('|') !== -1) {
                                                var parts = vName.split('|');
                                                parts.forEach(function(part) {
                                                    part = part.trim();
                                                    if (part.indexOf(':') !== -1) {
                                                        variationParts.push(part);
                                                    }
                                                });
                                                variationDetail = variationParts.join(' | ');
                                            } else if (vName.indexOf(':') !== -1) {
                                                variationParts.push(vName);
                                                variationDetail = vName;
                                            }
                                            
                                            // Format display name (e.g., "Generic - Large - Standard")
                                            if (vName.indexOf('|') !== -1 || vName.indexOf(':') !== -1) {
                                                var parts = vName.indexOf('|') !== -1 ? vName.split('|') : [vName];
                                                var values = [];
                                                parts.forEach(function(part) {
                                                    part = part.trim();
                                                    if (part.indexOf(':') !== -1) {
                                                        var kv = part.split(':');
                                                        if (kv.length >= 2) {
                                                            values.push(kv[1].trim());
                                                        }
                                                    } else {
                                                        values.push(part);
                                                    }
                                                });
                                                displayName = values.join(' - ');
                                            } else {
                                                displayName = vName;
                                            }
                                        }
                                        
                                        // Build variation display (matching orders.php badge style)
                                        if (vName) {
                                            variationsDisplay += '<div class="mb-3">' +
                                                '<div class="d-flex flex-column">' +
                                                '<span class="badge bg-info mb-1 fs-6">' +
                                                '<i class="bi bi-tag-fill me-1"></i>' + displayName.replace(/</g, '&lt;').replace(/>/g, '&gt;') +
                                                '</span>';
                                            if (variationDetail) {
                                                variationsDisplay += '<small class="text-muted">' + variationDetail.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</small>';
                                            }
                                            variationsDisplay += '</div></div>';
                                        } else {
                                            variationsDisplay += '<div class="mb-3"><span class="badge bg-secondary">No Variation</span></div>';
                                        }
                                        
                                        // Build stock display (matching orders.php badge style)
                                        var orderQuantity = parseInt(order.quantity || 0, 10);
                                        
                                        stockDisplay += '<div class="mb-3">' +
                                            '<div class="d-flex flex-column align-items-start">' +
                                            '<span class="badge bg-primary fs-6 mb-1">' +
                                            '<i class="bi bi-box-seam me-1"></i><strong>' + orderQuantity + '</strong> pcs' +
                                            '</span>' +
                                            '<small class="text-muted">Quantity Ordered</small>' +
                                            '</div>' +
                                            '</div>';
                                    });
                                } else {
                                    variationsDisplay = '<span class="text-muted">—</span>';
                                    stockDisplay = '<span class="text-muted">—</span>';
                                }
                                
                                var vt = item.unit_type ? item.unit_type : 'per piece';
                                var row = [
                                    item.sku,
                                    item.name,
                                    item.category,
                                    item.reorder_threshold,
                                    unitBadge,
                                    variationsDisplay,
                                    stockDisplay,
                                    item.supplier_name || '',
                                    item.location,
                                    '<span class="badge ' + sourceClass + '">' + item.source_type + '</span>',
                                    '<button type="button" class="btn btn-sm btn-info view-btn" data-id="' + item.id + '" data-sku="' + item.sku + '" data-name="' + item.name + '" data-description="' + (item.description || '') + '" data-reorder="' + item.reorder_threshold + '" data-supplier="' + (item.supplier_id || '') + '" data-category="' + item.category + '" data-location="' + item.location + '" data-source="' + item.source_type + '" data-unit_type="' + vt + '" data-variation_prices="' + vp + '" data-variation_stocks="' + vs + '" data-bs-toggle="modal" data-bs-target="#viewInventoryModal"><i class="bi bi-eye"></i></button> ' +
                                    '<button type="button" class="btn btn-sm btn-primary edit-btn" data-id="' + item.id + '" data-sku="' + item.sku + '" data-name="' + item.name + '" data-description="' + (item.description || '') + '" data-reorder="' + item.reorder_threshold + '" data-supplier="' + (item.supplier_id || '') + '" data-category="' + item.category + '" data-location="' + item.location + '" data-unit_type="' + vt + '" data-variation_prices="' + vp + '" data-variation_stocks="' + vs + '" data-bs-toggle="modal" data-bs-target="#editInventoryModal"><i class="bi bi-pencil"></i></button> ' +
                                    '<button type="button" class="btn btn-sm btn-danger delete-btn" data-id="' + item.id + '" data-name="' + (item.name || item.sku || 'Item #' + item.id) + '" data-bs-toggle="modal" data-bs-target="#deleteInventoryModal"><i class="bi bi-trash"></i></button>'
                                ];
                                var addedRow = table.row.add(row).draw(false);
                                if (rowClass) {
                                    $(addedRow.node()).addClass(rowClass);
                                }
                                
                            });
                            
                            // Re-bind event handlers
                            bindEventHandlers();
                        }
                    },
                    error: function() {
                        console.log('Error refreshing inventory data');
                    }
                });
            }

            // Function to bind event handlers to dynamically added elements
            function bindEventHandlers() {
                $('.view-btn').off('click').on('click', function() {
                    $('#view-sku').text($(this).data('sku'));
                    $('#view-name').text($(this).data('name'));
                    $('#view-description').text($(this).data('description'));
                    $('#view-reorder').text($(this).data('reorder'));
                    $('#view-category').text($(this).data('category'));
                    $('#view-location').text($(this).data('location'));
                    
                    var supplierId = $(this).data('supplier');
                    var supplierName = '';
                    <?php foreach ($suppliersArr as $supplier): ?>
                        if (supplierId == <?php echo $supplier['id']; ?>) {
                            supplierName = '<?php echo $supplier['name']; ?>';
                        }
                    <?php endforeach; ?>
                    $('#view-supplier').text(supplierName);

                    // Build variation dropdown and list
                    var priceRaw = $(this).attr('data-variation_prices') || '';
                    var stockRaw = $(this).attr('data-variation_stocks') || '';
                    var priceMap = {}, stockMap = {};
                    if (priceRaw) { try { priceMap = JSON.parse(priceRaw.replace(/&quot;/g, '"')); } catch(e){} }
                    if (stockRaw) { try { stockMap = JSON.parse(stockRaw.replace(/&quot;/g, '"')); } catch(e){} }
                    var reorder = parseInt($(this).data('reorder'), 10) || 0;
                    var $sel = $('#view-variation-select');
                    $sel.empty();
                    var listHtml = '';
                    Object.keys(priceMap).forEach(function(name){
                        var price = parseFloat(priceMap[name] || 0);
                        var stock = parseInt((stockMap[name] || 0), 10);
                        var lowStock = stock <= reorder;
                        var stockClass = stock > 0 ? (lowStock ? 'bg-warning' : 'bg-success') : 'bg-danger';
                        var lowClass = lowStock ? ' text-warning' : '';
                        $sel.append('<option value="'+name.replace(/"/g,'&quot;')+'" data-price="'+price.toFixed(2)+'" data-stock="'+stock+'" class="'+lowClass+'">'+name+' — ₱'+price.toFixed(2)+' (Stock: '+stock+')</option>');
                        listHtml += '<div class="d-flex justify-content-between align-items-center mb-2 p-2 border rounded">' +
                                    '<span class="fw-semibold">'+name+'</span>' +
                                    '<div class="d-flex align-items-center gap-2">' +
                                    '<span class="text-muted">₱'+price.toFixed(2)+'</span>' +
                                    '<span class="badge '+stockClass+' text-white">Stock: '+stock+'</span>' +
                                    '</div></div>';
                    });
                    $('#view-variation-list').html(listHtml || '<span class="text-muted">No variations</span>');
                    var $opt = $sel.find('option').first();
                    var selPrice = $opt.length ? $opt.data('price') : '0.00';
                    var selStock = $opt.length ? $opt.data('stock') : '0';
                    $('#view-selected-price').text(selPrice);
                    $('#view-selected-stock').text(selStock);
                    $sel.off('change').on('change', function(){
                        var $o = $(this).find('option:selected');
                        $('#view-selected-price').text(($o.data('price')||'0.00'));
                        $('#view-selected-stock').text(($o.data('stock')||'0'));
                    });
                });

                $('.edit-btn').off('click').on('click', function() {
                    // Only populate reorder threshold - other fields are preserved
                    $('#edit-id').val($(this).data('id'));
                    $('#edit-reorder_threshold').val($(this).data('reorder'));
                });

                $('.delete-btn').off('click').on('click', function() {
                    var itemId = $(this).data('id');
                    var itemName = $(this).data('name');
                    $('#delete-id').val(itemId);
                    $('#delete-name-input').val(itemName);
                    $('#delete-item-name').text(itemName);
                });
                // Ensure modal population works even for newly injected buttons
                $('#deleteInventoryModal').off('show.bs.modal').on('show.bs.modal', function (event) {
                    var button = $(event.relatedTarget);
                    if (!button || !button.hasClass('delete-btn')) return;
                    var itemId = parseInt(button.data('id'), 10);
                    var itemName = (button.data('name') || '').toString();
                    if (!itemId || !itemName.length) return;
                    $('#delete-id').val(itemId);
                    $('#delete-name-input').val(itemName);
                    $('#delete-item-name').text(itemName);
                });
            }

            // Save Variation Prices & Stock via AJAX
            $('#save-variation-prices-stock-btn').off('click').on('click', function() {
                var invId = parseInt($('#edit-id').val(), 10);
                var $btn = $(this);
                var $feedback = $('#edit-var-price-feedback');
                if (!$feedback.length) {
                    $feedback = $('<div id="edit-var-price-feedback" class="alert mt-2" role="alert" aria-live="polite"></div>');
                    $('#editInventoryForm').find('.modal-body').append($feedback);
                }
                function showFeedback(type, text) {
                    $feedback.removeClass('d-none alert-success alert-danger alert-warning')
                             .addClass(type === 'success' ? 'alert-success' : type === 'warning' ? 'alert-warning' : 'alert-danger')
                             .text(text);
                }
                if (!invId) { showFeedback('danger', 'Invalid inventory item.'); return; }
                var unitType = ($('#edit-unit-type').val() || 'per piece').toString().toLowerCase();
                // Try new structure first (variation price container), fall back to old
                var container = $('#editVariationPriceContainer').length ? $('#editVariationPriceContainer') : $('#edit-variations-container');
                var priceKeys = [], priceVals = [];
                var stockKeys = [], stockVals = [];
                var valid = true, err = '';

                // Clear previous validation states
                container.find('.variation-price, .variation-stock').removeClass('is-invalid').attr('aria-invalid', null);

                // Collect prices and stocks from variation price container (new structure)
                container.find('.var-price, .variation-price').each(function(){
                    var $input = $(this);
                    var key = ($input.data('variant') || $input.data('key') || '').toString();
                    if (!key) return;
                    var raw = ($input.val() || '').toString().trim();
                    if (raw !== '') {
                        var num = parseFloat(raw);
                        if (!isNaN(num) && num >= 0) {
                            priceKeys.push(key);
                            priceVals.push(Number(num.toFixed(2)));
                        }
                    }
                });
                
                // Collect stocks from variation stock inputs
                container.find('.var-stock, .variation-stock').each(function(){
                    var $input = $(this);
                    var key = ($input.data('variant') || $input.data('key') || '').toString();
                    if (!key) return;
                    var raw = ($input.val() || '').toString().trim();
                    if (raw !== '') {
                        var num = parseInt(raw, 10);
                        if (!isNaN(num) && num >= 0) {
                            stockKeys.push(key);
                            stockVals.push(num);
                        }
                    }
                });
                
                // Also check the variation attributes container for selected variations
                $('#editUnitVariationContainer input[type="checkbox"][name^="variation_attrs["]:checked').each(function(){
                    var name = $(this).attr('name') || '';
                    var m = name.match(/^variation_attrs\[(.+)\]\[\]$/);
                    if (!m) return;
                    var attr = m[1];
                    var val = $(this).val();
                    var key = attr + ':' + val;
                    // Check if we already have this key
                    if (priceKeys.indexOf(key) === -1) {
                        // Try to find price/stock inputs for this variation
                        var $priceInput = container.find('[data-key="' + key.replace(/"/g, '&quot;') + '"].var-price, [data-variant="' + key.replace(/"/g, '&quot;') + '"].variation-price');
                        var $stockInput = container.find('[data-key="' + key.replace(/"/g, '&quot;') + '"].var-stock, [data-variant="' + key.replace(/"/g, '&quot;') + '"].variation-stock');
                        if ($priceInput.length && $priceInput.val()) {
                            var p = parseFloat($priceInput.val());
                            if (!isNaN(p) && p >= 0) {
                                priceKeys.push(key);
                                priceVals.push(Number(p.toFixed(2)));
                            }
                        }
                        if ($stockInput.length && $stockInput.val()) {
                            var s = parseInt($stockInput.val(), 10);
                            if (!isNaN(s) && s >= 0) {
                                stockKeys.push(key);
                                stockVals.push(s);
                            }
                        }
                    }
                });

                if (priceKeys.length === 0 && stockKeys.length === 0) { 
                    showFeedback('warning', 'Select variations and enter prices or stock to save.'); 
                    return; 
                }
                
                $btn.prop('disabled', true).text('Saving...');
                $.ajax({
                    url: 'ajax/update_variation_prices_stock.php',
                    type: 'POST',
                    dataType: 'json',
                    data: { 
                        inventory_id: invId, 
                        unit_type: unitType, 
                        variation_price_keys: priceKeys, 
                        variation_price_vals: priceVals,
                        variation_stock_keys: stockKeys,
                        variation_stock_vals: stockVals
                    },
                    success: function(resp) {
                        if (resp && resp.success) {
                            var msg = 'Variation';
                            if (resp.updated.prices && Object.keys(resp.updated.prices).length > 0) msg += ' prices';
                            if (resp.updated.stocks && Object.keys(resp.updated.stocks).length > 0) {
                                if (msg.includes('prices')) msg += ' and';
                                msg += ' stock';
                            }
                            msg += ' updated successfully.';
                            showFeedback('success', msg);
                            
                            // Update the edit modal stock inputs immediately from response
                            if (resp.updated && resp.updated.stocks) {
                                Object.keys(resp.updated.stocks).forEach(function(variation) {
                                    var newStock = resp.updated.stocks[variation];
                                    var $stockInput = $('#editVariationPriceContainer').find('.var-stock[data-key="' + variation.replace(/"/g, '&quot;') + '"]');
                                    if ($stockInput.length) {
                                        $stockInput.val(newStock > 0 ? newStock : '');
                                    }
                                });
                            }
                            
                            // Wait a moment for DB commit, then refresh the table to show updated stock numbers in dropdown
                            setTimeout(function() {
                                refreshInventoryTable();
                            }, 500);
                        } else {
                            showFeedback('danger', 'Update failed: ' + (resp && resp.error ? resp.error : 'Unknown error'));
                        }
                        $btn.prop('disabled', false).text('Save Variation Prices & Stock');
                    },
                    error: function() { 
                        showFeedback('danger', 'Network or server error while updating.'); 
                        $btn.prop('disabled', false).text('Save Variation Prices & Stock');
                    }
                });
            });

            // Refresh inventory every 30 seconds
            // Initial refresh to ensure UI reflects latest variants
            refreshInventoryTable();
            // Refresh after closing edit modal
            $('#editInventoryModal').on('hidden.bs.modal', function(){
                refreshInventoryTable();
            });
            
            setInterval(refreshInventoryTable, 30000);
            
            // Also refresh when page becomes visible (user switches back to tab)
            document.addEventListener('visibilitychange', function() {
                if (!document.hidden) {
                    refreshInventoryTable();
                }
            });
        });
</script>
<script src="../js/unit_types.js"></script>
<script src="../js/form_state.js"></script>
<script src="../js/add_form_validation.js"></script>
<script>
// Initialize dynamic unit type lists and inline add controls
document.addEventListener('DOMContentLoaded', function(){
  try {
    // Add modal unit type select (if present) and Edit modal select
    UnitTypes.populateSelects(['#unit_type', '#edit-unit-type']);
    // Persist form fields for Edit modal across refresh
    FormState.initForSelectors([
      { form: '#editInventoryModal form', key: 'admin_edit_inventory_v1' }
    ]);
  } catch(e) { console.warn('UnitTypes init error', e); }
});
</script>
</body>
</html>


