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

// ---- Admin auth guard ----
requireManagementPage();

// ---- Instantiate dependencies ----
$db         = (new Database())->getConnection();
$inventory  = new Inventory($db);
$supplier   = new Supplier($db);
$order      = new Order($db);
$sales      = new SalesTransaction($db);
$alert      = new AlertLog($db);
$invVariation = new InventoryVariation($db);

// Interest markup for selling inventory products (10% markup)
$interestMarkup = 0.10;

if (empty($_SESSION['csrf_token'])) {
    try { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } catch (Throwable $e) { $_SESSION['csrf_token'] = sha1(uniqid('csrf', true)); }
}

// Format variation for display (same as orders.php)
function formatVariationForDisplay($variation) { return InventoryVariation::formatVariationForDisplay($variation, ' - '); }

// Truncate product name for receipt display
function truncateProductName($name, $maxLength = 25) {
    if (strlen($name) <= $maxLength) return $name;
    return substr($name, 0, $maxLength - 3) . '...';
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

// Handle AJAX sale processing
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'process_sale'
) {
    header('Content-Type: application/json');
    require_once '../config/session.php';
    ensureCsrf();
    $items        = json_decode($_POST['cart_items'], true);
    if (!is_array($items)) { echo json_encode(['status'=>'error','message'=>'Invalid cart data']); exit; }
    $total_amount = 0;
    $db->beginTransaction();
    try {
        foreach ($items as $item) {
            $inventory_id = (int)($item['id'] ?? 0);
            $quantity     = (int)($item['quantity'] ?? 0);
            $unit_type    = isset($item['unit_type']) ? (string)$item['unit_type'] : 'per piece';
            $variation    = isset($item['variation']) ? (string)$item['variation'] : '';
            if ($inventory_id <= 0 || $quantity <= 0) { throw new Exception('Invalid item payload'); }

            // Load inventory
            $inventory->id = $inventory_id;
            if (!$inventory->readOne()) {
                throw new Exception("Item not found.");
            }

            // Compute price from completed orders
            $price = isset($item['price']) ? (float)$item['price'] : 0.0;
            if ($price <= 0) { throw new Exception('Invalid price'); }
            $item_total   = $quantity * $price;
            $total_amount += $item_total;

            // Record sale
            $sales->inventory_id = $inventory_id;
            $sales->user_id      = $_SESSION['admin']['user_id'] ?? 0;
            $sales->quantity     = $quantity;
            $sales->total_amount = $item_total;
            $sales->unit_type    = $unit_type;
            $sales->variation    = $variation;
            if (!$sales->create()) {
                throw new Exception("Failed to record sale.");
            }
        }
        $db->commit();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'total_amount' => $total_amount]);
        exit;
    } catch (Exception $e) {
        $db->rollBack();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// Get products from admin_orders database (connected directly to admin_orders table)
// This matches exactly what orders.php displays - ALL products that have ANY orders
$inventoryItems = [];
$categoriesSet  = [];

try {
    // Get products ONLY from COMPLETED orders in admin_orders table
    // Stock calculation: SUM(admin_orders.quantity WHERE confirmation_status='completed') - sales_transactions.quantity
    // When orders are completed in orders.php, stock automatically increases
    // When items are sold in POS, stock decreases (sales_transactions)
    // NO inventory_variations fallback - stock ONLY from completed orders
    $posQuery = "SELECT DISTINCT
                        o.inventory_id as id,
                        i.sku,
                        COALESCE(i.name, CONCAT('Product #', o.inventory_id)) as name,
                        i.name as item_name, -- Match orders.php item_name field exactly
                        i.description,
                        0 as quantity, -- Stock calculated from admin_orders.quantity - sales
                        i.reorder_threshold,
                        COALESCE(i.category, 'Uncategorized') as category,
                        0 as unit_price, -- Price comes from admin_orders
                        i.location,
                        o.supplier_id as supplier_id, -- Supplier from admin_orders (matching orders.php)
                        i.image_url,
                        i.image_path,
                        '' as unit_type, -- Unit type comes from admin_orders
                        COALESCE(s.name, 'N/A') as supplier_name,
                        COALESCE(i.is_deleted, 0) as is_deleted,
                        'from_order' as source_type
                 FROM admin_orders o
                 INNER JOIN inventory i ON o.inventory_id = i.id
                 LEFT JOIN suppliers s ON o.supplier_id = s.id
                 WHERE o.confirmation_status = 'completed'
                   AND o.inventory_id IS NOT NULL
                 ORDER BY COALESCE(i.name, CONCAT('Product #', o.inventory_id)) ASC";
    
    // Execute query without caching to ensure we get the absolute latest data including new orders
    $stmt = $db->prepare($posQuery);
    $stmt->setFetchMode(PDO::FETCH_ASSOC);
    $stmt->execute();
    
    if ($stmt === false) {
        throw new PDOException("Failed to execute POS query");
    }
    
    $foundItems = 0;
    $processedIds = []; // Track processed inventory IDs to avoid duplicates by ID
    $processedItemNames = []; // Track processed item names to prevent duplicates by name (monitor Item Name)
    
    while ($row = $stmt->fetch()) {
        // Show ALL orders even if inventory is deleted or missing - data comes from admin_orders anyway
        if (empty($row['id']) || $row['id'] === null) {
            error_log("POS: Skipping - inventory_id is null");
            continue;
        }
        
        // Skip if we already processed this inventory_id (from DISTINCT, but double-check)
        $invId = (int)$row['id'];
        if (isset($processedIds[$invId])) {
            continue;
        }
        $processedIds[$invId] = true;
        
        // Use item_name (i.name from inventory) - this matches exactly what orders.php uses
        // If inventory is deleted/missing, use fallback name (COALESCE should handle this, but ensure it's set)
        if (empty($row['name']) || trim($row['name']) === '') {
            $row['name'] = !empty($row['item_name']) ? trim($row['item_name']) : "Product #" . $row['id'];
        }
        // Ensure item_name matches name for consistency with orders.php
        if (empty($row['item_name']) && !empty($row['name'])) {
            $row['item_name'] = $row['name'];
        }
        
        // MONITOR BY ITEM NAME: Prevent duplicates based on item_name (not just inventory_id)
        // If an item with the same name already exists, merge variations instead of creating duplicate
        $itemNameKey = strtolower(trim($row['item_name'] ?? $row['name'] ?? ''));
        
        // If item name is empty, use inventory_id as fallback
        if (empty($itemNameKey)) {
            $itemNameKey = 'product_' . $invId;
        }
        
        // Check if we already have an item with this name
        if (isset($processedItemNames[$itemNameKey])) {
            // Item with same name already exists - skip adding as separate item
            // Variations will be merged in the next loop based on inventory_id
            error_log("POS: Skipping duplicate item name '{$itemNameKey}' for inventory_id {$invId} - variations will be merged with existing item");
            continue;
        }
        
        // Mark this item name as processed
        $processedItemNames[$itemNameKey] = $invId;
        
        // Ensure category exists
        if (empty($row['category']) || trim($row['category']) === '') {
            $row['category'] = 'Uncategorized';
        }
        
        // All data will be populated from orders in next loop
        // For now, just store basic structure with display info from inventory (or defaults)
        unset($row['is_deleted']); // Remove debug field
        $inventoryItems[] = $row;
        $cat = trim(strtolower($row['category'] ?? 'uncategorized'));
        if ($cat === '') $cat = 'uncategorized';
        $categoriesSet[$cat] = true;
        $foundItems++;
    }
    
    error_log("POS: Found {$foundItems} products from completed admin_orders (including deleted inventory)");
    
    // Verify we're getting completed orders - show detailed breakdown
    $orderCheckStmt = $db->query("SELECT COUNT(*) as order_count FROM admin_orders WHERE inventory_id IS NOT NULL AND confirmation_status = 'completed'");
    $orderCheck = $orderCheckStmt->fetch(PDO::FETCH_ASSOC);
    $totalOrders = (int)($orderCheck['order_count'] ?? 0);
    
    // Get count of completed orders with deleted inventory
    $deletedInvStmt = $db->query("SELECT COUNT(DISTINCT o.inventory_id) as deleted_count 
                                   FROM admin_orders o 
                                   LEFT JOIN inventory i ON o.inventory_id = i.id 
                                   WHERE o.inventory_id IS NOT NULL 
                                     AND o.confirmation_status = 'completed'
                                     AND (i.id IS NULL OR i.is_deleted = 1)");
    $deletedInv = $deletedInvStmt->fetch(PDO::FETCH_ASSOC);
    $deletedCount = (int)($deletedInv['deleted_count'] ?? 0);
    
    error_log("POS: Total orders in database: {$totalOrders}, Products found: " . count($inventoryItems) . ", Orders with deleted inventory: {$deletedCount}");
    
} catch (PDOException $e) {
    error_log("POS product query error: " . $e->getMessage());
    error_log("POS query SQL: " . ($posQuery ?? 'N/A'));
    $inventoryItems = [];
}

// Load data ONLY from COMPLETED orders in admin_orders table
// Stock calculation: SUM(admin_orders.quantity WHERE confirmation_status='completed') - sales_transactions.quantity
// When new orders are completed in orders.php, stock automatically increases
// When items are sold in POS, stock decreases (recorded in sales_transactions)
// Only use inventory for display info (name, category, image)
// All operational data (price, stock, variations, unit_type) comes from COMPLETED admin_orders
// IMPORTANT: Monitor by Item Name - if multiple inventory_ids have the same item_name, 
// variations from ALL matching completed inventory_ids are merged into one item
foreach ($inventoryItems as &$item) {
    $item_id = (int)($item['id'] ?? 0);
    $item_name = trim(strtolower($item['item_name'] ?? $item['name'] ?? ''));
    $source_type = $item['source_type'] ?? 'from_order'; // 'from_order' or 'admin_created'
    
    if ($item_id > 0) {
        // Initialize variation maps (from COMPLETED admin_orders ONLY)
        $item['variation_available_stocks'] = [];
        $item['variation_prices'] = [];
        $item['variation_units'] = [];
        
        // Get ALL order data for this product from COMPLETED orders - matching orders.php exactly
        try {
            // Get all inventory_ids with same item_name (for merging)
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
            
            // ALL stock comes ONLY from completed orders in admin_orders table
            // No inventory_variations fallback - stock MUST be from orders.php
            
            // Get base order price for non-variation items from COMPLETED admin_orders
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
            
            // Set base price from admin_orders (most recent order price)
            if ($basePriceRow && isset($basePriceRow['unit_price']) && $basePriceRow['unit_price'] > 0) {
                $item['unit_price'] = (float)$basePriceRow['unit_price'];
                // Set unit_type from admin_orders
                if (!empty($basePriceRow['unit_type'])) {
                    $item['unit_type'] = trim($basePriceRow['unit_type']);
                }
            }
            
            // Get variations ONLY from COMPLETED admin_orders
            // Stock = SUM(admin_orders.quantity WHERE confirmation_status='completed') - sales
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
            $allVariationsStmt->setFetchMode(PDO::FETCH_ASSOC);
            $allVariationsStmt->execute($sameNameIds);
            $allVariations = $allVariationsStmt->fetchAll();
            
            foreach ($allVariations as $orderVar) {
                $varKey = trim($orderVar['variation'] ?? '');
                if (empty($varKey) || $varKey === 'null') continue;
                
                // Calculate stock: SUM(admin_orders.quantity WHERE confirmation_status='completed') - sales
                // This is the ONLY source of stock - based on orders.php completed orders
                $orderedQty = (int)($orderVar['total_ordered_qty'] ?? 0);
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
                
                // Available stock = completed orders quantity - sales
                // When new orders are completed in orders.php, orderedQty increases automatically
                // When items are sold in POS, soldQty increases, so stock decreases
                $availableStock = max(0, $orderedQty - $soldQty);
                
                // Store the exact variation key from admin_orders table
                // Stock is calculated as completed orders minus sales
                if (isset($item['variation_available_stocks'][$varKey])) {
                    $item['variation_available_stocks'][$varKey] += $availableStock;
                } else {
                    $item['variation_available_stocks'][$varKey] = $availableStock;
                }
                
                // Get latest unit_type and price for this variation from COMPLETED admin_orders
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
                
                // Unit type from admin_orders table (matching orders.php) - use latest
                if ($latestVarRow && isset($latestVarRow['unit_type']) && !empty(trim($latestVarRow['unit_type'] ?? ''))) {
                    $item['variation_units'][$varKey] = trim($latestVarRow['unit_type']);
                } else if (isset($orderVar['unit_type']) && !empty(trim($orderVar['unit_type'] ?? ''))) {
                    $item['variation_units'][$varKey] = trim($orderVar['unit_type']);
                } else {
                    $item['variation_units'][$varKey] = $item['variation_units'][$varKey] ?? ($item['unit_type'] ?? 'per piece');
                }
                
                // Price from admin_orders table (actual ordered price - matching orders.php)
                // Use latest price if available, otherwise fall back to grouped price
                if ($latestVarRow && isset($latestVarRow['unit_price']) && $latestVarRow['unit_price'] > 0) {
                    $item['variation_prices'][$varKey] = (float)$latestVarRow['unit_price'];
                } else if (isset($orderVar['unit_price']) && $orderVar['unit_price'] > 0) {
                    $item['variation_prices'][$varKey] = (float)$orderVar['unit_price'];
                } else {
                    // If no price in orders, use base order price
                    $item['variation_prices'][$varKey] = $item['variation_prices'][$varKey] ?? (float)($item['unit_price'] ?? 0);
                }
            }
            
            if (count($allVariations) > 0) {
                error_log("POS: Loaded " . count($allVariations) . " variations for inventory ID {$item_id} from completed admin_orders");
            }
        } catch (PDOException $e) {
            error_log("POS: Could not load data from admin_orders for inventory ID {$item_id}: " . $e->getMessage());
        }
        
        // Base quantity ONLY from COMPLETED orders in admin_orders
        // Stock = SUM(admin_orders.quantity WHERE confirmation_status='completed') - sales
        // When new orders are completed in orders.php, stock increases automatically
        // When items are sold in POS, stock decreases
        try {
            // Reuse sameNameIds from above if available
            if (empty($sameNameIds)) {
                if (!empty($item_name)) {
                    $nameCheckStmt = $db->prepare("SELECT DISTINCT id 
                                                   FROM inventory 
                                                   WHERE LOWER(TRIM(COALESCE(name, ''))) = :item_name
                                                     AND COALESCE(is_deleted, 0) = 0");
                    $nameCheckStmt->execute([':item_name' => $item_name]);
                    $sameNameIds = [];
                    while ($nameRow = $nameCheckStmt->fetch(PDO::FETCH_ASSOC)) {
                        $sameNameIds[] = (int)$nameRow['id'];
                    }
                }
                if (empty($sameNameIds)) {
                    $sameNameIds = [$item_id];
                }
            }
            
            // Get quantity ONLY from completed orders in admin_orders
            $basePlaceholders = implode(',', array_fill(0, count($sameNameIds), '?'));
            $baseQtyStmt = $db->prepare("SELECT SUM(quantity) as total_qty FROM admin_orders 
                                          WHERE inventory_id IN ($basePlaceholders)
                                            AND confirmation_status = 'completed'
                                            AND (variation IS NULL OR variation = '' OR variation = 'null' OR LOWER(TRIM(variation)) = 'null')");
            $baseQtyStmt->execute($sameNameIds);
            $baseQtyRow = $baseQtyStmt->fetch(PDO::FETCH_ASSOC);
            $orderedQty = (int)($baseQtyRow['total_qty'] ?? 0);
            
            // Get total sold quantity from ALL inventory_ids with same item_name
            $soldBasePlaceholders = implode(',', array_fill(0, count($sameNameIds), '?'));
            $soldQtyStmt = $db->prepare("SELECT SUM(quantity) as total_sold FROM sales_transactions 
                                          WHERE inventory_id IN ($soldBasePlaceholders)
                                            AND (variation IS NULL OR variation = '' OR variation = 'null' OR LOWER(TRIM(variation)) = 'null')");
            $soldQtyStmt->execute($sameNameIds);
            $soldQtyRow = $soldQtyStmt->fetch(PDO::FETCH_ASSOC);
            $soldQty = (int)($soldQtyRow['total_sold'] ?? 0);
            
            // Available stock = completed orders quantity - sales
            // When new orders are completed in orders.php, orderedQty increases
            // When items are sold in POS, soldQty increases, so stock decreases
            $item['available_quantity'] = max(0, $orderedQty - $soldQty);
            error_log("POS: Stock for inventory ID {$item_id} - From orders: {$orderedQty}, Sold: {$soldQty}, Available: {$item['available_quantity']}");
        } catch (PDOException $e) {
            $item['available_quantity'] = 0;
        }
    }
}
unset($item);

// Build categories list
$categories = [];
foreach ($categoriesSet as $cat => $_) {
    $label = ucfirst($cat);
    foreach ($inventoryItems as $it) {
        if (isset($it['category']) && strtolower($it['category']) === $cat) {
            $label = $it['category'];
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
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>POS System - Inventory & Stock Control System</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets/css/style.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Libre+Barcode+128&display=swap" rel="stylesheet">
  <style>
    /* POS-specific styles */
    .product-card { 
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
    .add-to-cart-btn {
      font-weight: 500;
      transition: all 0.2s ease;
    }
    .add-to-cart-btn:hover {
      transform: scale(1.02);
      box-shadow: 0 0.25rem 0.5rem rgba(0, 0, 0, 0.1);
    }
    .product-img { 
      width: 100%; 
      height: 140px; 
      object-fit: cover; 
      border-top-left-radius: 0.35rem; 
      border-top-right-radius: 0.35rem;
    }
    .cart-item { 
      border-bottom: 1px solid #e3e6f0; 
      padding: 0.75rem 0; 
    }
    .cart-summary { 
      position: sticky; 
      bottom: 0; 
      background: #f8f9fc; 
      padding: 1rem; 
      border-top: 1px solid #e3e6f0;
      border-radius: 0 0 0.35rem 0.35rem;
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
    .search-container {
      background: white;
      border-radius: 0.35rem;
      padding: 0.5rem;
      box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    }
    .cart-container {
      height: calc(100vh - 200px);
      display: flex;
      flex-direction: column;
    }
    .cart-items {
      flex: 1;
      overflow-y: auto;
      padding: 1rem;
    }
    .cart-empty {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      height: 200px;
      color: #6c757d;
    }
    .btn-checkout {
      background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%);
      border: none;
      color: white;
      font-weight: 500;
    }
    .btn-checkout:hover {
      background: linear-gradient(135deg, #13855c 0%, #0f6848 100%);
      color: white;
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
    
    /* Receipt Print Styles for Thermal Printers */
    @media print {
      /* Hide everything except receipt content */
      body * {
        visibility: hidden;
      }
      
      #receiptContent, #receiptContent * {
        visibility: visible;
      }
      
      #receiptContent {
        position: absolute;
        left: 0;
        top: 0;
        width: 80mm;
        margin: 0;
        padding: 2mm;
        font-family: 'Courier New', 'Lucida Console', monospace;
        font-size: 10pt;
        line-height: 1.2;
      }
      
      /* Receipt container styling */
      .receipt-container {
        width: 76mm;
        margin: 0 auto;
        background: white;
      }
      
      /* Store header styling */
      .receipt-header {
        text-align: center;
        margin-bottom: 3mm;
        border-bottom: 1px dashed #000;
        padding-bottom: 2mm;
      }
      
      .receipt-header h4 {
        font-size: 12pt;
        font-weight: bold;
        margin: 1mm 0;
      }
      
      .receipt-header p {
        font-size: 9pt;
        margin: 0.5mm 0;
      }
      
      /* Transaction details */
      .receipt-details {
        margin-bottom: 3mm;
      }
      
      .receipt-details p {
        margin: 0.5mm 0;
        display: flex;
        justify-content: space-between;
      }
      
      .receipt-details strong {
        font-weight: normal;
      }
      
      /* Items table styling */
      .receipt-items {
        margin-bottom: 3mm;
        border-bottom: 1px dashed #000;
        padding-bottom: 2mm;
      }
      
      .receipt-items table {
        width: 100%;
        border-collapse: collapse;
        font-family: 'Courier New', 'Lucida Console', monospace;
      }
      
      .receipt-items th,
      .receipt-items td {
        padding: 0.5mm 0;
        vertical-align: top;
      }
      
      .receipt-items th {
        font-weight: bold;
        border-bottom: 1px solid #000;
      }
      
      /* Column widths for proper alignment */
      .col-item {
        width: 35mm;
        text-align: left;
      }
      
      .col-qty {
        width: 8mm;
        text-align: center;
      }
      
      .col-price {
        width: 15mm;
        text-align: right;
      }
      
      .col-total {
        width: 18mm;
        text-align: right;
      }
      
      /* Item name and variation styling */
      .item-name {
        font-weight: normal;
        margin: 0;
        word-wrap: break-word;
        max-width: 35mm;
      }
      
      .item-variation {
        font-size: 8pt;
        color: #666;
        margin: 0;
        font-style: italic;
      }
      
      /* Totals section */
      .receipt-totals {
        margin-bottom: 3mm;
      }
      
      .receipt-totals div {
        display: flex;
        justify-content: space-between;
        margin: 0.5mm 0;
      }
      
      .receipt-totals strong {
        font-weight: bold;
      }
      
      /* Footer styling */
      .receipt-footer {
        text-align: center;
        margin-top: 5mm;
        padding-top: 2mm;
        border-top: 1px dashed #000;
      }
      
      .receipt-footer p {
        margin: 1mm 0;
        font-size: 9pt;
      }
      
      /* Barcode/QR code placeholder */
      .receipt-barcode {
        text-align: center;
        margin: 3mm 0;
        font-family: 'Libre Barcode 128', monospace;
        font-size: 24pt;
      }
      
      /* Ensure proper page breaks */
      @page {
        size: 80mm auto;
        margin: 0;
      }
      
      /* Prevent cutting off content */
      .receipt-container {
        page-break-inside: avoid;
      }
    }
    
    /* Screen preview styling */
    .receipt-preview {
      background: white;
      border: 1px solid #ccc;
      padding: 10px;
      margin: 10px auto;
      max-width: 300px;
      font-family: 'Courier New', 'Lucida Console', monospace;
      font-size: 10pt;
    }
    .quantity-controls input {
      width: 4.5rem;
      min-width: 4.5rem;
      height: 2rem;
      text-align: center;
      border: 1px solid #e3e6f0;
      border-radius: 0.25rem;
      font-size: 0.875rem;
      padding: 0.25rem;
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
          <h1 class="h2">Point of Sale</h1>
          <div class="btn-toolbar mb-2 mb-md-0">
            <button class="btn btn-sm btn-outline-secondary" id="printReceiptBtn">
              <i class="bi bi-printer"></i> Print Receipt
            </button>
          </div>
        </div>

        <div class="row">
          <!-- Products List -->
          <div class="col-md-8 mb-4">
            <div class="card dashboard-card">
              <div class="card-header bg-light">
                <div class="d-flex flex-wrap align-items-center justify-content-between">
                  <h5 class="mb-2 mb-md-0 text-gray-800">
                    <i class="bi bi-grid-3x3-gap me-2"></i>Products from Orders
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
                <div class="product-grid" id="productContainer">
                  <?php if (empty($inventoryItems)): ?>
                    <div class="col-12 text-center py-5">
                      <i class="bi bi-inbox" style="font-size: 4rem; color: #d3d3d3;"></i>
                      <p class="text-muted mt-3">No products found</p>
                      <p class="text-muted small">Products will appear here when their orders are marked as 'completed' in the Orders page.</p>
                      <?php 
                      // Debug: Check if there are any orders at all and why products aren't loading
                      try {
                          $debugStmt = $db->query("SELECT COUNT(*) as cnt FROM admin_orders WHERE inventory_id IS NOT NULL AND confirmation_status = 'completed'");
                          $debugRow = $debugStmt->fetch(PDO::FETCH_ASSOC);
                          $orderCount = (int)($debugRow['cnt'] ?? 0);
                          
                          if ($orderCount > 0) {
                              // Check what inventory IDs are in completed orders
                              $orderIdsStmt = $db->query("SELECT DISTINCT inventory_id FROM admin_orders WHERE inventory_id IS NOT NULL AND confirmation_status = 'completed' LIMIT 5");
                              $orderIds = [];
                              while ($idRow = $orderIdsStmt->fetch(PDO::FETCH_ASSOC)) {
                                  $orderIds[] = (int)($idRow['inventory_id'] ?? 0);
                              }
                              
                              // Check if these inventory items exist and if they're deleted
                              if (!empty($orderIds)) {
                                  $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
                                  $invCheckStmt = $db->prepare("SELECT id, name, is_deleted FROM inventory WHERE id IN ($placeholders)");
                                  $invCheckStmt->execute($orderIds);
                                  $existingCheck = [];
                                  while ($invCheck = $invCheckStmt->fetch(PDO::FETCH_ASSOC)) {
                                      $existingCheck[(int)$invCheck['id']] = [
                                          'name' => $invCheck['name'],
                                          'deleted' => (int)($invCheck['is_deleted'] ?? 0)
                                      ];
                                  }
                                  
                                  $missingIds = array_diff($orderIds, array_keys($existingCheck));
                                  $deletedIds = array_filter($orderIds, function($id) use ($existingCheck) {
                                      return isset($existingCheck[$id]) && $existingCheck[$id]['deleted'] == 1;
                                  });
                                  
                                  if (!empty($missingIds)) {
                                      echo "<p class='text-info small mt-2'><strong>Note:</strong> Some orders reference inventory IDs that don't exist (IDs: " . implode(', ', $missingIds) . "). These will still be shown with default names.</p>";
                                  } elseif (!empty($deletedIds)) {
                                      echo "<p class='text-info small mt-2'><strong>Note:</strong> Some orders reference deleted inventory items (IDs: " . implode(', ', $deletedIds) . "). These will still be shown - all data comes from orders.</p>";
                                  } else {
                                      echo "<p class='text-warning small mt-2'><strong>Debug:</strong> Found {$orderCount} completed orders in database but no products loaded. Check error logs for details.</p>";
                                  }
                              }
                          } else {
                              echo "<p class='text-info small mt-2'>No completed orders found in database. Complete orders in Orders page first - items will appear in POS only when order status becomes 'completed'.</p>";
                          }
                      } catch (Exception $e) {
                          echo "<p class='text-danger small mt-2'>Error checking orders: " . htmlspecialchars($e->getMessage()) . "</p>";
                      }
                      ?>
                    </div>
                  <?php else: ?>
                  <?php foreach ($inventoryItems as $item):
                    $sellingPrice = round(($item['unit_price'] ?? 0) * (1 + $interestMarkup), 2);
                    $catKey = strtolower(trim($item['category'] ?? 'uncategorized')) ?: 'uncategorized';
                    $nameSafe = htmlspecialchars($item['name'] ?? 'Item');
                    $catSafe  = htmlspecialchars($item['category'] ?? 'Uncategorized');
                    $img = trim($item['image_url'] ?? '');
                    if ($img === '') {
                        $img = trim($item['image_path'] ?? '');
                    }
                    if ($img === '') {
                        $img = '../assets/img/placeholder.svg';
                    } else {
                        $img = '../' . $img;
                    }
                    $pid = (int)($item['id'] ?? 0);
                    $stockLevel = $item['available_quantity'] ?? 0;
                    $reorderThreshold = (int)($item['reorder_threshold'] ?? 0);
                    
                    // Get variations from completed orders
                    $variationStockMap = $item['variation_available_stocks'] ?? [];
                    $variationUnitMap  = $item['variation_units'] ?? [];
                    $variationPriceMap = $item['variation_prices'] ?? [];
                    
                    // Build ordered variations array
                    $orderedVariations = [];
                    foreach ($variationStockMap as $varKey => $stock) {
                        if (!empty($varKey) && trim($varKey) !== '' && trim($varKey) !== 'null') {
                            $orderedVariations[$varKey] = [
                                'stock' => (int)$stock,
                                'price' => isset($variationPriceMap[$varKey]) ? (float)$variationPriceMap[$varKey] : (float)($item['unit_price'] ?? 0),
                                'unit_type' => isset($variationUnitMap[$varKey]) ? $variationUnitMap[$varKey] : 'per piece'
                            ];
                        }
                    }
                    $hasVariations = !empty($orderedVariations);
                    
                    $variationStockJson = htmlspecialchars(json_encode($variationStockMap), ENT_QUOTES, 'UTF-8');
                    $variationUnitsJson = htmlspecialchars(json_encode($variationUnitMap), ENT_QUOTES, 'UTF-8');
                    $variationPricesJson = htmlspecialchars(json_encode($variationPriceMap), ENT_QUOTES, 'UTF-8');
                  ?>
                  <div class="product-item"
                       data-name="<?= strtolower($item['name'] ?? '') ?>"
                       data-category="<?= htmlspecialchars($catKey) ?>">
                    <div class="card product-card h-100 border-left-primary <?= $stockLevel <= 0 ? 'bg-light' : '' ?>"
                         data-id="<?= $pid ?>"
                         data-name="<?= $nameSafe ?>"
                         data-category="<?= $catSafe ?>"
                         data-unit-cost="<?= number_format((float)($item['unit_price'] ?? 0), 2, '.', '') ?>"
                         data-interest-markup="<?= $interestMarkup ?>"
                         data-base-price="<?= number_format($sellingPrice, 2, '.', '') ?>"
                         data-price="<?= number_format($sellingPrice, 2, '.', '') ?>"
                         data-base-stock="<?= $stockLevel ?>"
                         data-stock="<?= $stockLevel ?>"
                         data-available-stock="<?= $stockLevel ?>"
                         data-reorder="<?= $reorderThreshold ?>"
                         data-variation-stock='<?= $variationStockJson ?>'
                         data-variation-unittypes='<?= $variationUnitsJson ?>'
                         data-variation-prices='<?= $variationPricesJson ?>'>
                      <div class="position-relative">
                        <img src="<?= htmlspecialchars($img) ?>" alt="<?= $nameSafe ?>" class="product-img">
                        <button class="btn btn-sm btn-primary position-absolute top-0 end-0 m-1 upload-image-btn" 
                                data-product-id="<?= $pid ?>" 
                                data-product-name="<?= $nameSafe ?>"
                                title="Upload Image">
                          <i class="bi bi-camera"></i>
                        </button>
                      </div>
                      <div class="card-body d-flex flex-column">
                        <h6 class="text-truncate text-gray-800 mb-1" title="<?= $nameSafe ?>"><?= $nameSafe ?></h6>
                        <p class="text-muted small mb-2">
                          <i class="bi bi-tag me-1"></i><?= $catSafe ?>
                        </p>
                        <div class="mt-auto">
                          <div class="d-flex justify-content-between align-items-center mb-2">
                            <small class="text-muted">Cost: ₱<?= number_format($item['unit_price'], 2) ?></small>
                            <small class="text-success">+<?= (int)($interestMarkup * 100) ?>%</small>
                          </div>
                          <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="price-display">₱<?= number_format($sellingPrice, 2) ?> <small class="text-muted ms-1 auto-unit-label"></small></span>
                          </div>
                          
                          <!-- Variation Dropdown - From Orders -->
                          <div class="mb-2">
                            <label class="form-label small fw-bold mb-1" style="color: #2470dc;">
                              <i class="bi bi-list-ul me-1"></i>Select Variation from Orders:
                            </label>
                            <select class="form-select form-select-sm variation-select-ordered" 
                                    data-product-id="<?= $pid ?>"
                                    style="border: 2px solid #2470dc; font-weight: 500; cursor: pointer;">
                              <option value="">-- Choose Variation --</option>
                              <?php if ($hasVariations): ?>
                              <?php 
                              // Extract attribute names and calculate column widths for alignment
                              $attributeNames = [];
                              $attributeValues = []; // [attrName => [values...]]
                              $attributeWidths = []; // [attrName => maxWidth]
                              
                              foreach ($orderedVariations as $varKey => $varData) {
                                  if (!empty($varKey) && (strpos($varKey, '|') !== false || strpos($varKey, ':') !== false)) {
                                      $parts = explode('|', $varKey);
                                      foreach ($parts as $part) {
                                          $av = explode(':', trim($part), 2);
                                          if (count($av) === 2 && !empty(trim($av[0]))) {
                                              $attrName = trim($av[0]);
                                              $attrValue = trim($av[1]);
                                              
                                              if (!in_array($attrName, $attributeNames)) {
                                                  $attributeNames[] = $attrName;
                                                  $attributeValues[$attrName] = [];
                                                  $attributeWidths[$attrName] = strlen($attrName); // Start with header width
                                              }
                                              
                                              $attributeValues[$attrName][] = $attrValue;
                                              // Update max width for this attribute (including name length)
                                              $valueWidth = strlen($attrValue);
                                              if ($valueWidth > $attributeWidths[$attrName] - strlen($attrName)) {
                                                  $attributeWidths[$attrName] = max(strlen($attrName), $valueWidth) + 2; // +2 for padding
                                              }
                                          }
                                      }
                                  }
                              }
                              sort($attributeNames);
                              
                              // Build aligned header row
                              if (!empty($attributeNames)): 
                                  $headerParts = [];
                                  foreach ($attributeNames as $attrName) {
                                      $width = $attributeWidths[$attrName];
                                      // Use non-breaking spaces for alignment
                                      $padded = $attrName . str_repeat('&nbsp;', max(0, $width - strlen($attrName)));
                                      $headerParts[] = $padded;
                                  }
                              ?>
                              <option disabled style="background-color: #e9ecef; font-weight: bold; color: #495057; padding: 0.5rem; font-family: 'Courier New', monospace; white-space: pre;">
                                <?= implode(' | ', $headerParts) ?>
                              </option>
                              <option disabled style="border-bottom: 1px solid #dee2e6; padding: 0.25rem;"></option>
                              <?php endif; ?>
                              
                              <?php 
                              // Sort variations by formatted display name
                              uksort($orderedVariations, function($a, $b) {
                                  $aFormatted = formatVariationForDisplay($a);
                                  $bFormatted = formatVariationForDisplay($b);
                                  return strcmp($aFormatted, $bFormatted);
                              });
                              
                              foreach ($orderedVariations as $varKey => $varData): 
                                  $displayPrice = number_format($varData['price'] * (1 + $interestMarkup), 2);
                                  $stockQty = $varData['stock'];
                                  
                                  // Build aligned variation row
                                  $alignedParts = [];
                                  if (!empty($attributeNames)) {
                                      // Parse variation into attribute-value pairs
                                      $varAttrs = [];
                                      if (!empty($varKey) && (strpos($varKey, '|') !== false || strpos($varKey, ':') !== false)) {
                                          $parts = explode('|', $varKey);
                                          foreach ($parts as $part) {
                                              $av = explode(':', trim($part), 2);
                                              if (count($av) === 2) {
                                                  $varAttrs[trim($av[0])] = trim($av[1]);
                                              }
                                          }
                                      }
                                      
                                      // Build aligned columns
                                      foreach ($attributeNames as $attrName) {
                                          $value = isset($varAttrs[$attrName]) ? $varAttrs[$attrName] : '';
                                          $width = $attributeWidths[$attrName];
                                          // Use non-breaking spaces for alignment
                                          $padded = htmlspecialchars($value) . str_repeat('&nbsp;', max(0, $width - strlen($value)));
                                          $alignedParts[] = $padded;
                                      }
                                      $formattedVar = implode(' | ', $alignedParts);
                                  } else {
                                      // Fallback to simple format if no attributes
                                      $formattedVar = htmlspecialchars(formatVariationForDisplay($varKey));
                                  }
                              ?>
                              <option value="<?= htmlspecialchars($varKey, ENT_QUOTES) ?>" 
                                      data-price="<?= $varData['price'] ?>"
                                      data-display-price="<?= $displayPrice ?>"
                                      data-stock="<?= $stockQty ?>"
                                      data-unit-type="<?= htmlspecialchars($varData['unit_type']) ?>"
                                      style="font-family: 'Courier New', monospace; white-space: pre;">
                                <?= $formattedVar ?>
                              </option>
                              <?php endforeach; ?>
                              <?php else: ?>
                              <option value="" disabled>No variations ordered yet</option>
                              <?php endif; ?>
                            </select>
                            <div class="variation-selected-info mt-2" data-product-id="<?= $pid ?>" style="display: none;">
                              <div class="d-flex flex-column">
                                <span class="badge bg-info mb-2 fs-6">
                                  <i class="bi bi-tag-fill me-1"></i><span class="selected-variation-badge"></span>
                                </span>
                                <div class="alert alert-info py-2 px-2 mb-0">
                                  <small>
                                    <strong>Price:</strong> ₱<span class="selected-variation-price"></span> | 
                                    <strong>Stock:</strong> <span class="selected-variation-stock"></span>
                                  </small>
                                </div>
                              </div>
                            </div>
                          </div>
                          
                          <!-- Quantity Input -->
                          <div class="mb-2 mt-2">
                            <label class="form-label small mb-1" style="color: #2470dc;">
                              <i class="bi bi-hash me-1"></i>Quantity:
                            </label>
                            <div class="input-group input-group-sm">
                              <button class="btn btn-outline-secondary decrease-qty-btn" type="button" data-product-id="<?= $pid ?>" title="Decrease quantity">
                                <i class="bi bi-dash"></i>
                              </button>
                              <input type="number" 
                                     class="form-control text-center qty-input-product" 
                                     id="qty-input-<?= $pid ?>"
                                     data-product-id="<?= $pid ?>"
                                     value="1" 
                                     min="1" 
                                     max="9999"
                                     style="font-weight: 500;">
                              <button class="btn btn-outline-secondary increase-qty-btn" type="button" data-product-id="<?= $pid ?>" title="Increase quantity">
                                <i class="bi bi-plus"></i>
                              </button>
                            </div>
                          </div>
                          
                          <!-- Add to Cart Button -->
                          <button class="btn btn-primary btn-sm w-100 add-to-cart-btn" 
                                  data-product-id="<?= $pid ?>"
                                  style="margin-top: 0.5rem;">
                            <i class="bi bi-cart-plus me-1"></i>Add to Cart
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                  <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- Shopping Cart -->
          <div class="col-md-4">
            <div class="card dashboard-card cart-container">
              <div class="card-header bg-gradient-primary text-white">
                <h5 class="mb-0">
                  <i class="bi bi-cart3 me-2"></i>Shopping Cart
                </h5>
              </div>
              <div class="card-body p-0 cart-items">
                <div id="cartItems">
                  <div class="cart-empty">
                    <i class="bi bi-cart3 fs-1 mb-3"></i>
                    <p class="mb-0">Cart is empty</p>
                    <small>Add products to get started</small>
                  </div>
                </div>
              </div>
              <div class="cart-summary">
                <div class="d-flex justify-content-between mb-2">
                  <span class="fw-medium">Subtotal:</span>
                  <span id="subtotal" class="fw-bold text-primary">₱0.00</span>
                </div>
                <div class="d-flex justify-content-between mb-3">
                  <span class="fw-medium">Total:</span>
                  <span id="total" class="fw-bold fs-5 text-success">₱0.00</span>
                </div>
                <div class="d-grid gap-2">
                  <button class="btn btn-checkout" id="checkoutBtn" disabled>
                    <i class="bi bi-credit-card me-2"></i>Checkout
                  </button>
                  <button class="btn btn-outline-danger btn-sm" id="clearCartBtn" disabled>
                    <i class="bi bi-trash me-2"></i>Clear Cart
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </main>
    </div>
  </div>

  <script>
  try {
    window.addEventListener('storage', function(e){
      if (e && e.key === 'inventory_last_update') {
        try { location.reload(); } catch(_) {}
      }
    });
  } catch(_) {}
  </script>
  <!-- Toast container -->
  <div class="toast-container position-fixed top-0 end-0 p-3" id="posToastContainer"></div>

  <!-- Checkout Modal -->
  <div class="modal fade" id="checkoutModal" tabindex="-1">
    <div class="modal-dialog">
      <form id="checkoutForm" class="modal-content">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="process_sale">
        <input type="hidden" name="ajax" value="1">
        <input type="hidden" name="cart_items" id="cartItemsInput">
        <div class="modal-header">
          <h5 class="modal-title">Checkout</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <h6>Order Summary</h6>
          <div id="orderSummary" class="mb-3"></div>
          <label>Amount Received</label>
          <div class="input-group mb-3">
            <span class="input-group-text">₱</span>
            <input type="number" id="paymentAmount" class="form-control" min="0" step="0.01" required>
          </div>
          <label>Change</label>
          <div class="input-group">
            <span class="input-group-text">₱</span>
            <input type="text" id="changeAmount" class="form-control" readonly>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button id="processPaymentBtn" class="btn btn-primary" disabled>Process Payment</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Receipt Modal -->
  <div class="modal fade" id="receiptModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Receipt</h5>
          <button class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div id="receiptContent" class="p-3">
            <div class="receipt-container">
              <div class="receipt-header">
                <h4>Hardware Store</h4>
                <p>Talisay City</p>
                <p>Tel: 123-456-7890</p>
              </div>
              
              <div class="receipt-details">
                <p><span>Date:</span> <span id="receiptDate"></span></p>
                <p><span>Cashier:</span> <span><?= htmlspecialchars($_SESSION['admin']['username'] ?? 'Admin') ?></span></p>
                <p><span>Receipt #:</span> <span id="receiptNumber"><?= date('YmdHis') ?></span></p>
              </div>
              
              <div class="receipt-items">
                <table>
                  <thead>
                    <tr>
                      <th class="col-item">Item</th>
                      <th class="col-qty">Qty</th>
                      <th class="col-price">Price</th>
                      <th class="col-total">Total</th>
                    </tr>
                  </thead>
                  <tbody id="receiptItemsBody">
                  </tbody>
                </table>
              </div>
              
              <div class="receipt-totals">
                <div><span>Subtotal:</span> <span>₱<span id="receiptSubtotal"></span></span></div>
                <div><span>Total:</span> <span>₱<span id="receiptTotal"></span></span></div>
                <div><span>Amount Received:</span> <span>₱<span id="receiptAmountReceived"></span></span></div>
                <div><span>Change:</span> <span>₱<span id="receiptChange"></span></span></div>
              </div>
              
              <div class="receipt-barcode" id="receiptBarcode">
                <?= date('YmdHis') ?>
              </div>
              
              <div class="receipt-footer">
                <p>Thank you for your purchase!</p>
                <p>Please come again</p>
                <p>VAT Registered</p>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button id="printPreviewBtn" class="btn btn-info">Print Preview</button>
          <button id="printBtn" class="btn btn-primary">Print</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Image Upload Modal -->
  <div class="modal fade" id="imageUploadModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Upload Product Image</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form id="imageUploadForm" enctype="multipart/form-data">
          <div class="modal-body">
            <input type="hidden" id="uploadProductId" name="product_id">
            <p>Upload image for: <strong id="uploadProductName"></strong></p>
            
            <div class="mb-3">
              <label for="productImage" class="form-label">Select Image</label>
              <input type="file" class="form-control" id="productImage" name="product_image" accept="image/*" required>
            </div>
            
            <div id="imagePreview" class="text-center" style="display: none;">
              <img id="previewImg" src="" alt="Preview" class="img-thumbnail" style="max-width: 200px; max-height: 200px;">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary" id="uploadBtn">
              <i class="bi bi-upload"></i> Upload
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- JS Libraries -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="../js/unit_utils.js"></script>
  <script src="../js/variation_state.js"></script>
  <script>
  $(function(){
    let cart = [],
        lastReceiptHTML = '',
        activeCategory = 'all';
    const checkoutModal = new bootstrap.Modal('#checkoutModal'),
          receiptModal  = new bootstrap.Modal('#receiptModal');
    
    // Initialize quantity inputs with correct max values based on stock
    $('.qty-input-product').each(function(){
      const $input = $(this);
      const productId = parseInt($input.data('product-id') || '0', 10);
      const $card = $input.closest('.product-card');
      
      if ($card.length) {
        const baseStock = parseInt($card.attr('data-base-stock') || $card.attr('data-stock') || 0, 10);
        if (baseStock > 0) {
          $input.attr('max', baseStock);
        }
      }
    });

    function showToast(message, type='info'){
      const container = document.getElementById('posToastContainer');
      if(!container) return alert(message);
      const el = document.createElement('div');
      const bg = type==='danger' ? 'bg-danger text-white' : type==='warning' ? 'bg-warning text-dark' : type==='success' ? 'bg-success text-white' : 'bg-primary text-white';
      el.className = 'toast align-items-center '+bg;
      el.setAttribute('role','alert');
      el.setAttribute('aria-live','assertive');
      el.setAttribute('aria-atomic','true');
      el.innerHTML = '<div class="d-flex">'+
                       '<div class="toast-body">'+message+'</div>'+
                       '<button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>'+
                     '</div>';
      container.appendChild(el);
      const t = new bootstrap.Toast(el, { delay: 2000 });
      t.show();
      el.addEventListener('hidden.bs.toast', ()=> el.remove());
    }

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
      return values.join(' - ');
    }

    function escapeHtml(str){ return $('<div>').text(str).html(); }
    
    function truncateProductName(name, maxLength = 25) {
      if (name.length <= maxLength) return name;
      return name.substring(0, maxLength - 3) + '...';
    }

    // Handle PHP-rendered dropdown (variation-select-ordered) from orders
    // When variation is selected, ALIGN stock and price immediately
    $(document).on('change', 'select.variation-select-ordered', function(e){
      e.stopPropagation();
      const $select = $(this);
      const productId = parseInt($select.data('product-id') || '0', 10);
      const $card = $select.closest('.product-card');
      
      if (!$card.length || !productId) return;
      
      const selectedOption = $select.find('option:selected');
      const $infoDiv = $(`.variation-selected-info[data-product-id="${productId}"]`);
      
      if (selectedOption.val()) {
        const variationKey = selectedOption.val().replace(/&quot;/g, '"');
        
        // Get EXACT stock and price from selected option (from orders table)
        const stock = parseInt(selectedOption.data('stock') || 0, 10);
        const variationBasePrice = parseFloat(selectedOption.data('price') || 0); // Base price from orders
        const unitType = selectedOption.data('unit-type') || 'per piece';
        
        // Calculate selling price with markup - ALWAYS use markup from card
        const markup = parseFloat($card.attr('data-interest-markup') || 0);
        const sellingPrice = variationBasePrice * (1 + markup);
        
        // Extract variation name for display (just the text, no parsing needed anymore)
        let variationName = selectedOption.text().trim();
        
        // Show selected variation info with badge - show EXACT stock and price
        if ($infoDiv.length) {
          $infoDiv.find('.selected-variation-badge').text(variationName);
          $infoDiv.find('.selected-variation-price').text(sellingPrice.toFixed(2)); // Use calculated selling price
          $infoDiv.find('.selected-variation-stock').text(stock + ' pcs');
          $infoDiv.show();
        }
        
        // ALIGN card data attributes with selected variation
        // Price: Use variation price from orders (with markup applied)
        
        $card.attr('data-price', sellingPrice.toFixed(2));
        $card.attr('data-stock', stock); // Stock from orders
        $card.attr('data-available-stock', stock); // Available stock
        $card.attr('data-unit_type', unitType);
        
        // Update price display immediately - shows selling price with markup
        $card.find('.price-display').html(`₱${sellingPrice.toFixed(2)} <small class="text-muted ms-1">${unitType}</small>`);
        
        // Update stock display if it exists on the card
        const $stockBadge = $card.find('.stock-badge, .stock-display, [data-stock-display]');
        if ($stockBadge.length) {
          $stockBadge.text(`${stock} in stock`);
          // Update badge color based on stock level
          $stockBadge.removeClass('bg-danger bg-warning bg-success');
          if (stock <= 0) {
            $stockBadge.addClass('bg-danger').text('Out of stock');
          } else if (stock <= ($card.data('reorder') || 0)) {
            $stockBadge.addClass('bg-warning').text(`Low: ${stock} left`);
          } else {
            $stockBadge.addClass('bg-success').text(`${stock} in stock`);
          }
        }
        
        // Visual feedback - highlight price change
        const $priceDisplay = $card.find('.price-display');
        $priceDisplay.css({
          'transition': 'all 0.3s ease',
          'color': '#28a745',
          'transform': 'scale(1.05)'
        });
        setTimeout(() => {
          $priceDisplay.css({
            'color': '#2470dc',
            'transform': 'scale(1)'
          });
        }, 300);
        
        // Store variation key on card for cart addition
        $card.attr('data-selected-variation', variationKey);
        $card.attr('data-selected-variation-stock', stock);
        $card.attr('data-selected-variation-price', variationBasePrice);
        
        // Update quantity input max value based on available stock
        const $qtyInput = $(`#qty-input-${productId}`);
        if ($qtyInput.length) {
          $qtyInput.attr('max', Math.max(1, stock));
          // Ensure current value doesn't exceed max
          const currentQty = parseInt($qtyInput.val() || 1, 10);
          if (currentQty > stock) {
            $qtyInput.val(Math.max(1, stock));
          }
        }
        
        console.log(`POS: Variation selected - Product ID: ${productId}, Variation: ${variationName}, Stock: ${stock}, Base Price: ₱${variationBasePrice.toFixed(2)}, Selling Price: ₱${sellingPrice.toFixed(2)}`);
      } else {
        // No variation selected - reset to base product
        const basePrice = parseFloat($card.attr('data-base-price') || 0);
        const baseStock = parseInt($card.attr('data-base-stock') || 0);
        const markup = parseFloat($card.attr('data-interest-markup') || 0);
        
        $card.attr('data-price', basePrice.toFixed(2));
        $card.attr('data-stock', baseStock);
        $card.attr('data-available-stock', baseStock);
        $card.find('.price-display').html(`₱${basePrice.toFixed(2)} <small class="text-muted ms-1 auto-unit-label"></small>`);
        $card.removeAttr('data-selected-variation');
        $card.removeAttr('data-selected-variation-stock');
        $card.removeAttr('data-selected-variation-price');
        
        // Update quantity input max value based on available stock
        const $qtyInput = $(`#qty-input-${productId}`);
        if ($qtyInput.length) {
          $qtyInput.attr('max', Math.max(1, baseStock));
          // Ensure current value doesn't exceed max
          const currentQty = parseInt($qtyInput.val() || 1, 10);
          if (currentQty > baseStock) {
            $qtyInput.val(Math.max(1, baseStock));
          }
        }
        
        if ($infoDiv.length) {
          $infoDiv.hide();
        }
      }
    });

    // Quantity input handlers for product cards
    $(document).on('click', '.increase-qty-btn', function(e){
      e.stopPropagation();
      const $btn = $(this);
      const productId = parseInt($btn.data('product-id') || '0', 10);
      const $input = $(`#qty-input-${productId}`);
      if (!$input.length) return;
      
      const currentQty = parseInt($input.val() || 1, 10);
      const maxQty = parseInt($input.attr('max') || 9999, 10);
      if (currentQty < maxQty) {
        $input.val(currentQty + 1);
      }
    });
    
    $(document).on('click', '.decrease-qty-btn', function(e){
      e.stopPropagation();
      const $btn = $(this);
      const productId = parseInt($btn.data('product-id') || '0', 10);
      const $input = $(`#qty-input-${productId}`);
      if (!$input.length) return;
      
      const currentQty = parseInt($input.val() || 1, 10);
      if (currentQty > 1) {
        $input.val(currentQty - 1);
      }
    });
    
    // Validate quantity input on change
    $(document).on('input change blur', '.qty-input-product', function(e){
      const $input = $(this);
      let val = parseInt($input.val() || 1, 10);
      const min = parseInt($input.attr('min') || 1, 10);
      const max = parseInt($input.attr('max') || 9999, 10);
      
      if (isNaN(val) || val < min) {
        val = min;
        $input.val(val);
      } else if (val > max) {
        val = max;
        $input.val(val);
        if (e.type === 'blur' || e.type === 'change') {
          showToast(`Maximum quantity is ${max}`, 'warning');
        }
      }
    });

    // Add product to cart button - use ALIGNED stock and price from selected variation
    $(document).on('click', '.add-to-cart-btn', function(e){
      e.stopPropagation(); // Prevent card click event
      const $btn = $(this);
      const btnProductId = parseInt($btn.data('product-id') || '0', 10);
      const $card = $btn.closest('.product-card');
      
      if (!$card.length || !btnProductId) return;
      
      const id    = +$card.data('id'),
            name  = $card.data('name'),
            reorder = +($card.data('reorder') || 0);
      
      // Get quantity from input field
      const $qtyInput = $(`#qty-input-${btnProductId}`);
      let quantity = parseInt($qtyInput.val() || 1, 10);
      if (isNaN(quantity) || quantity < 1) {
        quantity = 1;
        $qtyInput.val(1);
      }
      
      // Get selected variation FIRST - this ensures we use the correct stock/price
      const $select = $card.find(`select.variation-select-ordered[data-product-id="${btnProductId}"]`);
      let variation = '';
      let unitType = 'per piece';
      let baseStock = 0;
      let price = 0;
      
      // Check if variation is selected - use ALIGNED data from card attributes
      if ($select.length && $select.val()) {
        variation = $select.val().replace(/&quot;/g, '"');
        const selectedOption = $select.find('option:selected');
        unitType = selectedOption.data('unit-type') || 'per piece';
        
        // Use ALIGNED stock and price from card (updated by variation change handler)
        baseStock = parseInt($card.attr('data-selected-variation-stock') || $card.attr('data-stock') || 0, 10);
        const variationBasePrice = parseFloat($card.attr('data-selected-variation-price') || selectedOption.data('price') || 0);
        const markup = parseFloat($card.attr('data-interest-markup') || 0);
        price = variationBasePrice * (1 + markup);
        
        // Double-check: get from selected option if card doesn't have it
        if (!baseStock || baseStock <= 0) {
          baseStock = parseInt(selectedOption.data('stock') || 0, 10);
        }
        if (!price || price <= 0) {
          const optPrice = parseFloat(selectedOption.data('price') || 0);
          price = optPrice * (1 + markup);
        }
        
        console.log(`POS: Adding to cart - Product: ${name}, Variation: ${variation}, Quantity: ${quantity}, Stock: ${baseStock}, Price: ₱${price.toFixed(2)}`);
      } else {
        // No variation selected - use base product stock and price
        baseStock = parseInt($card.attr('data-base-stock') || $card.attr('data-stock') || 0, 10);
        price = parseFloat($card.attr('data-base-price') || $card.data('price') || 0);
        unitType = 'per piece';
      }
      
      // Validate stock before adding
      if (!baseStock || baseStock <= 0) {
        return showToast('Out of stock','danger');
      }
      
      // Validate quantity doesn't exceed stock
      if (quantity > baseStock) {
        quantity = baseStock;
        $qtyInput.val(quantity);
        return showToast(`Maximum available: ${baseStock}`, 'warning');
      }
      
      // Find existing cart item (match by id, variation, and unit_type)
      let item = cart.find(i => i.id === id && i.unit_type === unitType && i.variation === variation);
      
      if (item) {
        // Item already in cart - increase quantity if stock allows
        const newTotalQty = item.quantity + quantity;
        if (newTotalQty <= baseStock) {
          item.quantity = newTotalQty;
          // Update stock in cart item (in case stock changed)
          item.stock = baseStock;
          item.price = price; // Update price in case it changed
        } else {
          const canAdd = baseStock - item.quantity;
          if (canAdd > 0) {
            item.quantity = baseStock;
            showToast(`Added ${canAdd} more (max available: ${baseStock})`, 'warning');
          } else {
            return showToast('Stock limit reached','warning');
          }
        }
      } else {
        // New item - add to cart with specified quantity
        cart.push({
          id, 
          name, 
          price, 
          quantity: quantity, 
          stock: baseStock, 
          unit_type: unitType, 
          variation
        });
      }
      
      // Reset quantity input to 1 after adding
      $qtyInput.val(1);
      
      // Low stock warning
      if (reorder && baseStock <= reorder && baseStock > 0) {
        showToast(`Low stock: only ${baseStock} left`, 'warning');
      }
      
      updateCart();
      showToast(`${name}${variation ? ' (' + formatVariationForDisplay(variation) + ')' : ''} x${quantity} added to cart!`, 'success');
    });

    function updateCart(){
      if(cart.length===0){
        $('#cartItems').html(`
          <div class="cart-empty">
            <i class="bi bi-cart3 fs-1 mb-3"></i>
            <p class="mb-0">Cart is empty</p>
            <small>Add products to get started</small>
          </div>
        `);
        $('#subtotal,#total').text('₱0.00');
        $('#checkoutBtn,#clearCartBtn').prop('disabled',true);
        return;
      }
      let sub=0, html='';
      cart.forEach((it,i)=>{
        let line=it.price*it.quantity;
        sub+=line;
        html+=`
          <div class="cart-item border-bottom">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div class="flex-grow-1">
                <h6 class="mb-1 text-gray-800">${escapeHtml(it.name)}</h6>
                <div class="text-muted small">Unit: ${it.unit_type || 'per piece'}${it.variation ? ' • ' + formatVariationForDisplay(it.variation) : ''}</div>
                <small class="text-muted">
                  <i class="bi bi-currency-dollar me-1"></i>₱${it.price.toFixed(2)} each
                </small>
              </div>
              <button class="btn btn-sm btn-outline-danger remove-item" data-index="${i}" title="Remove item">
                <i class="bi bi-x"></i>
              </button>
            </div>
            <div class="d-flex justify-content-between align-items-center">
              <div class="quantity-controls">
                <button class="btn btn-outline-secondary decrease-qty" data-index="${i}">-</button>
                <input type="number" class="form-control text-center qty-input" value="${it.quantity}" min="1" max="${it.stock}" data-index="${i}" style="width: 4.5rem; min-width: 4.5rem;">
                <button class="btn btn-outline-secondary increase-qty" data-index="${i}">+</button>
              </div>
              <span class="fw-bold text-primary">₱${line.toFixed(2)}</span>
            </div>
          </div>
        `;
      });
      $('#cartItems').html(`<div class="p-3">${html}</div>`);
      $('#subtotal').text(`₱${sub.toFixed(2)}`);
      $('#total').text(`₱${sub.toFixed(2)}`);
      $('#checkoutBtn,#clearCartBtn').prop('disabled',false);
    }

    // Cart controls
    $(document).on('click','.remove-item',function(){
      cart.splice($(this).data('index'),1);
      updateCart();
    });
    $(document).on('click','.decrease-qty',function(){
      let i=$(this).data('index');
      if(cart[i].quantity>1) cart[i].quantity--, updateCart();
    });
    $(document).on('click','.increase-qty',function(){
      let i=$(this).data('index');
      if(cart[i].quantity<cart[i].stock) cart[i].quantity++ , updateCart();
      else showToast('Stock limit reached','warning');
    });
    // Handle direct quantity input - allow any number up to stock limit and update price in real-time
    $(document).on('input change blur', '.qty-input', function(e){
      let i = parseInt($(this).data('index') || -1);
      if(i < 0 || !cart[i]) return;
      
      let inputVal = $(this).val();
      let maxStock = cart[i].stock || 1;
      
      // If empty input, use 0 for real-time calculation (will show ₱0.00)
      if(inputVal === '' || inputVal === null) {
        cart[i].quantity = 0;
        updateCart(); // Update price immediately even when typing
        return;
      }
      
      let newQty = parseInt(inputVal || 0);
      
      // Validate and clamp quantity
      if(isNaN(newQty) || newQty < 1) {
        newQty = 1;
        $(this).val(1);
      } else if(newQty > maxStock) {
        newQty = maxStock;
        $(this).val(maxStock);
        // Only show toast on blur/change, not on every input
        const eventType = e.type || event.type;
        if(eventType === 'blur' || eventType === 'change') {
          showToast(`Stock limit reached. Maximum: ${maxStock}`, 'warning');
        }
      }
      
      cart[i].quantity = newQty;
      updateCart(); // Update price in real-time as user types
    });
    // Allow all numeric input - no restriction to single digits
    $(document).on('keydown', '.qty-input', function(e){
      // Allow: backspace, delete, tab, escape, enter
      if([46, 8, 9, 27, 13].indexOf(e.keyCode) !== -1 ||
         // Allow: Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
         (e.keyCode === 65 && e.ctrlKey === true) ||
         (e.keyCode === 67 && e.ctrlKey === true) ||
         (e.keyCode === 86 && e.ctrlKey === true) ||
         (e.keyCode === 88 && e.ctrlKey === true) ||
         // Allow: home, end, left, right
         (e.keyCode >= 35 && e.keyCode <= 39)) {
        return;
      }
      // Allow numbers 0-9 from main keyboard and numpad - NO RESTRICTION TO SINGLE DIGITS
      if((e.keyCode >= 48 && e.keyCode <= 57) || (e.keyCode >= 96 && e.keyCode <= 105)) {
        return; // Allow all numeric digits
      }
      // Block everything else (letters, special chars)
      if((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
        e.preventDefault();
      }
    });
    $('#clearCartBtn').click(function(){
      if(confirm('Clear cart?')){ cart=[]; updateCart(); }
    });

    // Search + Category filter
    function applyFilters(){
      const term = $('#searchProduct').val().toLowerCase();
      $('.product-item').each(function(){
        const name = $(this).data('name') || '';
        const cat  = $(this).data('category') || '';
        const matchesText = !term || name.includes(term) || cat.includes(term);
        const matchesCat  = (activeCategory === 'all') || (cat === activeCategory);
        $(this).toggle(matchesText && matchesCat);
      });
    }
    $('#searchProduct').on('input', applyFilters);
    $('#clearSearch').click(function(){
      $('#searchProduct').val(''); applyFilters();
    });

    $('.category-pill').on('click', function(){
      $('.category-pill').removeClass('active');
      $(this).addClass('active');
      activeCategory = $(this).data('category');
      applyFilters();
    });

    // Checkout
    $('#checkoutBtn').click(function(){
      if(!cart.length) return showToast('Cart is empty','warning');
      
      let validItems = cart.filter(item => item && item.name && item.price >= 0 && item.quantity > 0);
      if(validItems.length === 0) return showToast('No valid items in cart','warning');
      
      let sum = 0;
      let html = `
        <div class="table-responsive">
          <table class="table table-sm table-striped">
            <thead class="table-dark">
              <tr>
                <th>Item</th>
                <th class="text-center">Qty</th>
                <th class="text-end">Price</th>
                <th class="text-end">Total</th>
              </tr>
            </thead>
            <tbody>
      `;
      
      validItems.forEach((item, index) => {
        const lineTotal = (item.price || 0) * (item.quantity || 0);
        sum += lineTotal;
        html += `
          <tr>
            <td>
              <strong>${escapeHtml(item.name || 'Unknown Item')}</strong>
              <br><small class="text-muted">${item.variation ? formatVariationForDisplay(item.variation) : 'No variation'}</small>
            </td>
            <td class="text-center">
              <span class="badge bg-primary">${item.quantity || 0}</span>
            </td>
            <td class="text-end">₱${(item.price || 0).toFixed(2)}</td>
            <td class="text-end"><strong>₱${lineTotal.toFixed(2)}</strong></td>
          </tr>
        `;
      });
      
      html += `
            </tbody>
            <tfoot class="table-dark">
              <tr>
                <th colspan="3" class="text-end">Total Amount:</th>
                <th class="text-end">₱${sum.toFixed(2)}</th>
              </tr>
            </tfoot>
          </table>
        </div>
      `;
      
      $('#orderSummary').html(html);
      $('#cartItemsInput').val(JSON.stringify(validItems));
      $('#paymentAmount').val('');
      $('#changeAmount').val('');
      $('#processPaymentBtn').prop('disabled', true);
      checkoutModal.show();
    });

    // Calculate change
    $('#paymentAmount').on('input',function(){
      const pay=+$(this).val(), tot=+$('#total').text().replace('₱','');
      if(pay>=tot){
        $('#changeAmount').val((pay-tot).toFixed(2));
        $('#processPaymentBtn').prop('disabled',false);
      } else {
        $('#changeAmount').val('Insufficient');
        $('#processPaymentBtn').prop('disabled',true);
      }
    });

    // AJAX payment submission
    $('#checkoutForm').submit(function(e){
      e.preventDefault();
      $.post('', $(this).serialize(), function(resp){
        if(resp.status==='success'){
          const tot = resp.total_amount,
                pay = +$('#paymentAmount').val();
          $('#receiptDate').text(new Date().toLocaleString());
          let html = '';
          cart.forEach(item => {
            const lineTotal = (item.price || 0) * (item.quantity || 0);
            const itemName = truncateProductName(escapeHtml(item.name || 'Unknown Item'));
            const variationText = item.variation ? formatVariationForDisplay(item.variation) : '';
            
            html += `
              <tr>
                <td class="col-item">
                  <div class="item-name">${itemName}</div>
                  ${variationText ? `<div class="item-variation">${variationText}</div>` : ''}
                </td>
                <td class="col-qty">${item.quantity || 0}</td>
                <td class="col-price">${(item.price || 0).toFixed(2)}</td>
                <td class="col-total">${lineTotal.toFixed(2)}</td>
              </tr>
            `;
          });
          
          // Add totals row
          html += `
            <tr style="border-top: 1px solid #000;">
              <td colspan="3" style="text-align: right; font-weight: bold;">TOTAL:</td>
              <td class="col-total" style="font-weight: bold;">${tot.toFixed(2)}</td>
            </tr>
          `;
          
          $('#receiptItemsBody').html(html);
          $('#receiptSubtotal,#receiptTotal').text(`${tot.toFixed(2)}`);
          $('#receiptAmountReceived').text(`${pay.toFixed(2)}`);
          $('#receiptChange').text(`${(pay-tot).toFixed(2)}`);
          
          // Update receipt number and barcode
          const receiptNumber = '<?= date('YmdHis') ?>' + Math.floor(Math.random() * 1000);
          $('#receiptNumber').text(receiptNumber);
          $('#receiptBarcode').text(receiptNumber);
          receiptModal.show();

        lastReceiptHTML = $('#receiptContent').html();
        cart=[]; updateCart();

          $.ajax({
            url: 'ajax/get_alert_counts.php',
            method: 'GET',
            dataType: 'json'
          }).done(function(r){
            var c = parseInt((r && (r.active_stock_alerts||r.total_alerts||0)) ,10) || 0;
            var $b = $('#sidebarAlertBadge');
            if(c>0){
              if($b.length===0){
                $('a[href="alerts.php"]').append('<span class="badge bg-danger ms-1" id="sidebarAlertBadge">'+c+'</span>');
              } else {
                $b.text(c).show();
              }
            } else if($b.length){
              $b.hide();
            }
          });
        } else {
          showToast(resp.message,'danger');
        }
      }, 'json').fail(function(){
        showToast('Error processing sale.','danger');
      });
    });

    // Print Preview
    $('#printPreviewBtn').click(function(){
      const receiptHTML = $('#receiptContent').html();
      const previewWindow = window.open('', '_blank', 'width=350,height=600,scrollbars=yes,resizable=yes');
      previewWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
          <title>Receipt Preview</title>
          <style>
            body { 
              font-family: 'Courier New', monospace; 
              margin: 0; 
              padding: 10px; 
              width: 300px;
              font-size: 10pt;
            }
            .receipt-header { text-align: center; margin-bottom: 10px; }
            .receipt-header h4 { margin: 2px 0; font-size: 12pt; }
            .receipt-header p { margin: 1px 0; font-size: 9pt; }
            .receipt-details p { margin: 2px 0; display: flex; justify-content: space-between; }
            .receipt-items table { width: 100%; border-collapse: collapse; }
            .receipt-items th, .receipt-items td { text-align: left; padding: 1px 0; font-size: 9pt; }
            .receipt-items th { border-bottom: 1px solid #000; }
            .col-item { width: 120px; }
            .col-qty { width: 25px; text-align: center; }
            .col-price { width: 50px; text-align: right; }
            .col-total { width: 60px; text-align: right; }
            .item-name { font-weight: normal; }
            .item-variation { font-size: 8pt; color: #666; }
            .receipt-totals div { display: flex; justify-content: space-between; margin: 2px 0; }
            .receipt-footer { text-align: center; margin-top: 15px; padding-top: 10px; border-top: 1px dashed #000; }
            .receipt-barcode { text-align: center; margin: 10px 0; font-family: 'Libre Barcode 128', monospace; font-size: 20pt; }
          </style>
        </head>
        <body>
          ${receiptHTML}
        </body>
        </html>
      `);
      previewWindow.document.close();
    });

    // Print
    $('#printBtn').click(function(){
      const receiptContent = $('#receiptContent').html();
      const printWindow = window.open('', '_blank', 'width=350,height=600');
      printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
          <title>Receipt</title>
          <style>
            @media print {
              body { 
                width: 80mm; 
                margin: 0; 
                padding: 2mm;
                font-family: 'Courier New', monospace;
                font-size: 10pt;
              }
              .receipt-header { text-align: center; margin-bottom: 3mm; }
              .receipt-header h4 { margin: 1mm 0; font-size: 12pt; }
              .receipt-header p { margin: 0.5mm 0; font-size: 9pt; }
              .receipt-details p { margin: 0.5mm 0; display: flex; justify-content: space-between; }
              .receipt-items table { width: 100%; border-collapse: collapse; }
              .receipt-items th, .receipt-items td { padding: 0.5mm 0; font-size: 9pt; }
              .receipt-items th { border-bottom: 1px solid #000; }
              .col-item { width: 35mm; }
              .col-qty { width: 8mm; text-align: center; }
              .col-price { width: 15mm; text-align: right; }
              .col-total { width: 18mm; text-align: right; }
              .item-name { font-weight: normal; word-wrap: break-word; max-width: 35mm; overflow-wrap: break-word; }
              .item-variation { font-size: 8pt; color: #666; }
              .receipt-totals div { display: flex; justify-content: space-between; margin: 0.5mm 0; }
              .receipt-footer { text-align: center; margin-top: 5mm; padding-top: 2mm; border-top: 1px dashed #000; }
              .receipt-barcode { text-align: center; margin: 3mm 0; font-family: 'Libre Barcode 128', monospace; font-size: 24pt; }
              @page { size: 80mm auto; margin: 0; }
            }
          </style>
        </head>
        <body>
          ${receiptContent}
        </body>
        </html>
      `);
      printWindow.document.close();
      printWindow.print();
    });

    $('#printReceiptBtn').click(function(){
      if(!lastReceiptHTML) {
        return showToast('No receipt available. Please checkout first.');
      }
      $('#receiptContent').html(lastReceiptHTML);
      receiptModal.show();
    });

    // Image upload
    const imageUploadModal = new bootstrap.Modal(document.getElementById('imageUploadModal'));
    
    $(document).on('click', '.upload-image-btn', function(e) {
      e.stopPropagation();
      const productId = $(this).data('product-id');
      const productName = $(this).data('product-name');
      
      $('#uploadProductId').val(productId);
      $('#uploadProductName').text(productName);
      $('#productImage').val('');
      $('#imagePreview').hide();
      
      imageUploadModal.show();
    });

    $('#productImage').change(function() {
      const file = this.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
          $('#previewImg').attr('src', e.target.result);
          $('#imagePreview').show();
        };
        reader.readAsDataURL(file);
      } else {
        $('#imagePreview').hide();
      }
    });

    $('#imageUploadForm').submit(function(e) {
      e.preventDefault();
      
      const formData = new FormData(this);
      const uploadBtn = $('#uploadBtn');
      const originalText = uploadBtn.html();
      
      uploadBtn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Uploading...');
      
      $.ajax({
        url: 'upload_product_image.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
          if (response.success) {
            const productId = $('#uploadProductId').val();
            const productCard = $(`.product-card[data-id="${productId}"]`);
            const newImageUrl = '../' + response.image_url;
            
            productCard.find('.product-img').attr('src', newImageUrl);
            
            imageUploadModal.hide();
            showToast('Image uploaded successfully!', 'success');
          } else {
            showToast('Upload failed: ' + response.message,'danger');
          }
        },
        error: function() {
          showToast('Upload failed. Please try again.');
        },
        complete: function() {
          uploadBtn.prop('disabled', false).html(originalText);
        }
      });
    });
  });
  </script>
  <script>
    (function(){
      function refreshAlertBadge(){
        $.ajax({
          url: 'ajax/get_alert_counts.php',
          method: 'GET',
          dataType: 'json'
        }).done(function(r){
          var c = parseInt((r && (r.active_stock_alerts||r.total_alerts||0)) ,10) || 0;
          var $b = $('#sidebarAlertBadge');
          if(c>0){
            if($b.length===0){
              $('a[href="alerts.php"]').append('<span class="badge bg-danger ms-1" id="sidebarAlertBadge">'+c+'</span>');
            } else {
              $b.text(c).show();
            }
          } else if($b.length){
            $b.hide();
          }
        });
      }
      setInterval(refreshAlertBadge,30000);
    })();
  </script>
  <script src="../js/i18n.js"></script>
</body>
</html>

