<?php
class Notification {
    private $conn;
    private $table_name = "notifications";

    public $id;
    public $type;
    public $channel;
    public $recipient_type;
    public $recipient_id;
    public $order_id;
    public $alert_id;
    public $message;
    public $sent_at;
    public $status;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Create new notification
    public function create() {
        $query = "INSERT INTO " . $this->table_name . " 
                  SET type = :type, 
                      channel = :channel, 
                      recipient_type = :recipient_type, 
                      recipient_id = :recipient_id, 
                      order_id = :order_id, 
                      alert_id = :alert_id, 
                      message = :message, 
                      status = :status";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $this->type = htmlspecialchars(strip_tags($this->type));
        $this->channel = htmlspecialchars(strip_tags($this->channel));
        $this->recipient_type = htmlspecialchars(strip_tags($this->recipient_type));
        $this->recipient_id = htmlspecialchars(strip_tags($this->recipient_id));
        $this->order_id = $this->order_id ? htmlspecialchars(strip_tags($this->order_id)) : null;
        $this->alert_id = $this->alert_id ? htmlspecialchars(strip_tags($this->alert_id)) : null;
        $this->message = htmlspecialchars(strip_tags($this->message));
        $this->status = htmlspecialchars(strip_tags($this->status));

        // Bind values
        $stmt->bindParam(":type", $this->type);
        $stmt->bindParam(":channel", $this->channel);
        $stmt->bindParam(":recipient_type", $this->recipient_type);
        $stmt->bindParam(":recipient_id", $this->recipient_id);
        $stmt->bindParam(":order_id", $this->order_id);
        $stmt->bindParam(":alert_id", $this->alert_id);
        $stmt->bindParam(":message", $this->message);
        $stmt->bindParam(":status", $this->status);

        // Execute query
        if ($stmt->execute()) {
            return $this->conn->lastInsertId();
        }

        return false;
    }

    // Read all notifications
    public function readAll() {
        $query = "SELECT * FROM " . $this->table_name . " ORDER BY sent_at DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        return $stmt;
    }

    // Read one notification
    public function readOne() {
        $query = "SELECT * FROM " . $this->table_name . " WHERE id = :id LIMIT 0,1";

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
            $this->type = $row['type'];
            $this->channel = $row['channel'];
            $this->recipient_type = $row['recipient_type'];
            $this->recipient_id = $row['recipient_id'];
            $this->order_id = $row['order_id'];
            $this->alert_id = $row['alert_id'];
            $this->message = $row['message'];
            $this->sent_at = $row['sent_at'];
            $this->status = $row['status'];
            
            return true;
        }

        return false;
    }

    // Update notification status
    public function updateStatus($id, $status) {
        $query = "UPDATE " . $this->table_name . " SET status = :status WHERE id = :id";

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

    // Get recent notifications
    public function getRecentNotifications($limit = 5) {
        $query = "SELECT * FROM " . $this->table_name . " ORDER BY sent_at DESC LIMIT :limit";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":limit", $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt;
    }

    // Get notifications by recipient
    public function getNotificationsByRecipient($recipient_type, $recipient_id, $limit = null, $offset = null) {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE recipient_type = :recipient_type 
                  AND recipient_id = :recipient_id 
                  ORDER BY is_read ASC, sent_at DESC";

        // Add pagination if provided
        if ($limit !== null) {
            $query .= " LIMIT :limit";
            if ($offset !== null) {
                $query .= " OFFSET :offset";
            }
        }

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $recipient_type = htmlspecialchars(strip_tags($recipient_type));
        $recipient_id = htmlspecialchars(strip_tags($recipient_id));

        // Bind values
        $stmt->bindParam(":recipient_type", $recipient_type);
        $stmt->bindParam(":recipient_id", $recipient_id);
        
        // Bind pagination parameters if provided
        if ($limit !== null) {
            $stmt->bindParam(":limit", $limit, PDO::PARAM_INT);
            if ($offset !== null) {
                $stmt->bindParam(":offset", $offset, PDO::PARAM_INT);
            }
        }

        // Execute query
        $stmt->execute();

        return $stmt;
    }

    // Send SMS notification (mock function)
    public function sendSMS($phone, $message) {
        // In a real application, you would integrate with an SMS API
        // For this demo, we'll just return true
        return true;
    }

    // Send email notification (mock function)
    public function sendEmail($email, $subject, $message) {
        // In a real application, you would use PHPMailer or similar
        // For this demo, we'll just return true
        return true;
    }

    // Create and send low stock notification with duplicate prevention
    public function createLowStockNotification($inventory_id, $inventory_name, $current_quantity, $threshold, $alert_id = null, $prevent_duplicates = true) {
        // Check for existing alert_id in alert_logs table if not provided
        if ($alert_id === null) {
            $alertQuery = "SELECT id FROM alert_logs WHERE inventory_id = :inventory_id AND alert_type IN ('low_stock', 'out_of_stock') AND is_resolved = 0 ORDER BY alert_date DESC LIMIT 1";
            $alertStmt = $this->conn->prepare($alertQuery);
            $alertStmt->bindParam(':inventory_id', $inventory_id);
            $alertStmt->execute();
            if ($alertStmt->rowCount() > 0) {
                $alertRow = $alertStmt->fetch(PDO::FETCH_ASSOC);
                $alert_id = $alertRow['id'];
            }
        }
        
        // Get management users
        $query = "SELECT id, email, phone FROM users WHERE role = 'management'";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $message = "Low stock alert: {$inventory_name} is running low. Current quantity: {$current_quantity}, Threshold: {$threshold}";
            
            foreach ($users as $user) {
                // Check for duplicate notification if prevention is enabled
                if ($prevent_duplicates) {
                    // Check for existing notification with same type, recipient, and alert_id within last 30 minutes
                    $duplicateCheck = "SELECT COUNT(*) as count FROM " . $this->table_name . " 
                                      WHERE type = 'low_stock' 
                                      AND recipient_type = 'management' 
                                      AND recipient_id = :recipient_id 
                                      AND alert_id " . ($alert_id ? "= :alert_id" : "IS NULL") . "
                                      AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)";
                    $dupStmt = $this->conn->prepare($duplicateCheck);
                    $dupStmt->bindParam(':recipient_id', $user['id']);
                    if ($alert_id) {
                        $dupStmt->bindParam(':alert_id', $alert_id);
                    }
                    $dupStmt->execute();
                    $dupResult = $dupStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($dupResult['count'] > 0) {
                        // Duplicate exists, skip creation
                        continue;
                    }
                }
                
                // Create notification record
                $this->type = 'low_stock';
                $this->channel = 'sms';
                $this->recipient_type = 'management';
                $this->recipient_id = $user['id'];
                $this->order_id = null;
                $this->alert_id = $alert_id;
                $this->message = $message;
                $this->status = 'pending';
                
                $notification_id = $this->create();
                
                if ($notification_id && !empty($user['phone'])) {
                    // Send SMS
                    if ($this->sendSMS($user['phone'], $message)) {
                        $this->updateStatus($notification_id, 'sent');
                    } else {
                        $this->updateStatus($notification_id, 'failed');
                    }
                }
            }
            
            return true;
        }
        
        return false;
    }

    // Create and send order confirmation notification
    public function createOrderNotification($order_id, $supplier_id, $item_name, $quantity, $prevent_duplicates = true) {
        // Get supplier details
        $query = "SELECT name, email, contact_phone FROM suppliers WHERE id = :supplier_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":supplier_id", $supplier_id);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
            $message = "New order #{$order_id}: {$quantity} units of {$item_name} requested from {$supplier['name']}";
            
            // Set notification properties
            $this->type = 'order_confirmation';
            $this->channel = 'sms';
            $this->recipient_type = 'supplier';
            $this->recipient_id = $supplier_id;
            $this->order_id = $order_id;
            $this->alert_id = null;
            $this->message = $message;
            $this->status = 'pending';
            
            // Use duplicate prevention logic
            $notification_id = $this->createWithDuplicateCheck($prevent_duplicates, 5);
            
            // If duplicate was prevented, return true but don't send SMS
            if ($notification_id === false && $prevent_duplicates) {
                return true; // Duplicate prevented, but operation successful
            }
            
            if ($notification_id && !empty($supplier['contact_phone'])) {
                // Send SMS to supplier
                if ($this->sendSMS($supplier['contact_phone'], $message)) {
                    $this->updateStatus($notification_id, 'sent');
                } else {
                    $this->updateStatus($notification_id, 'failed');
                }
            }
            
            return true;
        }
        
        return false;
    }
    
    // Get unread delivery notifications for admin users
    public function getUnreadDeliveryNotifications($user_id, $user_role, $limit = 20) {
        $query = "SELECT n.*, o.id as order_id, o.quantity as order_quantity, o.order_date,
                         s.name as supplier_name, i.name as item_name, i.sku as item_sku
                  FROM " . $this->table_name . " n
                  LEFT JOIN orders o ON n.order_id = o.id
                  LEFT JOIN suppliers s ON o.supplier_id = s.id
                  LEFT JOIN inventory i ON o.inventory_id = i.id
                  WHERE n.recipient_type = :user_role 
                  AND n.status = 'sent'
                  AND n.type IN ('delivery_arrival', 'delivery_confirmation', 'delivery_status_update', 'inventory_update')
                  ORDER BY n.sent_at DESC
                  LIMIT :limit";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_role', $user_role);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get delivery notification statistics for admin dashboard
    public function getDeliveryNotificationStats($user_id, $user_role) {
        $query = "SELECT 
                    COUNT(*) as total_notifications,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as unread_count,
                    SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read_count,
                    SUM(CASE WHEN type = 'delivery_arrival' AND status = 'sent' THEN 1 ELSE 0 END) as unread_arrivals,
                    SUM(CASE WHEN type = 'delivery_confirmation' AND status = 'sent' THEN 1 ELSE 0 END) as unread_confirmations,
                    SUM(CASE WHEN type = 'inventory_update' AND status = 'sent' THEN 1 ELSE 0 END) as unread_inventory_updates,
                    MAX(sent_at) as last_notification_time
                  FROM " . $this->table_name . "
                  WHERE recipient_type = :user_role 
                  AND type IN ('delivery_arrival', 'delivery_confirmation', 'delivery_status_update', 'inventory_update')
                  AND sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_role', $user_role);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Mark delivery notification as read for admin users
    public function markDeliveryNotificationAsRead($notification_id, $user_id) {
        $query = "UPDATE " . $this->table_name . " 
                  SET status = 'read', 
                      read_at = NOW() 
                  WHERE id = :notification_id 
                  AND status = 'sent'";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':notification_id', $notification_id);
        
        if ($stmt->execute()) {
            return $stmt->rowCount() > 0;
        }
        
        return false;
    }
    
    // Get all delivery notifications with pagination for admin management
    public function getAllDeliveryNotifications($user_role, $page = 1, $per_page = 50, $filter_status = null, $filter_type = null) {
        $offset = ($page - 1) * $per_page;
        
        $where_clause = "WHERE n.recipient_type = :user_role 
                         AND n.type IN ('delivery_arrival', 'delivery_confirmation', 'delivery_status_update', 'inventory_update', 'order_confirmation', 'supplier_message')";
        
        if ($filter_status) {
            $where_clause .= " AND n.status = :filter_status";
        }
        
        if ($filter_type) {
            $where_clause .= " AND n.type = :filter_type";
        }
        
        $query = "SELECT n.*, o.id as order_id, o.quantity as order_quantity, o.order_date,
                         s.name as supplier_name, i.name as item_name, i.sku as item_sku,
                         d.status as delivery_status, d.delivery_date
                  FROM " . $this->table_name . " n
                  LEFT JOIN orders o ON n.order_id = o.id
                  LEFT JOIN suppliers s ON o.supplier_id = s.id
                  LEFT JOIN inventory i ON o.inventory_id = i.id
                  LEFT JOIN deliveries d ON o.id = d.order_id
                  " . $where_clause . "
                  ORDER BY n.sent_at DESC
                  LIMIT :limit OFFSET :offset";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_role', $user_role);
        if ($filter_status) {
            $stmt->bindParam(':filter_status', $filter_status);
        }
        if ($filter_type) {
            $stmt->bindParam(':filter_type', $filter_type);
        }
        $stmt->bindParam(':limit', $per_page, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get count of delivery notifications for pagination
    public function getDeliveryNotificationCount($user_role, $filter_status = null, $filter_type = null) {
        $where_clause = "WHERE recipient_type = :user_role 
                         AND type IN ('delivery_arrival', 'delivery_confirmation', 'delivery_status_update', 'inventory_update', 'order_confirmation', 'supplier_message')";
        
        if ($filter_status) {
            $where_clause .= " AND status = :filter_status";
        }
        
        if ($filter_type) {
            $where_clause .= " AND type = :filter_type";
        }
        
        $query = "SELECT COUNT(*) as total FROM " . $this->table_name . " " . $where_clause;
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_role', $user_role);
        if ($filter_status) {
            $stmt->bindParam(':filter_status', $filter_status);
        }
        if ($filter_type) {
            $stmt->bindParam(':filter_type', $filter_type);
        }
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['total'];
    }
    
    // Get supplier-specific notifications for admin dashboard
    public function getSupplierNotifications($user_role, $limit = 10) {
        $query = "SELECT n.*, s.name as supplier_name
                  FROM " . $this->table_name . " n
                  LEFT JOIN suppliers s ON n.message LIKE CONCAT('%[', s.name, ']%')
                  WHERE n.recipient_type = :user_role 
                  AND n.type IN ('order_confirmation', 'supplier_message', 'delivery_status_update')
                  ORDER BY n.sent_at DESC
                  LIMIT :limit";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_role', $user_role);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get supplier notification statistics
    public function getSupplierNotificationStats($user_role) {
        $query = "SELECT 
                    COUNT(*) as total_supplier_notifications,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as unread_supplier_notifications,
                    SUM(CASE WHEN type = 'order_confirmation' AND status = 'sent' THEN 1 ELSE 0 END) as unread_confirmations,
                    SUM(CASE WHEN type = 'supplier_message' AND status = 'sent' THEN 1 ELSE 0 END) as unread_messages
                  FROM " . $this->table_name . "
                  WHERE recipient_type = :user_role 
                  AND type IN ('order_confirmation', 'supplier_message', 'delivery_status_update')
                  AND sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':user_role', $user_role);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get notification count for a specific recipient
    public function getNotificationCount($recipient_type, $recipient_id) {
        $query = "SELECT COUNT(*) as count FROM " . $this->table_name . " 
                  WHERE recipient_type = :recipient_type AND recipient_id = :recipient_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':recipient_type', $recipient_type);
        $stmt->bindParam(':recipient_id', $recipient_id, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'];
    }
    
    // Get unread notification count for a specific recipient
    public function getUnreadCount($recipient_type, $recipient_id) {
        // Count notifications that are unread (is_read = 0 or NULL, and status != 'read')
        $query = "SELECT COUNT(*) as count FROM " . $this->table_name . " 
                  WHERE recipient_type = :recipient_type 
                    AND recipient_id = :recipient_id 
                    AND (is_read = 0 OR is_read IS NULL)
                    AND (status IS NULL OR status != 'read')";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':recipient_type', $recipient_type);
        $stmt->bindParam(':recipient_id', $recipient_id, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['count'] ?? 0);
    }
    
    // Get recent notification count (last 24 hours)
    public function getRecentNotificationCount($recipient_type, $recipient_id) {
        $query = "SELECT COUNT(*) as count FROM " . $this->table_name . " 
                  WHERE recipient_type = :recipient_type AND recipient_id = :recipient_id 
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':recipient_type', $recipient_type);
        $stmt->bindParam(':recipient_id', $recipient_id, PDO::PARAM_INT);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'];
    }
    
    // Mark a notification as read
    public function markAsRead($notification_id) {
        $query = "UPDATE " . $this->table_name . " 
                  SET is_read = 1, read_at = NOW(), status = 'read' 
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $notification_id, PDO::PARAM_INT);
        
        return $stmt->execute();
    }
    
    // Mark all notifications as read for a recipient
    public function markAllAsRead($recipient_type, $recipient_id) {
        $query = "UPDATE " . $this->table_name . " 
                  SET is_read = 1, read_at = NOW(), status = 'read' 
                  WHERE recipient_type = :recipient_type AND recipient_id = :recipient_id AND is_read = 0";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':recipient_type', $recipient_type);
        $stmt->bindParam(':recipient_id', $recipient_id, PDO::PARAM_INT);
        
        return $stmt->execute();
    }
    
    // Delete a notification
    public function deleteNotification($notification_id) {
        $query = "DELETE FROM " . $this->table_name . " WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $notification_id, PDO::PARAM_INT);
        
        return $stmt->execute();
    }
    
    // Delete all notifications for a specific recipient
    public function deleteAllNotificationsByRecipient($recipient_type, $recipient_id) {
        $query = "DELETE FROM " . $this->table_name . " WHERE recipient_type = :recipient_type AND recipient_id = :recipient_id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':recipient_type', $recipient_type);
        $stmt->bindParam(':recipient_id', $recipient_id, PDO::PARAM_INT);
        
        return $stmt->execute();
    }
    
    // Check if a notification already exists to prevent duplicates
    public function notificationExists($type, $recipient_type, $recipient_id, $order_id = null, $time_window_minutes = 5) {
        $query = "SELECT COUNT(*) as count FROM " . $this->table_name . " 
                  WHERE type = :type 
                  AND recipient_type = :recipient_type 
                  AND recipient_id = :recipient_id";
        
        $params = [
            ':type' => $type,
            ':recipient_type' => $recipient_type,
            ':recipient_id' => $recipient_id
        ];
        
        // Add order_id condition if provided
        if ($order_id !== null) {
            $query .= " AND order_id = :order_id";
            $params[':order_id'] = $order_id;
        }
        
        // Add time window to prevent duplicates within a specific timeframe
        $query .= " AND created_at >= DATE_SUB(NOW(), INTERVAL :time_window MINUTE)";
        $params[':time_window'] = $time_window_minutes;
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    }
    
    // Create notification with duplicate prevention
    public function createWithDuplicateCheck($prevent_duplicates = true, $time_window_minutes = 5) {
        // Check for duplicates if prevention is enabled
        if ($prevent_duplicates) {
            if ($this->notificationExists($this->type, $this->recipient_type, $this->recipient_id, $this->order_id, $time_window_minutes)) {
                // Return false to indicate duplicate was prevented
                return false;
            }
        }
        
        // Create the notification if no duplicate found
        return $this->create();
    }
    
    // Get recent notifications for the same order to check for duplicates
    public function getRecentOrderNotifications($order_id, $type = null, $minutes = 5) {
        $query = "SELECT * FROM " . $this->table_name . " 
                  WHERE order_id = :order_id 
                  AND created_at >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)";
        
        $params = [
            ':order_id' => $order_id,
            ':minutes' => $minutes
        ];
        
        if ($type !== null) {
            $query .= " AND type = :type";
            $params[':type'] = $type;
        }
        
        $query .= " ORDER BY created_at DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
