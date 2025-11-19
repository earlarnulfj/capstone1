-- Add is_deleted column to inventory_from_completed_orders table if it doesn't exist
-- This script aligns the table structure with the code requirements

-- Check and add is_deleted column
ALTER TABLE `inventory_from_completed_orders` 
ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Soft delete flag: 0 = active, 1 = deleted';

-- Add index for is_deleted column if it doesn't exist
ALTER TABLE `inventory_from_completed_orders` 
ADD INDEX IF NOT EXISTS `idx_is_deleted` (`is_deleted`);

