-- Complete SQL Script: Sync and Connect supplier_catalog and inventory tables
-- This ensures all data is synchronized and properly linked between both tables
-- Run this script to ensure complete data consistency

-- ============================================================================
-- STEP 1: Create missing inventory items from supplier_catalog
-- ============================================================================
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

-- ============================================================================
-- STEP 2: Update source_inventory_id links in supplier_catalog
-- ============================================================================
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

-- ============================================================================
-- STEP 3: Restore soft-deleted inventory items
-- ============================================================================
UPDATE inventory i
INNER JOIN supplier_catalog sc ON i.sku = sc.sku AND i.supplier_id = sc.supplier_id
SET i.is_deleted = 0
WHERE COALESCE(i.is_deleted, 0) = 1
  AND COALESCE(sc.is_deleted, 0) = 0
  AND sc.sku IS NOT NULL 
  AND sc.sku != '';

-- ============================================================================
-- STEP 4: Synchronize data fields from supplier_catalog to inventory
-- ============================================================================
UPDATE inventory i
INNER JOIN supplier_catalog sc ON i.sku = sc.sku AND i.supplier_id = sc.supplier_id
SET 
    i.name = sc.name,
    i.description = sc.description,
    i.category = sc.category,
    i.unit_price = sc.unit_price,
    i.location = sc.location,
    i.reorder_threshold = sc.reorder_threshold
WHERE COALESCE(sc.is_deleted, 0) = 0
  AND COALESCE(i.is_deleted, 0) = 0
  AND sc.sku IS NOT NULL 
  AND sc.sku != ''
  AND (
      i.name != sc.name OR
      COALESCE(i.description, '') != COALESCE(sc.description, '') OR
      COALESCE(i.category, '') != COALESCE(sc.category, '') OR
      ABS(COALESCE(i.unit_price, 0) - COALESCE(sc.unit_price, 0)) > 0.01 OR
      COALESCE(i.location, '') != COALESCE(sc.location, '') OR
      COALESCE(i.reorder_threshold, 10) != COALESCE(sc.reorder_threshold, 10)
  );

-- ============================================================================
-- STEP 5: Sync variations from supplier_product_variations to inventory_variations
-- ============================================================================
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

-- ============================================================================
-- STEP 6: Update variation prices and unit types to match supplier data
-- ============================================================================
UPDATE inventory_variations iv
INNER JOIN inventory i ON iv.inventory_id = i.id
INNER JOIN supplier_catalog sc ON i.sku = sc.sku AND i.supplier_id = sc.supplier_id
INNER JOIN supplier_product_variations spv ON spv.product_id = sc.id AND spv.variation = iv.variation
SET 
    iv.unit_price = spv.unit_price,
    iv.unit_type = COALESCE(spv.unit_type, sc.unit_type, 'per piece')
WHERE COALESCE(sc.is_deleted, 0) = 0
  AND COALESCE(i.is_deleted, 0) = 0
  AND (
      COALESCE(iv.unit_price, 0) != COALESCE(spv.unit_price, 0) OR
      iv.unit_type != COALESCE(spv.unit_type, sc.unit_type, 'per piece')
  );

-- ============================================================================
-- VERIFICATION QUERIES
-- ============================================================================

-- Check connection status
SELECT 
    'Connection Status' as check_type,
    COUNT(*) as total_catalog_products,
    SUM(CASE WHEN source_inventory_id IS NOT NULL THEN 1 ELSE 0 END) as linked_to_inventory,
    SUM(CASE WHEN source_inventory_id IS NULL AND (sku IS NULL OR sku = '') THEN 1 ELSE 0 END) as missing_sku,
    SUM(CASE WHEN source_inventory_id IS NULL AND sku IS NOT NULL AND sku != '' THEN 1 ELSE 0 END) as not_linked
FROM supplier_catalog
WHERE COALESCE(is_deleted, 0) = 0;

-- Check data consistency
SELECT 
    'Data Consistency' as check_type,
    COUNT(*) as total_connected_products,
    SUM(CASE WHEN 
        sc.name = i.name AND
        COALESCE(sc.description, '') = COALESCE(i.description, '') AND
        COALESCE(sc.category, '') = COALESCE(i.category, '') AND
        ABS(COALESCE(sc.unit_price, 0) - COALESCE(i.unit_price, 0)) < 0.01 AND
        COALESCE(sc.location, '') = COALESCE(i.location, '') AND
        COALESCE(sc.reorder_threshold, 10) = COALESCE(i.reorder_threshold, 10)
    THEN 1 ELSE 0 END) as synchronized,
    SUM(CASE WHEN 
        sc.name != i.name OR
        COALESCE(sc.description, '') != COALESCE(i.description, '') OR
        COALESCE(sc.category, '') != COALESCE(i.category, '') OR
        ABS(COALESCE(sc.unit_price, 0) - COALESCE(i.unit_price, 0)) >= 0.01 OR
        COALESCE(sc.location, '') != COALESCE(i.location, '') OR
        COALESCE(sc.reorder_threshold, 10) != COALESCE(i.reorder_threshold, 10)
    THEN 1 ELSE 0 END) as needs_sync
FROM supplier_catalog sc
INNER JOIN inventory i ON i.sku = sc.sku AND i.supplier_id = sc.supplier_id
WHERE COALESCE(sc.is_deleted, 0) = 0 
  AND COALESCE(i.is_deleted, 0) = 0;

-- List products that need attention
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

