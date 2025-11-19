<?php
// ====== Access control & dependencies (corrected) ======
include_once '../config/session.php';   // namespaced sessions (admin/staff)
require_once '../config/database.php';  // DB connection

// Load all model classes this page uses
require_once '../models/inventory.php';
require_once '../models/supplier.php';
require_once '../models/order.php';
require_once '../models/admin_order.php';  // Add AdminOrder for admin_orders table
require_once '../models/payment.php';
require_once '../models/sales_transaction.php';
require_once '../models/alert_log.php';
require_once '../models/notification.php';
// Include supplier catalog and variations to mirror into admin inventory
require_once '../models/supplier_catalog.php';
require_once '../models/supplier_product_variation.php';
require_once '../models/inventory_variation.php';
require_once '../models/stock_calculator.php';

// ---- Admin auth guard (namespaced) ----
if (empty($_SESSION['admin']['user_id'])) {
    header("Location: ../login.php");
    exit();
}
if (($_SESSION['admin']['role'] ?? null) !== 'management') {
    header("Location: ../login.php");
    exit();
}
$adminId = (int)($_SESSION['admin']['user_id'] ?? 0);

// ---- Instantiate dependencies ----
$db        = (new Database())->getConnection();
$inventory = new Inventory($db);
$supplier  = new Supplier($db);
$order     = new Order($db);          // For orders table (supplier orders)
$adminOrder = new AdminOrder($db);   // For admin_orders table (admin orders)
$payment   = new Payment($db);
$notification = new Notification($db);
// Models for mirroring supplier catalog items to admin inventory
$catalog      = new SupplierCatalog($db);
$spVariation  = new SupplierProductVariation($db);
$invVariation = new InventoryVariation($db);
$stockCalculator = new StockCalculator($db);

$message     = '';
$messageType = '';
$gcashRedirectUrl = '';

// ===== Validate supplier_id parameter =====
if (!isset($_GET['supplier_id'])) {
    header("Location: suppliers.php");
    exit();
}
$supplier_id = (int)$_GET['supplier_id'];

// Fetch supplier details
$supplier->id = $supplier_id;
if (!method_exists($supplier, 'readOne') || !$supplier->readOne()) {
    header("Location: suppliers.php");
    exit();
}

// ===== Preflight sync: mirror supplier_catalog -> inventory (so admin can order) =====
// CRITICAL: This ensures ALL products from supplier/products.php are mirrored to inventory BEFORE orders are placed
// This sync runs on every page load to ensure products are always available for ordering
try {
    if (method_exists($catalog, 'readBySupplier')) {
        $catStmt = $catalog->readBySupplier($supplier_id);
        $catalogItems = $catStmt ? $catStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        
        foreach ($catalogItems as $cat) {
            $sku = trim($cat['sku'] ?? '');
            $catalogId = (int)($cat['id'] ?? 0);
            
            // CRITICAL: Skip if SKU is empty - products without SKU cannot be ordered
            if (empty($sku) || $sku === '') {
                error_log("Warning: Skipping supplier_catalog item ID {$catalogId} - SKU is empty");
                continue;
            }
            
            // Check if inventory already has this SKU for supplier (only active items)
            $checkStmt = $db->prepare("SELECT id FROM inventory WHERE sku = :sku AND supplier_id = :sid AND COALESCE(is_deleted, 0) = 0 AND sku IS NOT NULL AND sku != '' LIMIT 1");
            $checkStmt->execute([':sku' => $sku, ':sid' => $supplier_id]);
            $existingInv = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingInv) {
                // Inventory item exists - update source_inventory_id link if needed
                $existingInvId = (int)$existingInv['id'];
                if (empty($cat['source_inventory_id'])) {
                    try {
                        $updateLink = $db->prepare("UPDATE supplier_catalog SET source_inventory_id = :inv_id WHERE id = :cat_id");
                        $updateLink->execute([':inv_id' => $existingInvId, ':cat_id' => $catalogId]);
                    } catch (Exception $e) {
                        error_log("Warning: Could not update source_inventory_id for catalog ID {$catalogId}: " . $e->getMessage());
                    }
                }
                continue; // Skip - already exists
            }
            
            // Check if SKU exists for THIS supplier (not globally, since SKUs can be unique per supplier)
            // The unique constraint is on (supplier_id, sku), so same SKU can exist for different suppliers
            $skuCheckStmt = $db->prepare("SELECT id, is_deleted FROM inventory WHERE sku = :sku AND supplier_id = :sid LIMIT 1");
            $skuCheckStmt->execute([':sku' => $sku, ':sid' => $supplier_id]);
            $skuRow = $skuCheckStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($skuRow) {
                // SKU exists for this supplier - restore if soft-deleted and update link
                $found_id = (int)$skuRow['id'];
                $is_deleted = (int)($skuRow['is_deleted'] ?? 0);
                
                if ($is_deleted) {
                    // Restore soft-deleted item
                    try {
                        $restoreStmt = $db->prepare("UPDATE inventory SET is_deleted = 0 WHERE id = :id");
                        $restoreStmt->execute([':id' => $found_id]);
                        error_log("Restored soft-deleted inventory item ID {$found_id} for SKU {$sku}");
                    } catch (Exception $e) {
                        error_log("Warning: Could not restore soft-deleted inventory item: " . $e->getMessage());
                    }
                }
                
                // Update source_inventory_id link if needed
                if (empty($cat['source_inventory_id']) || (int)$cat['source_inventory_id'] !== $found_id) {
                    try {
                        $updateLink = $db->prepare("UPDATE supplier_catalog SET source_inventory_id = :inv_id WHERE id = :cat_id");
                        $updateLink->execute([':inv_id' => $found_id, ':cat_id' => $catalogId]);
                    } catch (Exception $e) {
                        error_log("Warning: Could not update source_inventory_id for catalog ID {$catalogId}: " . $e->getMessage());
                    }
                }
                continue; // Skip - already exists for this supplier
            }

            // Create mirrored inventory item linked to this supplier
            // Note: SKU can exist for other suppliers - that's OK due to unique constraint on (supplier_id, sku)
            // This ensures products from supplier/products.php are immediately available for ordering
            $inventory->sku               = $sku;
            $inventory->name              = $cat['name'] ?? '';
            $inventory->description       = $cat['description'] ?? '';
            $inventory->quantity          = 0; // stock managed via orders/deliveries
            $inventory->reorder_threshold = isset($cat['reorder_threshold']) ? (int)$cat['reorder_threshold'] : 10;
            $inventory->category          = $cat['category'] ?? '';
            $inventory->unit_price        = isset($cat['unit_price']) ? (float)$cat['unit_price'] : 0.0;
            $inventory->location          = $cat['location'] ?? '';
            $inventory->supplier_id       = $supplier_id;

            $createdInv = method_exists($inventory, 'createForSupplier')
                ? $inventory->createForSupplier($supplier_id)
                : $inventory->create();
                
            if (!$createdInv) {
                error_log("Warning: Failed to create inventory item for SKU {$sku} from supplier_catalog ID {$catalogId}");
                continue;
            }
            
            $newInventoryId = (int)$db->lastInsertId();
            
            // Update supplier_catalog with source_inventory_id for future reference
            try {
                $updateLink = $db->prepare("UPDATE supplier_catalog SET source_inventory_id = :inv_id WHERE id = :cat_id");
                $updateLink->execute([':inv_id' => $newInventoryId, ':cat_id' => $catalogId]);
            } catch (Exception $e) {
                error_log("Warning: Could not update source_inventory_id: " . $e->getMessage());
            }

            // Mirror variations from supplier_product_variations into inventory_variations
            // This ensures all variations, prices, and unit types are synced
            if (method_exists($spVariation, 'getByProduct')) {
                $variants = $spVariation->getByProduct($catalogId);
                if (is_array($variants)) {
                    foreach ($variants as $vr) {
                        $variation = $vr['variation'] ?? '';
                        if ($variation === '') { continue; }
                        $unitType  = $vr['unit_type'] ?? ($cat['unit_type'] ?? 'per piece');
                        // Sync price from supplier_product_variations (can be null, 0, or any float)
                        $price = isset($vr['unit_price']) && $vr['unit_price'] !== null ? (float)$vr['unit_price'] : null;
                        if (method_exists($invVariation, 'createVariant')) {
                            $invVariation->createVariant($newInventoryId, $variation, $unitType, 0, $price);
                        }
                    }
                }
            }
            
            error_log("Success: Synced product '{$cat['name']}' (SKU: {$sku}) from supplier_catalog to inventory - Product is now available for ordering");
        }
    }
} catch (Throwable $e) {
    // Log error but don't fail - best-effort sync
    error_log("ERROR: Preflight sync failed in supplier_details.php: " . $e->getMessage());
    error_log("ERROR: Stack trace: " . $e->getTraceAsString());
}

// Pricing helpers for unit/size variations
function isNails($name, $category) {
    $n = strtolower($name ?? '');
    $c = strtolower($category ?? '');
    return (strpos($n, 'nail') !== false) || (strpos($c, 'nail') !== false);
}
function getSizeFactor($size) {
    $map = [
        '1.5mm' => 0.9,
        '2mm'   => 0.95,
        '2.5mm' => 1.0,
        '3mm'   => 1.1,
        '4mm'   => 1.25,
        '5mm'   => 1.4,
    ];
    return $map[$size] ?? 1.0;
}
function computeEffectivePrice($basePrice, $name, $category, $unitType, $size) {
    $price = (float)$basePrice;
    if (isNails($name, $category) && strtolower($unitType) === 'per kilo' && !empty($size)) {
        $price = $basePrice * getSizeFactor($size);
    }
    return $price;
}

// ===== Handle form submissions =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // 1) Place orders from cart data
    if (
        $_POST['action'] === 'place_order' &&
        isset($_POST['cart_data']) &&
        isset($_POST['payment_method'])
    ) {
        if ($adminId <= 0) {
            $message     = 'Your admin session is missing. Please log in again.';
            $messageType = 'danger';
        } else {
            $payment_method = trim($_POST['payment_method']);
            $transaction_reference = trim($_POST['transaction_reference'] ?? '');
            $gcash_number_input = trim($_POST['gcash_number'] ?? '');
            $cart_data = json_decode($_POST['cart_data'], true);
            
            // Validate payment method
            if (!in_array($payment_method, ['cash', 'gcash'])) {
                $message     = 'Invalid payment method selected.';
                $messageType = 'danger';
            } elseif (empty($cart_data) || !is_array($cart_data)) {
                $message     = 'Cart is empty or invalid.';
                $messageType = 'danger';
            } else {
                // Begin atomic transaction for order + payment + inventory updates
                $db->beginTransaction();
                $total_amount = 0;
                $order_ids = [];
                $ordered_items = [];
                
                foreach ($cart_data as $cart_item) {
                    $catalog_id = (int)$cart_item['id']; // This is supplier_catalog id from the UI (matching supplier/products.php)
                    $qty        = (int)$cart_item['quantity'];
                    $variation  = isset($cart_item['variation']) ? trim($cart_item['variation']) : '';
                    $unit_type  = isset($cart_item['unit_type']) ? trim($cart_item['unit_type']) : 'per piece';

                    if ($qty <= 0) {
                        continue; // skip zero / negative entries
                    }

                    // Get supplier catalog product details (matching supplier/products.php)
                    $cat_stmt = $db->prepare("SELECT id, name, category, unit_price, unit_type, source_inventory_id, sku FROM supplier_catalog WHERE id = :id AND supplier_id = :sid AND is_deleted = 0");
                    $cat_stmt->execute([':id' => $catalog_id, ':sid' => $supplier_id]);
                    $cat_data = $cat_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$cat_data) {
                        $message     = "Product ID {$catalog_id} not found or does not belong to this supplier.";
                        $messageType = 'danger';
                        break;
                    }
                    
                    // Find or get inventory_id for this supplier catalog product
                    $inventory_id = null;
                    
                    // First check if cart provides inventory_id
                    if (isset($cart_item['inventory_id']) && $cart_item['inventory_id']) {
                        $inventory_id = (int)$cart_item['inventory_id'];
                        // Verify it exists and belongs to the supplier
                        $verify_stmt = $db->prepare("SELECT id FROM inventory WHERE id = :id AND supplier_id = :sid LIMIT 1");
                        $verify_stmt->execute([':id' => $inventory_id, ':sid' => $supplier_id]);
                        if (!$verify_stmt->fetch(PDO::FETCH_ASSOC)) {
                            $inventory_id = null; // Invalid, reset
                        }
                    }
                    
                    // If not from cart, try source_inventory_id (existing mapping)
                    if (!$inventory_id) {
                        $inventory_id = $cat_data['source_inventory_id'];
                    }
                    
                    // If no source_inventory_id, find by SKU and supplier (including soft-deleted items to restore them)
                    if (!$inventory_id) {
                        $sku = trim($cat_data['sku'] ?? '');
                        if (!empty($sku)) {
                            // First try to find active item
                            $sku_stmt = $db->prepare("SELECT id, is_deleted FROM inventory WHERE sku = :sku AND supplier_id = :sid LIMIT 1");
                            $sku_stmt->execute([':sku' => $sku, ':sid' => $supplier_id]);
                            $sku_row = $sku_stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($sku_row) {
                                $found_id = (int)$sku_row['id'];
                                $is_deleted = (int)($sku_row['is_deleted'] ?? 0);
                                
                                if ($is_deleted) {
                                    // Item exists but is soft-deleted - restore it
                                    try {
                                        $restoreStmt = $db->prepare("UPDATE inventory SET is_deleted = 0 WHERE id = :id");
                                        $restoreStmt->execute([':id' => $found_id]);
                                        $inventory_id = $found_id;
                                        error_log("Restored soft-deleted inventory item ID {$found_id} for SKU {$sku}");
                                    } catch (Throwable $e) {
                                        error_log("Warning: Could not restore soft-deleted inventory item: " . $e->getMessage());
                                        $inventory_id = null;
                                    }
                                } else {
                                    $inventory_id = $found_id;
                                }
                            }
                        }
                    }
                    
                    // If inventory_id still not found, try to create it from supplier catalog (best-effort sync)
                    // This ensures products from supplier/products.php are available even if preflight sync missed them
                    if (!$inventory_id) {
                        $sku = trim($cat_data['sku'] ?? '');
                        
                        // CRITICAL: If SKU is empty, we cannot create inventory item - products must have SKU
                        if (empty($sku) || $sku === '') {
                            $message     = "Product '{$cat_data['name']}' has no SKU. Please add a SKU in supplier/products.php before ordering.";
                            $messageType = 'danger';
                            error_log("ERROR: Cannot create order for product '{$cat_data['name']}' (Catalog ID: {$catalog_id}) - SKU is empty");
                            break;
                        }
                        
                        try {
                            // Check if SKU exists for THIS supplier (not globally, since SKUs can be unique per supplier)
                            // The unique constraint is on (supplier_id, sku), so same SKU can exist for different suppliers
                            $skuCheckStmt = $db->prepare("SELECT id, is_deleted FROM inventory WHERE sku = :sku AND supplier_id = :sid LIMIT 1");
                            $skuCheckStmt->execute([':sku' => $sku, ':sid' => $supplier_id]);
                            $skuRow = $skuCheckStmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($skuRow) {
                                // SKU exists for this supplier - use it (restore if soft-deleted)
                                $found_id = (int)$skuRow['id'];
                                $is_deleted = (int)($skuRow['is_deleted'] ?? 0);
                                
                                if ($is_deleted) {
                                    // Restore soft-deleted item
                                    try {
                                        $restoreStmt = $db->prepare("UPDATE inventory SET is_deleted = 0 WHERE id = :id");
                                        $restoreStmt->execute([':id' => $found_id]);
                                        $inventory_id = $found_id;
                                        error_log("Restored soft-deleted inventory item ID {$found_id} for SKU {$sku}");
                                    } catch (Throwable $e) {
                                        error_log("Warning: Could not restore soft-deleted inventory item: " . $e->getMessage());
                                        $inventory_id = null;
                                    }
                                } else {
                                    $inventory_id = $found_id;
                                }
                                
                                // Update source_inventory_id link
                                if ($inventory_id) {
                                    try {
                                        $update_cat = $db->prepare("UPDATE supplier_catalog SET source_inventory_id = :inv_id WHERE id = :cat_id");
                                        $update_cat->execute([':inv_id' => $inventory_id, ':cat_id' => $catalog_id]);
                                    } catch (Throwable $e) {
                                        error_log("Warning: Could not update source_inventory_id: " . $e->getMessage());
                                    }
                                }
                            } else {
                                // SKU doesn't exist for this supplier - create new inventory item
                                // Note: SKU can exist for other suppliers - that's OK due to unique constraint on (supplier_id, sku)
                                $inventory->sku = $sku;
                                $inventory->name = $cat_data['name'];
                                $inventory->description = $cat_data['description'] ?? '';
                                $inventory->category = $cat_data['category'] ?? '';
                                $inventory->unit_price = isset($cat_data['unit_price']) ? (float)$cat_data['unit_price'] : 0.0;
                                $inventory->quantity = 0;
                                $inventory->reorder_threshold = isset($cat_data['reorder_threshold']) ? (int)$cat_data['reorder_threshold'] : 10;
                                $inventory->location = $cat_data['location'] ?? '';
                                $inventory->supplier_id = $supplier_id;
                                
                                if ($inventory->createForSupplier($supplier_id)) {
                                    $new_inventory_id = (int)$db->lastInsertId();
                                    
                                    // CRITICAL: Verify the inventory item was actually created
                                    if ($new_inventory_id <= 0) {
                                        error_log("ERROR: createForSupplier returned true but lastInsertId is invalid ({$new_inventory_id}) for SKU {$sku}");
                                        $inventory_id = null;
                                    } else {
                                        // Verify the item exists in the database
                                        $verifyStmt = $db->prepare("SELECT id FROM inventory WHERE id = :id AND COALESCE(is_deleted, 0) = 0 LIMIT 1");
                                        $verifyStmt->execute([':id' => $new_inventory_id]);
                                        $verifyRow = $verifyStmt->fetch(PDO::FETCH_ASSOC);
                                        
                                        if ($verifyRow) {
                                            $inventory_id = $new_inventory_id;
                                            
                                            // Update supplier_catalog with source_inventory_id
                                            try {
                                                $update_cat = $db->prepare("UPDATE supplier_catalog SET source_inventory_id = :inv_id WHERE id = :cat_id");
                                                $update_cat->execute([':inv_id' => $inventory_id, ':cat_id' => $catalog_id]);
                                            } catch (Throwable $e) {
                                                error_log("Warning: Could not update source_inventory_id: " . $e->getMessage());
                                            }
                                            
                                            // Sync variations from supplier_product_variations
                                            if (method_exists($spVariation, 'getByProduct')) {
                                                $variants = $spVariation->getByProduct($catalog_id);
                                                if (is_array($variants)) {
                                                    foreach ($variants as $vr) {
                                                        $variation = $vr['variation'] ?? '';
                                                        if ($variation === '') { continue; }
                                                        $unitType = $vr['unit_type'] ?? ($cat_data['unit_type'] ?? 'per piece');
                                                        $price = isset($vr['unit_price']) && $vr['unit_price'] !== null ? (float)$vr['unit_price'] : null;
                                                        if (method_exists($invVariation, 'createVariant')) {
                                                            $invVariation->createVariant($inventory_id, $variation, $unitType, 0, $price);
                                                        }
                                                    }
                                                }
                                            }
                                            
                                            error_log("Success: Created inventory item for product '{$cat_data['name']}' (SKU: {$sku}, Inventory ID: {$inventory_id}) during order placement - Product is now available");
                                        } else {
                                            error_log("ERROR: Inventory item created (ID: {$new_inventory_id}) but not found in database for SKU {$sku}");
                                            $inventory_id = null;
                                        }
                                    }
                                } else {
                                    error_log("ERROR: Failed to create inventory item for SKU {$sku} during order placement - createForSupplier returned false");
                                    $inventory_id = null;
                                }
                            }
                        } catch (Throwable $e) {
                            error_log("ERROR: Could not create inventory item during order placement: " . $e->getMessage());
                            error_log("ERROR: Stack trace: " . $e->getTraceAsString());
                        }
                    }
                    
                    // If inventory_id is still not found, the order cannot be created
                    if (!$inventory_id) {
                        $sku = trim($cat_data['sku'] ?? '');
                        if (empty($sku)) {
                            $message     = "Product '{$cat_data['name']}' has no SKU. Please add a SKU in supplier/products.php before ordering.";
                        } else {
                            $message     = "Inventory item not found for product '{$cat_data['name']}' (SKU: {$sku}). The product may not be synced to inventory yet. Please refresh the page and try again.";
                        }
                        $messageType = 'danger';
                        error_log("ERROR: Cannot create order - inventory_id not found for product '{$cat_data['name']}' (Catalog ID: {$catalog_id}, SKU: " . ($sku ?: 'EMPTY') . ")");
                        break;
                    }
                    
                    // Update supplier_catalog with source_inventory_id if not set (link existing inventory)
                    if (!$cat_data['source_inventory_id']) {
                        try {
                            $update_cat = $db->prepare("UPDATE supplier_catalog SET source_inventory_id = :inv_id WHERE id = :cat_id");
                            $update_cat->execute([':inv_id' => $inventory_id, ':cat_id' => $catalog_id]);
                        } catch (Throwable $e) {
                            // Non-fatal - continue with order
                            error_log("Warning: Could not update source_inventory_id: " . $e->getMessage());
                        }
                    }
                    
                    // Get item details for price calculation (from inventory for consistency)
                    // CRITICAL: Also check is_deleted to ensure item is active
                    $item_stmt = $db->prepare("SELECT name, category, unit_price FROM inventory WHERE id = :id AND COALESCE(is_deleted, 0) = 0");
                    $item_stmt->execute([':id' => $inventory_id]);
                    $item_data = $item_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$item_data) {
                        $sku = trim($cat_data['sku'] ?? '');
                        $productName = $cat_data['name'] ?? 'Unknown';
                        error_log("ERROR: Inventory item not found after creation/verification. Inventory ID: {$inventory_id}, Catalog ID: {$catalog_id}, SKU: " . ($sku ?: 'EMPTY') . ", Product: {$productName}");
                        
                        // Try to get more details about why it failed
                        $debugStmt = $db->prepare("SELECT id, sku, name, is_deleted FROM inventory WHERE id = :id");
                        $debugStmt->execute([':id' => $inventory_id]);
                        $debugRow = $debugStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($debugRow) {
                            $isDeleted = (int)($debugRow['is_deleted'] ?? 0);
                            if ($isDeleted) {
                                $message = "Inventory item for product '{$productName}' (ID: {$inventory_id}) was found but is marked as deleted. Please contact support.";
                            } else {
                                $message = "Inventory item for product '{$productName}' (ID: {$inventory_id}) exists but query failed. Please refresh and try again.";
                            }
                        } else {
                            $message = "Failed to find inventory item for product '{$productName}' (Catalog ID: {$catalog_id}). The item may not have been created successfully. Please refresh the page and try again.";
                        }
                        $messageType = 'danger';
                        break;
                    }

                    // Variation details are already extracted above
                    $unitType = $unit_type;
                    $size = $variation;
                    
                    // Get price: prioritize cart price, then supplier variation price, then inventory variation price, then base price
                    if (isset($cart_item['price']) && $cart_item['price'] > 0) {
                        $effective_price = (float)$cart_item['price'];
                    } else {
                        // Try to get variation-specific price from supplier_product_variations (matching supplier/products.php)
                        if (!empty($size)) {
                            $varPriceStmt = $db->prepare("SELECT unit_price FROM supplier_product_variations WHERE product_id = :cat_id AND variation = :var LIMIT 1");
                            $varPriceStmt->execute([':cat_id' => $catalog_id, ':var' => $size]);
                            $varPriceRow = $varPriceStmt->fetch(PDO::FETCH_ASSOC);
                            if ($varPriceRow && $varPriceRow['unit_price'] !== null && $varPriceRow['unit_price'] !== '') {
                                $effective_price = (float)$varPriceRow['unit_price'];
                            } else {
                                // Try inventory_variations as fallback
                                $invVarPriceStmt = $db->prepare("SELECT unit_price FROM inventory_variations WHERE inventory_id = :inv_id AND variation = :var AND unit_type = :ut LIMIT 1");
                                $invVarPriceStmt->execute([':inv_id' => $inventory_id, ':var' => $size, ':ut' => $unitType]);
                                $invVarPriceRow = $invVarPriceStmt->fetch(PDO::FETCH_ASSOC);
                                if ($invVarPriceRow && $invVarPriceRow['unit_price'] !== null && $invVarPriceRow['unit_price'] !== '') {
                                    $effective_price = (float)$invVarPriceRow['unit_price'];
                                } else {
                                    // Final fallback: compute from base price
                                    $effective_price = computeEffectivePrice((float)$item_data['unit_price'], $item_data['name'] ?? '', $item_data['category'] ?? '', $unitType, $size);
                                }
                            }
                        } else {
                            // No variation - use base price
                            $effective_price = (float)$item_data['unit_price'];
                        }
                    }
                    
                    // Do NOT create variations during order creation - they should already exist in inventory
                    // Variations will be created/updated only when orders are completed in orders.php
                    // This prevents duplicates and ensures proper stock tracking

                    // Validate that nails require a specific size variation
                    $isNailsItem = (strpos(strtolower($item_data['name'] ?? ''), 'nail') !== false) || (strpos(strtolower($item_data['category'] ?? ''), 'nail') !== false);
                    if ($isNailsItem && $size === '') {
                        $message     = 'Please select a valid size variation for this item.';
                        $messageType = 'danger';
                        break;
                    }

                    // Note: Supplier orders are allowed regardless of current admin inventory stock.
                    // We skip stock validation here to support backorders and restocking workflows.

                    // Create the order in BOTH tables:
                    // 1. orders table (for supplier orders - supplier can see and manage)
                    // 2. admin_orders table (for admin orders - admin can see and delete without affecting supplier)
                    
                    // Set order data for both models
                    $orderData = [
                        'inventory_id' => $inventory_id,
                        'quantity' => $qty,
                        'supplier_id' => $supplier_id,
                        'user_id' => $adminId,
                        'unit_price' => $effective_price,
                        'unit_type' => $unitType,
                        'variation' => $size,
                        'is_automated' => 0,
                        'confirmation_status' => 'pending'
                    ];
                    
                    // Create order in orders table (for suppliers)
                    $order->inventory_id        = $orderData['inventory_id'];
                    $order->quantity            = $orderData['quantity'];
                    $order->supplier_id         = $orderData['supplier_id'];
                    $order->user_id             = $orderData['user_id'];
                    $order->unit_price          = $orderData['unit_price'];
                    $order->unit_type           = $orderData['unit_type'];
                    $order->variation           = $orderData['variation'];
                    $order->is_automated        = $orderData['is_automated'];
                    $order->confirmation_status = $orderData['confirmation_status'];

                    $order_id = $order->create();
                    if (!$order_id) {
                        $message     = "Failed to create order in orders table for product ID {$catalog_id}.";
                        $messageType = 'danger';
                        break;
                    }
                    
                    // Create the same order in admin_orders table (for admin)
                    // IMPORTANT: Both orders must be created for data consistency
                    $adminOrder->inventory_id        = $orderData['inventory_id'];
                    $adminOrder->quantity            = $orderData['quantity'];
                    $adminOrder->supplier_id         = $orderData['supplier_id'];
                    $adminOrder->user_id             = $orderData['user_id'];
                    $adminOrder->unit_price          = $orderData['unit_price'];
                    $adminOrder->unit_type           = $orderData['unit_type'];
                    $adminOrder->variation           = $orderData['variation'];
                    $adminOrder->is_automated        = $orderData['is_automated'];
                    $adminOrder->confirmation_status = $orderData['confirmation_status'];
                    
                    $admin_order_id = $adminOrder->create();
                    if (!$admin_order_id) {
                        // If admin_orders creation fails, rollback the transaction
                        // Both tables must have the same orders for consistency
                        $message     = "Failed to create order in admin_orders table for product ID {$catalog_id}. Order creation cancelled.";
                        $messageType = 'danger';
                        error_log("Error: Failed to create order in admin_orders table. Rolling back transaction. orders table ID was: $order_id");
                        break;
                    } else {
                        error_log("Order created successfully in both tables - orders table ID: $order_id, admin_orders table ID: $admin_order_id");
                    }
                    
                    // Create notification for supplier about new order
                    try {
                        $selectedItemName = $cat_data['name'] ?? 'Item';
                        $decoratedName = $selectedItemName;
                        if (!empty($orderData['unit_type']) || !empty($orderData['variation'])) {
                            $parts = [];
                            if (!empty($orderData['unit_type'])) { $parts[] = $orderData['unit_type']; }
                            if (!empty($orderData['variation'])) { $parts[] = $orderData['variation']; }
                            $decoratedName = $selectedItemName . ' (' . implode(' / ', $parts) . ')';
                        }
                        
                        $notification->createOrderNotification(
                            $order_id,
                            $supplier_id,
                            $decoratedName,
                            $qty
                        );
                        error_log("Notification created for supplier {$supplier_id} about order {$order_id}");
                    } catch (Throwable $e) {
                        error_log("Warning: Failed to create notification for order {$order_id}: " . $e->getMessage());
                        // Non-fatal - continue with order processing
                    }
                    
                    $order_ids[] = $order_id;
                    $ordered_items[] = ['id' => $inventory_id, 'qty' => $qty];
                    $total_amount += $effective_price * $qty;
                }
                
                // Create payment record if orders were successful
                if (!empty($message)) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                } else if (!empty($order_ids)) {
                    // If GCash selected, initialize PayMongo intent and attach payment method
                    $redirectUrl = '';
                    if ($payment_method === 'gcash') {
                        // Normalize and validate GCash number to +639xxxxxxxxx
                        $raw = preg_replace('/\D+/', '', $gcash_number_input);
                        if (strlen($raw) === 11 && substr($raw,0,2) === '09') {
                            $gcash_e164 = '+63' . substr($raw,1);
                        } elseif (strlen($raw) === 10 && $raw[0] === '9') {
                            $gcash_e164 = '+63' . $raw;
                        } elseif (strlen($raw) === 12 && substr($raw,0,3) === '639') {
                            $gcash_e164 = '+' . $raw;
                        } elseif (strlen($gcash_number_input) === 13 && strpos($gcash_number_input, '+639') === 0) {
                            $gcash_e164 = $gcash_number_input;
                        } else {
                            if ($db->inTransaction()) { $db->rollBack(); }
                            $message     = 'Invalid GCash number format. Please enter 0917xxxxxxx or +63917xxxxxxx.';
                            $messageType = 'danger';
                        }

                        if (empty($message)) {
                            $paymongoSecret = 'sk_test_yLEJ3PfLqCttJeGtDvwhavP2';
                            $apiBase = 'https://api.paymongo.com/v1';
                            $amountCents = (int)round($total_amount * 100);
                            
                            // 1) Create Payment Intent
                            $piPayload = json_encode([
                                'data' => [
                                    'attributes' => [
                                        'amount' => $amountCents,
                                        'currency' => 'PHP',
                                        'payment_method_allowed' => ['gcash'],
                                        'capture_type' => 'automatic'
                                    ]
                                ]
                            ]);
                            $ch = curl_init($apiBase . '/payment_intents');
                            curl_setopt_array($ch, [
                                CURLOPT_POST => true,
                                CURLOPT_POSTFIELDS => $piPayload,
                                CURLOPT_HTTPHEADER => [
                                    'Content-Type: application/json',
                                    'Accept: application/json',
                                    'Authorization: Basic ' . base64_encode($paymongoSecret . ':')
                                ],
                                CURLOPT_RETURNTRANSFER => true
                            ]);
                            $piRespRaw = curl_exec($ch);
                            $piHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            $piErr = curl_error($ch);
                            curl_close($ch);
                            $piResp = json_decode($piRespRaw, true);
                            if ($piHttpCode >= 400 || empty($piResp['data']['id'])) {
                                if ($db->inTransaction()) { $db->rollBack(); }
                                $message     = 'Failed to initialize GCash payment. ' . ($piErr ?: ($piResp['errors'][0]['detail'] ?? ''));
                                $messageType = 'danger';
                            } else {
                                $piId = $piResp['data']['id'];
                                
                                // 2) Create Payment Method (GCash)
                                $pmPayload = json_encode([
                                    'data' => [
                                        'attributes' => [
                                            'type' => 'gcash',
                                            'billing' => [
                                                'name' => $_SESSION['admin']['name'] ?? 'Admin',
                                                'email' => $_SESSION['admin']['email'] ?? 'admin@example.com',
                                                'phone' => $gcash_e164
                                            ]
                                        ]
                                    ]
                                ]);
                                $ch = curl_init($apiBase . '/payment_methods');
                                curl_setopt_array($ch, [
                                    CURLOPT_POST => true,
                                    CURLOPT_POSTFIELDS => $pmPayload,
                                    CURLOPT_HTTPHEADER => [
                                        'Content-Type: application/json',
                                        'Accept: application/json',
                                        'Authorization: Basic ' . base64_encode($paymongoSecret . ':')
                                    ],
                                    CURLOPT_RETURNTRANSFER => true
                                ]);
                                $pmRespRaw = curl_exec($ch);
                                $pmHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                $pmErr = curl_error($ch);
                                curl_close($ch);
                                $pmResp = json_decode($pmRespRaw, true);
                                if ($pmHttpCode >= 400 || empty($pmResp['data']['id'])) {
                                    if ($db->inTransaction()) { $db->rollBack(); }
                                    $message     = 'Failed to create GCash payment method. ' . ($pmErr ?: ($pmResp['errors'][0]['detail'] ?? ''));
                                    $messageType = 'danger';
                                } else {
                                    $pmId = $pmResp['data']['id'];
                                    
                                    // 3) Attach PM to PI and get redirect URL
                                    $returnUrl = (isset($_SERVER['HTTP_HOST']) ? (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] : 'http://localhost') .
                                        '/haha/admin/supplier_details.php?supplier_id=' . $supplier_id . '&payment_intent=' . urlencode($piId);
                                    $attachPayload = json_encode([
                                        'data' => [
                                            'attributes' => [
                                                'payment_method' => $pmId,
                                                'return_url' => $returnUrl
                                            ]
                                        ]
                                    ]);
                                    $ch = curl_init($apiBase . '/payment_intents/' . $piId . '/attach');
                                    curl_setopt_array($ch, [
                                        CURLOPT_POST => true,
                                        CURLOPT_POSTFIELDS => $attachPayload,
                                        CURLOPT_HTTPHEADER => [
                                            'Content-Type: application/json',
                                            'Accept: application/json',
                                            'Authorization: Basic ' . base64_encode($paymongoSecret . ':')
                                        ],
                                        CURLOPT_RETURNTRANSFER => true
                                    ]);
                                    $attRespRaw = curl_exec($ch);
                                    $attHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                    $attErr = curl_error($ch);
                                    curl_close($ch);
                                    $attResp = json_decode($attRespRaw, true);
                                    if ($attHttpCode >= 400 || empty($attResp['data']['attributes']['next_action']['redirect']['url'])) {
                                        if ($db->inTransaction()) { $db->rollBack(); }
                                        $message     = 'Failed to start GCash payment. ' . ($attErr ?: ($attResp['errors'][0]['detail'] ?? ''));
                                        $messageType = 'danger';
                                    } else {
                                        $redirectUrl = $attResp['data']['attributes']['next_action']['redirect']['url'];
                                        // Store PayMongo payment intent id as transaction reference
                                        $transaction_reference = $piId;
                                        $gcashRedirectUrl = $redirectUrl;
                                    }
                                }
                            }
                        }
                    }

                    if (empty($message)) {
                        // For multiple orders, create one payment record for the total
                        $payment->order_id = $order_ids[0]; // Link to first order
                        $payment->payment_method = $payment_method;
                        $payment->amount = $total_amount;
                        $payment->payment_status = ($payment_method === 'cash') ? 'completed' : 'pending';
                        $payment->transaction_reference = $transaction_reference;
                        
                        if ($payment->create()) {
                            // Note: Inventory stocks (including variations) will be updated when orders are marked as "Completed" in orders.php
                            // This allows proper tracking and ensures stocks only reflect completed/delivered orders
                            // Commit transaction after successful payment
                            $db->commit();
                            if ($payment_method === 'gcash' && !empty($gcashRedirectUrl)) {
                                // Automatically redirect to PayMongo GCash payment page
                                header('Location: ' . $gcashRedirectUrl);
                                exit();
                            } else {
                                $message     = 'Order and payment created successfully.';
                                $messageType = 'success';
                            }
                        } else {
                            // Roll back if payment record fails
                            if ($db->inTransaction()) { $db->rollBack(); }
                            $message     = 'Order created but payment record failed.';
                            $messageType = 'warning';
                        }
                    }
                } else {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    $message     = 'No items were ordered.';
                    $messageType = 'warning';
                }
            }
        }
    }
}

// ===== FORCE SYNC: Ensure ALL products from supplier_catalog are in inventory BEFORE display =====
// This ensures every product from supplier/products.php is available for ordering
try {
    $forceSyncStmt = $db->prepare("SELECT * FROM supplier_catalog WHERE supplier_id = :sid AND COALESCE(is_deleted, 0) = 0");
    $forceSyncStmt->execute([':sid' => $supplier_id]);
    $forceSyncProducts = $forceSyncStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($forceSyncProducts as $syncCat) {
        $syncSku = trim($syncCat['sku'] ?? '');
        $syncCatalogId = (int)$syncCat['id'];
        
        // Generate SKU if empty
        if (empty($syncSku)) {
            $generatedSku = 'AUTO-' . $supplier_id . '-' . $syncCatalogId;
            try {
                $updateSkuStmt = $db->prepare("UPDATE supplier_catalog SET sku = :sku WHERE id = :id");
                $updateSkuStmt->execute([':sku' => $generatedSku, ':id' => $syncCatalogId]);
                $syncSku = $generatedSku;
            } catch (Exception $e) {
                error_log("Warning: Could not generate SKU for catalog ID {$syncCatalogId}: " . $e->getMessage());
            }
        }
        
        if (!empty($syncSku)) {
            // Check if inventory item exists
            $syncCheckStmt = $db->prepare("SELECT id FROM inventory WHERE sku = :sku AND supplier_id = :sid LIMIT 1");
            $syncCheckStmt->execute([':sku' => $syncSku, ':sid' => $supplier_id]);
            $syncExisting = $syncCheckStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$syncExisting) {
                // Create inventory item if it doesn't exist
                try {
                    $inventory->sku = $syncSku;
                    $inventory->name = $syncCat['name'] ?? '';
                    $inventory->description = $syncCat['description'] ?? '';
                    $inventory->quantity = 0;
                    $inventory->reorder_threshold = isset($syncCat['reorder_threshold']) ? (int)$syncCat['reorder_threshold'] : 10;
                    $inventory->category = $syncCat['category'] ?? '';
                    $inventory->unit_price = isset($syncCat['unit_price']) ? (float)$syncCat['unit_price'] : 0.0;
                    $inventory->location = $syncCat['location'] ?? '';
                    $inventory->supplier_id = $supplier_id;
                    
                    if ($inventory->createForSupplier($supplier_id)) {
                        $newSyncInvId = (int)$db->lastInsertId();
                        if ($newSyncInvId > 0) {
                            // Update source_inventory_id
                            $updateLinkStmt = $db->prepare("UPDATE supplier_catalog SET source_inventory_id = :inv_id WHERE id = :cat_id");
                            $updateLinkStmt->execute([':inv_id' => $newSyncInvId, ':cat_id' => $syncCatalogId]);
                            
                            // Sync variations
                            $syncVariants = $spVariation->getByProduct($syncCatalogId);
                            if (is_array($syncVariants)) {
                                foreach ($syncVariants as $svr) {
                                    $syncVar = $svr['variation'] ?? '';
                                    if (empty($syncVar)) continue;
                                    $syncUnitType = $svr['unit_type'] ?? ($syncCat['unit_type'] ?? 'per piece');
                                    $syncPrice = isset($svr['unit_price']) && $svr['unit_price'] !== null ? (float)$svr['unit_price'] : null;
                                    if (method_exists($invVariation, 'createVariant')) {
                                        $invVariation->createVariant($newSyncInvId, $syncVar, $syncUnitType, 0, $syncPrice);
                                    }
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log("Warning: Force sync failed for catalog ID {$syncCatalogId}: " . $e->getMessage());
                }
            } else {
                // Update source_inventory_id link if missing
                $syncInvId = (int)$syncExisting['id'];
                if (empty($syncCat['source_inventory_id']) || (int)$syncCat['source_inventory_id'] !== $syncInvId) {
                    try {
                        $updateLinkStmt = $db->prepare("UPDATE supplier_catalog SET source_inventory_id = :inv_id WHERE id = :cat_id");
                        $updateLinkStmt->execute([':inv_id' => $syncInvId, ':cat_id' => $syncCatalogId]);
                    } catch (Exception $e) {
                        error_log("Warning: Could not update source_inventory_id for catalog ID {$syncCatalogId}: " . $e->getMessage());
                    }
                }
            }
        }
    }
} catch (Exception $e) {
    error_log("Warning: Force sync process failed: " . $e->getMessage());
}

// Fetch products from supplier_catalog (EXACTLY matching supplier/products.php database query)
// This connects supplier_details.php directly to the same database source as supplier/products.php
$items = [];
// Use direct SQL query matching supplier/products.php pattern: readBySupplier + filter is_deleted = 0
// This ensures both pages query the exact same data from the database
// CRITICAL: Show ALL products, even those without SKU (they will be handled during ordering)
$catStmt = $db->prepare("SELECT * FROM supplier_catalog WHERE supplier_id = :sid AND COALESCE(is_deleted, 0) = 0 ORDER BY name");
$catStmt->execute([':sid' => $supplier_id]);

// Process each supplier catalog product and attach variations (matching supplier/products.php exactly)
while ($row = $catStmt->fetch(PDO::FETCH_ASSOC)) {
    
    $catalogId = (int)$row['id'];
    
    // Get variations from supplier_product_variations (matching supplier/products.php)
    $varRows = $spVariation->getByProduct($catalogId);
    
    // Build variation data maps
    $variationList = [];
    $variationPricesMap = [];
    $variationStocksMap = [];
    $variationUnitTypesMap = [];
    
    foreach ($varRows as $vr) {
        $variationList[] = $vr['variation'];
        $price = isset($vr['unit_price']) && $vr['unit_price'] !== null && $vr['unit_price'] > 0 ? (float)$vr['unit_price'] : null;
        $variationPricesMap[$vr['variation']] = $price;
        $variationStocksMap[$vr['variation']] = isset($vr['stock']) ? (int)$vr['stock'] : 0;
        $variationUnitTypesMap[$vr['variation']] = $vr['unit_type'] ?? $row['unit_type'] ?? 'per piece';
    }
    
    // Map to structure compatible with existing UI code
    // Use supplier_catalog data (matching supplier/products.php)
    $items[] = [
        'id' => $catalogId, // supplier_catalog ID
        'name' => $row['name'],
        'category' => $row['category'] ?? '',
        'sku' => $row['sku'],
        'unit_price' => (float)$row['unit_price'],
        'quantity' => (int)$row['supplier_quantity'], // Supplier's stock (from supplier_catalog)
        'unit_type' => $row['unit_type'] ?? 'per piece',
        'description' => $row['description'] ?? '',
        'image_path' => $row['image_path'] ?? '',
        'image_url' => $row['image_url'] ?? '',
        'location' => $row['location'] ?? '',
        'reorder_threshold' => (int)($row['reorder_threshold'] ?? 10),
        'supplier_id' => $supplier_id,
        // Variation data
        'variations' => $variationList,
        'variation_prices' => $variationPricesMap,
        'variation_stocks' => $variationStocksMap,
        'variation_unittypes' => $variationUnitTypesMap,
        // Map to inventory_id for ordering (will be resolved during order creation)
        'inventory_id' => $row['source_inventory_id'] ?? null,
        'source_catalog_id' => $catalogId // Keep track of supplier_catalog ID
    ];
}

// Build categories for filter UI (preserve original case labels)
$categoriesSet = [];
foreach ($items as $it) {
    $cat = trim(strtolower($it['category'] ?? 'uncategorized'));
    if ($cat === '') $cat = 'uncategorized';
    $categoriesSet[$cat] = true;
}
$categories = [];
foreach ($categoriesSet as $cat => $_) {
    $label = ucfirst($cat);
    foreach ($items as $it2) {
        if (isset($it2['category']) && strtolower($it2['category']) === $cat) {
            $label = $it2['category'];
            break;
        }
    }
    $categories[] = ['key' => $cat, 'label' => $label];
}
usort($categories, fn($a,$b) => strcasecmp($a['label'],$b['label']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Supplier Details – Inventory & Stock Control</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" />
    <link href="../assets/css/style.css" rel="stylesheet" />
    <style>
        .cart-sidebar {
            position: fixed;
            top: 0;
            right: -400px;
            width: 400px;
            height: 100vh;
            background: white;
            box-shadow: -2px 0 10px rgba(0,0,0,0.1);
            transition: right 0.3s ease;
            z-index: 1050;
            overflow-y: auto;
        }
        .cart-sidebar.open {
            right: 0;
        }
        .cart-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1040;
            display: none;
        }
        .cart-overlay.show {
            display: block;
        }
        /* Align core card look & feel to Admin POS */
        .product-card {
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid #e3e6f0;
            border-radius: 0.35rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            margin-bottom: 1rem;
        }
        .product-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            border-color: #2470dc;
        }
        .product-img {
            width: 100%;
            height: 140px;
            object-fit: cover;
            border-top-left-radius: 0.35rem;
            border-top-right-radius: 0.35rem;
        }
        .cart-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        .quantity-controls .btn {
            width: 2rem;
            height: 2rem;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.875rem;
        }
        .quantity-controls input {
            width: 3rem;
            height: 2rem;
            text-align: center;
            border: 1px solid #e3e6f0;
            border-radius: 0.25rem;
            font-size: 0.875rem;
        }
        .cart-item {
            border-bottom: 1px solid #eee;
            padding: 15px 0;
        }
        .cart-item:last-child {
            border-bottom: none;
        }
        /* Align Add to Cart buttons at card bottom */
        .product-card .card-body { display: flex; flex-direction: column; }
        .product-card .add-to-cart-btn { margin-top: auto; }
        .product-card .quantity-controls { margin-bottom: 0.5rem; }
        /* Added styles for image display and filters consistent with POS */
        .product-img {
          width: 100%;
          height: 140px;
          object-fit: cover;
          border-top-left-radius: 0.35rem;
          border-top-right-radius: 0.35rem;
        }
        .search-container {
          background: white;
          border-radius: 0.35rem;
          padding: 0.5rem;
          box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        .category-pill {
          margin: 0.125rem;
          border-radius: 1rem;
          font-size: 0.875rem;
        }
        .category-pill.active {
          background-color: #2470dc !important;
          border-color: #2470dc !important;
          color: white !important;
        }
        .product-grid {
          display: grid;
          grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
          gap: 1rem;
          padding: 1rem 0;
        }
        .price-display {
          font-size: 1.1rem;
          font-weight: 600;
          color: #2470dc;
          transition: all 0.3s ease;
        }
        .price-display.price-updated {
          animation: pricePulse 0.5s ease;
        }
        @keyframes pricePulse {
          0% { transform: scale(1); color: #2470dc; }
          50% { transform: scale(1.1); color: #28a745; }
          100% { transform: scale(1); color: #2470dc; }
        }
        .variant-stock-badge, .stock-badge {
          font-size: 0.75rem;
          padding: 0.25rem 0.5rem;
          border-radius: 0.5rem;
        }
        .variation-grid {
          display: flex;
          flex-wrap: wrap;
          gap: 0.5rem;
        }
        .variation-grid .btn {
          min-width: 80px;
          padding: 0.5rem 0.75rem;
          text-align: center;
          border-radius: 0.5rem;
          transition: all 0.2s ease;
          cursor: pointer;
          border: 2px solid #dee2e6;
          background-color: #ffffff;
          color: #495057;
          font-weight: 500;
          position: relative;
          display: flex;
          align-items: center;
          justify-content: center;
          gap: 0.25rem;
        }
        .variation-grid .btn:hover {
          border-color: #2470dc;
          background-color: #f0f4ff;
          color: #2470dc;
          transform: translateY(-1px);
          box-shadow: 0 2px 4px rgba(36, 112, 220, 0.2);
        }
        .variation-grid .btn-check:checked + .btn,
        .variation-grid .btn-check:checked + .btn:hover {
          border-color: #2470dc;
          background-color: #2470dc;
          color: #ffffff;
          box-shadow: 0 2px 8px rgba(36, 112, 220, 0.3);
        }
        .variation-grid .btn:active {
          transform: translateY(0);
        }
        .variation-grid .btn.disabled {
          opacity: 0.8;
          cursor: pointer !important; /* Always clickable */
          border-color: #dee2e6;
          background-color: #f8f9fa;
        }
        .variation-grid .btn.disabled:hover {
          transform: translateY(-1px);
          box-shadow: 0 2px 4px rgba(36, 112, 220, 0.2);
          border-color: #2470dc;
          background-color: #f0f4ff;
        }
        /* Ensure labels are always clickable */
        .variation-grid label {
          cursor: pointer !important;
          pointer-events: auto !important;
        }
    </style>
</head>
<body>
    <?php include_once 'includes/header.php'; ?>
    
    <!-- Cart Overlay -->
    <div class="cart-overlay" id="cartOverlay"></div>
    
    <!-- Cart Sidebar -->
    <div class="cart-sidebar" id="cartSidebar">
        <div class="p-3 border-bottom">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-cart3 me-2"></i>Shopping Cart</h5>
                <button type="button" class="btn-close" id="closeCart"></button>
            </div>
        </div>
        <div class="p-3">
            <div id="cartItems">
                <div class="text-center text-muted py-4">
                    <i class="bi bi-cart-x fs-1"></i>
                    <p class="mt-2">Your cart is empty</p>
                </div>
            </div>
            <div id="cartSummary" class="mt-3" style="display: none;">
                <div class="border-top pt-3">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Subtotal:</span>
                        <span id="cartSubtotal">₱0.00</span>
                    </div>
                    <div class="d-flex justify-content-between fw-bold fs-5">
                        <span>Total:</span>
                        <span id="cartTotal">₱0.00</span>
                    </div>
                    <button type="button" class="btn btn-primary w-100 mt-3" id="checkoutBtn">
                        <i class="bi bi-credit-card me-2"></i>Proceed to Checkout
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid">
      <div class="row">
        <?php include_once 'includes/sidebar.php'; ?>
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
        <div class="pos-header mb-3">
          <div class="d-flex justify-content-between align-items-center">
            <h2 class="mb-0"><?= htmlspecialchars($supplier->name) ?></h2>
            <button type="button" class="btn btn-outline-primary position-relative" id="cartToggle">
                <i class="bi bi-cart3 me-2"></i>Cart
                <span class="cart-badge" id="cartBadge" style="display: none;">0</span>
            </button>
          </div>
        </div>
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($gcashRedirectUrl)): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <div class="d-flex align-items-center">
                    <i class="bi bi-arrow-right-circle me-2"></i>
                    <span class="me-2">Proceed to GCash payment:</span>
                    <a href="<?= htmlspecialchars($gcashRedirectUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-primary">Pay via GCash (PayMongo)</a>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="mb-3">
            <a href="suppliers.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-2"></i>Back to Suppliers
            </a>
        </div>

        <!-- Products -->
        <div class="card dashboard-card">
            <div class="card-header bg-light">
                <div class="d-flex flex-wrap align-items-center justify-content-between">
                    <h5 class="mb-2 mb-md-0 text-gray-800">
                        <i class="bi bi-grid-3x3-gap me-2"></i>Products
                        <span class="badge bg-primary ms-2"><?php echo count($items); ?> items</span>
                    </h5>
                    <div class="search-container">
                        <div class="input-group" style="max-width:300px;">
                            <input type="text" id="searchProduct" class="form-control border-0" placeholder="Search products...">
                            <button class="btn btn-outline-secondary border-0" id="clearSearch" title="Clear search">
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <!-- Category Pills -->
                <div class="mt-3 p-3 bg-light rounded">
                    <label class="form-label fw-bold text-gray-800 mb-2">
                        <i class="bi bi-funnel me-1"></i>Filter by Category:
                    </label>
                    <div class="d-flex flex-wrap">
                        <button type="button" class="btn btn-sm btn-outline-primary category-pill active" data-category="all">
                            <i class="bi bi-grid me-1"></i>All
                        </button>
                        <?php foreach ($categories as $cat): ?>
                          <button type="button" class="btn btn-sm btn-outline-primary category-pill" data-category="<?= htmlspecialchars($cat['key']) ?>">
                            <?= htmlspecialchars($cat['label']) ?>
                          </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($items)): ?>
                    <div class="alert alert-info text-center">
                        <i class="bi bi-inbox me-2"></i>
                        No products found for this supplier. Products from supplier/products.php will appear here once they are added.
                    </div>
                <?php else: ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle me-2"></i>
                        <strong><?php echo count($items); ?> product(s)</strong> loaded from supplier catalog. All products are synced to inventory and ready for ordering.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <div class="product-grid" id="productContainer">
                    <?php foreach ($items as $row): 
                        $img = trim($row['image_url'] ?? '');
                        if ($img === '') {
                            $img = trim($row['image_path'] ?? '');
                        }
                        if ($img === '') {
                            $img = '../assets/img/placeholder.svg';
                        } else {
                            $img = '../' . $img;
                        }
                        $catKey = strtolower(trim($row['category'] ?? 'uncategorized')) ?: 'uncategorized';
                        $nameL = strtolower($row['name']);
                        $catL = strtolower($row['category'] ?? '');
                        $defaultUnit = 'per piece';
                        if (strpos($nameL, 'nail') !== false || strpos($catL, 'nail') !== false) { $defaultUnit = 'per kilo'; }
                        elseif (strpos($nameL, 'paint') !== false || strpos($catL, 'paint') !== false) { $defaultUnit = 'per gallon'; }
                        elseif (strpos($nameL, 'cement') !== false || strpos($nameL, 'sand') !== false || strpos($nameL, 'gravel') !== false) { $defaultUnit = 'per bag'; }
                        elseif (strpos($nameL, 'wire') !== false || strpos($nameL, 'rope') !== false) { $defaultUnit = 'per meter'; }
                        elseif (strpos($nameL, 'tile') !== false || strpos($nameL, 'sheet') !== false || strpos($nameL, 'plywood') !== false) { $defaultUnit = 'per sheet'; }
                        // Use variation data from supplier_catalog (matching supplier/products.php)
                        $varStocks    = $row['variation_stocks'] ?? [];
                        $varPrices    = $row['variation_prices'] ?? [];
                        $varUnitTypes = $row['variation_unittypes'] ?? [];
                        $variantList  = $row['variations'] ?? [];
                        
                        // Base stock is supplier_quantity (from supplier catalog, matching supplier/products.php)
                        $baseAvailableStock = (int)($row['quantity'] ?? 0);
                    ?>
                    <div class="product-item" data-name="<?= strtolower($row['name'] ?? '') ?>" data-category="<?= htmlspecialchars($catKey) ?>">
                        <div class="card product-card h-100"
                             data-id="<?= (int)$row['id'] ?>"
                             data-product-id="<?= (int)$row['id'] ?>"
                             data-inventory-id="<?= (int)($row['inventory_id'] ?? 0) ?>"
                             data-product-name="<?= htmlspecialchars($row['name'], ENT_QUOTES) ?>"
                             data-category="<?= htmlspecialchars($row['category'] ?? '', ENT_QUOTES) ?>"
                             data-base-price="<?= (float)$row['unit_price'] ?>"
                             data-base-stock="<?= (int)($row['quantity'] ?? 0) ?>"
                             data-variation-stock='<?= htmlspecialchars(json_encode($varStocks), ENT_QUOTES) ?>'
                             data-variation-prices='<?= htmlspecialchars(json_encode($varPrices), ENT_QUOTES) ?>'
                             data-variation-unittypes='<?= htmlspecialchars(json_encode($varUnitTypes), ENT_QUOTES) ?>'>
                            <div class="position-relative">
                                <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($row['name']) ?>" class="product-img">
                            </div>
                            <div class="card-body">
                                <h5 class="card-title"><?= htmlspecialchars($row['name']) ?></h5>
                                <p class="card-text text-muted mb-2">
                                    <small>Product ID: <?= (int)$row['id'] ?></small>
                                </p>
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="h5 text-primary mb-0 price-display" id="priceDisplay_<?= (int)$row['id'] ?>">₱<?= number_format((float)$row['unit_price'], 2) ?> <small class="text-muted">(<?= htmlspecialchars($defaultUnit) ?>)</small></span>
                                </div>
                                <?php 
                                    $hasVarMaps = (!empty($varUnitTypes) || !empty($varPrices) || !empty($varStocks));
                                    $hasVariations = (!empty($variantList) || $hasVarMaps);
                                ?>
                                <?php if ($hasVariations): ?>
                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <small class="fw-semibold text-dark"><i class="bi bi-sliders me-1"></i>Select Options:</small>
                                            <small class="text-muted"><i class="bi bi-info-circle me-1"></i>Price varies by selection</small>
                                        </div>
                                        <div class="variation-attrs" data-auto-build="1"></div>
                                        <div class="variation-status small mt-2 text-muted"></div>
                                    </div>
                                <?php endif; ?>
                                <?php /* Removed explicit Size (mm) selector; size handled internally via variants */ ?>
                                <div class="quantity-controls mb-3">
                                    <label class="form-label small text-muted mb-1">Order Quantity (Stock: <span id="stockDisplay_<?= (int)$row['id'] ?>">—</span>)</label>
                                    <div class="d-flex align-items-center gap-2">
                                        <button type="button" class="quantity-btn" onclick="decreaseQuantity(<?= (int)$row['id'] ?>)">
                                            <i class="bi bi-dash"></i>
                                        </button>
                                        <input type="number"
                                               id="qty_<?= (int)$row['id'] ?>"
                                               value="1"
                                               min="1"
                                               max="999"
                                               data-stock="0"
                                               class="form-control text-center"
                                               style="width: 80px;"
                                               title="Quantity to order">
                                        <button type="button" class="quantity-btn" onclick="increaseQuantity(<?= (int)$row['id'] ?>)">
                                            <i class="bi bi-plus"></i>
                                        </button>
                                    </div>
                                </div>
                                <button type="button"
                                        class="btn btn-primary w-100 add-to-cart-btn mt-auto"
                                        onclick="addToCart(<?= (int)$row['id'] ?>)">
                                    <i class="bi bi-cart-plus me-2"></i>Add to Cart
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Checkout Form (Hidden) -->
        <form method="POST" id="checkoutForm" style="display: none;">
            <input type="hidden" name="action" value="place_order" />
            <input type="hidden" name="cart_data" id="cartDataInput" />
            <input type="hidden" name="payment_method" id="hiddenPaymentMethod" />
            <input type="hidden" name="gcash_number" id="hiddenGcashNumber" />
            <input type="hidden" name="transaction_reference" id="hiddenTransactionReference" />
        </form>
        

        </main>
      </div>
    </div>

    <!-- Checkout Modal -->
    <div class="modal fade" id="checkoutModal" tabindex="-1" aria-labelledby="checkoutModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="checkoutModalLabel">
                        <i class="bi bi-credit-card me-2"></i>Checkout
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Order Summary -->
                    <div class="mb-4">
                        <h6 class="border-bottom pb-2">Order Summary</h6>
                        <div id="checkoutOrderSummary"></div>
                        <div class="d-flex justify-content-between fw-bold fs-5 mt-3 pt-3 border-top">
                            <span>Total:</span>
                            <span id="checkoutTotal">₱0.00</span>
                        </div>
                    </div>
                    
                    <!-- Payment Method Selection -->
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Payment Method</h6>
                            <div class="card">
                                <div class="card-body">
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="radio" name="modal_payment_method" id="modal_payment_cash" value="cash" checked>
                                        <label class="form-check-label" for="modal_payment_cash">
                                            <i class="bi bi-cash-coin text-success me-2"></i>
                                            <strong>Cash Payment</strong>
                                            <small class="d-block text-muted">Pay with cash on delivery</small>
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="modal_payment_method" id="modal_payment_gcash" value="gcash">
                                        <label class="form-check-label" for="modal_payment_gcash">
                                            <i class="bi bi-phone text-primary me-2"></i>
                                            <strong>GCash Payment</strong>
                                            <small class="d-block text-muted">Pay using GCash mobile wallet</small>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6>Transaction Details</h6>
                            <div class="card">
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="modal_gcash_number" class="form-label">
                                            GCash Number <small class="text-muted">(Required for GCash)</small>
                                        </label>
                                        <input type="text" class="form-control" id="modal_gcash_number" 
                                               placeholder="e.g. 09171234567 or +639171234567">
                                        <small class="form-text text-muted">Enter your GCash mobile number. For Cash payments, this can be left blank.</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="finalizeOrder">
                        <i class="bi bi-check-circle me-2"></i>Place Order
                    </button>
                </div>
            </div>
        </div>
    </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
        <script src="../js/unit_utils.js"></script>
        <script src="../js/variation_state.js"></script>
        <!-- Variation sync removed - not needed for supplier ordering page -->
    <script>
        // Cart functionality
        let cart = [];
        
        // DOM elements
        const cartToggle = document.getElementById('cartToggle');
        const cartSidebar = document.getElementById('cartSidebar');
        const cartOverlay = document.getElementById('cartOverlay');
        const closeCart = document.getElementById('closeCart');
        const cartItems = document.getElementById('cartItems');
        const cartSummary = document.getElementById('cartSummary');
        const cartBadge = document.getElementById('cartBadge');
        const checkoutBtn = document.getElementById('checkoutBtn');
        const checkoutForm = document.getElementById('checkoutForm');
        
        // Pricing helpers and cart functions (delegated to shared utils)
        function isNails(name, category) { return unitUtils.isNails(name, category); }
        function getSizeFactor(size) { return unitUtils.getSizeFactor(size); }
        function computeEffectivePrice(basePrice, name, category, unitType, size) { return unitUtils.computeEffectivePrice(basePrice, name, category, unitType, size); }
        function getAutoUnitType(name, category) { return unitUtils.getAutoUnitType(name, category); }
        
        // Format variation for display: "Color:Red|Size:Small" -> "Red Small" (combine values only)
        function formatVariationForDisplay(variation) {
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
            return values.join(' ');
        }
        
        // Multi-attribute helpers (align with POS pages)
        function parseVariationAttributes(cardEl){
            try {
                const unitMapStr = cardEl.getAttribute('data-variation-unittypes') || '{}';
                const priceMapStr = cardEl.getAttribute('data-variation-prices') || '{}';
                const stockMapStr = cardEl.getAttribute('data-variation-stock') || '{}';
                let u = {}, p = {}, s = {};
                try { u = JSON.parse(unitMapStr||'{}'); } catch(e){}
                try { p = JSON.parse(priceMapStr||'{}'); } catch(e){}
                try { s = JSON.parse(stockMapStr||'{}'); } catch(e){}
                const keys = Array.from(new Set([ ...Object.keys(u||{}), ...Object.keys(p||{}), ...Object.keys(s||{}) ]));
                const attrs = {};
                if (!keys.length) return attrs;
                const hasComposite = keys.some(k => (k||'').includes(':') || (k||'').includes('|'));
                if (hasComposite){
                    keys.forEach(k => {
                        (k||'').split('|').forEach(part => {
                            const [a,v] = part.split(':').map(x => (x||'').trim());
                            if (a && v) { (attrs[a] ||= new Set()).add(v); }
                        });
                    });
                } else {
                    (attrs['Size'] ||= new Set());
                    keys.forEach(v => attrs['Size'].add(v));
                }
                return attrs;
            } catch(e){ return {}; }
        }
        function renderVariationSelectors(cardEl){
            const container = cardEl.querySelector('.variation-attrs[data-auto-build="1"]');
            if (!container) return;
            const attrs = parseVariationAttributes(cardEl);
            container.innerHTML = '';
            const id = parseInt(cardEl.getAttribute('data-product-id') || cardEl.getAttribute('data-id') || '0', 10);
            // Parse variation stock map for per-option stock display (sum across combos containing the option)
            let stockMap = {};
            try {
                const stockMapStr = cardEl.getAttribute('data-variation-stock') || cardEl.getAttribute('data-variation_stocks') || '{}';
                stockMap = JSON.parse(stockMapStr);
            } catch(e){ stockMap = {}; }

            const computeOptionStock = (attrName, val) => {
                const needle = `${attrName}:${val}`;
                let total = 0;
                for (const [k, v] of Object.entries(stockMap)){
                    if (typeof v !== 'number') continue;
                    if ((k||'').split('|').includes(needle)) total += v;
                }
                return total;
            };
            Object.keys(attrs).forEach(attrName => {
                const group = document.createElement('div');
                group.className = 'mb-3';
                group.innerHTML = `<div class="small fw-semibold mb-2 text-dark"><i class="bi bi-tag me-1"></i>${attrName}:</div><div class="variation-grid"></div>`;
                const grid = group.querySelector('.variation-grid');
                Array.from(attrs[attrName]).forEach((val, idx) => {
                    const slug = (attrName + '_' + val).replace(/[\s\.\/]/g, '_');
                    const inputId = `variant_${id}_${slug}`;
                    const checked = idx === 0 ? 'checked' : '';
                    const optStock = computeOptionStock(attrName, val);
                    
                    // Shopee-style pricing: calculate price addition (delta) for this option
                    // Each option contributes a portion that gets added to base price
                    let optionPrice = '0';
                    let optionPriceValue = 0;
                    try {
                        const priceMapStr = cardEl.getAttribute('data-variation-prices') || '{}';
                        const priceMap = JSON.parse(priceMapStr || '{}') || {};
                        const basePrice = parseFloat(cardEl.getAttribute('data-base-price') || 0);
                        
                        // First try: exact match with attribute:value (e.g., "Size:2mm")
                        const singleKey = `${attrName}:${val}`;
                        if (priceMap[singleKey] !== null && priceMap[singleKey] !== undefined) {
                            const fullPrice = parseFloat(priceMap[singleKey]);
                            if (!isNaN(fullPrice) && fullPrice >= 0) {
                                // This is the full price for this option - calculate addition
                                optionPriceValue = Math.max(0, fullPrice - basePrice);
                                optionPrice = optionPriceValue.toFixed(2);
                            }
                        } else {
                            // Find combos containing this attribute:value and calculate average delta
                            const matchingKeys = Object.keys(priceMap).filter(k => {
                                const parts = (k || '').split('|');
                                return parts.some(p => p.trim() === `${attrName}:${val}`.trim());
                            });
                            
                            if (matchingKeys.length > 0) {
                                // Calculate average delta: (combo_price - base) / number_of_attributes_in_combo
                                let totalDelta = 0;
                                let comboCount = 0;
                                
                                matchingKeys.forEach(comboKey => {
                                    const comboPrice = parseFloat(priceMap[comboKey]);
                                    if (!isNaN(comboPrice) && comboPrice >= 0) {
                                        const parts = comboKey.split('|');
                                        const attributeCount = parts.length;
                                        // Calculate delta per attribute: (combo_price - base) / num_attributes
                                        const delta = attributeCount > 0
                                            ? Math.max(0, (comboPrice - basePrice) / attributeCount)
                                            : 0;
                                        totalDelta += delta;
                                        comboCount++;
                                    }
                                });
                                
                                if (comboCount > 0) {
                                    // Use average delta as the option's price addition
                                    optionPriceValue = totalDelta / comboCount;
                                    optionPrice = optionPriceValue.toFixed(2);
                                }
                            }
                        }
                    } catch(e) {
                        optionPrice = '0';
                        optionPriceValue = 0;
                    }
                    
                    // Clean button - only show option name (no price, no stock badge)
                    grid.insertAdjacentHTML('beforeend',
                        `<input class="btn-check" type="radio" name="variant_attr_${id}_${attrName}" id="${inputId}" value="${val}" ${checked} data-price="${optionPrice}" data-attr="${attrName}" data-value="${val}">`+
                        `<label class="btn btn-outline-secondary btn-sm d-flex align-items-center justify-content-center text-center" for="${inputId}" style="min-width: 80px; padding: 0.5rem;">
                            <span class="fw-semibold">${val}</span>
                        </label>`
                    );
                });
                container.appendChild(group);
            });
        }
        function getSelectedVariantKey(cardEl){
            const id = parseInt(cardEl.getAttribute('data-product-id') || cardEl.getAttribute('data-id') || '0', 10);
            const container = cardEl.querySelector('.variation-attrs');
            if (!container) return null;
            const inputs = container.querySelectorAll(`input[name^="variant_attr_${id}_"]`);
            if (!inputs.length) return null;
            const groupNames = Array.from(new Set(Array.from(inputs).map(i => i.getAttribute('name'))));
            const parts = [];
            for (const gn of groupNames){
                const sel = container.querySelector(`input[name="${gn}"]:checked`);
                const attrName = gn.replace(`variant_attr_${id}_`, '');
                if (!sel) return null;
                parts.push(`${attrName}:${sel.value}`);
            }
            parts.sort((a,b)=>a.localeCompare(b));
            return parts.join('|');
        }
        // Variant-aware UI updates - following supplier/products.php pattern
        function updateCardUnitAndPrice(cardEl){
            const id = parseInt(cardEl.getAttribute('data-product-id'), 10);
            const name = cardEl.getAttribute('data-product-name') || '';
            const category = cardEl.getAttribute('data-category') || '';
            const basePrice = parseFloat(cardEl.getAttribute('data-base-price')) || 0;
            const unitMapStr = cardEl.getAttribute('data-variation-unittypes') || '{}';
            const priceMapStr = cardEl.getAttribute('data-variation-prices') || '{}';
            const stockMapStr = cardEl.getAttribute('data-variation-stock') || '{}';
            let unitMap = {}; let priceMap = {}; let stockMap = {};
            try { unitMap = JSON.parse(unitMapStr || '{}') || {}; } catch(e){}
            try { priceMap = JSON.parse(priceMapStr || '{}') || {}; } catch(e){}
            try { stockMap = JSON.parse(stockMapStr || '{}') || {}; } catch(e){}
            const variant = getSelectedVariantKey(cardEl);
            let unitType = getAutoUnitType(name, category);
            if (variant && unitMap[variant]) unitType = unitMap[variant] || unitType;
            // Don't mark as unavailable just because stock is 0 - allow backorders
            const unavailable = false; // Always allow backorders
            cardEl.setAttribute('data-unavailable', unavailable ? '1' : '0');
            
            // Shopee-style pricing: base price + sum of all selected attribute option prices
            // Each option has a price addition stored in data-price attribute
            let cost = basePrice;
            
            // Priority 1: Check if exact variant combo has a price (use it if available for accuracy)
            if (variant && priceMap && priceMap[variant] != null && !isNaN(parseFloat(priceMap[variant]))) {
                cost = parseFloat(priceMap[variant]);
            } else {
                // Priority 2: Shopee-style additive pricing - add prices from each selected option
                const container = cardEl.querySelector('.variation-attrs');
                if (container) {
                    const selectedInputs = container.querySelectorAll(`input[name^="variant_attr_${id}_"]:checked`);
                    let totalOptionPrices = 0;
                    
                    selectedInputs.forEach(input => {
                        const inputPrice = parseFloat(input.getAttribute('data-price') || 'NaN');
                        if (!isNaN(inputPrice) && inputPrice >= 0) {
                            // Each option price is an addition to base - sum them all
                            totalOptionPrices += inputPrice;
                        }
                    });
                    
                    // Final price: base + sum of all selected option price additions
                    cost = basePrice + totalOptionPrices;
                }
            }
            if (unavailable) {
                const priceEl = document.getElementById(`priceDisplay_${id}`);
                if (priceEl) {
                    // Format variation name for display (combine values only: "Red Small")
                    let variantDisplay = '';
                    if (variant) {
                        const variantParts = variant.split('|');
                        variantDisplay = variantParts.map(p => p.split(':')[1] || p).join(' ');
                    }
                    const priceText = `₱${(cost||0).toFixed(2)}`;
                    const unitText = ` <small class="text-muted">(${unitType}${variantDisplay ? ' • ' + variantDisplay : ''})</small>`;
                    priceEl.innerHTML = priceText + unitText;
                    // Add animation class for price change
                    priceEl.classList.add('price-updated');
                    setTimeout(() => priceEl.classList.remove('price-updated'), 500);
                }
                cardEl.setAttribute('data-price', (cost||0).toFixed(2));
                cardEl.setAttribute('data-unit_type', unitType);
                updateCardVariantStock(cardEl);
                if (window.VariationState) window.VariationState.updateFromCard(cardEl);
                return;
            }
            const priceEl = document.getElementById(`priceDisplay_${id}`);
            if (priceEl) {
                // Format variation name for display
                let variantDisplay = '';
                if (variant) {
                    const variantParts = variant.split('|');
                    variantDisplay = variantParts.map(p => p.split(':')[1] || p).join(' • ');
                }
                // Show price with variation info
                const priceText = `₱${(cost||0).toFixed(2)}`;
                const unitText = ` <small class="text-muted">(${unitType}${variantDisplay ? ' • ' + variantDisplay : ''})</small>`;
                priceEl.innerHTML = priceText + unitText;
                
                // Add animation class for price change
                priceEl.classList.add('price-updated');
                setTimeout(() => priceEl.classList.remove('price-updated'), 500);
            }
            cardEl.setAttribute('data-price', (cost||0).toFixed(2));
            cardEl.setAttribute('data-unit_type', unitType);
            updateCardVariantStock(cardEl);
            if (window.VariationState) window.VariationState.updateFromCard(cardEl);
        }
        function updateCardVariantStock(cardEl){
            const id = parseInt(cardEl.getAttribute('data-product-id'), 10);
            const stockMapStr = cardEl.getAttribute('data-variation-stock') || '{}';
            let map = {}; try { map = JSON.parse(stockMapStr || '{}') || {}; } catch(e){}
            const variant = getSelectedVariantKey(cardEl);
            let variantStock = null;
            
            // Get stock from variation map
            if (variant) {
                variantStock = (map && (map[variant] !== undefined)) ? +map[variant] : 0;
            } else {
                // No variant selected - use base stock
                variantStock = parseInt(cardEl.getAttribute('data-base-stock') || '0');
            }
            
            // Update stock display in quantity label
            const stockDisplay = document.getElementById(`stockDisplay_${id}`);
            if (stockDisplay) {
                stockDisplay.textContent = (variantStock !== null && variantStock !== undefined) ? variantStock : '—';
            }
            
            // Update quantity input max and current value based on stock
            const qtyInput = document.getElementById(`qty_${id}`);
            if (qtyInput) {
                const currentStock = (variantStock !== null && variantStock !== undefined) ? variantStock : 999;
                qtyInput.setAttribute('data-stock', currentStock);
                qtyInput.max = currentStock > 0 ? currentStock : 999;
                // If current quantity exceeds stock, set to stock or 1
                const currentQty = parseInt(qtyInput.value) || 1;
                if (currentStock > 0 && currentQty > currentStock) {
                    qtyInput.value = currentStock;
                } else if (currentStock <= 0) {
                    qtyInput.value = 1; // Allow ordering even if stock is 0 (backorders)
                    qtyInput.max = 999;
                }
            }
            
            // Reflect selected variant stock in data-stock for checks
            if (variantStock !== null) cardEl.setAttribute('data-stock', variantStock);
        }

        // Status helper for Shopee-like feedback
        function setVariationStatus(cardEl, message, tone){
            const statusEl = cardEl.querySelector('.variation-status');
            if (!statusEl) return;
            statusEl.classList.remove('text-muted','text-success','text-danger','text-warning');
            const clsMap = { info: 'text-muted', success: 'text-success', danger: 'text-danger', warning: 'text-warning' };
            statusEl.classList.add(clsMap[tone||'info']||'text-muted');
            statusEl.innerHTML = message||'';
        }

        // Dynamically disable options based on selection compatibility (allow all stock levels including 0)
        function updateOptionAvailability(cardEl){
            try {
                const id = parseInt(cardEl.getAttribute('data-product-id') || cardEl.getAttribute('data-id') || '0', 10);
                const container = cardEl.querySelector('.variation-attrs');
                if (!container || !id) return;
                const selectedKey = getSelectedVariantKey(cardEl);
                const selectedParts = (selectedKey||'').split('|').filter(Boolean);
                let stockMap = {}, unitMap = {}, priceMap = {};
                try { stockMap = JSON.parse(cardEl.getAttribute('data-variation-stock')||'{}')||{}; } catch(e){}
                try { unitMap  = JSON.parse(cardEl.getAttribute('data-variation-unittypes')||'{}')||{}; } catch(e){}
                try { priceMap = JSON.parse(cardEl.getAttribute('data-variation-prices')||'{}')||{}; } catch(e){}
                // Allow all combinations regardless of stock level - support backorders
                const allKeys = Array.from(new Set([ ...Object.keys(unitMap||{}), ...Object.keys(priceMap||{}), ...Object.keys(stockMap||{}) ]));
                const validCombos = allKeys.length > 0 ? allKeys : Object.keys(stockMap||{});
                const groups = Array.from(new Set(Array.from(container.querySelectorAll(`input[name^="variant_attr_${id}_"]`)).map(i => i.getAttribute('name'))));
                groups.forEach(groupName => {
                    const attrName = groupName.replace(`variant_attr_${id}_`, '');
                    const inputs = container.querySelectorAll(`input[name="${groupName}"]`);
                    inputs.forEach(input => {
                        const val = input.value;
                        const inputId = input.id;
                        const label = container.querySelector(`label[for="${inputId}"]`);
                        const requiredParts = selectedParts.filter(p => !p.startsWith(attrName+':')).concat(`${attrName}:${val}`);
                        // Check if combination exists in any of the maps (unit, price, or stock)
                        const available = validCombos.some(k => {
                            const parts = (k||'').split('|');
                            return requiredParts.every(rp => parts.includes(rp));
                        }) || requiredParts.length === 1; // Always allow single attribute selections
                        // Never disable inputs - allow all selections for backorders
                        input.disabled = false;
                        if (label){
                            label.classList.remove('disabled','opacity-50');
                            // Visual indicator for zero stock but still clickable
                            const stockForVariant = stockMap && stockMap[Object.keys(stockMap).find(k => k.includes(`${attrName}:${val}`)) || ''] !== undefined 
                                ? (stockMap[Object.keys(stockMap).find(k => k.includes(`${attrName}:${val}`))] || 0)
                                : null;
                            if (stockForVariant !== null && stockForVariant <= 0) {
                                label.style.opacity = '0.8'; // Slight transparency for zero stock
                            } else {
                                label.style.opacity = '1';
                            }
                        }
                    });
                });
            } catch(e){ /* ignore */ }
        }

        // Update variant data from supplier catalog (no API call needed)
        function fetchAndApplyVariant(cardEl){
            const id = parseInt(cardEl.getAttribute('data-product-id') || cardEl.getAttribute('data-id') || '0', 10);
            if (!id) return;
            const variant = getSelectedVariantKey(cardEl);
            const name = cardEl.getAttribute('data-product-name') || '';
            const category = cardEl.getAttribute('data-category') || '';
            let unitType = 'per piece';
            
            // Get unit type from variation map
            try {
                const unitMapStr = cardEl.getAttribute('data-variation-unittypes');
                const unitMap = unitMapStr ? JSON.parse(unitMapStr) : {};
                if (variant && unitMap && unitMap[variant]) {
                    unitType = unitMap[variant];
                } else {
                    unitType = getAutoUnitType(name, category);
                }
            } catch(e){ unitType = getAutoUnitType(name, category); }
            
            // Get stock and price from data attributes (supplier catalog)
            const stockMapStr = cardEl.getAttribute('data-variation-stock') || '{}';
            const priceMapStr = cardEl.getAttribute('data-variation-prices') || '{}';
            const basePrice = parseFloat(cardEl.getAttribute('data-base-price') || 0);
            let stockMap = {}, priceMap = {};
            try { stockMap = JSON.parse(stockMapStr || '{}') || {}; } catch(e){}
            try { priceMap = JSON.parse(priceMapStr || '{}') || {}; } catch(e){}
            
            let stock = 0;
            let price = basePrice;
            
            if (variant) {
                // Get stock from variation map
                stock = (stockMap && stockMap[variant] !== undefined) ? parseInt(stockMap[variant] || 0) : 0;
                
                // Shopee-style pricing: base + sum of selected option prices
                if (priceMap && priceMap[variant] !== null && priceMap[variant] !== undefined) {
                    // Use exact combo price if available
                    price = parseFloat(priceMap[variant]);
                } else {
                    // Calculate from base + sum of selected options
                    price = basePrice;
                    const container = cardEl.querySelector('.variation-attrs');
                    if (container) {
                        const selectedInputs = container.querySelectorAll(`input[name^="variant_attr_${id}_"]:checked`);
                        selectedInputs.forEach(input => {
                            const inputPrice = parseFloat(input.getAttribute('data-price') || 'NaN');
                            if (!isNaN(inputPrice) && inputPrice >= 0) {
                                price += inputPrice;
                            }
                        });
                    }
                }
            } else {
                // No variant - use base stock
                stock = parseInt(cardEl.getAttribute('data-base-stock') || '0');
            }
            
            // Update card attributes
            cardEl.setAttribute('data-price', (price||0).toFixed(2));
            cardEl.setAttribute('data-unit_type', unitType);
            cardEl.setAttribute('data-stock', stock);
            
            // Update price display
            const priceEl = document.getElementById(`priceDisplay_${id}`);
            if (priceEl) {
                let variantDisplay = '';
                if (variant) {
                    const variantParts = variant.split('|');
                    variantDisplay = variantParts.map(p => p.split(':')[1] || p).join(' • ');
                }
                priceEl.innerHTML = `₱${(price||0).toFixed(2)} <small class="text-muted">(${unitType}${variantDisplay ? ' • ' + variantDisplay : ''})</small>`;
            }
            
            // Update stock display and quantity input
            updateCardVariantStock(cardEl);
            
            // Update status (optional - can remove if not needed)
            setVariationStatus(cardEl, '', 'info');
            
            // Always allow ordering (backorders supported)
            cardEl.setAttribute('data-unavailable','0');
            
            if (window.VariationState) window.VariationState.updateFromCard(cardEl);
        }
        function initVariationUI(){
            document.querySelectorAll('.product-card').forEach(card => {
                const id = parseInt(card.getAttribute('data-product-id'), 10);
                if (!id) return;
                // Render selectors from variation maps
                renderVariationSelectors(card);
                // Listen for option changes - make labels fully clickable like Shopee (always enabled)
                const labels = card.querySelectorAll(`label[for^="variant_${id}_"]`);
                labels.forEach(label => {
                    label.style.cursor = 'pointer';
                    // Ensure associated input is never disabled
                    const inputId = label.getAttribute('for');
                    const input = document.getElementById(inputId);
                    if (input) {
                        input.disabled = false;
                    }
                    label.addEventListener('click', function(e) {
                        // Find the associated input
                        const input = document.getElementById(inputId);
                        if (input) {
                            // Always allow clicks - support backorders
                            input.disabled = false;
                            input.checked = true;
                            
                            // Immediately update price when variation is clicked
                            updateCardUnitAndPrice(card);
                            updateCardVariantStock(card);
                            
                            // Trigger change event for other listeners
                            input.dispatchEvent(new Event('change', { bubbles: true }));
                            
                            // Visual feedback
                            label.style.transform = 'scale(0.98)';
                            setTimeout(() => { label.style.transform = ''; }, 150);
                            
                            // Highlight price change animation
                            const priceEl = document.getElementById(`priceDisplay_${id}`);
                            if (priceEl) {
                                priceEl.style.transition = 'all 0.3s ease';
                                priceEl.style.color = '#28a745';
                                priceEl.style.transform = 'scale(1.05)';
                                setTimeout(() => {
                                    priceEl.style.color = '';
                                    priceEl.style.transform = '';
                                }, 300);
                            }
                        }
                    });
                });
                
                const inputs = card.querySelectorAll(`input[name^="variant_attr_${id}_"]`);
                inputs.forEach(r => r.addEventListener('change', () => {
                    // Immediately update price when variation changes
                    updateCardUnitAndPrice(card);
                    updateOptionAvailability(card);
                    updateCardVariantStock(card);
                    
                    // Highlight price change
                    const priceEl = document.getElementById(`priceDisplay_${id}`);
                    if (priceEl) {
                        priceEl.style.transition = 'all 0.3s ease';
                        priceEl.style.color = '#28a745';
                        priceEl.style.transform = 'scale(1.05)';
                        setTimeout(() => {
                            priceEl.style.color = '';
                            priceEl.style.transform = '';
                        }, 300);
                    }
                    
                    // Debounce fetch per card
                    const t = card.getAttribute('data-fetch-timer');
                    if (t){ try { clearTimeout(parseInt(t)); } catch(e){} }
                    const newT = setTimeout(()=>{ fetchAndApplyVariant(card); }, 150);
                    card.setAttribute('data-fetch-timer', newT);
                    if (window.VariationState) window.VariationState.updateFromCard(card);
                }));
                // Initialize
                updateCardUnitAndPrice(card);
                updateOptionAvailability(card);
                updateCardVariantStock(card);
                // Initial stock display update
                const stockDisplay = document.getElementById(`stockDisplay_${id}`);
                if (stockDisplay) {
                    const baseStock = parseInt(card.getAttribute('data-base-stock') || '0');
                    stockDisplay.textContent = baseStock || '—';
                }
                if (window.VariationState) window.VariationState.updateFromCard(card);
            });
        }
        document.addEventListener('DOMContentLoaded', initVariationUI);

        // Enhanced Shopee-style click handling for variation labels - always allow clicks
        document.addEventListener('click', function(e){
            const target = e.target;
            // Check if clicked on label or any element inside the label
            let label = target.closest('label[for^="variant_"]');
            if (label){
                const idAttr = label.getAttribute('for');
                const input = document.getElementById(idAttr);
                if (input){
                    // Always allow clicks - remove disabled check for backorders
                    input.disabled = false; // Ensure it's never disabled
                    
                    // Find the parent product card
                    const card = input.closest('.product-card');
                    
                    if (!input.checked) {
                        input.checked = true;
                        
                        // Immediately update price when variation is clicked
                        if (card) {
                            updateCardUnitAndPrice(card);
                            updateCardVariantStock(card);
                            
                            // Highlight price change animation
                            const cardId = parseInt(card.getAttribute('data-product-id') || card.getAttribute('data-id') || '0', 10);
                            if (cardId) {
                                const priceEl = document.getElementById(`priceDisplay_${cardId}`);
                                if (priceEl) {
                                    priceEl.style.transition = 'all 0.3s ease';
                                    priceEl.style.color = '#28a745';
                                    priceEl.style.transform = 'scale(1.05)';
                                    setTimeout(() => {
                                        priceEl.style.color = '';
                                        priceEl.style.transform = '';
                                    }, 300);
                                }
                            }
                        }
                        
                        // Trigger change event for other listeners
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    
                    // Visual feedback - add active class temporarily
                    label.classList.add('active');
                    setTimeout(() => label.classList.remove('active'), 200);
                    
                    e.preventDefault();
                    e.stopPropagation();
                }
            }
        });

        function addToCart(id){
            const card = document.querySelector(`.product-card[data-product-id="${id}"]`);
            if (!card) return;
            const name = card.getAttribute('data-product-name') || '';
            const category = card.getAttribute('data-category') || '';
            const basePrice = parseFloat(card.getAttribute('data-base-price')) || 0;
            const qtyInput = document.getElementById(`qty_${id}`);
            const quantity = parseInt(qtyInput.value) || 1;
            const unitMapStr = card.getAttribute('data-variation-unittypes') || '{}';
            const priceMapStr = card.getAttribute('data-variation-prices') || '{}';
            const stockMapStr = card.getAttribute('data-variation-stock') || '{}';
            let unitMap = {}, priceMap = {}, stockMap = {};
            try { unitMap = JSON.parse(unitMapStr || '{}') || {}; } catch(e){}
            try { priceMap = JSON.parse(priceMapStr || '{}') || {}; } catch(e){}
            try { stockMap = JSON.parse(stockMapStr || '{}') || {}; } catch(e){}
            const variant = getSelectedVariantKey(card);
            // Allow ordering even if variation doesn't have explicit price (will use base price)
            // Stock check already removed - allow backorders
            let unitType = getAutoUnitType(name, category);
            if (variant && unitMap[variant]) unitType = unitMap[variant] || unitType;
            
            // Shopee-style pricing: base price + sum of all selected attribute option prices
            let price = basePrice;
            
            // Priority 1: Check if exact variant combo has a price (use it if available)
            if (variant && priceMap && priceMap[variant] != null && !isNaN(parseFloat(priceMap[variant]))) {
                price = parseFloat(priceMap[variant]);
            } else {
                // Priority 2: Shopee-style - add prices from each selected attribute option
                const container = card.querySelector('.variation-attrs');
                if (container) {
                    const selectedInputs = container.querySelectorAll(`input[name^="variant_attr_${id}_"]:checked`);
                    let totalOptionPrices = 0;
                    
                    selectedInputs.forEach(input => {
                        const inputPrice = parseFloat(input.getAttribute('data-price') || 'NaN');
                        if (!isNaN(inputPrice) && inputPrice >= 0) {
                            // Each option price is an addition to base - sum them all
                            totalOptionPrices += inputPrice;
                        }
                    });
                    
                    // Final price: base price + sum of all selected option price additions
                    price = basePrice + totalOptionPrices;
                }
            }
            // Require variant selection when variants exist
            if (!variant && Object.keys(stockMap).length > 0){
                showToast('Please select a variation first.', 'danger');
                return;
            }
            // Stock check removed - allow ordering even with zero stock (backorders/restocking)
            // Server-side already allows orders regardless of stock level
            
            // Get catalog_id and inventory_id (matching supplier/products.php)
            const catalogId = parseInt(id);
            const inventoryId = parseInt(card.getAttribute('data-inventory-id') || '0');
            
            // Find existing cart item with same catalog_id, variation, and unit_type (allow multiple variations)
            const existingItem = cart.find(item => 
                item.id === catalogId && 
                item.unit_type === unitType && 
                item.variation === variant
            );
            if (existingItem) {
                existingItem.quantity += quantity;
            } else {
                cart.push({ 
                    id: catalogId,  // supplier_catalog id (matching supplier/products.php)
                    inventory_id: inventoryId || null, // inventory id for order creation (may be null initially)
                    name, 
                    unit_type: unitType, 
                    variation: variant || '', 
                    price, 
                    quantity 
                });
            }
            updateCartDisplay();
            showToast(`${name} added to cart!`, 'success');
            qtyInput.value = 1;
        }
        
        function removeFromCart(id) {
            cart = cart.filter(item => item.id !== id);
            updateCartDisplay();
            showToast('Item removed from cart', 'info');
        }
        
        // Index-based handlers to support multiple variants of the same product
        function removeFromCartAt(index) {
            if (index >= 0 && index < cart.length) {
                cart.splice(index, 1);
                updateCartDisplay();
                showToast('Item removed from cart', 'info');
            }
        }
        
        function updateCartQuantity(id, newQuantity) {
            const item = cart.find(item => item.id === id);
            if (item) {
                if (newQuantity <= 0) {
                    removeFromCart(id);
                } else {
                    item.quantity = newQuantity;
                    updateCartDisplay();
                }
            }
        }
        
        function updateCartQuantityAt(index, newQuantity) {
            const item = cart[index];
            if (!item) return;
            if (newQuantity <= 0) {
                removeFromCartAt(index);
            } else {
                item.quantity = newQuantity;
                updateCartDisplay();
            }
        }
        
        function updateCartDisplay() {
            // Update cart badge
            const totalItems = cart.reduce((sum, item) => sum + item.quantity, 0);
            if (totalItems > 0) {
                cartBadge.textContent = totalItems;
                cartBadge.style.display = 'flex';
            } else {
                cartBadge.style.display = 'none';
            }
            
            // Update cart items
            if (cart.length === 0) {
                cartItems.innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-cart-x fs-1"></i>
                        <p class="mt-2">Your cart is empty</p>
                    </div>
                `;
                cartSummary.style.display = 'none';
            } else {
                cartItems.innerHTML = cart.map((item, idx) => `
                    <div class="cart-item">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <h6 class="mb-1">${item.name}</h6>
            <div class="text-muted small">Unit: ${item.unit_type}${item.variation ? ' • ' + formatVariationForDisplay(item.variation) : ''}</div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeFromCartAt(${idx})">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="quantity-controls">
                                <small class="text-muted me-2">Qty:</small>
                                <button type="button" class="quantity-btn" onclick="updateCartQuantityAt(${idx}, ${item.quantity - 1})">
                                    <i class="bi bi-dash"></i>
                                </button>
                                <span class="mx-2 fw-bold">${item.quantity}</span>
                                <button type="button" class="quantity-btn" onclick="updateCartQuantityAt(${idx}, ${item.quantity + 1})">
                                    <i class="bi bi-plus"></i>
                                </button>
                            </div>
                            <div class="text-end">
                                <div class="text-muted small">₱${item.price.toFixed(2)} per ${item.unit_type}</div>
                                <div class="fw-bold">₱${(item.price * item.quantity).toFixed(2)}</div>
                            </div>
                        </div>
                    </div>
                `).join('');
                
                // Update cart summary
                const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
                document.getElementById('cartSubtotal').textContent = `₱${subtotal.toFixed(2)}`;
                document.getElementById('cartTotal').textContent = `₱${subtotal.toFixed(2)}`;
                cartSummary.style.display = 'block';
            }
        }
        
        function increaseQuantity(id) {
            const qtyInput = document.getElementById(`qty_${id}`);
            const currentValue = parseInt(qtyInput.value) || 1;
            const maxStock = parseInt(qtyInput.max) || 999;
            qtyInput.value = Math.min(currentValue + 1, maxStock);
        }
        
        function decreaseQuantity(id) {
            const qtyInput = document.getElementById(`qty_${id}`);
            const currentValue = parseInt(qtyInput.value) || 1;
            qtyInput.value = Math.max(currentValue - 1, 1);
        }
        
        function showToast(message, type = 'info') {
            // Create toast element
            const toast = document.createElement('div');
            toast.className = `toast align-items-center text-white bg-${type === 'success' ? 'success' : type === 'error' ? 'danger' : 'info'} border-0`;
            toast.setAttribute('role', 'alert');
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">${message}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;
            
            // Add to page
            let toastContainer = document.querySelector('.toast-container');
            if (!toastContainer) {
                toastContainer = document.createElement('div');
                toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
                document.body.appendChild(toastContainer);
            }
            toastContainer.appendChild(toast);
            
            // Show toast
            const bsToast = new bootstrap.Toast(toast);
            bsToast.show();
            
            // Remove after hiding
            toast.addEventListener('hidden.bs.toast', () => {
                toast.remove();
            });
        }
        
        // Event listeners
        cartToggle.addEventListener('click', () => {
            cartSidebar.classList.add('open');
            cartOverlay.classList.add('show');
        });
        
        closeCart.addEventListener('click', () => {
            cartSidebar.classList.remove('open');
            cartOverlay.classList.remove('show');
        });
        
        cartOverlay.addEventListener('click', () => {
            cartSidebar.classList.remove('open');
            cartOverlay.classList.remove('show');
        });
        
        checkoutBtn.addEventListener('click', () => {
            if (cart.length === 0) {
                showToast('Your cart is empty!', 'error');
                return;
            }
            
            // Hide cart and show checkout modal
            cartSidebar.classList.remove('open');
            cartOverlay.classList.remove('show');
            
            // Update checkout modal with cart items
            updateCheckoutModal();
            
            // Show checkout modal
            const checkoutModal = new bootstrap.Modal(document.getElementById('checkoutModal'));
            checkoutModal.show();
        });
        
        function updateCheckoutModal() {
            const orderSummary = document.getElementById('checkoutOrderSummary');
            const checkoutTotal = document.getElementById('checkoutTotal');
            
            let summaryHTML = '';
            let total = 0;
            
            cart.forEach(item => {
                const itemTotal = item.price * item.quantity;
                total += itemTotal;
                
                summaryHTML += `
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <strong>${item.name}</strong>
            <div class="text-muted small">Unit: ${item.unit_type}${item.variation ? ' • ' + formatVariationForDisplay(item.variation) : ''}</div>
                            <small class="text-muted d-block">₱${item.price.toFixed(2)} per ${item.unit_type} × ${item.quantity}</small>
                        </div>
                        <span>₱${itemTotal.toFixed(2)}</span>
                    </div>
                `;
            });
            
            orderSummary.innerHTML = summaryHTML;
            checkoutTotal.textContent = `₱${total.toFixed(2)}`;
        }
        
        // Checkout form submission
        document.getElementById('finalizeOrder').addEventListener('click', function() {
            if (cart.length === 0) {
                showToast('Your cart is empty!', 'error');
                return;
            }
            
            const paymentMethod = document.querySelector('input[name="modal_payment_method"]:checked').value;
            const gcashInput = document.getElementById('modal_gcash_number');
            const gcashNumberRaw = gcashInput ? gcashInput.value : '';
            
            function normalizeGcash(num) {
                let s = (num || '').trim().replace(/[\s-]/g, '');
                if (s.startsWith('+639') && s.length === 13) return s;
                if (s.startsWith('09') && s.length === 11) return '+63' + s.slice(1);
                if (s.startsWith('9') && s.length === 10) return '+63' + s;
                if (s.startsWith('639') && s.length === 12) return '+' + s;
                return null;
            }
            
            if (paymentMethod === 'gcash') {
                const normalized = normalizeGcash(gcashNumberRaw);
                if (!normalized) {
                    showToast('Please enter a valid GCash number (e.g. 09171234567 or +639171234567).', 'error');
                    if (gcashInput) gcashInput.focus();
                    return;
                }
                document.getElementById('hiddenGcashNumber').value = normalized;
            } else {
                document.getElementById('hiddenGcashNumber').value = '';
            }
            
            // Calculate total
            const total = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            
            // Confirm order
            const confirmMessage = `Confirm order with total amount: ₱${total.toFixed(2)}\nPayment Method: ${paymentMethod === 'gcash' ? 'GCash' : 'Cash'}`;
            
            if (confirm(confirmMessage)) {
                // Set cart data in hidden form
                document.getElementById('cartDataInput').value = JSON.stringify(cart);
                document.getElementById('hiddenPaymentMethod').value = paymentMethod;
                document.getElementById('hiddenTransactionReference').value = '';
                
                // Submit the form
                checkoutForm.submit();
            }
        });
        
        // Payment method change handler in modal
        document.addEventListener('DOMContentLoaded', function() {
            const modalPaymentMethods = document.querySelectorAll('input[name="modal_payment_method"]');
            const modalGcashNumber = document.getElementById('modal_gcash_number');
            
            function updateTransactionField() {
                const selectedMethod = document.querySelector('input[name="modal_payment_method"]:checked');
                if (selectedMethod && selectedMethod.value === 'gcash') {
                    if (modalGcashNumber) {
                        modalGcashNumber.required = true;
                        modalGcashNumber.parentElement.querySelector('.form-label').innerHTML = 
                            'GCash Number <small class="text-danger">(Required for GCash)</small>';
                    }
                } else {
                    if (modalGcashNumber) {
                        modalGcashNumber.required = false;
                        modalGcashNumber.parentElement.querySelector('.form-label').innerHTML = 
                            'GCash Number <small class="text-muted">(Optional for Cash)</small>';
                    }
                }
            }
            
            modalPaymentMethods.forEach(method => {
                method.addEventListener('change', updateTransactionField);
            });
            
            updateTransactionField();
        });

        // Search and Category Filters
        document.addEventListener('DOMContentLoaded', function() {
            var hasJquery = typeof window.$ !== 'undefined';

            if (hasJquery) {
                var $items = $('#productContainer .product-item');
                var selectedCategory = 'all';
                var searchTerm = '';

                function applyFilters() {
                    var q = searchTerm.toLowerCase();
                    $items.each(function() {
                        var $it = $(this);
                        var name = ($it.data('name') || '').toString();
                        var cat = ($it.data('category') || '').toString();
                        var matchSearch = !q || name.indexOf(q) !== -1;
                        var matchCat = (selectedCategory === 'all') || (cat === selectedCategory);
                        $it.toggleClass('d-none', !(matchSearch && matchCat));
                    });
                }

                $('#searchProduct').on('input', function() {
                    searchTerm = $(this).val().trim();
                    applyFilters();
                });

                $('#clearSearch').on('click', function() {
                    $('#searchProduct').val('');
                    searchTerm = '';
                    applyFilters();
                });

                $('.category-pill').on('click', function() {
                    $('.category-pill').removeClass('active');
                    $(this).addClass('active');
                    selectedCategory = ($(this).data('category') || 'all').toString();
                    applyFilters();
                });

                applyFilters();
            } else {
                var items = Array.prototype.slice.call(document.querySelectorAll('#productContainer .product-item'));
                var selectedCategory = 'all';
                var searchTerm = '';

                function applyFilters() {
                    var q = searchTerm.toLowerCase();
                    items.forEach(function(el) {
                        var name = (el.getAttribute('data-name') || '').toString();
                        var cat = (el.getAttribute('data-category') || '').toString();
                        var matchSearch = !q || name.indexOf(q) !== -1;
                        var matchCat = (selectedCategory === 'all') || (cat === selectedCategory);
                        if (matchSearch && matchCat) {
                            el.classList.remove('d-none');
                        } else {
                            el.classList.add('d-none');
                        }
                    });
                }

                var searchInput = document.getElementById('searchProduct');
                var clearBtn = document.getElementById('clearSearch');
                var pills = Array.prototype.slice.call(document.querySelectorAll('.category-pill'));

                if (searchInput) {
                    searchInput.addEventListener('input', function() {
                        searchTerm = searchInput.value.trim();
                        applyFilters();
                    });
                }

                if (clearBtn) {
                    clearBtn.addEventListener('click', function() {
                        if (searchInput) searchInput.value = '';
                        searchTerm = '';
                        applyFilters();
                    });
                }

                pills.forEach(function(pill) {
                    pill.addEventListener('click', function() {
                        pills.forEach(function(p) { p.classList.remove('active'); });
                        pill.classList.add('active');
                        selectedCategory = (pill.getAttribute('data-category') || 'all').toString();
                        applyFilters();
                    });
                });

                applyFilters();
            }
        });
    </script>
</body>
</html>
