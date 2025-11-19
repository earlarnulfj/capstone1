<?php
/**
 * Inventory Synchronization Service
 * Handles real-time inventory updates with comprehensive logging
 * Supports both base inventory and variation-level stock tracking
 */
class InventorySync {
    private $conn;
    private $table_name = "inventory";
    private $variation_table = "inventory_variations";
    private $log_table = "inventory_logs";
    
    public function __construct($db) {
        $this->conn = $db;
        $this->ensureLogTable();
    }
    
    /**
     * Ensure inventory_logs table exists for historical tracking
     */
    private function ensureLogTable() {
        try {
            $check = $this->conn->prepare("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t");
            $check->bindParam(':t', $this->log_table);
            $check->execute();
            $row = $check->fetch(PDO::FETCH_ASSOC);
            $exists = isset($row['cnt']) ? ((int)$row['cnt'] > 0) : false;
            
            if (!$exists) {
                $this->conn->exec("
                    CREATE TABLE IF NOT EXISTS `{$this->log_table}` (
                        `id` INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        `inventory_id` INT(11) NOT NULL,
                        `variation` VARCHAR(255) NULL DEFAULT NULL,
                        `unit_type` VARCHAR(50) NULL DEFAULT NULL,
                        `action` ENUM('stock_in', 'stock_out', 'adjustment', 'order_placed', 'delivery_received', 'sale_completed') NOT NULL,
                        `quantity_before` INT(11) NOT NULL DEFAULT 0,
                        `quantity_change` INT(11) NOT NULL DEFAULT 0,
                        `quantity_after` INT(11) NOT NULL DEFAULT 0,
                        `order_id` INT(11) NULL DEFAULT NULL,
                        `delivery_id` INT(11) NULL DEFAULT NULL,
                        `sales_transaction_id` INT(11) NULL DEFAULT NULL,
                        `user_id` INT(11) NULL DEFAULT NULL,
                        `notes` TEXT NULL,
                        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX `idx_inventory` (`inventory_id`),
                        INDEX `idx_action` (`action`),
                        INDEX `idx_created` (`created_at`),
                        INDEX `idx_order` (`order_id`),
                        INDEX `idx_variation` (`variation`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
        } catch (Exception $e) {
            // Best-effort; log table creation is optional
        }
    }
    
    /**
     * Log inventory change for historical tracking
     */
    private function logChange($inventory_id, $variation, $unit_type, $action, $quantity_before, $quantity_change, $quantity_after, $order_id = null, $delivery_id = null, $sales_transaction_id = null, $user_id = null, $notes = null) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO {$this->log_table} 
                (inventory_id, variation, unit_type, action, quantity_before, quantity_change, quantity_after, order_id, delivery_id, sales_transaction_id, user_id, notes, created_at)
                VALUES (:inventory_id, :variation, :unit_type, :action, :quantity_before, :quantity_change, :quantity_after, :order_id, :delivery_id, :sales_transaction_id, :user_id, :notes, NOW())
            ");
            
            $stmt->bindValue(':inventory_id', $inventory_id, PDO::PARAM_INT);
            $stmt->bindValue(':variation', $variation ?: null, $variation ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':unit_type', $unit_type ?: null, $unit_type ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':action', $action, PDO::PARAM_STR);
            $stmt->bindValue(':quantity_before', $quantity_before, PDO::PARAM_INT);
            $stmt->bindValue(':quantity_change', $quantity_change, PDO::PARAM_INT);
            $stmt->bindValue(':quantity_after', $quantity_after, PDO::PARAM_INT);
            $stmt->bindValue(':order_id', $order_id ?: null, $order_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':delivery_id', $delivery_id ?: null, $delivery_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':sales_transaction_id', $sales_transaction_id ?: null, $sales_transaction_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':user_id', $user_id ?: null, $user_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':notes', $notes ?: null, $notes ? PDO::PARAM_STR : PDO::PARAM_NULL);
            
            return $stmt->execute();
        } catch (Exception $e) {
            // Logging failure should not block main operation
            error_log("Inventory sync log error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Handle stock decrement for order placement (reserves stock)
     * Returns true if successful, false if insufficient stock
     */
    public function reserveStockForOrder($inventory_id, $variation, $unit_type, $quantity, $order_id, $user_id = null) {
        try {
            $this->conn->beginTransaction();
            
            if (!empty($variation)) {
                // Variation-specific stock
                require_once __DIR__ . '/inventory_variation.php';
                $invVariation = new InventoryVariation($this->conn);
                
                $current_stock = $invVariation->getStock($inventory_id, $variation, $unit_type ?: 'per piece');
                
                if ($current_stock < $quantity) {
                    $this->conn->rollBack();
                    return false; // Insufficient stock
                }
                
                if (!$invVariation->decrementStock($inventory_id, $variation, $unit_type ?: 'per piece', $quantity)) {
                    $this->conn->rollBack();
                    return false;
                }
                
                $new_stock = $current_stock - $quantity;
                $this->logChange($inventory_id, $variation, $unit_type, 'order_placed', $current_stock, -$quantity, $new_stock, $order_id, null, null, $user_id, "Order #{$order_id} reserved {$quantity} units");
                
            } else {
                // Base inventory stock
                $stmt = $this->conn->prepare("SELECT quantity FROM {$this->table_name} WHERE id = :id FOR UPDATE");
                $stmt->execute([':id' => $inventory_id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$row || (int)$row['quantity'] < $quantity) {
                    $this->conn->rollBack();
                    return false; // Insufficient stock
                }
                
                $current_stock = (int)$row['quantity'];
                $stmt = $this->conn->prepare("UPDATE {$this->table_name} SET quantity = quantity - :qty WHERE id = :id AND quantity >= :qty");
                $stmt->execute([':qty' => $quantity, ':id' => $inventory_id]);
                
                if ($stmt->rowCount() === 0) {
                    $this->conn->rollBack();
                    return false;
                }
                
                $new_stock = $current_stock - $quantity;
                $this->logChange($inventory_id, null, null, 'order_placed', $current_stock, -$quantity, $new_stock, $order_id, null, null, $user_id, "Order #{$order_id} reserved {$quantity} units");
            }
            
            $this->conn->commit();
            return true;
            
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Inventory sync reserve error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Handle stock increment for delivery receipt (replenishes stock)
     */
    public function receiveDelivery($inventory_id, $variation, $unit_type, $quantity, $delivery_id, $user_id = null, $unit_price = null) {
        try {
            $this->conn->beginTransaction();
            
            if (!empty($variation)) {
                // Variation-specific stock
                require_once __DIR__ . '/inventory_variation.php';
                $invVariation = new InventoryVariation($this->conn);
                
                $current_stock = $invVariation->getStock($inventory_id, $variation, $unit_type ?: 'per piece');
                $wasNewVariation = ($current_stock === 0);
                
                // incrementStock will create the variation if it doesn't exist
                // But we need to ensure price is set correctly
                if ($wasNewVariation && $unit_price !== null && is_numeric($unit_price) && (float)$unit_price > 0) {
                    // For new variations, create with price directly
                    if ($invVariation->createVariant($inventory_id, $variation, $unit_type ?: 'per piece', $quantity, (float)$unit_price)) {
                        $new_stock = $quantity; // New variation starts with delivered quantity
                    } else {
                        // If create failed (maybe exists now), try increment
                        if (!$invVariation->incrementStock($inventory_id, $variation, $unit_type ?: 'per piece', $quantity)) {
                            $this->conn->rollBack();
                            return false;
                        }
                        $new_stock = $current_stock + $quantity;
                        // Ensure price is set
                        $invVariation->updatePrice($inventory_id, $variation, $unit_type ?: 'per piece', (float)$unit_price);
                    }
                } else {
                    // Existing variation or no price provided - use standard increment
                    if (!$invVariation->incrementStock($inventory_id, $variation, $unit_type ?: 'per piece', $quantity)) {
                        $this->conn->rollBack();
                        return false;
                    }
                    $new_stock = $current_stock + $quantity;
                    
                    // Update variation price if provided and price is valid
                    if ($unit_price !== null && is_numeric($unit_price) && (float)$unit_price > 0) {
                        $invVariation->updatePrice($inventory_id, $variation, $unit_type ?: 'per piece', (float)$unit_price);
                    }
                }
                
                $this->logChange($inventory_id, $variation, $unit_type, 'delivery_received', $current_stock, $quantity, $new_stock, null, $delivery_id, null, $user_id, "Delivery #{$delivery_id} added {$quantity} units");
                
            } else {
                // Base inventory stock
                $stmt = $this->conn->prepare("SELECT quantity FROM {$this->table_name} WHERE id = :id FOR UPDATE");
                $stmt->execute([':id' => $inventory_id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$row) {
                    $this->conn->rollBack();
                    return false;
                }
                
                $current_stock = (int)$row['quantity'];
                $stmt = $this->conn->prepare("UPDATE {$this->table_name} SET quantity = quantity + :qty WHERE id = :id");
                $stmt->execute([':qty' => $quantity, ':id' => $inventory_id]);
                
                $new_stock = $current_stock + $quantity;
                $this->logChange($inventory_id, null, null, 'delivery_received', $current_stock, $quantity, $new_stock, null, $delivery_id, null, $user_id, "Delivery #{$delivery_id} added {$quantity} units");
            }
            
            $this->conn->commit();
            return true;
            
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Inventory sync delivery error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Handle stock decrement for sales (POS transactions)
     */
    public function recordSale($inventory_id, $variation, $unit_type, $quantity, $sales_transaction_id, $user_id = null) {
        try {
            $this->conn->beginTransaction();
            
            if (!empty($variation)) {
                // Variation-specific stock
                require_once __DIR__ . '/inventory_variation.php';
                $invVariation = new InventoryVariation($this->conn);
                
                $current_stock = $invVariation->getStock($inventory_id, $variation, $unit_type ?: 'per piece');
                
                if ($current_stock < $quantity) {
                    $this->conn->rollBack();
                    return false; // Insufficient stock
                }
                
                if (!$invVariation->decrementStock($inventory_id, $variation, $unit_type ?: 'per piece', $quantity)) {
                    $this->conn->rollBack();
                    return false;
                }
                
                $new_stock = $current_stock - $quantity;
                $this->logChange($inventory_id, $variation, $unit_type, 'sale_completed', $current_stock, -$quantity, $new_stock, null, null, $sales_transaction_id, $user_id, "Sale #{$sales_transaction_id} sold {$quantity} units");
                
            } else {
                // Base inventory stock
                $stmt = $this->conn->prepare("SELECT quantity FROM {$this->table_name} WHERE id = :id FOR UPDATE");
                $stmt->execute([':id' => $inventory_id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$row || (int)$row['quantity'] < $quantity) {
                    $this->conn->rollBack();
                    return false; // Insufficient stock
                }
                
                $current_stock = (int)$row['quantity'];
                $stmt = $this->conn->prepare("UPDATE {$this->table_name} SET quantity = quantity - :qty WHERE id = :id AND quantity >= :qty");
                $stmt->execute([':qty' => $quantity, ':id' => $inventory_id]);
                
                if ($stmt->rowCount() === 0) {
                    $this->conn->rollBack();
                    return false;
                }
                
                $new_stock = $current_stock - $quantity;
                $this->logChange($inventory_id, null, null, 'sale_completed', $current_stock, -$quantity, $new_stock, null, null, $sales_transaction_id, $user_id, "Sale #{$sales_transaction_id} sold {$quantity} units");
            }
            
            $this->conn->commit();
            return true;
            
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Inventory sync sale error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check and trigger low stock alerts for both base inventory and variations
     */
    public function checkLowStockAlerts($inventory_id, $variation = null, $unit_type = null) {
        require_once __DIR__ . '/alert_log.php';
        require_once __DIR__ . '/inventory.php';
        require_once __DIR__ . '/inventory_variation.php';
        
        $alert = new AlertLog($this->conn);
        $inventory = new Inventory($this->conn);
        $invVariation = new InventoryVariation($this->conn);
        
        try {
            if (!empty($variation)) {
                // Check variation-specific stock
                $current_stock = $invVariation->getStock($inventory_id, $variation, $unit_type ?: 'per piece');
                
                // Get inventory base threshold as default
                $inventory->id = $inventory_id;
                if ($inventory->readOne()) {
                    $threshold = (int)$inventory->reorder_threshold;
                    
                    // Check if variation has specific threshold (could be extended in future)
                    // For now, use base inventory threshold
                    
                    if ($current_stock <= $threshold) {
                        $alert_type = ($current_stock === 0) ? 'out_of_stock' : 'low_stock';
                        
                        // Get or create alert
                        $alertId = null;
                        if (!$alert->alertExists($inventory_id, $alert_type)) {
                            $alert->inventory_id = $inventory_id;
                            $alert->alert_type = $alert_type;
                            $alert->is_resolved = 0;
                            $alertId = $alert->create();
                        } else {
                            // Get existing alert ID
                            $alertStmt = $this->conn->prepare("SELECT id FROM alert_logs WHERE inventory_id = :inv_id AND alert_type = :alert_type AND is_resolved = 0 ORDER BY alert_date DESC LIMIT 1");
                            $alertStmt->execute([':inv_id' => $inventory_id, ':alert_type' => $alert_type]);
                            $alertRow = $alertStmt->fetch(PDO::FETCH_ASSOC);
                            if ($alertRow) {
                                $alertId = (int)$alertRow['id'];
                            }
                        }
                        
                        // Trigger notification if critical (with duplicate prevention)
                        if ($current_stock === 0 || $current_stock <= max(1, floor($threshold / 2))) {
                            require_once __DIR__ . '/notification.php';
                            $notification = new Notification($this->conn);
                            $notification->createLowStockNotification(
                                $inventory_id,
                                $inventory->name . " ({$variation})",
                                $current_stock,
                                $threshold,
                                $alertId,
                                true // Enable duplicate prevention
                            );
                        }
                    }
                }
            } else {
                // Check base inventory stock
                $inventory->id = $inventory_id;
                if ($inventory->readOne()) {
                    $current_stock = (int)$inventory->quantity;
                    $threshold = (int)$inventory->reorder_threshold;
                    
                    if ($current_stock <= $threshold) {
                        $alert_type = ($current_stock === 0) ? 'out_of_stock' : 'low_stock';
                        
                        // Get or create alert
                        $alertId = null;
                        if (!$alert->alertExists($inventory_id, $alert_type)) {
                            $alert->inventory_id = $inventory_id;
                            $alert->alert_type = $alert_type;
                            $alert->is_resolved = 0;
                            $alertId = $alert->create();
                        } else {
                            // Get existing alert ID
                            $alertStmt = $this->conn->prepare("SELECT id FROM alert_logs WHERE inventory_id = :inv_id AND alert_type = :alert_type AND is_resolved = 0 ORDER BY alert_date DESC LIMIT 1");
                            $alertStmt->execute([':inv_id' => $inventory_id, ':alert_type' => $alert_type]);
                            $alertRow = $alertStmt->fetch(PDO::FETCH_ASSOC);
                            if ($alertRow) {
                                $alertId = (int)$alertRow['id'];
                            }
                        }
                        
                        // Trigger notification if critical (with duplicate prevention)
                        if ($current_stock === 0 || $current_stock <= max(1, floor($threshold / 2))) {
                            require_once __DIR__ . '/notification.php';
                            $notification = new Notification($this->conn);
                            $notification->createLowStockNotification(
                                $inventory_id,
                                $inventory->name,
                                $current_stock,
                                $threshold,
                                $alertId,
                                true // Enable duplicate prevention
                            );
                        }
                    }
                }
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Low stock alert check error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get inventory history for an item (or variation)
     */
    public function getHistory($inventory_id, $variation = null, $limit = 50) {
        try {
            $query = "SELECT * FROM {$this->log_table} WHERE inventory_id = :inventory_id";
            $params = [':inventory_id' => $inventory_id];
            
            if ($variation) {
                $query .= " AND variation = :variation";
                $params[':variation'] = $variation;
            }
            
            $query .= " ORDER BY created_at DESC LIMIT :limit";
            
            $stmt = $this->conn->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Inventory history fetch error: " . $e->getMessage());
            return [];
        }
    }
}
?>

