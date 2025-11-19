<?php
class Delivery {
    private $conn;
    private $table_name = "deliveries";

    public $id;
    public $order_id;
    public $delivery_date;
    public $latitude;
    public $longitude;
    public $status;
    public $replenished_quantity;
    public $driver_name;
    public $vehicle_info;
    public $tracking_number;
    public $delivery_address;
    public $notes;
    public $updated_at;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Create new delivery
    public function create() {
        $query = "INSERT INTO " . $this->table_name . " 
                  SET order_id = :order_id, 
                      delivery_date = :delivery_date, 
                      latitude = :latitude, 
                      longitude = :longitude, 
                      status = :status, 
                      replenished_quantity = :replenished_quantity,
                      driver_name = :driver_name,
                      vehicle_info = :vehicle_info,
                      tracking_number = :tracking_number,
                      delivery_address = :delivery_address,
                      notes = :notes";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $this->order_id = htmlspecialchars(strip_tags($this->order_id));
        $this->delivery_date = $this->delivery_date ? htmlspecialchars(strip_tags($this->delivery_date)) : null;
        $this->latitude = htmlspecialchars(strip_tags($this->latitude));
        $this->longitude = htmlspecialchars(strip_tags($this->longitude));
        $this->status = htmlspecialchars(strip_tags($this->status));
        $this->replenished_quantity = htmlspecialchars(strip_tags($this->replenished_quantity));
        $this->driver_name = htmlspecialchars(strip_tags($this->driver_name ?? ''));
        $this->vehicle_info = htmlspecialchars(strip_tags($this->vehicle_info ?? ''));
        $this->tracking_number = htmlspecialchars(strip_tags($this->tracking_number ?? ''));
        $this->delivery_address = htmlspecialchars(strip_tags($this->delivery_address ?? ''));
        $this->notes = htmlspecialchars(strip_tags($this->notes ?? ''));

        // Bind values
        $stmt->bindParam(":order_id", $this->order_id);
        $stmt->bindParam(":delivery_date", $this->delivery_date);
        $stmt->bindParam(":latitude", $this->latitude);
        $stmt->bindParam(":longitude", $this->longitude);
        $stmt->bindParam(":status", $this->status);
        $stmt->bindParam(":replenished_quantity", $this->replenished_quantity);
        $stmt->bindParam(":driver_name", $this->driver_name);
        $stmt->bindParam(":vehicle_info", $this->vehicle_info);
        $stmt->bindParam(":tracking_number", $this->tracking_number);
        $stmt->bindParam(":delivery_address", $this->delivery_address);
        $stmt->bindParam(":notes", $this->notes);

        // Execute query
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }

        return false;
    }

    // Read all deliveries
    public function readAll() {
        $query = "SELECT d.*, o.id as order_number, i.name as item_name, s.name as supplier_name 
                  FROM " . $this->table_name . " d 
                  LEFT JOIN orders o ON d.order_id = o.id 
                  LEFT JOIN inventory i ON o.inventory_id = i.id 
                  LEFT JOIN suppliers s ON o.supplier_id = s.id 
                  ORDER BY d.delivery_date DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        return $stmt;
    }

    // Read one delivery
    public function readOne() {
        $query = "SELECT d.*, o.id as order_number, i.name as item_name, s.name as supplier_name 
                  FROM " . $this->table_name . " d 
                  LEFT JOIN orders o ON d.order_id = o.id 
                  LEFT JOIN inventory i ON o.inventory_id = i.id 
                  LEFT JOIN suppliers s ON o.supplier_id = s.id 
                  WHERE d.id = :id 
                  LIMIT 0,1";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $this->id = htmlspecialchars(strip_tags($this->id));

        // Bind value
        $stmt->bindParam(":id", $this->id);

        // Execute query
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $this->id = $row['id'];
            $this->order_id = $row['order_id'];
            $this->delivery_date = $row['delivery_date'];
            $this->latitude = $row['latitude'];
            $this->longitude = $row['longitude'];
            $this->status = $row['status'];
            $this->replenished_quantity = $row['replenished_quantity'];
            
            return true;
        }

        return false;
    }

    // Update delivery status
    public function updateStatus($id = null, $status = null) {
        // Use parameters if provided, otherwise use object properties
        $update_id = $id !== null ? $id : $this->id;
        $update_status = $status !== null ? $status : $this->status;
        
        $query = "UPDATE " . $this->table_name . " SET status = :status WHERE id = :id";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $update_id = htmlspecialchars(strip_tags($update_id));
        $update_status = htmlspecialchars(strip_tags($update_status));

        // Bind values
        $stmt->bindParam(":status", $update_status);
        $stmt->bindParam(":id", $update_id);

        // Execute query
        if ($stmt->execute()) {
            // If status is delivered, also update the related order to 'delivered' and auto-update inventory
            if ($update_status === 'delivered') {
                // Find related order
                $oidStmt = $this->conn->prepare("SELECT order_id FROM " . $this->table_name . " WHERE id = :id");
                $oidStmt->bindParam(":id", $update_id);
                $oidStmt->execute();
                $order_id = $oidStmt->fetchColumn();
                if ($order_id) {
                    $os = $this->conn->prepare("UPDATE orders SET confirmation_status = 'delivered', confirmation_date = NOW() WHERE id = :id");
                    $os->bindParam(":id", $order_id);
                    $os->execute();
                }
                $this->autoUpdateInventoryOnCompletion($update_id);
            }
            return true;
        }

        return false;
    }

    // Update delivery location
    public function updateLocation($id, $latitude, $longitude) {
        $query = "UPDATE " . $this->table_name . " 
                  SET latitude = :latitude, 
                      longitude = :longitude 
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $id = htmlspecialchars(strip_tags($id));
        $latitude = htmlspecialchars(strip_tags($latitude));
        $longitude = htmlspecialchars(strip_tags($longitude));

        // Bind values
        $stmt->bindParam(":latitude", $latitude);
        $stmt->bindParam(":longitude", $longitude);
        $stmt->bindParam(":id", $id);

        // Execute query
        if ($stmt->execute()) {
            return true;
        }

        return false;
    }

    // Get deliveries by order
    public function getDeliveriesByOrder($order_id) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE order_id = :order_id ORDER BY delivery_date DESC";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $order_id = htmlspecialchars(strip_tags($order_id));

        // Bind value
        $stmt->bindParam(":order_id", $order_id);

        // Execute query
        $stmt->execute();

        return $stmt;
    }

    // Get recent deliveries
    public function getRecentDeliveries($limit = 5) {
        $query = "SELECT d.*, o.id as order_number, i.name as item_name, s.name as supplier_name 
                  FROM " . $this->table_name . " d 
                  LEFT JOIN orders o ON d.order_id = o.id 
                  LEFT JOIN inventory i ON o.inventory_id = i.id 
                  LEFT JOIN suppliers s ON o.supplier_id = s.id 
                  ORDER BY d.delivery_date DESC 
                  LIMIT :limit";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":limit", $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt;
    }

    // Get in-transit deliveries
    public function getInTransitDeliveries() {
        $query = "SELECT d.*, o.id as order_number, i.name as item_name, s.name as supplier_name 
                  FROM " . $this->table_name . " d 
                  LEFT JOIN orders o ON d.order_id = o.id 
                  LEFT JOIN inventory i ON o.inventory_id = i.id 
                  LEFT JOIN suppliers s ON o.supplier_id = s.id 
                  WHERE d.status = 'in_transit' 
                  ORDER BY d.delivery_date ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        return $stmt;
    }

    // Read deliveries by supplier
    public function readBySupplier($supplier_id) {
        $query = "SELECT d.*, o.id as order_number, i.name as item_name, s.name as supplier_name, o.quantity as order_quantity
                  FROM " . $this->table_name . " d 
                  LEFT JOIN orders o ON d.order_id = o.id 
                  LEFT JOIN inventory i ON o.inventory_id = i.id 
                  LEFT JOIN suppliers s ON o.supplier_id = s.id 
                  WHERE s.id = :supplier_id 
                  ORDER BY d.delivery_date DESC";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $supplier_id = htmlspecialchars(strip_tags($supplier_id));

        // Bind value
        $stmt->bindParam(":supplier_id", $supplier_id);

        // Execute query
        $stmt->execute();

        return $stmt;
    }

    // Update delivery status by order ID
    public function updateStatusByOrderId($order_id, $status) {
        $query = "UPDATE " . $this->table_name . " SET status = :status WHERE order_id = :order_id";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $order_id = htmlspecialchars(strip_tags($order_id));
        $status = htmlspecialchars(strip_tags($status));

        // Bind values
        $stmt->bindParam(":status", $status);
        $stmt->bindParam(":order_id", $order_id);

        // Execute query
        if ($stmt->execute()) {
            // If status is delivered, automatically update inventory for all deliveries of this order
            if ($status === 'delivered') {
                // Update related order status to delivered
                $order_update = $this->conn->prepare("UPDATE orders SET confirmation_status = 'delivered', confirmation_date = NOW() WHERE id = :order_id");
                $order_update->bindParam(":order_id", $order_id);
                $order_update->execute();

                $delivery_query = "SELECT id FROM " . $this->table_name . " WHERE order_id = :order_id";
                $delivery_stmt = $this->conn->prepare($delivery_query);
                $delivery_stmt->bindParam(":order_id", $order_id);
                $delivery_stmt->execute();
                
                while ($delivery_row = $delivery_stmt->fetch(PDO::FETCH_ASSOC)) {
                    $this->autoUpdateInventoryOnCompletion($delivery_row['id']);
                }
            }
            return true;
        }

        return false;
    }

    // Get delivery by order ID
    public function getByOrderId($order_id) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE order_id = :order_id LIMIT 1";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $order_id = htmlspecialchars(strip_tags($order_id));

        // Bind value
        $stmt->bindParam(":order_id", $order_id);

        // Execute query
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row;
        }

        return false;
    }

    // Complete delivery and update inventory
    public function completeDelivery($id, $replenished_quantity) {
        // Start transaction
        $this->conn->beginTransaction();
        
        try {
            // Update delivery status
            $query = "UPDATE " . $this->table_name . " 
                      SET status = 'delivered', 
                          delivery_date = NOW(), 
                          replenished_quantity = :replenished_quantity 
                      WHERE id = :id";

            $stmt = $this->conn->prepare($query);
            
            // Sanitize
            $id = htmlspecialchars(strip_tags($id));
            $replenished_quantity = htmlspecialchars(strip_tags($replenished_quantity));
            
            // Bind values
            $stmt->bindParam(":replenished_quantity", $replenished_quantity);
            $stmt->bindParam(":id", $id);
            
            $stmt->execute();
            
            // Get order and inventory details
            $query = "SELECT d.order_id, o.inventory_id, i.quantity 
                      FROM " . $this->table_name . " d 
                      JOIN orders o ON d.order_id = o.id 
                      JOIN inventory i ON o.inventory_id = i.id 
                      WHERE d.id = :id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":id", $id);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $order_id = $row['order_id'];
                $inventory_id = $row['inventory_id'];
                $current_quantity = $row['quantity'];
                
                // Update inventory quantity
                $new_quantity = $current_quantity + $replenished_quantity;
                $query = "UPDATE inventory SET quantity = :quantity WHERE id = :id";
                
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(":quantity", $new_quantity);
                $stmt->bindParam(":id", $inventory_id);
                $stmt->execute();
                
                // Update order status
                $query = "UPDATE orders SET confirmation_status = 'delivered', confirmation_date = NOW() WHERE id = :id";
                
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(":id", $order_id);
                $stmt->execute();
                
                // Resolve any related alerts
                $query = "UPDATE alert_logs SET is_resolved = 1 WHERE inventory_id = :inventory_id AND is_resolved = 0";
                
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(":inventory_id", $inventory_id);
                $stmt->execute();
                
                // Commit transaction
                $this->conn->commit();
                
                return true;
            }
            
            // Rollback transaction if no order found
            $this->conn->rollBack();
            return false;
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $this->conn->rollBack();
            return false;
        }
    }

    // Auto-create or update inventory when delivery is completed
    public function autoUpdateInventoryOnCompletion($delivery_id) {
        // Start transaction
        $this->conn->beginTransaction();
        
        try {
            // Get delivery details with order and item information
            // Use replenished_quantity if available (actual delivered quantity), otherwise use order_quantity
            $query = "SELECT d.*, 
                             COALESCE(d.replenished_quantity, o.quantity) as order_quantity, 
                             o.inventory_id, o.unit_type, o.variation, o.unit_price as order_unit_price,
                             i.name as item_name, i.sku, i.category, i.unit_price, 
                             i.supplier_id, i.location, i.description, i.reorder_threshold,
                             s.name as supplier_name
                      FROM " . $this->table_name . " d 
                      JOIN orders o ON d.order_id = o.id 
                      LEFT JOIN inventory i ON o.inventory_id = i.id 
                      LEFT JOIN suppliers s ON i.supplier_id = s.id 
                      WHERE d.id = :delivery_id AND d.status IN ('delivered', 'completed')";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(":delivery_id", $delivery_id);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $delivery_data = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Check variation table availability
                $hasVariationTable = false;
                try {
                    $this->conn->query("SELECT 1 FROM inventory_variations LIMIT 1");
                    $hasVariationTable = true;
                } catch (PDOException $e) {
                    $hasVariationTable = false;
                }
                
                // Lazy-load InventoryVariation model
                if ($hasVariationTable) {
                    require_once __DIR__ . '/inventory_variation.php';
                    $invVariation = new InventoryVariation($this->conn);
                }
                
                // Use inventory sync service for unified stock management and logging
                require_once __DIR__ . '/inventory_sync.php';
                $inventorySync = new InventorySync($this->conn);
                
                // Check if inventory item already exists
                if ($delivery_data['inventory_id']) {
                    // Use inventory sync service to receive delivery (handles both base and variation stock)
                    $variation = !empty($delivery_data['variation']) ? $delivery_data['variation'] : null;
                    $unitType = !empty($delivery_data['unit_type']) ? $delivery_data['unit_type'] : null;
                    $quantity = (int)$delivery_data['order_quantity'];
                    $orderUnitPrice = isset($delivery_data['order_unit_price']) && $delivery_data['order_unit_price'] > 0 ? (float)$delivery_data['order_unit_price'] : null;
                    
                    if (!$inventorySync->receiveDelivery(
                        (int)$delivery_data['inventory_id'],
                        $variation,
                        $unitType,
                        $quantity,
                        $delivery_id,
                        null, // user_id can be added later if needed
                        $orderUnitPrice
                    )) {
                        throw new Exception("Failed to update inventory stock via sync service");
                    }
                    
                    // Check low stock alerts after delivery
                    $inventorySync->checkLowStockAlerts((int)$delivery_data['inventory_id'], $variation, $unitType);
                } else {
                    // Create new inventory item from delivery data
                    $insert_query = "INSERT INTO inventory 
                                    (sku, name, description, quantity, reorder_threshold, 
                                     supplier_id, category, unit_price, location, created_at) 
                                    VALUES 
                                    (:sku, :name, :description, :quantity, :reorder_threshold, 
                                     :supplier_id, :category, :unit_price, :location, NOW())";
                    
                    $insert_stmt = $this->conn->prepare($insert_query);
                    
                    // Generate SKU if not exists
                    $sku = $delivery_data['sku'] ?: 'DEL-' . $delivery_id . '-' . time();
                    $name = $delivery_data['item_name'] ?: 'Delivered Item #' . $delivery_id;
                    $description = $delivery_data['description'] ?: 'Auto-created from delivery #' . $delivery_id;
                    $quantity = $delivery_data['order_quantity'];
                    $reorder_threshold = $delivery_data['reorder_threshold'] ?: 10;
                    $category = $delivery_data['category'] ?: 'Delivered Items';
                    // Use order_unit_price if available, otherwise fall back to inventory unit_price
                    $unit_price = isset($delivery_data['order_unit_price']) && $delivery_data['order_unit_price'] > 0 
                        ? (float)$delivery_data['order_unit_price'] 
                        : ($delivery_data['unit_price'] ?: 0.00);
                    $location = $delivery_data['location'] ?: 'Warehouse';
                    
                    $insert_stmt->bindParam(":sku", $sku);
                    $insert_stmt->bindParam(":name", $name);
                    $insert_stmt->bindParam(":description", $description);
                    $insert_stmt->bindParam(":quantity", $quantity);
                    $insert_stmt->bindParam(":reorder_threshold", $reorder_threshold);
                    $insert_stmt->bindParam(":supplier_id", $delivery_data['supplier_id']);
                    $insert_stmt->bindParam(":category", $category);
                    $insert_stmt->bindParam(":unit_price", $unit_price);
                    $insert_stmt->bindParam(":location", $location);
                    
                    $insert_stmt->execute();
                    
                    // Get the new inventory ID and update the order
                    $new_inventory_id = $this->conn->lastInsertId();
                    $update_order_query = "UPDATE orders SET inventory_id = :inventory_id WHERE id = :order_id";
                    $update_order_stmt = $this->conn->prepare($update_order_query);
                    $update_order_stmt->bindParam(":inventory_id", $new_inventory_id);
                    $update_order_stmt->bindParam(":order_id", $delivery_data['order_id']);
                    $update_order_stmt->execute();
                    
                    // Use inventory sync service for new inventory item too
                    $variation = !empty($delivery_data['variation']) ? $delivery_data['variation'] : null;
                    $unitType = !empty($delivery_data['unit_type']) ? $delivery_data['unit_type'] : null;
                    $quantity = (int)$delivery_data['order_quantity'];
                    $orderUnitPrice = isset($delivery_data['order_unit_price']) && $delivery_data['order_unit_price'] > 0 ? (float)$delivery_data['order_unit_price'] : null;
                    
                    if ($variation && $hasVariationTable) {
                        // For new items with variations, use sync service
                        if (!$inventorySync->receiveDelivery(
                            (int)$new_inventory_id,
                            $variation,
                            $unitType,
                            $quantity,
                            $delivery_id,
                            null,
                            $orderUnitPrice
                        )) {
                            throw new Exception("Failed to update variant stock for new inventory item");
                        }
                    }
                    
                    // Check low stock alerts after delivery
                    $inventorySync->checkLowStockAlerts((int)$new_inventory_id, $variation, $unitType);
                }
                
                // Commit transaction
                $this->conn->commit();
                return true;
            }
            
            // Rollback if no delivery found
            $this->conn->rollBack();
            return false;
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $this->conn->rollBack();
            return false;
        }
    }
}
?>
