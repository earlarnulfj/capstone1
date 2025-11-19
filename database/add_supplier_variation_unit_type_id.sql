-- Add unit_type_id to supplier_product_variations and backfill from unit_type string
ALTER TABLE `supplier_product_variations`
  ADD COLUMN `unit_type_id` INT NULL AFTER `variation`,
  ADD INDEX `idx_unit_type_id` (`unit_type_id`);

-- Add foreign key to unit_types(id)
ALTER TABLE `supplier_product_variations`
  ADD CONSTRAINT `fk_spv_unit_type_id`
    FOREIGN KEY (`unit_type_id`) REFERENCES `unit_types`(`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL;

-- Backfill unit_type_id using the normalized unit_type string (e.g., 'per piece' -> name 'piece')
UPDATE `supplier_product_variations` spv
JOIN `unit_types` ut ON LOWER(ut.name) = LOWER(SUBSTRING(spv.unit_type, 5))
SET spv.unit_type_id = ut.id
WHERE spv.unit_type IS NOT NULL AND spv.unit_type <> '' AND spv.unit_type_id IS NULL;

-- Note: keep existing unit_type string column for backward compatibility.