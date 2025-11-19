<?php
class Order {
    private $conn;
    private $table_name = "orders";

    public $id;
    public $inventory_id;
    public $supplier_id;
    public $user_id;
    public $quantity;
    public $unit_price;
    public $unit_type;
    public $variation;
    public $is_automated;
    public $order_date;
    public $confirmation_status;
    public $confirmation_date;

    public function __construct($db) {
        $this->conn = $db;
    }

    private function hasColumn($column) {
        try {
            $stmt = $this->conn->prepare("SELECT COUNT(*) AS cnt FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c");
            $stmt->bindParam(':t', $this->table_name);
            $stmt->bindParam(':c', $column);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return isset($row['cnt']) ? ((int)$row['cnt'] > 0) : false;
        } catch (Exception $e) {
            return false;
        }
    }

    // Create new order
    public function create() {
        $query = "INSERT INTO " . $this->table_name . " 
                  SET inventory_id = :inventory_id, 
                      supplier_id = :supplier_id, 
                      user_id = :user_id, 
                      quantity = :quantity, 
                      unit_price = :unit_price, 
                      is_automated = :is_automated, 
                      confirmation_status = :confirmation_status";
        if ($this->hasColumn('unit_type')) {
            $query .= ", unit_type = :unit_type";
        }
        if ($this->hasColumn('variation')) {
            $query .= ", variation = :variation";
        }

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $this->inventory_id = htmlspecialchars(strip_tags($this->inventory_id));
        $this->supplier_id = htmlspecialchars(strip_tags($this->supplier_id));
        $this->user_id = htmlspecialchars(strip_tags($this->user_id));
        $this->quantity = htmlspecialchars(strip_tags($this->quantity));
        $this->unit_price = htmlspecialchars(strip_tags($this->unit_price));
        $this->unit_type = htmlspecialchars(strip_tags($this->unit_type));
        $this->variation = htmlspecialchars(strip_tags($this->variation));
        $this->is_automated = htmlspecialchars(strip_tags($this->is_automated));
        $this->confirmation_status = htmlspecialchars(strip_tags($this->confirmation_status));

        // Bind values
        $stmt->bindParam(":inventory_id", $this->inventory_id);
        $stmt->bindParam(":supplier_id", $this->supplier_id);
        $stmt->bindParam(":user_id", $this->user_id);
        $stmt->bindParam(":quantity", $this->quantity);
        $stmt->bindParam(":unit_price", $this->unit_price);
        $stmt->bindParam(":is_automated", $this->is_automated);
        $stmt->bindParam(":confirmation_status", $this->confirmation_status);
        if ($this->hasColumn('unit_type')) {
            $stmt->bindParam(":unit_type", $this->unit_type);
        }
        if ($this->hasColumn('variation')) {
            $stmt->bindParam(":variation", $this->variation);
        }

        // Execute query
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }

        return false;
    }

    // Read all orders
    public function readAll() {
        $query = "SELECT o.*, i.name as item_name, i.unit_price as inventory_unit_price, s.name as supplier_name, u.username 
                  FROM " . $this->table_name . " o 
                  LEFT JOIN inventory i ON o.inventory_id = i.id 
                  LEFT JOIN suppliers s ON o.supplier_id = s.id 
                  LEFT JOIN users u ON o.user_id = u.id 
                  ORDER BY o.order_date DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        return $stmt;
    }

    // Read one order
    public function readOne() {
        $query = "SELECT o.*, i.name as item_name, s.name as supplier_name, u.username 
                  FROM " . $this->table_name . " o 
                  LEFT JOIN inventory i ON o.inventory_id = i.id 
                  LEFT JOIN suppliers s ON o.supplier_id = s.id 
                  LEFT JOIN users u ON o.user_id = u.id 
                  WHERE o.id = :id 
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
            $this->inventory_id = $row['inventory_id'];
            $this->supplier_id = $row['supplier_id'];
            $this->user_id = $row['user_id'];
            $this->quantity = $row['quantity'];
            $this->is_automated = $row['is_automated'];
            $this->order_date = $row['order_date'];
            $this->confirmation_status = $row['confirmation_status'];
            $this->confirmation_date = $row['confirmation_date'];
            
            return true;
        }

        return false;
    }

    // Update order
    public function update() {
        $query = "UPDATE " . $this->table_name . " 
                  SET inventory_id = :inventory_id, 
                      supplier_id = :supplier_id, 
                      quantity = :quantity, 
                      unit_price = :unit_price, 
                      confirmation_status = :confirmation_status";
        if ($this->hasColumn('unit_type')) {
            $query .= ", unit_type = :unit_type";
        }
        if ($this->hasColumn('variation')) {
            $query .= ", variation = :variation";
        }
        $query .= " WHERE id = :id";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $this->inventory_id = htmlspecialchars(strip_tags($this->inventory_id));
        $this->supplier_id = htmlspecialchars(strip_tags($this->supplier_id));
        $this->quantity = htmlspecialchars(strip_tags($this->quantity));
        $this->unit_price = htmlspecialchars(strip_tags($this->unit_price));
        $this->unit_type = htmlspecialchars(strip_tags($this->unit_type));
        $this->variation = htmlspecialchars(strip_tags($this->variation));
        $this->confirmation_status = htmlspecialchars(strip_tags($this->confirmation_status));
        $this->id = htmlspecialchars(strip_tags($this->id));

        // Bind values
        $stmt->bindParam(":inventory_id", $this->inventory_id);
        $stmt->bindParam(":supplier_id", $this->supplier_id);
        $stmt->bindParam(":quantity", $this->quantity);
        $stmt->bindParam(":unit_price", $this->unit_price);
        $stmt->bindParam(":confirmation_status", $this->confirmation_status);
        if ($this->hasColumn('unit_type')) {
            $stmt->bindParam(":unit_type", $this->unit_type);
        }
        if ($this->hasColumn('variation')) {
            $stmt->bindParam(":variation", $this->variation);
        }
        $stmt->bindParam(":id", $this->id);

        // Execute query
        if ($stmt->execute()) {
            return true;
        }

        return false;
    }

    // Update order status
    public function updateStatus($id = null, $status = null) {
        // Use parameters if provided, otherwise use object properties
        $update_id = $id !== null ? $id : $this->id;
        $update_status = $status !== null ? $status : $this->confirmation_status;
        
        $query = "UPDATE " . $this->table_name . " 
                  SET confirmation_status = :status, 
                      confirmation_date = NOW() 
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $update_id = htmlspecialchars(strip_tags($update_id));
        $update_status = htmlspecialchars(strip_tags($update_status));

        // Bind values
        $stmt->bindParam(":status", $update_status);
        $stmt->bindParam(":id", $update_id);

        // Execute query
        if ($stmt->execute()) {
            return true;
        }

        return false;
    }

    // Delete order
    public function delete() {
        $query = "DELETE FROM " . $this->table_name . " WHERE id = :id";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $this->id = htmlspecialchars(strip_tags($this->id));

        // Bind value
        $stmt->bindParam(":id", $this->id);

        // Execute query
        if ($stmt->execute()) {
            return true;
        }

        return false;
    }

    // Get pending orders
    public function getPendingOrders() {
        $query = "SELECT o.*, i.name as item_name, s.name as supplier_name 
                  FROM " . $this->table_name . " o 
                  LEFT JOIN inventory i ON o.inventory_id = i.id 
                  LEFT JOIN suppliers s ON o.supplier_id = s.id 
                  WHERE o.confirmation_status = 'pending' 
                  ORDER BY o.order_date ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        return $stmt;
    }

    // Get recent orders
    public function getRecentOrders($limit = 5) {
        $query = "SELECT o.*, i.name as item_name, s.name as supplier_name 
                  FROM " . $this->table_name . " o 
                  LEFT JOIN inventory i ON o.inventory_id = i.id 
                  LEFT JOIN suppliers s ON o.supplier_id = s.id 
                  ORDER BY o.order_date DESC 
                  LIMIT :limit";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":limit", $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt;
    }

    // Get order count
    public function getCount() {
        $query = "SELECT COUNT(*) as total FROM " . $this->table_name;

        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row['total'];
    }

    // Get pending order count
    public function getPendingCount() {
        $query = "SELECT COUNT(*) as total FROM " . $this->table_name . " WHERE confirmation_status = 'pending'";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row['total'];
    }

    // Create automated order for low stock item
    public function createAutomatedOrder($inventory_id, $supplier_id, $quantity) {
        $query = "INSERT INTO " . $this->table_name . " 
                  SET inventory_id = :inventory_id, 
                      supplier_id = :supplier_id, 
                      user_id = NULL, 
                      quantity = :quantity, 
                      is_automated = 1, 
                      confirmation_status = 'pending'";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $inventory_id = htmlspecialchars(strip_tags($inventory_id));
        $supplier_id = htmlspecialchars(strip_tags($supplier_id));
        $quantity = htmlspecialchars(strip_tags($quantity));

        // Bind values
        $stmt->bindParam(":inventory_id", $inventory_id);
        $stmt->bindParam(":supplier_id", $supplier_id);
        $stmt->bindParam(":quantity", $quantity);

        // Execute query
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }

        return false;
    }

    // Read orders by supplier
    public function readBySupplier($supplier_id) {
        $query = "SELECT o.id,
                         o.inventory_id,
                         o.supplier_id,
                         o.user_id,
                         o.quantity,
                         o.is_automated,
                         o.order_date,
                         o.confirmation_status,
                         o.confirmation_date,
                         COALESCE(o.unit_price, i.unit_price, 0) as unit_price,
                         o.unit_type,
                         o.variation,
                         i.name as inventory_name,
                         i.category,
                         i.unit_price as inventory_unit_price,
                         s.name as supplier_name, 
                         u.username as user_name,
                         u.id as customer_id,
                         u.email as customer_email,
                         u.username as customer_name
                  FROM " . $this->table_name . " o
                  LEFT JOIN inventory i ON o.inventory_id = i.id
                  LEFT JOIN suppliers s ON o.supplier_id = s.id
                  LEFT JOIN users u ON o.user_id = u.id
                  WHERE o.supplier_id = :supplier_id
                  ORDER BY o.order_date DESC";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $supplier_id = htmlspecialchars(strip_tags($supplier_id));

        // Bind value
        $stmt->bindParam(":supplier_id", $supplier_id);

        // Execute query
        $stmt->execute();

        return $stmt;
    }

    // Get order report for reports.php
    public function getOrderReport($start_date, $end_date) {
        $query = "SELECT 
                    o.id,
                    o.order_date,
                    o.inventory_id as inventory_id,
                    i.name as item_name,
                    i.sku,
                    s.name as supplier_name,
                    o.quantity,
                    o.unit_price,
                    (o.quantity * o.unit_price) as total_amount,
                    o.confirmation_status,
                    o.confirmation_date,
                    CASE 
                        WHEN o.is_automated = 1 THEN 'Automated'
                        ELSE 'Manual'
                    END as order_type,
                    o.unit_type,
                    o.variation,
                    u.username as ordered_by
                  FROM " . $this->table_name . " o
                  LEFT JOIN inventory i ON o.inventory_id = i.id
                  LEFT JOIN suppliers s ON o.supplier_id = s.id
                  LEFT JOIN users u ON o.user_id = u.id
                  WHERE DATE(o.order_date) BETWEEN :start_date AND :end_date
                  ORDER BY o.order_date DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":start_date", $start_date);
        $stmt->bindParam(":end_date", $end_date);
        $stmt->execute();

        return $stmt;
    }
}
?>
