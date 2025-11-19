<?php
class Supplier {
    private $conn;
    private $table_name = "suppliers";

    public $id;
    public $name;
    public $contact_phone;
    public $email;
    public $address;
    public $status;
    public $payment_methods; // comma-separated list, e.g. 'Cash on Delivery,GCash'

    public function __construct($db) {
        $this->conn = $db;
    }

    // Create supplier
    public function create() {
        $query = "INSERT INTO " . $this->table_name . " 
            (name, contact_phone, email, address, status, payment_methods) 
            VALUES (:name, :contact_phone, :email, :address, :status, :payment_methods)";

        $stmt = $this->conn->prepare($query);

        // sanitize
        $this->name = htmlspecialchars(strip_tags($this->name));
        $this->contact_phone = htmlspecialchars(strip_tags($this->contact_phone));
        $this->email = htmlspecialchars(strip_tags($this->email));
        $this->address = htmlspecialchars(strip_tags($this->address));
        $this->status = htmlspecialchars(strip_tags($this->status));
        $this->payment_methods = htmlspecialchars(strip_tags($this->payment_methods));

        $stmt->bindParam(':name', $this->name);
        $stmt->bindParam(':contact_phone', $this->contact_phone);
        $stmt->bindParam(':email', $this->email);
        $stmt->bindParam(':address', $this->address);
        $stmt->bindParam(':status', $this->status);
        $stmt->bindParam(':payment_methods', $this->payment_methods);

        if ($stmt->execute()) {
            return true;
        }
        return false;
    }

    // Update supplier
    public function update() {
        $query = "UPDATE " . $this->table_name . " SET 
            name = :name, 
            contact_phone = :contact_phone, 
            email = :email, 
            address = :address, 
            status = :status,
            payment_methods = :payment_methods
            WHERE id = :id";

        $stmt = $this->conn->prepare($query);

        // sanitize
        $this->name = htmlspecialchars(strip_tags($this->name));
        $this->contact_phone = htmlspecialchars(strip_tags($this->contact_phone));
        $this->email = htmlspecialchars(strip_tags($this->email));
        $this->address = htmlspecialchars(strip_tags($this->address));
        $this->status = htmlspecialchars(strip_tags($this->status));
        $this->payment_methods = htmlspecialchars(strip_tags($this->payment_methods));
        $this->id = htmlspecialchars(strip_tags($this->id));

        $stmt->bindParam(':name', $this->name);
        $stmt->bindParam(':contact_phone', $this->contact_phone);
        $stmt->bindParam(':email', $this->email);
        $stmt->bindParam(':address', $this->address);
        $stmt->bindParam(':status', $this->status);
        $stmt->bindParam(':payment_methods', $this->payment_methods);
        $stmt->bindParam(':id', $this->id);

        if ($stmt->execute()) {
            return true;
        }
        return false;
    }

    // Delete supplier
    public function delete() {
        try {
            // Start transaction
            $this->conn->beginTransaction();
            
            // First, get all inventory items for this supplier
            $query_inventory = "SELECT id FROM inventory WHERE supplier_id = :supplier_id";
            $stmt_inventory = $this->conn->prepare($query_inventory);
            $stmt_inventory->bindParam(':supplier_id', $this->id);
            $stmt_inventory->execute();
            $inventory_ids = $stmt_inventory->fetchAll(PDO::FETCH_COLUMN);
            
            // Delete cascade for each inventory item without nested transactions
            if (!empty($inventory_ids)) {
                $inventory_ids_placeholder = str_repeat('?,', count($inventory_ids) - 1) . '?';
                
                // Delete alert logs for these inventory items
                $query_alert_logs = "DELETE FROM alert_logs WHERE inventory_id IN ($inventory_ids_placeholder)";
                $stmt_alert_logs = $this->conn->prepare($query_alert_logs);
                $stmt_alert_logs->execute($inventory_ids);
                
                // Delete sales transactions for these inventory items
                $query_sales = "DELETE FROM sales_transactions WHERE inventory_id IN ($inventory_ids_placeholder)";
                $stmt_sales = $this->conn->prepare($query_sales);
                $stmt_sales->execute($inventory_ids);
                
                // Get orders related to these inventory items
                $query_get_inventory_orders = "SELECT id FROM orders WHERE inventory_id IN ($inventory_ids_placeholder)";
                $stmt_get_inventory_orders = $this->conn->prepare($query_get_inventory_orders);
                $stmt_get_inventory_orders->execute($inventory_ids);
                $inventory_order_ids = $stmt_get_inventory_orders->fetchAll(PDO::FETCH_COLUMN);
                
                if (!empty($inventory_order_ids)) {
                    $inventory_order_ids_placeholder = str_repeat('?,', count($inventory_order_ids) - 1) . '?';
                    
                    // Delete deliveries for inventory orders
                    $query_inventory_deliveries = "DELETE FROM deliveries WHERE order_id IN ($inventory_order_ids_placeholder)";
                    $stmt_inventory_deliveries = $this->conn->prepare($query_inventory_deliveries);
                    $stmt_inventory_deliveries->execute($inventory_order_ids);
                    
                    // Delete payments for inventory orders
                    $query_inventory_payments = "DELETE FROM payments WHERE order_id IN ($inventory_order_ids_placeholder)";
                    $stmt_inventory_payments = $this->conn->prepare($query_inventory_payments);
                    $stmt_inventory_payments->execute($inventory_order_ids);
                }
                
                // Delete orders for these inventory items
                $query_inventory_orders = "DELETE FROM orders WHERE inventory_id IN ($inventory_ids_placeholder)";
                $stmt_inventory_orders = $this->conn->prepare($query_inventory_orders);
                $stmt_inventory_orders->execute($inventory_ids);
                
                // Delete the inventory items themselves
                $query_delete_inventory = "DELETE FROM inventory WHERE id IN ($inventory_ids_placeholder)";
                $stmt_delete_inventory = $this->conn->prepare($query_delete_inventory);
                $stmt_delete_inventory->execute($inventory_ids);
            }
            
            // Delete orders directly related to this supplier (that might not have inventory_id)
            $query_get_supplier_orders = "SELECT id FROM orders WHERE supplier_id = :supplier_id";
            $stmt_get_supplier_orders = $this->conn->prepare($query_get_supplier_orders);
            $stmt_get_supplier_orders->bindParam(':supplier_id', $this->id);
            $stmt_get_supplier_orders->execute();
            $supplier_order_ids = $stmt_get_supplier_orders->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($supplier_order_ids)) {
                $supplier_order_ids_placeholder = str_repeat('?,', count($supplier_order_ids) - 1) . '?';
                
                // Delete related deliveries
                $query_supplier_deliveries = "DELETE FROM deliveries WHERE order_id IN ($supplier_order_ids_placeholder)";
                $stmt_supplier_deliveries = $this->conn->prepare($query_supplier_deliveries);
                $stmt_supplier_deliveries->execute($supplier_order_ids);
                
                // Delete related payments
                $query_supplier_payments = "DELETE FROM payments WHERE order_id IN ($supplier_order_ids_placeholder)";
                $stmt_supplier_payments = $this->conn->prepare($query_supplier_payments);
                $stmt_supplier_payments->execute($supplier_order_ids);
            }
            
            // Delete orders related to this supplier
            $query_supplier_orders = "DELETE FROM orders WHERE supplier_id = :supplier_id";
            $stmt_supplier_orders = $this->conn->prepare($query_supplier_orders);
            $stmt_supplier_orders->bindParam(':supplier_id', $this->id);
            $stmt_supplier_orders->execute();
            
            // Finally, delete the supplier
            $query = "DELETE FROM " . $this->table_name . " WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $this->id);
            $result = $stmt->execute();
            
            // Commit transaction
            $this->conn->commit();
            
            return $result;
            
        } catch (Exception $e) {
            // Rollback transaction on error
            if ($this->conn->inTransaction()) {
                $this->conn->rollback();
            }
            throw $e;
        }
    }

    // Read all suppliers
    public function readAll() {
        $query = "SELECT * FROM " . $this->table_name . " ORDER BY name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt;
    }

    // Read one supplier by id
    public function readOne() {
        $query = "SELECT * FROM " . $this->table_name . " WHERE id = :id LIMIT 0,1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $this->id);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $this->name = $row['name'];
            $this->contact_phone = $row['contact_phone'];
            $this->email = $row['email'];
            $this->address = $row['address'];
            $this->status = $row['status'];
            $this->payment_methods = isset($row['payment_methods']) ? $row['payment_methods'] : '';
            return true;
        }
        return false;
    }

    // Count suppliers
    public function countAll() {
        $query = "SELECT COUNT(*) as total FROM " . $this->table_name;
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['total'];
    }

    // Get supplier count (alias for countAll to maintain consistency with other models)
    public function getCount() {
        return $this->countAll();
    }

    // Get active suppliers
    public function getActiveSuppliers() {
        $query = "SELECT * FROM " . $this->table_name . " WHERE status = 'active' ORDER BY name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt;
    }
}
?>
