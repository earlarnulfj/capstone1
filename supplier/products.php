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
            $html = '<div class="col-md-6 mb-3">';
            $html .= '<label class="form-label small">' . $attr . ': ' . $val . '</label>';
            $html .= '<div class="input-group input-group-sm mb-2">';
            $html .= '<span class="input-group-text">₱</span>';
            $html .= '<input type="number" class="form-control var-price" data-key="' . htmlspecialchars($key) . '" name="variation_prices[' . htmlspecialchars($key) . ']" step="0.01" min="0" placeholder="Price">';
            $html .= '</div>';
            $html .= '<div class="input-group input-group-sm">';
            $html .= '<span class="input-group-text">Qty</span>';
            $html .= '<input type="number" class="form-control var-stock" data-key="' . htmlspecialchars($key) . '" name="variation_stocks[' . htmlspecialchars($key) . ']" step="1" min="0" placeholder="Stock" value="0">';
            $html .= '</div>';
            $html .= '<div class="form-check mt-1">';
            $html .= '<input class="form-check-input var-delete" type="checkbox" value="' . htmlspecialchars($key) . '" id="del_' . htmlspecialchars($key) . '" name="delete_variations[]">';
            $html .= '<label class="form-check-label" for="del_' . htmlspecialchars($key) . '">Remove this variation</label>';
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
    
    // Get full product details for edit
    if ($action === 'get_product') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        if ($product_id > 0 && $catalog->belongsToSupplier($product_id, $supplier_id)) {
            $stmt = $db->prepare("SELECT id, sku, name, description, category, unit_type, supplier_quantity, reorder_threshold, location, COALESCE(is_deleted,0) AS is_deleted FROM supplier_catalog WHERE id = :id AND supplier_id = :sid LIMIT 1");
            $stmt->execute([':id' => $product_id, ':sid' => $supplier_id]);
            $prod = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($prod) {
                $vars = $spVariation->getByProduct($product_id);
                echo json_encode(['success' => true, 'product' => $prod, 'variations' => $vars]);
                exit;
            }
        }
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
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
        
        // Add new product
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
                // Validate unit type selection against code map
                if (empty($unit_type_code) || !isset($UNIT_TYPE_CODE_MAP[$unit_type_code])) {
                    throw new Exception("Invalid unit type selection.");
                }
                if ($UNIT_TYPE_CODE_MAP[$unit_type_code] !== $unit_type) {
                    throw new Exception("Unit type mismatch with selected unit code.");
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
                // Server-side validation: ensure variation keys belong to selected unit type
                if (!empty($variations) && is_array($variations)) {
                    $allowed = get_unit_variation_options($unit_type_code);
                    foreach ($variations as $vk) {
                        $parts = explode(':', (string)$vk, 2);
                        $attr = $parts[0] ?? '';
                        $val = $parts[1] ?? '';
                        if ($attr === '' || $val === '' || !isset($allowed[$attr]) || !in_array($val, $allowed[$attr], true)) {
                            throw new Exception("Invalid variation '$vk' for unit type code '$unit_type_code'.");
                        }
                    }
                }
                
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
                                // Validate each selected option against unit type
                                $allowed = get_unit_variation_options($unit_type_code);
                                foreach ($values as $value) {
                                    if (!isset($allowed[$attr]) || !in_array($value, $allowed[$attr], true)) {
                                        throw new Exception("Invalid variation option '$attr:$value' for unit type code '$unit_type_code'.");
                                    }
                                }
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
                
                // Sync to inventory if needed
                try {
                    if (!$inventory->skuExists($sku)) {
                        $inventory->sku = $sku;
                        $inventory->name = $name;
                        $inventory->description = $description;
                        $inventory->category = $category;
                        $inventory->unit_price = 0;
                        $inventory->quantity = 0;
                        $inventory->reorder_threshold = $reorder_threshold;
                        $inventory->location = $location;
                        $inventory->supplier_id = $supplier_id;
                        
                        if ($inventory->createForSupplier($supplier_id)) {
                            $inv_id = (int)$db->lastInsertId();
                            // Mirror variations WITHOUT stocks - stocks will be added when orders are completed
                            $vars = $spVariation->getByProduct($product_id);
                            foreach ($vars as $vr) {
                                $vt = $vr['unit_type'] ?? $unit_type;
                                $pr = isset($vr['unit_price']) ? (float)$vr['unit_price'] : null;
                                $st = 0; // Don't copy stock - stocks will be added when orders from supplier_details.php are marked as Completed
                                $invVariation->createVariant($inv_id, $vr['variation'], $vt, $st, $pr);
                            }
                        }
                    }
                } catch (Throwable $e) {
                    // Log but don't fail
                    error_log("Inventory sync warning: " . $e->getMessage());
                }
                
                $db->commit();
                $message = "Product added successfully!";
                $messageType = "success";
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
        
        // Edit product
        elseif ($action === 'edit') {
            try {
                $product_id = (int)($_POST['product_id'] ?? 0);
                if (!$catalog->belongsToSupplier($product_id, $supplier_id)) {
                    throw new Exception("Product not found or access denied.");
                }
                
                // Get current product data for sync
                $currentProduct = $db->prepare("SELECT sku, name, description, category, location, reorder_threshold, unit_type FROM supplier_catalog WHERE id = :id AND supplier_id = :sid");
                $currentProduct->execute([':id' => $product_id, ':sid' => $supplier_id]);
                $current = $currentProduct->fetch(PDO::FETCH_ASSOC);
                if (!$current) {
                    throw new Exception("Product not found.");
                }
                $sku = $current['sku'];
                
                // Validate posted unit type code and normalize (allow change with validation)
                $unit_type_code = $_POST['unit_type_code'] ?? '';
                if (empty($unit_type_code) || !isset($UNIT_TYPE_CODE_MAP[$unit_type_code])) {
                    throw new Exception("Invalid unit type selection.");
                }
                $normalizedPosted = $UNIT_TYPE_CODE_MAP[$unit_type_code];
                
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $category = trim($_POST['category'] ?? '');
                $location = trim($_POST['location'] ?? '');
                
                if (empty($name)) {
                    throw new Exception("Name is required.");
                }
                
                $db->beginTransaction();
                
                $catalog->id = $product_id;
                $catalog->sku = $sku;
                $catalog->name = $name;
                $catalog->description = $description;
                $catalog->category = $category;
                $catalog->location = $location;
                $catalog->unit_type = $normalizedPosted;
                
                if (!$catalog->updateBySupplier($supplier_id)) {
                    throw new Exception("Failed to update product.");
                }
                
                // Update supplier variations only (do NOT sync to inventory)
                // Inventory edits are independent and should not be overridden by supplier edits
                $variation_prices = $_POST['variation_prices'] ?? [];
                $variation_stocks = $_POST['variation_stocks'] ?? [];
                $variations = $_POST['variations'] ?? [];
                $delete_variations = $_POST['delete_variations'] ?? [];
                
                // Server-side validation: ensure variation keys belong to the unit type
                if (!empty($variations) && is_array($variations)) {
                    $allowed = null;
                    // Resolve unit_type_code is already validated; use it to get allowed options
                    $allowed = get_unit_variation_options($unit_type_code);
                    foreach ($variations as $vk) {
                        $parts = explode(':', (string)$vk, 2);
                        $attr = $parts[0] ?? '';
                        $val = $parts[1] ?? '';
                        if ($attr === '' || $val === '' || !isset($allowed[$attr]) || !in_array($val, $allowed[$attr], true)) {
                            throw new Exception("Invalid variation '$vk' for unit type code '$unit_type_code'.");
                        }
                    }
                }
                
                if (!empty($variations) && is_array($variations)) {
                    $allowed = get_unit_variation_options($unit_type_code);
                    $existingRows = $spVariation->getByProduct($product_id);
                    $existingKeys = [];
                    foreach ($existingRows as $er) { $existingKeys[] = (string)($er['variation'] ?? ''); }
                    foreach ($variations as $varKey) {
                        $partsV = explode(':', (string)$varKey, 2);
                        $attrV = $partsV[0] ?? '';
                        $valV = $partsV[1] ?? '';
                        if ($attrV === '' || $valV === '' || !isset($allowed[$attrV]) || !in_array($valV, $allowed[$attrV], true)) {
                            throw new Exception("Invalid variation '$varKey' for unit type code '$unit_type_code'.");
                        }
                        $rawPrice = $variation_prices[$varKey] ?? null;
                        $rawStock = $variation_stocks[$varKey] ?? 0;
                        $price = ($rawPrice === null || $rawPrice === '') ? null : (float)$rawPrice;
                        if ($price !== null && (!is_numeric($rawPrice) || $price < 0)) {
                            throw new Exception("Invalid price for variation '$varKey'.");
                        }
                        $stock = (int)$rawStock;
                        if (!is_numeric($rawStock) || $stock < 0) {
                            throw new Exception("Invalid stock for variation '$varKey'.");
                        }
                        if (!in_array($varKey, $existingKeys, true)) {
                            $spVariation->createVariant($product_id, $varKey, $normalizedPosted, $stock, $price);
                        } else {
                            $spVariation->updateStock($product_id, $varKey, $current['unit_type'], $stock);
                            if ($price !== null) {
                                $spVariation->updatePrice($product_id, $varKey, $current['unit_type'], $price);
                            }
                        }
                    }
                }

                if (!empty($delete_variations) && is_array($delete_variations)) {
                    $spVariation->deleteVariantsBulk($product_id, array_values($delete_variations));
                }

                $totalStock = 0;
                $minPrice = null;
                foreach ($spVariation->getByProduct($product_id) as $var) {
                    $totalStock += (int)($var['stock'] ?? 0);
                    $vp = $var['unit_price'] ?? null;
                    if ($vp !== null && is_numeric($vp)) {
                        $fvp = (float)$vp;
                        if ($fvp >= 0 && ($minPrice === null || $fvp < $minPrice)) { $minPrice = $fvp; }
                    }
                }
                $upQty = $db->prepare("UPDATE supplier_catalog SET supplier_quantity = :qty, unit_price = :up WHERE id = :id AND supplier_id = :sid");
                $upQty->execute([':qty' => $totalStock, ':up' => ($minPrice === null ? 0 : $minPrice), ':id' => $product_id, ':sid' => $supplier_id]);
                
                // Note: Edits from supplier/products.php do NOT sync to inventory
                // All edits in admin/inventory.php will reflect in admin/supplier_details.php
                
                $db->commit();
                $message = "Product updated successfully!";
                $messageType = "success";
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                $message = htmlspecialchars($e->getMessage());
                $messageType = "danger";
            }
        }
        
        // Delete product
        elseif ($action === 'delete') {
            try {
                $product_id = (int)($_POST['product_id'] ?? 0);
                if (!$catalog->belongsToSupplier($product_id, $supplier_id)) {
                    throw new Exception("Product not found or access denied.");
                }
                
                $db->beginTransaction();
                
                // Get SKU before soft deleting to mark inventory as unavailable
                $skuStmt = $db->prepare("SELECT sku FROM supplier_catalog WHERE id = :id AND supplier_id = :sid");
                $skuStmt->execute([':id' => $product_id, ':sid' => $supplier_id]);
                $skuData = $skuStmt->fetch(PDO::FETCH_ASSOC);
                $sku = $skuData['sku'] ?? '';
                
                // Soft delete in supplier_catalog
                $catalog->id = $product_id;
                if (!$catalog->softDeleteBySupplier($supplier_id)) {
                    throw new Exception("Failed to delete product.");
                }
                $stmtDelSpVars = $db->prepare("DELETE FROM supplier_product_variations WHERE product_id = :pid");
                $stmtDelSpVars->execute([':pid' => $product_id]);
                
                // Mark corresponding inventory items as unavailable (soft delete in inventory)
                // This ensures the product is marked as unavailable in admin/inventory.php and admin/supplier_details.php
                if ($sku !== '') {
                    try {
                        // Add is_deleted column if it doesn't exist in inventory
                        try {
                            $checkCol = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory' AND COLUMN_NAME = 'is_deleted'")->fetchColumn();
                            if ($checkCol == 0) {
                                $db->exec("ALTER TABLE inventory ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0, ADD INDEX idx_is_deleted (is_deleted)");
                            }
                        } catch (Exception $e) {}
                        
                        // Soft delete inventory items by SKU and supplier_id
                        $invSoftDel = $db->prepare("UPDATE inventory SET is_deleted = 1 WHERE sku = :sku AND supplier_id = :sid");
                        $invSoftDel->execute([':sku' => $sku, ':sid' => $supplier_id]);
                        $invIdsStmt = $db->prepare("SELECT id FROM inventory WHERE sku = :sku AND supplier_id = :sid");
                        $invIdsStmt->execute([':sku' => $sku, ':sid' => $supplier_id]);
                        $invIds = $invIdsStmt->fetchAll(PDO::FETCH_COLUMN);
                        if (!empty($invIds)) {
                            $ph = implode(',', array_fill(0, count($invIds), '?'));
                            $delInvVars = $db->prepare("DELETE FROM inventory_variations WHERE inventory_id IN ($ph)");
                            $delInvVars->execute(array_map('intval', $invIds));
                        }
                    } catch (Throwable $e) {
                        // Log but don't fail - inventory soft delete is secondary
                        error_log("Warning: Could not mark inventory as unavailable for SKU {$sku}: " . $e->getMessage());
                    }
                }
                
                $db->commit();
                $message = "Product marked as unavailable in supplier catalog and inventory.";
                $messageType = "success";
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
                    <div class="card-header">
                        <h5><i class="bi bi-list-ul me-2"></i>All Products</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="productsTable">
                                <thead>
                                    <tr>
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
                                                    <?php 
                                                        $vks = []; $vps = []; $vss = [];
                                                        if (!empty($product['variations'])) {
                                                            foreach ($product['variations'] as $v) {
                                                                $key = htmlspecialchars($v['variation'] ?? '', ENT_QUOTES);
                                                                $price = isset($v['unit_price']) ? (float)$v['unit_price'] : '';
                                                                $stock = isset($v['stock']) ? (int)$v['stock'] : 0;
                                                                if ($key !== '') {
                                                                    $vks[] = $key;
                                                                    $vps[] = $key . '=' . $price;
                                                                    $vss[] = $key . '=' . $stock;
                                                                }
                                                            }
                                                        }
                                                        $varKeysStr = implode('|', $vks);
                                                        $varPricesStr = implode('|', $vps);
                                                        $varStocksStr = implode('|', $vss);
                                                    ?>
                                                    <button type="button" class="btn btn-sm btn-primary me-2 edit-btn"
                                                        data-bs-toggle="modal" data-bs-target="#editProductModal"
                                                        data-id="<?php echo (int)($product['id'] ?? 0); ?>"
                                                        data-name="<?php echo htmlspecialchars($product['name'] ?? '', ENT_QUOTES); ?>"
                                                        data-unit_type="<?php echo htmlspecialchars($product['unit_type'] ?? 'per piece', ENT_QUOTES); ?>"
                                                        data-category="<?php echo htmlspecialchars($product['category'] ?? '', ENT_QUOTES); ?>"
                                                        data-location="<?php echo htmlspecialchars($product['location'] ?? '', ENT_QUOTES); ?>"
                                                        data-description="<?php echo htmlspecialchars($product['description'] ?? '', ENT_QUOTES); ?>"
                                                        data-variation_keys="<?php echo htmlspecialchars($varKeysStr, ENT_QUOTES); ?>"
                                                        data-variation_prices="<?php echo htmlspecialchars($varPricesStr, ENT_QUOTES); ?>"
                                                        data-variation_stocks="<?php echo htmlspecialchars($varStocksStr, ENT_QUOTES); ?>">
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
    
    <!-- Edit Product Modal -->
    <div class="modal fade" id="editProductModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editProductModalLabel">
                        <i class="bi bi-pencil-square me-2"></i>Edit Product
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editProductForm" class="supplier-form" aria-labelledby="editProductModalLabel" novalidate>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="product_id" id="editProductId" value="">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div id="editFormStatus" class="visually-hidden" aria-live="polite"></div>
                        <div id="editLoading" class="d-none mb-2"><span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Loading…</div>
                        <div class="row g-3">
                            <div class="col-md-6 mb-3">
                                <label for="editName" class="form-label">Product Name *</label>
                                <input type="text" class="form-control" id="editName" name="name" required>
                                <div class="invalid-feedback">Product name is required.</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="editSku" class="form-label">SKU * <span class="badge bg-secondary">Read-only</span></label>
                                <input type="text" class="form-control" id="editSku" name="sku" pattern="[A-Za-z0-9_-]{2,}" required readonly>
                                <div class="invalid-feedback">SKU is required.</div>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6 mb-3">
                                <label for="editCategory" class="form-label">Category *</label>
                                <select class="form-select" id="editCategory" name="category" required>
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
                        </div>
                        <div class="row g-3">
                            <div class="col-md-12 mb-3">
                                <div class="d-flex justify-content-between align-items-center flex-wrap mb-1">
                                    <label class="form-label mb-0">Unit Type</label>
                                    <div id="editFormUnitTypeManageGroup" class="d-flex gap-1 mt-2 mt-sm-0">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="editBtnUnitTypeEdit"><i class="bi bi-pencil me-1"></i>Edit</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="editBtnUnitTypeAdd"><i class="bi bi-plus-lg me-1"></i>Add</button>
                                        <button type="button" class="btn btn-outline-danger btn-sm" id="editBtnUnitTypeDelete"><i class="bi bi-trash me-1"></i>Delete</button>
                                    </div>
                                </div>
                                <div id="editUnitTypeRadios" class="row g-2"></div>
                                <div class="d-flex align-items-center mt-2">
                                    <small class="text-muted me-2">Select the unit type; related variations will be shown below.</small>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label mb-0">Variation Attributes</label>
                            <div id="editFormVariationManageGroup" class="d-flex gap-1">
                                <button type="button" class="btn btn-outline-primary btn-sm" id="editBtnVariationAdd"><i class="bi bi-plus-lg me-1"></i>Add</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="editBtnVariationEdit"><i class="bi bi-pencil me-1"></i>Edit</button>
                                <button type="button" class="btn btn-outline-danger btn-sm" id="editBtnVariationDelete"><i class="bi bi-trash me-1"></i>Delete</button>
                            </div>
                        </div>
                        <div id="editUnitVariationContainer" class="border rounded p-2 mb-3"></div>
                        <div class="row g-3">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Pricing & Stock</label>
                                <div class="alert alert-info p-2 mb-2">Select variations; set price and stock per selection.</div>
                                <div id="editVariationPriceContainer" class="row g-2"></div>
                                <small class="text-muted">Total quantity is the sum of selected variation stocks.</small>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-12 mb-3">
                                <label for="editLocation" class="form-label">Location</label>
                                <input type="text" class="form-control" id="editLocation" name="location" placeholder="e.g., Warehouse A, Shelf 1">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="editDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="editDescription" name="description" rows="3"></textarea>
                        </div>
                        <input type="hidden" id="edit_quantity" name="quantity" value="0">
                        <input type="hidden" id="edit_reorder_threshold" name="reorder_threshold" value="0">
                        <input type="hidden" id="edit_unit_price" name="unit_price" value="0">
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
        function renderUnitVariationsEdit(unitCode) {
            const container = document.getElementById('editUnitVariationContainer');
            if (!container) return;
            const priceContainer = document.getElementById('editVariationPriceContainer');
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
            const originalAttrs = Object.keys(opts);
            const prioritized = [];
            if (originalAttrs.includes('Brand')) prioritized.push('Brand');
            if (originalAttrs.includes('Type')) prioritized.push('Type');
            const others = originalAttrs.filter(a => !prioritized.includes(a));
            const displayAttrs = ['Name', ...prioritized, ...others];
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
                    const nameInput = document.getElementById('editName');
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
            container.querySelectorAll('input[type="checkbox"][name^="variation_attrs["]').forEach(cb => {
                cb.addEventListener('change', (e) => {
                    onVariationAttrChangeEdit(e);
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
                    const lab = document.querySelector(`label[for="${cb.id}"]`);
                    if (lab) {
                        let badge = lab.querySelector('.price-badge');
                        if (!badge) { badge = document.createElement('span'); badge.className = 'badge bg-primary ms-1 price-badge'; lab.appendChild(badge); }
                        const pv = parseFloat(existing.value || '0');
                        badge.textContent = `₱${isNaN(pv) ? '0.00' : pv.toFixed(2)}`;
                        existing.addEventListener('input', () => {
                            const nv = parseFloat(existing.value || '0');
                            badge.textContent = `₱${isNaN(nv) ? '0.00' : nv.toFixed(2)}`;
                        });
                    }
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
                        const p = container.querySelector(`input.var-price[data-key="${CSS.escape(key)}"]`);
                        const lab = document.querySelector(`label[for="${cb.id}"]`);
                        if (lab) {
                            let badge = lab.querySelector('.price-badge');
                            if (!badge) { badge = document.createElement('span'); badge.className = 'badge bg-primary ms-1 price-badge'; lab.appendChild(badge); }
                            const pv = parseFloat(p?.value || '0');
                            badge.textContent = `₱${isNaN(pv) ? '0.00' : pv.toFixed(2)}`;
                            if (p) {
                                p.addEventListener('input', () => {
                                    const nv = parseFloat(p.value || '0');
                                    badge.textContent = `₱${isNaN(nv) ? '0.00' : nv.toFixed(2)}`;
                                });
                            }
                        }
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
                const lab = document.querySelector(`label[for="${cb.id}"]`);
                if (lab) { const badge = lab.querySelector('.price-badge'); if (badge) badge.remove(); }
            }
        }
        function onVariationAttrChangeEdit(evt) {
            const cb = evt.target;
            if (!cb || cb.type !== 'checkbox') return;
            const container = document.getElementById('editVariationPriceContainer');
            if (!container) return;
            const name = cb.getAttribute('name') || '';
            const m = name.match(/^variation_attrs\[(.+)\]\[\]$/);
            if (!m) return;
            const attr = m[1];
            const val = cb.value;
            const key = `${attr}:${val}`;
            if (cb.checked) {
                const existing = container.querySelector(`input.var-price[data-key="${CSS.escape(key)}"]`);
                if (existing) {
                    existing.disabled = false;
                    existing.closest('.col-md-6')?.classList.remove('d-none');
                    const stockInput = existing.closest('.col-md-6')?.querySelector(`input.var-stock[data-key="${CSS.escape(key)}"]`);
                    if (stockInput) stockInput.disabled = false;
                    if (window.__editPMap && window.__editPMap[key] !== undefined) existing.value = window.__editPMap[key];
                    if (stockInput && window.__editSMap && window.__editSMap[key] !== undefined) stockInput.value = window.__editSMap[key];
                    const lab = document.querySelector(`label[for="${cb.id}"]`);
                    if (lab) {
                        let badge = lab.querySelector('.price-badge');
                        if (!badge) { badge = document.createElement('span'); badge.className = 'badge bg-primary ms-1 price-badge'; lab.appendChild(badge); }
                        const pv = parseFloat(existing.value || '0');
                        badge.textContent = `₱${isNaN(pv) ? '0.00' : pv.toFixed(2)}`;
                        existing.addEventListener('input', () => {
                            const nv = parseFloat(existing.value || '0');
                            badge.textContent = `₱${isNaN(nv) ? '0.00' : nv.toFixed(2)}`;
                        });
                    }
                    return;
                }
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                    body: `action=get_price_input&key=${encodeURIComponent(key)}&csrf_token=${CSRF_TOKEN}`
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.html) {
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = data.html;
                        const el = tempDiv.firstElementChild;
                        container.appendChild(el);
                        const p = container.querySelector(`input.var-price[data-key="${CSS.escape(key)}"]`);
                        const s = container.querySelector(`input.var-stock[data-key="${CSS.escape(key)}"]`);
                        if (p && window.__editPMap && window.__editPMap[key] !== undefined) p.value = window.__editPMap[key];
                        if (s && window.__editSMap && window.__editSMap[key] !== undefined) s.value = window.__editSMap[key];
                        const lab = document.querySelector(`label[for="${cb.id}"]`);
                        if (lab) {
                            let badge = lab.querySelector('.price-badge');
                            if (!badge) { badge = document.createElement('span'); badge.className = 'badge bg-primary ms-1 price-badge'; lab.appendChild(badge); }
                            const pv = parseFloat(p?.value || '0');
                            badge.textContent = `₱${isNaN(pv) ? '0.00' : pv.toFixed(2)}`;
                            if (p) {
                                p.addEventListener('input', () => {
                                    const nv = parseFloat(p.value || '0');
                                    badge.textContent = `₱${isNaN(nv) ? '0.00' : nv.toFixed(2)}`;
                                });
                            }
                        }
                    }
                });
            } else {
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
                const lab = document.querySelector(`label[for="${cb.id}"]`);
                if (lab) { const badge = lab.querySelector('.price-badge'); if (badge) badge.remove(); }
            }
        }
        
        // Unit type management functions
        function normalizedFromName(name) {
            return 'per ' + (name || '').trim().toLowerCase();
        }
        
        function setUnitManageMode(mode) {
            const modal = document.getElementById('unitTypeManageModal');
            if (!modal) return;
            const addSec = modal.querySelector('#unitManageAddSection');
            const editSec = modal.querySelector('#unitManageEditSection');
            const delSec = modal.querySelector('#unitManageDeleteSection');
            if (addSec) addSec.classList.toggle('d-none', mode !== 'add');
            if (editSec) editSec.classList.toggle('d-none', mode !== 'edit');
            if (delSec) delSec.classList.toggle('d-none', mode !== 'delete');
            const title = modal.querySelector('#unitTypeManageLabel');
            if (title) {
                if (mode === 'add') title.textContent = 'Add Unit Type';
                else if (mode === 'edit') title.textContent = 'Edit Unit Type';
                else if (mode === 'delete') title.textContent = 'Delete Unit Type';
            }
            const saveBtn = modal.querySelector('#unitManageSaveBtn');
            const delBtn = modal.querySelector('#unitManageDeleteBtn');
            if (saveBtn) saveBtn.classList.toggle('d-none', mode === 'delete');
            if (delBtn) delBtn.classList.toggle('d-none', mode !== 'delete');
            const saveText = modal.querySelector('#unitManageSaveText');
            if (saveText) saveText.textContent = (mode === 'add') ? 'Add' : 'Save';
        }

        function toggleManageLoading(show) {
            const modal = document.getElementById('unitTypeManageModal');
            if (!modal) return;
            const spinnerSave = modal.querySelector('#unitManageLoading');
            const spinnerDel = modal.querySelector('#unitManageDeleteLoading');
            const body = modal.querySelector('.modal-body');
            if (spinnerSave) spinnerSave.classList.toggle('d-none', !show);
            if (spinnerDel) spinnerDel.classList.toggle('d-none', !show);
            if (body) body.classList.toggle('opacity-50', !!show);
            const saveBtn = modal.querySelector('#unitManageSaveBtn');
            const delBtn = modal.querySelector('#unitManageDeleteBtn');
            if (saveBtn) saveBtn.disabled = !!show;
            if (delBtn) delBtn.disabled = !!show;
        }

        async function openUnitManageModal(mode) {
            const modalEl = document.getElementById('unitTypeManageModal');
            if (!modalEl) return;
            setUnitManageMode(mode);
            const m = new bootstrap.Modal(modalEl);
            const currentUnitCode = (() => {
                const checkedAdd = document.querySelector('#unitTypeRadios input[type="radio"]:checked');
                const checkedEdit = document.querySelector('#editUnitTypeRadios input[type="radio"]:checked');
                const el = checkedEdit || checkedAdd;
                return el ? el.value : '';
            })();
            const unitName = UNIT_TYPE_MAP[currentUnitCode] || '';
            const selectedBadge = modalEl.querySelector('#selectedUnitBadge');
            if (selectedBadge) selectedBadge.textContent = `Selected: ${currentUnitCode || '—'}`;
            m.show();

            const saveBtn = modalEl.querySelector('#unitManageSaveBtn');
            const deleteBtn = modalEl.querySelector('#unitManageDeleteBtn');
            const inputAddCode = modalEl.querySelector('#unitAddCode');
            const inputAddName = modalEl.querySelector('#unitAddName');
            const inputEditName = modalEl.querySelector('#unitEditName');

            const resolveUnitByCode = async (code) => {
                const resp = await fetch('../api/unit_types.php');
                const data = await resp.json();
                if (!Array.isArray(data)) return null;
                return data.find(u => (u.code || '') === code) || null;
            };

            const resetHandlers = () => {
                if (saveBtn) saveBtn.onclick = null;
                if (deleteBtn) deleteBtn.onclick = null;
            };
            resetHandlers();

            if (mode === 'add' && saveBtn) {
                saveBtn.onclick = async () => {
                    const code = (inputAddCode?.value || '').trim();
                    const name = (inputAddName?.value || '').trim();
                    if (!code || !name) return;
                    toggleManageLoading(true);
                    try {
                        const resp = await fetch('../api/unit_types.php', {
                            method: 'POST', headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ code, name })
                        });
                        const ok = resp.status >= 200 && resp.status < 300;
                        if (ok) {
                            const listResp = await fetch('../api/unit_types.php');
                            const list = await listResp.json();
                            const map = {};
                            list.forEach(u => { map[u.code] = normalizedFromName(u.name); });
                            Object.assign(UNIT_TYPE_MAP, map);
                            renderUnitTypesInto('unitTypeRadios', false);
                            renderUnitTypesInto('editUnitTypeRadios', true);
                            m.hide();
                        }
                    } finally { toggleManageLoading(false); }
                };
            }
            if (mode === 'edit' && saveBtn) {
                if (inputEditName && unitName) inputEditName.value = displayNameFromNormalized(unitName);
                saveBtn.onclick = async () => {
                    const target = await resolveUnitByCode(currentUnitCode);
                    const name = (inputEditName?.value || '').trim();
                    if (!target || !name) return;
                    toggleManageLoading(true);
                    try {
                        await fetch(`../api/unit_types.php?id=${encodeURIComponent(target.id)}`, {
                            method: 'PUT', headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ name })
                        });
                        const listResp = await fetch('../api/unit_types.php');
                        const list = await listResp.json();
                        const map = {};
                        list.forEach(u => { map[u.code] = normalizedFromName(u.name); });
                        Object.assign(UNIT_TYPE_MAP, map);
                        renderUnitTypesInto('unitTypeRadios', false);
                        renderUnitTypesInto('editUnitTypeRadios', true);
                        m.hide();
                    } finally { toggleManageLoading(false); }
                };
            }
            if (mode === 'delete' && deleteBtn) {
                deleteBtn.onclick = async () => {
                    const target = await resolveUnitByCode(currentUnitCode);
                    if (!target) return;
                    toggleManageLoading(true);
                    try {
                        await fetch(`../api/unit_types.php?id=${encodeURIComponent(target.id)}`, { method: 'DELETE' });
                        const listResp = await fetch('../api/unit_types.php');
                        const list = await listResp.json();
                        const map = {};
                        list.forEach(u => { map[u.code] = normalizedFromName(u.name); });
                        Object.assign(UNIT_TYPE_MAP, map);
                        renderUnitTypesInto('unitTypeRadios', false);
                        renderUnitTypesInto('editUnitTypeRadios', true);
                        m.hide();
                    } finally { toggleManageLoading(false); }
                };
            }
        }

        async function openVariationManageModal(mode) {
            const modalEl = document.getElementById('variationManageModal');
            if (!modalEl) return;
            const m = new bootstrap.Modal(modalEl);
            const currentUnitCode = (() => {
                const checkedAdd = document.querySelector('#unitTypeRadios input[type="radio"]:checked');
                const checkedEdit = document.querySelector('#editUnitTypeRadios input[type="radio"]:checked');
                const el = checkedEdit || checkedAdd;
                return el ? el.value : '';
            })();
            const unitName = UNIT_TYPE_MAP[currentUnitCode] || '';
            const codeBadge = modalEl.querySelector('#variationManageCurrentCode');
            const nameBadge = modalEl.querySelector('#variationManageCurrentName');
            if (codeBadge) codeBadge.textContent = currentUnitCode || '—';
            if (nameBadge) nameBadge.textContent = displayNameFromNormalized(unitName) || '—';
            modalEl.querySelectorAll('[data-mode]').forEach(el => {
                const mAttr = el.getAttribute('data-mode');
                el.classList.toggle('d-none', mAttr !== mode);
            });
            m.show();

            const addForm = modalEl.querySelector('#variationAddForm');
            const editForm = modalEl.querySelector('#variationEditForm');
            const deleteForm = modalEl.querySelector('#variationDeleteForm');

            const loadAttributes = async () => {
                const url = `../api/attributes.php?action=attributes_for_unit&unit_type_code=${encodeURIComponent(currentUnitCode)}`;
                const resp = await fetch(url);
                const data = await resp.json();
                return Array.isArray(data?.attributes) ? data.attributes : [];
            };
            const loadOptions = async (attr) => {
                const url = `../api/attributes.php?action=options_for_unit_attribute&unit_type_code=${encodeURIComponent(currentUnitCode)}&attribute=${encodeURIComponent(attr)}`;
                const resp = await fetch(url);
                const data = await resp.json();
                return Array.isArray(data?.options) ? data.options : [];
            };

            if (mode === 'add' && addForm) {
                const attrSelect = addForm.querySelector('[name="attribute"]');
                const valueInput = addForm.querySelector('[name="value"]');
                const submitBtn = addForm.querySelector('button[type="submit"]');
                addForm.onsubmit = async (e) => {
                    e.preventDefault();
                    const attribute = (attrSelect?.value || '').trim();
                    const value = (valueInput?.value || '').trim();
                    if (!attribute || !value) return;
                    submitBtn.disabled = true;
                    try {
                        await fetch('../api/attributes.php', {
                            method: 'POST', headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'add_attribute_option', unit_type_code: currentUnitCode, attribute, value })
                        });
                        await hydrateUnitVariations(currentUnitCode, true);
                        renderUnitVariations(currentUnitCode);
                        renderUnitVariationsEdit(currentUnitCode);
                        m.hide();
                    } finally { submitBtn.disabled = false; }
                };
                const attrs = await loadAttributes();
                if (attrSelect) {
                    attrSelect.innerHTML = '';
                    attrs.forEach(a => { const o = document.createElement('option'); o.value = a; o.textContent = a; attrSelect.appendChild(o); });
                }
            }
            if (mode === 'edit' && editForm) {
                const kindSelect = editForm.querySelector('[name="kind"]');
                const attrSelect = editForm.querySelector('[name="attribute"]');
                const currentInput = editForm.querySelector('[name="current"]');
                const newInput = editForm.querySelector('[name="new"]');
                const submitBtn = editForm.querySelector('button[type="submit"]');
                const attrs = await loadAttributes();
                if (attrSelect) {
                    attrSelect.innerHTML = '';
                    attrs.forEach(a => { const o = document.createElement('option'); o.value = a; o.textContent = a; attrSelect.appendChild(o); });
                }
                kindSelect?.addEventListener('change', async () => {
                    const kind = kindSelect.value;
                    if (kind === 'option') {
                        const opts = await loadOptions(attrSelect.value);
                        currentInput.setAttribute('list', 'optsList');
                        let dl = editForm.querySelector('#optsList');
                        if (!dl) { dl = document.createElement('datalist'); dl.id = 'optsList'; editForm.appendChild(dl); }
                        dl.innerHTML = '';
                        opts.forEach(v => { const o = document.createElement('option'); o.value = v; dl.appendChild(o); });
                    } else {
                        currentInput.removeAttribute('list');
                    }
                });
                editForm.onsubmit = async (e) => {
                    e.preventDefault();
                    const kind = (kindSelect?.value || 'attribute');
                    const attribute = (attrSelect?.value || '').trim();
                    const current = (currentInput?.value || '').trim();
                    const next = (newInput?.value || '').trim();
                    if (kind === 'attribute') {
                        await fetch('../api/attributes.php', {
                            method: 'PUT', headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'rename_attribute', unit_type_code: currentUnitCode, current, new: next })
                        });
                    } else {
                        await fetch('../api/attributes.php', {
                            method: 'PUT', headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'rename_option', unit_type_code: currentUnitCode, attribute, current, new: next })
                        });
                    }
                    await hydrateUnitVariations(currentUnitCode, true);
                    renderUnitVariations(currentUnitCode);
                    renderUnitVariationsEdit(currentUnitCode);
                    m.hide();
                };
            }
            if (mode === 'delete' && deleteForm) {
                const attrSelect = deleteForm.querySelector('[name="attribute"]');
                const optionSelect = deleteForm.querySelector('[name="value"]');
                const submitBtn = deleteForm.querySelector('button[type="submit"]');
                const attrs = await loadAttributes();
                if (attrSelect) {
                    attrSelect.innerHTML = '';
                    attrs.forEach(a => { const o = document.createElement('option'); o.value = a; o.textContent = a; attrSelect.appendChild(o); });
                    attrSelect.onchange = async () => {
                        const opts = await loadOptions(attrSelect.value);
                        optionSelect.innerHTML = '';
                        opts.forEach(v => { const o = document.createElement('option'); o.value = v; o.textContent = v; optionSelect.appendChild(o); });
                    };
                    attrSelect.onchange();
                }
                deleteForm.onsubmit = async (e) => {
                    e.preventDefault();
                    const attribute = (attrSelect?.value || '').trim();
                    const value = (optionSelect?.value || '').trim();
                    if (!attribute || !value) return;
                    submitBtn.disabled = true;
                    try {
                        await fetch('../api/attributes.php', {
                            method: 'DELETE', headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'delete_attribute_option', unit_type_code: currentUnitCode, attribute, value })
                        });
                        await hydrateUnitVariations(currentUnitCode, true);
                        renderUnitVariations(currentUnitCode);
                        renderUnitVariationsEdit(currentUnitCode);
                        m.hide();
                    } finally { submitBtn.disabled = false; }
                };
            }
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
                    hydratedUnitCodes.delete(code);
                    if (containerId === 'unitTypeRadios') {
                        await hydrateUnitVariations(code, true);
                        renderUnitVariations(code);
                    } else if (containerId === 'editUnitTypeRadios') {
                        await hydrateUnitVariations(code, true);
                        renderUnitVariationsEdit(code);
                    }
                });
            });
        }
        
        // Note: Full unit type and variation management functions are available in admin/inventory.php
        // For now, basic functionality is implemented. Full CRUD management can be added by copying
        // the openUnitManageModal, openVariationManageModal, and related functions from admin/inventory.php
        
        // Initialize on page load
        $(document).ready(function() {
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
                            { orderable: false, targets: -1 } // Disable sorting on Actions column
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
            
            const btnAdd = document.getElementById('btnUnitTypeAdd');
            const btnEdit = document.getElementById('btnUnitTypeEdit');
            const btnDel = document.getElementById('btnUnitTypeDelete');
            if (btnAdd) btnAdd.addEventListener('click', () => openUnitManageModal('add'));
            if (btnEdit) btnEdit.addEventListener('click', () => openUnitManageModal('edit'));
            if (btnDel) btnDel.addEventListener('click', () => openUnitManageModal('delete'));
            const eBtnAdd = document.getElementById('editBtnUnitTypeAdd');
            const eBtnEdit = document.getElementById('editBtnUnitTypeEdit');
            const eBtnDel = document.getElementById('editBtnUnitTypeDelete');
            if (eBtnAdd) eBtnAdd.addEventListener('click', () => openUnitManageModal('add'));
            if (eBtnEdit) eBtnEdit.addEventListener('click', () => openUnitManageModal('edit'));
            if (eBtnDel) eBtnDel.addEventListener('click', () => openUnitManageModal('delete'));
            
            const btnVariationAdd = document.getElementById('btnVariationAdd');
            const btnVariationEdit = document.getElementById('btnVariationEdit');
            const btnVariationDelete = document.getElementById('btnVariationDelete');
            if (btnVariationAdd) btnVariationAdd.addEventListener('click', () => openVariationManageModal('add'));
            if (btnVariationEdit) btnVariationEdit.addEventListener('click', () => openVariationManageModal('edit'));
            if (btnVariationDelete) btnVariationDelete.addEventListener('click', () => openVariationManageModal('delete'));
            const eVarAdd = document.getElementById('editBtnVariationAdd');
            const eVarEdit = document.getElementById('editBtnVariationEdit');
            const eVarDelete = document.getElementById('editBtnVariationDelete');
            if (eVarAdd) eVarAdd.addEventListener('click', () => openVariationManageModal('add'));
            if (eVarEdit) eVarEdit.addEventListener('click', () => openVariationManageModal('edit'));
            if (eVarDelete) eVarDelete.addEventListener('click', () => openVariationManageModal('delete'));
            
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
            const editModal = document.getElementById('editProductModal');
            if (editModal) {
                // Prevent re-render on modal shown to avoid losing state
                editModal.addEventListener('shown.bs.modal', function(){});
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

            $(document).on('click', '.edit-btn', async function() {
                var $btn = $(this);
                var pid = parseInt($btn.data('id') || 0);
                $('#editProductId').val(pid);
                $('#editFormStatus').addClass('visually-hidden').removeClass('alert alert-danger alert-info alert-success').text('');
                $('#editLoading').removeClass('d-none');
                $('#editName').val('');
                $('#editCategory').val('');
                $('#editLocation').val('');
                $('#editDescription').val('');
                document.getElementById('editVariationPriceContainer').innerHTML = '';
                document.getElementById('editUnitVariationContainer').innerHTML = '';
                await reloadUnitTypesFromDB();
                renderUnitTypesInto('editUnitTypeRadios', true);
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                    body: 'action=get_product&product_id=' + encodeURIComponent(pid) + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
                })
                .then(function(res){ return res.json(); })
                .then(async function(data){
                    if (!data || !data.success || !data.product) {
                        $('#editFormStatus').removeClass('visually-hidden').addClass('alert alert-danger').text((data && data.message) ? data.message : 'Failed to load product details.');
                        return;
                    }
                    var prod = data.product;
                    $('#editName').val((prod.name || '').toString());
                    $('#editSku').val((prod.sku || '').toString());
                    $('#editCategory').val((prod.category || '').toString());
                    $('#editLocation').val((prod.location || '').toString());
                    $('#editDescription').val((prod.description || '').toString());
                    var normalized = (prod.unit_type || 'per piece').toString().toLowerCase();
                    var selectedCode = null;
                    Object.keys(UNIT_TYPE_MAP).forEach(function(code){ if ((UNIT_TYPE_MAP[code] || '').toLowerCase() === normalized) { selectedCode = code; } });
                    if (selectedCode) {
                        var r = document.getElementById('editUnitCode_' + selectedCode);
                        if (r) { r.checked = true; }
                        await hydrateUnitVariations(selectedCode, true);
                        renderUnitVariationsEdit(selectedCode);
                    }
                    var pmap = {}; var smap = {}; var keys = [];
                    if (Array.isArray(data.variations)) {
                        data.variations.forEach(function(v){
                            var k = (v.variation || '').toString();
                            if (k) { keys.push(k); }
                            if (v.unit_price !== undefined && v.unit_price !== null) { pmap[k] = v.unit_price; }
                            if (v.stock !== undefined && v.stock !== null) { smap[k] = v.stock; }
                        });
                    }
                    window.__editPMap = pmap;
                    window.__editSMap = smap;
                    keys.forEach(function(k){
                        var parts = k.split(':');
                        if (parts.length === 2) {
                            var attr = parts[0];
                            var val = parts[1];
                            var sel = '#editUnitVariationContainer input[type="checkbox"][name="variation_attrs['+CSS.escape(attr)+'][]"][value="'+CSS.escape(val)+'"]';
                            var cb = document.querySelector(sel);
                            if (cb) { cb.checked = true; cb.dispatchEvent(new Event('change', { bubbles: true })); }
                        }
                    });
                })
                .catch(function(err){
                    $('#editFormStatus').removeClass('visually-hidden').addClass('alert alert-danger').text('Error loading product: ' + (err && err.message ? err.message : 'Unknown error'));
                })
                .finally(function(){
                    $('#editLoading').addClass('d-none');
                });
            });

            $('#editProductForm').on('submit', function(e){
                var unitSelected = document.querySelector('#editUnitTypeRadios input[type="radio"]:checked');
                if (!unitSelected) { e.preventDefault(); $('#editFormStatus').removeClass('visually-hidden').addClass('alert alert-danger').text('Please select a unit type.'); return; }
                var normalized = UNIT_TYPE_MAP[unitSelected.value] || 'per piece';
                var hidden = this.querySelector('input[name="unit_type"]');
                if (!hidden) { hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = 'unit_type'; this.appendChild(hidden); }
                hidden.value = normalized;
                this.querySelectorAll('input[type="hidden"][name="variations[]"]').forEach(function(n){ n.remove(); });
                var checked = document.querySelectorAll('#editUnitVariationContainer input[type="checkbox"][name^="variation_attrs["]:checked');
                checked.forEach(function(cb){
                    var name = cb.getAttribute('name') || '';
                    var m = name.match(/^variation_attrs\[(.+)\]\[\]$/);
                    if (!m) return;
                    var attr = m[1];
                    var val = cb.value;
                    var key = attr + ':' + val;
                    var vHidden = document.createElement('input');
                    vHidden.type = 'hidden';
                    vHidden.name = 'variations[]';
                    vHidden.value = key;
                    document.getElementById('editProductForm').appendChild(vHidden);
                });
                var trackHidden = this.querySelector('input[name="track_variations"]');
                if (checked.length > 0) { if (!trackHidden) { trackHidden = document.createElement('input'); trackHidden.type = 'hidden'; trackHidden.name = 'track_variations'; this.appendChild(trackHidden); } trackHidden.value = '1'; } else { if (trackHidden) trackHidden.remove(); }
                var invalid = false;
                document.querySelectorAll('#editVariationPriceContainer .col-md-6').forEach(function(col){
                    if (col.classList.contains('d-none')) return;
                    var p = col.querySelector('input.var-price');
                    var s = col.querySelector('input.var-stock');
                    var pv = p ? parseFloat(p.value || '0') : 0;
                    var sv = s ? parseInt(s.value || '0', 10) : 0;
                    if (isNaN(pv) || pv < 0 || isNaN(sv) || sv < 0) { invalid = true; }
                });
                if (invalid) { e.preventDefault(); $('#editFormStatus').removeClass('visually-hidden').addClass('alert alert-danger').text('Please provide valid price (>= 0) and stock (>= 0) for selected variations.'); return; }
                var $submitBtn = $(this).find('button[type="submit"]');
                if ($submitBtn.length) { $submitBtn.prop('disabled', true).prepend('<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>'); }
            });
        });
    </script>
</body>
</html>


