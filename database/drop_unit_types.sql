-- Drop unit types-related schema introduced by add_unit_types.sql
-- Order matters due to foreign key constraints
SET FOREIGN_KEY_CHECKS = 0;

-- Link table referencing unit_types
DROP TABLE IF EXISTS supplier_catalog_unit;

-- Variations table referencing unit_types
DROP TABLE IF EXISTS unit_type_variations;

-- Base unit_types table
DROP TABLE IF EXISTS unit_types;

SET FOREIGN_KEY_CHECKS = 1;