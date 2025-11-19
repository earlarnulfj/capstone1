-- Reset supplier catalog and variations (drop and recreate)
USE inventory_db;

-- Drop child table first to satisfy FK order
DROP TABLE IF EXISTS supplier_product_variations;
DROP TABLE IF EXISTS supplier_catalog;

-- Recreate supplier_catalog
CREATE TABLE IF NOT EXISTS supplier_catalog (
  id INT NOT NULL AUTO_INCREMENT,
  supplier_id INT NOT NULL,
  sku VARCHAR(50) NOT NULL,
  name VARCHAR(100) NOT NULL,
  description TEXT DEFAULT NULL,
  category VARCHAR(50) DEFAULT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  unit_type VARCHAR(20) DEFAULT 'per piece',
  supplier_quantity INT NOT NULL DEFAULT 0,
  reorder_threshold INT NOT NULL DEFAULT 10,
  location VARCHAR(100) DEFAULT NULL,
  image_path VARCHAR(255) DEFAULT NULL,
  image_url VARCHAR(255) DEFAULT NULL,
  status ENUM('active','inactive','deprecated') DEFAULT 'active',
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  source_inventory_id INT DEFAULT NULL,
  last_updated DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_supplier_sku (supplier_id, sku),
  KEY idx_supplier_id (supplier_id),
  CONSTRAINT fk_supplier_catalog_supplier FOREIGN KEY (supplier_id)
    REFERENCES suppliers(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Recreate supplier_product_variations
CREATE TABLE IF NOT EXISTS supplier_product_variations (
  id INT NOT NULL AUTO_INCREMENT,
  product_id INT NOT NULL,
  variation VARCHAR(50) NOT NULL,
  unit_type VARCHAR(20) DEFAULT 'per piece',
  unit_price DECIMAL(10,2) DEFAULT NULL,
  stock INT NOT NULL DEFAULT 0,
  last_updated DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_product_variation (product_id, variation),
  KEY idx_product_id (product_id),
  CONSTRAINT fk_spv_product FOREIGN KEY (product_id)
    REFERENCES supplier_catalog(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;