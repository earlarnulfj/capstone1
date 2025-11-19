-- Remove product origin tracking fields from database tables
-- This script is safe to run multiple times; it checks column existence before dropping

SET @dbname = DATABASE();

-- Drop source_inventory_id from supplier_catalog if present
SET @tablename = 'supplier_catalog';
SET @columnname = 'source_inventory_id';
SET @preparedStatement = (
  SELECT IF(
    (
      SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE table_schema = @dbname AND table_name = @tablename AND column_name = @columnname
    ) > 0,
    CONCAT('ALTER TABLE `', @tablename, '` DROP COLUMN `', @columnname, '`;'),
    'SELECT 1'
  )
);
PREPARE dropIfExists FROM @preparedStatement;
EXECUTE dropIfExists;
DEALLOCATE PREPARE dropIfExists;

-- No source_type column exists in inventory; it is computed in queries.
-- Ensure future code avoids computing or displaying origin type.