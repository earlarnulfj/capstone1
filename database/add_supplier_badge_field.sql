-- Add supplier_badge column to users table if it does not exist
USE inventory_db;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS supplier_badge VARCHAR(100) NULL DEFAULT 'Supplier' AFTER role;

-- Verify
DESCRIBE users;