<?php
class AlertLog {
    private $conn;
    private $table_name = "alert_logs";

    public $id;
    public $inventory_id;
    public $alert_type;
    public $alert_date;
    public $is_resolved;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Create new alert log
    public function create() {
        $query = "INSERT INTO " . $this->table_name . " 
                  SET inventory_id = :inventory_id, 
                      alert_type = :alert_type, 
                      is_resolved = :is_resolved";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $this->inventory_id = htmlspecialchars(strip_tags($this->inventory_id));
        $this->alert_type = htmlspecialchars(strip_tags($this->alert_type));
        $this->is_resolved = htmlspecialchars(strip_tags($this->is_resolved));

        // Bind values
        $stmt->bindParam(":inventory_id", $this->inventory_id);
        $stmt->bindParam(":alert_type", $this->alert_type);
        $stmt->bindParam(":is_resolved", $this->is_resolved);

        // Execute query
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }

        return false;
    }

    // Read all alert logs
    public function readAll() {
        $query = "SELECT a.*, i.name as item_name 
                  FROM " . $this->table_name . " a 
                  LEFT JOIN inventory i ON a.inventory_id = i.id 
                  ORDER BY a.alert_date DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        return $stmt;
    }

    // Read one alert log
    public function readOne() {
        $query = "SELECT a.*, i.name as item_name 
                  FROM " . $this->table_name . " a 
                  LEFT JOIN inventory i ON a.inventory_id = i.id 
                  WHERE a.id = :id 
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
            $this->alert_type = $row['alert_type'];
            $this->alert_date = $row['alert_date'];
            $this->is_resolved = $row['is_resolved'];
            
            return true;
        }

        return false;
    }

    // Update alert log status
    public function updateStatus($id, $is_resolved) {
        $query = "UPDATE " . $this->table_name . " SET is_resolved = :is_resolved WHERE id = :id";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $id = htmlspecialchars(strip_tags($id));
        $is_resolved = htmlspecialchars(strip_tags($is_resolved));

        // Bind values
        $stmt->bindParam(":is_resolved", $is_resolved);
        $stmt->bindParam(":id", $id);

        // Execute query
        if ($stmt->execute()) {
            return true;
        }

        return false;
    }

    // Get unresolved alerts
    public function getUnresolvedAlerts() {
        $query = "SELECT a.*, i.name as item_name, i.quantity, i.reorder_threshold 
                  FROM " . $this->table_name . " a 
                  LEFT JOIN inventory i ON a.inventory_id = i.id 
                  WHERE a.is_resolved = 0 
                  ORDER BY a.alert_date DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        return $stmt;
    }

    // Get recent alerts
    public function getRecentAlerts($limit = 5) {
        $query = "SELECT a.*, i.name as item_name 
                  FROM " . $this->table_name . " a 
                  LEFT JOIN inventory i ON a.inventory_id = i.id 
                  ORDER BY a.alert_date DESC 
                  LIMIT :limit";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":limit", $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt;
    }

    // Check if alert exists for inventory
    public function alertExists($inventory_id, $alert_type) {
        $query = "SELECT id FROM " . $this->table_name . " 
                  WHERE inventory_id = :inventory_id 
                  AND alert_type = :alert_type 
                  AND is_resolved = 0 
                  LIMIT 0,1";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $inventory_id = htmlspecialchars(strip_tags($inventory_id));
        $alert_type = htmlspecialchars(strip_tags($alert_type));

        // Bind values
        $stmt->bindParam(":inventory_id", $inventory_id);
        $stmt->bindParam(":alert_type", $alert_type);

        // Execute query
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    // Get alert count
    public function getCount() {
        $query = "SELECT COUNT(*) as total FROM " . $this->table_name;

        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row['total'];
    }

    // Get unresolved alert count
    public function getUnresolvedCount() {
        $query = "SELECT COUNT(*) as total FROM " . $this->table_name . " WHERE is_resolved = 0";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row['total'];
    }
}
?>
