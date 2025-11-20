<?php
// ====== Access control & dependencies ======
include_once '../config/session.php';   // namespaced sessions (admin/staff)
require_once '../config/database.php';

// Load models
require_once '../models/inventory.php';
require_once '../models/supplier.php';
require_once '../models/order.php';
require_once '../models/sales_transaction.php';
require_once '../models/alert_log.php';
require_once '../models/inventory_variation.php';

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

// Fetch stats
$total_inventory  = $inventory->getCount();
$total_suppliers  = $supplier->getCount();
$total_orders     = $order->getCount();
$pending_orders   = $order->getPendingCount();

// Compute total sales directly (no model method required)
$__stmt = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales_transactions");
$__stmt->execute();
$total_sales = (float)$__stmt->fetchColumn();

$low_stock_count  = $inventory->getLowStock()->rowCount();

// Data for tables
$recent_sales     = $sales->getRecentTransactions(5);
$recent_orders    = $order->getRecentOrders(5);
$low_stock_items  = $inventory->getLowStock();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Dashboard - Inventory & Stock Control</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets/css/style.css" rel="stylesheet">
  <script src="assets/js/notification-badge.js"></script>
</head>
<body>
  <?php include_once 'includes/header.php'; ?>
  <div class="container-fluid">
    <div class="row">
      <?php include_once 'includes/sidebar.php'; ?>

      <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
        <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
          <h1 class="h2">Dashboard</h1>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
          <div class="col-md-3">
            <div class="card text-white bg-primary mb-3">
              <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                  <h6>Total Inventory</h6>
                  <h3><?= $total_inventory ?></h3>
                </div>
                <i class="bi bi-box-seam fs-2"></i>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="card text-white bg-success mb-3">
              <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                  <h6>Total Sales</h6>
                  <h3>₱<?= number_format($total_sales,2) ?></h3>
                </div>
                <i class="bi bi-cash-stack fs-2"></i>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="card text-white bg-warning mb-3">
              <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                  <h6>Pending Orders</h6>
                  <h3><?= $pending_orders ?></h3>
                </div>
                <i class="bi bi-truck fs-2"></i>
              </div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="card text-white bg-danger mb-3">
              <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                  <h6>Low Stock Items</h6>
                  <h3><?= $low_stock_count ?></h3>
                </div>
                <i class="bi bi-exclamation-triangle fs-2"></i>
              </div>
            </div>
          </div>
        </div>

        <!-- Recent Sales -->
        <div class="row">
          <div class="col-md-6 mb-4">
            <div class="card">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h5>Recent Sales</h5>
                <a href="sales.php" class="btn btn-sm btn-primary">View All</a>
              </div>
              <div class="card-body table-responsive">
                <table class="table table-striped table-sm">
                  <thead><tr><th>Item</th><th>Per Unit</th><th>Variations</th><th>Qty</th><th>Amount</th><th>Date</th></tr></thead>
                  <tbody>
                    <?php while($row = $recent_sales->fetch(PDO::FETCH_ASSOC)): ?>
                    <?php
                      $unit = '';
                      $variationStr = '';
                      if (!empty($row['inventory_id'])) {
                        try {
                          $variants = $invVariation->getByInventory((int)$row['inventory_id']);
                          if (is_array($variants) && count($variants) > 0) {
                            $unitTypes = [];
                            $names = [];
                            foreach ($variants as $v) {
                              $ut = trim((string)($v['unit_type'] ?? ''));
                              if ($ut !== '') { $unitTypes[$ut] = true; }
                            $vn = trim((string)($v['variation'] ?? ''));
                            if ($vn !== '') { 
                              // Format variation for display (combine values only)
                              $formatted = formatVariationForDisplay($vn);
                              $names[] = $formatted; 
                            }
                          }
                          $keys = array_keys($unitTypes);
                          if (count($keys) > 1)      { $unit = 'Mixed'; }
                          elseif (count($keys) === 1) { $unit = $keys[0]; }
                          $variationStr = implode(', ', $names);
                          }
                        } catch (Throwable $e) { /* ignore */ }
                      }
                    ?>
                    <tr>
                      <td><?= htmlspecialchars($row['item_name']) ?></td>
                      <td><?= htmlspecialchars($unit) ?></td>
                      <td><?= htmlspecialchars($variationStr) ?></td>
                      <td><?= $row['quantity'] ?></td>
                      <td>₱<?= number_format($row['total_amount'],2) ?></td>
                      <td><?= date('M d, Y',strtotime($row['transaction_date'])) ?></td>
                    </tr>
                    <?php endwhile; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Low Stock Items -->
          <div class="col-md-6 mb-4">
            <div class="card">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h5>Low Stock Items</h5>
                <a href="inventory.php" class="btn btn-sm btn-primary">View All</a>
              </div>
              <div class="card-body table-responsive">
                <table class="table table-striped table-sm">
                  <thead><tr><th>Item</th><th>Per Unit</th><th>Variations</th><th>Stock</th><th>Threshold</th><th>Supplier</th></tr></thead>
                  <tbody>
                    <?php while($row = $low_stock_items->fetch(PDO::FETCH_ASSOC)): ?>
                    <?php
                      $unit = '';
                      $variationStr = '';
                      if (!empty($row['id'])) {
                        try {
                          $variants = $invVariation->getByInventory((int)$row['id']);
                          if (is_array($variants) && count($variants) > 0) {
                            $unitTypes = [];
                            $names = [];
                            foreach ($variants as $v) {
                              $ut = trim((string)($v['unit_type'] ?? ''));
                              if ($ut !== '') { $unitTypes[$ut] = true; }
                            $vn = trim((string)($v['variation'] ?? ''));
                            if ($vn !== '') { 
                              // Format variation for display (combine values only)
                              $formatted = formatVariationForDisplay($vn);
                              $names[] = $formatted; 
                            }
                          }
                          $keys = array_keys($unitTypes);
                          if (count($keys) > 1)      { $unit = 'Mixed'; }
                          elseif (count($keys) === 1) { $unit = $keys[0]; }
                          $variationStr = implode(', ', $names);
                          }
                        } catch (Throwable $e) { /* ignore */ }
                      }
                    ?>
                    <tr class="<?= $row['quantity']==0?'table-danger':'table-warning' ?>">
                      <td><?= htmlspecialchars($row['name']) ?></td>
                      <td><?= htmlspecialchars($unit) ?></td>
                      <td><?= htmlspecialchars($variationStr) ?></td>
                      <td><?= $row['quantity'] ?></td>
                      <td><?= $row['reorder_threshold'] ?></td>
                      <td><?= htmlspecialchars($row['supplier_name']) ?></td>
                    </tr>
                    <?php endwhile; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- Recent Orders -->
        <div class="row mb-4">
          <div class="col-12">
            <div class="card">
              <div class="card-header d-flex justify-content-between align-items-center">
                <h5>Recent Orders</h5>
                <a href="orders.php" class="btn btn-sm btn-primary">View All</a>
              </div>
              <div class="card-body table-responsive">
                <table class="table table-striped table-sm">
                  <thead>
                    <tr><th>#</th><th>Item</th><th>Per Unit</th><th>Variation</th><th>Supplier</th><th>Qty</th><th>Status</th><th>Date</th></tr>
                  </thead>
                  <tbody>
                    <?php while($row = $recent_orders->fetch(PDO::FETCH_ASSOC)): ?>
                    <?php
                      // Prefer using order's stored unit_type and variation if available; fallback to inventory variants
                      $unit = '';
                      $variation = '';
                      if (!empty($row['unit_type'])) {
                        $unit = (string)$row['unit_type'];
                      }
                      if (!empty($row['variation'])) {
                        $variation = (string)$row['variation'];
                      }
                      if ($unit === '' || $variation === '') {
                        if (!empty($row['inventory_id'])) {
                          try {
                            $variants = $invVariation->getByInventory((int)$row['inventory_id']);
                            if (is_array($variants) && count($variants) > 0) {
                              $unitTypes = [];
                              $names = [];
                              foreach ($variants as $v) {
                                $ut = trim((string)($v['unit_type'] ?? ''));
                                if ($ut !== '') { $unitTypes[$ut] = true; }
                                $vn = trim((string)($v['variation'] ?? ''));
                                if ($vn !== '') { $names[] = $vn; }
                              }
                              $keys = array_keys($unitTypes);
                              if ($unit === '') {
                                if (count($keys) > 1)      { $unit = 'Mixed'; }
                                elseif (count($keys) === 1) { $unit = $keys[0]; }
                              }
                              if ($variation === '') { 
                                $formattedNames = array_map('formatVariationForDisplay', $names);
                                $variation = implode(', ', $formattedNames); 
                              }
                            }
                          } catch (Throwable $e) { /* ignore */ }
                        }
                      }
                    ?>
                    <tr>
                      <td><?= $row['id'] ?></td>
                      <td><?= htmlspecialchars($row['item_name']) ?></td>
                      <td><?= htmlspecialchars($unit) ?></td>
                      <td><?= htmlspecialchars($variation) ?></td>
                      <td><?= htmlspecialchars($row['supplier_name']) ?></td>
                      <td><?= $row['quantity'] ?></td>
                      <td>
                        <?php if($row['confirmation_status']==='pending'): ?>
                          <span class="badge bg-warning">Pending</span>
                        <?php elseif($row['confirmation_status']==='confirmed'): ?>
                          <span class="badge bg-success">Confirmed</span>
                        <?php else: ?>
                          <span class="badge bg-danger">Cancelled</span>
                        <?php endif; ?>
                      </td>
                      <td><?= date('M d, Y',strtotime($row['order_date'])) ?></td>
                    </tr>
                    <?php endwhile; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </main>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
  <script src="assets/js/notifications.js"></script>
</body>
</html>
