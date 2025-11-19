<?php
// ===== Dependencies & access control (corrected) =====
include_once '../config/session.php';
require_once '../config/database.php';

// Load the model classes used by this page
require_once '../models/user.php';

// ---- AJAX: Admin Logs endpoint (JSON) ----
if (($_GET['ajax'] ?? '') === 'admin_logs') {
    header('Content-Type: application/json');
    // Auth guard
    if (empty($_SESSION['admin']['user_id']) || (($_SESSION['admin']['role'] ?? null) !== 'management')) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit();
    }

    try {
        $db = (new Database())->getConnection();
        $username    = trim($_GET['username'] ?? '');
        $actionType  = trim($_GET['action_type'] ?? '');
        $startDate   = trim($_GET['start_date'] ?? '');
        $endDate     = trim($_GET['end_date'] ?? '');
        $page        = max(1, (int)($_GET['page'] ?? 1));
        $perPage     = min(100, max(5, (int)($_GET['per_page'] ?? 10)));
        $offset      = ($page - 1) * $perPage;

        // Pull auth logs (admins only)
        $authWhere = ['user_type = "admin"'];
        $authParams = [];
        if ($username !== '') { $authWhere[] = 'username = :username'; $authParams[':username'] = $username; }
        if ($actionType !== '') { $authWhere[] = 'action = :action'; $authParams[':action'] = $actionType; }
        if ($startDate !== '' && $endDate !== '') { $authWhere[] = 'DATE(created_at) BETWEEN :start AND :end'; $authParams[':start'] = $startDate; $authParams[':end'] = $endDate; }
        elseif ($startDate !== '') { $authWhere[] = 'DATE(created_at) >= :start'; $authParams[':start'] = $startDate; }
        elseif ($endDate !== '') { $authWhere[] = 'DATE(created_at) <= :end'; $authParams[':end'] = $endDate; }
        $authWhereSql = 'WHERE ' . implode(' AND ', $authWhere);

        $authRows = [];
        try {
            $sqlA = "SELECT username, action, ip_address, user_agent, additional_info, created_at
                     FROM auth_logs $authWhereSql
                     ORDER BY created_at DESC
                     LIMIT 1000"; // cap to reasonable limit
            $stmtA = $db->prepare($sqlA);
            foreach ($authParams as $k=>$v) { $stmtA->bindValue($k, $v); }
            $stmtA->execute();
            $authRows = $stmtA->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch(Exception $e) { $authRows = []; }

        // Pull admin activity
        $actWhere = ['1=1'];
        $actParams = [];
        if ($username !== '') { $actWhere[] = 'admin_username = :a_username'; $actParams[':a_username'] = $username; }
        if ($actionType !== '') { $actWhere[] = 'action = :a_action'; $actParams[':a_action'] = $actionType; }
        if ($startDate !== '' && $endDate !== '') { $actWhere[] = 'DATE(created_at) BETWEEN :a_start AND :a_end'; $actParams[':a_start'] = $startDate; $actParams[':a_end'] = $endDate; }
        elseif ($startDate !== '') { $actWhere[] = 'DATE(created_at) >= :a_start'; $actParams[':a_start'] = $startDate; }
        elseif ($endDate !== '') { $actWhere[] = 'DATE(created_at) <= :a_end'; $actParams[':a_end'] = $endDate; }
        $actWhereSql = 'WHERE ' . implode(' AND ', $actWhere);

        $actRows = [];
        try {
            $sqlB = "SELECT admin_username, action, component, details, status, created_at
                     FROM admin_activity $actWhereSql
                     ORDER BY created_at DESC
                     LIMIT 1000";
            $stmtB = $db->prepare($sqlB);
            foreach ($actParams as $k=>$v) { $stmtB->bindValue($k, $v); }
            $stmtB->execute();
            $actRows = $stmtB->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch(Exception $e) { $actRows = []; }

        // Map to unified items
        $items = [];
        foreach ($authRows as $r) {
            $items[] = [
                'timestamp' => $r['created_at'],
                'username'  => $r['username'],
                'action'    => $r['action'],
                'component' => 'auth',
                'details'   => $r['additional_info'],
                'status'    => (stripos($r['action'],'success')!==false ? 'success' : (stripos($r['action'],'fail')!==false?'failed':'info')),
                'ip'        => $r['ip_address'],
                'user_agent'=> $r['user_agent']
            ];
        }
        foreach ($actRows as $r) {
            $items[] = [
                'timestamp' => $r['created_at'],
                'username'  => $r['admin_username'],
                'action'    => $r['action'],
                'component' => $r['component'],
                'details'   => $r['details'],
                'status'    => $r['status'] ?? 'info',
                'ip'        => null,
                'user_agent'=> null
            ];
        }

        // Sort combined by timestamp desc
        usort($items, function($a,$b){ return strcmp($b['timestamp'], $a['timestamp']); });
        $total = count($items);
        $paged = array_slice($items, $offset, $perPage);

        // Distinct actions across both sources
        $actions = [];
        try {
            $acts1 = $db->query("SELECT DISTINCT action FROM auth_logs WHERE user_type='admin'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $acts2 = $db->query("SELECT DISTINCT action FROM admin_activity")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $actions = array_values(array_unique(array_merge($acts1, $acts2)));
            sort($actions);
        } catch(Exception $e) { $actions = []; }

        echo json_encode([
            'ok' => true,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'actions' => $actions,
            'items' => $paged
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load logs']);
    }
    exit();
}

// ---- Admin auth guard (namespaced) ----
if (empty($_SESSION['admin']['user_id'])) {
    header("Location: ../login.php");
    exit();
}
if (($_SESSION['admin']['role'] ?? null) !== 'management') {
    header("Location: ../login.php");
    exit();
}

// ---- Create dependencies ----
$db   = (new Database())->getConnection();
$user = new User($db);

// ===== Continue with your existing page logic below =====
// Example:
// if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
//     $user->create($_POST['username'], $_POST['password'], $_POST['role'], $_POST['email'], $_POST['phone'] ?? null);
// }
// $stmt = $user->readAll();
// ... render table, forms, etc.

// Process form submission
$message = '';
$messageType = '';

// Ensure admin_activity table exists (lightweight runtime guard)
try {
    $dbCheck = (new Database())->getConnection();
    $dbCheck->query("SELECT 1 FROM admin_activity LIMIT 1");
} catch (Exception $e) {
    try {
        $dbInit = (new Database())->getConnection();
        $dbInit->exec("CREATE TABLE IF NOT EXISTS admin_activity (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_user_id INT NOT NULL,
            admin_username VARCHAR(100) NOT NULL,
            action VARCHAR(100) NOT NULL,
            component VARCHAR(100) NOT NULL,
            details LONGTEXT,
            status VARCHAR(30) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_user (admin_user_id),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e2) { /* ignore */ }
}

// Helper: write admin activity
function log_admin_activity($db, $action, $component, $detailsArr = [], $status = null) {
    try {
        $stmt = $db->prepare("INSERT INTO admin_activity (admin_user_id, admin_username, action, component, details, status) VALUES (:uid, :uname, :action, :component, :details, :status)");
        $uid = (int)($_SESSION['admin']['user_id'] ?? 0);
        $uname = $_SESSION['admin']['username'] ?? 'unknown';
        $detailsJson = !empty($detailsArr) ? json_encode($detailsArr) : null;
        $stmt->execute([
            ':uid' => $uid,
            ':uname' => $uname,
            ':action' => $action,
            ':component' => $component,
            ':details' => $detailsJson,
            ':status' => $status,
        ]);
    } catch (Exception $e) { /* ignore */ }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Add new user
        if ($_POST['action'] === 'add') {
            $username = $_POST['username'];
            $password = $_POST['password'];
            $role = $_POST['role'];
            $email = $_POST['email'];
            $phone = $_POST['phone'];
            
            // Check if username already exists
            if ($user->usernameExists($username)) {
                $message = "Username already exists. Please choose another.";
                $messageType = "danger";
            } else {
                // Create user
                if ($user->create($username, $password, $role, $email, $phone)) {
                    $message = "User was created successfully.";
                    $messageType = "success";
                    log_admin_activity($db, 'user_create', 'users', [
                        'target_username' => $username,
                        'role' => $role,
                        'email' => $email
                    ], 'success');
                } else {
                    $message = "Unable to create user.";
                    $messageType = "danger";
                    log_admin_activity($db, 'user_create', 'users', [
                        'target_username' => $username,
                        'role' => $role,
                        'email' => $email
                    ], 'failed');
                }
            }
        }
        
        // Update user
        else if ($_POST['action'] === 'update') {
            $user->id = $_POST['id'];
            $user->username = $_POST['username'];
            $user->role = $_POST['role'];
            $user->email = $_POST['email'];
            $user->phone = $_POST['phone'];
            
            if ($user->update()) {
                $message = "User was updated successfully.";
                $messageType = "success";
                log_admin_activity($db, 'user_update', 'users', [
                    'target_id' => $user->id,
                    'username' => $user->username,
                    'role' => $user->role,
                    'email' => $user->email,
                    'phone' => $user->phone
                ], 'success');
            } else {
                $message = "Unable to update user.";
                $messageType = "danger";
                log_admin_activity($db, 'user_update', 'users', [
                    'target_id' => $user->id,
                    'username' => $user->username
                ], 'failed');
            }
        }
        
        // Change password
        else if ($_POST['action'] === 'change_password') {
            $id = $_POST['id'];
            $new_password = $_POST['new_password'];
            
            if ($user->changePassword($id, $new_password)) {
                $message = "Password was changed successfully.";
                $messageType = "success";
                log_admin_activity($db, 'password_change', 'users', [
                    'target_id' => (int)$id
                ], 'success');
            } else {
                $message = "Unable to change password.";
                $messageType = "danger";
                log_admin_activity($db, 'password_change', 'users', [
                    'target_id' => (int)$id
                ], 'failed');
            }
        }
        
        // Delete user
        else if ($_POST['action'] === 'delete') {
            $id = $_POST['id'];
            // Prevent deleting supplier accounts entirely
            try {
                $roleStmt = $db->prepare("SELECT username, role FROM users WHERE id = ?");
                $roleStmt->execute([$id]);
                $urow = $roleStmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $urow = null; }
            if ($urow && $urow['role'] === 'supplier') {
                $message = "You cannot delete supplier accounts.";
                $messageType = "danger";
                log_admin_activity($db, 'user_delete', 'users', [
                    'target_id' => (int)$id,
                    'target_username' => $urow['username'] ?? null
                ], 'blocked');
            } else {
            
            // Prevent deleting own account
         if ((int)$id === (int)($_SESSION['admin']['user_id'] ?? 0)) {

                $message = "You cannot delete your own account.";
                $messageType = "danger";
                log_admin_activity($db, 'user_delete', 'users', [
                    'target_id' => (int)$id
                ], 'blocked');
            } else {
                if ($user->delete($id)) {
                    $message = "User was deleted successfully.";
                    $messageType = "success";
                    log_admin_activity($db, 'user_delete', 'users', [
                        'target_id' => (int)$id
                    ], 'success');
                } else {
                    // Fallback: soft-delete when FK constraints prevent hard delete
                    try {
                        // Ensure is_deleted column exists
                        $hasDeletedCol = false;
                        try {
                            $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_deleted'");
                            $stmt->execute();
                            $hasDeletedCol = $stmt->fetchColumn() > 0;
                        } catch (Exception $e) { $hasDeletedCol = false; }

                        if (!$hasDeletedCol) {
                            try {
                                $db->exec("ALTER TABLE users ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0, ADD INDEX idx_users_is_deleted (is_deleted)");
                                $hasDeletedCol = true;
                            } catch (Exception $e) {}
                        }

                        // Soft-delete the user
                        $stmt = $db->prepare("UPDATE users SET is_deleted = 1 WHERE id = ?");
                        $stmt->execute([$id]);

                        $message = "User archived instead of deleted due to related records.";
                        $messageType = "success";
                        log_admin_activity($db, 'user_archive', 'users', [
                            'target_id' => (int)$id
                        ], 'success');
                    } catch (Exception $e) {
                        $message = "Unable to delete user. The account has related records.";
                        $messageType = "danger";
                        log_admin_activity($db, 'user_delete', 'users', [
                            'target_id' => (int)$id
                        ], 'failed');
                    }
                }
            }
            }
        }
    }
}

// Get all users
// $stmt = $user->readAll();
try {
    $hasDeletedCol = false;
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_deleted'");
        $stmt->execute();
        $hasDeletedCol = $stmt->fetchColumn() > 0;
    } catch (Exception $e) { $hasDeletedCol = false; }

    if (!$hasDeletedCol) {
        try {
            $db->exec("ALTER TABLE users ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0, ADD INDEX idx_users_is_deleted (is_deleted)");
            $hasDeletedCol = true;
        } catch (Exception $e) { /* ignore */ }
    }

    if ($hasDeletedCol) {
        $stmt = $db->prepare("SELECT * FROM users WHERE is_deleted = 0 ORDER BY username");
        $stmt->execute();
    } else {
        $stmt = $user->readAll();
    }
} catch (Exception $e) {
    // Fallback to original method on error
    $stmt = $user->readAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Inventory & Stock Control System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
      .supplier-badge { display: inline-flex; align-items: center; }
      @media (max-width: 576px) {
        .supplier-badge { font-size: 0.75rem; padding: 0.35rem 0.6rem; }
      }
    </style>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
</head>
<body>
    <?php include_once 'includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include_once 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">User Management</h1>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                        <i class="bi bi-person-plus me-2"></i>Add New User
                    </button>
                </div>
                
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-table me-1"></i>
                        Users
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="usersTable" class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Username</th>
                                        <th>Role</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Created At</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                                        <tr>
                                            <td><?php echo $row['id']; ?></td>
                                            <td><?php echo $row['username']; ?></td>
                                            <td>
                                                <?php if ($row['role'] == 'management'): ?>
                                                    <span class="badge bg-primary">Management</span>
                                                <?php elseif ($row['role'] === 'supplier'): ?>
                                                    <span class="badge bg-success supplier-badge">
                                                        <i class="bi bi-award me-1"></i><?php echo htmlspecialchars($row['supplier_badge'] ?? 'Supplier'); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Staff</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $row['email']; ?></td>
                                            <td><?php echo $row['phone']; ?></td>
                                            <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-primary edit-btn" 
                                                    data-id="<?php echo $row['id']; ?>"
                                                    data-username="<?php echo $row['username']; ?>"
                                                    data-role="<?php echo $row['role']; ?>"
                                                    data-email="<?php echo $row['email']; ?>"
                                                    data-phone="<?php echo $row['phone']; ?>"
                                                    data-bs-toggle="modal" data-bs-target="#editUserModal">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <?php if ($row['role'] === 'supplier'): ?>
                                                    <?php
                                                        // Fetch supplier details by username for view modal
                                                        $supplierDetails = null;
                                                        try {
                                                            $stmtSup = $db->prepare("SELECT id, name, contact_phone, email, address, status, payment_methods, username FROM suppliers WHERE username = :u LIMIT 1");
                                                            $stmtSup->execute([':u' => $row['username']]);
                                                            $supplierDetails = $stmtSup->fetch(PDO::FETCH_ASSOC) ?: null;
                                                        } catch (Exception $e) {
                                                            $supplierDetails = null;
                                                        }
                                                    ?>
                                                    <button type="button" class="btn btn-sm btn-info view-supplier-btn"
                                                        data-bs-toggle="modal" data-bs-target="#viewSupplierModal"
                                                        data-supplier-found="<?php echo $supplierDetails ? '1' : '0'; ?>"
                                                        data-supplier-username="<?php echo htmlspecialchars($row['username']); ?>"
                                                        data-supplier-name="<?php echo htmlspecialchars($supplierDetails['name'] ?? ''); ?>"
                                                        data-supplier-email="<?php echo htmlspecialchars($supplierDetails['email'] ?? ''); ?>"
                                                        data-supplier-phone="<?php echo htmlspecialchars($supplierDetails['contact_phone'] ?? ''); ?>"
                                                        data-supplier-address="<?php echo htmlspecialchars($supplierDetails['address'] ?? ''); ?>"
                                                        data-supplier-status="<?php echo htmlspecialchars($supplierDetails['status'] ?? ''); ?>"
                                                        data-supplier-payment_methods="<?php echo htmlspecialchars($supplierDetails['payment_methods'] ?? ''); ?>">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                <?php elseif ($row['role'] === 'management'): ?>
                                                    <!-- Change Password first for management -->
                                                    <button type="button" class="btn btn-sm btn-warning password-btn" 
                                                        data-id="<?php echo $row['id']; ?>"
                                                        data-username="<?php echo $row['username']; ?>"
                                                        data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                                                        <i class="bi bi-key"></i>
                                                    </button>
                                                    <!-- Admin Logs after Change Password for management -->
                                                    <button type="button" class="btn btn-sm btn-secondary admin-logs-btn ms-1"
                                                        data-username="<?php echo htmlspecialchars($row['username']); ?>"
                                                        data-bs-toggle="modal" data-bs-target="#adminLogsModal">
                                                        <i class="bi bi-journal-text"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-sm btn-warning password-btn" 
                                                        data-id="<?php echo $row['id']; ?>"
                                                        data-username="<?php echo $row['username']; ?>"
                                                        data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                                                        <i class="bi bi-key"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ((int)$row['id'] !== (int)($_SESSION['admin']['user_id'] ?? 0) && $row['role'] !== 'supplier'): ?>

                                                    <button type="button" class="btn btn-sm btn-danger delete-btn"
                                                        data-id="<?php echo $row['id']; ?>"
                                                        data-username="<?php echo $row['username']; ?>"
                                                        data-bs-toggle="modal" data-bs-target="#deleteUserModal">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addUserModalLabel">Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <div class="mb-3">
                            <label for="role" class="form-label">Role</label>
                            <select class="form-select" id="role" name="role" required>
                                <option value="staff">Staff</option>
                                <option value="management">Management</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="text" class="form-control" id="phone" name="phone">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editUserModalLabel">Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" id="edit-id">
                        <div class="mb-3">
                            <label for="edit-username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="edit-username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit-role" class="form-label">Role</label>
                            <select class="form-select" id="edit-role" name="role" required>
                                <option value="staff">Staff</option>
                                <option value="management">Management</option>
                                <option value="supplier">Supplier</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit-email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="edit-email" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit-phone" class="form-label">Phone</label>
                            <input type="text" class="form-control" id="edit-phone" name="phone">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="changePasswordModalLabel">Change Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="change_password">
                        <input type="hidden" name="id" id="password-id">
                        <p>Change password for user: <strong id="password-username"></strong></p>
                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" id="confirm_password" required>
                            <div class="invalid-feedback">Passwords do not match.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="changePasswordBtn" disabled>Change Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Supplier Modal -->
    <div class="modal fade" id="viewSupplierModal" tabindex="-1" aria-labelledby="viewSupplierModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewSupplierModalLabel">Supplier Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="supplierErrorAlert" class="alert alert-danger d-none" role="alert">
                        Unable to retrieve supplier details. Please try again later.
                    </div>
                    <div id="supplierDetailsContent">
                        <div class="mb-2"><strong>Username:</strong> <span id="sup-username"></span></div>
                        <div class="mb-2"><strong>Name:</strong> <span id="sup-name"></span></div>
                        <div class="mb-2"><strong>Email:</strong> <span id="sup-email"></span></div>
                        <div class="mb-2"><strong>Contact Phone:</strong> <span id="sup-phone"></span></div>
                        <div class="mb-2"><strong>Address:</strong> <span id="sup-address"></span></div>
                        <div class="mb-2"><strong>Status:</strong> <span id="sup-status"></span></div>
                        <div class="mb-2"><strong>Payment Methods:</strong> <span id="sup-payment"></span></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Admin Logs Modal -->
    <div class="modal fade" id="adminLogsModal" tabindex="-1" aria-labelledby="adminLogsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="adminLogsModalLabel">Admin Activity Logs</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 align-items-end mb-3">
                        <div class="col-12 col-sm-3">
                            <label for="logStartDate" class="form-label">Start Date</label>
                            <input type="date" id="logStartDate" class="form-control">
                        </div>
                        <div class="col-12 col-sm-3">
                            <label for="logEndDate" class="form-label">End Date</label>
                            <input type="date" id="logEndDate" class="form-control">
                        </div>
                        <div class="col-12 col-sm-3">
                            <label for="logActionType" class="form-label">Action Type</label>
                            <select id="logActionType" class="form-select">
                                <option value="">All</option>
                            </select>
                        </div>
                        <div class="col-12 col-sm-3 text-sm-end">
                            <button id="applyLogFilters" class="btn btn-primary w-100 w-sm-auto">Apply Filters</button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th style="white-space:nowrap;">Timestamp</th>
                                    <th>Admin Username</th>
                                    <th>Action</th>
                                    <th>Component/Data</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="adminLogsTableBody">
                                <tr><td colspan="5" class="text-center text-muted">No data</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <div class="small text-muted" id="adminLogsMeta">&nbsp;</div>
                        <nav>
                            <ul class="pagination pagination-sm mb-0" id="adminLogsPagination"></ul>
                        </nav>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete User Modal -->
    <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-labelledby="deleteUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteUserModalLabel">Delete User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="delete-id">
                        <p>Are you sure you want to delete the user: <strong id="delete-username"></strong>?</p>
                        <p class="text-danger">This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize DataTable
            $('#usersTable').DataTable({
                responsive: true,
                order: [[0, 'asc']]
            });
            
            // Edit User
            $('.edit-btn').click(function() {
                $('#edit-id').val($(this).data('id'));
                $('#edit-username').val($(this).data('username'));
                $('#edit-role').val($(this).data('role'));
                $('#edit-email').val($(this).data('email'));
                $('#edit-phone').val($(this).data('phone'));
            });
            
            // Change Password
            $('.password-btn').click(function() {
                $('#password-id').val($(this).data('id'));
                $('#password-username').text($(this).data('username'));
                $('#new_password').val('');
                $('#confirm_password').val('');
                $('#changePasswordBtn').prop('disabled', true);
                $('#confirm_password').removeClass('is-invalid');
            });
            
            // Password confirmation validation
            $('#new_password, #confirm_password').on('input', function() {
                let newPassword = $('#new_password').val();
                let confirmPassword = $('#confirm_password').val();
                
                if (newPassword && confirmPassword) {
                    if (newPassword === confirmPassword) {
                        $('#confirm_password').removeClass('is-invalid');
                        $('#changePasswordBtn').prop('disabled', false);
                    } else {
                        $('#confirm_password').addClass('is-invalid');
                        $('#changePasswordBtn').prop('disabled', true);
                    }
                } else {
                    $('#changePasswordBtn').prop('disabled', true);
                }
            });
            
            // Delete User
            $('.delete-btn').click(function() {
                $('#delete-id').val($(this).data('id'));
                $('#delete-username').text($(this).data('username'));
            });
        });
    </script>
</body>
<script>
// Populate Supplier View Modal securely from data attributes
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.view-supplier-btn');
    if (!btn) return;

    const found = btn.getAttribute('data-supplier-found') === '1';
    const err = document.getElementById('supplierErrorAlert');
    const content = document.getElementById('supplierDetailsContent');

    if (!found) {
        if (err) err.classList.remove('d-none');
        if (content) content.classList.add('d-none');
        return;
    }

    if (err) err.classList.add('d-none');
    if (content) content.classList.remove('d-none');

    const safe = (v) => (v && v.trim()) ? v : 'N/A';

    const setText = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.textContent = safe(val);
    };

    setText('sup-username', btn.getAttribute('data-supplier-username') || '');
    setText('sup-name', btn.getAttribute('data-supplier-name') || '');
    setText('sup-email', btn.getAttribute('data-supplier-email') || '');
    setText('sup-phone', btn.getAttribute('data-supplier-phone') || '');
    setText('sup-address', btn.getAttribute('data-supplier-address') || '');
    setText('sup-status', btn.getAttribute('data-supplier-status') || '');
    setText('sup-payment', btn.getAttribute('data-supplier-payment_methods') || '');
});

// Admin Logs modal logic
(function(){
  let currentUsername = '';
  let currentPage = 1;
  let perPage = 10;

  const tbody = document.getElementById('adminLogsTableBody');
  const pagination = document.getElementById('adminLogsPagination');
  const meta = document.getElementById('adminLogsMeta');
  const actionSelect = document.getElementById('logActionType');
  const startInput = document.getElementById('logStartDate');
  const endInput = document.getElementById('logEndDate');

  function deriveStatusFromAction(action){
    const a = (action||'').toLowerCase();
    if (a.includes('success')) return 'success';
    if (a.includes('fail') || a.includes('error')) return 'failed';
    return 'info';
  }

  function summarizeDetails(details){
    try {
      if (!details) return '';
      const dj = typeof details === 'string' ? JSON.parse(details) : details;
      if (dj && typeof dj === 'object'){
        const keys = ['target_username','target_id','role','email','info'];
        const parts = [];
        for (const k of keys){ if (dj[k] !== undefined && dj[k] !== null && dj[k] !== '') parts.push(`${k}: ${dj[k]}`); }
        if (parts.length) return parts.join(' | ');
        // fallback: stringify short object
        const s = JSON.stringify(dj);
        return s.length > 120 ? s.slice(0,117)+'...' : s;
      }
      // string fallback
      return String(details);
    } catch(_){ return ''; }
  }

  function renderRows(items){
    tbody.innerHTML = '';
    if (!items || items.length === 0){
      tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No data</td></tr>';
      return;
    }
    for (const it of items){
      const tr = document.createElement('tr');
      const ts = document.createElement('td'); ts.textContent = it.timestamp || '';
      const un = document.createElement('td'); un.textContent = it.username || '';
      const ac = document.createElement('td'); ac.textContent = it.action || '';
      const comp = document.createElement('td');
      const infoParts = [];
      if (it.component) infoParts.push(`[${it.component}]`);
      const detailsSummary = summarizeDetails(it.details);
      if (detailsSummary) infoParts.push(detailsSummary);
      if (it.ip) infoParts.push('IP: '+it.ip);
      comp.textContent = infoParts.join(' ');
      const st = document.createElement('td'); st.textContent = (it.status || deriveStatusFromAction(it.action));
      tr.appendChild(ts); tr.appendChild(un); tr.appendChild(ac); tr.appendChild(comp); tr.appendChild(st);
      tbody.appendChild(tr);
    }
  }

  function renderPagination(total, page, per_page){
    pagination.innerHTML = '';
    const totalPages = Math.max(1, Math.ceil(total / per_page));
    function addPage(p, label, active=false, disabled=false){
      const li = document.createElement('li');
      li.className = 'page-item'+(active?' active':'')+(disabled?' disabled':'');
      const a = document.createElement('a');
      a.className = 'page-link'; a.href = '#'; a.textContent = label;
      a.addEventListener('click', (ev)=>{ ev.preventDefault(); if (!disabled) { currentPage = p; load(); } });
      li.appendChild(a); pagination.appendChild(li);
    }
    addPage(Math.max(1, page-1), '«', page===1, page===1);
    const windowSize = 5;
    const start = Math.max(1, page - Math.floor(windowSize/2));
    const end = Math.min(totalPages, start + windowSize - 1);
    for (let i=start;i<=end;i++) addPage(i, String(i), i===page, false);
    addPage(Math.min(totalPages, page+1), '»', page===totalPages, page===totalPages);
    meta.textContent = `Total ${total} • Page ${page} of ${totalPages}`;
  }

  async function load(){
    const params = new URLSearchParams();
    params.set('ajax','admin_logs');
    params.set('page', String(currentPage));
    params.set('per_page', String(perPage));
    if (currentUsername) params.set('username', currentUsername);
    const act = actionSelect ? actionSelect.value : '';
    const sd  = startInput ? startInput.value : '';
    const ed  = endInput ? endInput.value : '';
    if (act) params.set('action_type', act);
    if (sd) params.set('start_date', sd);
    if (ed) params.set('end_date', ed);
    try {
      const res = await fetch('users.php?'+params.toString(), {credentials:'same-origin'});
      if (!res.ok) throw new Error('HTTP '+res.status);
      const data = await res.json();
      if (!data || data.ok !== true) throw new Error('Bad payload');
      // Populate actions dropdown once
      if (actionSelect && actionSelect.options.length <= 1 && Array.isArray(data.actions)){
        for (const a of data.actions){
          const opt = document.createElement('option');
          opt.value = a; opt.textContent = a; actionSelect.appendChild(opt);
        }
      }
      renderRows(data.items||[]);
      renderPagination(data.total||0, data.page||1, data.per_page||10);
    } catch (e){
      tbody.innerHTML = '<tr><td colspan="5" class="text-center text-danger">Failed to load logs</td></tr>';
      pagination.innerHTML = '';
      meta.textContent = '';
    }
  }

  document.addEventListener('click', function(e){
    const btn = e.target.closest('.admin-logs-btn');
    if (!btn) return;
    currentUsername = btn.getAttribute('data-username') || '';
    currentPage = 1;
    // Reset filters
    if (actionSelect) actionSelect.value = '';
    if (startInput) startInput.value = '';
    if (endInput) endInput.value = '';
    load();
  });

  const applyBtn = document.getElementById('applyLogFilters');
  if (applyBtn){
    applyBtn.addEventListener('click', function(){ currentPage = 1; load(); });
  }
})();
</script>
</html>
