<?php
/**
 * Comprehensive Sync Script: Ensure supplier_catalog and inventory tables are connected and synchronized
 * 
 * This script ensures:
 * 1. All products in supplier_catalog have corresponding inventory items
 * 2. All data fields are synchronized between both tables
 * 3. source_inventory_id links are properly maintained
 * 4. Variations are synced between supplier_product_variations and inventory_variations
 * 
 * Run this script to ensure complete data consistency between supplier_catalog and inventory tables.
 */

// ====== Access control & dependencies ======
include_once '../config/session.php';
require_once '../config/database.php';
require_once '../models/inventory.php';
require_once '../models/supplier_catalog.php';
require_once '../models/supplier_product_variation.php';
require_once '../models/inventory_variation.php';

// ---- Admin auth guard ----
if (empty($_SESSION['admin']['user_id'])) {
    die("Error: Admin authentication required. Please log in first.");
}

if (($_SESSION['admin']['role'] ?? null) !== 'management') {
    die("Error: Management role required.");
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Sync Supplier Catalog & Inventory Tables</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1400px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        h2 { color: #555; margin-top: 30px; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 4px; border-left: 4px solid #007bff; overflow-x: auto; font-size: 12px; }
        .success { color: #28a745; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        .warning { color: #ffc107; font-weight: bold; }
        .info { color: #17a2b8; font-weight: bold; }
        .summary { background: #e9ecef; padding: 15px; border-radius: 4px; margin-top: 20px; }
        .summary h3 { margin-top: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 12px; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #007bff; color: white; position: sticky; top: 0; }
        tr:hover { background: #f5f5f5; }
        .btn { display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; margin: 10px 5px; }
        .btn:hover { background: #0056b3; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 4px; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔄 Sync Supplier Catalog & Inventory Tables</h1>
    <p>This script ensures complete data consistency between <code>supplier_catalog</code> and <code>inventory</code> tables.</p>
    
<?php
try {
    $db = (new Database())->getConnection();
    $inventory = new Inventory($db);
    $catalog = new SupplierCatalog($db);
    $spVariation = new SupplierProductVariation($db);
    $invVariation = new InventoryVariation($db);
    
    echo "<div class=\"section\">\n";
    echo "<h2>Step 1: Creating Missing Inventory Items (FORCING ALL PRODUCTS)</h2>\n";
    echo "<pre>\n";
    
    // Step 1: Create missing inventory items - FORCE ALL PRODUCTS FROM supplier_catalog
    $catalogStmt = $db->query("
        SELECT sc.*, s.name as supplier_name 
        FROM supplier_catalog sc
        LEFT JOIN suppliers s ON sc.supplier_id = s.id
        WHERE COALESCE(sc.is_deleted, 0) = 0 
        ORDER BY sc.supplier_id, sc.id
    ");
    
    $totalProducts = 0;
    $createdItems = 0;
    $updatedItems = 0;
    $skippedItems = 0;
    $restoredItems = 0;
    $errors = [];
    $missingProducts = [];
    
    echo "Scanning ALL products from supplier_catalog table...\n";
    echo str_repeat("=", 80) . "\n\n";
    
    while ($cat = $catalogStmt->fetch(PDO::FETCH_ASSOC)) {
        $totalProducts++;
        $catalogId = (int)$cat['id'];
        $supplierId = (int)$cat['supplier_id'];
        $sku = trim($cat['sku'] ?? '');
        $productName = $cat['name'] ?? 'Unknown';
        $supplierName = $cat['supplier_name'] ?? 'Unknown Supplier';
        
        echo "[{$totalProducts}] Processing: <strong>{$productName}</strong> (Catalog ID: {$catalogId})\n";
        echo "    Supplier: {$supplierName} (ID: {$supplierId}) | SKU: " . ($sku ?: '<span class="error">EMPTY - WILL GENERATE</span>') . "\n";
        
        // If SKU is empty, generate one automatically
        if (empty($sku)) {
            $generatedSku = 'AUTO-' . $supplierId . '-' . $catalogId . '-' . time();
            echo "    <span class=\"warning\">⚠ Empty SKU detected - Generating: {$generatedSku}</span>\n";
            
            // Update supplier_catalog with generated SKU
            try {
                $updateSkuStmt = $db->prepare("UPDATE supplier_catalog SET sku = :sku WHERE id = :id");
                $updateSkuStmt->execute([':sku' => $generatedSku, ':id' => $catalogId]);
                $sku = $generatedSku;
                echo "    <span class=\"info\">✓ Updated supplier_catalog with generated SKU</span>\n";
            } catch (Exception $e) {
                echo "    <span class=\"error\">✗ Failed to update SKU: " . htmlspecialchars($e->getMessage()) . "</span>\n";
                $errors[] = "Product ID {$catalogId} ({$productName}): Failed to generate SKU - " . $e->getMessage();
                $skippedItems++;
                $missingProducts[] = ['id' => $catalogId, 'name' => $productName, 'reason' => 'SKU generation failed'];
                echo "\n";
                continue;
            }
        }
        
        // Check if inventory item exists (including soft-deleted)
        $checkStmt = $db->prepare("SELECT id, is_deleted FROM inventory WHERE sku = :sku AND supplier_id = :sid LIMIT 1");
        $checkStmt->execute([':sku' => $sku, ':sid' => $supplierId]);
        $existingInv = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingInv) {
            $invId = (int)$existingInv['id'];
            $isDeleted = (int)($existingInv['is_deleted'] ?? 0);
            
            if ($isDeleted) {
                // Restore soft-deleted item
                try {
                    $db->beginTransaction();
                    $restoreStmt = $db->prepare("UPDATE inventory SET is_deleted = 0 WHERE id = :id");
                    $restoreStmt->execute([':id' => $invId]);
                    $db->commit();
                    echo "    <span class=\"success\">✓ RESTORED: Inventory item ID {$invId} (was soft-deleted)</span>\n";
                    $restoredItems++;
                } catch (Exception $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    echo "    <span class=\"error\">✗ ERROR: Failed to restore item: " . htmlspecialchars($e->getMessage()) . "</span>\n";
                    $errors[] = "Failed to restore item for {$productName}: " . $e->getMessage();
                }
            } else {
                echo "    <span class=\"info\">✓ EXISTS: Inventory item ID {$invId} already exists</span>\n";
            }
            
            // Update source_inventory_id link
            if (empty($cat['source_inventory_id']) || (int)$cat['source_inventory_id'] !== $invId) {
                try {
                    $updateLink = $db->prepare("UPDATE supplier_catalog SET source_inventory_id = :inv_id WHERE id = :cat_id");
                    $updateLink->execute([':inv_id' => $invId, ':cat_id' => $catalogId]);
                    echo "    <span class=\"info\">✓ LINKED: Updated source_inventory_id to {$invId}</span>\n";
                } catch (Exception $e) {
                    echo "    <span class=\"warning\">⚠ WARNING: Could not update link: " . htmlspecialchars($e->getMessage()) . "</span>\n";
                    $errors[] = "Failed to update link for {$productName}: " . $e->getMessage();
                }
            }
        } else {
            // Inventory item doesn't exist - CREATE IT NOW
            echo "    <span class=\"warning\">⚠ MISSING: Creating new inventory item...</span>\n";
            // Create new inventory item - FORCE CREATION
            try {
                $db->beginTransaction();
                
                $inventory->sku = $sku;
                $inventory->name = $cat['name'] ?? '';
                $inventory->description = $cat['description'] ?? '';
                $inventory->quantity = 0;
                $inventory->reorder_threshold = isset($cat['reorder_threshold']) ? (int)$cat['reorder_threshold'] : 10;
                $inventory->category = $cat['category'] ?? '';
                $inventory->unit_price = isset($cat['unit_price']) ? (float)$cat['unit_price'] : 0.0;
                $inventory->location = $cat['location'] ?? '';
                $inventory->supplier_id = $supplierId;
                
                if ($inventory->createForSupplier($supplierId)) {
                    $newInvId = (int)$db->lastInsertId();
                    
                    if ($newInvId <= 0) {
                        throw new Exception("createForSupplier returned true but lastInsertId is invalid");
                    }
                    
                    // Verify the item was created
                    $verifyStmt = $db->prepare("SELECT id FROM inventory WHERE id = :id LIMIT 1");
                    $verifyStmt->execute([':id' => $newInvId]);
                    if (!$verifyStmt->fetch()) {
                        throw new Exception("Inventory item created but not found in database");
                    }
                    
                    // Update source_inventory_id
                    $updateLink = $db->prepare("UPDATE supplier_catalog SET source_inventory_id = :inv_id WHERE id = :cat_id");
                    $updateLink->execute([':inv_id' => $newInvId, ':cat_id' => $catalogId]);
                    
                    // Sync variations immediately
                    $variants = $spVariation->getByProduct($catalogId);
                    $variationsCreated = 0;
                    if (is_array($variants)) {
                        foreach ($variants as $vr) {
                            $variation = $vr['variation'] ?? '';
                            if (empty($variation)) continue;
                            $unitType = $vr['unit_type'] ?? ($cat['unit_type'] ?? 'per piece');
                            $price = isset($vr['unit_price']) && $vr['unit_price'] !== null ? (float)$vr['unit_price'] : null;
                            if (method_exists($invVariation, 'createVariant')) {
                                $invVariation->createVariant($newInvId, $variation, $unitType, 0, $price);
                                $variationsCreated++;
                            }
                        }
                    }
                    
                    $db->commit();
                    echo "    <span class=\"success\">✓ CREATED: Inventory item ID {$newInvId}</span>\n";
                    if ($variationsCreated > 0) {
                        echo "    <span class=\"success\">✓ CREATED: {$variationsCreated} variation(s)</span>\n";
                    }
                    $createdItems++;
                } else {
                    $db->rollBack();
                    throw new Exception("createForSupplier returned false");
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                echo "    <span class=\"error\">✗ ERROR: Failed to create inventory item: " . htmlspecialchars($e->getMessage()) . "</span>\n";
                $errors[] = "Failed to create item for {$productName}: " . $e->getMessage();
                $missingProducts[] = ['id' => $catalogId, 'name' => $productName, 'reason' => $e->getMessage()];
            }
        }
    }
    
    echo "</pre>\n";
    echo "</div>\n";
    
    // Step 2: Synchronize data fields
    echo "<div class=\"section\">\n";
    echo "<h2>Step 2: Synchronizing Data Fields</h2>\n";
    echo "<pre>\n";
    
    $syncStmt = $db->query("
        SELECT sc.*, i.id as inventory_id, i.name as inv_name, i.description as inv_description,
               i.category as inv_category, i.unit_price as inv_unit_price, i.location as inv_location,
               i.reorder_threshold as inv_reorder_threshold
        FROM supplier_catalog sc
        INNER JOIN inventory i ON i.sku = sc.sku AND i.supplier_id = sc.supplier_id
        WHERE COALESCE(sc.is_deleted, 0) = 0 
          AND COALESCE(i.is_deleted, 0) = 0
          AND sc.sku IS NOT NULL AND sc.sku != ''
        ORDER BY sc.supplier_id, sc.id
    ");
    
    $fieldsUpdated = 0;
    while ($row = $syncStmt->fetch(PDO::FETCH_ASSOC)) {
        $updates = [];
        $params = [':id' => (int)$row['inventory_id']];
        
        // Compare and update fields
        if (trim($row['name'] ?? '') !== trim($row['inv_name'] ?? '')) {
            $updates[] = "name = :name";
            $params[':name'] = $row['name'];
        }
        
        if (trim($row['description'] ?? '') !== trim($row['inv_description'] ?? '')) {
            $updates[] = "description = :description";
            $params[':description'] = $row['description'];
        }
        
        if (trim($row['category'] ?? '') !== trim($row['inv_category'] ?? '')) {
            $updates[] = "category = :category";
            $params[':category'] = $row['category'];
        }
        
        $catPrice = isset($row['unit_price']) ? (float)$row['unit_price'] : 0.0;
        $invPrice = isset($row['inv_unit_price']) ? (float)$row['inv_unit_price'] : 0.0;
        if (abs($catPrice - $invPrice) > 0.01) {
            $updates[] = "unit_price = :unit_price";
            $params[':unit_price'] = $catPrice;
        }
        
        if (trim($row['location'] ?? '') !== trim($row['inv_location'] ?? '')) {
            $updates[] = "location = :location";
            $params[':location'] = $row['location'];
        }
        
        $catReorder = isset($row['reorder_threshold']) ? (int)$row['reorder_threshold'] : 10;
        $invReorder = isset($row['inv_reorder_threshold']) ? (int)$row['inv_reorder_threshold'] : 10;
        if ($catReorder !== $invReorder) {
            $updates[] = "reorder_threshold = :reorder_threshold";
            $params[':reorder_threshold'] = $catReorder;
        }
        
        if (!empty($updates)) {
            try {
                $updateSql = "UPDATE inventory SET " . implode(", ", $updates) . " WHERE id = :id";
                $updateStmt = $db->prepare($updateSql);
                $updateStmt->execute($params);
                $fieldsUpdated++;
                echo "✓ Updated: {$row['name']} (ID: {$row['inventory_id']}) - " . implode(", ", $updates) . "\n";
            } catch (Exception $e) {
                $errors[] = "Failed to update {$row['name']}: " . $e->getMessage();
            }
        }
    }
    
    echo "</pre>\n";
    echo "</div>\n";
    
    // Step 3: Sync variations
    echo "<div class=\"section\">\n";
    echo "<h2>Step 3: Synchronizing Variations</h2>\n";
    echo "<pre>\n";
    
    $variationsSynced = 0;
    $varStmt = $db->query("
        SELECT sc.id as catalog_id, sc.sku, sc.supplier_id, i.id as inventory_id
        FROM supplier_catalog sc
        INNER JOIN inventory i ON i.sku = sc.sku AND i.supplier_id = sc.supplier_id
        WHERE COALESCE(sc.is_deleted, 0) = 0 
          AND COALESCE(i.is_deleted, 0) = 0
          AND sc.sku IS NOT NULL AND sc.sku != ''
    ");
    
    while ($varRow = $varStmt->fetch(PDO::FETCH_ASSOC)) {
        $catalogId = (int)$varRow['catalog_id'];
        $inventoryId = (int)$varRow['inventory_id'];
        
        // Get variations from supplier_product_variations
        $variants = $spVariation->getByProduct($catalogId);
        if (is_array($variants)) {
            foreach ($variants as $vr) {
                $variation = $vr['variation'] ?? '';
                if (empty($variation)) continue;
                
                // Check if variation exists in inventory_variations
                $varCheckStmt = $db->prepare("
                    SELECT id FROM inventory_variations 
                    WHERE inventory_id = :inv_id AND variation = :var 
                    LIMIT 1
                ");
                $varCheckStmt->execute([':inv_id' => $inventoryId, ':var' => $variation]);
                
                if (!$varCheckStmt->fetch()) {
                    // Variation doesn't exist - create it
                    try {
                        $unitType = $vr['unit_type'] ?? 'per piece';
                        $price = isset($vr['unit_price']) && $vr['unit_price'] !== null ? (float)$vr['unit_price'] : null;
                        if (method_exists($invVariation, 'createVariant')) {
                            $invVariation->createVariant($inventoryId, $variation, $unitType, 0, $price);
                            $variationsSynced++;
                            echo "✓ Created variation '{$variation}' for inventory ID {$inventoryId}\n";
                        }
                    } catch (Exception $e) {
                        $errors[] = "Failed to create variation '{$variation}': " . $e->getMessage();
                    }
                } else {
                    // Update variation price if different
                    $varUpdateStmt = $db->prepare("
                        UPDATE inventory_variations 
                        SET unit_price = :price, unit_type = :unit_type
                        WHERE inventory_id = :inv_id AND variation = :var
                    ");
                    $varPrice = isset($vr['unit_price']) && $vr['unit_price'] !== null ? (float)$vr['unit_price'] : null;
                    $varUnitType = $vr['unit_type'] ?? 'per piece';
                    $varUpdateStmt->execute([
                        ':price' => $varPrice,
                        ':unit_type' => $varUnitType,
                        ':inv_id' => $inventoryId,
                        ':var' => $variation
                    ]);
                }
            }
        }
    }
    
    echo "</pre>\n";
    echo "</div>\n";
    
    // Step 4: Verification Report
    echo "<div class=\"section\">\n";
    echo "<h2>Step 4: Verification Report</h2>\n";
    
    // Check connection status
    $verifyStmt = $db->query("
        SELECT 
            COUNT(*) as total_catalog,
            SUM(CASE WHEN source_inventory_id IS NOT NULL THEN 1 ELSE 0 END) as linked,
            SUM(CASE WHEN source_inventory_id IS NULL AND (sku IS NULL OR sku = '') THEN 1 ELSE 0 END) as missing_sku,
            SUM(CASE WHEN source_inventory_id IS NULL AND sku IS NOT NULL AND sku != '' THEN 1 ELSE 0 END) as not_linked
        FROM supplier_catalog
        WHERE COALESCE(is_deleted, 0) = 0
    ");
    $verify = $verifyStmt->fetch(PDO::FETCH_ASSOC);
    
    // Check data consistency
    $consistencyStmt = $db->query("
        SELECT COUNT(*) as mismatched
        FROM supplier_catalog sc
        INNER JOIN inventory i ON i.sku = sc.sku AND i.supplier_id = sc.supplier_id
        WHERE COALESCE(sc.is_deleted, 0) = 0 
          AND COALESCE(i.is_deleted, 0) = 0
          AND (
              sc.name != i.name OR
              COALESCE(sc.description, '') != COALESCE(i.description, '') OR
              COALESCE(sc.category, '') != COALESCE(i.category, '') OR
              ABS(COALESCE(sc.unit_price, 0) - COALESCE(i.unit_price, 0)) > 0.01 OR
              COALESCE(sc.location, '') != COALESCE(i.location, '') OR
              COALESCE(sc.reorder_threshold, 10) != COALESCE(i.reorder_threshold, 10)
          )
    ");
    $consistency = $consistencyStmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<div class=\"summary\">\n";
    echo "<h3>📊 Sync Summary</h3>\n";
    echo "<table>\n";
    echo "<tr><th>Metric</th><th>Count</th><th>Status</th></tr>\n";
    echo "<tr><td>Total supplier_catalog products</td><td><strong>{$verify['total_catalog']}</strong></td><td class=\"info\">-</td></tr>\n";
    echo "<tr><td>Linked to inventory</td><td><strong>{$verify['linked']}</strong></td><td class=\"success\">✓</td></tr>\n";
    echo "<tr><td>Missing SKU (cannot link)</td><td><strong>{$verify['missing_sku']}</strong></td><td class=\"warning\">⚠</td></tr>\n";
    echo "<tr><td>Not linked (needs attention)</td><td><strong>{$verify['not_linked']}</strong></td><td class=\"error\">✗</td></tr>\n";
    echo "<tr><td>New items created</td><td><strong>{$createdItems}</strong></td><td class=\"success\">✓</td></tr>\n";
    echo "<tr><td>Items restored</td><td><strong>{$restoredItems}</strong></td><td class=\"info\">✓</td></tr>\n";
    echo "<tr><td>Fields synchronized</td><td><strong>{$fieldsUpdated}</strong></td><td class=\"success\">✓</td></tr>\n";
    echo "<tr><td>Variations synced</td><td><strong>{$variationsSynced}</strong></td><td class=\"success\">✓</td></tr>\n";
    echo "<tr><td>Data mismatches remaining</td><td><strong>{$consistency['mismatched']}</strong></td><td>" . ($consistency['mismatched'] > 0 ? "<span class=\"warning\">⚠</span>" : "<span class=\"success\">✓</span>") . "</td></tr>\n";
    echo "</table>\n";
    
    if (!empty($errors)) {
        echo "<h3>❌ Errors Encountered</h3>\n";
        echo "<ul>\n";
        foreach ($errors as $error) {
            echo "<li class=\"error\">" . htmlspecialchars($error) . "</li>\n";
        }
        echo "</ul>\n";
    }
    
    if ($verify['not_linked'] == 0 && $consistency['mismatched'] == 0) {
        echo "<p class=\"success\"><strong>✅ Perfect! All products are connected and synchronized.</strong></p>\n";
    } elseif ($verify['not_linked'] > 0) {
        echo "<p class=\"warning\"><strong>⚠️ Some products are not linked. They may need SKUs or manual attention.</strong></p>\n";
    }
    
    echo "</div>\n";
    echo "</div>\n";
    
    // Show unlinked products
    if ($verify['not_linked'] > 0 || !empty($missingProducts)) {
        echo "<div class=\"section\">\n";
        echo "<h3>⚠️ Products Not Linked to Inventory</h3>\n";
        
        if (!empty($missingProducts)) {
            echo "<p><strong>Products that failed to sync:</strong></p>\n";
            echo "<table>\n";
            echo "<tr><th>Catalog ID</th><th>Product Name</th><th>Reason</th></tr>\n";
            foreach ($missingProducts as $missing) {
                echo "<tr>\n";
                echo "<td>{$missing['id']}</td>\n";
                echo "<td>" . htmlspecialchars($missing['name']) . "</td>\n";
                echo "<td class=\"error\">" . htmlspecialchars($missing['reason']) . "</td>\n";
                echo "</tr>\n";
            }
            echo "</table>\n";
        }
        
        $unlinkedStmt = $db->query("
            SELECT sc.id, sc.name, sc.sku, s.name as supplier_name
            FROM supplier_catalog sc
            LEFT JOIN suppliers s ON sc.supplier_id = s.id
            WHERE COALESCE(sc.is_deleted, 0) = 0
              AND sc.source_inventory_id IS NULL
              AND sc.sku IS NOT NULL AND sc.sku != ''
            ORDER BY sc.supplier_id, sc.id
        ");
        
        $unlinkedCount = $unlinkedStmt->rowCount();
        if ($unlinkedCount > 0) {
            echo "<p><strong>Products still not linked (may need manual intervention):</strong></p>\n";
            echo "<table>\n";
            echo "<tr><th>Catalog ID</th><th>Product Name</th><th>SKU</th><th>Supplier</th></tr>\n";
            while ($unlinked = $unlinkedStmt->fetch(PDO::FETCH_ASSOC)) {
                echo "<tr>\n";
                echo "<td>{$unlinked['id']}</td>\n";
                echo "<td>" . htmlspecialchars($unlinked['name']) . "</td>\n";
                echo "<td>" . htmlspecialchars($unlinked['sku']) . "</td>\n";
                echo "<td>" . htmlspecialchars($unlinked['supplier_name']) . "</td>\n";
                echo "</tr>\n";
            }
            echo "</table>\n";
        }
        echo "</div>\n";
    }
    
    // Final verification: Show all products that should be in inventory
    echo "<div class=\"section\">\n";
    echo "<h3>✅ Final Verification: All Products in Inventory</h3>\n";
    $finalCheckStmt = $db->query("
        SELECT 
            COUNT(*) as total_in_inventory,
            COUNT(DISTINCT sc.id) as total_in_catalog
        FROM supplier_catalog sc
        LEFT JOIN inventory i ON i.sku = sc.sku AND i.supplier_id = sc.supplier_id AND COALESCE(i.is_deleted, 0) = 0
        WHERE COALESCE(sc.is_deleted, 0) = 0
          AND sc.sku IS NOT NULL AND sc.sku != ''
    ");
    $finalCheck = $finalCheckStmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<p><strong>Total products in supplier_catalog (with SKU):</strong> {$finalCheck['total_in_catalog']}</p>\n";
    echo "<p><strong>Total products now in inventory:</strong> {$finalCheck['total_in_inventory']}</p>\n";
    
    if ($finalCheck['total_in_catalog'] == $finalCheck['total_in_inventory']) {
        echo "<p class=\"success\"><strong>✅ SUCCESS! All products from supplier/products.php are now in the inventory table!</strong></p>\n";
    } else {
        $missing = $finalCheck['total_in_catalog'] - $finalCheck['total_in_inventory'];
        echo "<p class=\"warning\"><strong>⚠️ {$missing} product(s) still missing from inventory. Please review errors above.</strong></p>\n";
    }
    echo "</div>\n";
    
} catch (Exception $e) {
    echo "<div class=\"error\">\n";
    echo "<h3>❌ Fatal Error</h3>\n";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "\n\n" . htmlspecialchars($e->getTraceAsString()) . "</pre>\n";
    echo "</div>\n";
}
?>

    <div style="margin-top: 30px; padding: 15px; background: #e9ecef; border-radius: 4px;">
        <h3>ℹ️ What This Script Does</h3>
        <ul>
            <li><strong>Creates Missing Items:</strong> Ensures every product in supplier_catalog has a corresponding inventory item</li>
            <li><strong>Synchronizes Data:</strong> Updates inventory fields to match supplier_catalog (name, description, category, price, location, reorder_threshold)</li>
            <li><strong>Links Tables:</strong> Maintains source_inventory_id links between supplier_catalog and inventory</li>
            <li><strong>Syncs Variations:</strong> Ensures all variations from supplier_product_variations exist in inventory_variations</li>
            <li><strong>Restores Items:</strong> Automatically restores soft-deleted inventory items if the catalog product is active</li>
        </ul>
        <p><strong>Note:</strong> Run this script whenever you need to ensure data consistency between the two tables.</p>
    </div>
</div>
</body>
</html>

