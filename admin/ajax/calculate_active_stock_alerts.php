<?php
// Helper function to calculate active stock alerts count
// This replicates the logic from alerts.php to calculate comprehensive alerts
// This file can be included/required by other scripts

if (!function_exists('calculateActiveStockAlerts')) {
function calculateActiveStockAlerts($db) {
    $alertItems = [];
    
    try {
        // Get products from TWO sources - EXACTLY matching alerts.php
        $alertQuery = "SELECT DISTINCT
                            COALESCE(o.inventory_id, i.id) as id,
                            i.sku,
                            COALESCE(i.name, CONCAT('Product #', COALESCE(o.inventory_id, i.id))) as name,
                            i.name as item_name,
                            i.reorder_threshold,
                            COALESCE(i.category, 'Uncategorized') as category,
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
                         (o.id IS NOT NULL AND o.confirmation_status = 'completed')
                         OR
                         (iv.id IS NOT NULL AND iv.quantity > 0 AND o.id IS NULL)
                       )
                       AND COALESCE(i.is_deleted, 0) = 0
                     ORDER BY COALESCE(i.name, CONCAT('Product #', COALESCE(o.inventory_id, i.id))) ASC";
        
        $stmt = $db->prepare($alertQuery);
        $stmt->execute();
        
        $processedIds = [];
        $processedItemNames = [];
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (empty($row['id']) || $row['id'] === null) continue;
            
            $invId = (int)$row['id'];
            if (isset($processedIds[$invId])) continue;
            $processedIds[$invId] = true;
            
            $itemNameKey = strtolower(trim($row['item_name'] ?? $row['name'] ?? ''));
            if (empty($itemNameKey)) {
                $itemNameKey = 'product_' . $invId;
            }
            
            if (isset($processedItemNames[$itemNameKey])) continue;
            $processedItemNames[$itemNameKey] = $invId;
            
            $alertItems[] = $row;
        }
    } catch (PDOException $e) {
        error_log("Calculate active stock alerts query error: " . $e->getMessage());
        return 0;
    }
    
    $comprehensiveAlerts = [];
    
    foreach ($alertItems as $item) {
        $item_id = (int)($item['id'] ?? 0);
        $threshold = (int)($item['reorder_threshold'] ?? 0);
        
        if ($item_id > 0) {
            try {
                // Get same name IDs for merging
                $item_name = trim(strtolower($item['item_name'] ?? $item['name'] ?? ''));
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
                
                // Check if has completed orders
                $hasCompletedOrders = false;
                $orderCheckStmt = $db->prepare("SELECT COUNT(*) as cnt FROM admin_orders 
                                                WHERE inventory_id IN (" . implode(',', array_fill(0, count($sameNameIds), '?')) . ")
                                                  AND confirmation_status = 'completed'");
                $orderCheckStmt->execute($sameNameIds);
                $hasCompletedOrders = ((int)$orderCheckStmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0) > 0;
                
                // Get variations
                $allVariations = [];
                if ($hasCompletedOrders) {
                    $placeholders = implode(',', array_fill(0, count($sameNameIds), '?'));
                    $allVariationsStmt = $db->prepare("SELECT 
                                                          variation, 
                                                          unit_type, 
                                                          unit_price,
                                                          SUM(quantity) as total_ordered_qty
                                                        FROM admin_orders 
                                                        WHERE inventory_id IN ($placeholders)
                                                          AND confirmation_status = 'completed'
                                                          AND variation IS NOT NULL
                                                          AND variation != ''
                                                          AND LOWER(TRIM(variation)) != 'null'
                                                        GROUP BY variation");
                    $allVariationsStmt->execute($sameNameIds);
                    $allVariations = $allVariationsStmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $placeholders = implode(',', array_fill(0, count($sameNameIds), '?'));
                    $allVariationsStmt = $db->prepare("SELECT 
                                                          variation, 
                                                          unit_type, 
                                                          unit_price,
                                                          quantity as total_ordered_qty
                                                        FROM inventory_variations 
                                                        WHERE inventory_id IN ($placeholders)
                                                          AND variation IS NOT NULL
                                                          AND variation != ''
                                                          AND LOWER(TRIM(variation)) != 'null'
                                                          AND quantity > 0");
                    $allVariationsStmt->execute($sameNameIds);
                    $allVariations = $allVariationsStmt->fetchAll(PDO::FETCH_ASSOC);
                }
                
                $variationStocks = [];
                
                foreach ($allVariations as $orderVar) {
                    $varKey = trim($orderVar['variation'] ?? '');
                    if (empty($varKey) || $varKey === 'null') continue;
                    
                    $orderedQty = (int)($orderVar['total_ordered_qty'] ?? 0);
                    
                    // Get sold quantity
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
                    
                    $varStock = max(0, $orderedQty - $soldQty);
                    
                    if (isset($variationStocks[$varKey])) {
                        $variationStocks[$varKey] += $varStock;
                    } else {
                        $variationStocks[$varKey] = $varStock;
                    }
                    
                    // Check if variation needs alert
                    if ($varStock <= $threshold) {
                        $comprehensiveAlerts[] = [
                            'inventory_id' => $item_id,
                            'variation' => $varKey
                        ];
                    }
                }
                
                // Base stock check
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
                
                $soldBasePlaceholders = implode(',', array_fill(0, count($sameNameIds), '?'));
                $soldQtyStmt = $db->prepare("SELECT SUM(quantity) as total_sold FROM sales_transactions 
                                              WHERE inventory_id IN ($soldBasePlaceholders)
                                                AND (variation IS NULL OR variation = '' OR variation = 'null' OR LOWER(TRIM(variation)) = 'null')");
                $soldQtyStmt->execute($sameNameIds);
                $soldQtyRow = $soldQtyStmt->fetch(PDO::FETCH_ASSOC);
                $soldQty = (int)($soldQtyRow['total_sold'] ?? 0);
                
                $baseStock = max(0, $orderedQty - $soldQty);
                
                // Only check base stock if no variations
                if (empty($variationStocks) && $baseStock <= $threshold) {
                    $comprehensiveAlerts[] = [
                        'inventory_id' => $item_id,
                        'variation' => null
                    ];
                }
            } catch (PDOException $e) {
                error_log("Error calculating alerts for item {$item_id}: " . $e->getMessage());
            }
        }
    }
    
    return count($comprehensiveAlerts);
}
}

