<?php
require_once 'config/database.php';
require_once 'models/order.php';
require_once 'models/delivery.php';

echo "<h2>Testing Delivery System Integration</h2>";

$database = new Database();
$db = $database->getConnection();
$order = new Order($db);
$delivery = new Delivery($db);

// Test 1: Check if orders exist
echo "<h3>Test 1: Checking Orders</h3>";
$orders = $order->readAll();
$order_count = 0;
while ($orders->fetch()) {
    $order_count++;
}
echo "Total orders found: " . $order_count . "<br>";

// Test 2: Check if deliveries exist
echo "<h3>Test 2: Checking Deliveries</h3>";
$deliveries = $delivery->readAll();
$delivery_count = 0;
while ($deliveries->fetch()) {
    $delivery_count++;
}
echo "Total deliveries found: " . $delivery_count . "<br>";

// Test 3: Check supplier-specific orders
echo "<h3>Test 3: Checking Supplier Orders</h3>";
$stmt = $db->prepare("SELECT DISTINCT supplier_id FROM orders WHERE supplier_id IS NOT NULL");
$stmt->execute();
$suppliers = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Suppliers with orders: " . implode(', ', $suppliers) . "<br>";

// Test 4: Check delivery statuses
echo "<h3>Test 4: Checking Delivery Statuses</h3>";
$stmt = $db->prepare("SELECT status, COUNT(*) as count FROM deliveries GROUP BY status");
$stmt->execute();
$statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($statuses as $status) {
    echo "Status '{$status['status']}': {$status['count']} deliveries<br>";
}

// Test 5: Check notifications
echo "<h3>Test 5: Checking Notifications</h3>";
$stmt = $db->prepare("SELECT type, COUNT(*) as count FROM notifications GROUP BY type");
$stmt->execute();
$notification_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($notification_types as $type) {
    echo "Type '{$type['type']}': {$type['count']} notifications<br>";
}

// Test 6: Test delivery status update
echo "<h3>Test 6: Testing Status Update</h3>";
$stmt = $db->prepare("SELECT id FROM orders LIMIT 1");
$stmt->execute();
$test_order = $stmt->fetch(PDO::FETCH_ASSOC);

if ($test_order) {
    $test_order_id = $test_order['id'];
    echo "Testing status update for order ID: " . $test_order_id . "<br>";
    
    // Try to update status
    $result = $delivery->updateStatusByOrderId($test_order_id, 'in_transit');
    echo "Status update result: " . ($result ? "SUCCESS" : "FAILED") . "<br>";
} else {
    echo "No orders available for testing<br>";
}

echo "<h3>Integration Test Complete</h3>";
echo "<p><a href='admin/deliveries.php'>View Admin Deliveries</a> | <a href='supplier/deliveries.php'>View Supplier Deliveries</a></p>";
?>