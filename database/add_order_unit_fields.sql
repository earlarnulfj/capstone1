-- Migration: Add unit_type and variation columns to orders
-- Run this against your MySQL database

ALTER TABLE `orders`
  ADD COLUMN `unit_type` VARCHAR(50) NOT NULL DEFAULT 'per piece' AFTER `unit_price`,
  ADD COLUMN `variation` VARCHAR(50) NULL AFTER `unit_type`;