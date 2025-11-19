-- Migration: add 'completed' to orders.confirmation_status enum (alongside existing values)
ALTER TABLE `orders`
  MODIFY `confirmation_status` ENUM('pending','confirmed','delivered','completed','cancelled') DEFAULT 'pending';