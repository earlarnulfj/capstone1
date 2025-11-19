<?php
/**
 * Database Fix Script: Sync supplier_catalog products to inventory table
 * 
 * This script ensures all products in supplier_catalog have corresponding
 * inventory items so they can be ordered in supplier_details.php
 * 
 * Run this script to fix missing inventory items for supplier catalog products.
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

try {
    $db = (new Database())->getConnection();
    $inventory = new Inventory($db);
    $catalog = new SupplierCatalog($db);
    $spVariation = new SupplierProductVariation($db);
    $invVariation = new InventoryVariation($db);
    
    echo "<h2>Supplier Catalog to Inventory Sync Fix</h2>\n";
    echo "<pre>\n";
    
    // Get all supplier catalog products
    $catalogStmt = $db->query("SELECT * FROM supplier_catalog WHERE COALESCE(is_deleted, 0) = 0 ORDER BY supplier_id, id");
    $totalProducts = 0;
    $syncedProducts = 0;
    $skippedProducts = 0;
    $errorProducts = 0;
    
    while ($cat = $catalogStmt->fetch(PDO::FETCH_ASSOC)) {
        $totalProducts++;
        $catalogId = (int)$cat['id'];
        $supplierId = (int)$cat['supplier_id'];
        $sku = trim($cat['sku'] ?? '');
        $productName = $cat['name'] ?? 'Unknown';
        
        echo "Processing: {$productName} (Catalog ID: {$catalogId}, SKU: " . ($sku ?: 'EMPTY') . ")\n";
        
        // Skip if SKU is empty
        if (empty($sku)) {
            echo "  ⚠ SKIP: Empty SKU\n";
            $skippedProducts++;
            continue;
        }
        
        // Check if inventory item already exists for this supplier and SKU
        $checkStmt = $db->prepare("SELECT id, is_deleted FROM inventory WHERE sku = :sku AND supplier_id = :sid LIMIT 1");
        $checkStmt->execute([':sku' => $sku, ':sid' => $supplierId]);
        $existingInv = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingInv) {
            $existingInvId = (int)$existingInv['id'];
            $isDeleted = (int)($existingInv['is_deleted'] ?? 0);
            
            if ($isDeleted) {
                // Restore soft-deleted item
                try {
                    $restoreStmt = $db->prepare("UPDATE inventory SET is_deleted = 0 WHERE id = :id");
                    $restoreStmt->execute([':id' => $existingInvId]);
                    echo "  ✓ RESTORED: Inventory item ID {$existingInvId} (was soft-deleted)\n";
                } catch (Exception $e) {
                    echo "  ✗ ERROR: Could not restore inventory item: " . $e->getMessage() . "\n";
                    $errorProducts++;
                    continue;
                }
            } else {
                echo "  ✓ EXISTS: Inventory item ID {$existingInvId} already exists\n";
            }
            
            // Update source_inventory_id link if needed
            if (empty($cat['source_inventory_id']) || (int)$cat['source_inventory_id'] !== $existingInvId) {
                try {
                    $updateLink = $db->prepare("UPDATE supplier_catalog SET source_inventory_id = :inv_id WHERE id = :cat_id");
                    $updateLink->execute([':inv_id' => $existingInvId, ':cat_id' => $catalogId]);
                    echo "  ✓ LINKED: Updated source_inventory_id to {$existingInvId}\n";
                } catch (Exception $e) {
                    echo "  ⚠ WARNING: Could not update source_inventory_id: " . $e->getMessage() . "\n";
                }
            }
            
            $syncedProducts++;
            continue;
        }
        
        // Check if SKU exists for another supplier (this is OK - we can still create for this supplier)
        // But we need to make sure we're not creating a duplicate for the same supplier
        $skuCheckStmt = $db->prepare("SELECT id FROM inventory WHERE sku = :sku AND supplier_id = :sid LIMIT 1");
        $skuCheckStmt->execute([':sku' => $sku, ':sid' => $supplierId]);
        if ($skuCheckStmt->fetch()) {
            echo "  ⚠ SKIP: SKU already exists for this supplier (should have been caught above)\n";
            $skippedProducts++;
            continue;
        }
        
        // Create new inventory item
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
                if (is_array($variants)) {
                    foreach ($variants as $vr) {
                        $variation = $vr['variation'] ?? '';
                        if ($variation === '') { continue; }
                        $unitType = $vr['unit_type'] ?? ($cat['unit_type'] ?? 'per piece');
                        $price = isset($vr['unit_price']) && $vr['unit_price'] !== null ? (float)$vr['unit_price'] : null;
                        if (method_exists($invVariation, 'createVariant')) {
                            $invVariation->createVariant($newInventoryId, $variation, $unitType, 0, $price);
                        }
                    }
                }
                
                $db->commit();
                echo "  ✓ CREATED: Inventory item ID {$newInventoryId}\n";
                $syncedProducts++;
            } else {
                $db->rollBack();
                throw new Exception("createForSupplier returned false");
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            echo "  ✗ ERROR: Failed to create inventory item: " . $e->getMessage() . "\n";
            $errorProducts++;
        }
    }
    
    echo "\n";
    echo "=== SUMMARY ===\n";
    echo "Total products processed: {$totalProducts}\n";
    echo "Successfully synced: {$syncedProducts}\n";
    echo "Skipped (empty SKU or already exists): {$skippedProducts}\n";
    echo "Errors: {$errorProducts}\n";
    echo "</pre>\n";
    
    // Also fix specific product ID 323 if mentioned
    if (isset($_GET['fix_id'])) {
        $fixId = (int)$_GET['fix_id'];
        echo "<h3>Fixing specific product ID: {$fixId}</h3>\n";
        echo "<pre>\n";
        
        $fixStmt = $db->prepare("SELECT * FROM supplier_catalog WHERE id = :id AND COALESCE(is_deleted, 0) = 0");
        $fixStmt->execute([':id' => $fixId]);
        $fixCat = $fixStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($fixCat) {
            $sku = trim($fixCat['sku'] ?? '');
            $supplierId = (int)$fixCat['supplier_id'];
            
            if (empty($sku)) {
                echo "ERROR: Product ID {$fixId} has empty SKU. Cannot create inventory item.\n";
            } else {
                // Check if exists
                $checkStmt = $db->prepare("SELECT id FROM inventory WHERE sku = :sku AND supplier_id = :sid LIMIT 1");
                $checkStmt->execute([':sku' => $sku, ':sid' => $supplierId]);
                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    $invId = (int)$existing['id'];
                    echo "Inventory item already exists: ID {$invId}\n";
                    
                    // Update link
                    $updateStmt = $db->prepare("UPDATE supplier_catalog SET source_inventory_id = :inv_id WHERE id = :cat_id");
                    $updateStmt->execute([':inv_id' => $invId, ':cat_id' => $fixId]);
                    echo "Updated source_inventory_id link.\n";
                } else {
                    // Create it
                    try {
                        $db->beginTransaction();
                        
                        $inventory->sku = $sku;
                        $inventory->name = $fixCat['name'] ?? '';
                        $inventory->description = $fixCat['description'] ?? '';
                        $inventory->quantity = 0;
                        $inventory->reorder_threshold = isset($fixCat['reorder_threshold']) ? (int)$fixCat['reorder_threshold'] : 10;
                        $inventory->category = $fixCat['category'] ?? '';
                        $inventory->unit_price = isset($fixCat['unit_price']) ? (float)$fixCat['unit_price'] : 0.0;
                        $inventory->location = $fixCat['location'] ?? '';
                        $inventory->supplier_id = $supplierId;
                        
                        if ($inventory->createForSupplier($supplierId)) {
                            $newId = (int)$db->lastInsertId();
                            $updateStmt = $db->prepare("UPDATE supplier_catalog SET source_inventory_id = :inv_id WHERE id = :cat_id");
                            $updateStmt->execute([':inv_id' => $newId, ':cat_id' => $fixId]);
                            
                            // Sync variations
                            $variants = $spVariation->getByProduct($fixId);
                            if (is_array($variants)) {
                                foreach ($variants as $vr) {
                                    $variation = $vr['variation'] ?? '';
                                    if ($variation === '') { continue; }
                                    $unitType = $vr['unit_type'] ?? ($fixCat['unit_type'] ?? 'per piece');
                                    $price = isset($vr['unit_price']) && $vr['unit_price'] !== null ? (float)$vr['unit_price'] : null;
                                    if (method_exists($invVariation, 'createVariant')) {
                                        $invVariation->createVariant($newId, $variation, $unitType, 0, $price);
                                    }
                                }
                            }
                            
                            $db->commit();
                            echo "✓ Created inventory item ID {$newId} for product ID {$fixId}\n";
                        } else {
                            $db->rollBack();
                            echo "✗ Failed to create inventory item\n";
                        }
                    } catch (Exception $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        echo "✗ Error: " . $e->getMessage() . "\n";
                    }
                }
            }
        } else {
            echo "Product ID {$fixId} not found or is deleted.\n";
        }
        echo "</pre>\n";
    }
    
} catch (Exception $e) {
    echo "<pre>FATAL ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "</pre>\n";
}
?>

