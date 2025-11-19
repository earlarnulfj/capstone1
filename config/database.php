<?php
class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;
    private static $instance = null;

    // Singleton pattern for connection pooling
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Allow environment overrides; fall back to sensible defaults for XAMPP
        $this->host = getenv('DB_HOST') ?: '127.0.0.1';
        $this->db_name = getenv('DB_NAME') ?: 'inventory_db';
        $this->username = getenv('DB_USER') ?: 'root';
        $this->password = getenv('DB_PASS') ?: '';
    }

    public function getConnection() {
        if ($this->conn !== null) { return $this->conn; }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => true,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];

        $dsnWithDb = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $this->host, $this->db_name);
        try {
            // First attempt: connect directly to the target database
            $this->conn = new PDO($dsnWithDb, $this->username, $this->password, $options);
            return $this->conn;
        } catch (PDOException $e) {
            // If database is missing, try to create it automatically
            $msg = $e->getMessage();
            $code = (int)($e->errorInfo[1] ?? 0); // MySQL error code
            $unknownDb = (strpos($msg, 'Unknown database') !== false) || ($code === 1049);
            if ($unknownDb) {
                try {
                    $dsnNoDb = sprintf('mysql:host=%s;charset=utf8mb4', $this->host);
                    $serverConn = new PDO($dsnNoDb, $this->username, $this->password, $options);
                    $sql = sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $this->db_name);
                    $serverConn->exec($sql);
                    // Retry connecting to the newly created DB
                    $this->conn = new PDO($dsnWithDb, $this->username, $this->password, $options);
                    return $this->conn;
                } catch (PDOException $e2) {
                    error_log('Database bootstrap error: ' . $e2->getMessage());
                    throw new Exception('Database connection failed (bootstrap). Please verify MySQL is running and credentials are correct.');
                }
            }
            // Other connection errors: surface a clearer message
            error_log('Database connection error: ' . $msg);
            throw new Exception('Database connection failed. Please verify MySQL is running and credentials are correct.');
        }
    }

    // Method to close connection explicitly if needed
    public function closeConnection() {
        $this->conn = null;
    }
}
?>
