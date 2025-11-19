<?php
// Simple script to create test supplier notifications
require_once 'config/database.php';
require_once 'models/notification.php';

$database = new Database();
$db = $database->getConnection();
$notification = new Notification($db);

// Create test notifications
$test_notifications = [
    [
        'type' => 'order_confirmation',
        'recipient_type' => 'admin',
        'message' => 'Order #12345 has been confirmed by [ABC Hardware Supplies]',
        'status' => 'sent'
    ],
    [
        'type' => 'supplier_message',
        'recipient_type' => 'admin',
        'message' => 'New message from [XYZ Building Materials]: Delivery scheduled for tomorrow at 2 PM',
        'status' => 'sent'
    ],
    [
        'type' => 'delivery_status_update',
        'recipient_type' => 'admin',
        'message' => 'Delivery status updated by [ABC Hardware Supplies]: Package is out for delivery',
        'status' => 'sent'
    ]
];

echo "<h2>Creating Test Supplier Notifications</h2>";

foreach ($test_notifications as $notif) {
    $notification->type = $notif['type'];
    $notification->recipient_type = $notif['recipient_type'];
    $notification->message = $notif['message'];
    $notification->status = $notif['status'];
    
    if ($notification->create()) {
        echo "✓ Created: " . $notif['type'] . " - " . substr($notif['message'], 0, 50) . "...<br>";
    } else {
        echo "✗ Failed to create: " . $notif['type'] . "<br>";
    }
}

echo "<br><p><strong>Test notifications created successfully!</strong></p>";
echo "<p><a href='admin/notifications.php'>View Admin Notifications</a></p>";
?>