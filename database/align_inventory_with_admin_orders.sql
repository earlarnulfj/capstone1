-- Migration script to align inventory_from_completed_orders table with admin_orders table
-- This adds missing fields from admin_orders to ensure proper data alignment
-- Note: MySQL doesn't support IF NOT EXISTS for ALTER TABLE, so check manually or use stored procedure

-- Add supplier_id column if it doesn't exist (should already exist, but ensure it's in the right position)
-- Run this only if the column doesn't exist
SET @dbname = DATABASE();
SET @tablename = 'inventory_from_completed_orders';
SET @columnname = 'supplier_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1', -- Column exists, do nothing
  CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @columnname, '` INT(11) DEFAULT NULL COMMENT ''Supplier ID from admin_orders'' AFTER `inventory_id`;')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add user_id column if it doesn't exist
SET @columnname = 'user_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @columnname, '` INT(11) DEFAULT NULL COMMENT ''User ID who created the order (from admin_orders)'' AFTER `supplier_id`;')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add is_automated column if it doesn't exist
SET @columnname = 'is_automated';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @columnname, '` TINYINT(1) DEFAULT 0 COMMENT ''Whether order was automated (from admin_orders)'' AFTER `user_id`;')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add confirmation_status column if it doesn't exist
SET @columnname = 'confirmation_status';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @columnname, '` ENUM(''pending'',''confirmed'',''cancelled'',''delivered'',''completed'') DEFAULT ''completed'' COMMENT ''Order status from admin_orders'' AFTER `order_date`;')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add confirmation_date column if it doesn't exist
SET @columnname = 'confirmation_date';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @tablename, '` ADD COLUMN `', @columnname, '` DATETIME DEFAULT NULL COMMENT ''Date when order was confirmed (from admin_orders.confirmation_date)'' AFTER `confirmation_status`;')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Add indexes for new columns (check if index exists first)
SET @indexname = 'idx_user_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (index_name = @indexname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @tablename, '` ADD INDEX `', @indexname, '` (`user_id`);')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @indexname = 'idx_confirmation_status';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (index_name = @indexname)
  ) > 0,
  'SELECT 1',
  CONCAT('ALTER TABLE `', @tablename, '` ADD INDEX `', @indexname, '` (`confirmation_status`);')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Update existing records to sync with admin_orders data
UPDATE `inventory_from_completed_orders` ifco
INNER JOIN `admin_orders` ao ON ifco.order_id = ao.id AND ifco.order_table = 'admin_orders'
SET 
    ifco.supplier_id = ao.supplier_id,
    ifco.user_id = ao.user_id,
    ifco.is_automated = ao.is_automated,
    ifco.confirmation_status = ao.confirmation_status,
    ifco.confirmation_date = ao.confirmation_date,
    ifco.order_date = ao.order_date
WHERE ifco.order_table = 'admin_orders';

