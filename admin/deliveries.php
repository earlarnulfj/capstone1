<?php
// ====== Access control & dependencies ======
include_once '../config/session.php';   // namespaced sessions (admin/staff)
require_once '../config/database.php';
require_once '../models/order.php';
require_once '../models/delivery.php';
require_once '../models/payment.php';

// ---- Admin auth guard (namespaced) ----
if (empty($_SESSION['admin']['user_id'])) {
    header("Location: ../login.php");
    exit();
}
if (($_SESSION['admin']['role'] ?? null) !== 'management') {
    header("Location: ../login.php");
    exit();
}

// ====== Helper function for variation display ======
// Format variation for display: "Color:Red|Size:Small" -> "Red Small" (combine values only)
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
    return implode(' ', $values);
}

$database = new Database();
$db = $database->getConnection();
$order = new Order($db);
$delivery = new Delivery($db);
$payment = new Payment($db);

// Get admin information
$admin_id = $_SESSION['admin']['user_id'];
$admin_name = $_SESSION['admin']['username'] ?? 'Admin';

// Admin action: clear all deliveries
if (isset($_GET['action']) && $_GET['action'] === 'clear_all') {
    try {
        $db->beginTransaction();
        $deleted = $db->exec("DELETE FROM deliveries");
        $db->commit();
        $_SESSION['deliveries_clear_message'] = "Removed " . (int)$deleted . " delivery record(s).";
        $_SESSION['deliveries_clear_message_type'] = 'success';
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        $_SESSION['deliveries_clear_message'] = 'Error removing deliveries: ' . $e->getMessage();
        $_SESSION['deliveries_clear_message_type'] = 'danger';
    }
    header('Location: deliveries.php');
    exit();
}

// Handle AJAX requests for status updates
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'update_order_status':
            $order_id = (int)$_POST['order_id'];
            $new_status = $_POST['status'];
            
            // Prevent cancelling orders after delivery/completion
            if ($new_status === 'cancelled') {
                // Check current order status
                $stmt = $db->prepare("SELECT confirmation_status FROM orders WHERE id = ?");
                $stmt->execute([$order_id]);
                $current = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$current) {
                    echo json_encode(['success' => false, 'message' => 'Order not found']);
                    exit();
                }
                if (in_array($current['confirmation_status'], ['completed', 'delivered'], true)) {
                    // Audit blocked attempt
                    $log = $db->prepare("INSERT INTO sync_events(entity_type, entity_id, action, status, message, actor_id) VALUES('order', ?, 'cancel_attempt_blocked', 'blocked', 'Cannot cancel completed/delivered order', ?)");
                    $log->execute([$order_id, $admin_id]);
                    echo json_encode(['success' => false, 'message' => 'Cannot cancel a completed or delivered order']);
                    exit();
                }
                // Block if any delivered quantity exists
                $agg = $db->prepare("SELECT COALESCE(SUM(CASE WHEN status='delivered' THEN COALESCE(replenished_quantity,0) ELSE 0 END),0) AS delivered_qty FROM deliveries WHERE order_id = ?");
                $agg->execute([$order_id]);
                $deliveredQty = (int)($agg->fetchColumn() ?? 0);
                if ($deliveredQty > 0) {
                    $log = $db->prepare("INSERT INTO sync_events(entity_type, entity_id, action, status, message, actor_id) VALUES('order', ?, 'cancel_attempt_blocked', 'blocked', 'Cancellation prevented due to delivered items', ?)");
                    $log->execute([$order_id, $admin_id]);
                    echo json_encode(['success' => false, 'message' => 'Cannot cancel order after successful delivery']);
                    exit();
                }
            }
            
            // Proceed with status update
            $order->id = $order_id;
            $order->confirmation_status = $new_status;
            if ($order->updateStatus()) {
                echo json_encode(['success' => true, 'message' => 'Order status updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update order status']);
            }
            exit();
            
        case 'create_delivery':
            $delivery->order_id = $_POST['order_id'];
            $delivery->status = 'pending';
            $delivery->delivery_date = date('Y-m-d H:i:s');
            if ($delivery->create()) {
                echo json_encode(['success' => true, 'message' => 'Delivery created successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to create delivery']);
            }
            exit();
            
        case 'update_delivery_status':
            // Validate allowed delivery statuses
            $incomingStatus = $_POST['status'] ?? '';
            $allowedDeliveryStatuses = ['pending','in_transit','delivered','completed','cancelled'];
            if (!in_array($incomingStatus, $allowedDeliveryStatuses, true)) {
                echo json_encode(['success' => false, 'message' => 'Invalid delivery status']);
                exit();
            }

            if (isset($_POST['delivery_id'])) {
                // Verify delivery exists and get related order
                $deliveryId = (int)$_POST['delivery_id'];
                $verifyStmt = $db->prepare("SELECT d.order_id, o.confirmation_status FROM deliveries d JOIN orders o ON o.id=d.order_id WHERE d.id = ?");
                $verifyStmt->execute([$deliveryId]);
                $row = $verifyStmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    echo json_encode(['success' => false, 'message' => 'Delivery not found']);
                    exit();
                }
                $orderId = (int)$row['order_id'];
                $orderStatusBefore = (string)$row['confirmation_status'];

                try {
                    $db->beginTransaction();

                    // Lock rows for consistency
                    $lockOrder = $db->prepare("SELECT confirmation_status FROM orders WHERE id = ? FOR UPDATE");
                    $lockOrder->execute([$orderId]);
                    $lockDelivery = $db->prepare("SELECT status FROM deliveries WHERE id = ? FOR UPDATE");
                    $lockDelivery->execute([$deliveryId]);

                    // Normalize delivery state: delivered -> completed
                    $nextDeliveryStatus = ($incomingStatus === 'delivered') ? 'completed' : $incomingStatus;
                    $updDel = $db->prepare("UPDATE deliveries SET status=? WHERE id=?");
                    $updDel->execute([$nextDeliveryStatus, $deliveryId]);

                    $orderStatusAfter = $orderStatusBefore;
                    $updatedOrder = false;
                    if ($incomingStatus === 'delivered') {
                        $updOrd = $db->prepare("UPDATE orders SET confirmation_status='completed', confirmation_date=NOW() WHERE id=? AND confirmation_status NOT IN ('completed','cancelled')");
                        $updOrd->execute([$orderId]);
                        $updatedOrder = $updOrd->rowCount() > 0;
                        $orderStatusAfter = $updatedOrder ? 'completed' : $orderStatusBefore;
                    } elseif ($incomingStatus === 'cancelled') {
                        $updOrd = $db->prepare("UPDATE orders SET confirmation_status='cancelled', confirmation_date=NOW() WHERE id=? AND confirmation_status NOT IN ('cancelled','completed')");
                        $updOrd->execute([$orderId]);
                        $updatedOrder = $updOrd->rowCount() > 0;
                        $orderStatusAfter = $updatedOrder ? 'cancelled' : $orderStatusBefore;
                    }

                    $db->commit();

                    // Audit logging (best-effort)
                    try {
                        $log = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message) VALUES (?,?,?,?,?,?,?,?,?,?)");
                        $log->execute([
                            'delivery_status_update',
                            'admin_ui',
                            'admin_system',
                            $orderId,
                            $deliveryId,
                            $orderStatusBefore,
                            $orderStatusAfter,
                            1,
                            'Delivery updated to ' . $nextDeliveryStatus . '; order ' . ($updatedOrder ? 'updated' : 'unchanged')
                        ]);
                    } catch (Throwable $e) {}

                    if ($incomingStatus === 'delivered') {
                        // Update inventory stock when delivery is marked as delivered/completed
                        try {
                            $delivery->autoUpdateInventoryOnCompletion($deliveryId);
                        } catch (Throwable $e) {
                            // Log error but don't fail the delivery update
                            error_log("Failed to update inventory on delivery completion: " . $e->getMessage());
                        }
                        
                        echo json_encode(['success' => true, 'message' => 'Delivery updated; order completed.', 'completed' => true]);
                        exit();
                    }

                    $msg = 'Delivery status updated to ' . $nextDeliveryStatus;
                    if ($incomingStatus === 'cancelled') { $msg .= '; order cancelled'; }
                    echo json_encode(['success' => true, 'message' => $msg]);
                } catch (Throwable $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    // Audit log error
                    try {
                        $log = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message) VALUES (?,?,?,?,?,?,?,?,?,?)");
                        $log->execute([
                            'delivery_status_update_error',
                            'admin_ui',
                            'admin_system',
                            $orderId ?? null,
                            $deliveryId,
                            $orderStatusBefore ?? null,
                            null,
                            0,
                            'Transaction failed: ' . $e->getMessage()
                        ]);
                    } catch (Throwable $ie) {}
                    echo json_encode(['success' => false, 'message' => 'Failed to update delivery/order status']);
                }
            } else if (isset($_POST['order_id'])) {
                // Update delivery status by order ID (transactional)
                $orderId = (int)$_POST['order_id'];

                try {
                    $db->beginTransaction();

                    // Lock order and related deliveries
                    $lockOrder = $db->prepare("SELECT confirmation_status FROM orders WHERE id = ? FOR UPDATE");
                    $lockOrder->execute([$orderId]);
                    $lockDeliveries = $db->prepare("SELECT id FROM deliveries WHERE order_id = ? FOR UPDATE");
                    $lockDeliveries->execute([$orderId]);

                    $orderStatusBefore = (string)($lockOrder->fetch(PDO::FETCH_ASSOC)['confirmation_status'] ?? '');

                    $nextDeliveryStatus = ($incomingStatus === 'delivered') ? 'completed' : $incomingStatus;
                    $updDelByOrder = $db->prepare("UPDATE deliveries SET status=? WHERE order_id=?");
                    $updDelByOrder->execute([$nextDeliveryStatus, $orderId]);

                    $orderStatusAfter = $orderStatusBefore;
                    $updatedOrder = false;
                    if ($incomingStatus === 'delivered') {
                        $updOrd = $db->prepare("UPDATE orders SET confirmation_status='completed', confirmation_date=NOW() WHERE id=? AND confirmation_status NOT IN ('completed','cancelled')");
                        $updOrd->execute([$orderId]);
                        $updatedOrder = $updOrd->rowCount() > 0;
                        $orderStatusAfter = $updatedOrder ? 'completed' : $orderStatusBefore;
                    } elseif ($incomingStatus === 'cancelled') {
                        $updOrd = $db->prepare("UPDATE orders SET confirmation_status='cancelled', confirmation_date=NOW() WHERE id=? AND confirmation_status NOT IN ('cancelled','completed')");
                        $updOrd->execute([$orderId]);
                        $updatedOrder = $updOrd->rowCount() > 0;
                        $orderStatusAfter = $updatedOrder ? 'cancelled' : $orderStatusBefore;
                    }

                    $db->commit();

                    // Audit logging (best-effort)
                    try {
                        $log = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message) VALUES (?,?,?,?,?,?,?,?,?,?)");
                        $log->execute([
                            'delivery_status_update',
                            'admin_ui',
                            'admin_system',
                            $orderId,
                            null,
                            $orderStatusBefore,
                            $orderStatusAfter,
                            1,
                            'Delivery statuses updated to ' . $nextDeliveryStatus . '; order ' . ($updatedOrder ? 'updated' : 'unchanged')
                        ]);
                    } catch (Throwable $e) {}

                    if ($incomingStatus === 'delivered') {
                        // Update inventory stock for all completed deliveries for this order
                        try {
                            $deliveriesStmt = $db->prepare("SELECT id FROM deliveries WHERE order_id = ? AND status = 'completed'");
                            $deliveriesStmt->execute([$orderId]);
                            while ($delRow = $deliveriesStmt->fetch(PDO::FETCH_ASSOC)) {
                                $delivery->autoUpdateInventoryOnCompletion($delRow['id']);
                            }
                        } catch (Throwable $e) {
                            // Log error but don't fail the delivery update
                            error_log("Failed to update inventory on delivery completion: " . $e->getMessage());
                        }
                        
                        echo json_encode(['success' => true, 'message' => 'Delivery updated; order completed.', 'completed' => true]);
                        exit();
                    }

                    $msg = 'Delivery status updated to ' . $nextDeliveryStatus;
                    if ($incomingStatus === 'cancelled') { $msg .= '; order cancelled'; }
                    echo json_encode(['success' => true, 'message' => $msg]);
                } catch (Throwable $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    try {
                        $log = $db->prepare("INSERT INTO sync_events (event_type, source_system, target_system, order_id, delivery_id, status_before, status_after, success, message) VALUES (?,?,?,?,?,?,?,?,?,?)");
                        $log->execute([
                            'delivery_status_update_error',
                            'admin_ui',
                            'admin_system',
                            $orderId,
                            null,
                            null,
                            null,
                            0,
                            'Transaction failed: ' . $e->getMessage()
                        ]);
                    } catch (Throwable $ie) {}
                    echo json_encode(['success' => false, 'message' => 'Failed to update delivery/order status']);
                }
            }
            exit();
            
        case 'get_notifications':
            // Get recent notifications for this admin
            $stmt = $db->prepare("SELECT * FROM notifications WHERE recipient_type = 'admin' AND recipient_id = ? ORDER BY sent_at DESC LIMIT 10");
            $stmt->execute([$admin_id]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'notifications' => $notifications]);
            exit();
            
        case 'mark_notification_read':
            $notification_id = $_POST['notification_id'];
            $stmt = $db->prepare("UPDATE notifications SET status = 'read' WHERE id = ? AND recipient_id = ?");
            $success = $stmt->execute([$notification_id, $admin_id]);
            
            echo json_encode(['success' => $success]);
            exit();
    }
}

// Get all orders with enhanced status mapping (admin view)
$stmt = $order->readAll();
$orders = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Map order status to Shopee-like workflow
    $workflow_status = '';
    switch ($row['confirmation_status']) {
        case 'pending':
            $workflow_status = 'to_pay';
            break;
        case 'confirmed':
            // Check if delivery exists
            $delivery_check = $db->prepare("SELECT status FROM deliveries WHERE order_id = ?");
            $delivery_check->execute([$row['id']]);
            $delivery_row = $delivery_check->fetch(PDO::FETCH_ASSOC);
            
            if (!$delivery_row) {
                $workflow_status = 'to_ship';
            } else {
                switch ($delivery_row['status']) {
                    case 'pending':
                        $workflow_status = 'to_ship';
                        break;
                    case 'in_transit':
                        $workflow_status = 'to_receive';
                        break;
                    case 'delivered':
                    case 'completed':
                        $workflow_status = 'completed';
                        break;
                }
            }
            break;
        case 'delivered':
            $workflow_status = 'completed';
            break;
        case 'completed':
            $workflow_status = 'completed';
            break;
        case 'cancelled':
            $workflow_status = 'cancelled';
            break;
    }
    
    $row['workflow_status'] = $workflow_status;
    $orders[] = $row;
}

// Get all deliveries (admin view)
$stmt = $delivery->readAll();
$deliveries = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $deliveries[] = $row;
}

// Calculate statistics
$total_orders = count($orders);
$to_pay = count(array_filter($orders, function($o) { return $o['workflow_status'] === 'to_pay'; }));
$to_ship = count(array_filter($orders, function($o) { return $o['workflow_status'] === 'to_ship'; }));
$to_receive = count(array_filter($orders, function($o) { return $o['workflow_status'] === 'to_receive'; }));
$completed = count(array_filter($orders, function($o) { return $o['workflow_status'] === 'completed'; }));
$cancelled = count(array_filter($orders, function($o) { return $o['workflow_status'] === 'cancelled'; }));

// Calculate total revenue from completed orders
$total_revenue = array_sum(array_map(function($o) {
    return $o['workflow_status'] === 'completed' ? $o['quantity'] * ($o['unit_price'] ?? 0) : 0;
}, $orders));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Management - Inventory System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
    <style>
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.875rem;
        }
        .status-to_pay { background: #fff3cd; color: #856404; }
        .status-to_ship { background: #cce5ff; color: #004085; }
        .status-to_receive { background: #e2e3e5; color: #383d41; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        
        .action-buttons .btn {
            margin: 2px;
        }
        
        .real-time-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #28a745;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
    </style>
</head>
<body>
    <?php include_once 'includes/header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include_once 'includes/sidebar.php'; ?>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <?php if (!empty($_SESSION['deliveries_clear_message'])): ?>
                    <div class="alert alert-<?= htmlspecialchars($_SESSION['deliveries_clear_message_type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($_SESSION['deliveries_clear_message']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php $_SESSION['deliveries_clear_message'] = null; $_SESSION['deliveries_clear_message_type'] = null; ?>
                <?php endif; ?>
                <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="bi bi-geo-alt me-2"></i>Deliveries
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-sm btn-outline-secondary me-2" onclick="refreshData()">
                            <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="if(confirm('Delete all delivery records? This cannot be undone.')) location.href='?action=clear_all'">
                            <i class="bi bi-trash me-1"></i>Clear All Deliveries
                        </button>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-2">
                        <div class="card text-white bg-warning mb-3">
                            <div class="card-body text-center">
                                <h6>To Pay</h6>
                                <h3><?php echo $to_pay; ?></h3>
                                <i class="bi bi-credit-card fs-4"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card text-white bg-info mb-3">
                            <div class="card-body text-center">
                                <h6>To Ship</h6>
                                <h3><?php echo $to_ship; ?></h3>
                                <i class="bi bi-box-seam fs-4"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card text-white bg-secondary mb-3">
                            <div class="card-body text-center">
                                <h6>To Receive</h6>
                                <h3><?php echo $to_receive; ?></h3>
                                <i class="bi bi-truck fs-4"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card text-white bg-success mb-3">
                            <div class="card-body text-center">
                                <h6>Completed</h6>
                                <h3><?php echo $completed; ?></h3>
                                <i class="bi bi-check-circle fs-4"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card text-white bg-danger mb-3">
                            <div class="card-body text-center">
                                <h6>Cancelled</h6>
                                <h3><?php echo $cancelled; ?></h3>
                                <i class="bi bi-x-circle fs-4"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card text-white bg-dark mb-3">
                            <div class="card-body text-center">
                                <h6>Revenue</h6>
                                <h5>₱<?php echo number_format($total_revenue, 2); ?></h5>
                                <i class="bi bi-currency-dollar fs-4"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Workflow Tabs -->
                <div class="card">
                    <div class="card-header">
                        <ul class="nav nav-tabs card-header-tabs" id="orderTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="all-tab" data-bs-toggle="tab" data-bs-target="#all" type="button" role="tab">
                                    All Orders <span class="badge bg-primary ms-1"><?php echo $total_orders; ?></span>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="to-pay-tab" data-bs-toggle="tab" data-bs-target="#to-pay" type="button" role="tab">
                                    To Pay <span class="badge bg-warning ms-1"><?php echo $to_pay; ?></span>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="to-ship-tab" data-bs-toggle="tab" data-bs-target="#to-ship" type="button" role="tab">
                                    To Ship <span class="badge bg-info ms-1"><?php echo $to_ship; ?></span>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="to-receive-tab" data-bs-toggle="tab" data-bs-target="#to-receive" type="button" role="tab">
                                    To Receive <span class="badge bg-secondary ms-1"><?php echo $to_receive; ?></span>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="completed-tab" data-bs-toggle="tab" data-bs-target="#completed" type="button" role="tab">
                                    Completed <span class="badge bg-success ms-1"><?php echo $completed; ?></span>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="cancelled-tab" data-bs-toggle="tab" data-bs-target="#cancelled" type="button" role="tab">
                                    Cancelled <span class="badge bg-danger ms-1"><?php echo $cancelled; ?></span>
                                </button>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body">
                        <div class="tab-content" id="orderTabContent">
                            
                            <!-- All Orders Tab -->
                            <div class="tab-pane fade show active" id="all" role="tabpanel">
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover" id="allOrdersTable">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Order ID</th>
                                                <th>Item</th>
                                                <th>Variation & Quantity</th>
                                                <th>Per Unit</th>
                                                <th>Total Amount</th>
                                                <th>Status</th>
                                                <th>Order Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($orders)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-4">
                                                    <div class="text-muted">
                                                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                                        No orders found
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($orders as $order_item): ?>
                                            <tr>
                                                <td><strong>#<?php echo $order_item['id']; ?></strong></td>
                                                <td><?php echo htmlspecialchars($order_item['item_name'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <?php if (!empty($order_item['variation'])): ?>
                                                            <span class="badge bg-info mb-1"><?= htmlspecialchars(formatVariationForDisplay($order_item['variation'])) ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted">No Variation</span>
                                                        <?php endif; ?>
                                                        <div class="mt-1">
                                                            <span class="badge bg-primary">Qty: <strong><?= $order_item['quantity'] ?></strong></span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($order_item['unit_type'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <strong class="text-success fs-6">₱<?php echo number_format($order_item['quantity'] * ($order_item['unit_price'] ?? 0), 2); ?></strong>
                                                        <small class="text-muted">(₱<?php echo number_format($order_item['unit_price'] ?? 0, 2); ?> × <?= $order_item['quantity'] ?>)</small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge status-badge status-<?php echo $order_item['workflow_status']; ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $order_item['workflow_status'])); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($order_item['order_date'])); ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-primary view-btn"
                                                        data-order-id="<?php echo $order_item['id']; ?>"
                                                        data-workflow-status="<?php echo $order_item['workflow_status']; ?>"
                                                        data-item="<?php echo htmlspecialchars($order_item['item_name'] ?? ($order_item['inventory_name'] ?? 'N/A')); ?>"
                                                        data-quantity="<?php echo $order_item['quantity']; ?>"
                                                        data-total="<?php echo number_format($order_item['quantity'] * ($order_item['unit_price'] ?? 0), 2); ?>">
                                                        View
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- To Pay Tab -->
                            <div class="tab-pane fade" id="to-pay" role="tabpanel">
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover" id="toPayTable">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Order ID</th>
                                                <th>Item</th>
                                                <th>Per Unit</th>
                                                <th>Variation</th>
                                                <th>Quantity</th>
                                                <th>Total Amount</th>
                                                <th>Order Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $to_pay_orders = array_filter($orders, function($order) {
                                                return $order['workflow_status'] === 'to_pay';
                                            });
                                            if (empty($to_pay_orders)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-4">
                                                    <div class="text-muted">
                                                        <i class="bi bi-credit-card fs-1 d-block mb-2"></i>
                                                        No orders waiting for payment
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($to_pay_orders as $order_item): ?>
                                            <tr>
                                                <td><strong>#<?php echo $order_item['id']; ?></strong></td>
                                                <td><?php echo htmlspecialchars($order_item['item_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($order_item['unit_type'] ?? 'N/A'); ?></td>
                                                <td><?php echo (isset($order_item['variation']) && $order_item['variation'] !== '') ? htmlspecialchars(formatVariationForDisplay($order_item['variation'])) : 'N/A'; ?></td>
                                                <td>
                                                    <span class="badge bg-primary"><?php echo $order_item['quantity']; ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <strong class="text-success fs-6">₱<?php echo number_format($order_item['quantity'] * ($order_item['unit_price'] ?? 0), 2); ?></strong>
                                                        <small class="text-muted">(₱<?php echo number_format($order_item['unit_price'] ?? 0, 2); ?> × <?= $order_item['quantity'] ?>)</small>
                                                    </div>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($order_item['order_date'])); ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-primary view-btn"
                                                        data-order-id="<?php echo $order_item['id']; ?>"
                                                        data-workflow-status="<?php echo $order_item['workflow_status']; ?>"
                                                        data-item="<?php echo htmlspecialchars($order_item['item_name'] ?? ($order_item['inventory_name'] ?? 'N/A')); ?>"
                                                        data-quantity="<?php echo $order_item['quantity']; ?>"
                                                        data-total="<?php echo number_format($order_item['quantity'] * ($order_item['unit_price'] ?? 0), 2); ?>">
                                                        View
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- To Ship Tab -->
                            <div class="tab-pane fade" id="to-ship" role="tabpanel">
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover" id="toShipTable">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Order ID</th>
                                                <th>Item</th>
                                                <th>Per Unit</th>
                                                <th>Variation</th>
                                                <th>Quantity</th>
                                                <th>Total Amount</th>
                                                <th>Order Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $to_ship_orders = array_filter($orders, function($order) {
                                                return $order['workflow_status'] === 'to_ship';
                                            });
                                            if (empty($to_ship_orders)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-4">
                                                    <div class="text-muted">
                                                        <i class="bi bi-box-seam fs-1 d-block mb-2"></i>
                                                        No orders ready to ship
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($to_ship_orders as $order_item): ?>
                                            <tr>
                                                <td><strong>#<?php echo $order_item['id']; ?></strong></td>
                                                <td><?php echo htmlspecialchars($order_item['item_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($order_item['unit_type'] ?? 'N/A'); ?></td>
                                                <td><?php echo (isset($order_item['variation']) && $order_item['variation'] !== '') ? htmlspecialchars(formatVariationForDisplay($order_item['variation'])) : 'N/A'; ?></td>

                                                <td>
                                                    <span class="badge bg-primary"><?php echo $order_item['quantity']; ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <strong class="text-success fs-6">₱<?php echo number_format($order_item['quantity'] * ($order_item['unit_price'] ?? 0), 2); ?></strong>
                                                        <small class="text-muted">(₱<?php echo number_format($order_item['unit_price'] ?? 0, 2); ?> × <?= $order_item['quantity'] ?>)</small>
                                                    </div>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($order_item['order_date'])); ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-primary view-btn"
                                                        data-order-id="<?php echo $order_item['id']; ?>"
                                                        data-workflow-status="<?php echo $order_item['workflow_status']; ?>"
                                                        data-item="<?php echo htmlspecialchars($order_item['item_name'] ?? ($order_item['inventory_name'] ?? 'N/A')); ?>"
                                                        data-quantity="<?php echo $order_item['quantity']; ?>"
                                                        data-total="<?php echo number_format($order_item['quantity'] * ($order_item['unit_price'] ?? 0), 2); ?>">
                                                        View
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- To Receive Tab -->
                            <div class="tab-pane fade" id="to-receive" role="tabpanel">
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover" id="toReceiveTable">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Order ID</th>
                                                <th>Item</th>
                                                <th>Per Unit</th>
                                                <th>Variation</th>
                                                <th>Quantity</th>
                                                <th>Total Amount</th>
                                                <th>Ship Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $to_receive_orders = array_filter($orders, function($order) {
                                                return $order['workflow_status'] === 'to_receive';
                                            });
                                            if (empty($to_receive_orders)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-4">
                                                    <div class="text-muted">
                                                        <i class="bi bi-truck fs-1 d-block mb-2"></i>
                                                        No orders in transit
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($to_receive_orders as $order_item): ?>
                                            <tr>
                                                <td><strong>#<?php echo $order_item['id']; ?></strong></td>
                                                <td><?php echo htmlspecialchars($order_item['item_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($order_item['unit_type'] ?? 'N/A'); ?></td>
                                                <td><?php echo (isset($order_item['variation']) && $order_item['variation'] !== '') ? htmlspecialchars(formatVariationForDisplay($order_item['variation'])) : 'N/A'; ?></td>

                                                <td>
                                                    <span class="badge bg-primary"><?php echo $order_item['quantity']; ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <strong class="text-success fs-6">₱<?php echo number_format($order_item['quantity'] * ($order_item['unit_price'] ?? 0), 2); ?></strong>
                                                        <small class="text-muted">(₱<?php echo number_format($order_item['unit_price'] ?? 0, 2); ?> × <?= $order_item['quantity'] ?>)</small>
                                                    </div>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($order_item['order_date'])); ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-primary view-btn"
                                                        data-order-id="<?php echo $order_item['id']; ?>"
                                                        data-workflow-status="<?php echo $order_item['workflow_status']; ?>"
                                                        data-item="<?php echo htmlspecialchars($order_item['item_name'] ?? ($order_item['inventory_name'] ?? 'N/A')); ?>"
                                                        data-quantity="<?php echo $order_item['quantity']; ?>"
                                                        data-total="<?php echo number_format($order_item['quantity'] * ($order_item['unit_price'] ?? 0), 2); ?>">
                                                        View
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Completed Tab -->
                            <div class="tab-pane fade" id="completed" role="tabpanel">
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover" id="completedTable">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Order ID</th>
                                                <th>Item</th>
                                                <th>Per Unit</th>
                                                <th>Variation</th>
                                                <th>Quantity</th>
                                                <th>Total Amount</th>
                                                <th>Completed Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $completed_orders = array_filter($orders, function($order) {
                                                return $order['workflow_status'] === 'completed';
                                            });
                                            if (empty($completed_orders)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-4">
                                                    <div class="text-muted">
                                                        <i class="bi bi-check-circle fs-1 d-block mb-2"></i>
                                                        No completed orders
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($completed_orders as $order_item): ?>
                                            <tr>
                                                <td><strong>#<?php echo $order_item['id']; ?></strong></td>
                                                <td><?php echo htmlspecialchars($order_item['inventory_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($order_item['unit_type'] ?? 'N/A'); ?></td>
                                                <td><?php echo (isset($order_item['variation']) && $order_item['variation'] !== '') ? htmlspecialchars(formatVariationForDisplay($order_item['variation'])) : 'N/A'; ?></td>

                                                <td>
                                                    <span class="badge bg-primary"><?php echo $order_item['quantity']; ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <strong class="text-success fs-6">₱<?php echo number_format($order_item['quantity'] * ($order_item['unit_price'] ?? 0), 2); ?></strong>
                                                        <small class="text-muted">(₱<?php echo number_format($order_item['unit_price'] ?? 0, 2); ?> × <?= $order_item['quantity'] ?>)</small>
                                                    </div>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($order_item['order_date'])); ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-primary view-btn"
                                                        data-order-id="<?php echo $order_item['id']; ?>"
                                                        data-workflow-status="<?php echo $order_item['workflow_status']; ?>"
                                                        data-item="<?php echo htmlspecialchars($order_item['item_name'] ?? ($order_item['inventory_name'] ?? 'N/A')); ?>"
                                                        data-quantity="<?php echo $order_item['quantity']; ?>"
                                                        data-total="<?php echo number_format($order_item['quantity'] * ($order_item['unit_price'] ?? 0), 2); ?>">
                                                        View
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Cancelled Tab -->
                            <div class="tab-pane fade" id="cancelled" role="tabpanel">
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover" id="cancelledTable">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>Order ID</th>
                                                <th>Item</th>
                                                <th>Per Unit</th>
                                                <th>Variation</th>
                                                <th>Quantity</th>
                                                <th>Total Amount</th>
                                                <th>Cancelled Date</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $cancelled_orders = array_filter($orders, function($order) {
                                                return $order['workflow_status'] === 'cancelled';
                                            });
                                            if (empty($cancelled_orders)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-4">
                                                    <div class="text-muted">
                                                        <i class="bi bi-x-circle fs-1 d-block mb-2"></i>
                                                        No cancelled orders
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php else: ?>
                                            <?php foreach ($cancelled_orders as $order_item): ?>
                                            <tr>
                                                <td><strong>#<?php echo $order_item['id']; ?></strong></td>
                                                <td><?php echo htmlspecialchars($order_item['inventory_name'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($order_item['unit_type'] ?? 'N/A'); ?></td>
                                                <td><?php echo (isset($order_item['variation']) && $order_item['variation'] !== '') ? htmlspecialchars(formatVariationForDisplay($order_item['variation'])) : 'N/A'; ?></td>

                                                <td>
                                                    <span class="badge bg-primary"><?php echo $order_item['quantity']; ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <strong class="text-success fs-6">₱<?php echo number_format($order_item['quantity'] * ($order_item['unit_price'] ?? 0), 2); ?></strong>
                                                        <small class="text-muted">(₱<?php echo number_format($order_item['unit_price'] ?? 0, 2); ?> × <?= $order_item['quantity'] ?>)</small>
                                                    </div>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($order_item['order_date'])); ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-primary view-btn"
                                                        data-order-id="<?php echo $order_item['id']; ?>"
                                                        data-workflow-status="<?php echo $order_item['workflow_status']; ?>"
                                                        data-item="<?php echo htmlspecialchars($order_item['item_name'] ?? ($order_item['inventory_name'] ?? 'N/A')); ?>"
                                                        data-quantity="<?php echo $order_item['quantity']; ?>"
                                                        data-total="<?php echo number_format($order_item['quantity'] * ($order_item['unit_price'] ?? 0), 2); ?>">
                                                        View
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>


            </main>
        </div>
    </div>

    <!-- View Order Modal -->
    <div class="modal fade" id="viewOrderModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Order #<span id="viewOrderId"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2"><strong>Item:</strong> <span id="viewItem"></span></div>
                    <div class="mb-2"><strong>Quantity:</strong> <span id="viewQuantity"></span></div>
                    <div class="mb-2"><strong>Total:</strong> ₱<span id="viewTotal"></span></div>
                    <div class="mb-2"><strong>Status:</strong> <span id="viewStatusBadge"></span></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // View button handler: populate and show modal
            $(document).on('click', '.view-btn', function() {
                const $btn = $(this);
                const orderId = $btn.data('order-id');
                const item = $btn.data('item');
                const quantity = $btn.data('quantity');
                const total = $btn.data('total');
                const status = $btn.data('workflow-status');

                $('#viewOrderId').text(orderId);
                $('#viewItem').text(item);
                $('#viewQuantity').text(quantity);
                $('#viewTotal').text(total);
                $('#viewStatusBadge').html(renderStatusBadge(status));

                const modal = new bootstrap.Modal(document.getElementById('viewOrderModal'));
                modal.show();
            });

            // Initialize DataTables for all tables
            const tables = [
                { id: '#allOrdersTable', orderColumn: 6 },
                { id: '#toPayTable', orderColumn: 6 },
                { id: '#toShipTable', orderColumn: 6 },
                { id: '#toReceiveTable', orderColumn: 6 },
                { id: '#completedTable', orderColumn: 6 },
                { id: '#cancelledTable', orderColumn: 6 }
            ];
            
            tables.forEach(function(table) {
                const $table = $(table.id);
                if ($table.length > 0 && $table.find('tbody tr').length > 0 && !$table.find('tbody tr td[colspan]').length) {
                    $table.DataTable({
                        responsive: true,
                        pageLength: 10,
                        order: [[table.orderColumn, 'desc']],
                        language: {
                            emptyTable: "No orders available",
                            zeroRecords: "No matching orders found"
                        },
                        columnDefs: [
                            { orderable: false, targets: [] }
                        ]
                    });
                }
            });
            
            // Auto-refresh every 30 seconds
            setInterval(refreshData, 30000);
            
            // Load notifications on page load
            loadNotifications();
            
            // Poll for new notifications every 10 seconds
            setInterval(loadNotifications, 10000);
        });

        function refreshData() {
            location.reload();
        }
        
        function refreshOrders() {
            location.reload();
        }


        function confirmPayment(orderId) {
            if (confirm('Confirm that payment has been received for this order?')) {
                $.post('deliveries.php', {
                    action: 'update_order_status',
                    order_id: orderId,
                    status: 'confirmed'
                }, function(response) {
                    if (response.success) {
                        showNotification('Payment confirmed successfully!', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showNotification('Error: ' + response.message, 'error');
                    }
                }, 'json');
            }
        }

        function shipOrder(orderId) {
            if (confirm('Mark this order as shipped?')) {
                $.post('deliveries.php', {
                    action: 'create_delivery',
                    order_id: orderId
                }, function(response) {
                    if (response.success) {
                        // Update delivery status to in_transit
                        $.post('deliveries.php', {
                            action: 'update_delivery_status',
                            order_id: orderId,
                            status: 'in_transit'
                        }, function(deliveryResponse) {
                            showNotification('Order shipped successfully!', 'success');
                            setTimeout(() => location.reload(), 1000);
                        }, 'json');
                    } else {
                        showNotification('Error: ' + response.message, 'error');
                    }
                }, 'json');
            }
        }

        function markDelivered(orderId) {
            if (confirm('Mark this order as delivered?')) {
                $.post('deliveries.php', {
                    action: 'update_delivery_status',
                    order_id: orderId,
                    status: 'delivered'
                }, function(response) {
                    if (response.success) {
                        showNotification('Order marked as delivered!', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showNotification('Error: ' + response.message, 'error');
                    }
                }, 'json');
            }
        }

        function cancelOrder(orderId) {
            const reason = prompt('Please provide a reason for cancellation:');
            if (reason) {
                $.post('deliveries.php', {
                    action: 'update_order_status',
                    order_id: orderId,
                    status: 'cancelled'
                }, function(response) {
                    if (response.success) {
                        showNotification('Order cancelled successfully!', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showNotification('Error: ' + response.message, 'error');
                    }
                }, 'json');
            }
        }

        function viewOrderDetails(orderId) {
            // Open order details in a modal or new page
            window.open('order_details.php?id=' + orderId, '_blank');
        }

        function loadNotifications() {
            $.post('deliveries.php', {
                action: 'get_notifications'
            }, function(response) {
                if (response.success && response.notifications) {
                    updateNotificationDropdown(response.notifications);
                }
            }, 'json').fail(function() {
                console.log('Failed to load notifications');
            });
        }
        
        function updateNotificationDropdown(notifications) {
            const $notificationList = $('#notificationList');
            const $notificationBadge = $('#notificationBadge');
            
            if (notifications.length === 0) {
                $notificationList.html('<li><span class="dropdown-item-text text-muted">No new notifications</span></li>');
                $notificationBadge.hide();
                return;
            }
            
            // Count unread notifications
            const unreadCount = notifications.filter(n => n.status !== 'read').length;
            
            if (unreadCount > 0) {
                $notificationBadge.text(unreadCount).show();
            } else {
                $notificationBadge.hide();
            }
            
            // Build notification list
            let notificationHtml = '';
            notifications.forEach(function(notification) {
                const isUnread = notification.status !== 'read';
                const timeAgo = formatTimeAgo(notification.sent_at);
                
                notificationHtml += `
                    <li>
                        <a class="dropdown-item ${isUnread ? 'fw-bold' : ''}" href="#" 
                           onclick="markNotificationRead(${notification.id})">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div class="small">${notification.message}</div>
                                    <div class="text-muted small">${timeAgo}</div>
                                </div>
                                ${isUnread ? '<span class="badge bg-primary rounded-pill">New</span>' : ''}
                            </div>
                        </a>
                    </li>
                `;
            });
            
            $notificationList.html(notificationHtml);
        }
        
        function markNotificationRead(notificationId) {
            $.post('deliveries.php', {
                action: 'mark_notification_read',
                notification_id: notificationId
            }, function(response) {
                if (response.success) {
                    loadNotifications(); // Refresh notifications
                }
            }, 'json');
        }
        
        function formatTimeAgo(dateString) {
            const now = new Date();
            const date = new Date(dateString);
            const diffInSeconds = Math.floor((now - date) / 1000);
            
            if (diffInSeconds < 60) {
                return 'Just now';
            } else if (diffInSeconds < 3600) {
                const minutes = Math.floor(diffInSeconds / 60);
                return `${minutes} minute${minutes > 1 ? 's' : ''} ago`;
            } else if (diffInSeconds < 86400) {
                const hours = Math.floor(diffInSeconds / 3600);
                return `${hours} hour${hours > 1 ? 's' : ''} ago`;
            } else {
                const days = Math.floor(diffInSeconds / 86400);
                return `${days} day${days > 1 ? 's' : ''} ago`;
            }
        }

        function renderStatusBadge(status) {
            const normalized = (status || '').toString().toLowerCase();
            const label = normalized.replace('_', ' ');
            return `<span class="badge status-badge status-${normalized}">${label.charAt(0).toUpperCase() + label.slice(1)}</span>`;
        }

        function showNotification(message, type) {
            const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
            const notification = `
                <div class="alert ${alertClass} alert-dismissible fade show position-fixed" 
                     style="top: 20px; right: 20px; z-index: 9999;" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            $('body').append(notification);
            
            // Auto-remove after 3 seconds
            setTimeout(() => {
                $('.alert').fadeOut();
            }, 3000);
        }
    </script>
    
    <!-- Notification System -->
    <script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
    <script src="assets/js/notifications.js"></script>
</body>
</html>