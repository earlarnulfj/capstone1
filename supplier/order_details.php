<?php
session_start();
require_once '../config/database.php';
require_once '../models/order.php';
require_once '../models/delivery.php';

// Check if user is logged in as supplier
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'supplier') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();
// Guard against DB connection issues
if (!$db) {
    header("Location: deliveries.php?error=db_unavailable");
    exit();
}
$order = new Order($db);
$delivery = new Delivery($db);

// Sanitize and validate order id (prevent injection and type issues)
$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$order_id) {
    header("Location: deliveries.php");
    exit();
}

// Get order details
try {
    $order->id = $order_id;
    if (!$order->readOne()) {
        header("Location: deliveries.php?error=order_not_found");
        exit();
    }
} catch (Throwable $e) {
    header("Location: deliveries.php?error=order_read_failed");
    exit();
}

// Validate supplier ownership (cast to int to avoid type mismatches)
$supplier_id = (int)($_SESSION['user_id'] ?? 0);
if (isset($order->supplier_id) && (int)$order->supplier_id !== $supplier_id) {
    header("Location: deliveries.php?error=not_allowed");
    exit();
}

// Fetch all order details matching orders.php structure
$item_name = null;
$inventory_id = null;
$category = null;
$unit_price = null;
$admin_name = null;
$admin_id = null;
$admin_email = null;
try {
    $detailsStmt = $db->prepare(
        "SELECT o.*, 
                i.name AS inventory_name, 
                i.category,
                i.unit_price AS inventory_unit_price, 
                o.unit_price AS order_unit_price, 
                u.username AS user_name,
                u.id AS user_id,
                u.email AS user_email
         FROM orders o
         LEFT JOIN inventory i ON o.inventory_id = i.id
         LEFT JOIN users u ON o.user_id = u.id
         WHERE o.id = :id
         LIMIT 1"
    );
    $detailsStmt->bindValue(':id', $order_id, PDO::PARAM_INT);
    $detailsStmt->execute();
    $detailsRow = $detailsStmt->fetch(PDO::FETCH_ASSOC);
    if ($detailsRow) {
        $item_name = $detailsRow['inventory_name'] ?? null;
        $inventory_id = $detailsRow['inventory_id'] ?? null;
        $category = $detailsRow['category'] ?? null;
        // Prefer per-order unit price if set; fallback to inventory unit price
        if (isset($detailsRow['order_unit_price']) && $detailsRow['order_unit_price'] !== null) {
            $unit_price = (float)$detailsRow['order_unit_price'];
        } elseif (isset($detailsRow['inventory_unit_price']) && $detailsRow['inventory_unit_price'] !== null) {
            $unit_price = (float)$detailsRow['inventory_unit_price'];
        } else {
            $unit_price = null;
        }
        // Admin info - user_id refers to the admin who created the order
        $admin_name = $detailsRow['user_name'] ?? 'N/A';
        $admin_id = $detailsRow['user_id'] ?? null;
        $admin_email = $detailsRow['user_email'] ?? 'N/A';
    }
} catch (Throwable $e) {
    // Keep defaults if lookup fails
}

// Get delivery details if exists (safe query by order_id)
$delivery_details = null;
try {
    $stmt = $db->prepare("SELECT * FROM deliveries WHERE order_id = :order_id ORDER BY delivery_date DESC, id DESC LIMIT 1");
    $stmt->bindValue(':order_id', $order_id, PDO::PARAM_INT);
    $stmt->execute();
    $delivery_details = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $delivery_details = null;
}

// Allowed statuses for consistency
$allowedDeliveryStatuses = ['pending','in_transit','delivered','cancelled'];
$allowedOrderStatuses = ['pending','confirmed','cancelled','completed'];
// Use strict comparison for status checks
$deliveryStatus = ($delivery_details && isset($delivery_details['status']) && in_array($delivery_details['status'], $allowedDeliveryStatuses, true)) ? $delivery_details['status'] : null;

// Get supplier information
$supplier_name = $_SESSION['username'] ?? 'Supplier';

// Create PDO connection for sidebar compatibility
$pdo = $db;

// ====== Helper function for variation display (matching admin/orders.php) ======
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

// Get the ordered variation from the order
$ordered_variation = isset($order->variation) ? trim($order->variation) : '';
$formatted_variation = formatVariationForDisplay($ordered_variation);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Details #<?php echo (int)$order->id; ?> - <?php echo htmlspecialchars($supplier_name, ENT_QUOTES, 'UTF-8'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .sidebar {
            min-height: 100vh;
        }
        .nav-link {
            color: #212529;
            transition: all 0.3s;
        }
        .nav-link:hover, .nav-link.active {
            color: #0d6efd;
            background-color: rgba(13, 110, 253, 0.08);
            border-radius: 5px;
        }
        .card {
            border: none;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.875rem;
        }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-confirmed { background: #d4edda; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        .status-in_transit { background: #cce5ff; color: #004085; }
        .status-delivered { background: #d4edda; color: #155724; }
        .status-completed { background: #e8f5e9; color: #1e7e34; }
        
        .main-content {
            padding: 2rem;
            background-color: #f8f9fa;
            min-height: 100vh;
        }
        
        .page-header {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .page-header h2 {
            margin: 0;
            color: #2c3e50;
            font-weight: 600;
        }
        
        /* Enhanced product and customer info styling (matching orders.php) */
        .product-details, .customer-info, .order-id-cell {
            line-height: 1.4;
        }

        .product-name, .customer-name {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 4px;
        }

        .product-meta, .customer-info small {
            display: block;
            color: #718096;
            font-size: 0.8rem;
        }

        .order-id-cell strong {
            font-size: 1.1rem;
            font-weight: 700;
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
                    <h2><i class="bi bi-file-text me-2"></i>Order Details #<?php echo (int)$order->id; ?></h2>
                    <div class="btn-toolbar">
                        <a href="orders.php" class="btn btn-outline-secondary me-2">
                            <i class="bi bi-arrow-left me-1"></i>Back to Orders
                        </a>
                        <a href="deliveries.php?order_id=<?php echo urlencode((string)$order->id); ?>" class="btn btn-outline-primary me-2">
                            <i class="bi bi-truck me-1"></i>View Delivery
                        </a>
                        <button type="button" class="btn btn-primary" onclick="window.print()">
                            <i class="bi bi-printer me-1"></i>Print
                        </button>
                    </div>
                </div>

                <div class="row">
                    <!-- Order Information -->
                    <div class="col-md-8">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-info-circle me-2"></i>Order Information
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-borderless">
                                            <tr>
                                                <td class="text-end"><strong>Order ID:</strong></td>
                                                <td class="order-id-cell">
                                                    <strong>#<?php echo (int)$order->id; ?></strong>
                                                    <small class="d-block text-muted">
                                                        <?php echo $order->order_date ? date('M d', strtotime($order->order_date)) : 'N/A'; ?>
                                                    </small>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="text-end"><strong>Product Details:</strong></td>
                                                <td class="product-details">
                                                    <div class="product-name"><?php echo htmlspecialchars($item_name ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></div>
                                                    <small class="product-meta">
                                                        ID: <?php echo $inventory_id ?? 'N/A'; ?>
                                                        <?php if (!empty($category)): ?>
                                                        | <?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>
                                                        <?php endif; ?>
                                                    </small>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="text-end"><strong>Unit Type:</strong></td>
                                                <td><?php echo htmlspecialchars($order->unit_type ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-end"><strong>Variation:</strong></td>
                                                <td>
                                                    <?= htmlspecialchars(formatVariationForDisplay($ordered_variation ?? 'N/A')) ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="text-end"><strong>Customer Info:</strong></td>
                                                <td class="customer-info">
                                                    <div class="customer-name"><?php echo htmlspecialchars($admin_name, ENT_QUOTES, 'UTF-8'); ?></div>
                                                    <small>
                                                        ID: <?php echo $admin_id ?? 'N/A'; ?><br>
                                                        <?php echo htmlspecialchars($admin_email, ENT_QUOTES, 'UTF-8'); ?>
                                                    </small>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-borderless">
                                            <tr>
                                                <td class="text-end"><strong>Quantity:</strong></td>
                                                <td><strong><?php echo (int)($order->quantity ?? 0); ?></strong></td>
                                            </tr>
                                            <tr>
                                                <td class="text-end"><strong>Total Price:</strong></td>
                                                <td><strong>₱<?php echo number_format(((float)($order->quantity ?? 0)) * ((float)($unit_price ?? 0)), 2); ?></strong></td>
                                            </tr>
                                            <tr>
                                                <td class="text-end"><strong>Order Date:</strong></td>
                                                <td><?php echo $order->order_date ? date('M d, Y H:i', strtotime($order->order_date)) : 'Not set'; ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-end"><strong>Status:</strong></td>
                                                <td>
                                                    <?php $orderStatus = in_array($order->confirmation_status, $allowedOrderStatuses, true) ? $order->confirmation_status : 'pending'; ?>
                                                    <span class="status-badge status-<?php echo htmlspecialchars($orderStatus, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <?php echo ucfirst($orderStatus); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php if ($order->confirmation_date): ?>
                                            <tr>
                                                <td class="text-end"><strong>Confirmed Date:</strong></td>
                                                <td><?php echo date('M d, Y H:i', strtotime($order->confirmation_date)); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Delivery Information -->
                        <?php if ($delivery_details): ?>
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-truck me-2"></i>Delivery Information
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-borderless">
                                            <tr>
                                                <td class="text-end"><strong>Delivery ID:</strong></td>
                                                <td>#<?php echo isset($delivery_details['id']) ? (int)$delivery_details['id'] : 0; ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-end"><strong>Status:</strong></td>
                                                <td>
                                                    <?php $deliveryStatus = ($delivery_details && isset($delivery_details['status']) && in_array($delivery_details['status'], $allowedDeliveryStatuses, true)) ? $delivery_details['status'] : 'pending'; ?>
                                                    <span class="status-badge status-<?php echo htmlspecialchars(str_replace(' ', '_', $deliveryStatus), ENT_QUOTES, 'UTF-8'); ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $deliveryStatus)); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="text-end"><strong>Delivery Date:</strong></td>
                                                <td>
                                                    <?php 
                                                    if (!empty($delivery_details['delivery_date'])) {
                                                        echo date('M j, Y g:i A', strtotime($delivery_details['delivery_date']));
                                                    } else {
                                                        echo 'Not set';
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-borderless">
                                            <tr>
                                                <td class="text-end"><strong>Replenished Qty:</strong></td>
                                                <td><?php echo isset($delivery_details['replenished_quantity']) ? (int)$delivery_details['replenished_quantity'] : 'N/A'; ?></td>
                                            </tr>
                                            <tr>
                                                <td class="text-end"><strong>Location:</strong></td>
                                                <td>
                                                    <?php 
                                                    $latRaw = $delivery_details['latitude'] ?? null;
                                                    $lngRaw = $delivery_details['longitude'] ?? null;
                                                    $latOk = isset($latRaw) && $latRaw !== '' && is_numeric($latRaw);
                                                    $lngOk = isset($lngRaw) && $lngRaw !== '' && is_numeric($lngRaw);
                                                    if ($latOk && $lngOk) {
                                                        $lat = number_format((float)$latRaw, 6, '.', '');
                                                        $lng = number_format((float)$lngRaw, 6, '.', '');
                                                        echo htmlspecialchars($lat, ENT_QUOTES, 'UTF-8') . ', ' . htmlspecialchars($lng, ENT_QUOTES, 'UTF-8');
                                                    } else {
                                                        echo 'Store Pickup';
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td colspan="2">
                                                    <a href="deliveries.php?order_id=<?php echo urlencode((string)$order->id); ?>" class="btn btn-outline-primary btn-sm">
                                                        <i class="bi bi-box-arrow-up-right me-1"></i>Open in Deliveries
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Order Timeline -->
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-clock-history me-2"></i>Order Timeline
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="timeline">
                                    <div class="timeline-item">
                                        <div class="timeline-marker bg-primary"></div>
                                        <div class="timeline-content">
                                            <h6 class="timeline-title">Order Placed</h6>
                                            <p class="timeline-text">
                                                <?php 
                                                if (!empty($order->order_date)) {
                                                    echo date('M j, Y g:i A', strtotime($order->order_date));
                                                } else {
                                                    echo 'Not set';
                                                }
                                                ?>
                                            </p>
                                        </div>
                                    </div>

                                    <?php if ($order->confirmation_status === 'confirmed' || $order->confirmation_status === 'cancelled'): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-marker <?php echo $order->confirmation_status === 'confirmed' ? 'bg-success' : 'bg-danger'; ?>"></div>
                                        <div class="timeline-content">
                                            <h6 class="timeline-title">
                                                <?php echo $order->confirmation_status === 'confirmed' ? 'Payment Confirmed' : 'Order Cancelled'; ?>
                                            </h6>
                                            <p class="timeline-text">
                                                <?php 
                                                if ($order->confirmation_date) {
                                                    echo date('M j, Y g:i A', strtotime($order->confirmation_date));
                                                } else {
                                                    echo 'Date not recorded';
                                                }
                                                ?>
                                            </p>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <style>
        .timeline {
            position: relative;
            padding-left: 30px;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #dee2e6;
        }
        .timeline-item {
            position: relative;
            margin-bottom: 20px;
        }
        .timeline-marker {
            position: absolute;
            left: -22px;
            top: 0;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 2px solid white;
        }
        .timeline-content {
            padding-left: 20px;
        }
        .timeline-title {
            margin-bottom: 5px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .timeline-text {
            margin-bottom: 0;
            font-size: 0.8rem;
            color: #6c757d;
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>