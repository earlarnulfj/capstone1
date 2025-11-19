-- Create admin_orders table for admin-side orders
-- This allows admin to delete orders without affecting supplier orders
-- Admin POS and Staff POS will use this table

CREATE TABLE IF NOT EXISTS `admin_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inventory_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `is_automated` tinyint(1) DEFAULT 0,
  `order_date` datetime DEFAULT current_timestamp(),
  `confirmation_status` enum('pending','confirmed','cancelled','delivered','completed') DEFAULT 'pending',
  `confirmation_date` datetime DEFAULT NULL,
  `unit_price` decimal(10,2) DEFAULT 0.00,
  `unit_type` VARCHAR(50) NOT NULL DEFAULT 'per piece',
  `variation` VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inventory_id` (`inventory_id`),
  KEY `supplier_id` (`supplier_id`),
  KEY `user_id` (`user_id`),
  KEY `confirmation_status` (`confirmation_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Copy existing orders from orders table to admin_orders (if you want to migrate existing data)
-- Uncomment the following if you want to migrate existing orders:
-- INSERT INTO admin_orders (inventory_id, supplier_id, user_id, quantity, is_automated, order_date, confirmation_status, confirmation_date, unit_price, unit_type, variation)
-- SELECT inventory_id, supplier_id, user_id, quantity, is_automated, order_date, confirmation_status, confirmation_date, unit_price, 
--        COALESCE(unit_type, 'per piece'), variation
-- FROM orders;

