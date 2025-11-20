<?php
// ===== Dependencies & access control (keep consistent with other admin pages) =====
include_once '../config/session.php';
require_once '../config/database.php';

require_once '../models/sales_transaction.php';
require_once '../models/inventory.php';
require_once '../models/alert_log.php';         // include only what you use
require_once '../models/inventory_variation.php';

requireManagementPage();

// ====== Helper function for variation display ======
// Format variation for display: "Color:Red|Size:Small" -> "Red Small" (combine values only)
function formatVariationForDisplay($variation) { return InventoryVariation::formatVariationForDisplay($variation, ' '); }

// ---- Create dependencies ----
$db         = (new Database())->getConnection();
$sales      = new SalesTransaction($db);
$inventory  = new Inventory($db);
$alert      = new AlertLog($db);
$invVariation = new InventoryVariation($db);

// Handle delete actions
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'delete_selected') {
        $transactionIds = isset($_POST['transaction_ids']) ? json_decode($_POST['transaction_ids'], true) : [];
        
        if (empty($transactionIds) || !is_array($transactionIds)) {
            $message = "No transactions selected for deletion.";
            $messageType = "warning";
        } else {
            $deletedCount = 0;
            $failedCount = 0;
            
            foreach ($transactionIds as $transId) {
                $transId = intval($transId);
                if ($transId > 0) {
                    try {
                        $deleteStmt = $db->prepare("DELETE FROM sales_transactions WHERE id = :id");
                        if ($deleteStmt->execute([':id' => $transId])) {
                            $deletedCount++;
                        } else {
                            $failedCount++;
                        }
                    } catch (Exception $e) {
                        error_log("Error deleting transaction ID {$transId}: " . $e->getMessage());
                        $failedCount++;
                    }
                }
            }
            
            if ($deletedCount > 0) {
                $message = "Successfully deleted {$deletedCount} transaction(s).";
                if ($failedCount > 0) {
                    $message .= " {$failedCount} transaction(s) could not be deleted.";
                    $messageType = "warning";
                } else {
                    $messageType = "success";
                }
            } else {
                $message = "Unable to delete selected transactions.";
                $messageType = "danger";
            }
        }
    }
}

// ===== Example: read filters (optional) =====
$from = $_GET['from'] ?? '';
$to   = $_GET['to']   ?? '';
$kw   = $_GET['q']    ?? '';

// ---- Get filtered data ----
$list = [];

// Get sales data based on filters
if (!empty($from) && !empty($to)) {
    // Get sales by date range
    $stmt = $sales->getSalesByDateRange($from, $to);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Filter by keyword if provided
        if (empty($kw) || 
            stripos($row['item_name'], $kw) !== false || 
            stripos($row['username'], $kw) !== false ||
            stripos($row['id'], $kw) !== false) {
            $list[] = $row;
        }
    }
} else {
    // Get all sales transactions
    $stmt = $sales->readAll();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Filter by keyword if provided
        if (empty($kw) || 
            stripos($row['item_name'], $kw) !== false || 
            stripos($row['username'], $kw) !== false ||
            stripos($row['id'], $kw) !== false) {
            $list[] = $row;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Sales • Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <!-- Use the same CSS you use for other admin pages -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/style.css" rel="stylesheet"> <!-- keep if your admin uses this -->
  <!-- If you use icons in dashboard: -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    /* Ensure consistent spacing like dashboard/users pages */
    .page-header { display:flex; align-items:center; justify-content:space-between; gap:1rem; }
    .page-title  { margin:0; }
    .subtitle    { color:#6c757d; font-size:.95rem; }
    .section-card .card-body { padding:1.25rem 1.25rem; }
    @media (min-width:1200px){ .section-card .card-body{ padding:1.5rem 1.75rem; } }
  </style>
</head>
<body>
<?php include 'includes/header.php'; ?>
<div class="container-fluid">
  <div class="row">
    <?php include 'includes/sidebar.php'; ?>

    <!-- Keep main grid exactly like other admin pages -->
    <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">

      <!-- Page header (title + breadcrumb) -->
      <div class="page-header mb-3">
        <div>
          <h2 class="page-title">Sales</h2>
          <div class="subtitle">View and manage sales transactions</div>
        </div>
        
        <?php if ($message): ?>
          <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert" style="position: absolute; top: 80px; right: 20px; z-index: 1000;">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>
        
        <div>
          <button type="button" id="deleteSelectedBtn" class="btn btn-outline-danger btn-sm" style="display: none;">
            <i class="bi bi-trash me-1"></i>Delete Selected (<span id="selectedCount">0</span>)
          </button>
        </div>
      </div>

      <!-- Optional: filters card (date range, keyword) -->
      <div class="card shadow-sm section-card mb-4">
        <div class="card-body">
          <form class="row g-3" method="GET" action="sales.php">
            <div class="col-12 col-md-3">
              <label class="form-label">From</label>
              <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>">
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label">To</label>
              <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Search</label>
              <input type="text" name="q" class="form-control" placeholder="Invoice, customer, item..." value="<?= htmlspecialchars($kw) ?>">
            </div>
            <div class="col-12 col-md-2 d-flex align-items-end">
              <button class="btn btn-primary w-100" type="submit"><i class="bi bi-search"></i> Filter</button>
            </div>
          </form>
        </div>
      </div>

      <!-- YOUR SALES CONTENT HERE -->
      <!-- Keep your table/cards inside this grid so the sidebar stays aligned. -->
      <div class="card shadow-sm section-card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="mb-0">Sales Transactions</h5>
            <!-- Example Action buttons -->
            <!-- <a href="sales_export.php?from=...&to=..." class="btn btn-outline-secondary btn-sm"><i class="bi bi-download"></i> Export</a> -->
          </div>

          <!-- Example table skeleton (replace with your existing table) -->
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>
                    <input type="checkbox" id="selectAllSales" class="form-check-input">
                  </th>
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
                <?php if (!empty($list)): ?>
                  <?php foreach ($list as $row): ?>
                    <?php
                      // Get unit_type and variation directly from sales_transaction record
                      $unit = htmlspecialchars($row['unit_type'] ?? 'per piece');
                      $variationStr = htmlspecialchars(formatVariationForDisplay($row['variation'] ?? ''));
                      
                      // Calculate per unit price
                      $perUnitPrice = $row['quantity'] > 0 ? ($row['total_amount'] / $row['quantity']) : $row['total_amount'];
                    ?>
                    <tr>
                      <td>
                        <input type="checkbox" class="form-check-input sales-select-checkbox" value="<?= (int)$row['id'] ?>" data-transaction-id="<?= (int)$row['id'] ?>">
                      </td>
                      <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($row['transaction_date']))) ?></td>
                      <td>#<?= htmlspecialchars($row['id']) ?></td>
                      <td><?= htmlspecialchars($row['item_name'] ?? 'N/A') ?></td>
                      <td><?= $unit ?: 'per piece' ?></td>
                      <td><?= $variationStr ?: '—' ?></td>
                      <td><?= htmlspecialchars($row['quantity']) ?></td>
                      <td>₱<?= number_format($row['total_amount'], 2) ?></td>
                      <td><?= htmlspecialchars($row['username'] ?? 'N/A') ?></td>
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
                <?php else: ?>
                  <tr><td colspan="10" class="text-center text-muted py-4">No sales transactions found.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </main>
  </div>
</div>

<script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  // Multi-select functionality for sales
  let selectedSales = new Set();
  
  function updateDeleteButton() {
    const count = selectedSales.size;
    const selectedCountEl = document.getElementById('selectedCount');
    const deleteBtn = document.getElementById('deleteSelectedBtn');
    
    if (selectedCountEl) {
      selectedCountEl.textContent = count;
    }
    if (deleteBtn) {
      deleteBtn.style.display = count > 0 ? 'inline-block' : 'none';
    }
  }
  
  // Select All checkbox
  const selectAllCheckbox = document.getElementById('selectAllSales');
  if (selectAllCheckbox) {
    selectAllCheckbox.addEventListener('change', function() {
      const isChecked = this.checked;
      document.querySelectorAll('.sales-select-checkbox').forEach(function(checkbox) {
        checkbox.checked = isChecked;
        if (isChecked) {
          selectedSales.add(checkbox.value);
        } else {
          selectedSales.delete(checkbox.value);
        }
      });
      updateDeleteButton();
    });
  }
  
  // Individual checkbox change
  document.addEventListener('change', function(e) {
    if (e.target.classList.contains('sales-select-checkbox')) {
      const checkbox = e.target;
      if (checkbox.checked) {
        selectedSales.add(checkbox.value);
      } else {
        selectedSales.delete(checkbox.value);
        if (selectAllCheckbox) {
          selectAllCheckbox.checked = false;
        }
      }
      updateDeleteButton();
    }
  });
  
  // Delete selected button
  const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
  if (deleteSelectedBtn) {
    deleteSelectedBtn.addEventListener('click', function() {
      const selectedArray = Array.from(selectedSales);
      if (selectedArray.length === 0) {
        alert('Please select at least one transaction to delete.');
        return;
      }
      
      if (confirm('WARNING: You are about to delete ' + selectedArray.length + ' sales transaction(s). This action cannot be undone and will affect financial records. Are you sure?')) {
        deleteSelectedSales(selectedArray);
      }
    });
  }
  
  function deleteSelectedSales(transactionIds) {
    const formData = new FormData();
    formData.append('action', 'delete_selected');
    formData.append('transaction_ids', JSON.stringify(transactionIds));
    
    fetch('sales.php', {
      method: 'POST',
      body: formData
    })
    .then(response => response.text())
    .then(data => {
      // Remove deleted transactions from UI
      transactionIds.forEach(function(id) {
        const row = document.querySelector('[data-transaction-id="' + id + '"]')?.closest('tr');
        if (row) {
          row.style.transition = 'opacity 0.3s ease-out';
          row.style.opacity = '0';
          setTimeout(function() {
            row.remove();
          }, 300);
        }
        selectedSales.delete(id);
      });
      
      updateDeleteButton();
      if (selectAllCheckbox) {
        selectAllCheckbox.checked = false;
      }
      
      // Reload page to refresh list
      setTimeout(function() {
        window.location.reload();
      }, 1000);
    })
    .catch(error => {
      console.error('Error deleting transactions:', error);
      alert('Error deleting transactions. Please try again.');
    });
  }

  // Existing code
  document.addEventListener('DOMContentLoaded', function(){
    // Inject modal template if missing
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

    // Hover effect remains
    var tableRows = document.querySelectorAll('tbody tr');
    tableRows.forEach(function(row){
      row.addEventListener('mouseenter', function(){ this.style.backgroundColor = '#f8f9fa'; });
      row.addEventListener('mouseleave', function(){ this.style.backgroundColor = ''; });
    });
  });

  // Open and populate modal on action button click
  document.addEventListener('click', function(e){
    var btn = e.target.closest('.btn-view-transaction');
    if (!btn) return;

    var id = btn.getAttribute('data-id') || 'N/A';
    var date = btn.getAttribute('data-date') || '';
    var cashier = btn.getAttribute('data-cashier') || 'N/A';
    var item = btn.getAttribute('data-item') || 'N/A';
    var unit = btn.getAttribute('data-unit') || 'N/A';
    var variations = btn.getAttribute('data-variations') || 'N/A';
    var qty = parseFloat(btn.getAttribute('data-qty') || '0');
    var totalStr = btn.getAttribute('data-total') || '0';
    var total = parseFloat(totalStr.replace(/[^\d.-]/g, '')) || 0;
    var perUnit = btn.getAttribute('data-perunit') || (qty > 0 ? (total / qty) : 0);
    // If perunit is a string with currency, extract number
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
})();
</script>
</body>
</html>
