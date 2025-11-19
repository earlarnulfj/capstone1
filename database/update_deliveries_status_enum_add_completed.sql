-- Migration: add 'completed' to deliveries.status enum
ALTER TABLE `deliveries`
  MODIFY `status` ENUM('pending','in_transit','delivered','completed','cancelled') DEFAULT 'pending';