-- Migration: Augment unit_types and unit_type_variations with metadata, soft delete, and indexes
-- Safe to run multiple times; uses IF EXISTS checks via information_schema

-- unit_types: add description, metadata(json/text), soft delete flags, timestamps
ALTER TABLE unit_types
  ADD COLUMN IF NOT EXISTS description TEXT NULL,
  ADD COLUMN IF NOT EXISTS metadata TEXT NULL,
  ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Add helpful indexes
ALTER TABLE unit_types
  ADD INDEX IF NOT EXISTS idx_unit_types_name (name),
  ADD INDEX IF NOT EXISTS idx_unit_types_is_deleted (is_deleted);

-- unit_type_variations: add description, metadata, soft delete flags, timestamps
ALTER TABLE unit_type_variations
  ADD COLUMN IF NOT EXISTS description TEXT NULL,
  ADD COLUMN IF NOT EXISTS metadata TEXT NULL,
  ADD COLUMN IF NOT EXISTS is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Add helpful indexes
ALTER TABLE unit_type_variations
  ADD INDEX IF NOT EXISTS idx_utv_unit_type_id (unit_type_id),
  ADD INDEX IF NOT EXISTS idx_utv_attribute (attribute),
  ADD INDEX IF NOT EXISTS idx_utv_is_deleted (is_deleted);