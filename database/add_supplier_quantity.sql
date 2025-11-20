-- Migration script to add supplier_quantity column to inventory table
-- This separates supplier-side inventory from admin-side inventory

-- Add supplier_quantity column to track supplier's own inventory
ALTER TABLE inventory 
ADD COLUMN IF NOT EXISTS supplier_quantity INT NOT NULL DEFAULT 0 
AFTER quantity;

-- Update existing records to set supplier_quantity to 0 initially
-- This ensures suppliers start with their own separate inventory tracking
UPDATE inventory SET supplier_quantity = COALESCE(supplier_quantity, 0);

-- Add comment to clarify the difference between columns
ALTER TABLE inventory 
MODIFY COLUMN quantity INT NOT NULL DEFAULT 0,
MODIFY COLUMN supplier_quantity INT NOT NULL DEFAULT 0;