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

    // Read inventory from completed orders only (matching inventory.php)
    $stmt = $inventory->readAllFromCompletedOrders();
    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Skip archived (soft-deleted) items if column exists
        if (!empty($row['is_deleted']) && intval($row['is_deleted']) === 1) {
            continue;
        }

        $inventory_id = isset($row['inventory_id']) ? (int)$row['inventory_id'] : (int)$row['id'];
        
        // Get ALL completed orders for this inventory item
        // CRITICAL: Only fetch from admin_orders table (admin/orders.php)
        // Do NOT fetch from orders table or any other source
        $completed_orders = [];
        try {
            // Get ONLY completed orders from admin_orders table
            $ordersStmt = $db->prepare("SELECT id, variation, quantity, unit_type, order_date
                                      FROM admin_orders 
                                      WHERE inventory_id = ? 
                                      AND confirmation_status = 'completed' 
                                      ORDER BY order_date DESC");
            $ordersStmt->execute([$inventory_id]);
            while ($orderRow = $ordersStmt->fetch(PDO::FETCH_ASSOC)) {
                $completed_orders[] = [
                    'id' => (int)$orderRow['id'],
                    'variation' => $orderRow['variation'] ?? '',
                    'quantity' => (int)$orderRow['quantity'],
                    'unit_type' => $orderRow['unit_type'] ?? 'per piece',
                    'order_date' => $orderRow['order_date'] ?? null
                ];
            }
            
            // DO NOT fetch from orders table - we only want data from admin/orders.php
        } catch (Exception $e) {
            error_log("Error getting completed orders from admin_orders in AJAX: " . $e->getMessage());
        }

        // Compute variations, prices, and stocks
        $varsStmt = $invVar->getByInventory($inventory_id);
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
                $stockStmt->execute([$inventory_id, $unitType]);
                $variationStocks = [];
                while ($stockRow = $stockStmt->fetch(PDO::FETCH_ASSOC)) {
                    $variationStocks[$stockRow['variation']] = (int)$stockRow['quantity'];
                }
            } catch (Throwable $e) { 
                // Fallback to model method if direct query fails
                try {
                    $variationStocks = $invVar->getStocksMap($inventory_id, $unitType);
                } catch (Throwable $e2) {
                    $variationStocks = [];
                }
            }
        }

        // Attach unified fields expected by inventory.php structure
        $row['variations'] = $variationList; // array of strings
        $row['variation_prices'] = $variationPrices; // map variation => price(float)
        $row['variation_stocks'] = $variationStocks; // map variation => stock(int)
        $row['unit_type'] = $unitType; // lower-case string
        $row['supplier_name'] = isset($supplierMap[$row['supplier_id']]) ? $supplierMap[$row['supplier_id']] : 'Admin';
        $row['completed_orders'] = $completed_orders; // ALL completed orders for this inventory item
        $items[] = $row;
    }

    echo json_encode(['success' => true, 'data' => $items]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}