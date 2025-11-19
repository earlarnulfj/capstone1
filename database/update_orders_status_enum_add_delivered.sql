-- Migration: add 'delivered' to orders.confirmation_status enum
ALTER TABLE `orders`
  MODIFY `confirmation_status` ENUM('pending','confirmed','delivered','cancelled') DEFAULT 'pending';