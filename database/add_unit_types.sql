-- Create unit types and variation attributes tables
CREATE TABLE IF NOT EXISTS unit_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(16) NOT NULL UNIQUE,
  name VARCHAR(64) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS unit_type_variations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  unit_type_id INT NOT NULL,
  attribute VARCHAR(64) NOT NULL,
  value VARCHAR(128) NOT NULL,
  UNIQUE KEY uniq_ut_attr_val (unit_type_id, attribute, value),
  FOREIGN KEY (unit_type_id) REFERENCES unit_types(id) ON DELETE CASCADE
);

-- Link supplier catalog items to unit types
CREATE TABLE IF NOT EXISTS supplier_catalog_unit (
  supplier_catalog_id INT NOT NULL,
  unit_type_id INT NOT NULL,
  PRIMARY KEY (supplier_catalog_id),
  FOREIGN KEY (supplier_catalog_id) REFERENCES supplier_catalog(id) ON DELETE CASCADE,
  FOREIGN KEY (unit_type_id) REFERENCES unit_types(id) ON DELETE RESTRICT
);

-- Seed predefined unit types
INSERT INTO unit_types (code, name) VALUES
('pc','per piece'),('set','per set'),('box','per box'),('pack','per pack'),('bag','per bag'),('roll','per roll'),
('bar','per bar'),('sheet','per sheet'),('m','per meter'),('L','per liter'),('gal','per gallon'),('tube','per tube'),('btl','per bottle'),('can','per can'),('sack','per sack')
ON DUPLICATE KEY UPDATE name = VALUES(name);