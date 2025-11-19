<?php
class Payment {
    private $conn;
    private $table_name = "payments";

    public $id;
    public $order_id;
    public $payment_method;
    public $amount;
    public $payment_status;
    public $payment_date;
    public $transaction_reference;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Create new payment
    public function create() {
        $query = "INSERT INTO " . $this->table_name . " 
                  SET order_id = :order_id, 
                      payment_method = :payment_method, 
                      amount = :amount, 
                      payment_status = :payment_status, 
                      transaction_reference = :transaction_reference";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $this->order_id = htmlspecialchars(strip_tags($this->order_id));
        $this->payment_method = htmlspecialchars(strip_tags($this->payment_method));
        $this->amount = htmlspecialchars(strip_tags($this->amount));
        $this->payment_status = htmlspecialchars(strip_tags($this->payment_status));
        $this->transaction_reference = htmlspecialchars(strip_tags($this->transaction_reference));

        // Bind values
        $stmt->bindParam(":order_id", $this->order_id);
        $stmt->bindParam(":payment_method", $this->payment_method);
        $stmt->bindParam(":amount", $this->amount);
        $stmt->bindParam(":payment_status", $this->payment_status);
        $stmt->bindParam(":transaction_reference", $this->transaction_reference);

        // Execute query
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }

        return false;
    }

    // Read all payments
    public function readAll() {
        $query = "SELECT p.*, o.id as order_number 
                  FROM " . $this->table_name . " p 
                  LEFT JOIN orders o ON p.order_id = o.id 
                  ORDER BY p.payment_date DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        return $stmt;
    }

    // Read one payment
    public function readOne() {
        $query = "SELECT p.*, o.id as order_number 
                  FROM " . $this->table_name . " p 
                  LEFT JOIN orders o ON p.order_id = o.id 
                  WHERE p.id = :id 
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
            $this->payment_method = $row['payment_method'];
            $this->amount = $row['amount'];
            $this->payment_status = $row['payment_status'];
            $this->payment_date = $row['payment_date'];
            $this->transaction_reference = $row['transaction_reference'];
            
            return true;
        }

        return false;
    }

    // Update payment status
    public function updateStatus($id, $status) {
        $query = "UPDATE " . $this->table_name . " SET payment_status = :status WHERE id = :id";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $id = htmlspecialchars(strip_tags($id));
        $status = htmlspecialchars(strip_tags($status));

        // Bind values
        $stmt->bindParam(":status", $status);
        $stmt->bindParam(":id", $id);

        // Execute query
        if ($stmt->execute()) {
            return true;
        }

        return false;
    }

    // Get payments by order
    public function getPaymentsByOrder($order_id) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE order_id = :order_id ORDER BY payment_date DESC";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $order_id = htmlspecialchars(strip_tags($order_id));

        // Bind value
        $stmt->bindParam(":order_id", $order_id);

        // Execute query
        $stmt->execute();

        return $stmt;
    }

    // Get recent payments
    public function getRecentPayments($limit = 5) {
        $query = "SELECT p.*, o.id as order_number 
                  FROM " . $this->table_name . " p 
                  LEFT JOIN orders o ON p.order_id = o.id 
                  ORDER BY p.payment_date DESC 
                  LIMIT :limit";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":limit", $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt;
    }

    // Process GCash payment (mock function)
    public function processGCashPayment($amount, $reference) {
        // In a real application, you would integrate with GCash API
        // For this demo, we'll just return a success response
        return [
            'success' => true,
            'transaction_id' => 'GC' . time() . rand(1000, 9999),
            'amount' => $amount,
            'reference' => $reference,
            'status' => 'completed'
        ];
    }
}
?>
