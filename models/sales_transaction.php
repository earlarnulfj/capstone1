<?php
class SalesTransaction {
    private $conn;
    private $table_name = "sales_transactions";

    public $id;
    public $inventory_id;
    public $user_id;
    public $quantity;
    public $transaction_date;
    public $total_amount;
    public $unit_type;
    public $variation;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Create new sales transaction
    public function create() {
        $query = "INSERT INTO " . $this->table_name . " 
                  SET inventory_id = :inventory_id, 
                      user_id = :user_id, 
                      quantity = :quantity, 
                      total_amount = :total_amount,
                      unit_type = :unit_type,
                      variation = :variation";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $this->inventory_id = htmlspecialchars(strip_tags($this->inventory_id));
        $this->user_id = htmlspecialchars(strip_tags($this->user_id));
        $this->quantity = htmlspecialchars(strip_tags($this->quantity));
        $this->total_amount = htmlspecialchars(strip_tags($this->total_amount));
        $this->unit_type = htmlspecialchars(strip_tags($this->unit_type ?? 'per piece'));
        $this->variation = htmlspecialchars(strip_tags($this->variation ?? null));

        // Bind values
        $stmt->bindParam(":inventory_id", $this->inventory_id);
        $stmt->bindParam(":user_id", $this->user_id);
        $stmt->bindParam(":quantity", $this->quantity);
        $stmt->bindParam(":total_amount", $this->total_amount);
        $stmt->bindParam(":unit_type", $this->unit_type);
        $stmt->bindParam(":variation", $this->variation);

        // Execute query
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }

        return false;
    }

    // Read all sales transactions
    public function readAll() {
        $query = "SELECT t.*, i.name as item_name, u.username, 
                         t.unit_type, t.variation 
                  FROM " . $this->table_name . " t 
                  LEFT JOIN inventory i ON t.inventory_id = i.id 
                  LEFT JOIN users u ON t.user_id = u.id 
                  ORDER BY t.transaction_date DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        return $stmt;
    }

    // Read one sales transaction
    public function readOne() {
        $query = "SELECT t.*, i.name as item_name, u.username, 
                         t.unit_type, t.variation 
                  FROM " . $this->table_name . " t 
                  LEFT JOIN inventory i ON t.inventory_id = i.id 
                  LEFT JOIN users u ON t.user_id = u.id 
                  WHERE t.id = :id 
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
            $this->user_id = $row['user_id'];
            $this->quantity = $row['quantity'];
            $this->transaction_date = $row['transaction_date'];
            $this->total_amount = $row['total_amount'];
            $this->unit_type = $row['unit_type'] ?? null;
            $this->variation = $row['variation'] ?? null;
            
            return true;
        }

        return false;
    }

    // Get recent sales transactions
    public function getRecentTransactions($limit = 5) {
        $query = "SELECT t.*, i.name as item_name, u.username, 
                         t.unit_type, t.variation 
                  FROM " . $this->table_name . " t 
                  LEFT JOIN inventory i ON t.inventory_id = i.id 
                  LEFT JOIN users u ON t.user_id = u.id 
                  ORDER BY t.transaction_date DESC 
                  LIMIT :limit";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":limit", $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt;
    }

    // Get sales transaction count
    public function getCount() {
        $query = "SELECT COUNT(*) as total FROM " . $this->table_name;

        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row['total'];
    }

    // Get total sales amount
    public function getTotalSales() {
        $query = "SELECT SUM(total_amount) as total FROM " . $this->table_name;

        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row['total'];
    }

    // Get sales by date range
    public function getSalesByDateRange($start_date, $end_date) {
        $query = "SELECT t.*, i.name as item_name, u.username, 
                         t.unit_type, t.variation 
                  FROM " . $this->table_name . " t 
                  LEFT JOIN inventory i ON t.inventory_id = i.id 
                  LEFT JOIN users u ON t.user_id = u.id 
                  WHERE DATE(t.transaction_date) BETWEEN :start_date AND :end_date 
                  ORDER BY t.transaction_date DESC";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $start_date = htmlspecialchars(strip_tags($start_date));
        $end_date = htmlspecialchars(strip_tags($end_date));

        // Bind values
        $stmt->bindParam(":start_date", $start_date);
        $stmt->bindParam(":end_date", $end_date);

        // Execute query
        $stmt->execute();

        return $stmt;
    }

    // Get daily sales for chart
    public function getDailySales($days = 7) {
        $query = "SELECT DATE(transaction_date) as date, SUM(total_amount) as total 
                  FROM " . $this->table_name . " 
                  WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY) 
                  GROUP BY DATE(transaction_date) 
                  ORDER BY DATE(transaction_date)";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":days", $days, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt;
    }

    // Get sales report for reports.php
    public function getSalesReport($start_date, $end_date) {
        $query = "SELECT 
                    DATE(t.transaction_date) as date,
                    t.inventory_id as inventory_id,
                    i.name as item_name,
                    i.category as category,
                    SUM(t.quantity) as total_quantity,
                    SUM(t.total_amount) as total_amount,
                    COUNT(t.id) as transaction_count,
                    u.username as cashier,
                    GROUP_CONCAT(DISTINCT t.unit_type ORDER BY t.unit_type SEPARATOR ', ') as unit_types,
                    GROUP_CONCAT(DISTINCT t.variation ORDER BY t.variation SEPARATOR ', ') as variations
                  FROM " . $this->table_name . " t 
                  LEFT JOIN inventory i ON t.inventory_id = i.id 
                  LEFT JOIN users u ON t.user_id = u.id 
                  WHERE DATE(t.transaction_date) BETWEEN :start_date AND :end_date 
                  GROUP BY DATE(t.transaction_date), t.inventory_id, t.user_id
                  ORDER BY t.transaction_date DESC, total_amount DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":start_date", $start_date);
        $stmt->bindParam(":end_date", $end_date);
        $stmt->execute();

        return $stmt;
    }
}
?>
