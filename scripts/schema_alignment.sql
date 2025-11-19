-- Schema alignment checks and non-destructive fixes
-- This script intentionally uses simple statements to avoid destructive changes.
-- Review and run as needed in staging before production.

-- Verify foreign keys exist (examples; adjust names to your actual schema)
-- Orders.inventory_id -> Inventory.id
SELECT CONSTRAINT_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'orders'
  AND COLUMN_NAME = 'inventory_id';

-- Deliveries.order_id -> Orders.id
SELECT CONSTRAINT_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'deliveries'
  AND COLUMN_NAME = 'order_id';

-- Payments.order_id -> Orders.id
SELECT CONSTRAINT_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'payments'
  AND COLUMN_NAME = 'order_id';

-- Suggested indexes for performance (use IF NOT EXISTS pattern via information_schema checks)
-- Create indexes if missing
ALTER TABLE inventory ADD INDEX idx_inventory_sku (sku);
ALTER TABLE inventory ADD INDEX idx_inventory_supplier (supplier_id);
ALTER TABLE orders ADD INDEX idx_orders_inventory (inventory_id);
ALTER TABLE deliveries ADD INDEX idx_deliveries_order (order_id);
ALTER TABLE payments ADD INDEX idx_payments_order (order_id);
ALTER TABLE sales_transactions ADD INDEX idx_sales_inventory (inventory_id);
ALTER TABLE alert_logs ADD INDEX idx_alerts_inventory (inventory_id);

-- Add soft-delete flags if missing (example columns)
ALTER TABLE supplier_catalog ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) DEFAULT 0;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) DEFAULT 0;