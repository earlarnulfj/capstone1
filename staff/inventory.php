<?php
include_once '../config/session.php';
require_once '../config/database.php';
require_once '../models/inventory.php';
require_once '../models/supplier.php';
require_once '../models/inventory_variation.php';

if (empty($_SESSION['staff']['user_id']) || ($_SESSION['staff']['role'] ?? null) !== 'staff') {
    header("Location: ../login.php");
    exit();
}

$db = (new Database())->getConnection();
$inventory = new Inventory($db);
$supplier = new Supplier($db);
$invVariation = new InventoryVariation($db);

// ====== Helper functions for variation display ======
// Format variation for display: "Brand:Adidas|Size:Large|Color:Red" -> "Adidas - Large - Red" (combine values with dashes)
function formatVariationForDisplay($variation) {
    if (empty($variation)) return '';
    if (strpos($variation, '|') === false && strpos($variation, ':') === false) return $variation;
    
    $parts = explode('|', $variation);
    $values = [];
    foreach ($parts as $part) {
        $av = explode(':', trim($part), 2);
        if (count($av) === 2) {
            $values[] = trim($av[1]);
        } else {
            $values[] = trim($part);
        }
    }
    return implode(' - ', $values);
}

// Format variation with labels: "Brand:Generic|Size:Large" -> "Brand: Generic | Size: Large"
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

// NOTE: Inventory now only shows products from completed orders
// Products from supplier/products.php are NOT synced to inventory
// They only appear in supplier/supplier_details.php

// IMPORTANT: Sync ALL completed orders to inventory table first
// This ensures all completed orders are stored in the inventory database
// The readAllFromCompletedOrders() method also calls sync, but we do it here explicitly
// to ensure data is always up-to-date when the page loads
try {
    // Sync all completed orders from both admin_orders and orders tables
    $inventory->syncAllCompletedOrdersToInventory();
} catch (Exception $e) {
    error_log("Error syncing completed orders to inventory on page load: " . $e->getMessage());
}

// Get all inventory items from completed orders only (not from supplier catalog)
// This method also calls syncAllCompletedOrdersToInventory() internally for double safety
$stmt = $inventory->readAllFromCompletedOrders();
// Compute ordered inventory IDs (non-cancelled orders)
$orderedInventoryIds = [];
$orderedStmt = $db->query("SELECT DISTINCT inventory_id FROM orders WHERE confirmation_status <> 'cancelled'");
while ($or = $orderedStmt->fetch(PDO::FETCH_ASSOC)) { $orderedInventoryIds[] = (int)$or['inventory_id']; }

// Get suppliers for view modal mapping
$suppliers = $supplier->readAll();
$suppliersArr = [];
while ($row = $suppliers->fetch(PDO::FETCH_ASSOC)) { $suppliersArr[] = $row; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory - Inventory & Stock Control System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
</head>
<body>
    <?php include_once 'includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include_once 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Inventory</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="printInventoryBtn">
                                <i class="bi bi-printer"></i> Print
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="exportCSVBtn">
                                <i class="bi bi-file-earmark-excel"></i> Export
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-table me-1"></i>
                        Inventory Items
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="inventoryTable" class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>SKU</th>
                                        <th>Name</th>
                                        <th>Category</th>
                                        <th>Reorder Threshold</th>
                                        <th>Unit Type</th>
                                        <th>Variations</th>
                                        <th>Stocks</th>
                                        <th>Supplier</th>
                                        <th>Location</th>
                                        <th>Source</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                                        <?php
                                        // Use inventory_id to get variations
                                        $inventory_id = isset($row['inventory_id']) ? (int)$row['inventory_id'] : (int)$row['id'];
                                        $__vars_row = $invVariation->getByInventory($inventory_id);
                                        $__names_row = [];
                                        $__price_map = [];
                                        foreach ($__vars_row as $__vr) { 
                                            $__names_row[] = $__vr['variation']; 
                                            $__price_map[$__vr['variation']] = isset($__vr['unit_price']) ? (float)$__vr['unit_price'] : null;
                                        }
                                        $__display_row = !empty($__names_row) ? implode(', ', array_slice($__names_row, 0, 6)) : '';
                                        $__unit_type_row = (!empty($__vars_row) && isset($__vars_row[0]['unit_type'])) ? $__vars_row[0]['unit_type'] : ($row['unit_type'] ?? 'Per Piece');
                                        $__price_map_json = htmlspecialchars(json_encode($__price_map), ENT_QUOTES);
                                        // Stocks map aligned with unit type (lower-case for lookup)
                                        $__stock_map = [];
                                        try { $__stock_map = $invVariation->getStocksMap($inventory_id, strtolower($__unit_type_row ?: 'per piece')); } catch (Throwable $e) { $__stock_map = []; }
                                        $__stock_map_json = htmlspecialchars(json_encode($__stock_map), ENT_QUOTES);
                                        
                                        // Get all completed orders for this inventory item (one entry per order)
                                        // CRITICAL: Only fetch from admin_orders table (admin/orders.php)
                                        // Do NOT fetch from orders table or any other source
                                        $__completed_orders_map = [];
                                        try {
                                            // Get ONLY completed orders from admin_orders table
                                            $ordersStmt = $db->prepare("SELECT id, variation, quantity, unit_type, order_date
                                                                      FROM admin_orders 
                                                                      WHERE inventory_id = ? 
                                                                      AND confirmation_status = 'completed' 
                                                                      ORDER BY order_date DESC");
                                            $ordersStmt->execute([$inventory_id]);
                                            while ($orderRow = $ordersStmt->fetch(PDO::FETCH_ASSOC)) {
                                                $orderId = 'admin_' . (int)$orderRow['id'];
                                                // Add ALL orders - each order ID is unique
                                                $__completed_orders_map[$orderId] = [
                                                    'id' => (int)$orderRow['id'],
                                                    'variation' => $orderRow['variation'] ?? '',
                                                    'quantity' => (int)$orderRow['quantity'],
                                                    'unit_type' => $orderRow['unit_type'] ?? 'per piece',
                                                    'order_date' => $orderRow['order_date'] ?? null
                                                ];
                                            }
                                            
                                            // DO NOT fetch from orders table - we only want data from admin/orders.php
                                            
                                            // Convert map to array
                                            $__completed_orders = array_values($__completed_orders_map);
                                        } catch (Exception $e) {
                                            error_log("Error getting completed orders from admin_orders: " . $e->getMessage());
                                            $__completed_orders = [];
                                        }
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['sku'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($row['name'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($row['category'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($row['reorder_threshold'] ?? 0); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($__unit_type_row); ?></span></td>
                                            <td>
                                                <?php if (!empty($__completed_orders)) { ?>
                                                    <?php foreach ($__completed_orders as $order) { 
                                                        $ordered_variation = isset($order['variation']) ? trim($order['variation']) : '';
                                                    ?>
                                                        <div class="mb-3">
                                                            <?php if (!empty($ordered_variation)): ?>
                                                                <div class="d-flex flex-column">
                                                                    <span class="badge bg-info mb-1 fs-6">
                                                                        <i class="bi bi-tag-fill me-1"></i><?= htmlspecialchars(formatVariationForDisplay($ordered_variation)) ?>
                                                                    </span>
                                                                    <small class="text-muted"><?= htmlspecialchars(formatVariationWithLabels($ordered_variation)) ?></small>
                                                                </div>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary">No Variation</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php } ?>
                                                <?php } else { echo '<span class="text-muted">—</span>'; } ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($__completed_orders)) { ?>
                                                    <?php foreach ($__completed_orders as $order) { 
                                                        $order_qty = (int)($order['quantity'] ?? 0);
                                                    ?>
                                                        <div class="mb-3">
                                                            <div class="d-flex flex-column align-items-start">
                                                                <span class="badge bg-primary fs-6 mb-1">
                                                                    <i class="bi bi-box-seam me-1"></i><strong><?= $order_qty ?></strong> pcs
                                                                </span>
                                                                <small class="text-muted">Quantity Ordered</small>
                                                            </div>
                                                        </div>
                                                    <?php } ?>
                                                <?php } else { echo '<span class="text-muted">—</span>'; } ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['supplier_name'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($row['location'] ?? ''); ?></td>
                                            <td>
                                                <span class="badge <?php echo ($row['source_type'] === 'Admin Created') ? 'bg-primary' : 'bg-success'; ?>">
                                                    <?php echo htmlspecialchars($row['source_type'] ?? 'From Completed Order'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-info view-btn" 
                                                    data-id="<?php echo $row['id']; ?>"
                                                    data-sku="<?php echo htmlspecialchars($row['sku'] ?? ''); ?>"
                                                    data-name="<?php echo htmlspecialchars($row['name'] ?? ''); ?>"
                                                    data-description="<?php echo htmlspecialchars($row['description'] ?? ''); ?>"
                                                    data-reorder="<?php echo $row['reorder_threshold'] ?? 0; ?>"
                                                    data-supplier="<?php echo $row['supplier_id'] ?? ''; ?>"
                                                    data-category="<?php echo htmlspecialchars($row['category'] ?? ''); ?>"
                                                    data-location="<?php echo htmlspecialchars($row['location'] ?? ''); ?>"
                                                    data-source="<?php echo htmlspecialchars($row['source_type'] ?? 'From Completed Order'); ?>"
                                                    data-variations="<?php echo htmlspecialchars($__display_row); ?>"
                                                    data-unit_type="<?php echo htmlspecialchars($__unit_type_row); ?>"
                                                    data-variation_prices="<?php echo $__price_map_json; ?>"
                                                    data-variation_stocks="<?php echo $__stock_map_json; ?>"
                                                    data-bs-toggle="modal" data-bs-target="#viewInventoryModal">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- View Inventory Modal -->
    <div class="modal fade" id="viewInventoryModal" tabindex="-1" aria-labelledby="viewInventoryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewInventoryModalLabel">View Inventory Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6"><p><strong>SKU:</strong> <span id="view-sku"></span></p></div>
                        <div class="col-md-6"><p><strong>Name:</strong> <span id="view-name"></span></p></div>
                    </div>
                    <div class="mb-3"><p><strong>Description:</strong> <span id="view-description"></span></p></div>
                    <div class="row mb-3">
                        <div class="col-md-4"><p><strong>Reorder Threshold:</strong> <span id="view-reorder"></span></p></div>
                        <div class="col-md-8">
                            <div class="row g-2 align-items-center">
                                <div class="col-md-6">
                                    <label class="form-label mb-0">Variation</label>
                                    <select id="view-variation-select" class="form-select form-select-sm" aria-label="Select variation"></select>
                                </div>
                                <div class="col-md-6">
                                    <span class="badge bg-light text-dark me-2">Price ₱<span id="view-selected-price">0.00</span></span>
                                    <span class="badge bg-light text-dark">Stock <span id="view-selected-stock">0</span></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <p><strong>Category:</strong> <span id="view-category"></span></p>
                        </div>
                        <div class="col-md-4">
                            <p><strong>Supplier:</strong> <span id="view-supplier"></span></p>
                        </div>
                        <div class="col-md-4">
                            <p><strong>Location:</strong> <span id="view-location"></span></p>
                        </div>
                    </div>
                    <div class="mb-3">
                        <p><strong>Variations:</strong></p>
                        <div id="view-variation-list"></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script src="../js/unit_utils.js"></script>
    <script>
    $(function(){
        $('#inventoryTable').DataTable({ responsive: true, order: [[1,'asc']] });
        // View modal fill
        $('.view-btn').on('click', function(){
            $('#view-sku').text($(this).data('sku'));
            $('#view-name').text($(this).data('name'));
            $('#view-description').text($(this).data('description'));
            $('#view-reorder').text($(this).data('reorder'));
            $('#view-category').text($(this).data('category'));
            $('#view-location').text($(this).data('location'));
            var supplierId = $(this).data('supplier');
            var supplierName = '';
            <?php foreach ($suppliersArr as $supplier): ?>
                if (supplierId == <?php echo $supplier['id']; ?>) { supplierName = '<?php echo $supplier['name']; ?>'; }
            <?php endforeach; ?>
            $('#view-supplier').text(supplierName);
            var unitType = $(this).data('unit_type') || 'Per Piece';
            $('#view-unit-type').text(unitType || 'N/A');
            $('#view-source').text($(this).data('source'));
            // Build variation dropdown and list
            var priceRaw = $(this).attr('data-variation_prices') || '';
            var stockRaw = $(this).attr('data-variation_stocks') || '';
            var priceMap = {}, stockMap = {};
            if (priceRaw) { try { priceMap = JSON.parse(priceRaw.replace(/&quot;/g, '"')); } catch(e) { priceMap = {}; } }
            if (stockRaw) { try { stockMap = JSON.parse(stockRaw.replace(/&quot;/g, '"')); } catch(e) { stockMap = {}; } }
            var reorder = parseInt($(this).data('reorder'), 10) || 0;
            var $sel = $('#view-variation-select');
            $sel.empty();
            var listHtml = '';
            Object.keys(priceMap).forEach(function(name){
                var price = parseFloat(priceMap[name] || 0);
                var stock = parseInt((stockMap[name] || 0), 10);
                var lowStock = stock <= reorder;
                var stockClass = stock > 0 ? (lowStock ? 'bg-warning' : 'bg-success') : 'bg-danger';
                var lowClass = lowStock ? ' text-warning' : '';
                $sel.append('<option value="'+name.replace(/"/g,'&quot;')+'" data-price="'+price.toFixed(2)+'" data-stock="'+stock+'" class="'+lowClass+'">'+name+' — ₱'+price.toFixed(2)+'</option>');
                listHtml += '<div class="d-flex justify-content-between align-items-center mb-2 p-2 border rounded">' +
                            '<span class="fw-semibold">'+name+'</span>' +
                            '<div class="d-flex align-items-center gap-2">' +
                            '<span class="text-muted">₱'+price.toFixed(2)+'</span>' +
                            '<span class="badge '+stockClass+' text-white">Stock: '+stock+'</span>' +
                            '</div></div>';
            });
            $('#view-variation-list').html(listHtml || '<span class="text-muted">No variations</span>');
            var $opt = $sel.find('option').first();
            var selPrice = $opt.length ? $opt.data('price') : '0.00';
            var selStock = $opt.length ? $opt.data('stock') : '0';
            $('#view-selected-price').text(selPrice);
            $('#view-selected-stock').text(selStock);
            $sel.off('change').on('change', function(){
                var $o = $(this).find('option:selected');
                $('#view-selected-price').text(($o.data('price')||'0.00'));
                $('#view-selected-stock').text(($o.data('stock')||'0'));
            });
        });

        // Real-time inventory refresh for staff (every 30s)
        function refreshInventoryTable() {
            $.ajax({
                url: '../admin/ajax/refresh_inventory.php',
                type: 'GET',
                dataType: 'json',
                success: function(response){
                    if (!response.success) return;
                    var table = $('#inventoryTable').DataTable();
                    table.clear();
                    response.data.forEach(function(item){
                        var sourceClass = item.source_type === 'Admin Created' ? 'bg-primary' : 'bg-success';
                        var rowClass = '';
                        var unitBadge = '<span class="badge bg-secondary">' + (item.unit_type ? item.unit_type : 'Per Piece') + '</span>';
                        var variationsDisplay = '';
                        var vp = item.variation_prices ? JSON.stringify(item.variation_prices).replace(/\"/g, '&quot;') : '';
                        var vs = item.variation_stocks ? JSON.stringify(item.variation_stocks).replace(/\"/g, '&quot;') : '{}';
                        // Build dropdown from both prices and stocks to ensure all variations are included
                        var allVariations = new Set();
                        if (item.variation_prices) {
                            Object.keys(item.variation_prices).forEach(function(k) { allVariations.add(k); });
                        }
                        if (item.variation_stocks) {
                            Object.keys(item.variation_stocks).forEach(function(k) { allVariations.add(k); });
                        }
                        
                        if (allVariations.size > 0) {
                            var reorder = parseInt(item.reorder_threshold, 10) || 0;
                            var variationArray = Array.from(allVariations);
                            variationsDisplay = '<select class="form-select form-select-sm variation-select" aria-label="Select variation">';
                            variationArray.forEach(function(name){
                                var price = parseFloat((item.variation_prices && item.variation_prices[name]) || 0);
                                var stock = parseInt((item.variation_stocks && item.variation_stocks[name]) || 0, 10);
                                var lowClass = stock <= reorder ? ' text-warning' : '';
                                var stockClass = stock > 0 ? 'text-success fw-bold' : 'text-danger';
                                variationsDisplay += '<option value="'+name.replace(/\"/g,'&quot;')+'" data-price="'+price.toFixed(2)+'" data-stock="'+stock+'" class="'+lowClass+' '+stockClass+'">'+name.replace(/</g, '&lt;').replace(/>/g, '&gt;')+' — ₱'+price.toFixed(2)+'</option>';
                            });
                            variationsDisplay += '</select>';
                        } else {
                            variationsDisplay = '<span class="text-muted">—</span>';
                        }
                        var vt = item.unit_type ? item.unit_type : 'Per Piece';
                        var row = [
                            item.sku,
                            item.name,
                            item.category,
                            item.reorder_threshold,
                            unitBadge,
                            variationsDisplay || '—',
                            item.supplier_name || '',
                            item.location,
                            '<span class="badge ' + sourceClass + '">' + item.source_type + '</span>',
                            '<button type="button" class="btn btn-sm btn-info view-btn" data-id="' + item.id + '" data-sku="' + item.sku + '" data-name="' + item.name + '" data-description="' + (item.description || '') + '" data-reorder="' + item.reorder_threshold + '" data-supplier="' + (item.supplier_id || '') + '" data-category="' + item.category + '" data-location="' + item.location + '" data-source="' + item.source_type + '" data-variations="' + (Array.isArray(item.variations) ? item.variations.join(', ') : (item.variations || '')) + '" data-unit_type="' + vt + '" data-variation_prices="' + vp + '" data-variation_stocks="' + vs + '" data-bs-toggle="modal" data-bs-target="#viewInventoryModal"><i class="bi bi-eye"></i></button>'
                        ];
                        var addedRow = table.row.add(row).draw(false);
                        if (rowClass) { $(addedRow.node()).addClass(rowClass); }
                    });
                    bindStaffHandlers();
                }
            });
        }

        function bindStaffHandlers(){
            $('.view-btn').off('click').on('click', function(){
                $('#view-sku').text($(this).data('sku'));
                $('#view-name').text($(this).data('name'));
                $('#view-description').text($(this).data('description'));
                $('#view-reorder').text($(this).data('reorder'));
                $('#view-category').text($(this).data('category'));
                $('#view-location').text($(this).data('location'));
                var supplierId = $(this).data('supplier');
                var supplierName = '';
                <?php foreach ($suppliersArr as $supplier): ?>
                if (supplierId == <?php echo $supplier['id']; ?>) { supplierName = '<?php echo $supplier['name']; ?>'; }
                <?php endforeach; ?>
                $('#view-supplier').text(supplierName);
                // Build variation dropdown and list
                var priceRaw = $(this).attr('data-variation_prices') || '';
                var stockRaw = $(this).attr('data-variation_stocks') || '';
                var priceMap = {}, stockMap = {};
                if (priceRaw) { try { priceMap = JSON.parse(priceRaw.replace(/&quot;/g, '"')); } catch(e) { priceMap = {}; } }
                if (stockRaw) { try { stockMap = JSON.parse(stockRaw.replace(/&quot;/g, '"')); } catch(e) { stockMap = {}; } }
                var reorder = parseInt($(this).data('reorder'), 10) || 0;
                var $sel = $('#view-variation-select');
                $sel.empty();
                var listHtml = '';
                Object.keys(priceMap).forEach(function(name){
                    var price = parseFloat(priceMap[name] || 0);
                    var stock = parseInt((stockMap[name] || 0), 10);
                    var lowStock = stock <= reorder;
                    var stockClass = stock > 0 ? (lowStock ? 'bg-warning' : 'bg-success') : 'bg-danger';
                    var lowClass = lowStock ? ' text-warning' : '';
                    $sel.append('<option value="'+name.replace(/"/g,'&quot;')+'" data-price="'+price.toFixed(2)+'" data-stock="'+stock+'" class="'+lowClass+'">'+name+' — ₱'+price.toFixed(2)+'</option>');
                    listHtml += '<div class="d-flex justify-content-between align-items-center mb-2 p-2 border rounded">' +
                                '<span class="fw-semibold">'+name+'</span>' +
                                '<div class="d-flex align-items-center gap-2">' +
                                '<span class="text-muted">₱'+price.toFixed(2)+'</span>' +
                                '<span class="badge '+stockClass+' text-white">Stock: '+stock+'</span>' +
                                '</div></div>';
                });
                $('#view-variation-list').html(listHtml || '<span class="text-muted">No variations</span>');
                // Set selected info
                var $opt = $sel.find('option').first();
                var selPrice = $opt.length ? $opt.data('price') : '0.00';
                var selStock = $opt.length ? $opt.data('stock') : '0';
                $('#view-selected-price').text(selPrice);
                $('#view-selected-stock').text(selStock);
                $sel.off('change').on('change', function(){
                    var $o = $(this).find('option:selected');
                    $('#view-selected-price').text(($o.data('price')||'0.00'));
                    $('#view-selected-stock').text(($o.data('stock')||'0'));
                });
            });
        }

        // Initial and interval refresh
        refreshInventoryTable();
        setInterval(refreshInventoryTable, 30000);
    });
    </script>
</body>
</html>

