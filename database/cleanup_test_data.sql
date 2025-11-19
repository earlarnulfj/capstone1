-- Database cleanup script for whitebox testing preparation
-- This script removes test databases, test tables, and test user accounts
-- WARNING: Review carefully before running

-- Drop test database if it exists
DROP DATABASE IF EXISTS `test`;

-- Remove test user accounts (adjust patterns as needed)
-- Uncomment and modify the following lines based on your test user naming conventions
-- DELETE FROM users WHERE username LIKE '%test%' OR email LIKE '%test%';
-- DELETE FROM users WHERE username LIKE '%demo%' OR email LIKE '%demo%';

-- Remove test data from main tables (be careful with this)
-- Uncomment only if you have test data that needs to be removed
-- DELETE FROM inventory WHERE sku LIKE 'TEST_%';
-- DELETE FROM orders WHERE order_number LIKE 'TEST_%';

-- Optimize tables after cleanup
OPTIMIZE TABLE inventory;
OPTIMIZE TABLE orders;
OPTIMIZE TABLE admin_orders;
OPTIMIZE TABLE suppliers;
OPTIMIZE TABLE users;

