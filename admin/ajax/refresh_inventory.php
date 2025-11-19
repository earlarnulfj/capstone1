<?php
header('Content-Type: application/json');

try {
    require_once '../../config/database.php';
    require_once '../../models/inventory.php';
    require_once '../../models/supplier.php';
    require_once '../../models/inventory_variation.php';

    $db = (new Database())->getConnection();
    $inventory = new Inventory($db);
    $supplier = new Supplier($db);
    $invVar = new InventoryVariation($db);

    // Build supplier id->name map
    $suppliersStmt = $supplier->readAll();
    $supplierMap = [];
    while ($row = $suppliersStmt->fetch(PDO::FETCH_ASSOC)) {
        $supplierMap[$row['id']] = $row['name'];
    }

    // Read inventory items that exist in admin_orders, orders, or sales_transactions
    $query = "SELECT i.*, s.name as supplier_name
              FROM inventory i
              LEFT JOIN suppliers s ON i.supplier_id = s.id
              WHERE COALESCE(i.is_deleted, 0) = 0
                AND (
                    EXISTS (SELECT 1 FROM admin_orders ao WHERE ao.inventory_id = i.id)
                    OR EXISTS (SELECT 1 FROM orders o WHERE o.inventory_id = i.id)
                    OR EXISTS (SELECT 1 FROM sales_transactions st WHERE st.inventory_id = i.id)
                )
              ORDER BY i.last_updated DESC, i.name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Skip archived (soft-deleted) items if column exists
        if (!empty($row['is_deleted']) && intval($row['is_deleted']) === 1) {
            continue;
        }

        // Compute variations, prices, and stocks
        $varsStmt = $invVar->getByInventory((int)$row['id']);
        $variationList = [];
        $variationPrices = [];
        $variationStocks = [];
        $unitType = 'per piece'; // normalized lower-case
        if ($varsStmt) {
            $allVars = $varsStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($allVars as $vr) {
                $variationList[] = (string)$vr['variation'];
                if (isset($vr['unit_price']) && $vr['unit_price'] !== null && $vr['unit_price'] !== '') {
                    $variationPrices[$vr['variation']] = (float)$vr['unit_price'];
                }
            }
            if (!empty($allVars) && isset($allVars[0]['unit_type']) && $allVars[0]['unit_type'] !== '') {
                $unitType = strtolower($allVars[0]['unit_type']);
            }
            // Stocks map for this unit type - ensure we get fresh data from database
            try {
                // Query directly from database to ensure fresh data (no caching)
                $stockQuery = "SELECT variation, quantity FROM inventory_variations WHERE inventory_id = ? AND LOWER(unit_type) = LOWER(?)";
                $stockStmt = $db->prepare($stockQuery);
                $stockStmt->execute([(int)$row['id'], $unitType]);
                $variationStocks = [];
                while ($stockRow = $stockStmt->fetch(PDO::FETCH_ASSOC)) {
                    $variationStocks[$stockRow['variation']] = (int)$stockRow['quantity'];
                }
            } catch (Throwable $e) { 
                // Fallback to model method if direct query fails
                try {
                    $variationStocks = $invVar->getStocksMap((int)$row['id'], $unitType);
                } catch (Throwable $e2) {
                    $variationStocks = [];
                }
            }
        }

        // Attach unified fields expected by supplier/products.php structure
        $row['variations'] = $variationList; // array of strings
        $row['variation_prices'] = $variationPrices; // map variation => price(float)
        $row['variation_stocks'] = $variationStocks; // map variation => stock(int)
        $row['unit_type'] = $unitType; // lower-case string
        $row['supplier_name'] = isset($supplierMap[$row['supplier_id']]) ? $supplierMap[$row['supplier_id']] : 'Admin';
        // Remove any origin-related fields before output
        if (isset($row['source_type'])) { unset($row['source_type']); }
        $items[] = $row;
    }

    echo json_encode(['success' => true, 'data' => $items]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}