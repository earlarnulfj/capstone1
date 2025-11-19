-- Create notifications table with proper structure
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` enum('low_stock','order_confirmation','delivery_update','delivery_arrival','delivery_status_update','delivery_created','order_status_update','supplier_message','inventory_update') NOT NULL,
  `channel` enum('email','sms','push','in_app') NOT NULL DEFAULT 'in_app',
  `recipient_type` enum('admin','supplier','customer','management') NOT NULL,
  `recipient_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `alert_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `status` enum('sent','failed','pending','read','unread') DEFAULT 'pending',
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `read_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_recipient` (`recipient_type`,`recipient_id`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `fk_notifications_orders` (`order_id`),
  KEY `fk_notifications_alert_logs` (`alert_id`),
  CONSTRAINT `fk_notifications_alert_logs` FOREIGN KEY (`alert_id`) REFERENCES `alert_logs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_notifications_orders` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert some sample notifications for testing
INSERT INTO `notifications` (`type`, `channel`, `recipient_type`, `recipient_id`, `order_id`, `alert_id`, `message`, `sent_at`, `status`, `is_read`) VALUES
('low_stock', 'in_app', 'management', 1, NULL, 1, 'Low stock alert: Product XYZ has only 5 units remaining', NOW(), 'sent', 0),
('delivery_update', 'in_app', 'management', 1, 1, NULL, 'Delivery status updated for Order #1', NOW(), 'sent', 0),
('order_status_update', 'in_app', 'management', 1, 2, NULL, 'Order #2 status changed to Completed', NOW(), 'sent', 1),
('delivery_arrival', 'in_app', 'management', 1, 3, NULL, 'Delivery has arrived for Order #3', NOW(), 'sent', 0);