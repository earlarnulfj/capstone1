<?php
/**
 * Comprehensive Sync Script: Sync ALL products from supplier_catalog to inventory
 * 
 * This script ensures that EVERY product in supplier_catalog has a corresponding
 * inventory item, making them available for ordering in supplier_details.php
 * 
 * Run this script to sync all missing products from supplier/products.php to inventory table.
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
    <title>Sync All Supplier Products to Inventory</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        h2 { color: #555; margin-top: 30px; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 4px; border-left: 4px solid #007bff; overflow-x: auto; }
        .success { color: #28a745; }
        .error { color: #dc3545; }
        .warning { color: #ffc107; }
        .info { color: #17a2b8; }
        .summary { background: #e9ecef; padding: 15px; border-radius: 4px; margin-top: 20px; }
        .summary h3 { margin-top: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #007bff; color: white; }
        tr:hover { background: #f5f5f5; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔄 Sync All Supplier Products to Inventory</h1>
    
<?php
try {
    $db = (new Database())->getConnection();
    $inventory = new Inventory($db);
    $catalog = new SupplierCatalog($db);
    $spVariation = new SupplierProductVariation($db);
    $invVariation = new InventoryVariation($db);
    
    echo "<h2>Starting Sync Process...</h2>\n";
    echo "<pre>\n";
    
    // Get all supplier catalog products (including those that might be missing from inventory)
    $catalogStmt = $db->query("
        SELECT sc.*, s.name as supplier_name 
        FROM supplier_catalog sc
        LEFT JOIN suppliers s ON sc.supplier_id = s.id
        WHERE COALESCE(sc.is_deleted, 0) = 0 
        ORDER BY sc.supplier_id, sc.id
    ");
    
    $totalProducts = 0;
    $syncedProducts = 0;
    $skippedProducts = 0;
    $errorProducts = 0;
    $restoredProducts = 0;
    $createdProducts = 0;
    $errors = [];
    
    echo "Scanning supplier_catalog table...\n";
    echo str_repeat("=", 80) . "\n\n";
    
    while ($cat = $catalogStmt->fetch(PDO::FETCH_ASSOC)) {
        $totalProducts++;
        $catalogId = (int)$cat['id'];
        $supplierId = (int)$cat['supplier_id'];
        $sku = trim($cat['sku'] ?? '');
        $productName = $cat['name'] ?? 'Unknown';
        $supplierName = $cat['supplier_name'] ?? 'Unknown Supplier';
        
        echo "[{$totalProducts}] Processing: <strong>{$productName}</strong>\n";
        echo "    Catalog ID: {$catalogId} | Supplier: {$supplierName} (ID: {$supplierId}) | SKU: " . ($sku ?: '<span class="error">EMPTY</span>') . "\n";
        
        // Skip if SKU is empty
        if (empty($sku)) {
            echo "    <span class=\"warning\">⚠ SKIP: Empty SKU - cannot create inventory item without SKU</span>\n";
            $skippedProducts++;
            echo "\n";
            continue;
        }
        
        // Check if inventory item already exists for this supplier and SKU
        $checkStmt = $db->prepare("
            SELECT id, is_deleted, name 
            FROM inventory 
            WHERE sku = :sku AND supplier_id = :sid 
            LIMIT 1
        ");
        $checkStmt->execute([':sku' => $sku, ':sid' => $supplierId]);
        $existingInv = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingInv) {
            $existingInvId = (int)$existingInv['id'];
            $isDeleted = (int)($existingInv['is_deleted'] ?? 0);
            
            if ($isDeleted) {
                // Restore soft-deleted item
                try {
                    $db->beginTransaction();
                    $restoreStmt = $db->prepare("UPDATE inventory SET is_deleted = 0 WHERE id = :id");
                    $restoreStmt->execute([':id' => $existingInvId]);
                    
                    // Update source_inventory_id link
                    $updateLink = $db->prepare("UPDATE supplier_catalog SET source_inventory_id = :inv_id WHERE id = :cat_id");
                    $updateLink->execute([':inv_id' => $existingInvId, ':cat_id' => $catalogId]);
                    
                    $db->commit();
                    echo "    <span class=\"success\">✓ RESTORED: Inventory item ID {$existingInvId} (was soft-deleted)</span>\n";
                    $restoredProducts++;
                    $syncedProducts++;
                } catch (Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    echo "    <span class=\"error\">✗ ERROR: Could not restore inventory item: " . htmlspecialchars($e->getMessage()) . "</span>\n";
                    $errors[] = "Product ID {$catalogId} ({$productName}): " . $e->getMessage();
                    $errorProducts++;
                }
            } else {
                // Item exists and is active
                echo "    <span class=\"info\">✓ EXISTS: Inventory item ID {$existingInvId} already exists</span>\n";
                
                // Update source_inventory_id link if needed
                if (empty($cat['source_inventory_id']) || (int)$cat['source_inventory_id'] !== $existingInvId) {
                    try {
                        $updateLink = $db->prepare("UPDATE supplier_catalog SET source_inventory_id = :inv_id WHERE id = :cat_id");
                        $updateLink->execute([':inv_id' => $existingInvId, ':cat_id' => $catalogId]);
                        echo "    <span class=\"info\">✓ LINKED: Updated source_inventory_id to {$existingInvId}</span>\n";
                    } catch (Exception $e) {
                        echo "    <span class=\"warning\">⚠ WARNING: Could not update source_inventory_id: " . htmlspecialchars($e->getMessage()) . "</span>\n";
                    }
                }
                
                // Sync variations if they don't exist
                try {
                    $variants = $spVariation->getByProduct($catalogId);
                    if (is_array($variants) && !empty($variants)) {
                        $variationsSynced = 0;
                        foreach ($variants as $vr) {
                            $variation = $vr['variation'] ?? '';
                            if ($variation === '') { continue; }
                            
                            // Check if variation already exists in inventory
                            $varCheckStmt = $db->prepare("
                                SELECT id FROM inventory_variations 
                                WHERE inventory_id = :inv_id AND variation = :var 
                                LIMIT 1
                            ");
                            $varCheckStmt->execute([':inv_id' => $existingInvId, ':var' => $variation]);
                            
                            if (!$varCheckStmt->fetch()) {
                                // Variation doesn't exist - create it
                                $unitType = $vr['unit_type'] ?? ($cat['unit_type'] ?? 'per piece');
                                $price = isset($vr['unit_price']) && $vr['unit_price'] !== null ? (float)$vr['unit_price'] : null;
                                if (method_exists($invVariation, 'createVariant')) {
                                    $invVariation->createVariant($existingInvId, $variation, $unitType, 0, $price);
                                    $variationsSynced++;
                                }
                            }
                        }
                        if ($variationsSynced > 0) {
                            echo "    <span class=\"info\">✓ SYNCED: {$variationsSynced} variation(s)</span>\n";
                        }
                    }
                } catch (Exception $e) {
                    echo "    <span class=\"warning\">⚠ WARNING: Could not sync variations: " . htmlspecialchars($e->getMessage()) . "</span>\n";
                }
                
                $syncedProducts++;
            }
            echo "\n";
            continue;
        }
        
        // Inventory item doesn't exist - create it
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
                $newInventoryId = (int)$db->lastInsertId();
                
                if ($newInventoryId <= 0) {
                    throw new Exception("createForSupplier returned true but lastInsertId is invalid");
                }
                
                // Verify the item was created
                $verifyStmt = $db->prepare("SELECT id FROM inventory WHERE id = :id LIMIT 1");
                $verifyStmt->execute([':id' => $newInventoryId]);
                if (!$verifyStmt->fetch()) {
                    throw new Exception("Inventory item created but not found in database");
                }
                
                // Update supplier_catalog with source_inventory_id
                $updateLink = $db->prepare("UPDATE supplier_catalog SET source_inventory_id = :inv_id WHERE id = :cat_id");
                $updateLink->execute([':inv_id' => $newInventoryId, ':cat_id' => $catalogId]);
                
                // Sync variations from supplier_product_variations
                $variants = $spVariation->getByProduct($catalogId);
                $variationsCreated = 0;
                if (is_array($variants)) {
                    foreach ($variants as $vr) {
                        $variation = $vr['variation'] ?? '';
                        if ($variation === '') { continue; }
                        $unitType = $vr['unit_type'] ?? ($cat['unit_type'] ?? 'per piece');
                        $price = isset($vr['unit_price']) && $vr['unit_price'] !== null ? (float)$vr['unit_price'] : null;
                        if (method_exists($invVariation, 'createVariant')) {
                            $invVariation->createVariant($newInventoryId, $variation, $unitType, 0, $price);
                            $variationsCreated++;
                        }
                    }
                }
                
                $db->commit();
                echo "    <span class=\"success\">✓ CREATED: Inventory item ID {$newInventoryId}</span>\n";
                if ($variationsCreated > 0) {
                    echo "    <span class=\"success\">✓ CREATED: {$variationsCreated} variation(s)</span>\n";
                }
                $createdProducts++;
                $syncedProducts++;
            } else {
                $db->rollBack();
                throw new Exception("createForSupplier returned false");
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            echo "    <span class=\"error\">✗ ERROR: Failed to create inventory item: " . htmlspecialchars($e->getMessage()) . "</span>\n";
            $errors[] = "Product ID {$catalogId} ({$productName}): " . $e->getMessage();
            $errorProducts++;
        }
        
        echo "\n";
    }
    
    echo str_repeat("=", 80) . "\n";
    echo "</pre>\n";
    
    // Summary
    echo "<div class=\"summary\">\n";
    echo "<h3>📊 Sync Summary</h3>\n";
    echo "<table>\n";
    echo "<tr><th>Metric</th><th>Count</th></tr>\n";
    echo "<tr><td>Total products processed</td><td><strong>{$totalProducts}</strong></td></tr>\n";
    echo "<tr><td class=\"success\">Successfully synced</td><td><strong class=\"success\">{$syncedProducts}</strong></td></tr>\n";
    echo "<tr><td class=\"success\">Newly created</td><td><strong class=\"success\">{$createdProducts}</strong></td></tr>\n";
    echo "<tr><td class=\"info\">Restored (was soft-deleted)</td><td><strong class=\"info\">{$restoredProducts}</strong></td></tr>\n";
    echo "<tr><td class=\"warning\">Skipped (empty SKU or already exists)</td><td><strong class=\"warning\">{$skippedProducts}</strong></td></tr>\n";
    echo "<tr><td class=\"error\">Errors</td><td><strong class=\"error\">{$errorProducts}</strong></td></tr>\n";
    echo "</table>\n";
    
    if (!empty($errors)) {
        echo "<h3>❌ Errors Encountered</h3>\n";
        echo "<ul>\n";
        foreach ($errors as $error) {
            echo "<li class=\"error\">" . htmlspecialchars($error) . "</li>\n";
        }
        echo "</ul>\n";
    }
    
    if ($errorProducts === 0 && $syncedProducts > 0) {
        echo "<p class=\"success\"><strong>✅ Sync completed successfully! All products are now available in inventory.</strong></p>\n";
    } elseif ($errorProducts > 0) {
        echo "<p class=\"warning\"><strong>⚠️ Sync completed with some errors. Please review the errors above.</strong></p>\n";
    }
    
    echo "</div>\n";
    
    // Show products that still need attention
    $missingStmt = $db->query("
        SELECT sc.id, sc.name, sc.sku, s.name as supplier_name, sc.supplier_id
        FROM supplier_catalog sc
        LEFT JOIN suppliers s ON sc.supplier_id = s.id
        WHERE COALESCE(sc.is_deleted, 0) = 0
          AND (sc.sku IS NULL OR sc.sku = '')
        ORDER BY sc.supplier_id, sc.id
    ");
    
    $missingProducts = $missingStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($missingProducts)) {
        echo "<h3>⚠️ Products Missing SKU (Cannot be synced)</h3>\n";
        echo "<p>These products need a SKU before they can be synced to inventory:</p>\n";
        echo "<table>\n";
        echo "<tr><th>Catalog ID</th><th>Product Name</th><th>Supplier</th><th>Action</th></tr>\n";
        foreach ($missingProducts as $missing) {
            echo "<tr>\n";
            echo "<td>{$missing['id']}</td>\n";
            echo "<td>" . htmlspecialchars($missing['name']) . "</td>\n";
            echo "<td>" . htmlspecialchars($missing['supplier_name']) . "</td>\n";
            echo "<td><a href=\"../supplier/products.php\" target=\"_blank\">Add SKU in Products Page</a></td>\n";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }
    
} catch (Exception $e) {
    echo "<div class=\"error\">\n";
    echo "<h3>❌ Fatal Error</h3>\n";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "\n\n" . htmlspecialchars($e->getTraceAsString()) . "</pre>\n";
    echo "</div>\n";
}
?>

    <div style="margin-top: 30px; padding: 15px; background: #e9ecef; border-radius: 4px;">
        <h3>ℹ️ Next Steps</h3>
        <ul>
            <li>All products with valid SKUs have been synced to the inventory table</li>
            <li>You can now order these products in <a href="supplier_details.php">supplier_details.php</a></li>
            <li>Products without SKUs need to be updated in <a href="../supplier/products.php" target="_blank">supplier/products.php</a></li>
            <li>This sync runs automatically on page load, but you can run this script anytime to ensure everything is synced</li>
        </ul>
    </div>
</div>
</body>
</html>

