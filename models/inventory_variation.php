<?php
class InventoryVariation {
    private $conn;
    private $table_name = "inventory_variations";

    public $id;
    public $inventory_id;
    public $variation;
    public $unit_type;
    public $quantity;
    public $last_updated;
    // Add price property for variations
    public $unit_price;

    public function __construct($db) {
        $this->conn = $db;
    }
    private function tableExists() {
        try {
            $stmt = $this->conn->prepare("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t");
            $stmt->bindParam(':t', $this->table_name);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return isset($row['cnt']) ? ((int)$row['cnt'] > 0) : false;
        } catch (Exception $e) {
            return false;
        }
    }
    // Ensure unit_price column exists in variations table
    private function ensurePriceColumn() {
        if (!$this->tableExists()) { return false; }
        try {
            $check = $this->conn->prepare("SELECT COUNT(*) AS cnt FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = 'unit_price'");
            $check->bindParam(':t', $this->table_name);
            $check->execute();
            $row = $check->fetch(PDO::FETCH_ASSOC);
            $exists = isset($row['cnt']) ? ((int)$row['cnt'] > 0) : false;
            if (!$exists) {
                $this->conn->exec("ALTER TABLE `".$this->table_name."` ADD COLUMN `unit_price` DECIMAL(10,2) NULL DEFAULT NULL AFTER `quantity`");
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    // Delete a single variant row. If unit_type is null, deletes any matching variation rows for the inventory.
    public function deleteVariant($inventory_id, $variation, $unit_type = null) {
        if (!$this->tableExists()) { return false; }
        if ($unit_type === null || $unit_type === '') {
            $query = "DELETE FROM " . $this->table_name . " WHERE inventory_id = :inventory_id AND variation = :variation";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':inventory_id', $inventory_id, PDO::PARAM_INT);
            $stmt->bindParam(':variation', $variation);
            return $stmt->execute();
        } else {
            $query = "DELETE FROM " . $this->table_name . " WHERE inventory_id = :inventory_id AND variation = :variation AND LOWER(unit_type) = LOWER(:unit_type)";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':inventory_id', $inventory_id, PDO::PARAM_INT);
            $stmt->bindParam(':variation', $variation);
            $stmt->bindParam(':unit_type', $unit_type);
            return $stmt->execute();
        }
    }

    // Bulk delete variation rows for an inventory. If unit_type is provided, restrict deletion to that unit type.
    public function deleteVariantsBulk($inventory_id, array $variations, $unit_type = null) {
        if (!$this->tableExists()) { return false; }
        if (empty($variations)) { return true; }
        // Build placeholders safely
        $placeholders = [];
        $params = [':inventory_id' => $inventory_id];
        foreach ($variations as $idx => $var) {
            $ph = ':v' . $idx;
            $placeholders[] = $ph;
            $params[$ph] = (string)$var;
        }
        if ($unit_type === null || $unit_type === '') {
            $query = "DELETE FROM " . $this->table_name . " WHERE inventory_id = :inventory_id AND variation IN (" . implode(',', $placeholders) . ")";
            $stmt = $this->conn->prepare($query);
            foreach ($params as $k => $v) {
                if ($k === ':inventory_id') { $stmt->bindValue($k, $v, PDO::PARAM_INT); }
                else { $stmt->bindValue($k, $v); }
            }
            return $stmt->execute();
        } else {
            $query = "DELETE FROM " . $this->table_name . " WHERE inventory_id = :inventory_id AND LOWER(unit_type) = LOWER(:unit_type) AND variation IN (" . implode(',', $placeholders) . ")";
            $stmt = $this->conn->prepare($query);
            $stmt->bindValue(':inventory_id', $inventory_id, PDO::PARAM_INT);
            $stmt->bindValue(':unit_type', $unit_type);
            foreach ($params as $k => $v) {
                if ($k === ':inventory_id' || $k === ':unit_type') { continue; }
                $stmt->bindValue($k, $v);
            }
            return $stmt->execute();
        }
    }

    // Get all variation stocks for an inventory item
    public function getByInventory($inventory_id) {
        if (!$this->tableExists()) {
            return [];
        }
        $this->ensurePriceColumn();
        $query = "SELECT variation, unit_type, quantity, unit_price FROM " . $this->table_name . " WHERE inventory_id = :inventory_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':inventory_id', $inventory_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get mapping variation => quantity filtered by unit_type
    public function getStocksMap($inventory_id, $unit_type = null) {
        $rows = $this->getByInventory($inventory_id);
        $map = [];
        foreach ($rows as $r) {
            if ($unit_type === null || strtolower($r['unit_type']) === strtolower($unit_type)) {
                $map[$r['variation']] = (int)$r['quantity'];
            }
        }
        return $map;
    }

    // Get comprehensive variation data: stocks, prices, and units
    public function getVariationDataByInventory($inventory_id) {
        $rows = $this->getByInventory($inventory_id);
        $data = [
            'stocks' => [],
            'prices' => [],
            'units' => []
        ];
        if (empty($rows)) { return $data; }
        foreach ($rows as $row) {
            $variation = $row['variation'];
            $data['stocks'][$variation] = (int)$row['quantity'];
            if (isset($row['unit_price']) && $row['unit_price'] !== null) {
                $data['prices'][$variation] = (float)$row['unit_price'];
            }
            if (isset($row['unit_type']) && $row['unit_type'] !== null) {
                $data['units'][$variation] = $row['unit_type'];
            }
        }
        return $data;
    }

    // Get specific variant stock
    public function getStock($inventory_id, $variation, $unit_type = 'per piece') {
        if (!$this->tableExists()) {
            return 0;
        }
        $query = "SELECT quantity FROM " . $this->table_name . " WHERE inventory_id = :inventory_id AND variation = :variation AND LOWER(unit_type) = LOWER(:unit_type) LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':inventory_id', $inventory_id, PDO::PARAM_INT);
        $stmt->bindParam(':variation', $variation);
        $stmt->bindParam(':unit_type', $unit_type);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['quantity'] : 0;
    }

    // Get specific variant unit price (raw cost), null if missing
    public function getPrice($inventory_id, $variation, $unit_type = 'per piece') {
        if (!$this->tableExists()) { return null; }
        if (!$this->ensurePriceColumn()) { return null; }
        $query = "SELECT unit_price FROM " . $this->table_name . " WHERE inventory_id = :inventory_id AND variation = :variation AND LOWER(unit_type) = LOWER(:unit_type) LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':inventory_id', $inventory_id, PDO::PARAM_INT);
        $stmt->bindParam(':variation', $variation);
        $stmt->bindParam(':unit_type', $unit_type);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $p = $row['unit_price'];
        if ($p === null) return null;
        $p = (float)$p;
        return $p > 0 ? $p : null;
    }

    // Decrement variant stock safely
    public function decrementStock($inventory_id, $variation, $unit_type, $qty) {
        if (!$this->tableExists()) {
            return false;
        }
        // Use distinct parameter names to avoid HY093 when native prepares are used
        $query = "UPDATE " . $this->table_name . " 
                  SET quantity = quantity - :qty_sub 
                  WHERE inventory_id = :inventory_id 
                    AND variation = :variation 
                    AND LOWER(unit_type) = LOWER(:unit_type) 
                    AND quantity >= :qty_min";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':qty_sub', $qty, PDO::PARAM_INT);
        $stmt->bindParam(':inventory_id', $inventory_id, PDO::PARAM_INT);
        $stmt->bindParam(':variation', $variation);
        $stmt->bindParam(':unit_type', $unit_type);
        $stmt->bindParam(':qty_min', $qty, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    // Update stock to an absolute value
    public function updateStock($inventory_id, $variation, $unit_type, $newQty) {
        if (!$this->tableExists()) {
            return false;
        }
        $query = "UPDATE " . $this->table_name . " SET quantity = :qty WHERE inventory_id = :inventory_id AND variation = :variation AND LOWER(unit_type) = LOWER(:unit_type)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':qty', $newQty, PDO::PARAM_INT);
        $stmt->bindParam(':inventory_id', $inventory_id, PDO::PARAM_INT);
        $stmt->bindParam(':variation', $variation);
        $stmt->bindParam(':unit_type', $unit_type);
        return $stmt->execute();
    }

    // Update or set variant unit price
    public function updatePrice($inventory_id, $variation, $unit_type, $price) {
        if (!$this->tableExists()) { return false; }
        if (!$this->ensurePriceColumn()) { return false; }
        $p = is_numeric($price) ? (float)$price : null;
        $query = "UPDATE " . $this->table_name . " SET unit_price = :price, last_updated = NOW() WHERE inventory_id = :inventory_id AND variation = :variation AND LOWER(unit_type) = LOWER(:unit_type)";
        $stmt = $this->conn->prepare($query);
        if ($p === null) {
            $stmt->bindValue(':price', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':price', $p);
        }
        $stmt->bindParam(':inventory_id', $inventory_id, PDO::PARAM_INT);
        $stmt->bindParam(':variation', $variation);
        $stmt->bindParam(':unit_type', $unit_type);
        return $stmt->execute();
    }

    // Create a variant record if missing
    public function createVariant($inventory_id, $variation, $unit_type, $initialQty = 0, $price = null) {
        if (!$this->tableExists()) {
            return false;
        }
        $this->ensurePriceColumn();
        $query = "INSERT INTO " . $this->table_name . " (inventory_id, variation, unit_type, quantity, unit_price, last_updated) VALUES (:inventory_id, :variation, :unit_type, :quantity, :unit_price, NOW())";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':inventory_id', $inventory_id, PDO::PARAM_INT);
        $stmt->bindParam(':variation', $variation);
        $stmt->bindParam(':unit_type', $unit_type);
        $stmt->bindParam(':quantity', $initialQty, PDO::PARAM_INT);
        if (is_numeric($price) && (float)$price > 0) {
            $stmt->bindValue(':unit_price', (float)$price);
        } else {
            $stmt->bindValue(':unit_price', null, PDO::PARAM_NULL);
        }
        return $stmt->execute();
    }

    // Increment variant stock (creates record if missing)
    public function incrementStock($inventory_id, $variation, $unit_type, $qty) {
        if (!$this->tableExists()) {
            return false;
        }
        // Try to update existing record
        $query = "UPDATE " . $this->table_name . " SET quantity = quantity + :qty, last_updated = NOW() WHERE inventory_id = :inventory_id AND variation = :variation AND LOWER(unit_type) = LOWER(:unit_type)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':qty', $qty, PDO::PARAM_INT);
        $stmt->bindParam(':inventory_id', $inventory_id, PDO::PARAM_INT);
        $stmt->bindParam(':variation', $variation);
        $stmt->bindParam(':unit_type', $unit_type);
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            return true;
        }
        // If no rows updated, create the variant record then set quantity
        return $this->createVariant($inventory_id, $variation, $unit_type, $qty);
    }
}