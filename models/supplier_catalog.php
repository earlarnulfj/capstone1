<?php
class SupplierCatalog {
    private $conn;
    private $table_name = "supplier_catalog";

    public $id;
    public $supplier_id;
    public $sku;
    public $name;
    public $description;
    public $category;
    public $unit_price;
    public $unit_type;
    public $supplier_quantity;
    public $reorder_threshold;
    public $location;
    public $image_path;
    public $image_url;
    public $status;
    public $is_deleted;

    public function __construct($db) { $this->conn = $db; }

    public function skuExistsForSupplier($sku, $supplier_id, $exclude_id = null) {
        $sql = "SELECT id FROM {$this->table_name} WHERE supplier_id = :sid AND sku = :sku AND is_deleted = 0";
        if ($exclude_id) { $sql .= " AND id <> :eid"; }
        $stmt = $this->conn->prepare($sql);
        $stmt->bindParam(':sid', $supplier_id, PDO::PARAM_INT);
        $stmt->bindParam(':sku', $sku);
        if ($exclude_id) { $stmt->bindParam(':eid', $exclude_id, PDO::PARAM_INT); }
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    /**
     * Generate a unique SKU for a supplier by appending a numeric suffix
     * when a collision is detected. Respects soft-deletes (is_deleted = 0).
     */
    public function generateUniqueSkuForSupplier($baseSku, $supplier_id) {
        $baseSku = trim((string)$baseSku);
        if ($baseSku === '') { return ''; }
        $candidate = $baseSku;
        $suffix = 1;
        while ($this->skuExistsForSupplier($candidate, $supplier_id)) {
            $candidate = $baseSku . '-' . $suffix;
            $suffix++;
            if ($suffix > 1000) { break; }
        }
        return $candidate;
    }

    public function createForSupplier($supplier_id) {
        $query = "INSERT INTO {$this->table_name}
                  (supplier_id, sku, name, description, category, unit_price, unit_type, supplier_quantity, reorder_threshold, location, image_path, image_url, status, is_deleted)
                  VALUES (:sid, :sku, :name, :description, :category, :unit_price, :unit_type, :supplier_quantity, :reorder_threshold, :location, :image_path, :image_url, 'active', 0)";
        $stmt = $this->conn->prepare($query);
        $this->sku = htmlspecialchars(strip_tags($this->sku));
        $this->name = htmlspecialchars(strip_tags($this->name));
        $this->description = htmlspecialchars(strip_tags($this->description));
        $this->category = htmlspecialchars(strip_tags($this->category));
        $this->unit_price = (float)$this->unit_price;
        $this->unit_type = $this->unit_type ?: 'per piece';
        $this->supplier_quantity = (int)($this->supplier_quantity ?? 0);
        $this->reorder_threshold = (int)($this->reorder_threshold ?? 10);
        $this->location = htmlspecialchars(strip_tags($this->location));
        $stmt->bindParam(':sid', $supplier_id, PDO::PARAM_INT);
        $stmt->bindParam(':sku', $this->sku);
        $stmt->bindParam(':name', $this->name);
        $stmt->bindParam(':description', $this->description);
        $stmt->bindParam(':category', $this->category);
        $stmt->bindParam(':unit_price', $this->unit_price);
        $stmt->bindParam(':unit_type', $this->unit_type);
        $stmt->bindParam(':supplier_quantity', $this->supplier_quantity, PDO::PARAM_INT);
        $stmt->bindParam(':reorder_threshold', $this->reorder_threshold, PDO::PARAM_INT);
        $stmt->bindParam(':location', $this->location);
        $image_path = $this->image_path ?: null;
        $image_url = $this->image_url ?: null;
        $stmt->bindParam(':image_path', $image_path);
        $stmt->bindParam(':image_url', $image_url);
        try {
            return $stmt->execute();
        } catch (PDOException $e) {
            // Map duplicate key errors to a graceful failure for uniq_supplier_sku
            if ((int)$e->getCode() === 23000) {
                return false;
            }
            throw $e;
        }
    }

    public function readBySupplier($supplier_id) {
        // Return all products for the supplier, including inactive/soft-deleted ones,
        // so the management UI can show and filter them appropriately.
        $query = "SELECT * FROM {$this->table_name} WHERE supplier_id = :sid ORDER BY name";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':sid', $supplier_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt;
    }

    public function readOne() {
        $query = "SELECT * FROM {$this->table_name} WHERE id = :id AND is_deleted = 0";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $this->id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function belongsToSupplier($product_id, $supplier_id) {
        $stmt = $this->conn->prepare("SELECT id FROM {$this->table_name} WHERE id = :pid AND supplier_id = :sid AND is_deleted = 0");
        $stmt->bindParam(':pid', $product_id, PDO::PARAM_INT);
        $stmt->bindParam(':sid', $supplier_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    public function updateBySupplier($supplier_id) {
        if (!$this->belongsToSupplier($this->id, $supplier_id)) { return false; }
        $query = "UPDATE {$this->table_name} SET sku = :sku, name = :name, description = :description, category = :category, unit_price = :unit_price, unit_type = :unit_type, location = :location, reorder_threshold = :reorder_threshold WHERE id = :id AND supplier_id = :sid";
        $stmt = $this->conn->prepare($query);
        $this->sku = htmlspecialchars(strip_tags($this->sku));
        $this->name = htmlspecialchars(strip_tags($this->name));
        $this->description = htmlspecialchars(strip_tags($this->description));
        $this->category = htmlspecialchars(strip_tags($this->category));
        $this->unit_price = (float)$this->unit_price;
        $this->unit_type = $this->unit_type ?: 'per piece';
        $this->location = htmlspecialchars(strip_tags($this->location));
        $this->reorder_threshold = (int)($this->reorder_threshold ?? 10);
        $stmt->bindParam(':sku', $this->sku);
        $stmt->bindParam(':name', $this->name);
        $stmt->bindParam(':description', $this->description);
        $stmt->bindParam(':category', $this->category);
        $stmt->bindParam(':unit_price', $this->unit_price);
        $stmt->bindParam(':unit_type', $this->unit_type);
        $stmt->bindParam(':location', $this->location);
        $stmt->bindParam(':reorder_threshold', $this->reorder_threshold, PDO::PARAM_INT);
        $stmt->bindParam(':id', $this->id, PDO::PARAM_INT);
        $stmt->bindParam(':sid', $supplier_id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function softDeleteBySupplier($supplier_id) {
        if (!$this->belongsToSupplier($this->id, $supplier_id)) { return false; }
        $stmt = $this->conn->prepare("UPDATE {$this->table_name} SET is_deleted = 1, status = 'inactive' WHERE id = :id AND supplier_id = :sid");
        $stmt->bindParam(':id', $this->id, PDO::PARAM_INT);
        $stmt->bindParam(':sid', $supplier_id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function updateSupplierStock($product_id, $new_quantity) {
        $stmt = $this->conn->prepare("UPDATE {$this->table_name} SET supplier_quantity = :q WHERE id = :id");
        $q = (int)$new_quantity;
        $stmt->bindParam(':q', $q, PDO::PARAM_INT);
        $stmt->bindParam(':id', $product_id, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function getSupplierStats($supplier_id) {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as total_products,
            SUM(CASE WHEN is_deleted = 0 THEN 1 ELSE 0 END) as active_products,
            SUM(CASE WHEN supplier_quantity <= reorder_threshold AND is_deleted = 0 THEN 1 ELSE 0 END) as low_stock_count
            FROM {$this->table_name} WHERE supplier_id = :sid");
        $stmt->bindParam(':sid', $supplier_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>