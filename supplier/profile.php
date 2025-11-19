<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as supplier
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'supplier') {
    header("Location: ../login.php");
    exit();
}

$supplier_name = $_SESSION['username'];

// Create database connection and PDO for sidebar compatibility
$database = new Database();
$db = $database->getConnection();
$pdo = $db;

// ---- Supplier logging: schema ensure, rotation, and helpers ----
try {
    // Activity logs (management-style for supplier actions)
    $db->exec("CREATE TABLE IF NOT EXISTS supplier_activity (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_user_id INT NOT NULL,
        supplier_username VARCHAR(100) NOT NULL,
        action VARCHAR(100) NOT NULL,
        component VARCHAR(100) NOT NULL,
        details LONGTEXT NULL,
        status VARCHAR(30) DEFAULT NULL,
        level VARCHAR(10) DEFAULT 'INFO',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sup_act_user (supplier_user_id),
        INDEX idx_sup_act_action (action),
        INDEX idx_sup_act_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS supplier_security_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_user_id INT NOT NULL,
        username VARCHAR(100) NOT NULL,
        event_type VARCHAR(100) NOT NULL,
        details LONGTEXT NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        status VARCHAR(30) DEFAULT NULL,
        level VARCHAR(10) DEFAULT 'INFO',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_supplier_sec (supplier_user_id, created_at, event_type),
        INDEX idx_sup_sec_user (supplier_user_id),
        INDEX idx_sup_sec_event (event_type),
        INDEX idx_sup_sec_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Archives for rotation
    $db->exec("CREATE TABLE IF NOT EXISTS supplier_activity_archive LIKE supplier_activity");
    $db->exec("CREATE TABLE IF NOT EXISTS supplier_security_logs_archive LIKE supplier_security_logs");
    // Meta for rotation cadence
    $db->exec("CREATE TABLE IF NOT EXISTS log_rotation_meta (
        name VARCHAR(64) PRIMARY KEY,
        last_run TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* ignore non-fatal */ }

function supplier_rotate_logs_if_due(PDO $db) {
    try {
        // Run at most once per 24h
        $name = 'supplier_logs';
        $stmt = $db->prepare("SELECT last_run FROM log_rotation_meta WHERE name = :n");
        $stmt->execute([':n'=>$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $shouldRun = true;
        if ($row) {
            $last = strtotime($row['last_run']);
            $shouldRun = ($last === false) || (time() - $last > 24*3600);
        }
        if (!$shouldRun) return;

        // Move logs older than 90 days to archive, then delete
        $db->beginTransaction();
        $db->exec("INSERT IGNORE INTO supplier_activity_archive SELECT * FROM supplier_activity WHERE created_at < (NOW() - INTERVAL 90 DAY)");
        $db->exec("DELETE FROM supplier_activity WHERE created_at < (NOW() - INTERVAL 90 DAY)");
        $db->exec("INSERT IGNORE INTO supplier_security_logs_archive SELECT * FROM supplier_security_logs WHERE created_at < (NOW() - INTERVAL 90 DAY)");
        $db->exec("DELETE FROM supplier_security_logs WHERE created_at < (NOW() - INTERVAL 90 DAY)");

        if ($row) {
            $upd = $db->prepare("UPDATE log_rotation_meta SET last_run = NOW() WHERE name = :n");
            $upd->execute([':n'=>$name]);
        } else {
            $ins = $db->prepare("INSERT INTO log_rotation_meta (name, last_run) VALUES (:n, NOW())");
            $ins->execute([':n'=>$name]);
        }
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        // Non-fatal; skip on error
    }
}

function log_supplier_activity(PDO $db, $supplier_id, $username, $action, $component, array $details = [], $status = null, $level = 'INFO') {
    try {
        $stmt = $db->prepare("INSERT INTO supplier_activity (supplier_user_id, supplier_username, action, component, details, status, level) VALUES (:id, :u, :a, :c, :d, :s, :l)");
        $stmt->execute([
            ':id' => (int)$supplier_id,
            ':u'  => (string)$username,
            ':a'  => (string)$action,
            ':c'  => (string)$component,
            ':d'  => $details ? json_encode($details) : null,
            ':s'  => $status,
            ':l'  => $level,
        ]);
    } catch (Exception $e) { /* ignore */ }
}

function log_supplier_security(PDO $db, $supplier_id, $username, $event_type, array $details = [], $status = null, $level = 'INFO') {
    try {
        $stmt = $db->prepare("INSERT INTO supplier_security_logs (supplier_user_id, username, event_type, details, ip_address, user_agent, status, level) VALUES (:id, :u, :e, :d, :ip, :ua, :s, :l)");
        $stmt->execute([
            ':id' => (int)$supplier_id,
            ':u'  => (string)$username,
            ':e'  => (string)$event_type,
            ':d'  => $details ? json_encode($details) : null,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ':s'  => $status,
            ':l'  => $level,
        ]);
    } catch (Exception $e) { /* ignore */ }
}

function migrate_supplier_auth_logs(PDO $db, $supplier_id) {
    // One-time migration of recent auth logs to dedicated supplier security table
    try {
        // Only migrate if the supplier-specific table is empty for this user
        $check = $db->prepare("SELECT COUNT(*) FROM supplier_security_logs WHERE supplier_user_id = :id");
        $check->execute([':id'=>$supplier_id]);
        if ((int)$check->fetchColumn() > 0) return;

        $sql = "SELECT username, action, ip_address, user_agent, additional_info, created_at
                FROM auth_logs
                WHERE user_type = 'supplier' AND user_id = :id AND created_at >= (NOW() - INTERVAL 180 DAY)
                ORDER BY created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute([':id'=>$supplier_id]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ev = $r['action'] ?? 'auth_event';
            $status = (stripos($ev,'success')!==false) ? 'success' : ((stripos($ev,'fail')!==false||stripos($ev,'error')!==false) ? 'failed' : 'info');
            $ins = $db->prepare("INSERT IGNORE INTO supplier_security_logs (supplier_user_id, username, event_type, details, ip_address, user_agent, status, level, created_at)
                                  VALUES (:id, :u, :e, :d, :ip, :ua, :s, :l, :ts)");
            $ins->execute([
                ':id' => (int)$supplier_id,
                ':u'  => (string)($r['username'] ?? ''),
                ':e'  => (string)$ev,
                ':d'  => $r['additional_info'] ?? null,
                ':ip' => $r['ip_address'] ?? null,
                ':ua' => substr($r['user_agent'] ?? '', 0, 255),
                ':s'  => $status,
                ':l'  => strtoupper($status) === 'FAILED' ? 'WARNING' : 'INFO',
                ':ts' => $r['created_at'] ?? date('Y-m-d H:i:s'),
            ]);
        }
    } catch (Exception $e) { /* ignore */ }
}

// Run rotation at start of request
supplier_rotate_logs_if_due($db);

// Initialize supplier context
$supplier_id = $_SESSION['supplier']['user_id'] ?? $_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';
$company = '';
$email = '';
$phone = '';
$address = '';
$message = '';
$message_type = '';
$last_login = null;
$login_attempts = 0;
$total_orders = 0;
$product_count = 0;
$security_logs = [];

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'update_profile')) {
    $company = trim($_POST['company'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($company !== '' && $email !== '' && $phone !== '' && $address !== '') {
        // Enforce server-side phone validation: must be exactly 11 numeric digits
        if (!preg_match('/^\d{11}$/', $phone)) {
            $message = 'Phone must be exactly 11 digits.';
            $message_type = 'danger';
        } elseif (strlen($address) < 5 || strlen($address) > 500) {
            // Basic server-side address length validation
            $message = 'Address must be between 5 and 500 characters.';
            $message_type = 'danger';
        } else {
            try {
                $stmt = $db->prepare("UPDATE suppliers SET name = :name, email = :email, contact_phone = :phone, address = :address WHERE id = :id");
                $stmt->bindParam(':name', $company);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':phone', $phone);
                $stmt->bindParam(':address', $address);
                $stmt->bindParam(':id', $supplier_id, PDO::PARAM_INT);
                if ($stmt->execute()) {
                    $message = 'Profile updated successfully.';
                    $message_type = 'success';
                    // Management log + security audit
                    $changed = [];
                    foreach (['company'=>'name','email'=>'email','phone'=>'phone','address'=>'address'] as $k=>$col) {
                        if (isset($_POST[$k])) $changed[] = $col;
                    }
                    log_supplier_activity($db, $supplier_id, $username, 'profile_update', 'supplier_profile', [ 'changed_fields' => $changed ], 'success', 'INFO');
                    log_supplier_security($db, $supplier_id, $username, 'profile_update', [ 'changed_fields' => $changed ], 'success', 'INFO');
                } else {
                    $message = 'Failed to update profile.';
                    $message_type = 'danger';
                    log_supplier_activity($db, $supplier_id, $username, 'profile_update', 'supplier_profile', [], 'failed', 'ERROR');
                    log_supplier_security($db, $supplier_id, $username, 'profile_update', [ 'error' => 'db_execute_failed' ], 'failed', 'ERROR');
                }
            } catch (Exception $e) {
                $message = 'Error: ' . $e->getMessage();
                $message_type = 'danger';
                log_supplier_activity($db, $supplier_id, $username, 'profile_update', 'supplier_profile', [ 'exception' => substr($e->getMessage(),0,200) ], 'failed', 'ERROR');
                log_supplier_security($db, $supplier_id, $username, 'profile_update', [ 'exception' => substr($e->getMessage(),0,200) ], 'failed', 'ERROR');
            }
        }
    } else {
        $message = 'Please fill in all required fields.';
        $message_type = 'warning';
        log_supplier_activity($db, $supplier_id, $username, 'profile_update', 'supplier_profile', [ 'reason' => 'missing_fields' ], 'failed', 'WARNING');
        log_supplier_security($db, $supplier_id, $username, 'profile_update', [ 'reason' => 'missing_fields' ], 'failed', 'WARNING');
    }
}

// Change password handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'change_password')) {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($current === '' || $new === '' || $confirm === '') {
        $message = 'Please fill in all password fields.';
        $message_type = 'warning';
        log_supplier_security($db, $supplier_id, $username, 'password_change', [ 'reason' => 'missing_fields' ], 'failed', 'WARNING');
    } elseif ($new !== $confirm) {
        $message = 'New passwords do not match.';
        $message_type = 'danger';
        log_supplier_security($db, $supplier_id, $username, 'password_change', [ 'reason' => 'mismatch' ], 'failed', 'WARNING');
    } elseif (strlen($new) < 8) {
        $message = 'New password must be at least 8 characters.';
        $message_type = 'warning';
        log_supplier_security($db, $supplier_id, $username, 'password_change', [ 'reason' => 'weak_password' ], 'failed', 'WARNING');
    } else {
        try {
            $stmt = $db->prepare("SELECT password_hash FROM suppliers WHERE id = :id LIMIT 1");
            $stmt->bindParam(':id', $supplier_id, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || !password_verify($current, $row['password_hash'])) {
                $message = 'Current password is incorrect.';
                $message_type = 'danger';
                log_supplier_security($db, $supplier_id, $username, 'password_change', [ 'reason' => 'current_incorrect' ], 'failed', 'WARNING');
            } else {
                $newHash = password_hash($new, PASSWORD_DEFAULT);
                $upd = $db->prepare("UPDATE suppliers SET password_hash = :ph WHERE id = :id");
                $upd->bindParam(':ph', $newHash);
                $upd->bindParam(':id', $supplier_id, PDO::PARAM_INT);
                if ($upd->execute()) {
                    $message = 'Password changed successfully.';
                    $message_type = 'success';
                    log_supplier_activity($db, $supplier_id, $username, 'password_change', 'supplier_profile', [], 'success', 'INFO');
                    log_supplier_security($db, $supplier_id, $username, 'password_change', [], 'success', 'INFO');
                } else {
                    $message = 'Failed to change password.';
                    $message_type = 'danger';
                    log_supplier_activity($db, $supplier_id, $username, 'password_change', 'supplier_profile', [ 'error' => 'db_execute_failed' ], 'failed', 'ERROR');
                    log_supplier_security($db, $supplier_id, $username, 'password_change', [ 'error' => 'db_execute_failed' ], 'failed', 'ERROR');
                }
            }
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'danger';
            log_supplier_activity($db, $supplier_id, $username, 'password_change', 'supplier_profile', [ 'exception' => substr($e->getMessage(),0,200) ], 'failed', 'ERROR');
            log_supplier_security($db, $supplier_id, $username, 'password_change', [ 'exception' => substr($e->getMessage(),0,200) ], 'failed', 'ERROR');
        }
    }
}

// Fetch current supplier data (for initial form values or after update)
try {
    $stmt = $db->prepare("SELECT username, name, email, contact_phone, address, last_login, login_attempts FROM suppliers WHERE id = :id LIMIT 1");
    $stmt->bindParam(':id', $supplier_id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $username = $row['username'] ?? $username;
        $last_login = $row['last_login'] ?? $last_login;
        $login_attempts = isset($row['login_attempts']) ? (int)$row['login_attempts'] : $login_attempts;
        // If a POST occurred, keep posted values; otherwise populate from DB
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $company = $row['name'] ?? '';
            $email = $row['email'] ?? '';
            $phone = $row['contact_phone'] ?? '';
            $address = $row['address'] ?? '';
        }
    }
} catch (Exception $e) {
    // Silent fail; show defaults
}

// Log sensitive access: profile view
log_supplier_security($db, $supplier_id, $username, 'profile_view', [ 'component' => 'supplier_profile' ], 'success', 'INFO');

// Compute Quick Stats: orders and products for this supplier
try {
    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM orders WHERE supplier_id = :id AND (confirmation_status IS NULL OR confirmation_status <> 'cancelled')");
    $stmt->bindParam(':id', $supplier_id, PDO::PARAM_INT);
    $stmt->execute();
    $total_orders = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);

    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM inventory WHERE supplier_id = :id");
    $stmt->bindParam(':id', $supplier_id, PDO::PARAM_INT);
    $stmt->execute();
    $product_count = (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
} catch (Exception $e) {
    // Leave defaults on error
}
// Migrate legacy auth logs into dedicated supplier security logs (one-time per supplier)
try { migrate_supplier_auth_logs($db, $supplier_id); } catch (Exception $e) { /* ignore */ }

// Fetch recent security logs for this supplier from dedicated table
try {
    $stmt = $db->prepare("SELECT event_type, ip_address, user_agent, created_at, details AS metadata FROM supplier_security_logs WHERE supplier_user_id = :id ORDER BY created_at DESC LIMIT 10");
    $stmt->bindParam(':id', $supplier_id, PDO::PARAM_INT);
    $stmt->execute();
    $security_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $security_logs = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Supplier Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .sidebar {
            min-height: 100vh;
        }
        .nav-link {
            color: #212529;
            transition: all 0.3s;
        }
        .nav-link:hover, .nav-link.active {
            color: #0d6efd;
            background-color: rgba(13, 110, 253, 0.08);
            border-radius: 5px;
        }
    </style>
</head>
<body class="bg-light">
<?php include_once 'includes/header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include_once 'includes/sidebar.php'; ?>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-1 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="bi bi-person me-2"></i>Supplier Profile
                    </h1>
                </div>

                <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Profile Information -->
                    <div class="col-lg-8">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Business Profile Information</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="update_profile">
                                    
                                    <!-- Basic Information -->
                                    <h6 class="text-primary mb-3">Basic Information</h6>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="username" class="form-label">Username</label>
                                                <input type="text" class="form-control" id="username" value="<?php echo htmlspecialchars($username); ?>" readonly>
                                                <div class="form-text">Username cannot be changed</div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="company" class="form-label">Company Name *</label>
                                                <input type="text" class="form-control" id="company" name="company" value="<?php echo htmlspecialchars($company); ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="email" class="form-label">Business Email *</label>
                                                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="phone" class="form-label">Business Phone *</label>
                                                <input type="tel" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($phone); ?>" required inputmode="numeric" pattern="[0-9]{11}" maxlength="11">
                                                <div class="form-text">Phone must be exactly 11 digits.</div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    
                                    
                                    <!-- Address Information -->
                                    <h6 class="text-primary mb-3 mt-4">Address Information</h6>
                                     <div class="mb-3">
                                         <label for="address" class="form-label">Business Address *</label>
                                         <textarea class="form-control" id="address" name="address" rows="2" required minlength="5" maxlength="500"><?php echo htmlspecialchars($address); ?></textarea>
                                         <div class="form-text">Address must be 5–500 characters.</div>
                                     </div>
                                     
                                    
                                    
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-circle me-1"></i>Update Profile
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary" onclick="resetForm()">
                                            <i class="bi bi-arrow-clockwise me-1"></i>Reset Changes
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Security Settings -->
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-shield-check me-2"></i>Security
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">Last Login</label>
                                    <p class="text-muted small"><?php echo $last_login ? htmlspecialchars(date('M j, Y g:i A', strtotime($last_login))) : 'N/A'; ?></p>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Login Attempts</label>
                                    <p class="small <?php echo ($login_attempts ?? 0) > 0 ? 'text-warning' : 'text-success'; ?>">
                                        <?php if (($login_attempts ?? 0) > 0): ?>
                                            <i class="bi bi-exclamation-triangle me-1"></i><?php echo (int)$login_attempts; ?> failed attempts
                                        <?php else: ?>
                                            <i class="bi bi-check-circle me-1"></i>No failed attempts
                                        <?php endif; ?>
                                    </p>
                                </div>
                                
                                <div class="d-grid gap-2">
                                    <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                                        <i class="bi bi-key me-2"></i>Change Password
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#securityLogModal">
                                        <i class="bi bi-shield-x me-2"></i>Security Log
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Stats -->
                        <div class="card mt-3">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-graph-up me-2"></i>Quick Stats
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-6">
                                        <div class="border-end">
                                            <h4 class="text-primary"><?php echo number_format($total_orders); ?></h4>
                                            <small class="text-muted">Total Orders</small>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <h4 class="text-success"><?php echo number_format($product_count); ?></h4>
                                        <small class="text-muted">Products</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordLabel" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="changePasswordLabel"><i class="bi bi-key me-2"></i>Change Password</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form method="POST">
            <input type="hidden" name="action" value="change_password">
            <div class="modal-body">
              <div class="mb-3">
                <label for="current_password" class="form-label">Current Password</label>
                <input type="password" class="form-control" id="current_password" name="current_password" required>
              </div>
              <div class="mb-3">
                <label for="new_password" class="form-label">New Password</label>
                <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8">
                <div class="form-text">Minimum 8 characters.</div>
              </div>
              <div class="mb-3">
                <label for="confirm_password" class="form-label">Confirm New Password</label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8">
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              <button type="submit" class="btn btn-primary">Update Password</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Security Log Modal -->
    <div class="modal fade" id="securityLogModal" tabindex="-1" aria-labelledby="securityLogLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="securityLogLabel"><i class="bi bi-shield-lock me-2"></i>Security Log</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <?php if (!empty($security_logs)): ?>
            <div class="table-responsive">
              <table class="table table-sm">
                <thead>
                  <tr>
                    <th>Event</th>
                    <th>IP</th>
                    <th>User Agent</th>
                    <th>Date</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($security_logs as $log): ?>
                  <tr>
                    <td><?php echo htmlspecialchars($log['event_type']); ?></td>
                    <td class="text-muted small"><?php echo htmlspecialchars($log['ip_address']); ?></td>
                    <td class="text-muted small" style="max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                      <?php echo htmlspecialchars($log['user_agent']); ?>
                    </td>
                    <td class="text-muted small"><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($log['created_at']))); ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php else: ?>
            <p class="text-muted">No recent security events.</p>
            <?php endif; ?>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function resetForm() {
        window.location.href = 'profile.php';
    }
    // Enforce numeric-only input and length on phone
    const phoneInput = document.getElementById('phone');
    if (phoneInput) {
        phoneInput.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 11);
        });
    }
    // Client-side validation on submit: phone is required and must be 11 digits
    const form = document.querySelector('form');
    if (form && phoneInput) {
        form.addEventListener('submit', function(e) {
            if ((document.querySelector('input[name="action"]')?.value || '') === 'update_profile') {
                phoneInput.setCustomValidity('');
                const val = phoneInput.value.trim();
                if (!/^\d{11}$/.test(val)) {
                    phoneInput.setCustomValidity('Phone must be exactly 11 digits');
                    e.preventDefault();
                    return false;
                }
            }
            return true;
        });
    }
    </script>
</body>
</html>