<?php
class Inventory {
    private $conn;
    private $table_name = "inventory";

    public $id;
    public $sku;
    public $name;
    public $description;
    public $quantity;
    public $reorder_threshold;
    public $supplier_id;
    public $category;
    public $unit_price;
    public $location;
    public $last_updated;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Create new inventory item
    public function create() {
        $query = "INSERT INTO " . $this->table_name . " 
                  SET sku = :sku, 
                      name = :name, 
                      description = :description, 
                      quantity = :quantity, 
                      reorder_threshold = :reorder_threshold, 
                      supplier_id = :supplier_id, 
                      category = :category, 
                      unit_price = :unit_price, 
                      location = :location";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $this->sku = htmlspecialchars(strip_tags($this->sku));
        $this->name = htmlspecialchars(strip_tags($this->name));
        $this->description = htmlspecialchars(strip_tags($this->description));
        $this->quantity = htmlspecialchars(strip_tags($this->quantity));
        $this->reorder_threshold = htmlspecialchars(strip_tags($this->reorder_threshold));
        $this->supplier_id = htmlspecialchars(strip_tags($this->supplier_id));
        $this->category = htmlspecialchars(strip_tags($this->category));
        $this->unit_price = htmlspecialchars(strip_tags($this->unit_price));
        $this->location = htmlspecialchars(strip_tags($this->location));

        // Bind values
        $stmt->bindParam(":sku", $this->sku);
        $stmt->bindParam(":name", $this->name);
        $stmt->bindParam(":description", $this->description);
        $stmt->bindParam(":quantity", $this->quantity);
        $stmt->bindParam(":reorder_threshold", $this->reorder_threshold);
        if (is_null($this->supplier_id) || $this->supplier_id === '') {
            $stmt->bindValue(":supplier_id", null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(":supplier_id", (int)$this->supplier_id, PDO::PARAM_INT);
        }
        $stmt->bindParam(":category", $this->category);
        $stmt->bindParam(":unit_price", $this->unit_price);
        $stmt->bindParam(":location", $this->location);

        // Execute query
        return $stmt->execute();
    }

    // Read all inventory items
    public function readAll() {
        // Check if soft-delete column exists
        $hasDeleted = false;
        try {
            $chk = $this->conn->query("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '" . $this->table_name . "' AND column_name = 'is_deleted'");
            $hasDeleted = (bool)$chk->fetchColumn();
        } catch (Exception $e) { $hasDeleted = false; }

        $query = "SELECT i.*, s.name as supplier_name 
                  FROM " . $this->table_name . " i 
                  LEFT JOIN suppliers s ON i.supplier_id = s.id ";
        if ($hasDeleted) {
            $query .= " WHERE COALESCE(i.is_deleted, 0) = 0 ";
        }
        $query .= " ORDER BY i.name";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        return $stmt;
    }

    // Read one inventory item
    public function readOne() {
        $query = "SELECT i.*, s.name as supplier_name 
                  FROM " . $this->table_name . " i 
                  LEFT JOIN suppliers s ON i.supplier_id = s.id 
                  WHERE i.id = :id 
                  LIMIT 0,1";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $this->id = htmlspecialchars(strip_tags($this->id));

        // Bind value
        $stmt->bindParam(":id", $this->id);

        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $this->id = $row['id'];
            $this->sku = $row['sku'];
            $this->name = $row['name'];
            $this->description = $row['description'];
            $this->quantity = $row['quantity'];
            $this->reorder_threshold = $row['reorder_threshold'];
            $this->supplier_id = $row['supplier_id'];
            $this->category = $row['category'];
            $this->unit_price = $row['unit_price'];
            $this->location = $row['location'];
            $this->last_updated = $row['last_updated'];
            
            return true;
        }

        return false;
    }

    // Update inventory item
    public function update() {
        $query = "UPDATE " . $this->table_name . " 
                  SET sku = :sku, 
                      name = :name, 
                      description = :description, 
                      quantity = :quantity, 
                      reorder_threshold = :reorder_threshold, 
                      supplier_id = :supplier_id, 
                      category = :category, 
                      unit_price = :unit_price, 
                      location = :location 
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $this->sku = htmlspecialchars(strip_tags($this->sku));
        $this->name = htmlspecialchars(strip_tags($this->name));
        $this->description = htmlspecialchars(strip_tags($this->description));
        $this->quantity = htmlspecialchars(strip_tags($this->quantity));
        $this->reorder_threshold = htmlspecialchars(strip_tags($this->reorder_threshold));
        $this->supplier_id = htmlspecialchars(strip_tags($this->supplier_id));
        $this->category = htmlspecialchars(strip_tags($this->category));
        $this->unit_price = htmlspecialchars(strip_tags($this->unit_price));
        $this->location = htmlspecialchars(strip_tags($this->location));
        $this->id = htmlspecialchars(strip_tags($this->id));

        // Bind values
        $stmt->bindParam(":sku", $this->sku);
        $stmt->bindParam(":name", $this->name);
        $stmt->bindParam(":description", $this->description);
        $stmt->bindParam(":quantity", $this->quantity);
        $stmt->bindParam(":reorder_threshold", $this->reorder_threshold);
        if (is_null($this->supplier_id) || $this->supplier_id === '') {
            $stmt->bindValue(":supplier_id", null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(":supplier_id", (int)$this->supplier_id, PDO::PARAM_INT);
        }
        $stmt->bindParam(":category", $this->category);
        $stmt->bindParam(":unit_price", $this->unit_price);
        $stmt->bindParam(":location", $this->location);
        $stmt->bindParam(":id", $this->id);

        return $stmt->execute();
    }

    // Delete inventory item
    public function delete() {
        try {
            // Start transaction
            $this->conn->beginTransaction();
            
            // Sanitize
            $this->id = htmlspecialchars(strip_tags($this->id));
            
            // Check if item exists
            $check_query = "SELECT id FROM " . $this->table_name . " WHERE id = :id";
            $check_stmt = $this->conn->prepare($check_query);
            $check_stmt->bindParam(":id", $this->id);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() == 0) {
                $this->conn->rollback();
                return false;
            }
            
            // Check for foreign key constraints - orders table
            $orders_check = "SELECT COUNT(*) as count FROM orders WHERE inventory_id = :id";
            $orders_stmt = $this->conn->prepare($orders_check);
            $orders_stmt->bindParam(":id", $this->id);
            $orders_stmt->execute();
            $orders_result = $orders_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($orders_result['count'] > 0) {
                $this->conn->rollback();
                error_log("Cannot delete inventory item ID {$this->id}: has {$orders_result['count']} associated orders");
                return false;
            }
            
            // Check for foreign key constraints - sales_transactions table
            $sales_check = "SELECT COUNT(*) as count FROM sales_transactions WHERE inventory_id = :id";
            $sales_stmt = $this->conn->prepare($sales_check);
            $sales_stmt->bindParam(":id", $this->id);
            $sales_stmt->execute();
            $sales_result = $sales_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($sales_result['count'] > 0) {
                $this->conn->rollback();
                error_log("Cannot delete inventory item ID {$this->id}: has {$sales_result['count']} associated sales transactions");
                return false;
            }
            
            // If no foreign key constraints, proceed with deletion
            $query = "DELETE FROM " . $this->table_name . " WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id", $this->id);
            $result = $stmt->execute();
            
            if ($result) {
                // Commit transaction
                $this->conn->commit();
                return true;
            } else {
                $this->conn->rollback();
                return false;
            }
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $this->conn->rollback();
            // Log the error for debugging
            error_log("Inventory delete error: " . $e->getMessage());
            return false;
        }
    }

    // Search inventory items
    public function search($keywords) {
        $query = "SELECT i.*, s.name as supplier_name 
                  FROM " . $this->table_name . " i 
                  LEFT JOIN suppliers s ON i.supplier_id = s.id 
                  WHERE i.name LIKE :keywords 
                  OR i.sku LIKE :keywords 
                  OR i.description LIKE :keywords 
                  OR i.category LIKE :keywords 
                  ORDER BY i.name";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $keywords = htmlspecialchars(strip_tags($keywords));
        $keywords = "%{$keywords}%";

        // Bind value
        $stmt->bindParam(":keywords", $keywords);

        $stmt->execute();
        return $stmt;
    }

    // Get low stock items
    public function getLowStock() {
        $query = "SELECT i.*, s.name as supplier_name 
                  FROM " . $this->table_name . " i 
                  LEFT JOIN suppliers s ON i.supplier_id = s.id 
                  WHERE i.quantity <= i.reorder_threshold 
                  ORDER BY i.quantity ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        return $stmt;
    }

    /**
     * Fetch all inventory items supplied by a given supplier
     */
    public function readBySupplier($supplier_id) {
        // Optionally filter out soft-deleted rows if column exists
        $hasDeleted = false;
        try {
            $chk = $this->conn->query("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '" . $this->table_name . "' AND column_name = 'is_deleted'");
            $hasDeleted = (bool)$chk->fetchColumn();
        } catch (Exception $e) { $hasDeleted = false; }

        $query = "SELECT i.*, s.name as supplier_name 
                  FROM " . $this->table_name . " i 
                  LEFT JOIN suppliers s ON i.supplier_id = s.id 
                  WHERE i.supplier_id = :supplier_id ";
        if ($hasDeleted) {
            $query .= " AND COALESCE(i.is_deleted, 0) = 0 ";
        }
        $query .= " AND i.supplier_quantity IS NOT NULL 
                  ORDER BY i.name";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':supplier_id', $supplier_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt;
    }

    /**
     * Fetch only admin-created inventory items (excluding supplier products)
     */
    public function readAdminOnly() {
        $query = "SELECT i.*, s.name as supplier_name 
                  FROM " . $this->table_name . " i 
                  LEFT JOIN suppliers s ON i.supplier_id = s.id 
                  WHERE i.supplier_id IS NULL 
                  ORDER BY i.name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt;
    }

    /**
     * Fetch all inventory items including those from completed deliveries
     */
    public function readAllIncludingDeliveries() {
        // Check if soft-delete column exists
        $hasDeleted = false;
        try {
            $chk = $this->conn->query("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '" . $this->table_name . "' AND column_name = 'is_deleted'");
            $hasDeleted = (bool)$chk->fetchColumn();
        } catch (Exception $e) { $hasDeleted = false; }

        $query = "SELECT i.*, s.name as supplier_name,
                         CASE 
                             WHEN i.supplier_id IS NULL THEN 'Admin Created'
                             ELSE 'From Delivery'
                         END as source_type
                  FROM " . $this->table_name . " i 
                  LEFT JOIN suppliers s ON i.supplier_id = s.id ";
        if ($hasDeleted) {
            $query .= " WHERE COALESCE(i.is_deleted, 0) = 0 ";
        }
        $query .= " ORDER BY i.last_updated DESC, i.name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt;
    }

    // Update admin stock quantity (for admin use only)
    public function updateAdminStock($id, $quantity) {
        $query = "UPDATE " . $this->table_name . " 
                  SET quantity = :quantity 
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $id = htmlspecialchars(strip_tags($id));
        $quantity = htmlspecialchars(strip_tags($quantity));

        // Bind values
        $stmt->bindParam(":quantity", $quantity);
        $stmt->bindParam(":id", $id);

        return $stmt->execute();
    }

    // Update supplier stock quantity (for supplier use only)
    public function updateSupplierStock($id, $quantity) {
        $query = "UPDATE " . $this->table_name . " 
                  SET supplier_quantity = :quantity 
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $id = htmlspecialchars(strip_tags($id));
        $quantity = htmlspecialchars(strip_tags($quantity));

        // Bind values
        $stmt->bindParam(":quantity", $quantity);
        $stmt->bindParam(":id", $id);

        return $stmt->execute();
    }

    // Update main inventory stock quantity (for POS transactions)
    public function updateStock($id, $quantity) {
        $query = "UPDATE " . $this->table_name . " 
                  SET quantity = :quantity 
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $id = htmlspecialchars(strip_tags($id));
        $quantity = htmlspecialchars(strip_tags($quantity));

        // Bind values
        $stmt->bindParam(":quantity", $quantity);
        $stmt->bindParam(":id", $id);

        return $stmt->execute();
    }

    // Atomically decrement base stock for POS sales, preventing overselling
    public function decrementStockSafe($id, $qty) {
        $query = "UPDATE " . $this->table_name . " 
                  SET quantity = quantity - :qty_sub 
                  WHERE id = :id AND quantity >= :qty_min";
        $stmt = $this->conn->prepare($query);
        $id = htmlspecialchars(strip_tags($id));
        $qty = (int)$qty;
        $stmt->bindParam(':qty_sub', $qty, PDO::PARAM_INT);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':qty_min', $qty, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    // Check if SKU exists
    public function skuExists($sku) {
        $query = "SELECT id FROM " . $this->table_name . " WHERE sku = :sku LIMIT 0,1";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $sku = htmlspecialchars(strip_tags($sku));

        // Bind value
        $stmt->bindParam(":sku", $sku);

        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    // Get inventory count
    public function getCount() {
        $query = "SELECT COUNT(*) as total FROM " . $this->table_name;

        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row['total'];
    }

    // Get total inventory value
    public function getTotalValue() {
        $query = "SELECT SUM(quantity * unit_price) as total_value FROM " . $this->table_name;

        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row['total_value'];
    }

    // Check if item needs reordering and create alert
    public function checkReorderStatus() {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE quantity <= reorder_threshold 
                  AND id NOT IN (
                      SELECT inventory_id FROM alert_logs 
                      WHERE alert_type = 'reorder' 
                      AND is_resolved = 0
                  )";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $alertsCreated = 0;

            foreach ($items as $item) {
                // Create alert log
                $alertQuery = "INSERT INTO alert_logs 
                              SET inventory_id = :inventory_id, 
                                  alert_type = 'reorder'";

                $alertStmt = $this->conn->prepare($alertQuery);
                $alertStmt->bindParam(":inventory_id", $item['id']);

                if ($alertStmt->execute()) {
                    $alertsCreated++;
                }
            }

            return $alertsCreated;
        }

        return 0;
    }

    // Supplier-specific methods for data segregation

    // Check if a product belongs to a specific supplier
    public function belongsToSupplier($product_id, $supplier_id) {
        $query = "SELECT id FROM " . $this->table_name . " 
                  WHERE id = :product_id AND supplier_id = :supplier_id 
                  LIMIT 0,1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":product_id", $product_id);
        $stmt->bindParam(":supplier_id", $supplier_id);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    // Update inventory item with supplier verification
    public function updateBySupplier($supplier_id) {
        // First verify the product belongs to the supplier
        if (!$this->belongsToSupplier($this->id, $supplier_id)) {
            return false;
        }

        $query = "UPDATE " . $this->table_name . " 
                  SET sku = :sku, 
                      name = :name, 
                      description = :description, 
                      quantity = :quantity, 
                      reorder_threshold = :reorder_threshold, 
                      category = :category, 
                      unit_price = :unit_price, 
                      location = :location 
                  WHERE id = :id AND supplier_id = :supplier_id";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $this->sku = htmlspecialchars(strip_tags($this->sku));
        $this->name = htmlspecialchars(strip_tags($this->name));
        $this->description = htmlspecialchars(strip_tags($this->description));
        $this->quantity = htmlspecialchars(strip_tags($this->quantity));
        $this->reorder_threshold = htmlspecialchars(strip_tags($this->reorder_threshold));
        $this->category = htmlspecialchars(strip_tags($this->category));
        $this->unit_price = htmlspecialchars(strip_tags($this->unit_price));
        $this->location = htmlspecialchars(strip_tags($this->location));
        $this->id = htmlspecialchars(strip_tags($this->id));

        // Bind values
        $stmt->bindParam(":sku", $this->sku);
        $stmt->bindParam(":name", $this->name);
        $stmt->bindParam(":description", $this->description);
        $stmt->bindParam(":quantity", $this->quantity);
        $stmt->bindParam(":reorder_threshold", $this->reorder_threshold);
        $stmt->bindParam(":category", $this->category);
        $stmt->bindParam(":unit_price", $this->unit_price);
        $stmt->bindParam(":location", $this->location);
        $stmt->bindParam(":id", $this->id);
        $stmt->bindParam(":supplier_id", $supplier_id);

        return $stmt->execute();
    }

    // Delete inventory item with supplier verification
    public function deleteBySupplier($supplier_id) {
        // First verify the product belongs to the supplier
        if (!$this->belongsToSupplier($this->id, $supplier_id)) {
            return false;
        }

        // Sanitize
        $this->id = htmlspecialchars(strip_tags($this->id));
        $invId = (int)$this->id;

        try {
            // Use a single transaction to satisfy FK constraints
            if (!$this->conn->inTransaction()) {
                $this->conn->beginTransaction();
            }

            // 1) Collect related order ids for this inventory
            $orderIds = [];
            try {
                $stmt = $this->conn->prepare("SELECT id FROM orders WHERE inventory_id = ?");
                $stmt->execute([$invId]);
                $orderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) { /* ignore if table missing */ }

            // 2) Delete deliveries and payments for those orders
            if (!empty($orderIds)) {
                $ph = implode(',', array_fill(0, count($orderIds), '?'));
                try {
                    $stmt = $this->conn->prepare("DELETE FROM deliveries WHERE order_id IN ($ph)");
                    $stmt->execute($orderIds);
                } catch (Exception $e) { /* ignore */ }
                try {
                    $stmt = $this->conn->prepare("DELETE FROM payments WHERE order_id IN ($ph)");
                    $stmt->execute($orderIds);
                } catch (Exception $e) { /* ignore */ }
                try {
                    $stmt = $this->conn->prepare("DELETE FROM orders WHERE id IN ($ph)");
                    $stmt->execute($orderIds);
                } catch (Exception $e) { /* ignore */ }
            }

            // 3) Delete sales transactions for this inventory
            try {
                $stmt = $this->conn->prepare("DELETE FROM sales_transactions WHERE inventory_id = ?");
                $stmt->execute([$invId]);
            } catch (Exception $e) { /* ignore */ }

            // 4) Clean up alerts and notifications referencing this inventory
            try {
                // Get alert IDs from alert_logs table
                $alertIds = [];
                try {
                    $stmt = $this->conn->prepare("SELECT id FROM alert_logs WHERE inventory_id = ?");
                    $stmt->execute([$invId]);
                    $alertIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                } catch (Exception $e1) {
                    $alertIds = [];
                }

                if (!empty($alertIds)) {
                    $ph = implode(',', array_fill(0, count($alertIds), '?'));
                    // notifications.alert_id may reference alert_logs
                    try {
                        $stmt = $this->conn->prepare("DELETE FROM notifications WHERE alert_id IN ($ph)");
                        $stmt->execute($alertIds);
                    } catch (Exception $e) { /* ignore */ }
                }
                // Delete alerts
                try {
                    $stmt = $this->conn->prepare("DELETE FROM alert_logs WHERE inventory_id = ?");
                    $stmt->execute([$invId]);
                } catch (Exception $e1) {
                    /* ignore */
                }
            } catch (Exception $e) { /* ignore */ }

            // 5) Delete variation rows (even though FK may cascade)
            try {
                $stmt = $this->conn->prepare("DELETE FROM inventory_variations WHERE inventory_id = ?");
                $stmt->execute([$invId]);
            } catch (Exception $e) { /* ignore */ }

            // 6) Finally delete the inventory row itself (scoped to supplier)
            $stmt = $this->conn->prepare(
                "DELETE FROM " . $this->table_name . " WHERE id = :id AND supplier_id = :supplier_id"
            );
            $stmt->bindParam(':id', $invId, PDO::PARAM_INT);
            $stmt->bindParam(':supplier_id', $supplier_id, PDO::PARAM_INT);
            $ok = $stmt->execute();

            $this->conn->commit();
            return $ok;
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            // Re-throw as PDOException if it originated there, else return false
            if ($e instanceof PDOException) { throw $e; }
            return false;
        }
    }

    // Create inventory item for specific supplier
    public function createForSupplier($supplier_id) {
        $query = "INSERT INTO " . $this->table_name . " 
                  SET sku = :sku, 
                      name = :name, 
                      description = :description, 
                      quantity = :quantity, 
                      supplier_quantity = 0, 
                      reorder_threshold = :reorder_threshold, 
                      supplier_id = :supplier_id, 
                      category = :category, 
                      unit_price = :unit_price, 
                      location = :location";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $this->sku = htmlspecialchars(strip_tags($this->sku));
        $this->name = htmlspecialchars(strip_tags($this->name));
        $this->description = htmlspecialchars(strip_tags($this->description));
        $this->quantity = htmlspecialchars(strip_tags($this->quantity));
        $this->reorder_threshold = htmlspecialchars(strip_tags($this->reorder_threshold));
        $this->category = htmlspecialchars(strip_tags($this->category));
        $this->unit_price = htmlspecialchars(strip_tags($this->unit_price));
        $this->location = htmlspecialchars(strip_tags($this->location));

        // Bind values
        $stmt->bindParam(":sku", $this->sku);
        $stmt->bindParam(":name", $this->name);
        $stmt->bindParam(":description", $this->description);
        $stmt->bindParam(":quantity", $this->quantity);
        $stmt->bindParam(":reorder_threshold", $this->reorder_threshold);
        $stmt->bindParam(":supplier_id", $supplier_id);
        $stmt->bindParam(":category", $this->category);
        $stmt->bindParam(":unit_price", $this->unit_price);
        $stmt->bindParam(":location", $this->location);

        // Execute query
        return $stmt->execute();
    }

    // Check if SKU exists for a specific supplier (to prevent duplicate SKUs within supplier)
    public function skuExistsForSupplier($sku, $supplier_id, $exclude_id = null) {
        $query = "SELECT id FROM " . $this->table_name . " 
                  WHERE sku = :sku AND supplier_id = :supplier_id";
        
        if ($exclude_id) {
            $query .= " AND id != :exclude_id";
        }
        
        $query .= " LIMIT 0,1";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $sku = htmlspecialchars(strip_tags($sku));

        // Bind values
        $stmt->bindParam(":sku", $sku);
        $stmt->bindParam(":supplier_id", $supplier_id);
        
        if ($exclude_id) {
            $stmt->bindParam(":exclude_id", $exclude_id);
        }

        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    // Get supplier-specific statistics
    public function getSupplierStats($supplier_id) {
        $query = "SELECT 
                    COUNT(*) as total_products,
                    SUM(supplier_quantity) as total_stock,
                    SUM(CASE WHEN supplier_quantity <= reorder_threshold THEN 1 ELSE 0 END) as low_stock_count,
                    SUM(CASE WHEN supplier_quantity > 0 THEN 1 ELSE 0 END) as active_products,
                    SUM(supplier_quantity * unit_price) as total_value
                  FROM " . $this->table_name . " 
                  WHERE supplier_id = :supplier_id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":supplier_id", $supplier_id);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Get inventory report for reports.php
    public function getInventoryReport($start_date, $end_date) {
        $query = "SELECT 
                    i.id as inventory_id,
                    i.sku,
                    i.name,
                    i.category,
                    i.quantity,
                    i.unit_price,
                    (i.quantity * i.unit_price) as total_value,
                    i.reorder_threshold,
                    s.name as supplier_name,
                    i.location,
                    i.last_updated
                  FROM " . $this->table_name . " i
                  LEFT JOIN suppliers s ON i.supplier_id = s.id
                  WHERE DATE(i.last_updated) BETWEEN :start_date AND :end_date
                  ORDER BY i.name";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":start_date", $start_date);
        $stmt->bindParam(":end_date", $end_date);
        $stmt->execute();

        return $stmt;
    }

    // Get low stock report for reports.php
    public function getLowStockReport($start_date, $end_date) {
        $query = "SELECT 
                    i.id as inventory_id,
                    i.sku,
                    i.name,
                    i.category,
                    i.quantity,
                    i.reorder_threshold,
                    i.unit_price,
                    (i.quantity * i.unit_price) as total_value,
                    s.name as supplier_name,
                    i.location,
                    CASE 
                        WHEN i.quantity = 0 THEN 'Out of Stock'
                        WHEN i.quantity <= i.reorder_threshold THEN 'Low Stock'
                        ELSE 'Normal'
                    END as stock_status
                  FROM " . $this->table_name . " i
                  LEFT JOIN suppliers s ON i.supplier_id = s.id
                  WHERE i.quantity <= i.reorder_threshold
                  AND DATE(i.last_updated) BETWEEN :start_date AND :end_date
                  ORDER BY i.quantity ASC, i.name";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":start_date", $start_date);
        $stmt->bindParam(":end_date", $end_date);
        $stmt->execute();

        return $stmt;
    }

    // Get admin inventory quantity by SKU (for supplier comparison)
    public function getAdminQuantityBySku($sku) {
        $query = "SELECT quantity FROM " . $this->table_name . " 
                  WHERE sku = :sku AND supplier_id IS NULL 
                  LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $sku = htmlspecialchars(strip_tags($sku));
        $stmt->bindParam(":sku", $sku);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['quantity'] : null;
    }

    // Get admin inventory quantity by name (for supplier comparison)
    public function getAdminQuantityByName($name) {
        $query = "SELECT quantity FROM " . $this->table_name . " 
                  WHERE name = :name AND supplier_id IS NULL 
                  LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $name = htmlspecialchars(strip_tags($name));
        $stmt->bindParam(":name", $name);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['quantity'] : null;
    }

    // Get low admin stock items that suppliers should restock
    public function getLowAdminStockForSupplier($supplier_id) {
        $query = "SELECT 
                    admin.id as admin_id,
                    admin.sku,
                    admin.name,
                    admin.quantity as admin_quantity,
                    admin.reorder_threshold,
                    admin.category,
                    supplier.id as supplier_id,
                    supplier.quantity as supplier_quantity,
                    supplier.unit_price as supplier_price
                  FROM " . $this->table_name . " admin
                  LEFT JOIN " . $this->table_name . " supplier 
                    ON (admin.sku = supplier.sku OR admin.name = supplier.name) 
                    AND supplier.supplier_id = :supplier_id
                  WHERE admin.supplier_id IS NULL 
                    AND admin.quantity <= admin.reorder_threshold
                    AND admin.quantity > 0
                  ORDER BY admin.quantity ASC, admin.name";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":supplier_id", $supplier_id);
        $stmt->execute();

        return $stmt;
    }

    // Get critical admin stock items (out of stock) that suppliers should restock
    public function getCriticalAdminStockForSupplier($supplier_id) {
        $query = "SELECT 
                    admin.id as admin_id,
                    admin.sku,
                    admin.name,
                    admin.quantity as admin_quantity,
                    admin.reorder_threshold,
                    admin.category,
                    supplier.id as supplier_id,
                    supplier.quantity as supplier_quantity,
                    supplier.unit_price as supplier_price
                  FROM " . $this->table_name . " admin
                  LEFT JOIN " . $this->table_name . " supplier 
                    ON (admin.sku = supplier.sku OR admin.name = supplier.name) 
                    AND supplier.supplier_id = :supplier_id
                  WHERE admin.supplier_id IS NULL 
                    AND admin.quantity = 0
                  ORDER BY admin.name";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":supplier_id", $supplier_id);
        $stmt->execute();

        return $stmt;
    }
}
?>
