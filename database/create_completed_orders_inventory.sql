-- Create table for Completed Orders Inventory
-- This table stores products that are available in POS systems (admin_pos.php and pos.php)
-- Products are synced from the Completed Orders Inventory section in inventory.php
-- This table is separate from the main inventory table and admin_orders table

CREATE TABLE IF NOT EXISTS `completed_orders_inventory` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `inventory_id` INT(11) NOT NULL,
  `sku` VARCHAR(50) DEFAULT NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `variation` VARCHAR(255) DEFAULT NULL,
  `unit_type` VARCHAR(50) DEFAULT 'per piece',
  `quantity` INT(11) NOT NULL DEFAULT 0,
  `available_quantity` INT(11) NOT NULL DEFAULT 0,
  `total_ordered_quantity` INT(11) NOT NULL DEFAULT 0,
  `sold_quantity` INT(11) NOT NULL DEFAULT 0,
  `reorder_threshold` INT(11) NOT NULL DEFAULT 0,
  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `supplier_id` INT(11) DEFAULT NULL,
  `supplier_name` VARCHAR(100) DEFAULT NULL,
  `category` VARCHAR(50) DEFAULT NULL,
  `location` VARCHAR(100) DEFAULT NULL,
  `image_url` VARCHAR(255) DEFAULT NULL,
  `image_path` VARCHAR(255) DEFAULT NULL,
  `order_ids` TEXT DEFAULT NULL COMMENT 'Comma-separated list of admin_orders.id',
  `order_count` INT(11) NOT NULL DEFAULT 0,
  `first_order_date` DATETIME DEFAULT NULL,
  `last_confirmation_date` DATETIME DEFAULT NULL,
  `last_updated` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_inventory_variation` (`inventory_id`, `variation`(100)),
  KEY `idx_inventory_id` (`inventory_id`),
  KEY `idx_sku` (`sku`),
  KEY `idx_category` (`category`),
  KEY `idx_supplier_id` (`supplier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

