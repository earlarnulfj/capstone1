<?php
// ===== Dependencies & access control =====
include_once '../config/session.php';
require_once '../config/database.php';

// Load the model classes
require_once '../models/sales_transaction.php';
require_once '../models/inventory.php';
require_once '../models/alert_log.php';
require_once '../models/inventory_variation.php';

requireStaffPage();

// ====== Helper function for variation display ======
// Format variation for display: "Color:Red|Size:Small" -> "Red Small" (combine values only)
function formatVariationForDisplay($variation) { return InventoryVariation::formatVariationForDisplay($variation, ' '); }

// ---- Create dependencies ----
$database = new Database();
$db       = $database->getConnection();

$sales = new SalesTransaction($db);
$invVariation = new InventoryVariation($db);

// Process date range filter
$start_date = date('Y-m-d', strtotime('-30 days'));
$end_date = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['filter'])) {
    $start_date = $_POST['start_date'] ?? $start_date;
    $end_date = $_POST['end_date'] ?? $end_date;
}

// Get sales by date range
$stmt = $sales->getSalesByDateRange($start_date, $end_date);

// Get total sales for the period
$total_sales = 0;
$total_items = 0;
$sales_data = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $total_sales += $row['total_amount'];
    $total_items += $row['quantity'];
    $sales_data[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales History - Inventory & Stock Control System</title>
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
                    <h1 class="h2">Sales History</h1>
                </div>
                
                <!-- Date Range Filter -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Filter Sales</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" class="row g-3">
                            <div class="col-md-4">
                                <label for="start_date" class="form-label">Start Date</label>
                                <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="end_date" class="form-label">End Date</label>
                                <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>">
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" name="filter" class="btn btn-primary">Apply Filter</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Sales Summary -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card text-white bg-primary">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title">Total Sales</h6>
                                        <h2 class="card-text">₱<?php echo number_format($total_sales, 2); ?></h2>
                                    </div>
                                    <i class="bi bi-cash-stack fs-1"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-white bg-success">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title">Total Items Sold</h6>
                                        <h2 class="card-text"><?php echo $total_items; ?></h2>
                                    </div>
                                    <i class="bi bi-box-seam fs-1"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-white bg-info">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="card-title">Date Range</h6>
                                        <h5 class="card-text"><?php echo date('M d, Y', strtotime($start_date)); ?> - <?php echo date('M d, Y', strtotime($end_date)); ?></h5>
                                    </div>
                                    <i class="bi bi-calendar-range fs-1"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Sales Transactions Table -->
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-table me-1"></i>
                        Sales Transactions
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="salesTable" class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Transaction ID</th>
                                        <th>Item</th>
                                        <th>Per Unit</th>
                                        <th>Variations</th>
                                        <th>Quantity</th>
                                        <th>Total Amount</th>
                                        <th>Cashier</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sales_data as $row): ?>
                                        <?php
                                          // Get unit_type and variation directly from sales_transaction record
                                          $unit = htmlspecialchars($row['unit_type'] ?? 'per piece');
                                          $variationStr = htmlspecialchars(formatVariationForDisplay($row['variation'] ?? ''));
                                          
                                          // Calculate per unit price
                                          $perUnitPrice = $row['quantity'] > 0 ? ($row['total_amount'] / $row['quantity']) : $row['total_amount'];
                                        ?>
                                        <tr>
                                            <td><?php echo date('Y-m-d H:i', strtotime($row['transaction_date'])); ?></td>
                                            <td>#<?php echo $row['id']; ?></td>
                                            <td><?php echo htmlspecialchars($row['item_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo $unit ?: 'per piece'; ?></td>
                                            <td><?php echo $variationStr ?: '—'; ?></td>
                                            <td><?php echo $row['quantity']; ?></td>
                                            <td>₱<?php echo number_format($row['total_amount'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($row['username'] ?? 'N/A'); ?></td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-sm btn-outline-primary btn-view-transaction" 
                                                        data-bs-toggle="modal" data-bs-target="#transactionModal"
                                                        data-id="<?= (int)$row['id'] ?>"
                                                        data-date="<?= htmlspecialchars(date('Y-m-d H:i', strtotime($row['transaction_date']))) ?>"
                                                        data-item="<?= htmlspecialchars($row['item_name'] ?? 'N/A') ?>"
                                                        data-unit="<?= $unit ?>"
                                                        data-variations="<?= $variationStr ?>"
                                                        data-qty="<?= htmlspecialchars($row['quantity']) ?>"
                                                        data-total="<?= number_format($row['total_amount'], 2) ?>"
                                                        data-perunit="<?= number_format($perUnitPrice, 2) ?>"
                                                        data-cashier="<?= htmlspecialchars($row['username'] ?? 'N/A') ?>">
                                                    <i class="bi bi-eye"></i> View
                                                </button>
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize DataTable
            $('#salesTable').DataTable({
                responsive: true,
                order: [[0, 'desc']]
            });
            
            // Transaction modal (same as admin/sales.php)
            if (!document.getElementById('transactionModal')) {
                var modalHtml = '<div class="modal fade" id="transactionModal" tabindex="-1" aria-labelledby="transactionModalLabel" aria-hidden="true">\n'
                  + '  <div class="modal-dialog modal-lg modal-dialog-scrollable">\n'
                  + '    <div class="modal-content">\n'
                  + '      <div class="modal-header">\n'
                  + '        <h5 class="modal-title" id="transactionModalLabel">Transaction Details</h5>\n'
                  + '        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>\n'
                  + '      </div>\n'
                  + '      <div class="modal-body">\n'
                  + '        <div class="row g-3">\n'
                  + '          <div class="col-12 col-md-6">\n'
                  + '            <div class="list-group list-group-flush">\n'
                  + '              <div class="list-group-item d-flex justify-content-between">\n'
                  + '                <span class="text-muted">Transaction ID</span>\n'
                  + '                <span id="td-id" class="fw-semibold"></span>\n'
                  + '              </div>\n'
                  + '              <div class="list-group-item d-flex justify-content-between">\n'
                  + '                <span class="text-muted">Date</span>\n'
                  + '                <span id="td-date"></span>\n'
                  + '              </div>\n'
                  + '              <div class="list-group-item d-flex justify-content-between">\n'
                  + '                <span class="text-muted">Cashier</span>\n'
                  + '                <span id="td-cashier"></span>\n'
                  + '              </div>\n'
                  + '            </div>\n'
                  + '          </div>\n'
                  + '          <div class="col-12 col-md-6">\n'
                  + '            <div class="list-group list-group-flush">\n'
                  + '              <div class="list-group-item d-flex justify-content-between">\n'
                  + '                <span class="text-muted">Item</span>\n'
                  + '                <span id="td-item"></span>\n'
                  + '              </div>\n'
                  + '              <div class="list-group-item d-flex justify-content-between">\n'
                  + '                <span class="text-muted">Unit</span>\n'
                  + '                <span id="td-unit"></span>\n'
                  + '              </div>\n'
                  + '              <div class="list-group-item d-flex justify-content-between">\n'
                  + '                <span class="text-muted">Variations</span>\n'
                  + '                <span id="td-variations"></span>\n'
                  + '              </div>\n'
                  + '            </div>\n'
                  + '          </div>\n'
                  + '          <div class="col-12">\n'
                  + '            <div class="row g-3">\n'
                  + '              <div class="col-6">\n'
                  + '                <div class="card">\n'
                  + '                  <div class="card-body">\n'
                  + '                    <div class="d-flex justify-content-between align-items-center">\n'
                  + '                      <div>\n'
                  + '                        <div class="text-muted">Quantity</div>\n'
                  + '                        <div id="td-qty" class="fs-5 fw-semibold"></div>\n'
                  + '                      </div>\n'
                  + '                      <i class="bi bi-box-seam fs-3 text-secondary"></i>\n'
                  + '                    </div>\n'
                  + '                  </div>\n'
                  + '                </div>\n'
                  + '              </div>\n'
                  + '              <div class="col-6">\n'
                  + '                <div class="card">\n'
                  + '                  <div class="card-body">\n'
                  + '                    <div class="d-flex justify-content-between align-items-center">\n'
                  + '                      <div>\n'
                  + '                        <div class="text-muted">Total Amount</div>\n'
                  + '                        <div id="td-total" class="fs-5 fw-semibold"></div>\n'
                  + '                        <div class="small text-muted">Per Unit: <span id="td-perunit"></span></div>\n'
                  + '                      </div>\n'
                  + '                      <i class="bi bi-cash-stack fs-3 text-success"></i>\n'
                  + '                    </div>\n'
                  + '                  </div>\n'
                  + '                </div>\n'
                  + '              </div>\n'
                  + '            </div>\n'
                  + '          </div>\n'
                  + '        </div>\n'
                  + '      </div>\n'
                  + '      <div class="modal-footer">\n'
                  + '        <button type="button" class="btn btn-outline-secondary" id="copyTxnId"><i class="bi bi-clipboard"></i> Copy ID</button>\n'
                  + '        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>\n'
                  + '      </div>\n'
                  + '    </div>\n'
                  + '  </div>\n'
                  + '</div>';
                var wrapper = document.createElement('div');
                wrapper.innerHTML = modalHtml;
                document.body.appendChild(wrapper.firstElementChild);
            }
        });
        
        // Open and populate modal on action button click
        document.addEventListener('click', function(e){
            var btn = e.target.closest('.btn-view-transaction');
            if (!btn) return;

            var id = btn.getAttribute('data-id') || 'N/A';
            var date = btn.getAttribute('data-date') || '';
            var cashier = btn.getAttribute('data-cashier') || 'N/A';
            var item = btn.getAttribute('data-item') || 'N/A';
            var unit = btn.getAttribute('data-unit') || 'per piece';
            var variations = btn.getAttribute('data-variations') || '';
            var qty = parseFloat(btn.getAttribute('data-qty') || '0');
            var totalStr = btn.getAttribute('data-total') || '0';
            var total = parseFloat(totalStr.replace(/[^\d.-]/g, '')) || 0;
            var perUnit = btn.getAttribute('data-perunit') || (qty > 0 ? (total / qty) : 0);
            if (typeof perUnit === 'string' && perUnit.includes('₱')) {
                perUnit = parseFloat(perUnit.replace(/[₱,]/g, '')) || (qty > 0 ? (total / qty) : 0);
            } else {
                perUnit = parseFloat(perUnit) || (qty > 0 ? (total / qty) : 0);
            }

            var setText = function(idSel, val){ var el = document.getElementById(idSel); if (el) el.textContent = val; };
            setText('td-id', '#' + id);
            setText('td-date', date);
            setText('td-cashier', cashier);
            setText('td-item', item);
            setText('td-unit', unit || 'per piece');
            setText('td-variations', variations || '—');
            setText('td-qty', isNaN(qty) ? 'N/A' : qty);
            setText('td-total', '₱' + (isNaN(total) ? '0.00' : total.toFixed(2)));
            setText('td-perunit', '₱' + (isNaN(perUnit) ? '0.00' : perUnit.toFixed(2)));

            var copyBtn = document.getElementById('copyTxnId');
            if (copyBtn) {
                copyBtn.onclick = function(){
                    navigator.clipboard.writeText(String(id)).then(function(){
                        copyBtn.innerHTML = '<i class="bi bi-clipboard-check"></i> Copied';
                        setTimeout(function(){ copyBtn.innerHTML = '<i class="bi bi-clipboard"></i> Copy ID'; }, 2000);
                    });
                };
            }
        });
    </script>
</body>
</html>
