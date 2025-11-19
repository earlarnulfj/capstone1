<?php
class SupplierProductVariation {
    private $conn;
    private $table_name = "supplier_product_variations";

    public function __construct($db) { $this->conn = $db; }

    public function createVariant($product_id, $variation, $unit_type, $stock = 0, $price = null) {
        $stmt = $this->conn->prepare("INSERT INTO {$this->table_name} (product_id, variation, unit_type, stock, unit_price) VALUES (:pid, :var, :ut, :st, :up)");
        $stmt->bindParam(':pid', $product_id, PDO::PARAM_INT);
        $v = htmlspecialchars(strip_tags($variation));
        $stmt->bindParam(':var', $v);
        $u = $unit_type ?: 'per piece';
        $stmt->bindParam(':ut', $u);
        $s = (int)$stock;
        $stmt->bindParam(':st', $s, PDO::PARAM_INT);
        if ($price === null) { $stmt->bindValue(':up', null, PDO::PARAM_NULL); }
        else { $p = (float)$price; $stmt->bindParam(':up', $p); }
        return $stmt->execute();
    }

    public function updatePrice($product_id, $variation, $unit_type, $price) {
        $stmt = $this->conn->prepare("UPDATE {$this->table_name} SET unit_price = :up, unit_type = :ut WHERE product_id = :pid AND variation = :var");
        $stmt->bindParam(':pid', $product_id, PDO::PARAM_INT);
        $v = htmlspecialchars(strip_tags($variation));
        $stmt->bindParam(':var', $v);
        $u = $unit_type ?: 'per piece';
        $stmt->bindParam(':ut', $u);
        if ($price === null) { $stmt->bindValue(':up', null, PDO::PARAM_NULL); }
        else { $p = (float)$price; $stmt->bindParam(':up', $p); }
        return $stmt->execute();
    }

    public function getByProduct($product_id) {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table_name} WHERE product_id = :pid ORDER BY variation");
        $stmt->bindParam(':pid', $product_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStocksMap($product_id, $unit_type = null) {
        $sql = "SELECT variation, stock FROM {$this->table_name} WHERE product_id = :pid";
        if ($unit_type) { $sql .= " AND unit_type = :ut"; }
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':pid', $product_id, PDO::PARAM_INT);
        if ($unit_type) { $stmt->bindParam(':ut', $unit_type); }
        $stmt->execute();
        $res = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $res[$row['variation']] = (int)$row['stock']; }
        return $res;
    }

    // Get mapping variation => unit_price (raw supplier price)
    public function getPricesMap($product_id, $unit_type = null) {
        $sql = "SELECT variation, unit_price FROM {$this->table_name} WHERE product_id = :pid";
        if ($unit_type) { $sql .= " AND unit_type = :ut"; }
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':pid', $product_id, PDO::PARAM_INT);
        if ($unit_type) { $stmt->bindParam(':ut', $unit_type); }
        $stmt->execute();
        $res = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['unit_price'] !== null) { $res[$row['variation']] = (float)$row['unit_price']; }
        }
        return $res;
    }

    // Get mapping variation => unit_type
    public function getUnitTypesMap($product_id) {
        $sql = "SELECT variation, unit_type FROM {$this->table_name} WHERE product_id = :pid";
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':pid', $product_id, PDO::PARAM_INT);
        $stmt->execute();
        $res = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $res[$row['variation']] = $row['unit_type']; }
        return $res;
    }

    // Update stock for a specific product variation
    public function updateStock($product_id, $variation, $unit_type, $stock) {
        $stmt = $this->conn->prepare("UPDATE {$this->table_name} SET stock = :st, unit_type = :ut WHERE product_id = :pid AND variation = :var");
        $stmt->bindParam(':pid', $product_id, PDO::PARAM_INT);
        $v = htmlspecialchars(strip_tags($variation));
        $stmt->bindParam(':var', $v);
        $u = $unit_type ?: 'per piece';
        $stmt->bindParam(':ut', $u);
        $s = is_numeric($stock) ? max(0, (int)$stock) : 0;
        $stmt->bindParam(':st', $s, PDO::PARAM_INT);
        return $stmt->execute();
    }

    // Delete a specific variation for a product
    public function deleteVariant($product_id, $variation) {
        $stmt = $this->conn->prepare("DELETE FROM {$this->table_name} WHERE product_id = :pid AND variation = :var");
        $stmt->bindParam(':pid', $product_id, PDO::PARAM_INT);
        $v = htmlspecialchars(strip_tags($variation));
        $stmt->bindParam(':var', $v);
        return $stmt->execute();
    }

    // Bulk delete variations by keys
    public function deleteVariantsBulk($product_id, array $variations) {
        if (empty($variations)) { return true; }
        // Use IN clause safely by binding placeholders
        $placeholders = [];
        $params = [':pid' => (int)$product_id];
        foreach ($variations as $i => $v) {
            $ph = ":v{$i}";
            $placeholders[] = $ph;
            $params[$ph] = htmlspecialchars(strip_tags($v));
        }
        $in = implode(',', $placeholders);
        $sql = "DELETE FROM {$this->table_name} WHERE product_id = :pid AND variation IN ($in)";
        $stmt = $this->conn->prepare($sql);
        foreach ($params as $k => $val) {
            if ($k === ':pid') { $stmt->bindValue($k, $val, PDO::PARAM_INT); }
            else { $stmt->bindValue($k, $val); }
        }
        return $stmt->execute();
    }

    // Rename a specific variation key for a product (preserving stock and price)
    public function renameVariant($product_id, $old_variation, $new_variation) {
        $stmt = $this->conn->prepare("UPDATE {$this->table_name} SET variation = :new WHERE product_id = :pid AND variation = :old");
        $stmt->bindParam(':pid', $product_id, PDO::PARAM_INT);
        $o = htmlspecialchars(strip_tags($old_variation));
        $n = htmlspecialchars(strip_tags($new_variation));
        $stmt->bindParam(':old', $o);
        $stmt->bindParam(':new', $n);
        return $stmt->execute();
    }
}
?>