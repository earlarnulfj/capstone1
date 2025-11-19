-- Create table to manage stock per product variation (e.g., nail sizes)
CREATE TABLE IF NOT EXISTS `inventory_variations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `inventory_id` INT NOT NULL,
  `variation` VARCHAR(50) NOT NULL,
  `unit_type` VARCHAR(50) NOT NULL DEFAULT 'per piece',
  `quantity` INT NOT NULL DEFAULT 0,
  `last_updated` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_inventory_variation_unit` (`inventory_id`, `variation`, `unit_type`),
  CONSTRAINT `fk_inventory_variations_inventory` FOREIGN KEY (`inventory_id`) REFERENCES `inventory`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;