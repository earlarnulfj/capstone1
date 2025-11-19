-- Cleanup script for old/unused inventory-related tables
-- WARNING: Review carefully before running - these tables may still be needed for other features
-- Only run this if you're certain these tables are no longer needed

-- Optionally drop old inventory tables if they're not needed
-- Uncomment the lines below ONLY if you're sure these tables are not used elsewhere

-- DROP TABLE IF EXISTS `completed_orders_inventory`;
-- DROP TABLE IF EXISTS `inventory_completed_orders`;

-- Note: The main `inventory` table is still needed for:
-- 1. Order creation (orders reference inventory_id)
-- 2. Supplier catalog sync (for ordering purposes)
-- 3. Other system features

-- Only the display in inventory.php now uses inventory_from_completed_orders table

