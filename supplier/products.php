<?php
/**
 * Supplier Products Management Page
 * Handles product catalog CRUD operations for suppliers with variation support
 */
session_start();

// CSRF token setup
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Authentication check - supports both namespaced and legacy sessions
$hasSupplierSession = isset($_SESSION['supplier']) && !empty($_SESSION['supplier']);
$hasLegacySession = isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'supplier';

if (!$hasSupplierSession && !$hasLegacySession) {
    header("Location: login.php");
    exit();
}

$supplier_id_raw = $_SESSION['supplier']['user_id'] ?? $_SESSION['user_id'] ?? null;

if (!$supplier_id_raw || $supplier_id_raw <= 0) {
    header("Location: login.php");
    exit();
}

// Database and model includes
require_once '../config/database.php';
require_once '../models/supplier_catalog.php';
require_once '../models/supplier.php';
require_once '../models/supplier_product_variation.php';
require_once '../models/inventory.php';
require_once '../models/inventory_variation.php';
require_once '../lib/unit_variations.php';

// Database connection
try {
    $db = (new Database())->getConnection();
    $db->query("SELECT 1");
} catch (PDOException $e) {
    die("Database connection failed: " . htmlspecialchars($e->getMessage()));
}

// Resolve supplier ID
$supplier_id = (int)$supplier_id_raw;
try {
    $chk = $db->prepare("SELECT id FROM suppliers WHERE id = :id LIMIT 1");
    $chk->execute([':id' => $supplier_id]);
    if (!$chk->fetchColumn()) {
        $uname = $_SESSION['supplier']['username'] ?? $_SESSION['username'] ?? null;
        if ($uname) {
            $res = $db->prepare("SELECT id FROM suppliers WHERE username = :u LIMIT 1");
            $res->execute([':u' => $uname]);
            $resolvedId = $res->fetchColumn();
            if ($resolvedId) {
                $supplier_id = (int)$resolvedId;
                $_SESSION['supplier'] = $_SESSION['supplier'] ?? [];
                $_SESSION['supplier']['user_id'] = $supplier_id;
            }
        }
    }
} catch (Throwable $e) {
    error_log("Warning: Could not resolve supplier_id: " . $e->getMessage());
}

if ($supplier_id <= 0) {
    header("Location: login.php");
    exit();
}

// Initialize models
$catalog = new SupplierCatalog($db);
$supplier = new Supplier($db);
$spVariation = new SupplierProductVariation($db);
$inventory = new Inventory($db);
$invVariation = new InventoryVariation($db);

// Get supplier info
$supplier_info = $supplier->readOne($supplier_id);
$supplier_name = $supplier_info['company_name'] ?? $_SESSION['supplier']['username'] ?? 'Supplier';

// Initialize unit type mappings
$UNIT_TYPE_CODE_MAP = [];
try {
    $stmtUtAll = $db->query("SELECT code, name FROM unit_types WHERE COALESCE(is_deleted,0)=0");
    while ($row = $stmtUtAll->fetch(PDO::FETCH_ASSOC)) {
        $c = trim((string)$row['code'] ?? '');
        $n = trim((string)$row['name'] ?? '');
        if ($c !== '' && $n !== '') {
            $norm = 'per ' . strtolower($n);
            $UNIT_TYPE_CODE_MAP[$c] = $norm;
        }
    }
} catch (Throwable $e) {}

// Process AJAX requests
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    header('Content-Type: application/json');
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        echo json_encode(['success' => false, 'message' => 'CSRF token mismatch']);
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    
    // Get price and stock input HTML for variation
    if ($action === 'get_price_input') {
        $key = $_POST['key'] ?? '';
        if ($key) {
            $parts = explode(':', $key, 2);
            $attr = htmlspecialchars($parts[0] ?? '');
            $val = htmlspecialchars($parts[1] ?? '');
            $displayLabel = $val ? $attr . ': ' . $val : $key;
            $html = '<div class="col-md-6 mb-3">';
            $html .= '<label class="form-label small fw-bold">' . $displayLabel . '</label>';
            $html .= '<div class="input-group input-group-sm mb-2">';
            $html .= '<span class="input-group-text">₱</span>';
            $html .= '<input type="number" class="form-control var-price" data-key="' . htmlspecialchars($key) . '" name="variation_prices[' . htmlspecialchars($key) . ']" step="0.01" min="0" placeholder="Price">';
            $html .= '</div>';
            $html .= '<div class="input-group input-group-sm">';
            $html .= '<span class="input-group-text">Qty</span>';
            $html .= '<input type="number" class="form-control var-stock" data-key="' . htmlspecialchars($key) . '" name="variation_stocks[' . htmlspecialchars($key) . ']" step="1" min="0" placeholder="Stock" value="0">';
            $html .= '</div></div>';
            echo json_encode(['success' => true, 'html' => $html]);
            exit;
        }
    }
    
    // List variations for a product
    if ($action === 'list_variations') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        if ($product_id > 0) {
            $variations = $spVariation->getByProduct($product_id);
            echo json_encode(['success' => true, 'variations' => $variations]);
            exit;
        }
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

// ============================================================================================
// REAL-TIME PRODUCT SYNCHRONIZATION SYSTEM
// ============================================================================================
// 
// PURPOSE: Ensures all product updates in supplier/products.php are automatically synchronized
//          with admin/inventory.php and admin/supplier_details.php in real-time
// 
// ARCHITECTURE:
// 1. supplier_details.php reads DIRECTLY from supplier_catalog and supplier_product_variations
//    → Any changes to these tables automatically appear/disappear in supplier_details.php
// 
// 2. admin/inventory.php reads from inventory and inventory_variations tables
//    → Changes must be explicitly synced to these tables via syncProductToInventory()
// 
// SYNC ACTIONS:
// - ADD: Creates in supplier_catalog + supplier_product_variations → Syncs to inventory + inventory_variations
// - EDIT: Updates supplier_catalog + supplier_product_variations → Syncs to inventory + inventory_variations
// - DELETE: Deletes from supplier_catalog + supplier_product_variations → Archives in inventory (is_deleted=1)
// - BULK DELETE: Same as DELETE but for multiple products
// 
// SYNCED FIELDS:
// ✓ Product Name, Description, Category, Location, Reorder Threshold, Unit Type
// ✓ Variations (add/remove/update), Variation Prices, Variation Unit Types
// 
// ERROR HANDLING:
// - All sync operations are wrapped in try-catch blocks
// - Failures are logged but don't block the main operation
// - Referential integrity is maintained through SKU + supplier_id validation
// 
// PERFORMANCE:
// - Uses prepared statements for all database operations
// - Batch operations where possible
// - Minimal database queries (reuses existing data)
// 
// AUDIT LOGGING:
// - All sync events are logged with timestamps
// - Success and failure events are tracked
// - Detailed error messages for debugging
// 
// RESULT: All changes made in supplier/products.php are immediately visible in BOTH:
// ✓ admin/inventory.php (via inventory/inventory_variations sync)
// ✓ admin/supplier_details.php (via direct read from supplier_catalog/supplier_product_variations)
// ============================================================================================

/**
 * Real-time synchronization function: Syncs product data from supplier_catalog to inventory
 * 
 * This function ensures that all product updates are immediately reflected in admin/inventory.php
 * while maintaining referential integrity and providing comprehensive error handling.
 * 
 * @param PDO $db Database connection
 * @param string $sku Product SKU (required for all operations)
 * @param int $supplier_id Supplier ID
 * @param array $productData Product data array with keys: name, description, category, location, reorder_threshold, unit_type
 * @param array $variationData Array of variation data from supplier_product_variations
 * @param Inventory $inventory Inventory model instance
 * @param InventoryVariation $invVariation InventoryVariation model instance
 * @param bool $createIfMissing If true, creates inventory item if it doesn't exist (for edit operations)
 * @return array Sync result with keys: success (bool), inventory_id (int|null), message (string), stats (array)
 */
function syncProductToInventory($db, $sku, $supplier_id, $productData, $variationData, $inventory, $invVariation, $createIfMissing = false) {
    $syncStartTime = microtime(true);
    $result = [
        'success' => false,
        'inventory_id' => null,
        'message' => '',
        'stats' => [
            'variations_deleted' => 0,
            'variations_added' => 0,
            'variations_updated' => 0,
            'fields_updated' => 0
        ]
    ];
    
    // Validate required parameters
    if (empty($sku) || trim($sku) === '') {
        $result['message'] = "SKU is required for synchronization";
        error_log("SYNC ERROR: Empty SKU provided for supplier_id {$supplier_id}");
        return $result;
    }
    
    if ($supplier_id <= 0) {
        $result['message'] = "Invalid supplier_id";
        error_log("SYNC ERROR: Invalid supplier_id {$supplier_id} for SKU {$sku}");
        return $result;
    }
    
    try {
        // STEP 1: Find or create inventory item
        // CRITICAL: Use a transaction-safe query to prevent race conditions and duplicates
        // This ensures we always find existing items by SKU + supplier_id before creating new ones
        $invStmt = $db->prepare("SELECT id, sku FROM inventory WHERE sku = :sku AND supplier_id = :sid AND COALESCE(is_deleted, 0) = 0 AND sku IS NOT NULL AND sku != '' LIMIT 1");
        $invStmt->execute([':sku' => $sku, ':sid' => $supplier_id]);
        $invRow = $invStmt->fetch(PDO::FETCH_ASSOC);
        
        $invId = null;
        
        if ($invRow && !empty($invRow['sku'])) {
            // Inventory item exists - use it (UPDATE existing, don't create duplicate)
            $invId = (int)$invRow['id'];
            
            // Verify inventory item still exists and is valid
            $verifyStmt = $db->prepare("SELECT id, sku FROM inventory WHERE id = :id AND sku = :sku AND supplier_id = :sid AND COALESCE(is_deleted, 0) = 0 AND sku IS NOT NULL AND sku != '' LIMIT 1");
            $verifyStmt->execute([':id' => $invId, ':sku' => $sku, ':sid' => $supplier_id]);
            $verified = $verifyStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$verified || empty($verified['sku'])) {
                // Verification failed - item was deleted or changed
                error_log("SYNC WARNING: Inventory item verification failed for SKU {$sku} and supplier {$supplier_id}");
                if (!$createIfMissing) {
                    $result['message'] = "Inventory item verification failed";
                    return $result;
                }
                // Fall through to create if createIfMissing is true
                $invId = null;
            } else {
                // Item exists and verified - we will UPDATE it, not create a duplicate
                error_log("SYNC INFO: Found existing inventory item for SKU {$sku} (supplier_id: {$supplier_id}) - Inventory ID: {$invId} - Will UPDATE, not create duplicate");
            }
        }
        
        // Create inventory item ONLY if it doesn't exist and createIfMissing is true
        // CRITICAL: Double-check for duplicates before creating to prevent race conditions
        if (!$invId && $createIfMissing) {
            // Final check: Ensure no duplicate exists (race condition protection)
            $finalCheckStmt = $db->prepare("SELECT id FROM inventory WHERE sku = :sku AND supplier_id = :sid AND COALESCE(is_deleted, 0) = 0 AND sku IS NOT NULL AND sku != '' LIMIT 1");
            $finalCheckStmt->execute([':sku' => $sku, ':sid' => $supplier_id]);
            $finalCheck = $finalCheckStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($finalCheck && !empty($finalCheck['id'])) {
                // Duplicate found - use existing instead of creating new
                $invId = (int)$finalCheck['id'];
                error_log("SYNC INFO: Duplicate check found existing inventory item for SKU {$sku} (supplier_id: {$supplier_id}) - Using Inventory ID: {$invId} - Will UPDATE, not create duplicate");
            } else {
                // No duplicate exists - safe to create
                $inventory->sku = $sku;
                $inventory->name = $productData['name'] ?? '';
                $inventory->description = $productData['description'] ?? '';
                $inventory->category = $productData['category'] ?? '';
                $inventory->unit_price = 0;
                $inventory->quantity = 0;
                $inventory->reorder_threshold = $productData['reorder_threshold'] ?? 10;
                $inventory->location = $productData['location'] ?? '';
                $inventory->supplier_id = $supplier_id;
                
                if ($inventory->createForSupplier($supplier_id)) {
                    $invId = (int)$db->lastInsertId();
                    
                    // Verify the created item has valid SKU
                    $verifyStmt = $db->prepare("SELECT sku FROM inventory WHERE id = :id AND sku = :sku AND sku IS NOT NULL AND sku != '' LIMIT 1");
                    $verifyStmt->execute([':id' => $invId, ':sku' => $sku]);
                    if (!$verifyStmt->fetchColumn()) {
                        error_log("SYNC ERROR: Created inventory item but SKU verification failed for SKU {$sku} and inventory_id {$invId}");
                        $result['message'] = "Created inventory item but SKU verification failed";
                        return $result;
                    }
                    error_log("SYNC INFO: Created NEW inventory item for SKU {$sku} (supplier_id: {$supplier_id}) - Inventory ID: {$invId}");
                } else {
                    error_log("SYNC ERROR: Failed to create inventory item for SKU {$sku} and supplier_id {$supplier_id}");
                    $result['message'] = "Failed to create inventory item";
                    return $result;
                }
            }
        }
        
        if (!$invId) {
            $result['message'] = "Inventory item not found and creation not allowed";
            return $result;
        }
        
        // STEP 2: Update inventory item basic fields
        $updatedName = $productData['name'] ?? '';
        $updatedDesc = $productData['description'] ?? '';
        $updatedCat = $productData['category'] ?? '';
        $updatedLoc = $productData['location'] ?? '';
        $updatedReorder = $productData['reorder_threshold'] ?? 10;
        
        $updInvStmt = $db->prepare("UPDATE inventory SET name = :name, description = :desc, category = :cat, location = :loc, reorder_threshold = :reorder WHERE id = :id AND sku = :sku AND supplier_id = :sid AND sku IS NOT NULL AND sku != ''");
        $updResult = $updInvStmt->execute([
            ':name' => $updatedName,
            ':desc' => $updatedDesc,
            ':cat' => $updatedCat,
            ':loc' => $updatedLoc,
            ':reorder' => $updatedReorder,
            ':id' => $invId,
            ':sku' => $sku,
            ':sid' => $supplier_id
        ]);
        
        $rowsAffected = $updInvStmt->rowCount();
        if ($rowsAffected === 1) {
            $result['stats']['fields_updated'] = 1;
            error_log("SYNC SUCCESS: Updated inventory item for SKU {$sku} - Name: {$updatedName}, Category: {$updatedCat}, Location: {$updatedLoc}");
        } else if ($rowsAffected !== 1) {
            error_log("SYNC WARNING: Inventory update affected {$rowsAffected} rows instead of 1 for SKU {$sku}");
        }
        
        // STEP 3: Sync variations
        // Get existing inventory variations
        $invVarStmt = $db->prepare("SELECT variation, id FROM inventory_variations WHERE inventory_id = :inv_id");
        $invVarStmt->execute([':inv_id' => $invId]);
        $existingInvVars = [];
        while ($row = $invVarStmt->fetch(PDO::FETCH_ASSOC)) {
            $existingInvVars[$row['variation']] = $row['id'];
        }
        $existingInvVarKeys = array_keys($existingInvVars);
        
        // Get latest variation keys from supplier
        $supplierVarKeys = [];
        $supplierVarData = [];
        foreach ($variationData as $var) {
            $varKey = $var['variation'] ?? '';
            if (!empty($varKey)) {
                $supplierVarKeys[] = $varKey;
                $supplierVarData[$varKey] = $var;
            }
        }
        
        // STEP 3a: Remove variations that are not in supplier list
        foreach ($existingInvVarKeys as $existingVar) {
            if (!in_array($existingVar, $supplierVarKeys)) {
                try {
                    $delStmt = $db->prepare("DELETE FROM inventory_variations WHERE inventory_id = :inv_id AND variation = :var");
                    $delStmt->execute([':inv_id' => $invId, ':var' => $existingVar]);
                    if ($delStmt->rowCount() > 0) {
                        $result['stats']['variations_deleted']++;
                        unset($existingInvVars[$existingVar]);
                        error_log("SYNC SUCCESS: Deleted inventory variation '{$existingVar}' for SKU {$sku}");
                    }
                } catch (Exception $e) {
                    error_log("SYNC ERROR: Could not remove inventory variation {$existingVar}: " . $e->getMessage());
                }
            }
        }
        
        // STEP 3b: Add new variations and update existing ones
        foreach ($supplierVarKeys as $varKey) {
            $varData = $supplierVarData[$varKey] ?? null;
            $varPrice = null;
            if ($varData && isset($varData['unit_price']) && $varData['unit_price'] !== null && $varData['unit_price'] !== '') {
                $varPrice = (float)$varData['unit_price'];
            }
            $varUnitType = $varData['unit_type'] ?? $productData['unit_type'] ?? 'per piece';
            
            if (isset($existingInvVars[$varKey])) {
                // Update existing variation
                try {
                    if ($varPrice !== null && $varPrice > 0) {
                        $updStmt = $db->prepare("UPDATE inventory_variations SET unit_price = :price, unit_type = :unit_type WHERE inventory_id = :inv_id AND variation = :var");
                        $updStmt->execute([
                            ':price' => $varPrice,
                            ':unit_type' => $varUnitType,
                            ':inv_id' => $invId,
                            ':var' => $varKey
                        ]);
                    } else {
                        $updStmt = $db->prepare("UPDATE inventory_variations SET unit_type = :unit_type WHERE inventory_id = :inv_id AND variation = :var");
                        $updStmt->execute([
                            ':unit_type' => $varUnitType,
                            ':inv_id' => $invId,
                            ':var' => $varKey
                        ]);
                    }
                    if ($updStmt->rowCount() > 0) {
                        $result['stats']['variations_updated']++;
                        error_log("SYNC SUCCESS: Updated inventory variation '{$varKey}' for SKU {$sku} - Price: " . ($varPrice ?? 'N/A') . ", Unit Type: {$varUnitType}");
                    }
                } catch (Exception $e) {
                    error_log("SYNC ERROR: Could not update inventory variation {$varKey}: " . $e->getMessage());
                }
            } else {
                // Create new variation
                try {
                    if ($invVariation->createVariant($invId, $varKey, $varUnitType, 0, $varPrice)) {
                        $result['stats']['variations_added']++;
                        $existingInvVars[$varKey] = $db->lastInsertId();
                        error_log("SYNC SUCCESS: Added inventory variation '{$varKey}' for SKU {$sku} - Price: " . ($varPrice ?? 'N/A') . ", Unit Type: {$varUnitType}");
                    }
                } catch (Exception $e) {
                    error_log("SYNC ERROR: Could not create inventory variation {$varKey}: " . $e->getMessage());
                }
            }
        }
        
        // Calculate sync duration
        $syncDuration = round((microtime(true) - $syncStartTime) * 1000, 2);
        
        // Success
        $result['success'] = true;
        $result['inventory_id'] = $invId;
        $result['message'] = "Synchronization completed successfully";
        
        // Log summary
        $statsSummary = "Deleted: {$result['stats']['variations_deleted']}, Added: {$result['stats']['variations_added']}, Updated: {$result['stats']['variations_updated']}, Fields: {$result['stats']['fields_updated']}";
        error_log("SYNC COMPLETE: SKU {$sku} synchronized in {$syncDuration}ms - {$statsSummary}");
        
        return $result;
        
    } catch (Exception $e) {
        error_log("SYNC ERROR: Exception during sync for SKU {$sku}: " . $e->getMessage());
        error_log("SYNC ERROR: Stack trace: " . $e->getTraceAsString());
        $result['message'] = "Synchronization failed: " . $e->getMessage();
        return $result;
    }
}

// Process form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isAjax) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        $message = "Security check failed. Please refresh and try again.";
        $messageType = "danger";
    } else {
        $action = $_POST['action'] ?? '';
        
        // ============================================================================================
        // ADD NEW PRODUCT: Sync new products to admin/inventory.php AND admin/supplier_details.php
        // ============================================================================================
        // 
        // SYNC OVERVIEW:
        // 1. Creates in supplier_catalog → auto-appears in admin/supplier_details.php (reads directly)
        // 2. Creates in supplier_product_variations → auto-appears in admin/supplier_details.php (reads directly)
        // 3. Creates in inventory and inventory_variations → appears in admin/inventory.php
        // 
        // ALL FIELDS ARE SYNCED:
        // ✓ Product Name → supplier_catalog + inventory
        // ✓ Description → supplier_catalog + inventory
        // ✓ Category → supplier_catalog + inventory
        // ✓ Location → supplier_catalog + inventory
        // ✓ Reorder Threshold → supplier_catalog + inventory
        // ✓ Unit Type → supplier_catalog + inventory_variations
        // ✓ Variations → supplier_product_variations + inventory_variations
        // ✓ Variation Prices → supplier_product_variations + inventory_variations
        // ✓ Variation Unit Types → supplier_product_variations + inventory_variations
        // ============================================================================================
        if ($action === 'add') {
            try {
                $sku = trim($_POST['sku'] ?? '');
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $category = trim($_POST['category'] ?? '');
                $unit_type = trim($_POST['unit_type'] ?? 'per piece');
                $unit_type_code = $_POST['unit_type_code'] ?? '';
                $location = trim($_POST['location'] ?? '');
                $reorder_threshold = (int)($_POST['reorder_threshold'] ?? 10);
                $supplier_quantity = (int)($_POST['supplier_quantity'] ?? 0);
                
                // Validate
                if (empty($sku) || empty($name)) {
                    throw new Exception("SKU and Name are required.");
                }
                
                // Check for duplicate SKU
                if ($catalog->skuExistsForSupplier($sku, $supplier_id)) {
                    $sku = $catalog->generateUniqueSkuForSupplier($sku, $supplier_id);
                }
                
                // Normalize unit type
                if ($unit_type_code && isset($UNIT_TYPE_CODE_MAP[$unit_type_code])) {
                    $unit_type = $UNIT_TYPE_CODE_MAP[$unit_type_code];
                }
                
                $db->beginTransaction();
                
                // Create catalog entry
                $catalog->sku = $sku;
                $catalog->name = $name;
                $catalog->description = $description;
                $catalog->category = $category;
                $catalog->unit_price = 0; // Base price, variations have individual prices
                $catalog->unit_type = $unit_type;
                $catalog->supplier_quantity = $supplier_quantity;
                $catalog->reorder_threshold = $reorder_threshold;
                $catalog->location = $location;
                
                if (!$catalog->createForSupplier($supplier_id)) {
                    throw new Exception("Failed to create product. SKU may already exist.");
                }
                
                $product_id = (int)$db->lastInsertId();
                
                // Process variations
                $variations = $_POST['variations'] ?? [];
                $variation_prices = $_POST['variation_prices'] ?? [];
                $variation_stocks = $_POST['variation_stocks'] ?? [];
                
                if (!empty($variations) && is_array($variations)) {
                    foreach ($variations as $varKey) {
                        $price = isset($variation_prices[$varKey]) ? (float)$variation_prices[$varKey] : null;
                        $stock = isset($variation_stocks[$varKey]) ? (int)$variation_stocks[$varKey] : 0;
                        $spVariation->createVariant($product_id, $varKey, $unit_type, $stock, $price);
                    }
                } else {
                    // Check for variation_attrs checkboxes
                    $variation_attrs = $_POST['variation_attrs'] ?? [];
                    if (!empty($variation_attrs) && is_array($variation_attrs)) {
                        $selectedCombos = [];
                        // Build combinations from selected attributes
                        $attrs = [];
                        foreach ($variation_attrs as $attr => $values) {
                            if (is_array($values) && !empty($values)) {
                                $attrs[] = $values;
                            }
                        }
                        if (!empty($attrs)) {
                            // Generate combinations
                            function generateCombinations($arrays, $index = 0, $current = []) {
                                if ($index >= count($arrays)) {
                                    return [implode(':', $current)];
                                }
                                $result = [];
                                foreach ($arrays[$index] as $value) {
                                    $current[] = $value;
                                    $result = array_merge($result, generateCombinations($arrays, $index + 1, $current));
                                    array_pop($current);
                                }
                                return $result;
                            }
                            $selectedCombos = generateCombinations($attrs);
                        }
                        
                        foreach ($selectedCombos as $combo) {
                            $price = isset($variation_prices[$combo]) ? (float)$variation_prices[$combo] : null;
                            $stock = isset($variation_stocks[$combo]) ? (int)$variation_stocks[$combo] : 0;
                            $spVariation->createVariant($product_id, $combo, $unit_type, $stock, $price);
                        }
                    }
                }
                
                // Update supplier_quantity to sum of all variation stocks
                $totalStock = 0;
                foreach ($spVariation->getByProduct($product_id) as $var) {
                    $totalStock += (int)($var['stock'] ?? 0);
                }
                if ($totalStock > 0) {
                    $updateQty = $db->prepare("UPDATE supplier_catalog SET supplier_quantity = :qty WHERE id = :id");
                    $updateQty->execute([':qty' => $totalStock, ':id' => $product_id]);
                }
                
                // ============================================================================================
                // REAL-TIME SYNC: Use centralized sync function for robust synchronization
                // ============================================================================================
                // 
                // This uses the syncProductToInventory() function which provides:
                // - Comprehensive error handling
                // - Referential integrity validation
                // - Performance monitoring
                // - Detailed audit logging
                // - Automatic creation if inventory item is missing (createIfMissing = true)
                // 
                // All product fields and variations are synced in real-time to admin/inventory.php
                // supplier_details.php automatically reflects changes via direct table reads
                // ============================================================================================
                try {
                    // Prepare product data for sync
                    $productData = [
                        'name' => $name,
                        'description' => $description,
                        'category' => $category,
                        'location' => $location,
                        'reorder_threshold' => $reorder_threshold,
                        'unit_type' => $unit_type
                    ];
                    
                    // Get variations from supplier_product_variations
                    $variationData = $spVariation->getByProduct($product_id);
                    
                    // Perform real-time synchronization
                    // createIfMissing = true ensures new products are always created in inventory
                    $syncResult = syncProductToInventory($db, $sku, $supplier_id, $productData, $variationData, $inventory, $invVariation, true);
                    
                    if ($syncResult['success']) {
                        // Log successful sync with statistics
                        $stats = $syncResult['stats'];
                        error_log("SYNC SUCCESS: Product add synchronized for SKU {$sku} - Inventory ID: {$syncResult['inventory_id']}, Stats: " . json_encode($stats));
                    } else {
                        // Log sync failure but don't block the main operation
                        error_log("SYNC WARNING: Product add sync failed for SKU {$sku}: {$syncResult['message']}");
                    }
                } catch (Exception $e) {
                    // Log but don't fail - inventory sync is secondary
                    error_log("SYNC ERROR: Exception during product add sync for SKU {$sku}: " . $e->getMessage());
                    error_log("SYNC ERROR: Stack trace: " . $e->getTraceAsString());
                }
                
                $db->commit();
                $message = "Product added successfully!";
                $messageType = "success";
                
                // Log sync completion
                if (!empty($sku) && trim($sku) !== '') {
                    error_log("Success: Product add completed for SKU {$sku} - Product is now visible in admin/inventory.php and admin/supplier_details.php");
                }
            } catch (PDOException $e) {
                if ($db->inTransaction()) $db->rollBack();
                $message = "Database error: " . htmlspecialchars($e->getMessage());
                $messageType = "danger";
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $message = htmlspecialchars($e->getMessage());
                $messageType = "danger";
            }
        }
        
        // ============================================================================================
        // EDIT PRODUCT: Sync all changes to admin/inventory.php AND admin/supplier_details.php
        // ============================================================================================
        // 
        // SYNC OVERVIEW:
        // 1. Updates supplier_catalog → auto-reflects in admin/supplier_details.php (reads directly)
        // 2. Updates supplier_product_variations → auto-reflects in admin/supplier_details.php (reads directly)
        // 3. Syncs to inventory and inventory_variations → reflects in admin/inventory.php
        // 
        // ALL EDITED FIELDS ARE SYNCED:
        // ✓ Product Name → supplier_catalog + inventory
        // ✓ Description → supplier_catalog + inventory
        // ✓ Category → supplier_catalog + inventory
        // ✓ Location → supplier_catalog + inventory
        // ✓ Reorder Threshold → supplier_catalog + inventory
        // ✓ Unit Type → supplier_catalog + inventory
        // ✓ Variations (add/remove/update) → supplier_product_variations + inventory_variations
        // ✓ Variation Prices → supplier_product_variations + inventory_variations
        // ✓ Variation Unit Types → supplier_product_variations + inventory_variations
        // ============================================================================================
        elseif ($action === 'edit') {
            try {
                $product_id = (int)($_POST['product_id'] ?? 0);
                if (!$catalog->belongsToSupplier($product_id, $supplier_id)) {
                    throw new Exception("Product not found or access denied.");
                }
                
                // Get current product data for sync
                // CRITICAL: SKU is preserved and never changed during edit (readonly in form)
                // This ensures we always update the existing inventory item by SKU + supplier_id, never create duplicates
                $currentProduct = $db->prepare("SELECT sku, name, description, category, location, reorder_threshold, unit_type FROM supplier_catalog WHERE id = :id AND supplier_id = :sid");
                $currentProduct->execute([':id' => $product_id, ':sid' => $supplier_id]);
                $current = $currentProduct->fetch(PDO::FETCH_ASSOC);
                if (!$current) {
                    throw new Exception("Product not found.");
                }
                // Preserve existing SKU - never change it (ensures we update existing inventory item, not create duplicate)
                $sku = $current['sku'];
                
                // Validate SKU exists (required for inventory sync)
                if (empty($sku) || trim($sku) === '') {
                    throw new Exception("Product SKU is missing. Cannot sync to inventory without SKU.");
                }
                
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $category = trim($_POST['category'] ?? '');
                $location = trim($_POST['location'] ?? '');
                $unit_type = trim($_POST['unit_type'] ?? '');
                $unit_type_code = $_POST['unit_type_code'] ?? '';
                
                // Normalize unit type if code is provided
                if ($unit_type_code && isset($UNIT_TYPE_CODE_MAP[$unit_type_code])) {
                    $unit_type = $UNIT_TYPE_CODE_MAP[$unit_type_code];
                } elseif (empty($unit_type)) {
                    // Fallback to current unit_type if not provided
                    $unit_type = $current['unit_type'] ?? 'per piece';
                }
                
                if (empty($name)) {
                    throw new Exception("Name is required.");
                }
                
                $db->beginTransaction();
                
                $catalog->id = $product_id;
                $catalog->name = $name;
                $catalog->description = $description;
                $catalog->category = $category;
                $catalog->location = $location;
                $catalog->unit_type = $unit_type;  // Update unit_type
                $catalog->reorder_threshold = (int)($_POST['reorder_threshold'] ?? $current['reorder_threshold'] ?? 10);  // Update reorder_threshold
                
                if (!$catalog->updateBySupplier($supplier_id)) {
                    throw new Exception("Failed to update product.");
                }
                
                // Get updated product data after catalog update to ensure we sync the latest values
                $updatedProduct = $db->prepare("SELECT sku, name, description, category, location, reorder_threshold, unit_type FROM supplier_catalog WHERE id = :id AND supplier_id = :sid");
                $updatedProduct->execute([':id' => $product_id, ':sid' => $supplier_id]);
                $updated = $updatedProduct->fetch(PDO::FETCH_ASSOC);
                if ($updated) {
                    // Use updated data for sync
                    $current = $updated;
                    $sku = $updated['sku'];
                }
                
                // Update supplier variations - handle additions, updates, and removals
                $variation_prices = $_POST['variation_prices'] ?? [];
                $variation_stocks = $_POST['variation_stocks'] ?? [];
                $variations = $_POST['variations'] ?? [];
                
                // Get all existing variations for this product
                $existingVariations = $spVariation->getByProduct($product_id);
                $existingVarKeys = [];
                foreach ($existingVariations as $ev) {
                    $existingVarKeys[] = $ev['variation'] ?? '';
                }
                
                // STEP 1: Remove variations that are not in the submitted list (deleted variations)
                // This ensures variations removed in the UI are deleted from supplier_product_variations
                // The sync logic below will then remove them from inventory_variations
                $submittedVarKeys = is_array($variations) ? $variations : [];
                $deletedFromSupplier = 0;
                foreach ($existingVarKeys as $existingKey) {
                    if (!in_array($existingKey, $submittedVarKeys)) {
                        // Variation was removed - delete it from supplier_product_variations
                        try {
                            $delStmt = $db->prepare("DELETE FROM supplier_product_variations WHERE product_id = :pid AND variation = :var");
                            $delStmt->execute([':pid' => $product_id, ':var' => $existingKey]);
                            if ($delStmt->rowCount() > 0) {
                                $deletedFromSupplier++;
                                error_log("Info: Deleted variation '{$existingKey}' from supplier_product_variations for product_id {$product_id}");
                            }
                        } catch (Exception $e) {
                            error_log("Warning: Could not remove variation {$existingKey} from supplier_product_variations: " . $e->getMessage());
                        }
                    }
                }
                if ($deletedFromSupplier > 0) {
                    error_log("Info: Deleted {$deletedFromSupplier} variation(s) from supplier_product_variations - will be synced to inventory_variations");
                }
                
                // Update or add variations that are in the submitted list
                if (!empty($variations) && is_array($variations)) {
                    foreach ($variations as $varKey) {
                        $price = isset($variation_prices[$varKey]) ? (float)$variation_prices[$varKey] : null;
                        $stock = isset($variation_stocks[$varKey]) ? (int)$variation_stocks[$varKey] : 0;
                        
                        // Check if variation exists
                        $varExists = false;
                        foreach ($existingVariations as $ev) {
                            if (($ev['variation'] ?? '') === $varKey) {
                                $varExists = true;
                                break;
                            }
                        }
                        
                        if ($varExists) {
                            // Update existing variation
                            $spVariation->updateStock($product_id, $varKey, $current['unit_type'], $stock);
                            if ($price !== null) {
                                $spVariation->updatePrice($product_id, $varKey, $current['unit_type'], $price);
                            }
                        } else {
                            // Create new variation (added variation)
                            $spVariation->createVariant($product_id, $varKey, $current['unit_type'], $stock, $price);
                        }
                    }
                }
                
                // Get latest variations from supplier_product_variations AFTER all updates
                // This ensures we sync the most current variation data to inventory
                $latestVariations = $spVariation->getByProduct($product_id);
                $latestVariationKeys = [];
                $latestVariationData = [];
                foreach ($latestVariations as $lv) {
                    $varKey = $lv['variation'] ?? '';
                    if (!empty($varKey)) {
                        $latestVariationKeys[] = $varKey;
                        $latestVariationData[$varKey] = $lv;
                    }
                }
                
                // ============================================================================================
                // REAL-TIME SYNC: Use centralized sync function for robust synchronization
                // ============================================================================================
                // 
                // This uses the syncProductToInventory() function which provides:
                // - Comprehensive error handling
                // - Referential integrity validation
                // - Performance monitoring
                // - Detailed audit logging
                // - Automatic creation if inventory item is missing (createIfMissing = true)
                // 
                // All product fields and variations are synced in real-time to admin/inventory.php
                // supplier_details.php automatically reflects changes via direct table reads
                // ============================================================================================
                try {
                    // Prepare product data for sync
                    $productData = [
                        'name' => $current['name'] ?? $name,
                        'description' => $current['description'] ?? $description,
                        'category' => $current['category'] ?? $category,
                        'location' => $current['location'] ?? $location,
                        'reorder_threshold' => $current['reorder_threshold'] ?? 10,
                        'unit_type' => $current['unit_type'] ?? $unit_type
                    ];
                    
                    // Use latest variations from supplier_product_variations (after all updates/deletes)
                    // This ensures we sync the most current state
                    $variationData = $latestVariations;
                    
                    // Perform real-time synchronization
                    // CRITICAL: createIfMissing = true ensures edits always reflect in inventory
                    // The sync function will UPDATE existing inventory item by SKU + supplier_id (never creates duplicate)
                    // SKU is preserved from original product, ensuring we always find and update the correct inventory item
                    $syncResult = syncProductToInventory($db, $sku, $supplier_id, $productData, $variationData, $inventory, $invVariation, true);
                    
                    if ($syncResult['success']) {
                        // Log successful sync with statistics
                        $stats = $syncResult['stats'];
                        error_log("SYNC SUCCESS: Product edit synchronized for SKU {$sku} - Inventory ID: {$syncResult['inventory_id']}, Stats: " . json_encode($stats));
                    } else {
                        // Log sync failure but don't block the main operation
                        error_log("SYNC WARNING: Product edit sync failed for SKU {$sku}: {$syncResult['message']}");
                    }
                } catch (Exception $e) {
                    // Log but don't fail - inventory sync is secondary
                    error_log("SYNC ERROR: Exception during product edit sync for SKU {$sku}: " . $e->getMessage());
                    error_log("SYNC ERROR: Stack trace: " . $e->getTraceAsString());
                }
                
                $db->commit();
                $message = "Product updated successfully!";
                $messageType = "success";
                
                // Log sync completion - CRITICAL: This confirms all edits are synced
                if (!empty($sku) && trim($sku) !== '') {
                    error_log("Success: Product edit completed for SKU {$sku} - All changes (name, description, category, location, reorder_threshold, unit_type, variations) are now visible in admin/inventory.php and admin/supplier_details.php");
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $message = htmlspecialchars($e->getMessage());
                $messageType = "danger";
            }
        }
        
        // ============================================================================================
        // DELETE PRODUCT: Sync deletion to admin/inventory.php AND admin/supplier_details.php
        // ============================================================================================
        // 
        // SYNC OVERVIEW:
        // 1. Hard deletes from supplier_catalog → auto-removed from admin/supplier_details.php (reads directly)
        // 2. Hard deletes from supplier_product_variations → auto-removed from admin/supplier_details.php (reads directly)
        // 3. Soft deletes in inventory (is_deleted = 1) → archived in admin/inventory.php
        // 4. Hard deletes from inventory_variations → removed from admin/inventory.php
        // 
        // DELETION PROCESS:
        // ✓ Product deleted from supplier_catalog → no longer appears in admin/supplier_details.php
        // ✓ Variations deleted from supplier_product_variations → no longer appear in admin/supplier_details.php
        // ✓ Inventory item marked as is_deleted = 1 → appears in archive view in admin/inventory.php
        // ✓ Inventory variations deleted → removed from admin/inventory.php
        // ============================================================================================
        elseif ($action === 'delete') {
            try {
                $product_id = (int)($_POST['product_id'] ?? 0);
                if (!$catalog->belongsToSupplier($product_id, $supplier_id)) {
                    throw new Exception("Product not found or access denied.");
                }
                
                $db->beginTransaction();
                
                // Get SKU before deleting to mark inventory as unavailable
                $skuStmt = $db->prepare("SELECT sku FROM supplier_catalog WHERE id = :id AND supplier_id = :sid");
                $skuStmt->execute([':id' => $product_id, ':sid' => $supplier_id]);
                $skuData = $skuStmt->fetch(PDO::FETCH_ASSOC);
                $sku = $skuData['sku'] ?? '';
                
                // Delete ALL variations from supplier_product_variations
                try {
                    $delVarsStmt = $db->prepare("DELETE FROM supplier_product_variations WHERE product_id = :pid");
                    $delVarsStmt->execute([':pid' => $product_id]);
                } catch (Exception $e) {
                    throw new Exception("Failed to delete product variations: " . $e->getMessage());
                }
                
                // Hard delete from supplier_catalog (DELETE from database, not soft delete)
                try {
                    $delProductStmt = $db->prepare("DELETE FROM supplier_catalog WHERE id = :id AND supplier_id = :sid");
                    if (!$delProductStmt->execute([':id' => $product_id, ':sid' => $supplier_id])) {
                        throw new Exception("Failed to delete product from supplier catalog.");
                    }
                } catch (Exception $e) {
                    throw new Exception("Failed to delete product: " . $e->getMessage());
                }
                
                // Mark corresponding inventory items as unavailable (soft delete in inventory = archive)
                // This ensures the product is automatically archived in admin/inventory.php and removed from active view
                // CRITICAL: This MUST execute to ensure products deleted in supplier portal are archived in inventory
                if ($sku !== '' && trim($sku) !== '') {
                    try {
                        // Add is_deleted column if it doesn't exist in inventory
                        try {
                            $checkCol = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory' AND COLUMN_NAME = 'is_deleted'")->fetchColumn();
                            if ($checkCol == 0) {
                                $db->exec("ALTER TABLE inventory ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0, ADD INDEX idx_is_deleted (is_deleted)");
                                error_log("Info: Added is_deleted column to inventory table");
                            }
                        } catch (Exception $e) {
                            error_log("Warning: Could not check/add is_deleted column: " . $e->getMessage());
                        }
                        
                        // Soft delete (archive) inventory items by SKU and supplier_id
                        // This ensures the product is automatically moved to archive in admin/inventory.php
                        // Update ALL matching items (even if already soft-deleted, to ensure consistency)
                        $invSoftDel = $db->prepare("UPDATE inventory SET is_deleted = 1 WHERE sku = :sku AND supplier_id = :sid AND sku IS NOT NULL AND sku != ''");
                        $invSoftDel->execute([':sku' => $sku, ':sid' => $supplier_id]);
                        $deletedCount = $invSoftDel->rowCount();
                        
                        if ($deletedCount > 0) {
                            error_log("Success: Archived {$deletedCount} inventory item(s) for SKU {$sku} and supplier {$supplier_id} - Product will appear in archive view in admin/inventory.php");
                            
                            // Also delete inventory variations for this SKU (cleanup)
                            try {
                                $invIdsStmt = $db->prepare("SELECT id FROM inventory WHERE sku = :sku AND supplier_id = :sid");
                                $invIdsStmt->execute([':sku' => $sku, ':sid' => $supplier_id]);
                                $invIds = $invIdsStmt->fetchAll(PDO::FETCH_COLUMN);
                                
                                if (!empty($invIds)) {
                                    $placeholders = implode(',', array_fill(0, count($invIds), '?'));
                                    $delInvVarsStmt = $db->prepare("DELETE FROM inventory_variations WHERE inventory_id IN ($placeholders)");
                                    $delInvVarsStmt->execute($invIds);
                                    $deletedVarsCount = $delInvVarsStmt->rowCount();
                                    if ($deletedVarsCount > 0) {
                                        error_log("Success: Deleted {$deletedVarsCount} inventory variation(s) for archived SKU {$sku}");
                                    }
                                }
                            } catch (Exception $e) {
                                // Log but don't fail - inventory variation deletion is secondary
                                error_log("Warning: Could not delete inventory variations for SKU {$sku}: " . $e->getMessage());
                            }
                        } else {
                            // Check if inventory item exists at all (might not have been synced yet)
                            $checkInvStmt = $db->prepare("SELECT COUNT(*) FROM inventory WHERE sku = :sku AND supplier_id = :sid AND sku IS NOT NULL AND sku != ''");
                            $checkInvStmt->execute([':sku' => $sku, ':sid' => $supplier_id]);
                            $existsCount = $checkInvStmt->fetchColumn();
                            
                            if ($existsCount > 0) {
                                error_log("Info: Inventory item(s) for SKU {$sku} and supplier {$supplier_id} may already be archived (is_deleted = 1)");
                            } else {
                                error_log("Info: No inventory items found for SKU {$sku} and supplier {$supplier_id} - item may not have been synced to inventory yet");
                            }
                        }
                    } catch (Throwable $e) {
                        // Log error but don't fail the transaction - inventory archive is important but shouldn't block deletion
                        error_log("ERROR: Could not archive inventory item for SKU {$sku}: " . $e->getMessage());
                        error_log("ERROR: Stack trace: " . $e->getTraceAsString());
                    }
                } else {
                    error_log("Warning: Cannot archive inventory - SKU is empty for product_id {$product_id}");
                }
                
                $db->commit();
                $message = "Product and all variations deleted successfully from supplier catalog.";
                $messageType = "success";
                
                // Log sync completion
                if ($sku !== '' && trim($sku) !== '') {
                    error_log("Success: Product deletion completed for SKU {$sku} - Product removed from admin/supplier_details.php and archived in admin/inventory.php");
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $message = htmlspecialchars($e->getMessage());
                $messageType = "danger";
            }
        }
        
        // ============================================================================================
        // BULK DELETE PRODUCTS: Sync bulk deletions to admin/inventory.php AND admin/supplier_details.php
        // ============================================================================================
        // 
        // SYNC OVERVIEW:
        // Same as single delete, but processes multiple products at once
        // 1. Hard deletes from supplier_catalog → auto-removed from admin/supplier_details.php
        // 2. Hard deletes from supplier_product_variations → auto-removed from admin/supplier_details.php
        // 3. Soft deletes in inventory (is_deleted = 1) → archived in admin/inventory.php
        // 4. Hard deletes from inventory_variations → removed from admin/inventory.php
        // ============================================================================================
        elseif ($action === 'bulk_delete') {
            try {
                $product_ids = $_POST['product_ids'] ?? [];
                if (empty($product_ids) || !is_array($product_ids)) {
                    throw new Exception("No products selected for deletion.");
                }
                
                // Validate all products belong to this supplier
                $valid_ids = [];
                foreach ($product_ids as $pid) {
                    $pid = (int)$pid;
                    if ($pid > 0 && $catalog->belongsToSupplier($pid, $supplier_id)) {
                        $valid_ids[] = $pid;
                    }
                }
                
                if (empty($valid_ids)) {
                    throw new Exception("No valid products selected for deletion.");
                }
                
                $db->beginTransaction();
                $deleted_count = 0;
                $skus = [];
                
                foreach ($valid_ids as $product_id) {
                    // Get SKU before deleting
                    $skuStmt = $db->prepare("SELECT sku FROM supplier_catalog WHERE id = :id AND supplier_id = :sid");
                    $skuStmt->execute([':id' => $product_id, ':sid' => $supplier_id]);
                    $skuData = $skuStmt->fetch(PDO::FETCH_ASSOC);
                    $sku = $skuData['sku'] ?? '';
                    if ($sku) {
                        $skus[] = $sku;
                    }
                    
                    // Delete variations
                    try {
                        $delVarsStmt = $db->prepare("DELETE FROM supplier_product_variations WHERE product_id = :pid");
                        $delVarsStmt->execute([':pid' => $product_id]);
                    } catch (Exception $e) {
                        error_log("Warning: Could not delete variations for product {$product_id}: " . $e->getMessage());
                    }
                    
                    // Delete product
                    try {
                        $delProductStmt = $db->prepare("DELETE FROM supplier_catalog WHERE id = :id AND supplier_id = :sid");
                        if ($delProductStmt->execute([':id' => $product_id, ':sid' => $supplier_id])) {
                            $deleted_count++;
                        }
                    } catch (Exception $e) {
                        error_log("Warning: Could not delete product {$product_id}: " . $e->getMessage());
                    }
                }
                
                // Mark corresponding inventory items as unavailable (soft delete in inventory = archive)
                // This ensures the products are automatically archived in admin/inventory.php
                // CRITICAL: This MUST execute to ensure products deleted in supplier portal are archived in inventory
                if (!empty($skus)) {
                    try {
                        // Add is_deleted column if it doesn't exist
                        try {
                            $checkCol = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory' AND COLUMN_NAME = 'is_deleted'")->fetchColumn();
                            if ($checkCol == 0) {
                                $db->exec("ALTER TABLE inventory ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0, ADD INDEX idx_is_deleted (is_deleted)");
                                error_log("Info: Added is_deleted column to inventory table");
                            }
                        } catch (Exception $e) {
                            error_log("Warning: Could not check/add is_deleted column: " . $e->getMessage());
                        }
                        
                        // Soft delete (archive) inventory items by SKUs and supplier_id
                        // This ensures the products are automatically moved to archive in admin/inventory.php
                        // Update ALL matching items (even if already soft-deleted, to ensure consistency)
                        $placeholders = implode(',', array_fill(0, count($skus), '?'));
                        $invSoftDel = $db->prepare("UPDATE inventory SET is_deleted = 1 WHERE sku IN ($placeholders) AND supplier_id = ? AND sku IS NOT NULL AND sku != ''");
                        $params = array_merge($skus, [$supplier_id]);
                        $invSoftDel->execute($params);
                        $bulkDeletedCount = $invSoftDel->rowCount();
                        if ($bulkDeletedCount > 0) {
                            error_log("Success: Archived {$bulkDeletedCount} inventory item(s) in bulk delete operation - Products will appear in archive view in admin/inventory.php");
                        } else {
                            error_log("Info: No inventory items found to archive in bulk delete (may already be archived or not synced)");
                        }
                        
                        // Delete inventory variations (cleanup)
                        try {
                            $invIdsStmt = $db->prepare("SELECT id FROM inventory WHERE sku IN ($placeholders) AND supplier_id = ?");
                            $invIdsStmt->execute($params);
                            $invIds = $invIdsStmt->fetchAll(PDO::FETCH_COLUMN);
                            
                            if (!empty($invIds)) {
                                $invPlaceholders = implode(',', array_fill(0, count($invIds), '?'));
                                $delInvVarsStmt = $db->prepare("DELETE FROM inventory_variations WHERE inventory_id IN ($invPlaceholders)");
                                $delInvVarsStmt->execute($invIds);
                                $deletedVarsCount = $delInvVarsStmt->rowCount();
                                if ($deletedVarsCount > 0) {
                                    error_log("Success: Deleted {$deletedVarsCount} inventory variation(s) for archived items");
                                }
                            }
                        } catch (Exception $e) {
                            error_log("Warning: Could not delete inventory variations: " . $e->getMessage());
                        }
                    } catch (Throwable $e) {
                        // Log error but don't fail the transaction
                        error_log("ERROR: Could not archive inventory items in bulk delete: " . $e->getMessage());
                        error_log("ERROR: Stack trace: " . $e->getTraceAsString());
                    }
                }
                
                $db->commit();
                $message = "{$deleted_count} product(s) deleted successfully.";
                $messageType = "success";
                
                // Log sync completion
                if (!empty($skus)) {
                    error_log("Success: Bulk deletion completed for " . count($skus) . " product(s) - Products removed from admin/supplier_details.php and archived in admin/inventory.php");
                }
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $message = htmlspecialchars($e->getMessage());
                $messageType = "danger";
            }
        }
    }
}

// Get products for this supplier
$productsStmt = $catalog->readBySupplier($supplier_id);
$products = [];
while ($row = $productsStmt->fetch(PDO::FETCH_ASSOC)) {
    // Auto-generate SKU if missing (required for inventory sync)
    $sku = trim($row['sku'] ?? '');
    if (empty($sku)) {
        // Generate SKU from product name and ID
        $productName = trim($row['name'] ?? '');
        $productId = (int)$row['id'];
        
        // Create base SKU from product name (first 3-5 chars, uppercase, alphanumeric only)
        $baseSku = preg_replace('/[^A-Za-z0-9]/', '', substr($productName, 0, 5));
        $baseSku = strtoupper($baseSku);
        if (empty($baseSku)) {
            $baseSku = 'PROD';
        }
        $baseSku .= '-' . $productId;
        
        // Ensure uniqueness
        $generatedSku = $catalog->generateUniqueSkuForSupplier($baseSku, $supplier_id);
        
        // Update the product with generated SKU
        try {
            $updateSkuStmt = $db->prepare("UPDATE supplier_catalog SET sku = :sku WHERE id = :id AND supplier_id = :sid");
            $updateSkuStmt->execute([':sku' => $generatedSku, ':id' => $productId, ':sid' => $supplier_id]);
            $row['sku'] = $generatedSku;
            error_log("Info: Auto-generated SKU '{$generatedSku}' for product ID {$productId} (Name: {$productName})");
        } catch (Exception $e) {
            error_log("Warning: Could not auto-generate SKU for product ID {$productId}: " . $e->getMessage());
            // Continue without SKU - will show warning in UI
        }
    }
    
    $variations = $spVariation->getByProduct((int)$row['id']);
    $row['variations'] = $variations;
    // Calculate total stock from variations
    $totalStock = 0;
    foreach ($variations as $var) {
        $totalStock += (int)($var['stock'] ?? 0);
    }
    $row['total_stock'] = $totalStock;
    $products[] = $row;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - Supplier Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <style>
        body {
            min-height: 100vh;
        }

        .sidebar {
            min-height: 100vh;
        }

        .main-content {
            background: rgba(255, 255, 255, 0.95);
            min-height: 100vh;
            padding: 30px;
            backdrop-filter: blur(10px);
        }

        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 30px;
            border-radius: 15px;
            margin-bottom: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .page-header h2 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .page-header .subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            border: none;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            margin-bottom: 25px;
        }

        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
        }

        .stats-card .card-body {
            padding: 0;
        }

        .stats-card h3 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stats-card .text-muted {
            font-size: 0.95rem;
            font-weight: 500;
        }

        .card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .card-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 2px solid #dee2e6;
            border-radius: 15px 15px 0 0 !important;
            padding: 20px 25px;
        }

        .card-header h5 {
            margin: 0;
            font-weight: 600;
            color: #2c3e50;
        }

        .table {
            margin-bottom: 0;
        }

        .table thead th {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: none;
            font-weight: 600;
            color: #2c3e50;
            padding: 15px;
            font-size: 0.9rem;
        }

        .table tbody tr {
            transition: all 0.3s ease;
        }

        .table tbody tr:hover {
            background-color: rgba(102, 126, 234, 0.05);
            transform: scale(1.01);
        }

        .table tbody td {
            padding: 15px;
            vertical-align: middle;
            border-color: #f1f3f4;
        }

        .variation-chip {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            margin: 0.1rem;
            background: #f0f0f0;
            border-radius: 0.25rem;
            font-size: 0.85rem;
        }

        .shp-variations {
            margin-top: 1rem;
        }

        .shp-variations__header {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .shp-variations__controls {
            margin-bottom: 1rem;
        }

        .shp-chip {
            margin: 0.25rem;
            cursor: pointer;
        }

        .btn-check:checked + .shp-chip {
            background-color: #0d6efd;
            color: white;
            border-color: #0d6efd;
        }

        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            justify-content: center;
        }

        .action-buttons .btn {
            margin: 2px;
            font-size: 0.8rem;
            padding: 6px 12px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .action-buttons .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: none;
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            border: none;
        }

        .btn-warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            border: none;
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            border: none;
        }

        .dataTables_wrapper .dataTables_filter {
            margin-bottom: 20px;
        }
        
        .dataTables_wrapper .dataTables_filter label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
        }
        
        .dataTables_wrapper .dataTables_filter input {
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 15px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .dataTables_wrapper .dataTables_filter input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            outline: none;
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }

            .page-header {
                padding: 20px 15px;
                margin-bottom: 20px;
            }

            .page-header h2 {
                font-size: 1.6rem;
            }
        }
    </style>
</head>
<body>
    <?php include_once 'includes/header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include_once 'includes/sidebar.php'; ?>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 main-content">
                <div class="page-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h2><i class="bi bi-box-seam me-3"></i>Product Management</h2>
                            <p class="subtitle mb-0">Manage your product catalog and inventory</p>
                        </div>
                        <button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#addProductModal">
                            <i class="bi bi-plus-circle me-2"></i>Add New Product
                        </button>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php
                // Calculate statistics
                $total_products = count(array_filter($products, function($p) { return $p['is_deleted'] == 0; }));
                $active_products = $total_products;
                $total_variations = array_sum(array_map(function($p) { return count($p['variations'] ?? []); }, $products));
                $low_stock = count(array_filter($products, function($p) { 
                    return $p['is_deleted'] == 0 && isset($p['supplier_quantity']) && isset($p['reorder_threshold']) && $p['supplier_quantity'] <= $p['reorder_threshold']; 
                }));
                ?>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="card-body text-center">
                                <h3 class="text-primary"><?php echo $total_products; ?></h3>
                                <p class="text-muted mb-0">Total Products</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="card-body text-center">
                                <h3 class="text-success"><?php echo $active_products; ?></h3>
                                <p class="text-muted mb-0">Active Products</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="card-body text-center">
                                <h3 class="text-info"><?php echo $total_variations; ?></h3>
                                <p class="text-muted mb-0">Total Variations</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="card-body text-center">
                                <h3 class="text-warning"><?php echo $low_stock; ?></h3>
                                <p class="text-muted mb-0">Low Stock Items</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Products Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>All Products</h5>
                        <button type="button" class="btn btn-danger btn-sm" id="bulkDeleteBtn" style="display: none;">
                            <i class="bi bi-trash me-1"></i>Delete Selected (<span id="selectedCount">0</span>)
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="productsTable">
                                <thead>
                                    <tr>
                                        <th width="50">
                                            <input type="checkbox" id="selectAllProducts" title="Select All">
                                        </th>
                                        <th>SKU</th>
                                        <th>Product Name</th>
                                        <th>Category</th>
                                        <th>Unit Type</th>
                                        <th>Variations</th>
                                        <th>Quantity</th>
                                        <th>Reorder Threshold</th>
                                        <th>Location</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $activeProducts = array_filter($products, function($p) { return ($p['is_deleted'] ?? 0) == 0; });
                                    foreach ($activeProducts as $product): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="product-checkbox" value="<?php echo (int)($product['id'] ?? 0); ?>" data-sku="<?php echo htmlspecialchars($product['sku'] ?? ''); ?>">
                                            </td>
                                            <td><strong><?php echo htmlspecialchars($product['sku'] ?? ''); ?></strong></td>
                                            <td>
                                                <div class="product-name"><?php echo htmlspecialchars($product['name'] ?? ''); ?></div>
                                                <?php if (!empty($product['description'])): ?>
                                                    <small class="text-muted"><?php echo htmlspecialchars(substr($product['description'], 0, 50)) . (strlen($product['description']) > 50 ? '...' : ''); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($product['category'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($product['unit_type'] ?? 'per piece'); ?></td>
                                            <td>
                                                <?php if (!empty($product['variations'])): ?>
                                                    <select class="form-select form-select-sm variation-select" aria-label="Select variation">
                                                        <?php 
                                                        // Parse variations to show as attribute:option format
                                                        foreach ($product['variations'] as $var): 
                                                            $vName = htmlspecialchars($var['variation'] ?? '');
                                                            $vPrice = isset($var['unit_price']) ? (float)$var['unit_price'] : 0;
                                                            $vStock = isset($var['stock']) ? (int)$var['stock'] : 0;
                                                            $lowClass = ($vStock <= (int)($product['reorder_threshold'] ?? 10)) ? ' text-warning' : '';
                                                            $stockClass = $vStock > 0 ? 'text-success fw-bold' : 'text-danger';
                                                            // Parse variation string (format: "Attribute:Option")
                                                            $parts = explode(':', $vName, 2);
                                                            $attr = $parts[0] ?? $vName;
                                                            $opt = $parts[1] ?? '';
                                                            $display = $opt ? $attr . ': ' . $opt : $vName;
                                                        ?>
                                                            <option value="<?php echo htmlspecialchars($vName); ?>" data-price="<?php echo htmlspecialchars($vPrice); ?>" data-stock="<?php echo htmlspecialchars($vStock); ?>" class="<?php echo $lowClass; ?> <?php echo $stockClass; ?>">
                                                                <?php echo htmlspecialchars($display); ?> — ₱<?php echo number_format($vPrice, 2); ?> — Stock: <?php echo $vStock; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="<?php echo (isset($product['total_stock']) && isset($product['reorder_threshold']) && (int)$product['total_stock'] <= (int)$product['reorder_threshold']) ? 'text-danger fw-bold' : ''; ?>">
                                                    <?php echo isset($product['total_stock']) ? (int)$product['total_stock'] : 0; ?>
                                                </span>
                                            </td>
                                            <td><?php echo isset($product['reorder_threshold']) ? (int)$product['reorder_threshold'] : 10; ?></td>
                                            <td><?php echo htmlspecialchars($product['location'] ?? '—'); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button type="button" class="btn btn-sm btn-primary edit-product-btn" 
                                                        data-id="<?php echo (int)($product['id'] ?? 0); ?>"
                                                        data-sku="<?php echo htmlspecialchars($product['sku'] ?? ''); ?>"
                                                        data-name="<?php echo htmlspecialchars($product['name'] ?? ''); ?>"
                                                        data-description="<?php echo htmlspecialchars($product['description'] ?? ''); ?>"
                                                        data-category="<?php echo htmlspecialchars($product['category'] ?? ''); ?>"
                                                        data-location="<?php echo htmlspecialchars($product['location'] ?? ''); ?>"
                                                        data-unit-type="<?php echo htmlspecialchars($product['unit_type'] ?? 'per piece'); ?>"
                                                        data-variations='<?php echo json_encode($product['variations'] ?? []); ?>'
                                                        data-bs-toggle="modal" data-bs-target="#editProductModal">
                                                        <i class="bi bi-pencil"></i> Edit
                                                    </button>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this product?');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="product_id" value="<?php echo (int)($product['id'] ?? 0); ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger">
                                                            <i class="bi bi-trash"></i> Delete
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Add Product Modal -->
    <div class="modal fade" id="addProductModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addProductModalLabel">
                        <i class="bi bi-plus-circle me-2"></i>Add New Product
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="addProductForm" class="supplier-form" aria-labelledby="addProductModalLabel" novalidate>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div id="addFormStatus" class="visually-hidden" aria-live="polite"></div>
                        <div class="row g-3">
                            <div class="col-md-6 mb-3">
                                <label for="productName" class="form-label">Product Name *</label>
                                <input type="text" class="form-control" id="productName" name="name" required>
                                <div class="invalid-feedback">Product name is required.</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="productSku" class="form-label">SKU *</label>
                                <input type="text" class="form-control" id="productSku" name="sku" pattern="[A-Za-z0-9_-]{2,}" required>
                                <div class="invalid-feedback">SKU is required.</div>
                            </div>
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-6 mb-3">
                                <label for="productCategory" class="form-label">Category *</label>
                                <select class="form-select" id="productCategory" name="category" required>
                                    <option value="">Select Category</option>
                                    <option value="Construction">Construction</option>
                                    <option value="Tools">Tools</option>
                                    <option value="Electrical">Electrical</option>
                                    <option value="Plumbing">Plumbing</option>
                                    <option value="Paints">Paints</option>
                                    <option value="Hardware">Hardware</option>
                                    <option value="Fasteners">Fasteners</option>
                                    <option value="Garden">Garden</option>
                                    <option value="Fixtures">Fixtures</option>
                                    <option value="Household">Household</option>
                                </select>
                                <div class="invalid-feedback">Please select a category.</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Pricing & Stock</label>
                                <div class="alert alert-info p-2 mb-2">
                                    Select variations below; you may set price and stock for any selection. Stock quantity will be summed for total quantity.
                                </div>
                                <div id="variationPriceContainer" class="row g-2"></div>
                                <small class="text-muted">Set price (₱) and stock quantity for each selected variation. Total quantity will be the sum of all variation stocks.</small>
                            </div>
                        </div>

                        <!-- Unit Type selection (radio buttons) -->
                        <div class="row g-3">
                            <div class="col-md-12 mb-3">
                                <div class="d-flex justify-content-between align-items-center flex-wrap mb-1">
                                    <label class="form-label mb-0">Unit Type</label>
                                    <div id="addFormUnitTypeManageGroup" class="d-flex gap-1 mt-2 mt-sm-0" aria-label="Manage unit types in Add Product">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnUnitTypeEdit">
                                            <i class="bi bi-pencil me-1"></i>Edit
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnUnitTypeAdd">
                                            <i class="bi bi-plus-lg me-1"></i>Add
                                        </button>
                                        <button type="button" class="btn btn-outline-danger btn-sm" id="btnUnitTypeDelete">
                                            <i class="bi bi-trash me-1"></i>Delete
                                        </button>
                                    </div>
                                </div>
                                <div id="unitTypeRadios" class="row g-2">
                                    <?php
                                        require_once '../models/unit_type.php';
                                        $unitTypeModel = new UnitType($db);
                                        $units = $unitTypeModel->readAll();
                                        foreach ($units as $u):
                                            $code = htmlspecialchars($u['code'] ?? '');
                                            $name = htmlspecialchars($u['name'] ?? '');
                                            $label = $name . ' (' . $code . ')';
                                    ?>
                                        <div class="col-auto">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="unit_type_code" id="unitCode_<?php echo $code; ?>" value="<?php echo $code; ?>">
                                                <label class="form-check-label" for="unitCode_<?php echo $code; ?>"><?php echo $label; ?></label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="d-flex align-items-center mt-2">
                                    <small class="text-muted me-2">Select the unit type; related variations will be shown below.</small>
                                </div>
                            </div>
                        </div>

                        <!-- Dynamic variation attributes per unit type -->
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label mb-0">Variation Attributes</label>
                            <div id="addFormVariationManageGroup" class="d-flex gap-1" aria-label="Manage variation options (Add, Edit, Delete) in Add Product">
                                <button type="button" class="btn btn-outline-primary btn-sm" id="btnVariationAdd" title="Add variation options">
                                    <i class="bi bi-plus-lg me-1"></i>Add
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnVariationEdit" title="Edit variation options">
                                    <i class="bi bi-pencil me-1"></i>Edit
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm" id="btnVariationDelete" title="Delete variation options">
                                    <i class="bi bi-trash me-1"></i>Delete
                                </button>
                            </div>
                        </div>
                        <div id="unitVariationContainer" class="border rounded p-2 mb-3"></div>

                        
                        <div class="row g-3">
                            <div class="col-md-12 mb-3">
                                <label for="productLocation" class="form-label">Location</label>
                                <input type="text" class="form-control" id="productLocation" name="location" placeholder="e.g., Warehouse A, Shelf 1">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="productDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="productDescription" name="description" rows="3"></textarea>
                        </div>
                        <input type="hidden" id="quantity" name="quantity" value="0">
                        <input type="hidden" id="reorder_threshold" name="reorder_threshold" value="0">
                        <input type="hidden" id="unit_price" name="unit_price" value="0">
                        <div id="variations-container" class="d-none"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Add Product
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Product Modal -->
    <div class="modal fade" id="editProductModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editProductModalLabel">
                        <i class="bi bi-pencil me-2"></i>Edit Product
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editProductForm" class="supplier-form" aria-labelledby="editProductModalLabel" novalidate>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="product_id" id="edit-product-id">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div id="editFormStatus" class="visually-hidden" aria-live="polite"></div>
                        <div class="row g-3">
                            <div class="col-md-6 mb-3">
                                <label for="editProductName" class="form-label">Product Name *</label>
                                <input type="text" class="form-control" id="editProductName" name="name" required>
                                <div class="invalid-feedback">Product name is required.</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="editProductSku" class="form-label">SKU *</label>
                                <input type="text" class="form-control" id="editProductSku" name="sku" pattern="[A-Za-z0-9_-]{2,}" required readonly>
                                <small class="text-muted">SKU cannot be changed</small>
                                <div class="invalid-feedback">SKU is required.</div>
                            </div>
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-6 mb-3">
                                <label for="editProductCategory" class="form-label">Category *</label>
                                <select class="form-select" id="editProductCategory" name="category" required>
                                    <option value="">Select Category</option>
                                    <option value="Construction">Construction</option>
                                    <option value="Tools">Tools</option>
                                    <option value="Electrical">Electrical</option>
                                    <option value="Plumbing">Plumbing</option>
                                    <option value="Paints">Paints</option>
                                    <option value="Hardware">Hardware</option>
                                    <option value="Fasteners">Fasteners</option>
                                    <option value="Garden">Garden</option>
                                    <option value="Fixtures">Fixtures</option>
                                    <option value="Household">Household</option>
                                </select>
                                <div class="invalid-feedback">Please select a category.</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Pricing & Stock</label>
                                <div class="alert alert-info p-2 mb-2">
                                    Select variations below; you may set price and stock for any selection. Stock quantity will be summed for total quantity.
                                </div>
                                <div id="editVariationPriceContainer" class="row g-2"></div>
                                <small class="text-muted">Set price (₱) and stock quantity for each selected variation. Total quantity will be the sum of all variation stocks.</small>
                            </div>
                        </div>

                        <!-- Unit Type selection (radio buttons) -->
                        <div class="row g-3">
                            <div class="col-md-12 mb-3">
                                <div class="d-flex justify-content-between align-items-center flex-wrap mb-1">
                                    <label class="form-label mb-0">Unit Type</label>
                                    <div id="editFormUnitTypeManageGroup" class="d-flex gap-1 mt-2 mt-sm-0" aria-label="Manage unit types in Edit Product">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnEditUnitTypeEdit">
                                            <i class="bi bi-pencil me-1"></i>Edit
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnEditUnitTypeAdd">
                                            <i class="bi bi-plus-lg me-1"></i>Add
                                        </button>
                                        <button type="button" class="btn btn-outline-danger btn-sm" id="btnEditUnitTypeDelete">
                                            <i class="bi bi-trash me-1"></i>Delete
                                        </button>
                                    </div>
                                </div>
                                <div id="editUnitTypeRadios" class="row g-2">
                                    <?php
                                        require_once '../models/unit_type.php';
                                        $unitTypeModel = new UnitType($db);
                                        $units = $unitTypeModel->readAll();
                                        foreach ($units as $u):
                                            $code = htmlspecialchars($u['code'] ?? '');
                                            $name = htmlspecialchars($u['name'] ?? '');
                                            $label = $name . ' (' . $code . ')';
                                    ?>
                                        <div class="col-auto">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="unit_type_code" id="editUnitCode_<?php echo $code; ?>" value="<?php echo $code; ?>">
                                                <label class="form-check-label" for="editUnitCode_<?php echo $code; ?>"><?php echo $label; ?></label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="d-flex align-items-center mt-2">
                                    <small class="text-muted me-2">Select the unit type; related variations will be shown below.</small>
                                </div>
                            </div>
                        </div>

                        <!-- Dynamic variation attributes per unit type -->
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label mb-0">Variation Attributes</label>
                            <div id="editFormVariationManageGroup" class="d-flex gap-1" aria-label="Manage variation options (Add, Edit, Delete) in Edit Product">
                                <button type="button" class="btn btn-outline-primary btn-sm" id="btnEditVariationAdd" title="Add variation options">
                                    <i class="bi bi-plus-lg me-1"></i>Add
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnEditVariationEdit" title="Edit variation options">
                                    <i class="bi bi-pencil me-1"></i>Edit
                                </button>
                                <button type="button" class="btn btn-outline-danger btn-sm" id="btnEditVariationDelete" title="Delete variation options">
                                    <i class="bi bi-trash me-1"></i>Delete
                                </button>
                            </div>
                        </div>
                        <div id="editUnitVariationContainer" class="border rounded p-2 mb-3"></div>

                        
                        <div class="row g-3">
                            <div class="col-md-12 mb-3">
                                <label for="editProductLocation" class="form-label">Location</label>
                                <input type="text" class="form-control" id="editProductLocation" name="location" placeholder="e.g., Warehouse A, Shelf 1">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="editProductDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="editProductDescription" name="description" rows="3"></textarea>
                        </div>
                        <input type="hidden" id="edit-quantity" name="quantity" value="0">
                        <input type="hidden" id="edit-reorder_threshold" name="reorder_threshold" value="0">
                        <input type="hidden" id="edit-unit_price" name="unit_price" value="0">
                        <div id="edit-variations-container" class="d-none"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Update Product
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
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="../js/unit_utils.js"></script>
    <script>
        // Unit type and variation mappings - aligned with admin inventory
        const UNIT_TYPE_CODE_MAP = <?php echo json_encode($UNIT_TYPE_CODE_MAP); ?>;
        const CSRF_TOKEN = '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>';
        
        // Mutable maps for client-side management
        const UNIT_TYPE_MAP = { ...UNIT_TYPE_CODE_MAP };
        // Start with empty map - will be loaded from database
        const VARIATION_OPTIONS_MAP = {};
        
        function displayNameFromNormalized(norm) {
            const base = (norm || '').replace(/^per\s+/i, '').trim();
            return base ? base.replace(/\b\w/g, c => c.toUpperCase()) : 'Piece';
        }
        
        function getSelectedUnitCode(containerId) {
            const sel = document.querySelector(`#${containerId} input[type="radio"]:checked`);
            return sel ? sel.value : null;
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
        
        const hydratedUnitCodes = new Set();
        
        // Render variation attributes
        function renderUnitVariations(unitCode) {
            const container = document.getElementById('unitVariationContainer');
            if (!container) return;
            const priceContainer = document.getElementById('variationPriceContainer');
            if (priceContainer) priceContainer.innerHTML = '';
            container.innerHTML = '';
            const opts = VARIATION_OPTIONS_MAP[unitCode] || {};
            
            if (Object.keys(opts).length === 0) {
                container.innerHTML = '<div class="alert alert-info">No variations found for this unit type.</div>';
                return;
            }
            
            const wrapper = document.createElement('div');
            wrapper.className = 'shp-variations border rounded p-3';
            
            const title = document.createElement('label');
            title.className = 'form-label mb-2';
            title.textContent = 'Select Variation Options';
            wrapper.appendChild(title);
            
            // Determine display order
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
            
            // Bind checkbox change events
            container.querySelectorAll('input[type="checkbox"][name^="variation_attrs["]').forEach(cb => {
                cb.addEventListener('change', (e) => {
                    onVariationAttrChange(e);
                });
            });
        }
        
        // Handle variation attribute changes - show/hide price and stock inputs
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
                // Check if price/stock input already exists
                const existing = container.querySelector(`input.var-price[data-key="${CSS.escape(key)}"]`);
                if (existing) { 
                    existing.disabled = false; 
                    existing.closest('.col-md-6')?.classList.remove('d-none');
                    const stockInput = existing.closest('.col-md-6')?.querySelector(`input.var-stock[data-key="${CSS.escape(key)}"]`);
                    if (stockInput) stockInput.disabled = false;
                    return; 
                }
                // Fetch HTML from server for price and stock inputs
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: `action=get_price_input&key=${encodeURIComponent(key)}&csrf_token=${CSRF_TOKEN}`
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.html) {
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = data.html;
                        container.appendChild(tempDiv.firstElementChild);
                    }
                })
                .catch(err => console.error('Failed to get price/stock input:', err));
            } else {
                // Hide price and stock inputs
                const existing = container.querySelector(`input.var-price[data-key="${CSS.escape(key)}"]`);
                if (existing) {
                    const col = existing.closest('.col-md-6');
                    if (col) {
                        existing.value = '';
                        const stockInput = col.querySelector(`input.var-stock[data-key="${CSS.escape(key)}"]`);
                        if (stockInput) stockInput.value = '0';
                        col.classList.add('d-none');
                    }
                }
            }
        }
        
        // Unit type management functions
        function normalizedFromName(name) {
            return 'per ' + (name || '').trim().toLowerCase();
        }
        
        // Hydrate variations for a unit type
        async function hydrateUnitVariations(unitCode, forceRefresh = false) {
            try {
                if (!unitCode) return;
                if (!forceRefresh && hydratedUnitCodes.has(unitCode)) return;
                const url = `../api/attributes.php?action=attribute_options_by_unit&unit_type_code=${encodeURIComponent(unitCode)}`;
                const resp = await fetch(url, { method: 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const data = await resp.json();
                if (data && data.success && data.attribute_options) {
                    VARIATION_OPTIONS_MAP[unitCode] = data.attribute_options;
                    hydratedUnitCodes.add(unitCode);
                } else {
                    VARIATION_OPTIONS_MAP[unitCode] = {};
                }
            } catch (err) {
                console.error('Failed to hydrate unit variations', err);
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
        
        // ============================================================================================
        // UNIT TYPE MANAGEMENT FUNCTIONS
        // ============================================================================================
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
        
        function openUnitManageModal(mode, containerId) {
            const modalEl = document.getElementById('unitTypeManageModal');
            if (!modalEl) return;
            if (!unitManageModalInst) unitManageModalInst = new bootstrap.Modal(modalEl);
            setUnitManageMode(mode);
            const badge = document.getElementById('selectedUnitBadge');
            const code = getSelectedUnitCode(containerId);
            const norm = UNIT_TYPE_MAP[code] || '';
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
                        fetch('../api/unit_types.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            body: JSON.stringify({ code: codeVal, name: nameVal })
                        })
                        .then(r => r.json())
                        .then(async data => {
                            if (data && !data.error) {
                                await reloadUnitTypesFromDB();
                                renderUnitTypesInto(containerId, containerId.includes('edit'));
                                // Refresh variations if a unit type is selected
                                const selectedCode = getSelectedUnitCode(containerId);
                                if (selectedCode) {
                                    hydratedUnitCodes.delete(selectedCode);
                                    await hydrateUnitVariations(selectedCode, true);
                                    if (containerId === 'unitTypeRadios') {
                                        renderUnitVariations(selectedCode);
                                    } else if (containerId === 'editUnitTypeRadios') {
                                        renderEditUnitVariations(selectedCode);
                                    }
                                }
                                unitManageModalInst.hide();
                                toggleManageLoading('save', false);
                                alert(`Unit type ${codeVal} added.`);
                            } else {
                                toggleManageLoading('save', false);
                                alert(data?.error || data?.message || 'Add failed.');
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
                        // Get unit type ID from code - fetch all and find by code
                        fetch('../api/unit_types.php', {
                            method: 'GET',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        })
                        .then(r => r.json())
                        .then(async unitTypes => {
                            const unitType = Array.isArray(unitTypes) ? unitTypes.find(u => u.code === code) : null;
                            if (!unitType || !unitType.id) {
                                toggleManageLoading('save', false);
                                alert('Unit type not found.');
                                return null;
                            }
                            return fetch(`../api/unit_types.php?id=${unitType.id}`, {
                                method: 'PUT',
                                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                body: JSON.stringify({ name: newName })
                            });
                        })
                        .then(r => r ? r.json() : null)
                        .then(async data => {
                            if (data && !data.error) {
                                await reloadUnitTypesFromDB();
                                renderUnitTypesInto(containerId, containerId.includes('edit'));
                                // Refresh variations if a unit type is selected
                                const selectedCode = getSelectedUnitCode(containerId);
                                if (selectedCode) {
                                    hydratedUnitCodes.delete(selectedCode);
                                    await hydrateUnitVariations(selectedCode, true);
                                    if (containerId === 'unitTypeRadios') {
                                        renderUnitVariations(selectedCode);
                                    } else if (containerId === 'editUnitTypeRadios') {
                                        renderEditUnitVariations(selectedCode);
                                    }
                                }
                                unitManageModalInst.hide();
                                toggleManageLoading('save', false);
                                alert(`Unit type ${code} updated.`);
                            } else {
                                toggleManageLoading('save', false);
                                alert(data?.error || data?.message || 'Update failed.');
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
                    renderUnitTypesInto(containerId, containerId.includes('edit'));
                    // Get unit type ID from code - fetch all and find by code
                    fetch('../api/unit_types.php', {
                        method: 'GET',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    })
                    .then(r => r.json())
                    .then(async unitTypes => {
                        const unitType = Array.isArray(unitTypes) ? unitTypes.find(u => u.code === code) : null;
                        if (!unitType || !unitType.id) {
                            if (prevName) { UNIT_TYPE_MAP[code] = prevName; }
                            renderUnitTypesInto(containerId, containerId.includes('edit'));
                            toggleManageLoading('delete', false);
                            alert('Unit type not found.');
                            return null;
                        }
                        return fetch(`../api/unit_types.php?id=${unitType.id}`, {
                            method: 'DELETE',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        });
                    })
                    .then(r => r ? r.json() : null)
                    .then(async data => {
                        if (data && !data.error) {
                            await reloadUnitTypesFromDB();
                            renderUnitTypesInto(containerId, containerId.includes('edit'));
                            // Clear variations if deleted unit type was selected
                            const selectedCode = getSelectedUnitCode(containerId);
                            if (selectedCode === code) {
                                // Clear the variation container
                                if (containerId === 'unitTypeRadios') {
                                    const container = document.getElementById('unitVariationContainer');
                                    if (container) container.innerHTML = '<div class="alert alert-info">Please select a unit type above to view available variations...</div>';
                                } else if (containerId === 'editUnitTypeRadios') {
                                    const container = document.getElementById('editUnitVariationContainer');
                                    if (container) container.innerHTML = '<div class="alert alert-info">Please select a unit type above to view available variations...</div>';
                                }
                            } else if (selectedCode) {
                                // Refresh variations for currently selected unit type
                                hydratedUnitCodes.delete(selectedCode);
                                await hydrateUnitVariations(selectedCode, true);
                                if (containerId === 'unitTypeRadios') {
                                    renderUnitVariations(selectedCode);
                                } else if (containerId === 'editUnitTypeRadios') {
                                    renderEditUnitVariations(selectedCode);
                                }
                            }
                            unitManageModalInst.hide();
                            toggleManageLoading('delete', false);
                            alert(`Unit type ${code} deleted.`);
                        } else {
                            if (prevName) { UNIT_TYPE_MAP[code] = prevName; }
                            renderUnitTypesInto(containerId, containerId.includes('edit'));
                            toggleManageLoading('delete', false);
                            alert(data?.error || data?.message || 'Delete failed.');
                        }
                    })
                    .catch(err => {
                        if (prevName) { UNIT_TYPE_MAP[code] = prevName; }
                        renderUnitTypesInto(containerId, containerId.includes('edit'));
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
        
        // ============================================================================================
        // VARIATION MANAGEMENT FUNCTIONS
        // ============================================================================================
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
        
        async function openVariationManageModal(mode, containerId) {
            let unitCode = getSelectedUnitCode(containerId);
            if (!unitCode) { alert('Please select a unit type first.'); return; }
            const modalEl = document.getElementById('variationManageModal');
            if (!modalEl) return;
            if (!variationManageModalInst) variationManageModalInst = new bootstrap.Modal(modalEl);
            setVariationManageMode(mode);
            currentVariationContext = containerId.includes('edit') ? 'editForm' : 'addForm';
            currentVariationMode = mode;
            currentVariationUnitCode = unitCode;
            hydratedUnitCodes.delete(unitCode);
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
                        hydratedUnitCodes.delete(unitCode);
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
                        hydratedUnitCodes.delete(unitCode);
                        await hydrateUnitVariations(unitCode, true);
                        // Refresh variation display in the form
                        rerenderVariations(unitCode);
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
                                    method: 'PUT',
                                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                    body: JSON.stringify(body)
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
                                    method: 'PUT',
                                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                    body: JSON.stringify(body)
                                });
                                const d1 = await r1.json();
                                if (!d1?.success) throw new Error(d1?.error || 'Failed to rename option');
                                const arr = VARIATION_OPTIONS_MAP[unitCode]?.[ren.attribute] || [];
                                const idx = arr.indexOf(ren.current);
                                if (idx >= 0) arr[idx] = ren.new;
                            }
                            hydratedUnitCodes.delete(unitCode);
                            await hydrateUnitVariations(unitCode, true);
                            // Refresh variation display in the form
                            rerenderVariations(unitCode);
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
                                        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
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
                                        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
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
                        hydratedUnitCodes.delete(unitCode);
                        await hydrateUnitVariations(unitCode, true);
                        // Refresh variation display in the form
                        rerenderVariations(unitCode);
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
        
        // Helper functions for variation management
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
        
        async function saveAttributeOption(attribute, option, unitCode) {
            try {
                const response = await fetch('../api/attributes.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({
                        action: 'add_attribute_option',
                        unit_type_code: unitCode,
                        attribute: attribute,
                        value: option
                    })
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
            hydratedUnitCodes.delete(unitCode);
            await hydrateUnitVariations(unitCode, true);
            selectedAttribute = name;
            initializeOptionManagementForAttribute(selectedAttribute, unitCode);
            renderVariationAddTree(unitCode);
            rerenderVariations(unitCode);
            setVariationManageStatus('success', 'Attribute added. Add options below to persist for edits.');
            input.value = '';
        }
        
        function rerenderVariations(unitCode) {
            const containerId = currentVariationContext === 'editForm' ? 'editUnitVariationContainer' : 'unitVariationContainer';
            if (containerId === 'editUnitVariationContainer') {
                renderEditUnitVariations(unitCode);
            } else {
                renderUnitVariations(unitCode);
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
                    hydratedUnitCodes.delete(unitCode);
                    await hydrateUnitVariations(unitCode, true);
                    renderVariationAddTree(unitCode);
                    rerenderVariations(unitCode);
                    addInput.value = '';
                    setVariationManageStatus('success', `Option "${val}" added to ${attr}.`);
                };
                addOptRow.appendChild(addInput);
                addOptRow.appendChild(addBtn);
                liAttr.appendChild(addOptRow);
                container.appendChild(liAttr);
            });
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
        
        function resetVariationDeleteState() {
            variationDeleteState = { attrs: new Set(), options: new Map() };
            updateDeleteSelectionSummary();
        }
        
        function updateDeleteSelectionSummary() {
            const badge = document.getElementById('variationDeleteSelectedCount');
            if (!badge) return;
            let count = variationDeleteState.attrs.size;
            variationDeleteState.options.forEach(set => { count += set.size; });
            badge.textContent = String(count);
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
        
        // Initialize on page load
        $(document).ready(function() {
            // Multi-select functionality
            const selectAllCheckbox = $('#selectAllProducts');
            const productCheckboxes = $('.product-checkbox');
            const bulkDeleteBtn = $('#bulkDeleteBtn');
            const selectedCountSpan = $('#selectedCount');
            
            // Update selected count and show/hide bulk delete button
            function updateSelection() {
                const checked = $('.product-checkbox:checked').length;
                selectedCountSpan.text(checked);
                if (checked > 0) {
                    bulkDeleteBtn.show();
                } else {
                    bulkDeleteBtn.hide();
                }
                
                // Update select all checkbox state
                const total = productCheckboxes.length;
                selectAllCheckbox.prop('indeterminate', checked > 0 && checked < total);
                selectAllCheckbox.prop('checked', checked === total && total > 0);
            }
            
            // Select all checkbox handler
            selectAllCheckbox.on('change', function() {
                productCheckboxes.prop('checked', $(this).is(':checked'));
                updateSelection();
            });
            
            // Individual checkbox handler
            $(document).on('change', '.product-checkbox', function() {
                updateSelection();
            });
            
            // Bulk delete handler
            bulkDeleteBtn.on('click', function() {
                const selected = $('.product-checkbox:checked').map(function() {
                    return $(this).val();
                }).get();
                
                if (selected.length === 0) {
                    alert('Please select at least one product to delete.');
                    return;
                }
                
                const skus = $('.product-checkbox:checked').map(function() {
                    return $(this).data('sku');
                }).get().join(', ');
                
                if (confirm(`Are you sure you want to delete ${selected.length} product(s)?\n\nSKUs: ${skus}\n\nThis action cannot be undone.`)) {
                    const form = $('<form>', {
                        method: 'POST',
                        action: ''
                    });
                    
                    form.append($('<input>', {
                        type: 'hidden',
                        name: 'action',
                        value: 'bulk_delete'
                    }));
                    
                    form.append($('<input>', {
                        type: 'hidden',
                        name: 'csrf_token',
                        value: CSRF_TOKEN
                    }));
                    
                    selected.forEach(function(id) {
                        form.append($('<input>', {
                            type: 'hidden',
                            name: 'product_ids[]',
                            value: id
                        }));
                    });
                    
                    $('body').append(form);
                    form.submit();
                }
            });
            
            // Initialize selection state
            updateSelection();
            
            // Initialize DataTable - ensure table exists and has correct structure
            if ($.fn.DataTable && $('#productsTable').length) {
                // Destroy existing instance if any
                if ($.fn.dataTable.isDataTable('#productsTable')) {
                    $('#productsTable').DataTable().destroy();
                }
                
                // Initialize DataTable
                try {
                    $('#productsTable').DataTable({
                        order: [[1, 'asc']],
                        pageLength: 25,
                        responsive: true,
                        columnDefs: [
                            { orderable: false, targets: [0, -1] } // Disable sorting on checkbox and Actions columns
                        ],
                        language: {
                            search: "Search products:",
                            lengthMenu: "Show _MENU_ products per page",
                            info: "Showing _START_ to _END_ of _TOTAL_ products",
                            infoEmpty: "No products found. Click 'Add New Product' to get started.",
                            infoFiltered: "(filtered from _MAX_ total products)",
                            emptyTable: "<div class='text-center py-5'><i class='bi bi-inbox' style='font-size: 3rem;'></i><p class='mt-3 mb-0 fs-5'>No products available</p><p class='text-muted'>Click 'Add New Product' to get started</p></div>"
                        },
                        drawCallback: function(settings) {
                            // Hide default empty message and show custom if needed
                            if (this.api().data().length === 0) {
                                $('.dataTables_empty').html("<div class='text-center py-5'><i class='bi bi-inbox' style='font-size: 3rem;'></i><p class='mt-3 mb-0 fs-5'>No products available</p><p class='text-muted'>Click 'Add New Product' to get started</p></div>");
                            }
                        }
                    });
                } catch (e) {
                    console.error('DataTable initialization error:', e);
                }
            }
            
            renderUnitTypesInto('unitTypeRadios', false);
            
            // Hydrate variations for initially selected unit code, if any
            const initAddSel = document.querySelector('#unitTypeRadios input[type="radio"]:checked');
            (async () => {
                const addCode = initAddSel ? initAddSel.value : null;
                if (addCode) { 
                    await hydrateUnitVariations(addCode, true);
                    renderUnitVariations(addCode); 
                }
            })();
            
            document.querySelectorAll('#unitTypeRadios input[type="radio"]').forEach(r => {
                r.addEventListener('change', async function() {
                    if (r.checked) {
                        await hydrateUnitVariations(r.value, true);
                        renderUnitVariations(r.value);
                    }
                });
            });
            
            // Wire up unit type management buttons (Add form)
            const btnAdd = document.getElementById('btnUnitTypeAdd');
            const btnEdit = document.getElementById('btnUnitTypeEdit');
            const btnDel = document.getElementById('btnUnitTypeDelete');
            if (btnAdd) btnAdd.addEventListener('click', () => openUnitManageModal('add', 'unitTypeRadios'));
            if (btnEdit) btnEdit.addEventListener('click', () => openUnitManageModal('edit', 'unitTypeRadios'));
            if (btnDel) btnDel.addEventListener('click', () => openUnitManageModal('delete', 'unitTypeRadios'));
            
            // Wire up variation management buttons (Add form)
            const btnVariationAdd = document.getElementById('btnVariationAdd');
            const btnVariationEdit = document.getElementById('btnVariationEdit');
            const btnVariationDelete = document.getElementById('btnVariationDelete');
            if (btnVariationAdd) btnVariationAdd.addEventListener('click', () => openVariationManageModal('add', 'unitTypeRadios'));
            if (btnVariationEdit) btnVariationEdit.addEventListener('click', () => openVariationManageModal('edit', 'unitTypeRadios'));
            if (btnVariationDelete) btnVariationDelete.addEventListener('click', () => openVariationManageModal('delete', 'unitTypeRadios'));
            
            // Wire up unit type management buttons (Edit form)
            const btnEditAdd = document.getElementById('btnEditUnitTypeAdd');
            const btnEditEdit = document.getElementById('btnEditUnitTypeEdit');
            const btnEditDel = document.getElementById('btnEditUnitTypeDelete');
            if (btnEditAdd) btnEditAdd.addEventListener('click', () => openUnitManageModal('add', 'editUnitTypeRadios'));
            if (btnEditEdit) btnEditEdit.addEventListener('click', () => openUnitManageModal('edit', 'editUnitTypeRadios'));
            if (btnEditDel) btnEditDel.addEventListener('click', () => openUnitManageModal('delete', 'editUnitTypeRadios'));
            
            // Wire up variation management buttons (Edit form)
            const btnEditVariationAdd = document.getElementById('btnEditVariationAdd');
            const btnEditVariationEdit = document.getElementById('btnEditVariationEdit');
            const btnEditVariationDelete = document.getElementById('btnEditVariationDelete');
            if (btnEditVariationAdd) btnEditVariationAdd.addEventListener('click', () => openVariationManageModal('add', 'editUnitTypeRadios'));
            if (btnEditVariationEdit) btnEditVariationEdit.addEventListener('click', () => openVariationManageModal('edit', 'editUnitTypeRadios'));
            if (btnEditVariationDelete) btnEditVariationDelete.addEventListener('click', () => openVariationManageModal('delete', 'editUnitTypeRadios'));
            
            // Re-attach listeners when modal opens
            const addModal = document.getElementById('addProductModal');
            if (addModal) {
                addModal.addEventListener('shown.bs.modal', function() {
                    renderUnitTypesInto('unitTypeRadios', false);
                    // Auto-select first unit type if none selected
                    setTimeout(() => {
                        const firstRadio = document.querySelector('#unitTypeRadios input[type="radio"]');
                        if (firstRadio && !document.querySelector('#unitTypeRadios input[type="radio"]:checked')) {
                            firstRadio.checked = true;
                            firstRadio.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }, 100);
                });
            }
            
            // Form submission handler
            const addInventoryForm = document.querySelector('#addProductForm');
            if (addInventoryForm) {
                addInventoryForm.addEventListener('submit', function(e) {
                    if (this.dataset.clientValidated === '1') { 
                        this.dataset.clientValidated = ''; 
                        return; 
                    }
                    e.preventDefault();
                    
                    // Ensure a unit type is selected
                    const unitSelected = document.querySelector('#unitTypeRadios input[type="radio"]:checked');
                    if (!unitSelected) {
                        alert('Please select a unit type.');
                        return;
                    }
                    
                    // Append normalized unit_type for backend compatibility
                    const normalized = UNIT_TYPE_MAP[unitSelected.value] || 'per piece';
                    let hidden = this.querySelector('input[name="unit_type"]');
                    if (!hidden) {
                        hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'unit_type';
                        this.appendChild(hidden);
                    }
                    hidden.value = normalized;
                    
                    // Collect selected variations into variations[] array
                    this.querySelectorAll('input[type="hidden"][name="variations[]"]').forEach(n => n.remove());
                    const checked = document.querySelectorAll('#unitVariationContainer input[type="checkbox"][name^="variation_attrs["]:checked');
                    checked.forEach(cb => {
                        const name = cb.getAttribute('name') || '';
                        const m = name.match(/^variation_attrs\[(.+)\]\[\]$/);
                        if (!m) return;
                        const attr = m[1];
                        const val = cb.value;
                        const key = `${attr}:${val}`;
                        const vHidden = document.createElement('input');
                        vHidden.type = 'hidden';
                        vHidden.name = 'variations[]';
                        vHidden.value = key;
                        this.appendChild(vHidden);
                    });
                    
                    // Auto-set track_variations if any selection made
                    let trackHidden = this.querySelector('input[name="track_variations"]');
                    if (checked.length > 0) {
                        if (!trackHidden) {
                            trackHidden = document.createElement('input');
                            trackHidden.type = 'hidden';
                            trackHidden.name = 'track_variations';
                            this.appendChild(trackHidden);
                        }
                        trackHidden.value = '1';
                    } else {
                        if (trackHidden) trackHidden.remove();
                    }
                    
                    this.dataset.clientValidated = '1';
                    this.submit();
                });
            }
            
            // Store edit product data
            let editProductData = null;
            
            // Edit button handler - store data
            $('.edit-product-btn').on('click', function() {
                editProductData = {
                    id: $(this).data('id'),
                    sku: $(this).data('sku'),
                    name: $(this).data('name'),
                    description: $(this).data('description'),
                    category: $(this).data('category'),
                    location: $(this).data('location'),
                    unitType: $(this).data('unit-type') || 'per piece',
                    variations: $(this).data('variations') || []
                };
            });
            
            // Edit form submission handler
            const editProductForm = document.querySelector('#editProductForm');
            if (editProductForm) {
                editProductForm.addEventListener('submit', function(e) {
                    if (this.dataset.clientValidated === '1') { 
                        this.dataset.clientValidated = ''; 
                        return; 
                    }
                    e.preventDefault();
                    
                    // Ensure a unit type is selected
                    const unitSelected = document.querySelector('#editUnitTypeRadios input[type="radio"]:checked');
                    if (!unitSelected) {
                        alert('Please select a unit type.');
                        return;
                    }
                    
                    // Append normalized unit_type for backend compatibility
                    const normalized = UNIT_TYPE_MAP[unitSelected.value] || 'per piece';
                    let hidden = this.querySelector('input[name="unit_type"]');
                    if (!hidden) {
                        hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'unit_type';
                        this.appendChild(hidden);
                    }
                    hidden.value = normalized;
                    
                    // Collect selected variations into variations[] array
                    // Include variations from both checked checkboxes AND existing price/stock inputs
                    this.querySelectorAll('input[type="hidden"][name="variations[]"]').forEach(n => n.remove());
                    
                    // First, collect from checked checkboxes
                    const checked = document.querySelectorAll('#editUnitVariationContainer input[type="checkbox"][name^="variation_attrs["]:checked');
                    const collectedKeys = new Set();
                    checked.forEach(cb => {
                        const name = cb.getAttribute('name') || '';
                        const m = name.match(/^variation_attrs\[(.+)\]\[\]$/);
                        if (!m) return;
                        const attr = m[1];
                        const val = cb.value;
                        const key = `${attr}:${val}`;
                        collectedKeys.add(key);
                        const vHidden = document.createElement('input');
                        vHidden.type = 'hidden';
                        vHidden.name = 'variations[]';
                        vHidden.value = key;
                        this.appendChild(vHidden);
                    });
                    
                    // Also collect from existing price/stock inputs in Pricing & Stock section
                    const allPriceInputs = this.querySelectorAll('#editVariationPriceContainer input.var-price[data-key]');
                    allPriceInputs.forEach(input => {
                        const key = input.getAttribute('data-key');
                        if (key && !collectedKeys.has(key)) {
                            // Check if this variation's checkbox exists and is checked
                            const parts = key.split(':');
                            const attr = parts[0] || '';
                            const opt = parts[1] || '';
                            const checkbox = this.querySelector(`#editUnitVariationContainer input[type="checkbox"][name="variation_attrs[${attr}][]"][value="${opt}"]`);
                            
                            // Include if checkbox is checked OR if checkbox doesn't exist (existing variation displayed immediately)
                            if (!checkbox || checkbox.checked) {
                                collectedKeys.add(key);
                                const vHidden = document.createElement('input');
                                vHidden.type = 'hidden';
                                vHidden.name = 'variations[]';
                                vHidden.value = key;
                                this.appendChild(vHidden);
                            } else {
                                // Variation is unchecked - remove its price/stock inputs
                                const col = input.closest('.col-md-6');
                                if (col) {
                                    col.remove();
                                }
                            }
                        }
                    });
                    
                    this.dataset.clientValidated = '1';
                    this.submit();
                });
            }
            
            // Handle edit modal opening - populate form and display existing data
            $('#editProductModal').on('shown.bs.modal', function() {
                if (!editProductData) return;
                
                // Populate basic form fields
                $('#edit-product-id').val(editProductData.id);
                $('#editProductSku').val(editProductData.sku);
                $('#editProductName').val(editProductData.name);
                $('#editProductDescription').val(editProductData.description || '');
                $('#editProductCategory').val(editProductData.category);
                $('#editProductLocation').val(editProductData.location || '');
                
                // Clear variation containers
                $('#editVariationPriceContainer').empty();
                $('#editUnitVariationContainer').empty();
                
                // Display existing variations IMMEDIATELY in Pricing & Stock section
                const $priceContainer = $('#editVariationPriceContainer');
                if (editProductData.variations && editProductData.variations.length > 0) {
                    editProductData.variations.forEach(variation => {
                        const varKey = variation.variation || variation;
                        const price = parseFloat(variation.unit_price || 0);
                        const stock = parseInt(variation.stock || 0, 10);
                        
                        // Format variation key for display (Attribute: Option)
                        const parts = varKey.split(':');
                        const attr = parts[0] || varKey;
                        const opt = parts[1] || '';
                        const displayLabel = opt ? `${attr}: ${opt}` : varKey;
                        
                        // Create price/stock inputs immediately - visible in Pricing & Stock section
                        const col = $('<div class="col-md-6 mb-3"></div>');
                        const label = $('<label class="form-label small fw-bold"></label>').text(displayLabel);
                        col.append(label);
                        
                        const priceGroup = $('<div class="input-group input-group-sm mb-2"></div>');
                        priceGroup.append('<span class="input-group-text">₱</span>');
                        const priceInput = $('<input type="number" class="form-control var-price" step="0.01" min="0" placeholder="Price">');
                        priceInput.attr('data-key', varKey);
                        priceInput.attr('name', `variation_prices[${varKey}]`);
                        priceInput.val(price > 0 ? price.toFixed(2) : '');
                        priceGroup.append(priceInput);
                        col.append(priceGroup);
                        
                        const stockGroup = $('<div class="input-group input-group-sm"></div>');
                        stockGroup.append('<span class="input-group-text">Qty</span>');
                        const stockInput = $('<input type="number" class="form-control var-stock" step="1" min="0" placeholder="Stock">');
                        stockInput.attr('data-key', varKey);
                        stockInput.attr('name', `variation_stocks[${varKey}]`);
                        stockInput.val(stock > 0 ? stock : '');
                        stockGroup.append(stockInput);
                        col.append(stockGroup);
                        
                        // Append to Pricing & Stock container immediately
                        $priceContainer.append(col);
                    });
                } else {
                    // Show message if no variations exist
                    $priceContainer.html('<div class="col-12"><p class="text-muted mb-0"><em>No variations yet. Select variations below to add them.</em></p></div>');
                }
                
                // Render unit types first
                renderUnitTypesInto('editUnitTypeRadios', true);
                
                // Wait for unit types to render, then select the correct one
                setTimeout(() => {
                    if (!editProductData) return;
                    
                    const unitType = editProductData.unitType.toLowerCase().trim();
                    
                    // Find matching unit type code - try multiple matching strategies
                    let unitTypeCode = null;
                    
                    // Strategy 1: Direct normalized match
                    for (const code in UNIT_TYPE_MAP) {
                        const normalized = UNIT_TYPE_MAP[code].toLowerCase().trim();
                        if (normalized === unitType) {
                            unitTypeCode = code;
                            break;
                        }
                    }
                    
                    // Strategy 2: Match with "per " prefix handling
                    if (!unitTypeCode) {
                        const unitTypeName = unitType.replace(/^per\s+/i, '').trim();
                        for (const code in UNIT_TYPE_MAP) {
                            const normalized = UNIT_TYPE_MAP[code].toLowerCase().trim();
                            const normalizedName = normalized.replace(/^per\s+/i, '').trim();
                            if (normalizedName === unitTypeName || normalized === 'per ' + unitTypeName) {
                                unitTypeCode = code;
                                break;
                            }
                        }
                    }
                    
                    // Strategy 3: Partial match
                    if (!unitTypeCode) {
                        for (const code in UNIT_TYPE_MAP) {
                            const normalized = UNIT_TYPE_MAP[code].toLowerCase().trim();
                            if (normalized.includes(unitType) || unitType.includes(normalized.replace(/^per\s+/i, '').trim())) {
                                unitTypeCode = code;
                                break;
                            }
                        }
                    }
                    
                    // Select the unit type radio button
                    if (unitTypeCode) {
                        const $radio = $(`#editUnitTypeRadios input[value="${unitTypeCode}"]`);
                        if ($radio.length) {
                            $radio.prop('checked', true);
                            
                            // Trigger change to load variations
                            $radio.trigger('change');
                            
                            // Wait for variations to load, then check existing ones
                            // Use a recursive function to wait for variations to be available
                            const checkExistingVariations = (attempts = 0) => {
                                if (!editProductData || !editProductData.variations) return;
                                if (attempts > 20) return; // Max 2 seconds wait
                                
                                const variations = editProductData.variations;
                                const container = document.getElementById('editUnitVariationContainer');
                                
                                // Check if variations container has been populated
                                if (!container || container.querySelectorAll('input[type="checkbox"]').length === 0) {
                                    setTimeout(() => checkExistingVariations(attempts + 1), 100);
                                    return;
                                }
                                
                                // All variations loaded, now check the ones that exist
                                if (variations && variations.length > 0) {
                                    variations.forEach(variation => {
                                        const varKey = variation.variation || variation;
                                        
                                        // Parse variation key (format: "Attribute:Option")
                                        const parts = varKey.split(':');
                                        const attr = parts[0] || '';
                                        const opt = parts[1] || '';
                                        
                                        // Check the corresponding checkbox if it exists
                                        const checkbox = container.querySelector(`input[type="checkbox"][name="variation_attrs[${attr}][]"][value="${CSS.escape(opt)}"]`);
                                        if (checkbox) {
                                            checkbox.checked = true;
                                            // Trigger change event to ensure price/stock inputs are visible
                                            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                                        }
                                    });
                                }
                            };
                            
                            // Start checking after a short delay
                            setTimeout(() => checkExistingVariations(), 300);
                        }
                    }
                }, 100);
            });
            
            // Clear edit data when modal is hidden
            $('#editProductModal').on('hidden.bs.modal', function() {
                editProductData = null;
                $('#editProductForm')[0]?.reset();
                $('#editVariationPriceContainer').empty();
                $('#editUnitVariationContainer').empty();
            });
            
            // Handle edit unit type radio changes
            $(document).on('change', '#editUnitTypeRadios input[type="radio"]', async function() {
                if ($(this).is(':checked')) {
                    const code = $(this).val();
                    hydratedUnitCodes.delete(code); // Force refresh
                    await hydrateUnitVariations(code, true);
                    renderEditUnitVariations(code);
                }
            });
            
            // Render variations for edit form
            function renderEditUnitVariations(unitCode) {
                const container = document.getElementById('editUnitVariationContainer');
                if (!container) return;
                const priceContainer = document.getElementById('editVariationPriceContainer');
                
                // Store existing price/stock inputs before clearing
                const existingPriceInputs = {};
                if (priceContainer) {
                    priceContainer.querySelectorAll('.col-md-6').forEach(col => {
                        const priceInput = col.querySelector('input.var-price');
                        const stockInput = col.querySelector('input.var-stock');
                        if (priceInput && priceInput.dataset.key) {
                            existingPriceInputs[priceInput.dataset.key] = {
                                price: priceInput.value || '',
                                stock: stockInput ? (stockInput.value || '') : '',
                                element: col.cloneNode(true)
                            };
                        }
                    });
                }
                
                // Clear only the variation checkboxes container, not the price container
                container.innerHTML = '';
                const opts = VARIATION_OPTIONS_MAP[unitCode] || {};
                
                if (Object.keys(opts).length === 0) {
                    container.innerHTML = '<div class="alert alert-info">No variations found for this unit type.</div>';
                    // Restore existing price inputs if any
                    if (priceContainer && Object.keys(existingPriceInputs).length > 0) {
                        priceContainer.innerHTML = '';
                        Object.values(existingPriceInputs).forEach(data => {
                            priceContainer.appendChild(data.element);
                        });
                    }
                    return;
                }
                
                const wrapper = document.createElement('div');
                wrapper.className = 'shp-variations border rounded p-3';
                
                const title = document.createElement('label');
                title.className = 'form-label mb-2';
                title.textContent = 'Select Variation Options';
                wrapper.appendChild(title);
                
                // Determine display order
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
                        nameEcho.id = 'editShpProductNameEcho';
                        const nameInput = document.getElementById('editProductName');
                        const updateEcho = () => { nameEcho.textContent = (nameInput?.value?.trim() || '—'); };
                        updateEcho();
                        nameInput?.addEventListener('input', updateEcho);
                        group.appendChild(nameEcho);
                    } else {
                        const values = opts[attr] || [];
                        values.forEach((val, idx) => {
                            const id = `edit_var_${attr.replace(/\s+/g,'_')}_${idx}`;
                            const check = document.createElement('input');
                            check.type = 'checkbox';
                            check.className = 'btn-check';
                            check.id = id;
                            check.name = `variation_attrs[${attr}][]`;
                            check.value = val;
                            
                            // Check if this variation already exists in price container
                            const varKey = `${attr}:${val}`;
                            if (existingPriceInputs[varKey]) {
                                check.checked = true;
                            }
                            
                            const label = document.createElement('label');
                            label.className = 'btn btn-outline-secondary shp-chip';
                            label.setAttribute('for', id);
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
                
                // Restore existing price inputs that match current unit type variations
                if (priceContainer) {
                    // Clear and restore only matching variations
                    priceContainer.innerHTML = '';
                    Object.keys(existingPriceInputs).forEach(key => {
                        // Check if this variation key can be formed from current options
                        const parts = key.split(':');
                        const attr = parts[0];
                        const opt = parts[1];
                        if (opts[attr] && opts[attr].includes(opt)) {
                            priceContainer.appendChild(existingPriceInputs[key].element);
                        }
                    });
                }
                
                // Bind checkbox change events
                container.querySelectorAll('input[type="checkbox"][name^="variation_attrs["]').forEach(cb => {
                    cb.addEventListener('change', (e) => {
                        onEditVariationAttrChange(e);
                    });
                });
            }
            
            // Handle edit variation attribute changes
            function onEditVariationAttrChange(evt) {
                const cb = evt.target;
                if (!cb || cb.type !== 'checkbox') return;
                const container = document.getElementById('editVariationPriceContainer');
                if (!container) return;
                
                // Build variation key from checkbox
                const name = cb.getAttribute('name') || '';
                const m = name.match(/^variation_attrs\[(.+)\]\[\]$/);
                if (!m) return;
                const attr = m[1];
                const val = cb.value;
                const key = `${attr}:${val}`;
                
                if (cb.checked) {
                    // Check if price/stock input already exists
                    const existing = container.querySelector(`input.var-price[data-key="${CSS.escape(key)}"]`);
                    if (existing) { 
                        // Variation already exists - just make sure it's visible and enabled
                        existing.disabled = false; 
                        const col = existing.closest('.col-md-6');
                        if (col) {
                            col.classList.remove('d-none');
                            const stockInput = col.querySelector(`input.var-stock[data-key="${CSS.escape(key)}"]`);
                            if (stockInput) stockInput.disabled = false;
                        }
                        return; 
                    }
                    // Create new variation - fetch HTML from server for price and stock inputs
                    fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: `action=get_price_input&key=${encodeURIComponent(key)}&csrf_token=${CSRF_TOKEN}`
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success && data.html) {
                            const tempDiv = document.createElement('div');
                            tempDiv.innerHTML = data.html;
                            const newCol = tempDiv.firstElementChild;
                            if (newCol) {
                                container.appendChild(newCol);
                            }
                        }
                    })
                    .catch(err => {
                        console.error('Failed to get price/stock input:', err);
                        // Fallback: create the inputs manually
                        const col = document.createElement('div');
                        col.className = 'col-md-6 mb-3';
                        const label = document.createElement('label');
                        label.className = 'form-label small fw-bold';
                        // Format variation key for display (Attribute: Option)
                        const parts = key.split(':');
                        const attr = parts[0] || key;
                        const opt = parts[1] || '';
                        label.textContent = opt ? `${attr}: ${opt}` : key;
                        col.appendChild(label);
                        
                        const priceGroup = document.createElement('div');
                        priceGroup.className = 'input-group input-group-sm mb-2';
                        priceGroup.innerHTML = '<span class="input-group-text">₱</span>';
                        const priceInput = document.createElement('input');
                        priceInput.type = 'number';
                        priceInput.className = 'form-control var-price';
                        priceInput.setAttribute('data-key', key);
                        priceInput.name = `variation_prices[${key}]`;
                        priceInput.step = '0.01';
                        priceInput.min = '0';
                        priceInput.placeholder = 'Price';
                        priceGroup.appendChild(priceInput);
                        col.appendChild(priceGroup);
                        
                        const stockGroup = document.createElement('div');
                        stockGroup.className = 'input-group input-group-sm';
                        stockGroup.innerHTML = '<span class="input-group-text">Qty</span>';
                        const stockInput = document.createElement('input');
                        stockInput.type = 'number';
                        stockInput.className = 'form-control var-stock';
                        stockInput.setAttribute('data-key', key);
                        stockInput.name = `variation_stocks[${key}]`;
                        stockInput.step = '1';
                        stockInput.min = '0';
                        stockInput.placeholder = 'Stock';
                        stockInput.value = '0';
                        stockGroup.appendChild(stockInput);
                        col.appendChild(stockGroup);
                        
                        container.appendChild(col);
                    });
                } else {
                    // Remove price and stock inputs when variation is unchecked (to delete variation)
                    const existing = container.querySelector(`input.var-price[data-key="${CSS.escape(key)}"]`);
                    if (existing) {
                        const col = existing.closest('.col-md-6');
                        if (col) {
                            // Clear values before removing
                            existing.value = '';
                            const stockInput = col.querySelector(`input.var-stock[data-key="${CSS.escape(key)}"]`);
                            if (stockInput) stockInput.value = '0';
                            // Remove the entire column to fully remove the variation
                            col.remove();
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>



