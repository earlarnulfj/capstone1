<?php
/**
 * Optional separate POS database configuration.
 * If you need selective synchronization using a distinct DB instance,
 * update the DSN, username, and password below.
 * By default, this falls back to the main Database config.
 */
class DatabasePOS {
    private $conn;

    public function getConnection() {
        if ($this->conn) return $this->conn;
        // Fallback to main Database if no separate POS DB is configured
        require_once __DIR__ . '/database.php';
        $db = new Database();
        $this->conn = $db->getConnection();
        return $this->conn;
    }
}