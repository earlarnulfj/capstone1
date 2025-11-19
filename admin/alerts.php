<?php
// ====== Access control & dependencies ======
include_once '../config/session.php';
require_once '../config/database.php';

// Load all model classes
require_once '../models/inventory.php';
require_once '../models/supplier.php';
require_once '../models/order.php';
require_once '../models/sales_transaction.php';
require_once '../models/alert_log.php';
require_once '../models/inventory_variation.php';
require_once '../models/notification.php';
require_once '../models/stock_calculator.php';
require_once '../models/inventory_sync.php';

// ---- Admin auth guard ----
if (empty($_SESSION['admin']['user_id'])) {
    header("Location: ../login.php");
    exit();
}
if (($_SESSION['admin']['role'] ?? null) !== 'management') {
    header("Location: ../login.php");
    exit();
}

// ---- Instantiate dependencies ----
$db         = (new Database())->getConnection();
$inventory  = new Inventory($db);
$supplier   = new Supplier($db);
$order      = new Order($db);
$sales      = new SalesTransaction($db);
$alert      = new AlertLog($db);
$variation  = new InventoryVariation($db);
$stockCalculator = new StockCalculator($db);
$inventorySync = new InventorySync($db);
$notification = new Notification($db);

// Format variation for display (same as orders.php and admin_pos.php)
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

// Format variation with labels (same as orders.php)
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

// Format variation with aligned columns (EXACTLY matching admin_pos.php dropdown format)
function formatVariationAligned($variation, $attributeNames = [], $attributeWidths = []) {
    if (empty($variation)) return '';
    if (empty($attributeNames) || empty($attributeWidths)) {
        // Fallback to simple format
        return formatVariationForDisplay($variation);
    }
    
    // Parse variation into attribute-value pairs
    $varAttrs = [];
    if (strpos($variation, '|') !== false || strpos($variation, ':') !== false) {
        $parts = explode('|', $variation);
        foreach ($parts as $part) {
            $av = explode(':', trim($part), 2);
            if (count($av) === 2) {
                $varAttrs[trim($av[0])] = trim($av[1]);
            }
        }
    }
    
    // Build aligned columns (matching admin_pos.php exactly)
    $alignedParts = [];
    foreach ($attributeNames as $attrName) {
        $value = isset($varAttrs[$attrName]) ? $varAttrs[$attrName] : '';
        $width = $attributeWidths[$attrName];
        // Use non-breaking spaces for alignment (same as admin_pos.php)
        $padded = htmlspecialchars($value) . str_repeat('&nbsp;', max(0, $width - mb_strlen($value, 'UTF-8')));
        $alignedParts[] = $padded;
    }
    
    return implode(' | ', $alignedParts);
}

// Calculate attribute widths for all variations (matching admin_pos.php logic)
function calculateVariationAttributeWidths($variations) {
    $attributeNames = [];
    $attributeWidths = [];
    
    foreach ($variations as $varKey => $varData) {
        if (!empty($varKey) && (strpos($varKey, '|') !== false || strpos($varKey, ':') !== false)) {
            $parts = explode('|', $varKey);
            foreach ($parts as $part) {
                $av = explode(':', trim($part), 2);
                if (count($av) === 2 && !empty(trim($av[0]))) {
                    $attrName = trim($av[0]);
                    $attrValue = trim($av[1]);
                    
                    if (!in_array($attrName, $attributeNames)) {
                        $attributeNames[] = $attrName;
                        $attributeWidths[$attrName] = mb_strlen($attrName, 'UTF-8');
                    }
                    
                    // Update max width for this attribute
                    $valueWidth = mb_strlen($attrValue, 'UTF-8');
                    if ($valueWidth > $attributeWidths[$attrName] - mb_strlen($attrName, 'UTF-8')) {
                        $attributeWidths[$attrName] = max(mb_strlen($attrName, 'UTF-8'), $valueWidth) + 2;
                    }
                }
            }
        }
    }
    
    sort($attributeNames);
    
    return ['names' => $attributeNames, 'widths' => $attributeWidths];
}

// Process form submission
$message     = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'resolve') {
        // Resolve an alert
        $alertId = intval($_POST['id'] ?? 0);
        if ($alertId > 0 && $alert->updateStatus($alertId, 1)) {
            $message     = "Alert marked as resolved.";
            $messageType = "success";
        } else {
            $message     = "Unable to update alert status.";
            $messageType = "danger";
        }

    } elseif ($action === 'delete_selected') {
        // Delete selected alerts
        $alertIds = isset($_POST['alert_ids']) ? json_decode($_POST['alert_ids'], true) : [];
        
        if (empty($alertIds) || !is_array($alertIds)) {
            $message     = "No alerts selected for deletion.";
            $messageType = "warning";
        } else {
            $deletedCount = 0;
            $failedCount = 0;
            
            foreach ($alertIds as $alertId) {
                $alertId = intval($alertId);
                if ($alertId > 0) {
                    try {
                        $deleteStmt = $db->prepare("DELETE FROM alert_logs WHERE id = :id");
                        if ($deleteStmt->execute([':id' => $alertId])) {
                            $deletedCount++;
                        } else {
                            $failedCount++;
                        }
                    } catch (Exception $e) {
                        error_log("Error deleting alert ID {$alertId}: " . $e->getMessage());
                        $failedCount++;
                    }
                }
            }
            
            if ($deletedCount > 0) {
                $message = "Successfully deleted {$deletedCount} alert(s).";
                if ($failedCount > 0) {
                    $message .= " {$failedCount} alert(s) could not be deleted.";
                    $messageType = "warning";
                } else {
                    $messageType = "success";
                }
            } else {
                $message     = "Unable to delete selected alerts.";
                $messageType = "danger";
            }
        }

    } elseif ($action === 'create_order') {
        // Create an order from an alert
        $inventoryId  = intval($_POST['inventory_id'] ?? 0);
        $supplierId   = intval($_POST['supplier_id'] ?? 0);
        $quantity     = intval($_POST['quantity'] ?? 0);
        $alertId      = intval($_POST['alert_id'] ?? 0);
        $unitType     = isset($_POST['unit_type']) ? trim($_POST['unit_type']) : null;
        $variationSel = isset($_POST['variation']) ? trim($_POST['variation']) : null;
        $unitPrice    = isset($_POST['unit_price']) ? (float)$_POST['unit_price'] : null;

        // Validation
        if ($inventoryId <= 0 || $supplierId <= 0 || $quantity <= 0) {
            $message     = "Invalid order parameters.";
            $messageType = "danger";
        } else {
        // Prepare order
        $order->inventory_id        = $inventoryId;
        $order->supplier_id         = $supplierId;
        $order->user_id             = $_SESSION['admin']['user_id'];
        $order->quantity            = $quantity;
        $order->is_automated        = 0;
        $order->confirmation_status = 'pending';
        $order->unit_type           = $unitType;
        $order->variation           = $variationSel;

        // Resolve unit price if not provided
        if (!$unitPrice && $inventoryId && $variationSel) {
                try { 
                    $unitPrice = $variation->getPrice($inventoryId, $variationSel); 
                } catch (Throwable $e) { 
                    $unitPrice = null; 
                }
            }
            if ($unitPrice && $unitPrice > 0) {
                $order->unit_price = $unitPrice;
        }

        $orderId = $order->create();

        if ($orderId) {
            // Resolve the original alert
                if ($alertId > 0 && $alert->updateStatus($alertId, 1)) {
                $message     = "Order created successfully and alert resolved.";
                $messageType = "success";

                // Fetch item name for notification
                $inventory->id = $inventoryId;
                $inventory->readOne();

                    $decoratedName = $inventory->name ?? 'Item';
                if (!empty($unitType) || !empty($variationSel)) {
                    $parts = [];
                    if (!empty($unitType)) { $parts[] = $unitType; }
                        if (!empty($variationSel)) { $parts[] = formatVariationForDisplay($variationSel); }
                    $decoratedName = $inventory->name . ' (' . implode(' / ', $parts) . ')';
                }

                $notification->createOrderNotification(
                    $orderId,
                    $supplierId,
                    $decoratedName,
                    $quantity
                );
            } else {
                $message     = "Order created but unable to resolve alert.";
                $messageType = "warning";
            }
        } else {
            $message     = "Unable to create order.";
            $messageType = "danger";
            }
        }
    }
}

// ====== Real-time Stock Alert System (based on admin_pos.php logic) ======
// Get products from TWO sources - EXACTLY matching admin_pos.php:
// 1. COMPLETED orders from admin_orders (items appear when order status becomes 'completed')
// 2. ADMIN-CREATED items from inventory_variations (items created directly by admin)
// This ensures stock and variation data align perfectly with admin_pos.php
$alertItems = [];
$alertCategoriesSet = [];

try {
    // Get products from TWO sources - EXACTLY matching admin_pos.php query
    $alertQuery = "SELECT DISTINCT
                        COALESCE(o.inventory_id, i.id) as id,
                        i.sku,
                        COALESCE(i.name, CONCAT('Product #', COALESCE(o.inventory_id, i.id))) as name,
                        i.name as item_name,
                        i.description,
                        i.reorder_threshold,
                        COALESCE(i.category, 'Uncategorized') as category,
                        i.location,
                        COALESCE(o.supplier_id, NULL) as supplier_id,
                        COALESCE(s.name, 'N/A') as supplier_name,
                        COALESCE(i.is_deleted, 0) as is_deleted,
                        CASE WHEN o.id IS NOT NULL THEN 'from_order' ELSE 'admin_created' END as source_type
                 FROM inventory i
                 LEFT JOIN admin_orders o ON o.inventory_id = i.id AND o.confirmation_status = 'completed'
                 LEFT JOIN suppliers s ON o.supplier_id = s.id
                 LEFT JOIN inventory_variations iv ON iv.inventory_id = i.id
                 WHERE i.id IS NOT NULL
                   AND (
                     -- Show items from completed orders in admin_orders (automatically appears when order status becomes 'completed')
                     (o.id IS NOT NULL AND o.confirmation_status = 'completed')
                     OR
                     -- OR show items created directly by admin (have variations with stock, no orders required)
                     (iv.id IS NOT NULL AND iv.quantity > 0 AND o.id IS NULL)
                   )
                   AND COALESCE(i.is_deleted, 0) = 0
                   -- CRITICAL: All alerts are based on admin_orders database - matches admin_pos.php and pos.php exactly
                   -- Items automatically appear in alerts once their order status becomes 'completed' in orders.php
                 ORDER BY COALESCE(i.name, CONCAT('Product #', COALESCE(o.inventory_id, i.id))) ASC";
    
    $stmt = $db->prepare($alertQuery);
    $stmt->execute();
    
    $processedIds = []; // Track processed inventory IDs to avoid duplicates
    $processedItemNames = []; // Track processed item names to prevent duplicates
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (empty($row['id']) || $row['id'] === null) continue;
        
        $invId = (int)$row['id'];
        if (isset($processedIds[$invId])) {
            continue;
        }
        $processedIds[$invId] = true;
        
        // Use item_name (i.name from inventory) - matching admin_pos.php
        if (empty($row['name']) || trim($row['name']) === '') {
            $row['name'] = !empty($row['item_name']) ? trim($row['item_name']) : "Product #" . $row['id'];
        }
        if (empty($row['item_name']) && !empty($row['name'])) {
            $row['item_name'] = $row['name'];
        }
        
        // MONITOR BY ITEM NAME: Prevent duplicates based on item_name
        $itemNameKey = strtolower(trim($row['item_name'] ?? $row['name'] ?? ''));
        if (empty($itemNameKey)) {
            $itemNameKey = 'product_' . $invId;
        }
        
        if (isset($processedItemNames[$itemNameKey])) {
            // Item with same name already exists - variations will be merged later
            continue;
        }
        $processedItemNames[$itemNameKey] = $invId;
        
        if (empty($row['category']) || trim($row['category']) === '') {
            $row['category'] = 'Uncategorized';
        }
        
        unset($row['is_deleted']);
        $alertItems[] = $row;
        $cat = trim(strtolower($row['category'] ?? 'uncategorized'));
        if ($cat === '') $cat = 'uncategorized';
        $alertCategoriesSet[$cat] = true;
    }
} catch (PDOException $e) {
    error_log("Alerts product query error: " . $e->getMessage());
    $alertItems = [];
}

// Load data from TWO sources - EXACTLY matching admin_pos.php:
// 1. COMPLETED orders in admin_orders table (where confirmation_status = 'completed')
// 2. ADMIN-CREATED items from inventory_variations (items with stock, no orders required)
// Stock calculation matches admin_pos.php: Stock = completed orders/variations - sales
foreach ($alertItems as &$item) {
    $item_id = (int)($item['id'] ?? 0);
    $item_name = trim(strtolower($item['item_name'] ?? $item['name'] ?? ''));
    $source_type = $item['source_type'] ?? 'from_order';
    
    if ($item_id > 0) {
        // Initialize variation maps (from admin_orders OR inventory_variations)
        $item['variation_stocks'] = [];
        $item['variation_prices'] = [];
        $item['variation_units'] = [];
        $item['variation_alerts'] = [];
        
        try {
            // Get ALL inventory_ids with same item_name (for merging) - matching admin_pos.php
            $sameNameIds = [];
            if (!empty($item_name)) {
                $nameCheckStmt = $db->prepare("SELECT DISTINCT id 
                                               FROM inventory 
                                               WHERE LOWER(TRIM(COALESCE(name, ''))) = :item_name
                                                 AND COALESCE(is_deleted, 0) = 0");
                $nameCheckStmt->execute([':item_name' => $item_name]);
                while ($nameRow = $nameCheckStmt->fetch(PDO::FETCH_ASSOC)) {
                    $sameNameIds[] = (int)$nameRow['id'];
                }
            }
            if (empty($sameNameIds)) {
                $sameNameIds = [$item_id];
            }
            
            // Check if this item has completed orders or is admin-created
            $hasCompletedOrders = false;
            $orderCheckStmt = $db->prepare("SELECT COUNT(*) as cnt FROM admin_orders 
                                            WHERE inventory_id IN (" . implode(',', array_fill(0, count($sameNameIds), '?')) . ")
                                              AND confirmation_status = 'completed'");
            $orderCheckStmt->execute($sameNameIds);
            $hasCompletedOrders = ((int)$orderCheckStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;
            
            // Get base price - Priority: 1) Completed orders, 2) Admin-created inventory_variations
            if ($hasCompletedOrders) {
                $basePricePlaceholders = implode(',', array_fill(0, count($sameNameIds), '?'));
                $basePriceStmt = $db->prepare("SELECT unit_price, order_date, unit_type
                                               FROM admin_orders 
                                               WHERE inventory_id IN ($basePricePlaceholders)
                                                 AND confirmation_status = 'completed'
                                                 AND unit_price > 0
                                                 AND (variation IS NULL OR variation = '' OR variation = 'null' OR LOWER(TRIM(variation)) = 'null')
                                               ORDER BY order_date DESC, id DESC
                                               LIMIT 1");
                $basePriceStmt->execute($sameNameIds);
                $basePriceRow = $basePriceStmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $basePricePlaceholders = implode(',', array_fill(0, count($sameNameIds), '?'));
                $basePriceStmt = $db->prepare("SELECT unit_price, unit_type
                                               FROM inventory_variations 
                                               WHERE inventory_id IN ($basePricePlaceholders)
                                                 AND unit_price > 0
                                                 AND (variation IS NULL OR variation = '' OR variation = 'null' OR LOWER(TRIM(variation)) = 'null')
                                               ORDER BY last_updated DESC
                                               LIMIT 1");
                $basePriceStmt->execute($sameNameIds);
                $basePriceRow = $basePriceStmt->fetch(PDO::FETCH_ASSOC);
            }
            
            if ($basePriceRow && isset($basePriceRow['unit_price']) && $basePriceRow['unit_price'] > 0) {
                $item['unit_price'] = (float)$basePriceRow['unit_price'];
                if (!empty($basePriceRow['unit_type'])) {
                    $item['unit_type'] = trim($basePriceRow['unit_type']);
                }
            }
            
            // Get variations from TWO sources - EXACTLY matching admin_pos.php
            $allVariations = [];
            
            if ($hasCompletedOrders) {
                // Get variations from COMPLETED admin_orders (priority)
                $placeholders = implode(',', array_fill(0, count($sameNameIds), '?'));
                $allVariationsStmt = $db->prepare("SELECT 
                                                      variation, 
                                                      unit_type, 
                                                      unit_price,
                                                      SUM(quantity) as total_ordered_qty,
                                                      MAX(id) as latest_order_id,
                                                      MAX(order_date) as latest_order_date
                                                    FROM admin_orders 
                                                    WHERE inventory_id IN ($placeholders)
                                                      AND confirmation_status = 'completed'
                                                      AND variation IS NOT NULL
                                                      AND variation != ''
                                                      AND LOWER(TRIM(variation)) != 'null'
                                                    GROUP BY variation
                                                    ORDER BY variation ASC");
                $allVariationsStmt->execute($sameNameIds);
                $allVariations = $allVariationsStmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Get variations from inventory_variations (admin-created items)
                $placeholders = implode(',', array_fill(0, count($sameNameIds), '?'));
                $allVariationsStmt = $db->prepare("SELECT 
                                                      variation, 
                                                      unit_type, 
                                                      unit_price,
                                                      quantity as total_ordered_qty,
                                                      id as latest_order_id,
                                                      last_updated as latest_order_date
                                                    FROM inventory_variations 
                                                    WHERE inventory_id IN ($placeholders)
                                                      AND variation IS NOT NULL
                                                      AND variation != ''
                                                      AND LOWER(TRIM(variation)) != 'null'
                                                      AND quantity > 0
                                                    ORDER BY variation ASC");
                $allVariationsStmt->execute($sameNameIds);
                $allVariations = $allVariationsStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            $threshold = (int)($item['reorder_threshold'] ?? 0);
            
            foreach ($allVariations as $orderVar) {
                $varKey = trim($orderVar['variation'] ?? '');
                if (empty($varKey) || $varKey === 'null') continue;
                
                // Calculate stock: completed orders/variations - sales (EXACTLY matching admin_pos.php)
                $orderedQty = (int)($orderVar['total_ordered_qty'] ?? 0);
                
                // Get sold quantity for this variation (matching admin_pos.php query exactly)
                $soldPlaceholders = implode(',', array_fill(0, count($sameNameIds), '?'));
                $soldStmt = $db->prepare("SELECT SUM(quantity) as total_sold 
                                          FROM sales_transactions 
                                          WHERE inventory_id IN ($soldPlaceholders)
                                            AND variation = ?
                                            AND (variation IS NOT NULL AND variation != '' AND variation != 'null')");
                $soldParams = array_merge($sameNameIds, [$varKey]);
                $soldStmt->execute($soldParams);
                $soldRow = $soldStmt->fetch(PDO::FETCH_ASSOC);
                $soldQty = (int)($soldRow['total_sold'] ?? 0);
                
                // Available stock = completed orders/variations - sales (EXACT calculation from admin_pos.php)
                $varStock = max(0, $orderedQty - $soldQty);
                
                // Merge stock from all inventory_ids with same item_name
                if (isset($item['variation_stocks'][$varKey])) {
                    $item['variation_stocks'][$varKey] += $varStock;
                } else {
                    $item['variation_stocks'][$varKey] = $varStock;
                }
                
                // Get latest unit_type and price for this variation
                if ($hasCompletedOrders) {
                    $latestPlaceholders = implode(',', array_fill(0, count($sameNameIds), '?'));
                    $latestVarStmt = $db->prepare("SELECT unit_type, unit_price 
                                                   FROM admin_orders 
                                                   WHERE inventory_id IN ($latestPlaceholders)
                                                     AND confirmation_status = 'completed'
                                                     AND variation = ?
                                                     AND variation IS NOT NULL
                                                     AND variation != ''
                                                     AND LOWER(TRIM(variation)) != 'null'
                                                   ORDER BY order_date DESC, id DESC 
                                                   LIMIT 1");
                    $latestParams = array_merge($sameNameIds, [$varKey]);
                    $latestVarStmt->execute($latestParams);
                    $latestVarRow = $latestVarStmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $latestPlaceholders = implode(',', array_fill(0, count($sameNameIds), '?'));
                    $latestVarStmt = $db->prepare("SELECT unit_type, unit_price 
                                                   FROM inventory_variations 
                                                   WHERE inventory_id IN ($latestPlaceholders)
                                                     AND variation = ?
                                                     AND variation IS NOT NULL
                                                     AND variation != ''
                                                     AND LOWER(TRIM(variation)) != 'null'
                                                   ORDER BY last_updated DESC 
                                                   LIMIT 1");
                    $latestParams = array_merge($sameNameIds, [$varKey]);
                    $latestVarStmt->execute($latestParams);
                    $latestVarRow = $latestVarStmt->fetch(PDO::FETCH_ASSOC);
                }
                
                // Unit type from admin_orders or inventory_variations
                if ($latestVarRow && isset($latestVarRow['unit_type']) && !empty(trim($latestVarRow['unit_type'] ?? ''))) {
                    $item['variation_units'][$varKey] = trim($latestVarRow['unit_type']);
                } else if (isset($orderVar['unit_type']) && !empty(trim($orderVar['unit_type'] ?? ''))) {
                    $item['variation_units'][$varKey] = trim($orderVar['unit_type']);
                } else {
                    $item['variation_units'][$varKey] = $item['unit_type'] ?? 'per piece';
                }
                
                // Price from admin_orders or inventory_variations
                if ($latestVarRow && isset($latestVarRow['unit_price']) && $latestVarRow['unit_price'] > 0) {
                    $item['variation_prices'][$varKey] = (float)$latestVarRow['unit_price'];
                } else if (isset($orderVar['unit_price']) && $orderVar['unit_price'] > 0) {
                    $item['variation_prices'][$varKey] = (float)$orderVar['unit_price'];
                } else {
                    $item['variation_prices'][$varKey] = (float)($item['unit_price'] ?? 0);
                }
                
                // Get final merged stock for alert checking
                $finalStock = $item['variation_stocks'][$varKey];
                
                // Check if variation needs alert (real-time monitoring)
                if ($finalStock <= $threshold) {
                    $alertType = ($finalStock <= 0) ? 'out_of_stock' : 'low_stock';
                    $item['variation_alerts'][$varKey] = [
                        'type' => $alertType,
                        'stock' => $finalStock,
                        'threshold' => $threshold,
                        'price' => $item['variation_prices'][$varKey],
                        'unit_type' => $item['variation_units'][$varKey],
                        'latest_order_date' => $orderVar['latest_order_date'] ?? null
                    ];
                    
                    // Ensure alert exists in database
                    try {
                        $existingAlertStmt = $db->prepare("SELECT id FROM alert_logs 
                                                             WHERE inventory_id = :inv_id 
                                                               AND alert_type = :alert_type 
                                                               AND (variation = :variation OR (variation IS NULL AND :variation IS NULL))
                                                               AND is_resolved = 0 
                                                             LIMIT 1");
                        $existingAlertStmt->execute([
                            ':inv_id' => $item_id,
                            ':alert_type' => $alertType,
                            ':variation' => $varKey
                        ]);
                        $existingAlert = $existingAlertStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$existingAlert) {
                            $alert->inventory_id = $item_id;
                            $alert->alert_type = $alertType;
                            if (property_exists($alert, 'variation')) {
                                $alert->variation = $varKey;
                            }
                            $alert->is_resolved = 0;
                            $alert->create();
                        }
                    } catch (Exception $e) {
                        error_log("Error creating variation alert: " . $e->getMessage());
                    }
                }
            }
            
            // Base quantity from TWO sources: COMPLETED orders OR inventory_variations (admin-created)
            // Stock = completed orders/variations - sales (matching admin_pos.php)
            try {
                if ($hasCompletedOrders) {
                    $basePlaceholders = implode(',', array_fill(0, count($sameNameIds), '?'));
                    $baseQtyStmt = $db->prepare("SELECT SUM(quantity) as total_qty FROM admin_orders 
                                                  WHERE inventory_id IN ($basePlaceholders)
                                                    AND confirmation_status = 'completed'
                                                    AND (variation IS NULL OR variation = '' OR variation = 'null' OR LOWER(TRIM(variation)) = 'null')");
                    $baseQtyStmt->execute($sameNameIds);
                    $baseQtyRow = $baseQtyStmt->fetch(PDO::FETCH_ASSOC);
                    $orderedQty = (int)($baseQtyRow['total_qty'] ?? 0);
                } else {
                    $basePlaceholders = implode(',', array_fill(0, count($sameNameIds), '?'));
                    $baseQtyStmt = $db->prepare("SELECT SUM(quantity) as total_qty FROM inventory_variations 
                                                  WHERE inventory_id IN ($basePlaceholders)
                                                    AND (variation IS NULL OR variation = '' OR variation = 'null' OR LOWER(TRIM(variation)) = 'null')
                                                    AND quantity > 0");
                    $baseQtyStmt->execute($sameNameIds);
                    $baseQtyRow = $baseQtyStmt->fetch(PDO::FETCH_ASSOC);
                    $orderedQty = (int)($baseQtyRow['total_qty'] ?? 0);
                }
                
                // Get total sold quantity from ALL inventory_ids with same item_name
                $soldBasePlaceholders = implode(',', array_fill(0, count($sameNameIds), '?'));
                $soldQtyStmt = $db->prepare("SELECT SUM(quantity) as total_sold FROM sales_transactions 
                                              WHERE inventory_id IN ($soldBasePlaceholders)
                                                AND (variation IS NULL OR variation = '' OR variation = 'null' OR LOWER(TRIM(variation)) = 'null')");
                $soldQtyStmt->execute($sameNameIds);
                $soldQtyRow = $soldQtyStmt->fetch(PDO::FETCH_ASSOC);
                $soldQty = (int)($soldQtyRow['total_sold'] ?? 0);
                
                // Available stock = completed orders/variations - sales
                $item['base_stock'] = max(0, $orderedQty - $soldQty);
                
                // Check base stock for alerts
                if ($item['base_stock'] <= $threshold) {
                    $alertType = ($item['base_stock'] <= 0) ? 'out_of_stock' : 'low_stock';
                    try {
                        $existingBaseStmt = $db->prepare("SELECT id FROM alert_logs 
                                                           WHERE inventory_id = :inv_id 
                                                             AND alert_type = :alert_type 
                                                             AND (variation IS NULL OR variation = '' OR variation = 'null')
                                                             AND is_resolved = 0 
                                                           LIMIT 1");
                        $existingBaseStmt->execute([
                            ':inv_id' => $item_id,
                            ':alert_type' => $alertType
                        ]);
                        $existingBase = $existingBaseStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$existingBase) {
                            $alert->inventory_id = $item_id;
                            $alert->alert_type = $alertType;
                            if (property_exists($alert, 'variation')) {
                                $alert->variation = null;
                            }
                            $alert->is_resolved = 0;
                            $alert->create();
                        }
                    } catch (Exception $e) {
                        error_log("Error creating base stock alert: " . $e->getMessage());
                    }
                }
            } catch (PDOException $e) {
                $item['base_stock'] = 0;
            }
        } catch (PDOException $e) {
            error_log("Alerts: Could not load data from admin_orders/inventory_variations for inventory ID {$item_id}: " . $e->getMessage());
        }
    }
}
unset($item);

// Build comprehensive alert list with variation-specific data
// Calculate attribute widths for aligned display (matching admin_pos.php dropdown format)
$comprehensiveAlerts = [];
foreach ($alertItems as $item) {
    $item_id = (int)($item['id'] ?? 0);
    $threshold = (int)($item['reorder_threshold'] ?? 0);
    
    // Calculate attribute widths for this item's variations (for aligned display)
    $variationStocks = $item['variation_stocks'] ?? [];
    $attributeData = null;
    if (!empty($variationStocks)) {
        // Build variations array in the format expected by calculateVariationAttributeWidths
        $variationsForWidth = [];
        foreach ($variationStocks as $varKey => $stock) {
            $variationsForWidth[$varKey] = ['stock' => $stock];
        }
        $attributeData = calculateVariationAttributeWidths($variationsForWidth);
    }
    
    // Check base stock alerts - ONLY if product has NO variations (matching admin_pos.php dropdown)
    // In admin_pos.php, if there are variations, the base stock is not shown in the dropdown
    $hasVariationsInDropdown = !empty($variationStocks);
    if (!$hasVariationsInDropdown && isset($item['base_stock']) && $item['base_stock'] <= $threshold) {
        $comprehensiveAlerts[] = [
            'inventory_id' => $item_id,
            'item_name' => $item['name'] ?? 'Product #' . $item_id,
            'variation' => null,
            'variation_display' => 'Base Stock',
            'variation_aligned' => 'Base Stock',
            'variation_labels' => 'Base Stock',
            'stock' => $item['base_stock'],
            'threshold' => $threshold,
            'price' => (float)($item['unit_price'] ?? 0),
            'unit_type' => $item['unit_type'] ?? 'per piece',
            'alert_type' => ($item['base_stock'] <= 0) ? 'out_of_stock' : 'low_stock',
            'category' => $item['category'] ?? 'Uncategorized',
            'supplier_id' => (int)($item['supplier_id'] ?? 0),
            'supplier_name' => $item['supplier_name'] ?? 'N/A',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    // Check variation-specific alerts
    if (isset($item['variation_alerts']) && is_array($item['variation_alerts'])) {
        foreach ($item['variation_alerts'] as $varKey => $varAlert) {
            // Format variation with aligned columns (matching admin_pos.php dropdown)
            $alignedDisplay = '';
            if ($attributeData && !empty($attributeData['names']) && !empty($attributeData['widths'])) {
                $alignedDisplay = formatVariationAligned($varKey, $attributeData['names'], $attributeData['widths']);
            } else {
                $alignedDisplay = formatVariationForDisplay($varKey);
            }
            
            $comprehensiveAlerts[] = [
                'inventory_id' => $item_id,
                'item_name' => $item['name'] ?? 'Product #' . $item_id,
                'variation' => $varKey,
                'variation_display' => formatVariationForDisplay($varKey),
                'variation_aligned' => $alignedDisplay, // Aligned format matching admin_pos.php
                'variation_labels' => formatVariationWithLabels($varKey),
                'stock' => $varAlert['stock'],
                'threshold' => $varAlert['threshold'],
                'price' => (float)($varAlert['price'] ?? 0),
                'unit_type' => $varAlert['unit_type'] ?? 'per piece',
                'alert_type' => $varAlert['type'],
                'category' => $item['category'] ?? 'Uncategorized',
                'supplier_id' => (int)($item['supplier_id'] ?? 0),
                'supplier_name' => $item['supplier_name'] ?? 'N/A',
                'timestamp' => $varAlert['latest_order_date'] ?? date('Y-m-d H:i:s'),
                'attribute_names' => $attributeData['names'] ?? [], // For reference
                'attribute_widths' => $attributeData['widths'] ?? [] // For reference
            ];
        }
    }
}

// Get alert timestamps from database
$alertTimestampsMap = [];
try {
    $timestampStmt = $db->prepare("SELECT inventory_id, variation, alert_type, MAX(alert_date) as latest_alert_date 
                                    FROM alert_logs 
                                    WHERE is_resolved = 0 
                                    GROUP BY inventory_id, variation, alert_type");
    $timestampStmt->execute();
    while ($tsRow = $timestampStmt->fetch(PDO::FETCH_ASSOC)) {
        $key = (int)$tsRow['inventory_id'] . '_' . ($tsRow['variation'] ?? 'base');
        $alertTimestampsMap[$key] = $tsRow['latest_alert_date'];
    }
    
    // Update timestamps in comprehensive alerts
    foreach ($comprehensiveAlerts as &$alertItem) {
        $key = $alertItem['inventory_id'] . '_' . ($alertItem['variation'] ?? 'base');
        if (isset($alertTimestampsMap[$key])) {
            $alertItem['timestamp'] = $alertTimestampsMap[$key];
        }
    }
} catch (Exception $e) {
    error_log("Error fetching alert timestamps: " . $e->getMessage());
}
unset($alertItem);

// Sorting and filtering parameters
$sortBy = $_GET['sort'] ?? 'timestamp';
$sortOrder = strtoupper($_GET['order'] ?? 'DESC');
$filterType = $_GET['filter'] ?? 'all';
$filterCategory = $_GET['category'] ?? 'all';
$searchTerm = trim($_GET['search'] ?? '');

// Apply filters
$filteredAlerts = $comprehensiveAlerts;

if ($filterType !== 'all') {
    $filteredAlerts = array_filter($filteredAlerts, function($a) use ($filterType) {
        return $a['alert_type'] === $filterType;
    });
}

if ($filterCategory !== 'all') {
    $filteredAlerts = array_filter($filteredAlerts, function($a) use ($filterCategory) {
        return strtolower($a['category']) === strtolower($filterCategory);
    });
}

if (!empty($searchTerm)) {
    $searchTermLower = strtolower($searchTerm);
    $filteredAlerts = array_filter($filteredAlerts, function($a) use ($searchTermLower) {
        return strpos(strtolower($a['item_name']), $searchTermLower) !== false ||
               strpos(strtolower($a['variation_display'] ?? ''), $searchTermLower) !== false;
    });
}

// Apply sorting
usort($filteredAlerts, function($a, $b) use ($sortBy, $sortOrder) {
    $result = 0;
    switch ($sortBy) {
        case 'name':
            $result = strcasecmp($a['item_name'], $b['item_name']);
            break;
        case 'stock':
            $result = $a['stock'] - $b['stock'];
            break;
        case 'price':
            $result = ($a['price'] ?? 0) - ($b['price'] ?? 0);
            break;
        case 'timestamp':
        default:
            $result = strtotime($a['timestamp'] ?? '1970-01-01') - strtotime($b['timestamp'] ?? '1970-01-01');
            break;
    }
    return $sortOrder === 'ASC' ? $result : -$result;
});

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 25;
$totalAlerts = count($filteredAlerts);
$totalPages = ceil($totalAlerts / $perPage);
$offset = ($page - 1) * $perPage;
$paginatedAlerts = array_slice($filteredAlerts, $offset, $perPage);

// Store Active Stock Alerts count in session for sidebar badge (this is the count for Active Stock Alerts)
$_SESSION['active_stock_alerts_count'] = count($comprehensiveAlerts);

// Build categories list for filter
$categories = [];
foreach ($alertCategoriesSet as $cat => $_) {
    $label = ucfirst($cat);
    foreach ($alertItems as $it) {
        if (isset($it['category']) && strtolower($it['category']) === $cat) {
            $label = $it['category'];
            break;
        }
    }
    $categories[] = ['key' => $cat, 'label' => $label];
}
usort($categories, fn($a,$b) => strcasecmp($a['label'],$b['label']));

// Get all alerts from database for history table (fetch all rows first)
$allAlertsStmt = $alert->readAll();
$allAlertsRows = $allAlertsStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Alerts - Inventory & Stock Control System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        /* Alerts-specific styles matching admin_pos.php */
        .alert-card {
            border-left: 4px solid;
            transition: all 0.2s ease;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        .alert-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        .alert-card.out-of-stock {
            border-left-color: #dc3545;
            background-color: rgba(220, 53, 69, 0.05);
        }
        .alert-card.low-stock {
            border-left-color: #ffc107;
            background-color: rgba(255, 193, 7, 0.05);
        }
        .variation-display {
            font-size: 0.875rem;
            background-color: #f8f9fa;
            padding: 0.5rem;
            border-radius: 0.25rem;
            margin-top: 0.5rem;
            word-wrap: break-word;
            overflow-wrap: break-word;
            max-width: 100%;
            overflow-x: auto;
        }
        .variation-header {
            font-size: 0.8rem;
            font-weight: bold;
            color: #495057;
            background-color: #e9ecef;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            margin-bottom: 0.25rem;
            word-wrap: break-word;
            overflow-wrap: break-word;
            max-width: 100%;
        }
        .variation-content {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
        }
        .variation-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            background-color: #e7f3ff;
            color: #0066cc;
            border: 1px solid #b3d9ff;
            white-space: nowrap;
        }
        .stock-indicator {
            font-weight: 600;
            font-size: 1.1rem;
        }
        .stock-indicator.out-of-stock {
            color: #dc3545;
        }
        .stock-indicator.low-stock {
            color: #ffc107;
        }
        .stock-indicator.in-stock {
            color: #28a745;
        }
        .filter-container {
            background: white;
            border-radius: 0.35rem;
            padding: 1rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            margin-bottom: 1.5rem;
        }
        .price-display {
            font-weight: 600;
            color: #2470dc;
        }
        .timestamp-display {
            font-size: 0.875rem;
            color: #6c757d;
        }
        .pagination-info {
            font-size: 0.875rem;
            color: #6c757d;
        }
        .alert-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
    </style>
</head>
<body>
    <?php include_once 'includes/header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include_once 'includes/sidebar.php'; ?>
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2 d-flex align-items-center">
                        <i class="bi bi-bell me-2"></i>Stock Alerts
                        <?php if (count($comprehensiveAlerts) > 0): ?>
                            <span class="badge bg-danger ms-2" id="headerAlertBadge"><?= count($comprehensiveAlerts) ?></span>
                        <?php endif; ?>
                </h1>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Filters and Search -->
                <div class="filter-container">
                    <form method="GET" action="" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">
                                <i class="bi bi-funnel me-1"></i>Alert Type
                            </label>
                            <select name="filter" class="form-select form-select-sm">
                                <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>All Alerts</option>
                                <option value="out_of_stock" <?= $filterType === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
                                <option value="low_stock" <?= $filterType === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">
                                <i class="bi bi-tag me-1"></i>Category
                            </label>
                            <select name="category" class="form-select form-select-sm">
                                <option value="all" <?= $filterCategory === 'all' ? 'selected' : '' ?>>All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat['key']) ?>" <?= $filterCategory === $cat['key'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">
                                <i class="bi bi-search me-1"></i>Search
                            </label>
                            <input type="text" name="search" class="form-control form-control-sm" 
                                   value="<?= htmlspecialchars($searchTerm) ?>" placeholder="Product name...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">
                                <i class="bi bi-sort-alpha-down me-1"></i>Sort By
                            </label>
                            <select name="sort" class="form-select form-select-sm">
                                <option value="timestamp" <?= $sortBy === 'timestamp' ? 'selected' : '' ?>>Date</option>
                                <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>Name</option>
                                <option value="stock" <?= $sortBy === 'stock' ? 'selected' : '' ?>>Stock Level</option>
                                <option value="price" <?= $sortBy === 'price' ? 'selected' : '' ?>>Price</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-filter me-1"></i>Apply Filters
                            </button>
                            <a href="alerts.php" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-x-circle me-1"></i>Clear
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Real-time Stock Alerts -->
                <div class="card mb-4">
                    <div class="card-header" style="background-color: #dc3545; color: #ffffff; padding: 1rem 1.25rem; border-bottom: 2px solid #c82333;">
                        <h5 class="mb-0" style="color: #ffffff; font-weight: 600;">
                            <i class="bi bi-exclamation-triangle-fill me-2" style="color: #ffffff;"></i>Active Stock Alerts
                            <?php if (count($filteredAlerts) > 0): ?>
                                <span class="badge bg-light text-dark ms-2" id="cardAlertBadge" style="color: #212529 !important;"><?= count($filteredAlerts) ?></span>
                            <?php endif; ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($paginatedAlerts)): ?>
                            <div class="alert alert-info border border-info mb-0 p-4 text-center" style="background-color: #d1ecf1;">
                                <i class="bi bi-check-circle-fill me-2 fs-4" style="color: #0c5460;"></i>
                                <strong class="fs-5" style="color: #0c5460;">No active alerts. All stock levels are above threshold.</strong>
                            </div>
                        <?php else: ?>
                        <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="min-width: 150px;">Product</th>
                                            <th style="min-width: 200px; max-width: 300px;">Variation</th>
                                            <th style="min-width: 100px;">Current Stock</th>
                                            <th style="min-width: 100px;">Threshold</th>
                                            <th style="min-width: 120px;">Per-Unit Price</th>
                                            <th style="min-width: 100px;">Alert Type</th>
                                            <th style="min-width: 130px;">Triggered</th>
                                            <th style="min-width: 100px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                        <?php foreach ($paginatedAlerts as $alertItem): 
                                            $alertClass = $alertItem['alert_type'] === 'out_of_stock' ? 'out-of-stock' : 'low-stock';
                                            $stockClass = $alertItem['alert_type'] === 'out_of_stock' ? 'out-of-stock' : ($alertItem['alert_type'] === 'low_stock' ? 'low-stock' : 'in-stock');
                                        ?>
                                        <tr class="alert-card <?= $alertClass ?>">
                                            <td>
                                                <strong><?= htmlspecialchars($alertItem['item_name']) ?></strong>
                                                <br><small class="text-muted">
                                                    <i class="bi bi-tag me-1"></i><?= htmlspecialchars($alertItem['category']) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php if ($alertItem['variation']): ?>
                                                    <div class="variation-display">
                                                        <?php 
                                                        // Parse variation into attribute-value pairs for better display
                                                        $variationKey = $alertItem['variation'] ?? '';
                                                        $parsedVariation = [];
                                                        
                                                        if (!empty($variationKey) && (strpos($variationKey, '|') !== false || strpos($variationKey, ':') !== false)) {
                                                            $parts = explode('|', $variationKey);
                                                            foreach ($parts as $part) {
                                                                $av = explode(':', trim($part), 2);
                                                                if (count($av) === 2) {
                                                                    $parsedVariation[trim($av[0])] = trim($av[1]);
                                                                } else {
                                                                    $parsedVariation[] = trim($part);
                                                                }
                                                            }
                                                        }
                                                        
                                                        if (!empty($parsedVariation)):
                                                        ?>
                                                            <div class="variation-content">
                                                                <?php foreach ($parsedVariation as $attr => $value): ?>
                                                                    <?php if (is_numeric($attr)): ?>
                                                                        <span class="variation-badge"><?= htmlspecialchars($value) ?></span>
                                                                    <?php else: ?>
                                                                        <span class="variation-badge">
                                                                            <strong><?= htmlspecialchars($attr) ?>:</strong> <?= htmlspecialchars($value) ?>
                                                                        </span>
                                                                    <?php endif; ?>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="variation-badge"><?= htmlspecialchars($alertItem['variation_display'] ?? $variationKey) ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                            <?php else: ?>
                                                    <span class="text-muted">Base Stock</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                                <!-- Current stock calculated as orders - sales (matching admin_pos.php exactly) -->
                                                <span class="stock-indicator <?= $stockClass ?>">
                                                    <?= number_format($alertItem['stock'], 0) ?> pcs
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?= number_format($alertItem['threshold'], 0) ?></span>
                                            </td>
                                            <td>
                                                <span class="price-display">₱<?= number_format($alertItem['price'], 2) ?></span>
                                                <br><small class="text-muted">per <?= htmlspecialchars($alertItem['unit_type']) ?></small>
                                            </td>
                                            <td>
                                                <?php if ($alertItem['alert_type'] === 'out_of_stock'): ?>
                                                    <span class="badge bg-danger alert-badge">
                                                        <i class="bi bi-x-circle me-1"></i>Out of Stock
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark alert-badge">
                                                        <i class="bi bi-exclamation-triangle me-1"></i>Low Stock
                                                    </span>
                                            <?php endif; ?>
                                        </td>
                                            <td>
                                                <span class="timestamp-display">
                                                    <i class="bi bi-clock me-1"></i><?= date('M d, Y H:i', strtotime($alertItem['timestamp'])) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <a class="btn btn-outline-primary" 
                                                       href="supplier_details.php?supplier_id=<?= $alertItem['supplier_id'] ?>&from_alert=1&inventory_id=<?= $alertItem['inventory_id'] ?>&variation=<?= urlencode($alertItem['variation'] ?? '') ?>"
                                                       title="Create Order">
                                                        <i class="bi bi-cart-plus"></i>
                                                    </a>
                                                    <button class="btn btn-success resolve-alert-btn"
                                                            data-inventory-id="<?= $alertItem['inventory_id'] ?>"
                                                            data-variation="<?= htmlspecialchars($alertItem['variation'] ?? '') ?>"
                                                            data-item="<?= htmlspecialchars($alertItem['item_name']) ?>"
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#resolveAlertModal"
                                                            title="Resolve Alert">
                                                        <i class="bi bi-check-circle"></i>
                                            </button>
                                                </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                            
                            <!-- Pagination -->
                            <?php if ($totalPages > 1): ?>
                                <nav aria-label="Alerts pagination">
                                    <ul class="pagination justify-content-center mt-3">
                                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])) ?>">Previous</a>
                                        </li>
                                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => min($totalPages, $page + 1)])) ?>">Next</a>
                                        </li>
                                    </ul>
                                    <div class="pagination-info text-center mt-2">
                                        Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalAlerts) ?> of <?= $totalAlerts ?> alerts
                            </div>
                                </nav>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- All Alerts History -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-clock-history me-2"></i>Alert History
                        </h5>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-danger" id="deleteSelectedBtn" style="display: none;">
                                <i class="bi bi-trash me-1"></i>Delete Selected (<span id="selectedCount">0</span>)
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="alertsHistoryTable" class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>
                                            <input type="checkbox" id="selectAllCheckbox" class="form-check-input">
                                        </th>
                                        <th>ID</th>
                                        <th>Product</th>
                                        <th>Variation</th>
                                        <th>Type</th>
                                        <th>Per-Unit Price</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $historyCount = 0;
                                    foreach ($allAlertsRows as $row): 
                                        $historyCount++;
                                        
                                        // Get inventory details
                                        $invId = (int)($row['inventory_id'] ?? 0);
                                        $itemName = 'Product #' . $invId;
                                        $unitPrice = 0;
                                        
                                        try {
                                            $inventory->id = $invId;
                                            if ($inventory->readOne()) {
                                                $itemName = $inventory->name ?? $itemName;
                                                $unitPrice = (float)($inventory->unit_price ?? 0);
                                            }
                                        } catch (Exception $e) {
                                            error_log("Error loading inventory for alert: " . $e->getMessage());
                                        }
                                        
                                        // Get variation price if variation exists - from admin_orders (completed) or inventory_variations
                                        $variationKey = $row['variation'] ?? null;
                                        if ($variationKey && $invId > 0) {
                                            try {
                                                // Try admin_orders first (completed orders)
                                                $varPriceStmt = $db->prepare("SELECT unit_price FROM admin_orders 
                                                                              WHERE inventory_id = :inv_id 
                                                                                AND confirmation_status = 'completed'
                                                                                AND variation = :variation 
                                                                              ORDER BY order_date DESC LIMIT 1");
                                                $varPriceStmt->execute([':inv_id' => $invId, ':variation' => $variationKey]);
                                                $varPriceRow = $varPriceStmt->fetch(PDO::FETCH_ASSOC);
                                                if ($varPriceRow && $varPriceRow['unit_price'] > 0) {
                                                    $unitPrice = (float)$varPriceRow['unit_price'];
                                                } else {
                                                    // Fallback to inventory_variations (admin-created)
                                                    $varPriceStmt2 = $db->prepare("SELECT unit_price FROM inventory_variations 
                                                                                  WHERE inventory_id = :inv_id 
                                                                                    AND variation = :variation 
                                                                                  ORDER BY last_updated DESC LIMIT 1");
                                                    $varPriceStmt2->execute([':inv_id' => $invId, ':variation' => $variationKey]);
                                                    $varPriceRow2 = $varPriceStmt2->fetch(PDO::FETCH_ASSOC);
                                                    if ($varPriceRow2 && $varPriceRow2['unit_price'] > 0) {
                                                        $unitPrice = (float)$varPriceRow2['unit_price'];
                                                    }
                                                }
                                            } catch (Exception $e) {
                                                // Use base price if variation price not found
                                            }
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="form-check-input alert-select-checkbox" value="<?= $row['id'] ?>" data-alert-id="<?= $row['id'] ?>">
                                        </td>
                                        <td><?= $row['id'] ?></td>
                                        <td><?= htmlspecialchars($itemName) ?></td>
                                        <td>
                                            <?php if ($variationKey): ?>
                                                <span class="badge bg-info"><?= htmlspecialchars(formatVariationForDisplay($variationKey)) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">Base Stock</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($row['alert_type'] === 'low_stock'): ?>
                                                <span class="badge bg-warning">Low Stock</span>
                                            <?php elseif ($row['alert_type'] === 'out_of_stock'): ?>
                                                <span class="badge bg-danger">Out of Stock</span>
                                            <?php else: ?>
                                                <span class="badge bg-info">Reorder</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="price-display">₱<?= number_format($unitPrice, 2) ?></span>
                                        </td>
                                        <td>
                                            <span class="timestamp-display">
                                                <?= date('M d, Y H:i', strtotime($row['alert_date'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($row['is_resolved']): ?>
                                                <span class="badge bg-success">Resolved</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Unresolved</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php 
                                    endforeach;
                                    if ($historyCount === 0): 
                                    ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">No alert history found.</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

    <!-- Delete Selected Alerts Modal -->
    <div class="modal fade" id="deleteSelectedModal" tabindex="-1" aria-labelledby="deleteSelectedModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="deleteSelectedModalLabel">
                        <i class="bi bi-exclamation-triangle me-2"></i>Delete Selected Alerts
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="deleteSelectedForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete_selected">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="alert_ids" id="delete-alert-ids">
                        <p>Are you sure you want to delete <strong id="delete-count">0</strong> selected alert(s)?</p>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>Warning:</strong> This action cannot be undone. The selected alerts will be permanently deleted from the history.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash me-1"></i>Delete Selected
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
            </main>
        </div>
    </div>

    <!-- Resolve Alert Modal -->
    <div class="modal fade" id="resolveAlertModal" tabindex="-1" aria-labelledby="resolveAlertModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="resolveAlertModalLabel">Resolve Alert</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="resolve">
                        <input type="hidden" name="id" id="resolve-id">
                        <input type="hidden" name="inventory_id" id="resolve-inventory-id">
                        <input type="hidden" name="variation" id="resolve-variation">
                        <p>Mark alert for <strong id="resolve-item"></strong> as resolved?</p>
                        <?php 
                        // Find and resolve all matching alerts
                        ?>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Resolve</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Create Order Modal -->
    <div class="modal fade" id="createOrderModal" tabindex="-1" aria-labelledby="createOrderModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="createOrderModalLabel">Create Order from Alert</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create_order">
                        <input type="hidden" name="alert_id" id="order-alert-id">
                        <input type="hidden" name="inventory_id" id="order-inventory-id">

                        <p>Create order for <strong id="order-item"></strong>.</p>

                        <div class="mb-3">
                            <label class="form-label">Supplier</label>
                            <select name="supplier_id" class="form-select" required>
                                <option value="">Select Supplier</option>
                                <?php 
                                try {
                                    foreach ($supplier->getActiveSuppliers()->fetchAll(PDO::FETCH_ASSOC) as $sup): 
                                ?>
                                    <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?></option>
                                <?php 
                                    endforeach;
                                } catch (Exception $e) {
                                    error_log("Error loading suppliers: " . $e->getMessage());
                                }
                                ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="order-variation">Variation</label>
                            <select id="order-variation" name="variation" class="form-select">
                                <option value="">Base Stock (No Variation)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="order-unit-type">Unit Type</label>
                            <input type="text" id="order-unit-type" name="unit_type" class="form-control" placeholder="Auto-filled from variation" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Unit Price</label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="text" id="order-unit-price-display" class="form-control" placeholder="Auto-filled" readonly>
                            </div>
                            <input type="hidden" id="order-unit-price" name="unit_price">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Quantity</label>
                            <input type="number" name="quantity" id="quantity" class="form-control" min="1" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Order</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(function() {
            // Remove any existing badges on page load if there are no alerts
            const initialAlertCount = <?= count($comprehensiveAlerts) ?>;
            if (initialAlertCount === 0) {
                // Remove the badge by ID
                $('#headerAlertBadge').remove();
                // Also remove any badge with class 'badge bg-danger' that might be incorrectly placed
                $('h1.h2').find('.badge.bg-danger').remove();
            }
            
            // Initialize DataTable for alert history (exclude checkbox column from sorting)
            const historyTable = $('#alertsHistoryTable').DataTable({
                responsive: true,
                order: [[1, 'desc']], // Sort by ID column (column index 1)
                pageLength: 25,
                columnDefs: [
                    { orderable: false, targets: 0 } // Make checkbox column non-sortable
                ],
                language: {
                    search: "Search history:",
                    lengthMenu: "Show _MENU_ alerts per page"
                }
            });
            
            // Multi-select functionality
            let selectedAlerts = new Set();
            
            // Select All checkbox
            $('#selectAllCheckbox').on('change', function() {
                const isChecked = $(this).prop('checked');
                $('.alert-select-checkbox').prop('checked', isChecked);
                
                if (isChecked) {
                    $('.alert-select-checkbox').each(function() {
                        selectedAlerts.add($(this).val());
                    });
                } else {
                    selectedAlerts.clear();
                }
                updateDeleteButton();
            });
            
            // Individual checkbox change
            $(document).on('change', '.alert-select-checkbox', function() {
                const alertId = $(this).val();
                if ($(this).prop('checked')) {
                    selectedAlerts.add(alertId);
                } else {
                    selectedAlerts.delete(alertId);
                    $('#selectAllCheckbox').prop('checked', false);
                }
                updateDeleteButton();
            });
            
            // Update delete button visibility and count
            function updateDeleteButton() {
                const count = selectedAlerts.size;
                $('#selectedCount').text(count);
                if (count > 0) {
                    $('#deleteSelectedBtn').show();
                } else {
                    $('#deleteSelectedBtn').hide();
                }
            }
            
            // Delete selected button click
            $('#deleteSelectedBtn').on('click', function() {
                const selectedArray = Array.from(selectedAlerts);
                if (selectedArray.length === 0) {
                    alert('Please select at least one alert to delete.');
                    return;
                }
                
                $('#delete-alert-ids').val(JSON.stringify(selectedArray));
                $('#delete-count').text(selectedArray.length);
                const deleteModal = new bootstrap.Modal(document.getElementById('deleteSelectedModal'));
                deleteModal.show();
            });
            
            // Handle form submission for delete
            $('#deleteSelectedForm').on('submit', function(e) {
                // Form will submit normally via POST
                // The page will reload and show success message
            });
            
            // Update select all when DataTable redraws
            historyTable.on('draw', function() {
                $('#selectAllCheckbox').prop('checked', false);
                // Update selected checkboxes based on selectedAlerts Set
                $('.alert-select-checkbox').each(function() {
                    $(this).prop('checked', selectedAlerts.has($(this).val()));
                });
                updateDeleteButton();
            });

            // Resolve alert button handler
            $('.resolve-alert-btn').click(function() {
                const invId = $(this).data('inventory-id');
                const variation = $(this).data('variation') || '';
                const itemName = $(this).data('item');
                
                $('#resolve-inventory-id').val(invId);
                $('#resolve-variation').val(variation);
                $('#resolve-item').text(itemName + (variation ? ' (' + variation + ')' : ''));
                
                // Find matching alert IDs
                $.ajax({
                    url: 'ajax/get_alert_ids.php',
                    method: 'GET',
                    data: {
                        inventory_id: invId,
                        variation: variation
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success && response.alert_ids.length > 0) {
                            // For now, resolve the first matching alert
                            $('#resolve-id').val(response.alert_ids[0]);
                        }
                    },
                    error: function() {
                        console.error('Error fetching alert IDs');
                    }
                });
            });

            // Real-time alert updates (every 30 seconds)
            let alertUpdateInterval = setInterval(function() {
                updateAlertBadges();
            }, 30000);

            function updateAlertBadges() {
                $.ajax({
                    url: 'ajax/get_alert_counts.php',
                    method: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            const count = parseInt(response.active_stock_alerts || response.total_alerts || 0);
                            
                            // Update header badge
                            $('h1.h2').find('.badge.bg-danger').remove();
                            if (count > 0) {
                                $('h1.h2').append('<span class="badge bg-danger ms-2" id="headerAlertBadge">' + count + '</span>');
                            }
                            
                            // Update sidebar badge
                            const $sidebarBadge = $('#sidebarAlertBadge');
                            if (count > 0) {
                                if ($sidebarBadge.length === 0) {
                                    $('a[href="alerts.php"]').append('<span class="badge bg-danger ms-1" id="sidebarAlertBadge">' + count + '</span>');
                                } else {
                                    $sidebarBadge.text(count).show();
                                }
                            } else {
                                $sidebarBadge.hide();
                            }
                            
                            // Optionally reload if count changed significantly
                            if (Math.abs(count - <?= count($comprehensiveAlerts) ?>) > 5) {
                                // Reload page if major change (this will also update card badge with filtered count)
                                location.reload();
                            }
                        }
                    },
                    error: function() {
                        console.error('Error updating alert counts');
                    }
                });
            }

            // Populate order variations when modal opens
            $('#createOrderModal').on('show.bs.modal', function(event) {
                const button = $(event.relatedTarget);
                const invId = button.data('inventory-id') || button.closest('tr').find('[data-inventory-id]').data('inventory-id');
                const variation = button.data('variation') || '';
                const itemName = button.data('item') || button.closest('tr').find('strong').first().text();
                
                $('#order-inventory-id').val(invId);
                $('#order-item').text(itemName);
                
                // Load variations for this inventory item
                populateOrderVariations(invId);
            });

            // Variation data map (injected from PHP)
            const alertsVariationDataMap = <?php 
                $varMapForJS = [];
                foreach ($alertItems as $item) {
                    $invId = (int)($item['id'] ?? 0);
                    if ($invId > 0 && isset($item['variation_stocks'])) {
                        $varMapForJS[$invId] = [];
                        foreach ($item['variation_stocks'] as $varKey => $stock) {
                            $varMapForJS[$invId][] = [
                                'variation' => $varKey,
                                'unit_type' => $item['variation_units'][$varKey] ?? 'per piece',
                                'unit_price' => $item['variation_prices'][$varKey] ?? 0,
                                'stock' => $stock
                            ];
                        }
                    }
                }
                echo json_encode($varMapForJS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            ?>;

      function populateOrderVariations(inventoryId) {
        const list = alertsVariationDataMap[inventoryId] || [];
        const sel = document.getElementById('order-variation');
        if (!sel) return;
                
        sel.innerHTML = '';
                const opt0 = document.createElement('option');
                opt0.value = '';
                opt0.textContent = 'Base Stock (No Variation)';
                sel.appendChild(opt0);
                
                list.forEach(v => {
                    const variationDisplay = formatVariationForDisplayJS(v.variation);
                    const text = `${variationDisplay} (${v.unit_type || 'N/A'}) - ₱${(v.unit_price ?? 0).toFixed(2)}`;
                    const opt = document.createElement('option');
                    opt.value = v.variation;
                    opt.textContent = text;
                    opt.setAttribute('data-unit-type', v.unit_type || '');
                    opt.setAttribute('data-unit-price', v.unit_price || 0);
                    sel.appendChild(opt);
                });
                
                document.getElementById('order-unit-type').value = '';
                document.getElementById('order-unit-price-display').value = '';
                document.getElementById('order-unit-price').value = '';
            }

            function formatVariationForDisplayJS(variation) {
          if (!variation) return '';
          if (variation.indexOf('|') === -1 && variation.indexOf(':') === -1) return variation;
          const parts = variation.split('|');
          const values = [];
          parts.forEach(part => {
            const av = part.split(':');
            if (av.length === 2) {
              values.push(av[1].trim());
            } else {
              values.push(part.trim());
            }
          });
                return values.join(' - ');
            }

            // Update order fields when variation changes
            $('#order-variation').on('change', function() {
                const selectedOption = $(this).find('option:selected');
                const unitType = selectedOption.data('unit-type') || '';
                const unitPrice = parseFloat(selectedOption.data('unit-price') || 0);
                
                $('#order-unit-type').val(unitType);
                $('#order-unit-price-display').val(unitPrice > 0 ? unitPrice.toFixed(2) : '');
                $('#order-unit-price').val(unitPrice > 0 ? unitPrice : '');
            });

            // Cleanup interval on page unload
            $(window).on('beforeunload', function() {
                if (alertUpdateInterval) {
                    clearInterval(alertUpdateInterval);
                }
            });
      });
    </script>
</body>
</html>
