-- Migration: Add unit_type and variation columns to sales_transactions
-- Run this against your MySQL database

ALTER TABLE `sales_transactions`
  ADD COLUMN `unit_type` VARCHAR(50) NOT NULL DEFAULT 'per piece' AFTER `quantity`,
  ADD COLUMN `variation` VARCHAR(255) NULL AFTER `unit_type`;

