-- SQL Script: Sync all products from supplier_catalog to inventory table
-- This ensures all products from supplier/products.php are available in inventory for ordering
-- Run this script if you need to fix missing inventory items directly in the database

-- Step 1: Create inventory items for all supplier_catalog products that don't have corresponding inventory items
-- (Only for products with valid SKUs and supplier_ids)

INSERT INTO inventory (
    sku, 
    name, 
    description, 
    category, 
    unit_price, 
    quantity, 
    reorder_threshold, 
    supplier_id, 
    location,
    is_deleted
)
SELECT 
    sc.sku,
    sc.name,
    sc.description,
    sc.category,
    sc.unit_price,
    0 as quantity,
    sc.reorder_threshold,
    sc.supplier_id,
    sc.location,
    0 as is_deleted
FROM supplier_catalog sc
WHERE COALESCE(sc.is_deleted, 0) = 0
  AND sc.sku IS NOT NULL 
  AND sc.sku != ''
  AND sc.supplier_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 
      FROM inventory i 
      WHERE i.sku = sc.sku 
        AND i.supplier_id = sc.supplier_id
        AND COALESCE(i.is_deleted, 0) = 0
  );

-- Step 2: Update supplier_catalog.source_inventory_id for all products
-- This links supplier_catalog products to their corresponding inventory items

UPDATE supplier_catalog sc
SET source_inventory_id = (
    SELECT i.id 
    FROM inventory i 
    WHERE i.sku = sc.sku 
      AND i.supplier_id = sc.supplier_id
      AND COALESCE(i.is_deleted, 0) = 0
    LIMIT 1
)
WHERE sc.source_inventory_id IS NULL
  AND COALESCE(sc.is_deleted, 0) = 0
  AND sc.sku IS NOT NULL 
  AND sc.sku != ''
  AND EXISTS (
      SELECT 1 
      FROM inventory i 
      WHERE i.sku = sc.sku 
        AND i.supplier_id = sc.supplier_id
        AND COALESCE(i.is_deleted, 0) = 0
  );

-- Step 3: Restore soft-deleted inventory items that have corresponding active supplier_catalog products
UPDATE inventory i
INNER JOIN supplier_catalog sc ON i.sku = sc.sku AND i.supplier_id = sc.supplier_id
SET i.is_deleted = 0
WHERE COALESCE(i.is_deleted, 0) = 1
  AND COALESCE(sc.is_deleted, 0) = 0
  AND sc.sku IS NOT NULL 
  AND sc.sku != '';

-- Step 4: Sync variations from supplier_product_variations to inventory_variations
-- (Only create variations that don't already exist)

INSERT INTO inventory_variations (
    inventory_id,
    variation,
    unit_type,
    quantity,
    unit_price
)
SELECT 
    i.id as inventory_id,
    spv.variation,
    COALESCE(spv.unit_type, sc.unit_type, 'per piece') as unit_type,
    0 as quantity,
    spv.unit_price
FROM supplier_product_variations spv
INNER JOIN supplier_catalog sc ON spv.product_id = sc.id
INNER JOIN inventory i ON i.sku = sc.sku AND i.supplier_id = sc.supplier_id
WHERE COALESCE(sc.is_deleted, 0) = 0
  AND COALESCE(i.is_deleted, 0) = 0
  AND sc.sku IS NOT NULL 
  AND sc.sku != ''
  AND spv.variation IS NOT NULL
  AND spv.variation != ''
  AND NOT EXISTS (
      SELECT 1 
      FROM inventory_variations iv
      WHERE iv.inventory_id = i.id
        AND iv.variation = spv.variation
  );

-- Verification queries (run these to check the results):

-- Check how many products were synced:
SELECT 
    COUNT(*) as total_supplier_catalog_products,
    SUM(CASE WHEN source_inventory_id IS NOT NULL THEN 1 ELSE 0 END) as linked_to_inventory,
    SUM(CASE WHEN source_inventory_id IS NULL AND (sku IS NULL OR sku = '') THEN 1 ELSE 0 END) as missing_sku,
    SUM(CASE WHEN source_inventory_id IS NULL AND sku IS NOT NULL AND sku != '' THEN 1 ELSE 0 END) as not_linked
FROM supplier_catalog
WHERE COALESCE(is_deleted, 0) = 0;

-- Check for products that still need attention:
SELECT 
    sc.id as catalog_id,
    sc.name,
    sc.sku,
    s.name as supplier_name,
    sc.source_inventory_id,
    CASE 
        WHEN sc.sku IS NULL OR sc.sku = '' THEN 'Missing SKU'
        WHEN sc.source_inventory_id IS NULL THEN 'Not linked to inventory'
        ELSE 'OK'
    END as status
FROM supplier_catalog sc
LEFT JOIN suppliers s ON sc.supplier_id = s.id
WHERE COALESCE(sc.is_deleted, 0) = 0
  AND (sc.sku IS NULL OR sc.sku = '' OR sc.source_inventory_id IS NULL)
ORDER BY sc.supplier_id, sc.id;

