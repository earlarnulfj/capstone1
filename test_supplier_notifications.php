<?php
// Test script for supplier notification integration
require_once 'config/database.php';
require_once 'models/notification.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Initialize notification model
$notification = new Notification($db);

echo "<h2>Testing Supplier Notification Integration</h2>";

// Test 1: Create a supplier notification
echo "<h3>Test 1: Creating supplier notifications</h3>";

// Create order confirmation notification
$notification->type = 'order_confirmation';
$notification->recipient_type = 'admin';
$notification->message = 'Order #12345 confirmed by [ABC Suppliers Ltd]';
$notification->status = 'sent';

if ($notification->create()) {
    echo "✓ Order confirmation notification created successfully<br>";
} else {
    echo "✗ Failed to create order confirmation notification<br>";
}

// Create supplier message notification
$notification->type = 'supplier_message';
$notification->recipient_type = 'admin';
$notification->message = 'New message from [XYZ Trading Co]: Delivery scheduled for tomorrow';
$notification->status = 'sent';

if ($notification->create()) {
    echo "✓ Supplier message notification created successfully<br>";
} else {
    echo "✗ Failed to create supplier message notification<br>";
}

// Test 2: Retrieve supplier notifications
echo "<h3>Test 2: Retrieving supplier notifications</h3>";

$supplier_notifications = $notification->getSupplierNotifications('admin', 5);
echo "Found " . count($supplier_notifications) . " supplier notifications:<br>";

foreach ($supplier_notifications as $notif) {
    echo "- Type: {$notif['type']}, Message: " . substr($notif['message'], 0, 50) . "...<br>";
}

// Test 3: Get supplier notification statistics
echo "<h3>Test 3: Supplier notification statistics</h3>";

$stats = $notification->getSupplierNotificationStats('admin');
echo "Total supplier notifications (last 30 days): " . ($stats['total_supplier_notifications'] ?? 0) . "<br>";
echo "Unread supplier notifications: " . ($stats['unread_supplier_notifications'] ?? 0) . "<br>";
echo "Unread order confirmations: " . ($stats['unread_confirmations'] ?? 0) . "<br>";
echo "Unread supplier messages: " . ($stats['unread_messages'] ?? 0) . "<br>";

// Test 4: Test API endpoint
echo "<h3>Test 4: Testing API endpoint</h3>";

$api_url = 'http://localhost:8080/api/supplier_notifications.php';

// Test GET request
$get_response = file_get_contents($api_url . '?action=get_recent&limit=3');
if ($get_response) {
    echo "✓ API GET request successful<br>";
    $get_data = json_decode($get_response, true);
    if ($get_data['success']) {
        echo "✓ API returned " . count($get_data['notifications']) . " notifications<br>";
    } else {
        echo "✗ API returned error: " . $get_data['message'] . "<br>";
    }
} else {
    echo "✗ API GET request failed<br>";
}

// Test POST request (simulate supplier sending notification)
$post_data = json_encode([
    'action' => 'send_notification',
    'type' => 'supplier_message',
    'message' => 'Test notification from supplier integration test',
    'supplier_id' => 'test_supplier',
    'api_key' => 'test_key_123'
]);

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/json',
        'content' => $post_data
    ]
]);

$post_response = file_get_contents($api_url, false, $context);
if ($post_response) {
    echo "✓ API POST request successful<br>";
    $post_data_response = json_decode($post_response, true);
    if ($post_data_response['success']) {
        echo "✓ Notification sent successfully via API<br>";
    } else {
        echo "✗ API returned error: " . $post_data_response['message'] . "<br>";
    }
} else {
    echo "✗ API POST request failed<br>";
}

echo "<h3>Integration Test Complete</h3>";
echo "<p><a href='admin/notifications.php'>View Admin Notifications</a></p>";
?>