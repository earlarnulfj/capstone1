<?php
class User {
    private $conn;
    private $table_name = "users";

    public $id;
    public $username;
    public $password_hash;
    public $role;
    public $email;
    public $phone;
    public $created_at;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Create new user
    public function create($username, $password, $role, $email, $phone = null, $first_name = null, $middle_name = null, $last_name = null, $address = null, $city = null, $province = null, $postal_code = null, $profile_picture = null) {
        try {
            // Start transaction
            $this->conn->beginTransaction();
            
            $query = "INSERT INTO " . $this->table_name . " 
                      SET username = :username, 
                          password_hash = :password_hash, 
                          role = :role, 
                          email = :email, 
                          phone = :phone,
                          first_name = :first_name,
                          middle_name = :middle_name,
                          last_name = :last_name,
                          address = :address,
                          city = :city,
                          province = :province,
                          postal_code = :postal_code,
                          profile_picture = :profile_picture";

            $stmt = $this->conn->prepare($query);

            // Sanitize and hash
            $this->username = htmlspecialchars(strip_tags($username));
            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $this->role = htmlspecialchars(strip_tags($role));
            $this->email = htmlspecialchars(strip_tags($email));
            $this->phone = htmlspecialchars(strip_tags($phone));
            $this->first_name = htmlspecialchars(strip_tags($first_name));
            $this->middle_name = htmlspecialchars(strip_tags($middle_name));
            $this->last_name = htmlspecialchars(strip_tags($last_name));
            $this->address = htmlspecialchars(strip_tags($address));
            $this->city = htmlspecialchars(strip_tags($city));
            $this->province = htmlspecialchars(strip_tags($province));
            $this->postal_code = htmlspecialchars(strip_tags($postal_code));
            $this->profile_picture = htmlspecialchars(strip_tags($profile_picture));

            // Bind values
            $stmt->bindParam(":username", $this->username);
            $stmt->bindParam(":password_hash", $password_hash);
            $stmt->bindParam(":role", $this->role);
            $stmt->bindParam(":email", $this->email);
            $stmt->bindParam(":phone", $this->phone);
            $stmt->bindParam(":first_name", $this->first_name);
            $stmt->bindParam(":middle_name", $this->middle_name);
            $stmt->bindParam(":last_name", $this->last_name);
            $stmt->bindParam(":address", $this->address);
            $stmt->bindParam(":city", $this->city);
            $stmt->bindParam(":province", $this->province);
            $stmt->bindParam(":postal_code", $this->postal_code);
            $stmt->bindParam(":profile_picture", $this->profile_picture);

            // Execute user creation query
            if (!$stmt->execute()) {
                $errorInfo = $stmt->errorInfo();
                $errorCode = $errorInfo[0] ?? '';
                $errorMessage = $errorInfo[2] ?? 'Unknown error';
                error_log("User creation SQL error: " . print_r($errorInfo, true));
                $this->conn->rollBack();
                
                // Check for duplicate entry
                if ($errorCode == '23000' || strpos($errorMessage, '1062') !== false || strpos($errorMessage, 'Duplicate entry') !== false) {
                    if (strpos($errorMessage, 'username') !== false) {
                        throw new Exception("Username already exists. Please choose a different username.");
                    } elseif (strpos($errorMessage, 'email') !== false) {
                        throw new Exception("Email address already exists. Please use a different email or try logging in instead.");
                    } else {
                        throw new Exception("A record with this information already exists. Please check your username and email.");
                    }
                }
                
                // Missing column error
                if (strpos($errorMessage, '1054') !== false || strpos($errorMessage, 'Unknown column') !== false) {
                    throw new Exception("Database schema error: Missing required columns. Please run database/add_user_profile_fields.sql or visit database/fix_now.php");
                }
                
                throw new Exception("Database error: " . $errorMessage);
            }

            // If role is supplier, create corresponding supplier record
            if ($role === 'supplier') {
                $supplierQuery = "INSERT INTO suppliers 
                                  SET name = :name,
                                      contact_phone = :contact_phone,
                                      email = :email,
                                      username = :username,
                                      password_hash = :password_hash,
                                      address = :address,
                                      city = :city,
                                      province = :province,
                                      postal_code = :postal_code,
                                      status = 'active'";
                
                $supplierStmt = $this->conn->prepare($supplierQuery);
                
                // Build full name from first, middle, last name
                $supplierName = trim(($this->first_name ?? '') . ' ' . ($this->middle_name ?? '') . ' ' . ($this->last_name ?? ''));
                if (empty($supplierName)) {
                    $supplierName = $this->username; // Fallback to username
                }
                
                $supplierStmt->bindParam(":name", $supplierName);
                $supplierStmt->bindParam(":contact_phone", $this->phone);
                $supplierStmt->bindParam(":email", $this->email);
                $supplierStmt->bindParam(":username", $this->username);
                $supplierStmt->bindParam(":password_hash", $password_hash);
                $supplierStmt->bindParam(":address", $this->address);
                $supplierStmt->bindParam(":city", $this->city);
                $supplierStmt->bindParam(":province", $this->province);
                $supplierStmt->bindParam(":postal_code", $this->postal_code);
                
                if (!$supplierStmt->execute()) {
                    $errorInfo = $supplierStmt->errorInfo();
                    $errorCode = $errorInfo[0] ?? '';
                    $errorMessage = $errorInfo[2] ?? 'Unknown error';
                    error_log("Supplier creation SQL error: " . print_r($errorInfo, true));
                    $this->conn->rollBack();
                    
                    // Check for duplicate entry in suppliers table
                    if ($errorCode == '23000' || strpos($errorMessage, '1062') !== false || strpos($errorMessage, 'Duplicate entry') !== false) {
                        if (strpos($errorMessage, 'username') !== false) {
                            throw new Exception("Username already exists. Please choose a different username.");
                        } elseif (strpos($errorMessage, 'email') !== false) {
                            throw new Exception("Email address already exists. Please use a different email or try logging in instead.");
                        } else {
                            throw new Exception("A supplier record with this information already exists. Please check your username and email.");
                        }
                    }
                    
                    // Missing column error
                    if (strpos($errorMessage, '1054') !== false || strpos($errorMessage, 'Unknown column') !== false) {
                        throw new Exception("Database schema error: Missing required columns in suppliers table. Please check if suppliers table has city, province, postal_code columns.");
                    }
                    
                    throw new Exception("Supplier creation error: " . $errorMessage);
                }
            }
            
            // Commit transaction
            $this->conn->commit();
            return true;
            
        } catch (PDOException $e) {
            // Rollback transaction on error
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            // Log the error for debugging
            error_log("User creation PDO error: " . $e->getMessage());
            error_log("SQL State: " . $e->getCode());
            
            // Check for specific error types
            $errorCode = $e->getCode();
            $errorMessage = $e->getMessage();
            
            // Duplicate entry error (1062 for username/email)
            if ($errorCode == 23000 || strpos($errorMessage, '1062') !== false || strpos($errorMessage, 'Duplicate entry') !== false) {
                if (strpos($errorMessage, 'username') !== false) {
                    throw new Exception("Username already exists. Please choose a different username.");
                } elseif (strpos($errorMessage, 'email') !== false) {
                    throw new Exception("Email address already exists. Please use a different email or try logging in instead.");
                } else {
                    throw new Exception("A record with this information already exists. Please check your username and email.");
                }
            }
            
            // Missing column error (1054)
            if (strpos($errorMessage, '1054') !== false || strpos($errorMessage, 'Unknown column') !== false) {
                throw new Exception("Database schema error: Missing required columns. Please run database/add_user_profile_fields.sql or visit database/fix_now.php");
            }
            
            // Generic database error
            throw new Exception("Database error: " . $errorMessage);
        } catch (Exception $e) {
            // Rollback transaction on error
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            // Log the error for debugging
            error_log("User creation error: " . $e->getMessage());
            // Re-throw so caller can see the actual error
            throw $e;
        }
    }

    // Default login (kept for compatibility)
    public function login($username, $password) {
        $query = "SELECT id, username, password_hash, role, email 
                  FROM " . $this->table_name . " 
                  WHERE username = :username 
                  LIMIT 0,1";

        $stmt = $this->conn->prepare($query);

        // Sanitize
        $this->username = htmlspecialchars(strip_tags($username));

        // Bind value
        $stmt->bindParam(":username", $this->username);

        // Execute query
        $stmt->execute();

        // Check if user exists
        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Verify password
            if (password_verify($password, $row['password_hash'])) {
                // Set session variables
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['username'] = $row['username'];
                $_SESSION['role'] = $row['role'];
                $_SESSION['email'] = $row['email'];
                
                return true;
            }
        }

        return false;
    }

    // ✅ Enhanced login method for role-based sessions (admin, staff, supplier)
    public function loginAsRole($username, $password, $targetRole) {
        if (!in_array($targetRole, ['admin', 'staff', 'supplier'], true)) return false;

        $safeUsername = htmlspecialchars(strip_tags($username));
        
        // Handle supplier login differently
        if ($targetRole === 'supplier') {
            return $this->loginSupplier($safeUsername, $password);
        }

        $query = "SELECT id, username, password_hash, role, email
                  FROM " . $this->table_name . "
                  WHERE username = :username
                  LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":username", $safeUsername);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (password_verify($password, $row['password_hash'])) {
                $dbRole = $row['role']; // 'management' or 'staff'
                $isTargetAdmin = ($targetRole === 'admin');
                $isDbAdmin     = ($dbRole === 'management');

                if (($isTargetAdmin && $isDbAdmin) || (!$isTargetAdmin && !$isDbAdmin)) {
                    if (session_status() === PHP_SESSION_NONE) session_start();
                    $_SESSION[$targetRole] = [
                        'user_id'  => $row['id'],
                        'username' => $row['username'],
                        'email'    => $row['email'],
                        'role'     => $dbRole,
                    ];
                    
                    // Log successful login
                    $this->logAuthAttempt($targetRole, $row['id'], $row['username'], 'login_success', 'Successful login');
                    return true;
                } else {
                    // Log failed login due to role mismatch
                    $this->logAuthAttempt($targetRole, $row['id'], $row['username'], 'login_failed', 'Role mismatch');
                }
            } else {
                // Log failed login due to wrong password
                $this->logAuthAttempt($targetRole, $row['id'], $row['username'], 'login_failed', 'Invalid password');
            }
        } else {
            // Log failed login due to user not found
            $this->logAuthAttempt($targetRole, null, $safeUsername, 'login_failed', 'User not found');
        }
        return false;
    }

    /**
     * Login using email + password only. Auto-detects role and sets namespaced sessions.
     * Also enforces basic account lockout using auth_logs for users, and built-in lock
     * fields for suppliers. Returns an associative array with success, role, and redirect.
     */
    public function loginByEmail(string $email, string $password): array {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'invalid_email'];
        }

        // 1) Try normal users table first
        $q = "SELECT id, username, password_hash, role, email FROM users WHERE email = :email LIMIT 1";
        $stmt = $this->conn->prepare($q);
        $stmt->bindParam(':email', $email);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            // Check recent failed attempts (lockout): 5 or more in last 15 minutes
            try {
                $lockStmt = $this->conn->prepare(
                    "SELECT COUNT(*) FROM auth_logs WHERE user_id = :uid AND action = 'login_failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
                );
                $lockStmt->bindParam(':uid', $row['id']);
                $lockStmt->execute();
                $failedCount = (int)$lockStmt->fetchColumn();
                if ($failedCount >= 5) {
                    // Soft lockout
                    $this->logAuthAttempt($this->mapRoleToUserType($row['role']), $row['id'], $row['username'], 'login_failed', 'Account temporarily locked');
                    return ['success' => false, 'error' => 'locked'];
                }
            } catch (Exception $e) {
                // Ignore lock check errors; proceed without blocking
                error_log('Lockout check failed: ' . $e->getMessage());
            }

            if (!password_verify($password, $row['password_hash'])) {
                $this->logAuthAttempt($this->mapRoleToUserType($row['role']), $row['id'], $row['username'], 'login_failed', 'Invalid password');
                return ['success' => false, 'error' => 'invalid_password'];
            }

            // Success: set namespaced session according to role
            if (session_status() === PHP_SESSION_NONE) session_start();
            $role = $row['role']; // 'management' or 'staff' or possibly 'supplier'

            if ($role === 'management') {
                $_SESSION['admin'] = [
                    'user_id'  => $row['id'],
                    'username' => $row['username'],
                    'email'    => $row['email'],
                    'role'     => 'management',
                ];
                $this->logAuthAttempt('admin', $row['id'], $row['username'], 'login_success', 'Successful login');
                return ['success' => true, 'role' => 'admin', 'redirect' => 'admin/dashboard.php'];
            } elseif ($role === 'staff') {
                $_SESSION['staff'] = [
                    'user_id'  => $row['id'],
                    'username' => $row['username'],
                    'email'    => $row['email'],
                    'role'     => 'staff',
                ];
                $this->logAuthAttempt('staff', $row['id'], $row['username'], 'login_success', 'Successful login');
                return [
                    'success' => true, 
                    'role' => 'staff', 
                    'redirect' => 'staff/pos.php',
                    'user_id' => $row['id'],
                    'username' => $row['username'],
                    'email' => $row['email']
                ];
            } elseif ($role === 'supplier') {
                // Users table has a supplier role; map to suppliers.id for consistency
                $supplierId = $this->findSupplierIdByUsername($row['username']);
                $effectiveSupplierId = $supplierId ?? $row['id'];

                // Set supplier session using suppliers.id when available
                $_SESSION['supplier'] = [
                    'user_id'  => $effectiveSupplierId,
                    'username' => $row['username'],
                    'name'     => $row['username'],
                    'role'     => 'supplier',
                ];
                // Legacy expectations (use suppliers.id to satisfy FKs referencing suppliers)
                $_SESSION['user_id'] = $effectiveSupplierId;
                $_SESSION['username'] = $row['username'];
                $_SESSION['role'] = 'supplier';
                $this->logAuthAttempt('supplier', $effectiveSupplierId, $row['username'], 'login_success', 'Successful login');
                return ['success' => true, 'role' => 'supplier', 'redirect' => 'supplier/dashboard.php'];
            }

            // Unknown role; treat as failure for safety
            return ['success' => false, 'error' => 'role_unknown'];
        }

        // 2) Try suppliers table by email
        $supplierLogin = $this->loginSupplierByEmail($email, $password);
        if ($supplierLogin['success'] ?? false) {
            return $supplierLogin;
        }

        // Not found anywhere
        return ['success' => false, 'error' => 'not_found'];
    }

    /**
     * Supplier login using email instead of username. Preserves existing lockout fields.
     */
    public function loginSupplierByEmail(string $email, string $password): array {
        $email = trim($email);
        $lockQuery = "SELECT id, name, username, email, password_hash, status, login_attempts, locked_until 
                      FROM suppliers 
                      WHERE email = :email AND status = 'active' 
                      LIMIT 1";
        $stmt = $this->conn->prepare($lockQuery);
        $stmt->bindParam(':email', $email);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            // Do not reveal existence
            $this->logAuthAttempt('supplier', null, $email, 'login_failed', 'Account not found or inactive');
            return ['success' => false, 'error' => 'not_found'];
        }

        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

        // Check lockout
        if ($supplier['locked_until'] && new DateTime() < new DateTime($supplier['locked_until'])) {
            $this->logAuthAttempt('supplier', $supplier['id'], $supplier['username'], 'login_failed', 'Account locked');
            return ['success' => false, 'error' => 'locked'];
        }

        if (!password_verify($password, $supplier['password_hash'])) {
            $this->incrementLoginAttempts($supplier['id']);
            $this->logAuthAttempt('supplier', $supplier['id'], $supplier['username'], 'login_failed', 'Invalid password');
            return ['success' => false, 'error' => 'invalid_password'];
        }

        // Success: reset attempts, update last login
        $this->resetLoginAttempts($supplier['id']);
        $this->updateLastLogin($supplier['id']);

        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['supplier'] = [
            'user_id'  => $supplier['id'],
            'username' => $supplier['username'],
            'name'     => $supplier['name'],
            'role'     => 'supplier',
        ];
        // Legacy expectations for existing supplier pages
        $_SESSION['user_id'] = $supplier['id'];
        $_SESSION['username'] = $supplier['username'];
        $_SESSION['role'] = 'supplier';

        $this->logAuthAttempt('supplier', $supplier['id'], $supplier['username'], 'login_success', 'Successful login');
        return ['success' => true, 'role' => 'supplier', 'redirect' => 'supplier/dashboard.php'];
    }

    private function mapRoleToUserType(string $role): string {
        return $role === 'management' ? 'admin' : ($role === 'staff' ? 'staff' : 'supplier');
    }

    // Check if username exists
    public function usernameExists($username) {
        $query = "SELECT id FROM " . $this->table_name . " WHERE username = :username LIMIT 0,1";

        $stmt = $this->conn->prepare($query);
        $this->username = htmlspecialchars(strip_tags($username));
        $stmt->bindParam(":username", $this->username);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    // Check if email exists
    public function emailExists($email) {
        $query = "SELECT id FROM " . $this->table_name . " WHERE email = :email LIMIT 0,1";

        $stmt = $this->conn->prepare($query);
        $this->email = htmlspecialchars(strip_tags($email));
        $stmt->bindParam(":email", $this->email);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    // Get user by ID
    public function readOne($id) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE id = :id LIMIT 0,1";
        $stmt = $this->conn->prepare($query);
        $id = htmlspecialchars(strip_tags($id));
        $stmt->bindParam(":id", $id);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $this->id = $row['id'];
            $this->username = $row['username'];
            $this->role = $row['role'];
            $this->email = $row['email'];
            $this->phone = $row['phone'];
            $this->created_at = $row['created_at'];
            
            return true;
        }
        return false;
    }

    // Update user
    public function update() {
        $query = "UPDATE " . $this->table_name . " 
                  SET username = :username, 
                      role = :role, 
                      email = :email, 
                      phone = :phone 
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);
        $this->username = htmlspecialchars(strip_tags($this->username));
        $this->role = htmlspecialchars(strip_tags($this->role));
        $this->email = htmlspecialchars(strip_tags($this->email));
        $this->phone = htmlspecialchars(strip_tags($this->phone));
        $this->id = htmlspecialchars(strip_tags($this->id));

        $stmt->bindParam(":username", $this->username);
        $stmt->bindParam(":role", $this->role);
        $stmt->bindParam(":email", $this->email);
        $stmt->bindParam(":phone", $this->phone);
        $stmt->bindParam(":id", $this->id);

        return $stmt->execute();
    }

    // Change password
    public function changePassword($id, $new_password) {
        $query = "UPDATE " . $this->table_name . " 
                  SET password_hash = :password_hash 
                  WHERE id = :id";

        $stmt = $this->conn->prepare($query);
        $id = htmlspecialchars(strip_tags($id));
        $password_hash = password_hash($new_password, PASSWORD_BCRYPT);

        $stmt->bindParam(":password_hash", $password_hash);
        $stmt->bindParam(":id", $id);

        return $stmt->execute();
    }

    // Get all users
    public function readAll() {
        $query = "SELECT * FROM " . $this->table_name . " ORDER BY username";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt;
    }

    // Delete user
    public function delete($id) {
        $query = "DELETE FROM " . $this->table_name . " WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $id = htmlspecialchars(strip_tags($id));
        $stmt->bindParam(":id", $id);
        try {
            return $stmt->execute();
        } catch (PDOException $e) {
            // Gracefully handle FK constraint violations (e.g., orders referencing this user)
            // SQLSTATE[23000] with MySQL error 1451 indicates a foreign key restriction
            error_log("User delete failed for ID {$id}: " . $e->getMessage());
            return false;
        }
    }

    // ✅ Supplier login method with security features
    public function loginSupplier($username, $password) {
        // Check if supplier account is locked
        $lockQuery = "SELECT id, name, username, password_hash, status, login_attempts, locked_until 
                      FROM suppliers 
                      WHERE username = :username AND status = 'active'
                      LIMIT 1";
        
        $stmt = $this->conn->prepare($lockQuery);
        $stmt->bindParam(":username", $username);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            $this->logAuthAttempt('supplier', null, $username, 'login_failed', 'Account not found or inactive');
            return false;
        }

        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Check if account is locked
        if ($supplier['locked_until'] && new DateTime() < new DateTime($supplier['locked_until'])) {
            $this->logAuthAttempt('supplier', $supplier['id'], $username, 'login_failed', 'Account locked');
            return false;
        }

        // Verify password
        if (!password_verify($password, $supplier['password_hash'])) {
            $this->incrementLoginAttempts($supplier['id']);
            $this->logAuthAttempt('supplier', $supplier['id'], $username, 'login_failed', 'Invalid password');
            return false;
        }

        // Reset login attempts on successful login
        $this->resetLoginAttempts($supplier['id']);
        
        // Update last login
        $this->updateLastLogin($supplier['id']);

        // Set session
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['supplier'] = [
            'user_id'  => $supplier['id'],
            'username' => $supplier['username'],
            'name'     => $supplier['name'],
            'role'     => 'supplier',
        ];

        $this->logAuthAttempt('supplier', $supplier['id'], $username, 'login_success', 'Successful login');
        return true;
    }

    // Map a username in `users` to a supplier ID in `suppliers`
    private function findSupplierIdByUsername(string $username): ?int {
        try {
            $sql = "SELECT id FROM suppliers WHERE username = :u LIMIT 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':u', $username);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row && isset($row['id']) ? (int)$row['id'] : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ✅ Log authentication attempts for security monitoring
    public function logAuthAttempt($userType, $userId, $username, $action, $additionalInfo = null) {
        $query = "INSERT INTO auth_logs (user_type, user_id, username, action, ip_address, user_agent, session_id, additional_info)
                  VALUES (:user_type, :user_id, :username, :action, :ip_address, :user_agent, :session_id, :additional_info)";
        
        $stmt = $this->conn->prepare($query);
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $sessionId = session_id() ?: 'no_session';
        $additionalInfoJson = $additionalInfo ? json_encode(['info' => $additionalInfo]) : null;
        
        $stmt->bindParam(":user_type", $userType);
        $stmt->bindParam(":user_id", $userId);
        $stmt->bindParam(":username", $username);
        $stmt->bindParam(":action", $action);
        $stmt->bindParam(":ip_address", $ipAddress);
        $stmt->bindParam(":user_agent", $userAgent);
        $stmt->bindParam(":session_id", $sessionId);
        $stmt->bindParam(":additional_info", $additionalInfoJson);
        
        return $stmt->execute();
    }

    // ✅ Increment login attempts and lock account if necessary
    private function incrementLoginAttempts($supplierId) {
        $query = "UPDATE suppliers 
                  SET login_attempts = login_attempts + 1,
                      locked_until = CASE 
                          WHEN login_attempts >= 4 THEN DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                          ELSE locked_until 
                      END
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $supplierId);
        $stmt->execute();

        // Check if account was locked
        $checkQuery = "SELECT login_attempts FROM suppliers WHERE id = :id";
        $checkStmt = $this->conn->prepare($checkQuery);
        $checkStmt->bindParam(":id", $supplierId);
        $checkStmt->execute();
        $result = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($result['login_attempts'] >= 5) {
            $this->logAuthAttempt('supplier', $supplierId, null, 'account_locked', 'Too many failed attempts');
        }
    }

    // ✅ Reset login attempts on successful login
    private function resetLoginAttempts($supplierId) {
        $query = "UPDATE suppliers 
                  SET login_attempts = 0, locked_until = NULL 
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $supplierId);
        return $stmt->execute();
    }

    // ✅ Update last login timestamp
    private function updateLastLogin($supplierId) {
        $query = "UPDATE suppliers SET last_login = NOW() WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $supplierId);
        return $stmt->execute();
    }

    // ✅ Enhanced logout with logging
    public function logout($userType = null) {
        if (session_status() === PHP_SESSION_NONE) session_start();
        
        // Log logout for each active session
        if (isset($_SESSION['admin'])) {
            $this->logAuthAttempt('admin', $_SESSION['admin']['user_id'], $_SESSION['admin']['username'], 'logout');
        }
        if (isset($_SESSION['staff'])) {
            $this->logAuthAttempt('staff', $_SESSION['staff']['user_id'], $_SESSION['staff']['username'], 'logout');
        }
        if (isset($_SESSION['supplier'])) {
            $this->logAuthAttempt('supplier', $_SESSION['supplier']['user_id'], $_SESSION['supplier']['username'], 'logout');
        }

        // Clear all sessions
        session_unset();
        session_destroy();
        return true;
    }

    // ✅ Password Reset Functionality
    
    /**
     * Generate and send password reset verification code
     * @param string $email User's email address
     * @return array Result with success status and message
     */
    public function initiatePasswordReset($email) {
        try {
            // Check if email exists in users table
            if (!$this->emailExists($email)) {
                return [
                    'success' => false,
                    'message' => 'Email not found in our records.'
                ];
            }

            // Generate verification code and token (cryptographically secure)
            $verificationCode = sprintf('%06d', random_int(100000, 999999));
            $token = bin2hex(random_bytes(32));
            // Compute expiry in the database to avoid PHP/DB timezone mismatches

            // Basic rate limiting: throttle excessive requests per 15 minutes
            if ($this->hasTooManyResetRequests($email)) {
                // Do not disclose throttling to avoid enumeration; respond generically
                return [
                    'success' => true,
                    'message' => 'If the account exists, a verification code has been sent to the provided email.'
                ];
            }

            // Clean up old tokens for this email
            $this->cleanupOldTokens($email);

            // Insert new reset token; compute expires_at in SQL for consistent timezone
            $query = "INSERT INTO password_reset_tokens 
                      (email, token, verification_code, expires_at) 
                      VALUES (:email, :token, :verification_code, DATE_ADD(NOW(), INTERVAL 15 MINUTE))";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':token', $token);
            $stmt->bindParam(':verification_code', $verificationCode);

            if ($stmt->execute()) {
                // In a real application, you would send the verification code via email
                // For demo purposes, we'll return the code (remove this in production)
                return [
                    'success' => true,
                    'message' => 'Verification code sent to your email.',
                    'verification_code' => $verificationCode, // Remove in production
                    'token' => $token
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to generate reset code. Please try again.'
                ];
            }

        } catch (Exception $e) {
            error_log("Password reset initiation error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'An error occurred. Please try again later.'
            ];
        }
    }

    /**
     * Check for excessive password reset requests for an email within last 15 minutes
     * @param string $email
     * @return bool true if too many requests
     */
    private function hasTooManyResetRequests($email) {
        try {
            $q = "SELECT COUNT(*) AS cnt FROM password_reset_tokens 
                  WHERE email = :email AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)";
            $stmt = $this->conn->prepare($q);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            $count = (int)$stmt->fetchColumn();
            // Allow up to 5 requests per 15 minutes
            return $count >= 5;
        } catch (Exception $e) {
            error_log('Rate limit check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Verify the reset code entered by user
     * @param string $email User's email
     * @param string $code Verification code
     * @return array Result with success status and token
     */
    public function verifyResetCode($email, $code) {
        try {
            // Normalize inputs to avoid whitespace or copy-paste artefacts
            $email = trim($email);
            $code = trim($code);
            $query = "SELECT token, expires_at FROM password_reset_tokens 
                      WHERE email = :email AND verification_code = :code 
                      AND used = 0 AND expires_at > NOW() 
                      ORDER BY created_at DESC LIMIT 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':code', $code);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return [
                    'success' => true,
                    'message' => 'Code verified successfully.',
                    'token' => $row['token']
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Invalid or expired verification code.'
                ];
            }

        } catch (Exception $e) {
            error_log("Code verification error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'An error occurred during verification.'
            ];
        }
    }

    /**
     * Reset password using verified token
     * @param string $token Reset token
     * @param string $newPassword New password
     * @return array Result with success status
     */
    public function resetPassword($token, $newPassword) {
        try {
            // Start transaction
            $this->conn->beginTransaction();

            // Verify token is valid and not used
            $query = "SELECT email FROM password_reset_tokens 
                      WHERE token = :token AND used = 0 AND expires_at > NOW() 
                      LIMIT 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':token', $token);
            $stmt->execute();

            if ($stmt->rowCount() === 0) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'message' => 'Invalid or expired reset token.'
                ];
            }

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $email = $row['email'];

            // Update password in users table
            $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
            $updateQuery = "UPDATE users SET password_hash = :password_hash WHERE email = :email";
            $updateStmt = $this->conn->prepare($updateQuery);
            $updateStmt->bindParam(':password_hash', $passwordHash);
            $updateStmt->bindParam(':email', $email);

            if (!$updateStmt->execute()) {
                $this->conn->rollBack();
                return [
                    'success' => false,
                    'message' => 'Failed to update password.'
                ];
            }

            // Update password in suppliers table if user is a supplier
            $supplierQuery = "UPDATE suppliers SET password_hash = :password_hash WHERE email = :email";
            $supplierStmt = $this->conn->prepare($supplierQuery);
            $supplierStmt->bindParam(':password_hash', $passwordHash);
            $supplierStmt->bindParam(':email', $email);
            $supplierStmt->execute(); // Don't fail if supplier doesn't exist

            // Mark token as used
            $markUsedQuery = "UPDATE password_reset_tokens SET used = 1 WHERE token = :token";
            $markUsedStmt = $this->conn->prepare($markUsedQuery);
            $markUsedStmt->bindParam(':token', $token);
            $markUsedStmt->execute();

            // Commit transaction
            $this->conn->commit();

            return [
                'success' => true,
                'message' => 'Password reset successfully.'
            ];

        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("Password reset error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'An error occurred while resetting password.'
            ];
        }
    }

    /**
     * Clean up old/expired tokens for an email
     * @param string $email User's email
     */
    private function cleanupOldTokens($email) {
        $query = "DELETE FROM password_reset_tokens 
                  WHERE email = :email AND (expires_at < NOW() OR used = 1)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
    }

    /**
     * Get user details by email for password reset
     * @param string $email User's email
     * @return array|false User details or false if not found
     */
    public function getUserByEmail($email) {
        $query = "SELECT id, username, email, role FROM users WHERE email = :email LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
        return false;
    }
}
?>
