<?php
// ====== Access control & dependencies (corrected) ======
include_once '../config/session.php';   // namespaced sessions (admin/staff)
require_once '../config/database.php';  // DB connection

// Load all model classes this page uses
require_once '../models/inventory.php';
require_once '../models/supplier.php';
require_once '../models/order.php';
require_once '../models/sales_transaction.php';
require_once '../models/alert_log.php';
require_once '../models/inventory_variation.php';
// If your dashboard uses more models, include them here with require_once

requireManagementPage();

// ====== Helper function for variation display ======
// Format variation for display: "Color:Red|Size:Small" -> "Red Small" (combine values only)
function formatVariationForDisplay($variation) { return InventoryVariation::formatVariationForDisplay($variation, ' '); }

// ---- Instantiate dependencies ----
$db         = (new Database())->getConnection();
$inventory  = new Inventory($db);
$supplier   = new Supplier($db);
$order      = new Order($db);
$sales      = new SalesTransaction($db);
$alert      = new AlertLog($db);
$invVariation = new InventoryVariation($db);

// ====== (Keep your existing page logic below) ======
// From here down, keep your original code (queries, computations, HTML).
// For example, if you previously computed variables like $total_inventory,
// $total_suppliers, $pending_orders, etc., leave that logic as-is.


$sales     = new SalesTransaction($db);
$inventory = new Inventory($db);
$order     = new Order($db);
$supplier  = new Supplier($db);

// Default: last 30 days, sales report
$start_date  = date('Y-m-d', strtotime('-30 days'));
$end_date    = date('Y-m-d');
$report_type = 'sales';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['generate_report'])) {
        $start_date  = $_POST['start_date']  ?: $start_date;
        $end_date    = $_POST['end_date']    ?: $end_date;
        $report_type = $_POST['report_type'] ?: $report_type;
    }
    
    
}

$message = $message ?? '';
$messageType = $messageType ?? 'success';

// Gather report data
$report_data  = [];
$report_title = '';
switch ($report_type) {
    case 'sales':
        $stmt = $sales->getSalesReport($start_date, $end_date);
        $report_title = 'Sales Report';
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $report_data[] = $r;
        }
        break;
    case 'inventory':
        $stmt = $inventory->getInventoryReport($start_date, $end_date);
        $report_title = 'Inventory Report';
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $report_data[] = $r;
        }
        break;
    case 'orders':
        $stmt = $order->getOrderReport($start_date, $end_date);
        $report_title = 'Orders Report';
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $report_data[] = $r;
        }
        break;
    case 'low_stock':
        $stmt = $inventory->getLowStockReport($start_date, $end_date);
        $report_title = 'Low Stock Report';
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $report_data[] = $r;
        }
        break;
}

// CSV export
if (isset($_POST['export_csv'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename="report.csv"');
    $out = fopen('php://output','w');

    switch ($report_type) {
        case 'sales':
            fputcsv($out, ['Date','Item Name','Per Unit','Variations','Total Quantity','Total Amount','Transaction Count','Cashier']);
            foreach ($report_data as $r) {
                // Get unit_types and variations directly from sales report query
                $perUnit = $r['unit_types'] ?? 'N/A';
                $variations = $r['variations'] ?? 'N/A';

                fputcsv($out, [
                    $r['date'],
                    $r['item_name'],
                    $perUnit,
                    $variations,
                    $r['total_quantity'],
                    $r['total_amount'],
                    $r['transaction_count'],
                    $r['cashier'] ?? ''
                ]);
            }
            break;

        case 'inventory':
            fputcsv($out, ['SKU','Product Name','Category','Quantity','Unit Price','Per Unit','Variations','Total Value','Supplier','Location']);
            foreach ($report_data as $r) {
                $vars = [];
                try {
                    $vars = $invVariation->getByInventory((int)($r['inventory_id'] ?? 0));
                } catch (Throwable $e) { $vars = []; }
                $unitTypes = array_unique(array_filter(array_map(function($v){ return $v['unit_type'] ?? null; }, $vars)));
                $varNames  = array_filter(array_map(function($v){ return $v['variation'] ?? ''; }, $vars));
                $perUnit   = count($unitTypes) === 1 ? $unitTypes[0] : (count($unitTypes) > 1 ? 'Mixed' : '');
                $variations = implode('|', $varNames);
                
                fputcsv($out, [
                    $r['sku'],
                    $r['name'],
                    $r['category'],
                    $r['quantity'],
                    $r['unit_price'],
                    $perUnit,
                    $variations,
                    $r['total_value'],
                    $r['supplier_name'],
                    $r['location']
                ]);
            }
            break;

        case 'orders':
            fputcsv($out, ['Order ID','Order Date','Item','Per Unit','Variation','SKU','Supplier','Quantity','Unit Price','Total Amount','Status','Order Type','Ordered By']);
            foreach ($report_data as $r) {
                fputcsv($out, [
                    $r['id'],
                    $r['order_date'],
                    $r['item_name'],
                    $r['unit_type'] ?? '',
                    $r['variation'] ?? '',
                    $r['sku'],
                    $r['supplier_name'],
                    $r['quantity'],
                    $r['unit_price'],
                    $r['total_amount'],
                    $r['confirmation_status'],
                    $r['order_type'],
                    $r['ordered_by']
                ]);
            }
            break;

        case 'low_stock':
            fputcsv($out, ['SKU','Product Name','Category','Current Quantity','Reorder Threshold','Unit Price','Per Unit','Variations','Total Value','Supplier','Stock Status']);
            foreach ($report_data as $r) {
                $vars = [];
                try {
                    $vars = $invVariation->getByInventory((int)($r['inventory_id'] ?? 0));
                } catch (Throwable $e) { $vars = []; }
                $unitTypes = array_unique(array_filter(array_map(function($v){ return $v['unit_type'] ?? null; }, $vars)));
                $varNames  = array_filter(array_map(function($v){ return $v['variation'] ?? ''; }, $vars));
                $perUnit   = count($unitTypes) === 1 ? $unitTypes[0] : (count($unitTypes) > 1 ? 'Mixed' : '');
                $variations = implode('|', $varNames);
                
                fputcsv($out, [
                    $r['sku'],
                    $r['name'],
                    $r['category'],
                    $r['quantity'],
                    $r['reorder_threshold'],
                    $r['unit_price'],
                    $perUnit,
                    $variations,
                    $r['total_value'],
                    $r['supplier_name'],
                    $r['stock_status']
                ]);
            }
            break;
    }

    fclose($out);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Reports - Inventory & Stock Control</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets/css/style.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/buttons/2.2.2/css/buttons.bootstrap5.min.css" rel="stylesheet">
  <style>
    .spinner {
      display: none;
      position: fixed; top:50%; left:50%;
      transform: translate(-50%,-50%);
      border:4px solid #f3f3f3; border-top:4px solid #3498db;
      border-radius:50%; width:40px; height:40px;
      animation:spin 1s linear infinite;
    }
    @keyframes spin {0%{transform:rotate(0)}100%{transform:rotate(360deg)}}
    
    /* Print styles - hide form elements, show only table content */
    @media print {
      .no-print,
      header,
      .sidebar,
      .btn-toolbar,
      .btn-group,
      button,
      .dropdown-menu,
      form,
      .alert,
      #selectAllReports,
      label[for="selectAllReports"],
      #deleteSelectedBtn,
      th:first-child,
      td:first-child,
      .dataTables_wrapper .dataTables_filter,
      .dataTables_wrapper .dataTables_length,
      .dataTables_wrapper .dataTables_info,
      .dataTables_wrapper .dataTables_paginate {
        display: none !important;
      }
      
      body {
        margin: 0;
        padding: 20px;
      }
      
      h1, h2 {
        margin-top: 0;
      }
      
      .table-responsive {
        overflow: visible;
      }
      
      table {
        width: 100% !important;
        border-collapse: collapse;
      }
      
      th, td {
        border: 1px solid #ddd;
        padding: 8px;
      }
    }
  </style>
</head>
<body>
  <?php include_once 'includes/header.php'; ?>

  <div class="container-fluid">
    <div class="row">
      <?php include_once 'includes/sidebar.php'; ?>

      <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
        <div id="spinner" class="spinner"></div>

        <div class="d-flex justify-content-between align-items-center mb-3 border-bottom no-print">
          <h1 class="h2">Reports</h1>
          <div class="btn-toolbar">
            
            <div class="btn-group me-2">
              <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-funnel"></i> Filter
              </button>
              <div class="dropdown-menu p-3">
                <form method="POST" onsubmit="return validateDates() && showSpinner()">
                  <div class="mb-3">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="start_date" id="start_date" class="form-control" value="<?= $start_date ?>">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">End Date</label>
                    <input type="date" name="end_date" id="end_date" class="form-control" value="<?= $end_date ?>">
                  </div>
                  <div class="mb-3">
                    <label class="form-label">Report Type</label>
                    <select name="report_type" class="form-select">
                      <option value="sales"      <?= $report_type==='sales'?'selected':'' ?>>Sales</option>
                      <option value="inventory"  <?= $report_type==='inventory'?'selected':'' ?>>Inventory</option>
                      <option value="orders"     <?= $report_type==='orders'?'selected':'' ?>>Orders</option>
                      <option value="low_stock"  <?= $report_type==='low_stock'?'selected':'' ?>>Low Stock</option>
                    </select>
                  </div>
                  <button name="generate_report" class="btn btn-primary w-100">Generate</button>
                </form>
              </div>
            </div>
            <form method="POST" class="me-2">
              <button name="export_csv" class="btn btn-sm btn-outline-secondary">Export CSV</button>
            </form>
            <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
              <i class="bi bi-printer"></i> Print
            </button>
          </div>
        </div>

        <?php if ($message): ?>
          <div class="alert alert-<?= $messageType ?> alert-dismissible fade show no-print" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <h2><?= htmlspecialchars($report_title) ?></h2>

        <?php if ($report_data): ?>
          <input type="hidden" id="currentReportType" value="<?= htmlspecialchars($report_type) ?>">
          
          <div class="table-responsive">
            <table id="report_table" class="table table-striped table-bordered">
              <thead>
                <tr>
                  
                  <?php if ($report_type==='sales'): ?>
                    <th>Date</th>
                    <th>Item Name</th>
                    <th>Per Unit</th>
                    <th>Variations</th>
                    <th>Total Quantity</th>
                    <th>Total Amount</th>
                    <th>Transaction Count</th>
                    <th>Cashier</th>
                  <?php elseif($report_type==='inventory'): ?>
                    <th>SKU</th>
                    <th>Product Name</th>
                    <th>Category</th>
                    <th>Quantity</th>
                    <th>Unit Price</th>
                    <th>Per Unit</th>
                    <th>Variations</th>
                    <th>Total Value</th>
                    <th>Supplier</th>
                    <th>Location</th>
                  <?php elseif($report_type==='orders'): ?>
                    <th>Order ID</th>
                    <th>Order Date</th>
                    <th>Item</th>
                    <th>Per Unit</th>
                    <th>Variation</th>
                    <th>Supplier</th>
                    <th>Quantity</th>
                    <th>Unit Price</th>
                    <th>Total Amount</th>
                    <th>Status</th>
                    <th>Order Type</th>
                    <th>Ordered By</th>
                  <?php else: /* low_stock */ ?>
                    <th>SKU</th>
                    <th>Product Name</th>
                    <th>Category</th>
                    <th>Current Quantity</th>
                    <th>Reorder Threshold</th>
                    <th>Unit Price</th>
                    <th>Per Unit</th>
                    <th>Variations</th>
                    <th>Total Value</th>
                    <th>Supplier</th>
                    <th>Stock Status</th>
                  <?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach($report_data as $r): ?>
                  <tr>
                    <td><span class="text-muted">—</span></td>
                    <?php if ($report_type==='sales'): ?>
                      <?php 
                        // Get unit_types and variations directly from sales report query
                        $unit_types_str = $r['unit_types'] ?? '';
                        $variations_str = $r['variations'] ?? '';
                        $__per_unit = !empty($unit_types_str) ? $unit_types_str : 'N/A';
                        if (!empty($variations_str)) {
                          // Format each variation in the comma-separated list
                          $var_parts = explode(', ', $variations_str);
                          $formatted_vars = array_map('formatVariationForDisplay', $var_parts);
                          $__variations = implode(', ', $formatted_vars);
                        } else {
                          $__variations = 'N/A';
                        }
                      ?>
                      <td><?= htmlspecialchars($r['date']) ?></td>
                      <td><?= htmlspecialchars($r['item_name'] ?? 'N/A') ?></td>
                      <td><?= htmlspecialchars($__per_unit) ?></td>
                      <td><?= htmlspecialchars($__variations) ?></td>
                      <td><?= htmlspecialchars($r['total_quantity']) ?></td>
                      <td>₱<?= number_format($r['total_amount'],2) ?></td>
                      <td><?= htmlspecialchars($r['transaction_count']) ?></td>
                      <td><?= htmlspecialchars($r['cashier'] ?? 'N/A') ?></td>
                    <?php elseif($report_type==='inventory'): ?>
                      <?php 
                        $__vars_row = $invVariation->getByInventory((int)($r['inventory_id'] ?? 0));
                        $__unit_types = array_unique(array_filter(array_map(fn($v) => $v['unit_type'] ?? null, $__vars_row)));
                        $__per_unit = count($__unit_types) === 1 ? $__unit_types[0] : (count($__unit_types) > 1 ? 'Mixed' : '');
                        $__var_names = array_filter(array_map(fn($v) => $v['variation'] ?? '', $__vars_row));
                        $__formatted_names = array_map('formatVariationForDisplay', $__var_names);
                        $__variations = implode(', ', $__formatted_names);
                      ?>
                      <td><?= htmlspecialchars($r['sku']) ?></td>
                      <td><?= htmlspecialchars($r['name']) ?></td>
                      <td><?= htmlspecialchars($r['category']) ?></td>
                      <td><?= $r['quantity'] ?></td>
                      <td>₱<?= number_format($r['unit_price'],2) ?></td>
                      <td><?= htmlspecialchars($__per_unit) ?></td>
                      <td><?= htmlspecialchars($__variations) ?></td>
                      <td>₱<?= number_format($r['total_value'],2) ?></td>
                      <td><?= htmlspecialchars($r['supplier_name'] ?? 'N/A') ?></td>
                      <td><?= htmlspecialchars($r['location']) ?></td>
                    <?php elseif($report_type==='orders'): ?>
                      <td><?= $r['id'] ?></td>
                      <td><?= date('M d, Y', strtotime($r['order_date'])) ?></td>
                      <td><?= htmlspecialchars($r['item_name'] ?? 'N/A') ?></td>
                      <td><?= htmlspecialchars($r['unit_type'] ?? '') ?></td>
                      <td><?= htmlspecialchars(formatVariationForDisplay($r['variation'] ?? '')) ?></td>
                      <td><?= htmlspecialchars($r['supplier_name'] ?? 'N/A') ?></td>
                      <td><?= $r['quantity'] ?></td>
                      <td>₱<?= number_format($r['unit_price'],2) ?></td>
                      <td>₱<?= number_format($r['total_amount'],2) ?></td>
                      <td><?= htmlspecialchars($r['confirmation_status']) ?></td>
                      <td><?= htmlspecialchars($r['order_type']) ?></td>
                      <td><?= htmlspecialchars($r['ordered_by'] ?? 'N/A') ?></td>
                    <?php else: ?>
                      <td><?= htmlspecialchars($r['sku']) ?></td>
                      <td><?= htmlspecialchars($r['name']) ?></td>
                      <td><?= htmlspecialchars($r['category']) ?></td>
                      <td><?= $r['quantity'] ?></td>
                      <td><?= $r['reorder_threshold'] ?></td>
                      <td>₱<?= number_format($r['unit_price'],2) ?></td>
                      <td>₱<?= number_format($r['total_value'],2) ?></td>
                      <td><?= htmlspecialchars($r['supplier_name'] ?? 'N/A') ?></td>
                      <td><span class="badge bg-<?= $r['stock_status'] === 'Out of Stock' ? 'danger' : ($r['stock_status'] === 'Low Stock' ? 'warning' : 'success') ?>"><?= htmlspecialchars($r['stock_status']) ?></span></td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p class="text-muted">No data available for that range/type.</p>
        <?php endif; ?>
      </main>
    </div>
  </div>

  <!-- JS -->
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
  <script>
    
    $(function(){
      $('#report_table').DataTable({
        dom: 'Bfrtip',
        buttons: ['csv','excel','print']
      });
    });
    function showSpinner(){ $('#spinner').show(); return true; }
    function validateDates(){
      let sd = $('#start_date').val(), ed = $('#end_date').val();
      if(sd > ed){ alert('Start date cannot be after end date.'); return false; }
      return true;
    }
  </script>
</body>
</html>
