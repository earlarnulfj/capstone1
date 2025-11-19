-- Quick Fix: Add Missing Columns to users table
-- Copy and paste this entire block into phpMyAdmin SQL tab and run it

ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `first_name` VARCHAR(100) NULL AFTER `email`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `middle_name` VARCHAR(100) NULL AFTER `first_name`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `last_name` VARCHAR(100) NULL AFTER `middle_name`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `address` TEXT NULL AFTER `phone`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `city` VARCHAR(100) NULL AFTER `address`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `province` VARCHAR(100) NULL AFTER `city`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `postal_code` VARCHAR(20) NULL AFTER `province`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `profile_picture` VARCHAR(255) NULL AFTER `postal_code`;

-- Add columns to suppliers table
ALTER TABLE `suppliers` ADD COLUMN IF NOT EXISTS `city` VARCHAR(100) NULL AFTER `address`;
ALTER TABLE `suppliers` ADD COLUMN IF NOT EXISTS `province` VARCHAR(100) NULL AFTER `city`;
ALTER TABLE `suppliers` ADD COLUMN IF NOT EXISTS `postal_code` VARCHAR(20) NULL AFTER `province`;

