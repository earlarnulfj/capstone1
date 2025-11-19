-- =====================================================
-- RECREATE INVENTORY TABLE - ALIGNED WITH admin_orders
-- =====================================================
-- This script drops the existing inventory table and creates a new one
-- that is properly aligned with the admin_orders table structure
-- to ensure seamless syncing of completed orders to inventory
-- =====================================================

-- Drop existing inventory table and related tables
DROP TABLE IF EXISTS `inventory_variations`;
DROP TABLE IF EXISTS `inventory`;

-- Create new inventory table aligned with admin_orders structure
-- This table structure matches exactly what's used in admin/orders.php
-- Fields align with: admin_orders.inventory_id -> inventory.id
-- Fields align with: admin_orders.unit_type -> inventory.unit_type
-- Fields align with: admin_orders.unit_price -> inventory.unit_price
-- Fields align with: admin_orders.supplier_id -> inventory.supplier_id
CREATE TABLE `inventory` (
  `id` INT(11) NOT NULL AUTO_INCREMENT COMMENT 'Primary key - matches admin_orders.inventory_id',
  `sku` VARCHAR(50) DEFAULT NULL COMMENT 'Product SKU',
  `name` VARCHAR(100) NOT NULL COMMENT 'Product name - displayed as item_name in orders.php',
  `description` TEXT DEFAULT NULL COMMENT 'Product description',
  `category` VARCHAR(50) DEFAULT NULL COMMENT 'Product category',
  `reorder_threshold` INT(11) NOT NULL DEFAULT 0 COMMENT 'Reorder threshold for alerts',
  `unit_type` VARCHAR(50) NOT NULL DEFAULT 'per piece' COMMENT 'Unit type - matches admin_orders.unit_type',
  `quantity` INT(11) NOT NULL DEFAULT 0 COMMENT 'Total quantity from completed orders - matches admin_orders.quantity aggregation',
  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Unit price - matches admin_orders.unit_price',
  `supplier_id` INT(11) DEFAULT NULL COMMENT 'Supplier ID - matches admin_orders.supplier_id',
  `location` VARCHAR(100) DEFAULT NULL COMMENT 'Storage location',
  `image_url` VARCHAR(255) DEFAULT NULL COMMENT 'Product image URL',
  `image_path` VARCHAR(255) DEFAULT NULL COMMENT 'Product image file path',
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Soft delete flag: 0 = active, 1 = deleted',
  `last_updated` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Creation timestamp',
  PRIMARY KEY (`id`),
  KEY `idx_supplier_id` (`supplier_id`) COMMENT 'Index for JOIN with suppliers table',
  KEY `idx_sku` (`sku`) COMMENT 'Index for SKU lookups',
  KEY `idx_category` (`category`) COMMENT 'Index for category filtering',
  KEY `idx_is_deleted` (`is_deleted`) COMMENT 'Index for soft delete filtering',
  KEY `idx_name` (`name`) COMMENT 'Index for name searches',
  KEY `idx_unit_type` (`unit_type`) COMMENT 'Index for unit type filtering',
  KEY `idx_unit_price` (`unit_price`) COMMENT 'Index for price queries'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Create inventory_variations table to store variation-specific data
CREATE TABLE `inventory_variations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `inventory_id` INT(11) NOT NULL,
  `variation` VARCHAR(255) DEFAULT NULL COMMENT 'Product variation from admin_orders (e.g., Brand:Adidas|Size:Large|Color:Red)',
  `unit_type` VARCHAR(50) NOT NULL DEFAULT 'per piece',
  `quantity` INT(11) NOT NULL DEFAULT 0 COMMENT 'Quantity for this specific variation from completed orders',
  `unit_price` DECIMAL(10,2) DEFAULT NULL COMMENT 'Unit price for this variation',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_inventory_variation` (`inventory_id`, `variation`(100)),
  KEY `idx_inventory_id` (`inventory_id`),
  KEY `idx_variation` (`variation`(100)),
  CONSTRAINT `fk_inventory_variations_inventory` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- NOTES:
-- =====================================================
-- 1. The inventory table now has all fields needed to sync from admin_orders
-- 2. inventory_variations table stores variation-specific data (variation, quantity, unit_price)
-- 3. The quantity field in inventory stores the total quantity from all completed orders
-- 4. The quantity field in inventory_variations stores quantity per variation
-- 5. Both tables support soft deletion via is_deleted flag
-- 6. All timestamps are properly indexed for performance
-- =====================================================

