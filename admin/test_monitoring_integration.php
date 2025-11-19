<?php
// ====== Integration Test for Admin Monitoring & Supplier Delivery System ======
include_once '../config/session.php';
require_once '../config/database.php';
require_once '../models/order.php';
require_once '../models/delivery.php';
require_once '../models/supplier.php';

$database = new Database();
$db = $database->getConnection();
$order = new Order($db);
$delivery = new Delivery($db);
$supplier = new Supplier($db);

// Test results array
$test_results = [];

// Test 1: Check if admin deliveries page exists and loads
$test_results['admin_page_exists'] = file_exists(__DIR__ . '/deliveries.php');

// Test 2: Check if supplier deliveries page exists and loads
$test_results['supplier_page_exists'] = file_exists(__DIR__ . '/../supplier/deliveries.php');

// Test 3: Test database connectivity and delivery data access
try {
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM deliveries");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $test_results['database_connectivity'] = true;
    $test_results['delivery_count'] = $result['count'];
} catch (Exception $e) {
    $test_results['database_connectivity'] = false;
    $test_results['error'] = $e->getMessage();
}

// Test 4: Test supplier data access
try {
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM suppliers WHERE status = 'active'");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $test_results['supplier_access'] = true;
    $test_results['active_suppliers'] = $result['count'];
} catch (Exception $e) {
    $test_results['supplier_access'] = false;
}

// Test 5: Test order integration
try {
    $stmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM orders o 
        JOIN deliveries d ON o.id = d.order_id 
        WHERE o.confirmation_status = 'confirmed'
    ");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $test_results['order_integration'] = true;
    $test_results['confirmed_orders_with_deliveries'] = $result['count'];
} catch (Exception $e) {
    $test_results['order_integration'] = false;
}

// Test 6: Test analytics data availability
try {
    $stmt = $db->prepare("
        SELECT 
            d.status,
            COUNT(*) as count,
            AVG(TIMESTAMPDIFF(HOUR, d.created_at, d.delivered_at)) as avg_delivery_time
        FROM deliveries d 
        GROUP BY d.status
    ");
    $stmt->execute();
    $analytics_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $test_results['analytics_data'] = true;
    $test_results['status_distribution'] = $analytics_data;
} catch (Exception $e) {
    $test_results['analytics_data'] = false;
}

// Test 7: Test supplier performance data
try {
    $stmt = $db->prepare("
        SELECT 
            s.company_name,
            COUNT(d.id) as total_deliveries,
            SUM(CASE WHEN d.status = 'delivered' THEN 1 ELSE 0 END) as completed_deliveries,
            AVG(CASE WHEN d.status = 'delivered' THEN TIMESTAMPDIFF(HOUR, d.created_at, d.delivered_at) END) as avg_delivery_time
        FROM suppliers s
        LEFT JOIN orders o ON s.id = o.supplier_id
        LEFT JOIN deliveries d ON o.id = d.order_id
        WHERE s.status = 'active'
        GROUP BY s.id, s.company_name
        HAVING total_deliveries > 0
    ");
    $stmt->execute();
    $performance_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $test_results['supplier_performance'] = true;
    $test_results['performance_data'] = $performance_data;
} catch (Exception $e) {
    $test_results['supplier_performance'] = false;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Monitoring Integration Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .test-pass { color: #28a745; }
        .test-fail { color: #dc3545; }
        .test-card { margin-bottom: 1rem; }
    </style>
</head>
<body class="bg-light">
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">
                            <i class="bi bi-clipboard-check me-2"></i>
                            Admin Monitoring Integration Test Results
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- Test Results -->
                            <div class="col-md-8">
                                <h5>System Integration Tests</h5>
                                
                                <!-- Test 1: Admin Page -->
                                <div class="card test-card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span>Admin Deliveries Page Exists</span>
                                            <span class="<?= $test_results['admin_page_exists'] ? 'test-pass' : 'test-fail' ?>">
                                                <i class="bi bi-<?= $test_results['admin_page_exists'] ? 'check-circle' : 'x-circle' ?>"></i>
                                                <?= $test_results['admin_page_exists'] ? 'PASS' : 'FAIL' ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Test 2: Supplier Page -->
                                <div class="card test-card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span>Supplier Deliveries Page Exists</span>
                                            <span class="<?= $test_results['supplier_page_exists'] ? 'test-pass' : 'test-fail' ?>">
                                                <i class="bi bi-<?= $test_results['supplier_page_exists'] ? 'check-circle' : 'x-circle' ?>"></i>
                                                <?= $test_results['supplier_page_exists'] ? 'PASS' : 'FAIL' ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Test 3: Database Connectivity -->
                                <div class="card test-card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span>Database Connectivity & Delivery Data</span>
                                            <span class="<?= $test_results['database_connectivity'] ? 'test-pass' : 'test-fail' ?>">
                                                <i class="bi bi-<?= $test_results['database_connectivity'] ? 'check-circle' : 'x-circle' ?>"></i>
                                                <?= $test_results['database_connectivity'] ? 'PASS' : 'FAIL' ?>
                                            </span>
                                        </div>
                                        <?php if ($test_results['database_connectivity']): ?>
                                            <small class="text-muted">Found <?= $test_results['delivery_count'] ?> deliveries in database</small>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Test 4: Supplier Access -->
                                <div class="card test-card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span>Supplier Data Access</span>
                                            <span class="<?= $test_results['supplier_access'] ? 'test-pass' : 'test-fail' ?>">
                                                <i class="bi bi-<?= $test_results['supplier_access'] ? 'check-circle' : 'x-circle' ?>"></i>
                                                <?= $test_results['supplier_access'] ? 'PASS' : 'FAIL' ?>
                                            </span>
                                        </div>
                                        <?php if ($test_results['supplier_access']): ?>
                                            <small class="text-muted"><?= $test_results['active_suppliers'] ?> active suppliers found</small>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Test 5: Order Integration -->
                                <div class="card test-card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span>Order-Delivery Integration</span>
                                            <span class="<?= $test_results['order_integration'] ? 'test-pass' : 'test-fail' ?>">
                                                <i class="bi bi-<?= $test_results['order_integration'] ? 'check-circle' : 'x-circle' ?>"></i>
                                                <?= $test_results['order_integration'] ? 'PASS' : 'FAIL' ?>
                                            </span>
                                        </div>
                                        <?php if ($test_results['order_integration']): ?>
                                            <small class="text-muted"><?= $test_results['confirmed_orders_with_deliveries'] ?> confirmed orders with deliveries</small>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Test 6: Analytics Data -->
                                <div class="card test-card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span>Analytics Data Availability</span>
                                            <span class="<?= $test_results['analytics_data'] ? 'test-pass' : 'test-fail' ?>">
                                                <i class="bi bi-<?= $test_results['analytics_data'] ? 'check-circle' : 'x-circle' ?>"></i>
                                                <?= $test_results['analytics_data'] ? 'PASS' : 'FAIL' ?>
                                            </span>
                                        </div>
                                        <?php if ($test_results['analytics_data'] && !empty($test_results['status_distribution'])): ?>
                                            <small class="text-muted">Status distribution data available</small>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Test 7: Supplier Performance -->
                                <div class="card test-card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span>Supplier Performance Analytics</span>
                                            <span class="<?= $test_results['supplier_performance'] ? 'test-pass' : 'test-fail' ?>">
                                                <i class="bi bi-<?= $test_results['supplier_performance'] ? 'check-circle' : 'x-circle' ?>"></i>
                                                <?= $test_results['supplier_performance'] ? 'PASS' : 'FAIL' ?>
                                            </span>
                                        </div>
                                        <?php if ($test_results['supplier_performance'] && !empty($test_results['performance_data'])): ?>
                                            <small class="text-muted"><?= count($test_results['performance_data']) ?> suppliers with performance data</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Summary & Actions -->
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h6 class="mb-0">Test Summary</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php
                                        $total_tests = 7;
                                        $passed_tests = 0;
                                        foreach (['admin_page_exists', 'supplier_page_exists', 'database_connectivity', 'supplier_access', 'order_integration', 'analytics_data', 'supplier_performance'] as $test) {
                                            if ($test_results[$test]) $passed_tests++;
                                        }
                                        $success_rate = ($passed_tests / $total_tests) * 100;
                                        ?>
                                        <div class="text-center mb-3">
                                            <h4 class="<?= $success_rate >= 85 ? 'text-success' : ($success_rate >= 70 ? 'text-warning' : 'text-danger') ?>">
                                                <?= $passed_tests ?>/<?= $total_tests ?> Tests Passed
                                            </h4>
                                            <div class="progress">
                                                <div class="progress-bar <?= $success_rate >= 85 ? 'bg-success' : ($success_rate >= 70 ? 'bg-warning' : 'bg-danger') ?>" 
                                                     style="width: <?= $success_rate ?>%"></div>
                                            </div>
                                            <small class="text-muted"><?= number_format($success_rate, 1) ?>% Success Rate</small>
                                        </div>

                                        <div class="d-grid gap-2">
                                            <a href="deliveries.php" class="btn btn-primary">
                                                <i class="bi bi-speedometer2 me-1"></i>
                                                View Admin Monitoring
                                            </a>
                                            <a href="../supplier/deliveries.php" class="btn btn-outline-primary">
                                                <i class="bi bi-truck me-1"></i>
                                                View Supplier Portal
                                            </a>
                                            <button class="btn btn-outline-secondary" onclick="location.reload()">
                                                <i class="bi bi-arrow-clockwise me-1"></i>
                                                Rerun Tests
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <?php if ($test_results['analytics_data'] && !empty($test_results['status_distribution'])): ?>
                                <div class="card mt-3">
                                    <div class="card-header">
                                        <h6 class="mb-0">Status Distribution</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php foreach ($test_results['status_distribution'] as $status): ?>
                                            <div class="d-flex justify-content-between mb-2">
                                                <span class="text-capitalize"><?= str_replace('_', ' ', $status['status']) ?></span>
                                                <span class="badge bg-secondary"><?= $status['count'] ?></span>
                                            </div>
                                        <?php endforeach; ?>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>