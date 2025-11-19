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

    /**
     * Sync completed order to inventory table
     * This method is called when an order is marked as completed
     * Updates the inventory table with order information and increases quantity
     */
    public function syncCompletedOrderToInventory($orderId, $orderTable = 'admin_orders') {
        try {
            // Get order details - get item_name from order if available
            $orderQuery = "SELECT o.*, 
                                 i.sku, i.name, i.description, i.category, i.location, i.reorder_threshold,
                                 i.image_url, i.image_path, i.quantity as current_quantity,
                                 s.name as supplier_name
                          FROM {$orderTable} o
                          LEFT JOIN " . $this->table_name . " i ON o.inventory_id = i.id
                          LEFT JOIN suppliers s ON o.supplier_id = s.id
                          WHERE o.id = ? AND o.confirmation_status = 'completed'";
            
            $stmt = $this->conn->prepare($orderQuery);
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) {
                error_log("Sync failed: Order #{$orderId} not found or not completed in {$orderTable}");
                return false;
            }
            
            // If order doesn't have inventory_id, we need to create a new inventory item
            // and update the order with the new inventory_id
            $inventoryId = null;
            if (!empty($order['inventory_id']) && (int)$order['inventory_id'] > 0) {
                $inventoryId = (int)$order['inventory_id'];
            }
            
            $orderQuantity = (int)($order['quantity'] ?? 0);
            if ($orderQuantity <= 0) {
                error_log("Sync skipped: Order #{$orderId} has invalid quantity: {$orderQuantity}");
                return false;
            }
            
            // Check if inventory item exists (if inventory_id was provided)
            $inventoryExists = false;
            if ($inventoryId) {
                $checkStmt = $this->conn->prepare("SELECT id, name, sku FROM " . $this->table_name . " WHERE id = ?");
                $checkStmt->execute([$inventoryId]);
                $inventoryExists = $checkStmt->fetch(PDO::FETCH_ASSOC);
            }
            
            if (!$inventoryExists) {
                // Inventory item doesn't exist - CREATE it from order data
                // This ensures completed orders create inventory items if they don't exist
                
                // Get item name - try multiple sources
                $name = null;
                if (!empty($order['name'])) {
                    $name = $order['name'];
                } elseif (!empty($order['item_name'])) {
                    $name = $order['item_name'];
                } else {
                    // Try to get from inventory table if inventory_id exists
                    if ($inventoryId) {
                        $invCheck = $this->conn->prepare("SELECT name FROM " . $this->table_name . " WHERE id = ?");
                        $invCheck->execute([$inventoryId]);
                        $invRow = $invCheck->fetch(PDO::FETCH_ASSOC);
                        if ($invRow && !empty($invRow['name'])) {
                            $name = $invRow['name'];
                        }
                    }
                    
                    // If still no name and we have supplier_id, try to get from supplier catalog
                    // Note: supplier_catalog uses source_inventory_id, not inventory_id
                    if (empty($name) && !empty($order['supplier_id'])) {
                        try {
                            if ($inventoryId) {
                                // Try by source_inventory_id
                                $supplierCheck = $this->conn->prepare("SELECT name FROM supplier_catalog 
                                                                      WHERE supplier_id = ? AND source_inventory_id = ? 
                                                                      LIMIT 1");
                                $supplierCheck->execute([(int)$order['supplier_id'], $inventoryId]);
                            } else {
                                // If no inventory_id, try to find any product from this supplier (fallback)
                                $supplierCheck = $this->conn->prepare("SELECT name FROM supplier_catalog 
                                                                      WHERE supplier_id = ? 
                                                                      ORDER BY id DESC LIMIT 1");
                                $supplierCheck->execute([(int)$order['supplier_id']]);
                            }
                            $supplierRow = $supplierCheck->fetch(PDO::FETCH_ASSOC);
                            if ($supplierRow && !empty($supplierRow['name'])) {
                                $name = $supplierRow['name'];
                            }
                        } catch (Exception $e) {
                            // Supplier catalog might not exist, ignore
                            error_log("Warning: Could not get name from supplier_catalog: " . $e->getMessage());
                        }
                    }
                }
                
                if (empty($name)) {
                    $name = 'Item from Order #' . $orderId;
                }
                
                // Get SKU - try multiple sources
                $sku = null;
                if (!empty($order['sku'])) {
                    $sku = $order['sku'];
                } elseif ($inventoryId) {
                    $invCheck = $this->conn->prepare("SELECT sku FROM " . $this->table_name . " WHERE id = ?");
                    $invCheck->execute([$inventoryId]);
                    $invRow = $invCheck->fetch(PDO::FETCH_ASSOC);
                    if ($invRow && !empty($invRow['sku'])) {
                        $sku = $invRow['sku'];
                    }
                }
                
                if (empty($sku)) {
                    $sku = 'SKU-' . ($inventoryId ? $inventoryId : $orderId);
                }
                
                // If inventory_id was provided but item doesn't exist, use that ID
                // Otherwise, let AUTO_INCREMENT handle it
                if ($inventoryId) {
                    $createQuery = "INSERT INTO " . $this->table_name . " 
                                    (id, sku, name, description, category, reorder_threshold, unit_type, quantity, 
                                     unit_price, supplier_id, location, last_updated) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    
                    $createStmt = $this->conn->prepare($createQuery);
                    $createResult = $createStmt->execute([
                        $inventoryId,
                        $sku,
                        $name,
                        !empty($order['description']) ? $order['description'] : null,
                        !empty($order['category']) ? $order['category'] : null,
                        isset($order['reorder_threshold']) && $order['reorder_threshold'] > 0 ? (int)$order['reorder_threshold'] : 0,
                        !empty($order['unit_type']) ? $order['unit_type'] : 'per piece',
                        $orderQuantity, // Initial quantity from order
                        isset($order['unit_price']) && $order['unit_price'] > 0 ? (float)$order['unit_price'] : 0.00,
                        isset($order['supplier_id']) && $order['supplier_id'] > 0 ? (int)$order['supplier_id'] : null,
                        !empty($order['location']) ? $order['location'] : null
                    ]);
                    
                    if (!$createResult) {
                        $errorInfo = $createStmt->errorInfo();
                        error_log("Error: Failed to create inventory item #{$inventoryId} for order #{$orderId}. Error: " . ($errorInfo[2] ?? 'Unknown'));
                        return false;
                    }
                    
                    // Update the order with the new inventory_id if it was NULL
                    if (empty($order['inventory_id'])) {
                        try {
                            $updateOrderStmt = $this->conn->prepare("UPDATE {$orderTable} SET inventory_id = ? WHERE id = ?");
                            $updateOrderStmt->execute([$inventoryId, $orderId]);
                        } catch (Exception $e) {
                            error_log("Warning: Created inventory item but failed to update order #{$orderId} with inventory_id: " . $e->getMessage());
                        }
                    }
                } else {
                    // No inventory_id in order - create new item and update order
                    $createQuery = "INSERT INTO " . $this->table_name . " 
                                    (sku, name, description, category, reorder_threshold, unit_type, quantity, 
                                     unit_price, supplier_id, location, last_updated) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    
                    $createStmt = $this->conn->prepare($createQuery);
                    $createResult = $createStmt->execute([
                        $sku,
                        $name,
                        !empty($order['description']) ? $order['description'] : null,
                        !empty($order['category']) ? $order['category'] : null,
                        isset($order['reorder_threshold']) && $order['reorder_threshold'] > 0 ? (int)$order['reorder_threshold'] : 0,
                        !empty($order['unit_type']) ? $order['unit_type'] : 'per piece',
                        $orderQuantity, // Initial quantity from order
                        isset($order['unit_price']) && $order['unit_price'] > 0 ? (float)$order['unit_price'] : 0.00,
                        isset($order['supplier_id']) && $order['supplier_id'] > 0 ? (int)$order['supplier_id'] : null,
                        !empty($order['location']) ? $order['location'] : null
                    ]);
                    
                    if (!$createResult) {
                        $errorInfo = $createStmt->errorInfo();
                        error_log("Error: Failed to create inventory item for order #{$orderId}. Error: " . ($errorInfo[2] ?? 'Unknown'));
                        return false;
                    }
                    
                    // Get the newly created inventory ID
                    $newInventoryId = (int)$this->conn->lastInsertId();
                    
                    // Update the order with the new inventory_id
                    try {
                        $updateOrderStmt = $this->conn->prepare("UPDATE {$orderTable} SET inventory_id = ? WHERE id = ?");
                        $updateOrderStmt->execute([$newInventoryId, $orderId]);
                        $inventoryId = $newInventoryId;
                    } catch (Exception $e) {
                        error_log("Warning: Created inventory item #{$newInventoryId} but failed to update order #{$orderId} with inventory_id: " . $e->getMessage());
                        $inventoryId = $newInventoryId; // Use it anyway
                    }
                }
            } else {
                // Update existing inventory item with order information and increase quantity
                // This ensures we update the SAME inventory item, not create duplicates
                $updateQuery = "UPDATE " . $this->table_name . " SET
                                quantity = quantity + ?,
                                unit_price = COALESCE(?, unit_price),
                                unit_type = COALESCE(?, unit_type),
                                supplier_id = COALESCE(?, supplier_id),
                                name = COALESCE(?, name),
                                description = COALESCE(?, description),
                                category = COALESCE(?, category),
                                location = COALESCE(?, location),
                                reorder_threshold = COALESCE(?, reorder_threshold),
                                last_updated = NOW()
                                WHERE id = ?";
                
                $updateStmt = $this->conn->prepare($updateQuery);
                $updateResult = $updateStmt->execute([
                    $orderQuantity, // Add order quantity to existing quantity
                    isset($order['unit_price']) && $order['unit_price'] > 0 ? (float)$order['unit_price'] : null,
                    !empty($order['unit_type']) ? $order['unit_type'] : null,
                    isset($order['supplier_id']) && $order['supplier_id'] > 0 ? (int)$order['supplier_id'] : null,
                    !empty($order['name']) ? $order['name'] : null,
                    !empty($order['description']) ? $order['description'] : null,
                    !empty($order['category']) ? $order['category'] : null,
                    !empty($order['location']) ? $order['location'] : null,
                    isset($order['reorder_threshold']) && $order['reorder_threshold'] > 0 ? (int)$order['reorder_threshold'] : null,
                    $inventoryId
                ]);
                
                if (!$updateResult || $updateStmt->rowCount() === 0) {
                    error_log("Warning: Failed to update inventory item #{$inventoryId} for order #{$orderId}. Row may not exist or update had no effect.");
                    return false;
                }
            }
            
            // Ensure we have a valid inventoryId at this point
            if (!$inventoryId) {
                error_log("Error: No inventory_id available after sync for order #{$orderId}");
                return false;
            }
            
            // CRITICAL: Always update the order with inventory_id if it was NULL or changed
            // This ensures the order is linked to the inventory item for future queries
            try {
                $currentOrderStmt = $this->conn->prepare("SELECT inventory_id FROM {$orderTable} WHERE id = ?");
                $currentOrderStmt->execute([$orderId]);
                $currentOrder = $currentOrderStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$currentOrder || empty($currentOrder['inventory_id']) || (int)$currentOrder['inventory_id'] !== $inventoryId) {
                    // Update order with inventory_id
                    $updateOrderStmt = $this->conn->prepare("UPDATE {$orderTable} SET inventory_id = ? WHERE id = ?");
                    $updateOrderStmt->execute([$inventoryId, $orderId]);
                    error_log("Updated order #{$orderId} with inventory_id: {$inventoryId}");
                }
            } catch (Exception $e) {
                error_log("Warning: Failed to update order #{$orderId} with inventory_id: " . $e->getMessage());
                // Don't fail the sync - inventory item was created/updated successfully
            }
            
            // Also update inventory_variations table if order has a variation
            // This ensures variation data is stored in the database for display in inventory.php
            if (!empty($order['variation'])) {
                try {
                    $variation = trim($order['variation']);
                    $unitType = !empty($order['unit_type']) ? $order['unit_type'] : 'per piece';
                    $unitPrice = isset($order['unit_price']) && $order['unit_price'] > 0 ? (float)$order['unit_price'] : null;
                    
                    // Check if variation already exists
                    $varCheckStmt = $this->conn->prepare("SELECT id, quantity FROM inventory_variations 
                                                          WHERE inventory_id = ? AND variation = ? LIMIT 1");
                    $varCheckStmt->execute([$inventoryId, $variation]);
                    $existingVar = $varCheckStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existingVar) {
                        // Update existing variation - add order quantity to existing quantity
                        $newQuantity = (int)$existingVar['quantity'] + $orderQuantity;
                        $updateVarStmt = $this->conn->prepare("UPDATE inventory_variations 
                                                               SET quantity = ?,
                                                                   unit_type = COALESCE(?, unit_type),
                                                                   unit_price = COALESCE(?, unit_price)
                                                               WHERE id = ?");
                        $updateVarStmt->execute([
                            $newQuantity,
                            $unitType,
                            $unitPrice,
                            (int)$existingVar['id']
                        ]);
                    } else {
                        // Create new variation entry
                        $insertVarStmt = $this->conn->prepare("INSERT INTO inventory_variations 
                                                               (inventory_id, variation, unit_type, quantity, unit_price) 
                                                               VALUES (?, ?, ?, ?, ?)");
                        $insertVarStmt->execute([
                            $inventoryId,
                            $variation,
                            $unitType,
                            $orderQuantity,
                            $unitPrice
                        ]);
                    }
                } catch (Exception $e) {
                    // Log error but don't fail the sync - variation update is secondary
                    error_log("Warning: Failed to update inventory_variations for order #{$orderId}: " . $e->getMessage());
                }
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Error syncing completed order to inventory: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Ensure inventory_from_completed_orders table exists
     */
    private function ensureInventoryFromCompletedOrdersTable() {
        try {
            $check = $this->conn->query("SELECT 1 FROM information_schema.tables 
                                        WHERE table_schema = DATABASE() 
                                        AND table_name = 'inventory_from_completed_orders'");
            if ($check->rowCount() === 0) {
                // Table doesn't exist, create it
                $createSQL = "CREATE TABLE IF NOT EXISTS `inventory_from_completed_orders` (
                  `id` INT(11) NOT NULL AUTO_INCREMENT,
                  `order_id` INT(11) NOT NULL COMMENT 'ID from admin_orders or orders table',
                  `order_table` VARCHAR(20) DEFAULT 'admin_orders' COMMENT 'Which table the order came from: admin_orders or orders',
                  `inventory_id` INT(11) DEFAULT NULL COMMENT 'Original inventory_id from order (for reference)',
                  `supplier_id` INT(11) DEFAULT NULL COMMENT 'Supplier ID from admin_orders',
                  `user_id` INT(11) DEFAULT NULL COMMENT 'User ID who created the order (from admin_orders)',
                  `is_automated` TINYINT(1) DEFAULT 0 COMMENT 'Whether order was automated (from admin_orders)',
                  `sku` VARCHAR(50) DEFAULT NULL,
                  `name` VARCHAR(100) NOT NULL,
                  `description` TEXT DEFAULT NULL,
                  `variation` VARCHAR(255) DEFAULT NULL COMMENT 'Product variation from admin_orders',
                  `unit_type` VARCHAR(50) DEFAULT 'per piece' COMMENT 'Unit type from admin_orders',
                  `quantity` INT(11) NOT NULL DEFAULT 0 COMMENT 'Quantity from completed order',
                  `available_quantity` INT(11) NOT NULL DEFAULT 0 COMMENT 'Available stock after sales',
                  `sold_quantity` INT(11) NOT NULL DEFAULT 0 COMMENT 'Total sold quantity',
                  `reorder_threshold` INT(11) NOT NULL DEFAULT 0,
                  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Unit price from admin_orders',
                  `supplier_name` VARCHAR(100) DEFAULT NULL,
                  `category` VARCHAR(50) DEFAULT NULL,
                  `location` VARCHAR(100) DEFAULT NULL,
                  `image_url` VARCHAR(255) DEFAULT NULL,
                  `image_path` VARCHAR(255) DEFAULT NULL,
                  `order_date` DATETIME DEFAULT NULL COMMENT 'Date when order was placed (from admin_orders.order_date)',
                  `confirmation_status` ENUM('pending','confirmed','cancelled','delivered','completed') DEFAULT 'completed' COMMENT 'Order status from admin_orders',
                  `confirmation_date` DATETIME DEFAULT NULL COMMENT 'Date when order was confirmed (from admin_orders.confirmation_date)',
                  `completion_date` DATETIME DEFAULT NULL COMMENT 'Date when order was marked as completed',
                  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Soft delete flag: 0 = active, 1 = deleted',
                  `last_updated` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `unique_order_inventory_variation` (`order_id`, `order_table`, `inventory_id`, `variation`(100)),
                  KEY `idx_inventory_id` (`inventory_id`),
                  KEY `idx_order_id` (`order_id`),
                  KEY `idx_order_table` (`order_table`),
                  KEY `idx_supplier_id` (`supplier_id`),
                  KEY `idx_user_id` (`user_id`),
                  KEY `idx_sku` (`sku`),
                  KEY `idx_category` (`category`),
                  KEY `idx_completion_date` (`completion_date`),
                  KEY `idx_confirmation_status` (`confirmation_status`),
                  KEY `idx_is_deleted` (`is_deleted`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
                
                $this->conn->exec($createSQL);
            }
        } catch (Exception $e) {
            error_log("Error ensuring inventory_from_completed_orders table: " . $e->getMessage());
        }
    }

    /**
     * Fetch all inventory items from completed orders only
     * This method reads from the inventory table, showing only items from completed orders
     * Products are synced to inventory table when orders are completed
     */
    public function readAllFromCompletedOrders() {
        try {
            // Sync all completed orders to inventory table first
            $this->syncAllCompletedOrdersToInventory();
            
            // Check if soft-delete column exists
            $hasDeleted = false;
            try {
                $chk = $this->conn->query("SELECT 1 FROM information_schema.columns 
                                         WHERE table_schema = DATABASE() 
                                         AND table_name = '" . $this->table_name . "' 
                                         AND column_name = 'is_deleted'");
                $hasDeleted = (bool)$chk->fetchColumn();
            } catch (Exception $e) { $hasDeleted = false; }
            
            $deletedCondition = $hasDeleted ? " AND COALESCE(i.is_deleted, 0) = 0" : "";
            
            // CRITICAL: Only show inventory items from admin_orders table (admin/orders.php)
            // Do NOT include items from orders table or any other source
            // Only inventory items that have at least one COMPLETED order in admin_orders table
            
            // Check if admin_orders table exists
            $hasAdminOrders = false;
            try {
                $chk = $this->conn->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'admin_orders'");
                $hasAdminOrders = (bool)$chk->fetchColumn();
            } catch (Exception $e) { $hasAdminOrders = false; }

            // If admin_orders table doesn't exist, return empty result
            if (!$hasAdminOrders) {
                error_log("WARNING: admin_orders table does not exist. Cannot display inventory from completed orders.");
                $query = "SELECT i.*, NULL as supplier_name, 'From Completed Order' as source_type
                          FROM " . $this->table_name . " i
                          WHERE 1 = 0";
                $stmt = $this->conn->prepare($query);
                $stmt->execute();
                return $stmt;
            }

            // Get ONLY inventory IDs from completed orders in admin_orders table
            // This ensures we ONLY show items from admin/orders.php that are marked as completed
            $inventoryIdsWithOrders = [];
            $stmt = $this->conn->query("SELECT DISTINCT inventory_id 
                                       FROM admin_orders 
                                       WHERE confirmation_status = 'completed' 
                                       AND inventory_id IS NOT NULL 
                                       AND inventory_id > 0");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $inventoryIdsWithOrders[] = (int)$row['inventory_id'];
            }
            
            // Remove duplicates
            $inventoryIdsWithOrders = array_unique($inventoryIdsWithOrders);
            
            // If no inventory IDs found, return empty result
            if (empty($inventoryIdsWithOrders)) {
                
                // Return empty result - do NOT show any inventory items
                $query = "SELECT i.*, NULL as supplier_name, 'From Completed Order' as source_type
                          FROM " . $this->table_name . " i
                          WHERE 1 = 0";
                $stmt = $this->conn->prepare($query);
                $stmt->execute();
                return $stmt;
            }
            
            // Build IN clause for inventory IDs
            $placeholders = implode(',', array_fill(0, count($inventoryIdsWithOrders), '?'));
            
            // Query ONLY inventory items that have completed orders in admin_orders table
            // Use INNER JOIN to ensure we ONLY get items that have completed orders
            $query = "SELECT 
                        i.id,
                        i.sku,
                        i.name,
                        i.description,
                        i.category,
                        i.reorder_threshold,
                        i.unit_type,
                        i.quantity,
                        i.unit_price,
                        i.supplier_id,
                        i.location,
                        i.image_url,
                        i.image_path,
                        i.last_updated,
                        COALESCE(MAX(s.name), '') as supplier_name,
                        'From Completed Order' as source_type
                      FROM " . $this->table_name . " i
                      INNER JOIN admin_orders ao ON ao.inventory_id = i.id AND ao.confirmation_status = 'completed'
                      LEFT JOIN suppliers s ON i.supplier_id = s.id
                      WHERE i.id IN ({$placeholders})" . $deletedCondition . "
                      GROUP BY i.id
                      ORDER BY i.name ASC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($inventoryIdsWithOrders);
            
            // Return fresh statement for iteration
            $stmt = $this->conn->prepare($query);
            $stmt->execute($inventoryIdsWithOrders);
            return $stmt;
        } catch (Exception $e) {
            error_log("Error reading from inventory: " . $e->getMessage());
            // Fallback to empty result
            $query = "SELECT NULL as id, NULL as inventory_id, NULL as sku, NULL as name, NULL as description,
                            NULL as category, NULL as reorder_threshold, NULL as unit_type, NULL as quantity,
                            NULL as available_quantity, NULL as unit_price, NULL as supplier_id, NULL as supplier_name,
                            NULL as location, NULL as source_type
                      WHERE 1 = 0";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt;
        }
    }
    
    /**
     * Sync all completed orders to inventory table
     * This ensures all completed orders update the inventory table
     * This method is public so it can be called from inventory.php to ensure data is synced
     */
    public function syncAllCompletedOrdersToInventory() {
        try {
            $syncedCount = 0;
            $errorCount = 0;
            
            // CRITICAL: Only sync from admin_orders table (admin/orders.php)
            // Do NOT sync from orders table or any other source
            // Only sync orders that are marked as 'completed' in admin_orders table
            
            $hasAdminOrders = false;
            try {
                $chk = $this->conn->query("SELECT 1 FROM information_schema.tables 
                                          WHERE table_schema = DATABASE() 
                                          AND table_name = 'admin_orders'");
                $hasAdminOrders = (bool)$chk->fetchColumn();
            } catch (Exception $e) { $hasAdminOrders = false; }
            
            if ($hasAdminOrders) {
                // Get ONLY completed orders from admin_orders table
                // Sync EVERY completed order, even if inventory_id is NULL
                // The syncCompletedOrderToInventory method will create inventory items if needed
                $ordersStmt = $this->conn->query("SELECT id FROM admin_orders 
                                                  WHERE confirmation_status = 'completed'
                                                  ORDER BY id ASC");
                $totalOrders = 0;
                while ($order = $ordersStmt->fetch(PDO::FETCH_ASSOC)) {
                    $totalOrders++;
                    $orderId = (int)$order['id'];
                    $result = $this->syncCompletedOrderToInventory($orderId, 'admin_orders');
                    if ($result) {
                        $syncedCount++;
                    } else {
                        $errorCount++;
                        error_log("Warning: Failed to sync admin_orders order #{$orderId} to inventory");
                    }
                }
            } else {
                error_log("WARNING: admin_orders table does not exist. Cannot sync completed orders to inventory.");
            }
            
            // DO NOT sync from orders table - we only want data from admin/orders.php (admin_orders table)
            
            if ($syncedCount > 0 || $errorCount > 0) {
                error_log("Inventory sync completed (admin_orders ONLY): {$syncedCount} orders synced, {$errorCount} errors");
            }
        } catch (Exception $e) {
            error_log("Error syncing all completed orders to inventory: " . $e->getMessage());
        }
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
    // CRITICAL: Never creates items with empty/null SKU - returns false if SKU is invalid
    public function createForSupplier($supplier_id) {
        // CRITICAL: Validate SKU is not empty before creating
        if (empty($this->sku) || trim($this->sku) === '') {
            error_log("Error: Attempted to create inventory item with empty SKU. Creation aborted.");
            return false;
        }
        
        // CRITICAL: Validate supplier_id is valid
        if (empty($supplier_id) || $supplier_id <= 0) {
            error_log("Error: Attempted to create inventory item with invalid supplier_id. Creation aborted.");
            return false;
        }
        
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
        $this->sku = trim(htmlspecialchars(strip_tags($this->sku)));
        $this->name = htmlspecialchars(strip_tags($this->name));
        $this->description = htmlspecialchars(strip_tags($this->description));
        $this->quantity = htmlspecialchars(strip_tags($this->quantity));
        $this->reorder_threshold = htmlspecialchars(strip_tags($this->reorder_threshold));
        $this->category = htmlspecialchars(strip_tags($this->category));
        $this->unit_price = htmlspecialchars(strip_tags($this->unit_price));
        $this->location = htmlspecialchars(strip_tags($this->location));

        // CRITICAL: Double-check SKU is still valid after sanitization
        if (empty($this->sku)) {
            error_log("Error: SKU became empty after sanitization. Creation aborted.");
            return false;
        }

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
