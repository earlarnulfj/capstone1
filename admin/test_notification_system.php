<?php
// ====== Access control & dependencies ======
include_once '../config/session.php';
require_once '../config/database.php';
require_once '../models/notification.php';
require_once '../models/delivery.php';
require_once '../models/inventory.php';

// ---- Admin auth guard (namespaced) ----
if (empty($_SESSION['admin']['user_id'])) {
    header("Location: ../login.php");
    exit();
}
if (($_SESSION['admin']['role'] ?? null) !== 'management') {
    header("Location: ../login.php");
    exit();
}

// ---- Instantiate dependencies ----
$db = (new Database())->getConnection();
$notification = new Notification($db);
$delivery = new Delivery($db);
$inventory = new Inventory($db);

// Handle test actions
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'test_delivery_arrival':
            try {
                // Get a random delivery to test with
                $query = "SELECT d.*, i.name as item_name, s.name as supplier_name 
                          FROM deliveries d 
                          JOIN inventory i ON d.inventory_id = i.id 
                          JOIN suppliers s ON i.supplier_id = s.id 
                          WHERE d.status != 'delivered' 
                          ORDER BY RAND() 
                          LIMIT 1";
                $stmt = $db->prepare($query);
                $stmt->execute();
                $testDelivery = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($testDelivery) {
                    // Update delivery status to trigger notification
                    $result = $delivery->updateStatusByOrderId($testDelivery['order_id'], 'delivered');
                    
                    if ($result) {
                        $message = "Test delivery arrival notification sent for Order #{$testDelivery['order_id']} - {$testDelivery['item_name']} from {$testDelivery['supplier_name']}";
                        $messageType = 'success';
                    } else {
                        $message = "Failed to update delivery status";
                        $messageType = 'danger';
                    }
                } else {
                    $message = "No pending deliveries found to test with";
                    $messageType = 'warning';
                }
            } catch (Exception $e) {
                $message = "Error: " . $e->getMessage();
                $messageType = 'danger';
            }
            break;
            
        case 'test_manual_notification':
            try {
                $testMessage = $_POST['test_message'] ?? 'Test notification from admin panel';
                $testType = $_POST['test_type'] ?? 'delivery_arrival';
                
                // Create a manual test notification
                $result = $notification->createDeliveryNotification(
                    $testType,
                    $testMessage,
                    'management',
                    null, // order_id
                    null, // delivery_id
                    ['test' => true, 'manual' => true]
                );
                
                if ($result) {
                    $message = "Manual test notification created successfully";
                    $messageType = 'success';
                } else {
                    $message = "Failed to create test notification";
                    $messageType = 'danger';
                }
            } catch (Exception $e) {
                $message = "Error: " . $e->getMessage();
                $messageType = 'danger';
            }
            break;
            
        case 'clear_test_notifications':
            try {
                $query = "DELETE FROM notifications WHERE message LIKE '%test%' OR message LIKE '%Test%'";
                $stmt = $db->prepare($query);
                $result = $stmt->execute();
                
                $message = $result ? "Test notifications cleared" : "Failed to clear test notifications";
                $messageType = $result ? 'success' : 'danger';
            } catch (Exception $e) {
                $message = "Error: " . $e->getMessage();
                $messageType = 'danger';
            }
            break;
    }
}

// Get current notification stats
$stats = $notification->getDeliveryNotificationStats($_SESSION['admin']['user_id'], $_SESSION['admin']['role']);

// Get recent notifications for testing
$recentNotifications = $notification->getAllDeliveryNotifications($_SESSION['admin']['role'], 1, 10);

// Get pending deliveries for testing
$query = "SELECT d.*, i.name as item_name, s.name as supplier_name 
          FROM deliveries d 
          JOIN inventory i ON d.inventory_id = i.id 
          JOIN suppliers s ON i.supplier_id = s.id 
          WHERE d.status != 'delivered' 
          ORDER BY d.created_at DESC 
          LIMIT 5";
$stmt = $db->prepare($query);
$stmt->execute();
$pendingDeliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification System Test - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .test-section {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #007bff;
        }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .notification-preview {
            background-color: #fff;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
        }
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
        .status-pending { background-color: #ffc107; }
        .status-in_transit { background-color: #17a2b8; }
        .status-delivered { background-color: #28a745; }
        .real-time-indicator {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1050;
            background: #28a745;
            color: white;
            padding: 10px 15px;
            border-radius: 5px;
            display: none;
        }
    </style>
</head>
<body>
    <?php include_once 'includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include_once 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-gear me-2"></i>Notification System Test</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="location.reload()">
                            <i class="bi bi-arrow-clockwise"></i> Refresh
                        </button>
                    </div>
                </div>
                
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Real-time indicator -->
                <div id="realTimeIndicator" class="real-time-indicator">
                    <i class="bi bi-wifi"></i> Real-time updates active
                </div>
                
                <!-- Current Stats -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <h3 class="mb-0"><?php echo $stats['total_notifications'] ?? 0; ?></h3>
                                <p class="mb-0">Total Notifications</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-warning text-dark">
                            <div class="card-body text-center">
                                <h3 class="mb-0" id="unread-count"><?php echo $stats['unread_count'] ?? 0; ?></h3>
                                <p class="mb-0">Unread</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body text-center">
                                <h3 class="mb-0"><?php echo $stats['unread_arrivals'] ?? 0; ?></h3>
                                <p class="mb-0">New Arrivals</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-info text-white">
                            <div class="card-body text-center">
                                <h3 class="mb-0"><?php echo count($pendingDeliveries); ?></h3>
                                <p class="mb-0">Pending Deliveries</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Test Actions -->
                <div class="test-section">
                    <h4><i class="bi bi-play-circle me-2"></i>Test Actions</h4>
                    <div class="row">
                        <div class="col-md-4">
                            <form method="POST" class="mb-3">
                                <input type="hidden" name="action" value="test_delivery_arrival">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-truck"></i> Test Delivery Arrival
                                </button>
                                <small class="text-muted">Simulates a delivery arriving and triggers notification</small>
                            </form>
                        </div>
                        <div class="col-md-4">
                            <form method="POST" class="mb-3">
                                <input type="hidden" name="action" value="test_manual_notification">
                                <input type="hidden" name="test_message" value="Manual test notification - System working correctly">
                                <input type="hidden" name="test_type" value="delivery_confirmation">
                                <button type="submit" class="btn btn-info w-100">
                                    <i class="bi bi-bell"></i> Send Test Notification
                                </button>
                                <small class="text-muted">Creates a manual test notification</small>
                            </form>
                        </div>
                        <div class="col-md-4">
                            <form method="POST" class="mb-3">
                                <input type="hidden" name="action" value="clear_test_notifications">
                                <button type="submit" class="btn btn-outline-danger w-100">
                                    <i class="bi bi-trash"></i> Clear Test Notifications
                                </button>
                                <small class="text-muted">Removes all test notifications</small>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Pending Deliveries -->
                <div class="test-section">
                    <h4><i class="bi bi-clock me-2"></i>Pending Deliveries (Available for Testing)</h4>
                    <?php if (empty($pendingDeliveries)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i>No pending deliveries found. All deliveries have been completed.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Order ID</th>
                                        <th>Item</th>
                                        <th>Supplier</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingDeliveries as $delivery): ?>
                                        <tr>
                                            <td>#<?php echo $delivery['order_id']; ?></td>
                                            <td><?php echo htmlspecialchars($delivery['item_name']); ?></td>
                                            <td><?php echo htmlspecialchars($delivery['supplier_name']); ?></td>
                                            <td>
                                                <span class="status-indicator status-<?php echo $delivery['status']; ?>"></span>
                                                <?php echo ucfirst(str_replace('_', ' ', $delivery['status'])); ?>
                                            </td>
                                            <td><?php echo date('M j, Y g:i A', strtotime($delivery['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Recent Notifications -->
                <div class="test-section">
                    <h4><i class="bi bi-list me-2"></i>Recent Notifications (Live Preview)</h4>
                    <div id="notification-preview">
                        <?php if (empty($recentNotifications)): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle me-2"></i>No recent notifications found.
                            </div>
                        <?php else: ?>
                            <?php foreach ($recentNotifications as $notif): ?>
                                <div class="notification-preview">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong><?php echo htmlspecialchars($notif['message']); ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                Type: <?php echo ucfirst(str_replace('_', ' ', $notif['type'])); ?> | 
                                                Status: <?php echo ucfirst($notif['status']); ?> | 
                                                <?php echo date('M j, Y g:i A', strtotime($notif['sent_at'])); ?>
                                            </small>
                                        </div>
                                        <span class="badge bg-<?php echo $notif['status'] === 'sent' ? 'warning' : 'success'; ?>">
                                            <?php echo $notif['status'] === 'sent' ? 'Unread' : 'Read'; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- System Status -->
                <div class="test-section">
                    <h4><i class="bi bi-shield-check me-2"></i>System Status</h4>
                    <div class="row">
                        <div class="col-md-6">
                            <ul class="list-group">
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Notification API
                                    <span class="badge bg-success rounded-pill" id="api-status">Active</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Real-time Updates
                                    <span class="badge bg-success rounded-pill" id="realtime-status">Active</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Database Connection
                                    <span class="badge bg-success rounded-pill">Connected</span>
                                </li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <ul class="list-group">
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Sound Alerts
                                    <span class="badge bg-success rounded-pill" id="sound-status">Enabled</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Browser Notifications
                                    <span class="badge bg-warning rounded-pill" id="browser-notif-status">Checking...</span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    Last Update
                                    <span class="badge bg-info rounded-pill" id="last-update">Just now</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
    <script src="assets/js/notifications.js"></script>
    
    <script>
        // Test-specific functionality
        let testStartTime = Date.now();
        let notificationCount = <?php echo $stats['unread_count'] ?? 0; ?>;
        
        // Override notification callback for testing
        if (window.adminNotifications) {
            const originalCallback = window.adminNotifications.onNewNotification;
            window.adminNotifications.onNewNotification = function(notification) {
                // Call original callback
                if (originalCallback) {
                    originalCallback.call(this, notification);
                }
                
                // Test-specific actions
                showRealTimeIndicator();
                updateTestStats();
                addNotificationToPreview(notification);
            };
        }
        
        function showRealTimeIndicator() {
            const indicator = document.getElementById('realTimeIndicator');
            indicator.style.display = 'block';
            setTimeout(() => {
                indicator.style.display = 'none';
            }, 3000);
        }
        
        function updateTestStats() {
            // Update unread count
            const unreadElement = document.getElementById('unread-count');
            if (unreadElement) {
                const currentCount = parseInt(unreadElement.textContent);
                unreadElement.textContent = currentCount + 1;
            }
            
            // Update last update time
            const lastUpdateElement = document.getElementById('last-update');
            if (lastUpdateElement) {
                lastUpdateElement.textContent = 'Just now';
            }
        }
        
        function addNotificationToPreview(notification) {
            const previewContainer = document.getElementById('notification-preview');
            const newNotification = document.createElement('div');
            newNotification.className = 'notification-preview';
            newNotification.style.borderLeft = '4px solid #28a745';
            newNotification.innerHTML = `
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <strong>${notification.message}</strong>
                        <br>
                        <small class="text-muted">
                            Type: ${notification.type.replace('_', ' ')} | 
                            Status: Unread | 
                            Just now
                        </small>
                    </div>
                    <span class="badge bg-warning">New</span>
                </div>
            `;
            
            // Add to top of preview
            previewContainer.insertBefore(newNotification, previewContainer.firstChild);
            
            // Remove old notifications if more than 10
            const notifications = previewContainer.querySelectorAll('.notification-preview');
            if (notifications.length > 10) {
                notifications[notifications.length - 1].remove();
            }
        }
        
        // Check browser notification permission
        if ('Notification' in window) {
            const status = Notification.permission;
            const statusElement = document.getElementById('browser-notif-status');
            if (status === 'granted') {
                statusElement.textContent = 'Enabled';
                statusElement.className = 'badge bg-success rounded-pill';
            } else if (status === 'denied') {
                statusElement.textContent = 'Disabled';
                statusElement.className = 'badge bg-danger rounded-pill';
            } else {
                statusElement.textContent = 'Not Requested';
                statusElement.className = 'badge bg-warning rounded-pill';
            }
        }
        
        // Update system status indicators
        setInterval(() => {
            // Check if notification system is working
            if (window.adminNotifications && window.adminNotifications.isPolling) {
                document.getElementById('realtime-status').textContent = 'Active';
                document.getElementById('realtime-status').className = 'badge bg-success rounded-pill';
            } else {
                document.getElementById('realtime-status').textContent = 'Inactive';
                document.getElementById('realtime-status').className = 'badge bg-danger rounded-pill';
            }
            
            // Update last update time
            const elapsed = Math.floor((Date.now() - testStartTime) / 1000);
            const lastUpdateElement = document.getElementById('last-update');
            if (elapsed < 60) {
                lastUpdateElement.textContent = `${elapsed}s ago`;
            } else {
                lastUpdateElement.textContent = `${Math.floor(elapsed / 60)}m ago`;
            }
        }, 1000);
        
        // Test API connectivity
        fetch('api/notifications.php')
            .then(response => response.json())
            .then(data => {
                document.getElementById('api-status').textContent = 'Active';
                document.getElementById('api-status').className = 'badge bg-success rounded-pill';
            })
            .catch(error => {
                document.getElementById('api-status').textContent = 'Error';
                document.getElementById('api-status').className = 'badge bg-danger rounded-pill';
            });
    </script>
</body>
</html>