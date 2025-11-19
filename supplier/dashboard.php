<?php
session_start();
require_once '../config/database.php';
require_once '../models/user.php';
require_once '../models/notification.php';

// Check if user is logged in as supplier
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'supplier') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();
$pdo = $db; // For sidebar compatibility
$notification = new Notification($db);

// Get supplier information
$supplier_id = $_SESSION['user_id'];
$supplier_name = $_SESSION['username'];

// Get unread notification count
try {
    $unread_notifications = (int)$notification->getUnreadCount('supplier', (int)$supplier_id);
} catch (Exception $e) {
    $unread_notifications = 0;
}

// Get real statistics from database
try {
    // Total orders for this supplier
    $query = "SELECT COUNT(*) as total FROM orders WHERE supplier_id = :supplier_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':supplier_id', $supplier_id);
    $stmt->execute();
    $total_orders = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Pending orders for this supplier
    $query = "SELECT COUNT(*) as total FROM orders WHERE supplier_id = :supplier_id AND confirmation_status = 'pending'";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':supplier_id', $supplier_id);
    $stmt->execute();
    $pending_orders = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Completed orders for this supplier
    $query = "SELECT COUNT(*) as total FROM orders WHERE supplier_id = :supplier_id AND confirmation_status = 'confirmed'";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':supplier_id', $supplier_id);
    $stmt->execute();
    $completed_orders = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Total products for this supplier
    $query = "SELECT COUNT(*) as total FROM inventory WHERE supplier_id = :supplier_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':supplier_id', $supplier_id);
    $stmt->execute();
    $total_products = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get recent activity (last 5 orders)
    $query = "SELECT o.*, i.name as item_name, o.order_date, o.confirmation_status 
              FROM orders o 
              LEFT JOIN inventory i ON o.inventory_id = i.id 
              WHERE o.supplier_id = :supplier_id 
              ORDER BY o.order_date DESC 
              LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':supplier_id', $supplier_id);
    $stmt->execute();
    $recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle Export Request
    if (isset($_GET['export']) && $_GET['export'] === 'dashboard') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="supplier_dashboard_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Add BOM for UTF-8 Excel compatibility
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Write header
        fputcsv($output, ['Supplier Dashboard Export - ' . date('Y-m-d H:i:s')]);
        fputcsv($output, ['Supplier: ' . $supplier_name]);
        fputcsv($output, []); // Empty row
        
        // Write statistics
        fputcsv($output, ['Statistics']);
        fputcsv($output, ['Metric', 'Value']);
        fputcsv($output, ['Total Orders', $total_orders]);
        fputcsv($output, ['Pending Orders', $pending_orders]);
        fputcsv($output, ['Completed Orders', $completed_orders]);
        fputcsv($output, ['Total Products', $total_products]);
        fputcsv($output, []); // Empty row
        
        // Write recent orders
        fputcsv($output, ['Recent Orders']);
        fputcsv($output, ['Order ID', 'Item Name', 'Quantity', 'Status', 'Order Date']);
        
        if (!empty($recent_orders)) {
            foreach ($recent_orders as $order) {
                fputcsv($output, [
                    $order['id'],
                    $order['item_name'] ?? 'Unknown Item',
                    $order['quantity'],
                    ucfirst($order['confirmation_status']),
                    date('Y-m-d H:i:s', strtotime($order['order_date']))
                ]);
            }
        } else {
            fputcsv($output, ['No recent orders']);
        }
        
        fclose($output);
        exit;
    }

} catch (Exception $e) {
    // Fallback to zero values if queries fail
    $total_orders = 0;
    $pending_orders = 0;
    $completed_orders = 0;
    $total_products = 0;
    $recent_orders = [];
    
    // If export was requested, still try to export
    if (isset($_GET['export']) && $_GET['export'] === 'dashboard') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="supplier_dashboard_' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, ['Error: Could not fetch dashboard data']);
        fputcsv($output, ['Message: ' . $e->getMessage()]);
        fclose($output);
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Dashboard - <?php echo htmlspecialchars($supplier_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary-color: #64748b;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --light-bg: #f8fafc;
            --card-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --card-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        /* Use global fonts from ../assets/css/style.css */
        
        body {
            min-height: 100vh;
        }
        
        .main-content {
            background: var(--light-bg);
            min-height: 100vh;
            border-radius: 20px 0 0 0;
            margin-left: 0;
        }
        
        .sidebar {
            min-height: 100vh;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }
        
        .welcome-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }
        
        .welcome-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(30px, -30px);
        }
        
        .stat-card {
            background: white;
            border: none;
            border-radius: 15px;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
            overflow: hidden;
            position: relative;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }
        
        .stat-card .card-body {
            padding: 1.5rem;
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            margin-bottom: 1rem;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: var(--secondary-color);
            font-weight: 500;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .quick-actions {
            background: white;
            border-radius: 15px;
            box-shadow: var(--card-shadow);
            padding: 1.5rem;
        }
        
        .action-btn {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem;
            text-decoration: none;
            color: #334155;
            transition: all 0.3s ease;
            display: block;
            margin-bottom: 1rem;
        }
        
        .action-btn:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
            transform: translateX(5px);
            text-decoration: none;
        }
        
        .action-btn i {
            font-size: 1.25rem;
            margin-right: 0.75rem;
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 1.5rem;
        }
        
        .bg-primary-gradient { background: linear-gradient(135deg, var(--primary-color), var(--primary-dark)); }
        .bg-success-gradient { background: linear-gradient(135deg, var(--success-color), #059669); }
        .bg-warning-gradient { background: linear-gradient(135deg, var(--warning-color), #d97706); }
        .bg-danger-gradient { background: linear-gradient(135deg, var(--danger-color), #dc2626); }
        .bg-info-gradient { background: linear-gradient(135deg, #17a2b8, #138496); }
    </style>
</head>
<body>
<?php include_once 'includes/header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include_once 'includes/sidebar.php'; ?>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 main-content p-4">
                <!-- Welcome Header -->
                <div class="welcome-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h1 class="h2 mb-2">
                                <i class="bi bi-speedometer2 me-2"></i>Dashboard
                            </h1>
                            <p class="mb-0 opacity-75">
                                Welcome back, <strong><?php echo htmlspecialchars($supplier_name); ?></strong>! 
                                Your supplier account is active and ready for business.
                            </p>
                        </div>
                        <div class="col-auto">
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-light btn-sm" id="exportBtn">
                                    <i class="bi bi-download me-1"></i>Export
                                </button>
                                <a href="notifications.php" class="btn btn-light btn-sm position-relative">
                                    <i class="bi bi-bell me-1"></i>Notifications
                                    <?php if ($unread_notifications > 0): ?>
                                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                            <?php echo $unread_notifications > 99 ? '99+' : $unread_notifications; ?>
                                            <span class="visually-hidden">unread notifications</span>
                                        </span>
                                    <?php endif; ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="stat-icon bg-primary-gradient">
                                    <i class="bi bi-box-seam"></i>
                                </div>
                                <div class="stat-number"><?php echo $total_orders; ?></div>
                                <div class="stat-label">Total Orders</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="stat-icon bg-warning-gradient">
                                    <i class="bi bi-clock"></i>
                                </div>
                                <div class="stat-number"><?php echo $pending_orders; ?></div>
                                <div class="stat-label">Pending Orders</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="stat-icon bg-success-gradient">
                                    <i class="bi bi-check-circle"></i>
                                </div>
                                <div class="stat-number"><?php echo $completed_orders; ?></div>
                                <div class="stat-label">Completed Orders</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card stat-card h-100">
                            <div class="card-body">
                                <div class="stat-icon bg-info-gradient">
                                    <i class="bi bi-box"></i>
                                </div>
                                <div class="stat-number"><?php echo $total_products; ?></div>
                                <div class="stat-label">My Products</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card stat-card">
                            <div class="card-body">
                                <h5 class="card-title mb-3">
                                    <i class="bi bi-lightning-charge me-2"></i>Quick Actions
                                </h5>
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <a href="products.php" class="btn btn-outline-primary w-100 h-100 d-flex flex-column align-items-center justify-content-center p-3">
                                            <i class="bi bi-plus-circle mb-2" style="font-size: 2rem;"></i>
                                            <span>Add Product</span>
                                        </a>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <a href="orders.php" class="btn btn-outline-warning w-100 h-100 d-flex flex-column align-items-center justify-content-center p-3">
                                            <i class="bi bi-eye mb-2" style="font-size: 2rem;"></i>
                                            <span>View Orders</span>
                                        </a>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <a href="chat.php" class="btn btn-outline-success w-100 h-100 d-flex flex-column align-items-center justify-content-center p-3">
                                            <i class="bi bi-chat-dots mb-2" style="font-size: 2rem;"></i>
                                            <span>Messages</span>
                                        </a>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <a href="profile.php" class="btn btn-outline-info w-100 h-100 d-flex flex-column align-items-center justify-content-center p-3">
                                            <i class="bi bi-person-gear mb-2" style="font-size: 2rem;"></i>
                                            <span>Settings</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="row">
                    <div class="col-12">
                        <div class="card stat-card">
                            <div class="card-body">
                                <h5 class="card-title mb-3">
                                    <i class="bi bi-activity me-2"></i>Recent Activity
                                </h5>
                                <div class="list-group list-group-flush">
                                    <?php if (!empty($recent_orders)): ?>
                                        <?php foreach ($recent_orders as $order): ?>
                                            <div class="list-group-item border-0 px-0">
                                                <div class="d-flex align-items-center">
                                                    <div class="stat-icon <?php echo $order['confirmation_status'] === 'confirmed' ? 'bg-success-gradient' : ($order['confirmation_status'] === 'pending' ? 'bg-warning-gradient' : 'bg-danger-gradient'); ?> me-3" style="width: 40px; height: 40px; font-size: 1rem;">
                                                        <i class="bi <?php echo $order['confirmation_status'] === 'confirmed' ? 'bi-check-circle' : ($order['confirmation_status'] === 'pending' ? 'bi-clock' : 'bi-x-circle'); ?>"></i>
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <h6 class="mb-1">Order #<?php echo $order['id']; ?> - <?php echo htmlspecialchars($order['item_name'] ?? 'Unknown Item'); ?></h6>
                                                        <small class="text-muted">
                                                            <?php echo ucfirst($order['confirmation_status']); ?> • 
                                                            Qty: <?php echo $order['quantity']; ?> • 
                                                            <?php echo date('M j, Y', strtotime($order['order_date'])); ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="list-group-item border-0 px-0">
                                            <div class="d-flex align-items-center">
                                                <div class="stat-icon bg-secondary me-3" style="width: 40px; height: 40px; font-size: 1rem;">
                                                    <i class="bi bi-inbox"></i>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <h6 class="mb-1">No recent activity</h6>
                                                    <small class="text-muted">Start by adding products or receiving orders</small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                        </div>
                    </div>
                </div>


            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Export button functionality
        document.addEventListener('DOMContentLoaded', function() {
            const exportBtn = document.getElementById('exportBtn');
            if (exportBtn) {
                exportBtn.addEventListener('click', function() {
                    // Show loading state
                    const originalText = exportBtn.innerHTML;
                    exportBtn.disabled = true;
                    exportBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Exporting...';
                    
                    // Trigger export by navigating to export URL
                    window.location.href = '?export=dashboard';
                    
                    // Reset button after a delay (in case export doesn't trigger navigation)
                    setTimeout(function() {
                        exportBtn.disabled = false;
                        exportBtn.innerHTML = originalText;
                    }, 2000);
                });
            }
        });
    </script>
</body>
</html>