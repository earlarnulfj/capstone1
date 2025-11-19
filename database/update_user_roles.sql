-- Update users table to support supplier role
USE inventory_db;

-- Modify the role column to include 'supplier'
ALTER TABLE users MODIFY COLUMN role ENUM('management', 'staff', 'supplier') NOT NULL;

-- Verify the change
DESCRIBE users;