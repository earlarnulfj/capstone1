<?php
// Include session and models like admin page
include_once '../config/session.php';
require_once '../config/database.php';
require_once '../models/notification.php';
require_once '../models/supplier.php';

// Secure session management: ensure CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Supplier auth guard (namespaced + legacy)
if (empty($_SESSION['supplier']['user_id']) && (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supplier')) {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();
$notification = new Notification($db);
$supplier = new Supplier($db);

$supplier_id = $_SESSION['supplier']['user_id'] ?? $_SESSION['user_id'];
$supplier_name = $_SESSION['supplier']['username'] ?? $_SESSION['username'];

// Create PDO connection for sidebar compatibility
$pdo = $db;

// Helper functions (unit-testable)
function respondJson(int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function logNotificationEvent(string $event, array $context = []): void {
    try {
        $line = json_encode(['ts' => date('c'), 'event' => $event, 'context' => $context], JSON_UNESCAPED_SLASHES) . PHP_EOL;
        @file_put_contents(__DIR__ . '/../logs/notification.log', $line, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // ignore logging errors
    }
}

function validateStatus(?string $status): ?string {
    if ($status === null) return null;
    $allowed = ['pending','sent','read'];
    $status = strtolower(trim($status));
    return in_array($status, $allowed, true) ? $status : null;
}

function parseDate(?string $date): ?string {
    if (!$date) return null;
    try {
        $dt = new DateTime($date);
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
}

function ensureOwnsNotification(PDO $pdo, int $supplier_id, int $notification_id): bool {
    $stmt = $pdo->prepare("SELECT recipient_type, recipient_id FROM notifications WHERE id = :id");
    $stmt->bindValue(':id', $notification_id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row && $row['recipient_type'] === 'supplier' && (int)$row['recipient_id'] === $supplier_id;
}

function listSupplierNotifications(PDO $pdo, int $supplier_id, array $filters, int $limit, int $offset): array {
    $where = "recipient_type = :recipient_type AND recipient_id = :recipient_id";
    $params = [':recipient_type' => 'supplier', ':recipient_id' => $supplier_id];
    if (!empty($filters['type'])) { $where .= " AND type = :type"; $params[':type'] = $filters['type']; }
    if (!empty($filters['status'])) { $where .= " AND status = :status"; $params[':status'] = $filters['status']; }
    $dateField = ($filters['date_field'] ?? 'created_at') === 'sent_at' ? 'sent_at' : 'created_at';
    if (!empty($filters['from'])) { $where .= " AND {$dateField} >= :from"; $params[':from'] = $filters['from']; }
    if (!empty($filters['to'])) { $where .= " AND {$dateField} <= :to"; $params[':to'] = $filters['to']; }
    $order = strtoupper($filters['order'] ?? 'DESC');
    $order = $order === 'ASC' ? 'ASC' : 'DESC';
    $sql = "SELECT * FROM notifications WHERE {$where} ORDER BY is_read ASC, {$dateField} {$order} LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function countSupplierNotifications(PDO $pdo, int $supplier_id, array $filters): int {
    $where = "recipient_type = :recipient_type AND recipient_id = :recipient_id";
    $params = [':recipient_type' => 'supplier', ':recipient_id' => $supplier_id];
    if (!empty($filters['type'])) { $where .= " AND type = :type"; $params[':type'] = $filters['type']; }
    if (!empty($filters['status'])) { $where .= " AND status = :status"; $params[':status'] = $filters['status']; }
    $dateField = ($filters['date_field'] ?? 'created_at') === 'sent_at' ? 'sent_at' : 'created_at';
    if (!empty($filters['from'])) { $where .= " AND {$dateField} >= :from"; $params[':from'] = $filters['from']; }
    if (!empty($filters['to'])) { $where .= " AND {$dateField} <= :to"; $params[':to'] = $filters['to']; }
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM notifications WHERE {$where}");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return (int)($row['total'] ?? 0);
}

// ===== Admin order sync helpers (secure, unit-testable) =====
function isEmailConfigured(): bool {
    try {
        $cfg = @include __DIR__ . '/../config/email.php';
        if (!is_array($cfg)) return false;
        $username = $cfg['username'] ?? '';
        $password = $cfg['password'] ?? '';
        $provider = $cfg['provider'] ?? '';
        if (!$username || !$password) return false;
        if ($provider === 'gmail' && (stripos($username, 'your_email@') !== false || stripos($password, 'your_app_password') !== false)) return false;
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function sendEmailNotificationToSupplier(PDO $pdo, int $supplier_id, string $subject, string $body): bool {
    if (!isEmailConfigured()) return false;
    try {
        $stmt = $pdo->prepare("SELECT email, name, company_name FROM suppliers WHERE id = :id");
        $stmt->bindValue(':id', $supplier_id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $toEmail = $row['email'] ?? '';
        $toName = $row['company_name'] ?? ($row['name'] ?? 'Supplier');
        if (!$toEmail) return false;
        $cfg = @include __DIR__ . '/../config/email.php';
        require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
        require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';
        require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $cfg['host'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];
        $mail->SMTPSecure = ($cfg['secure'] ?? 'tls') === 'ssl' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)($cfg['port'] ?? 587);
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom($cfg['from_email'] ?? $cfg['username'], $cfg['from_name'] ?? 'Notifications');
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->isHTML(true);
        $ok = $mail->send();
        logNotificationEvent('supplier_email_sent', ['supplier_id' => $supplier_id, 'to' => $toEmail, 'ok' => (bool)$ok]);
        return (bool)$ok;
    } catch (Throwable $e) {
        logNotificationEvent('supplier_email_error', ['supplier_id' => $supplier_id, 'error' => $e->getMessage()]);
        return false;
    }
}

function getNewAdminOrdersForSupplier(PDO $pdo, int $supplier_id, ?string $since): array {
    $since = $since ?: date('Y-m-d H:i:s', strtotime('-24 hours'));
    $sql = "SELECT o.id AS order_id, o.inventory_id, o.quantity, o.order_date, o.user_id,
                   u.username AS admin_username, i.name AS item_name
            FROM orders o
            INNER JOIN users u ON u.id = o.user_id AND u.role = 'management'
            LEFT JOIN inventory i ON i.id = o.inventory_id
            WHERE o.supplier_id = :sid AND o.order_date >= :since
            ORDER BY o.order_date DESC LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':sid', $supplier_id, PDO::PARAM_INT);
    $stmt->bindValue(':since', $since);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function notificationExistsForOrder(PDO $pdo, int $supplier_id, int $order_id): bool {
    // Check if notification exists (even if deleted, we track it was created)
    // Also check a deleted_notifications table or use a soft delete approach
    $stmt = $pdo->prepare("SELECT 1 FROM notifications 
                           WHERE recipient_type = 'supplier' 
                             AND recipient_id = :sid 
                             AND order_id = :oid 
                             AND type = 'order_confirmation' 
                           LIMIT 1");
    $stmt->bindValue(':sid', $supplier_id, PDO::PARAM_INT);
    $stmt->bindValue(':oid', $order_id, PDO::PARAM_INT);
    $stmt->execute();
    return (bool)$stmt->fetchColumn();
}

function notificationWasDeletedForOrder(PDO $pdo, int $supplier_id, int $order_id): bool {
    // Check if notification was explicitly deleted by user
    // We'll use a session or database table to track deleted notifications
    // For now, check if order is older than when sync should run (only sync recent orders)
    $stmt = $pdo->prepare("SELECT order_date FROM orders WHERE id = :oid LIMIT 1");
    $stmt->bindValue(':oid', $order_id, PDO::PARAM_INT);
    $stmt->execute();
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($order && isset($order['order_date'])) {
        $orderDate = strtotime($order['order_date']);
        $now = time();
        // If order is older than 7 days, don't recreate notification if it was deleted
        // This prevents old orders from constantly creating notifications
        $daysSinceOrder = ($now - $orderDate) / (60 * 60 * 24);
        return $daysSinceOrder > 7;
    }
    
    return false;
}

function createSupplierOrderNotificationFromAdmin(Notification $notification, PDO $pdo, int $supplier_id, array $orderRow): array {
    $orderId = (int)($orderRow['order_id'] ?? 0);
    $qty     = (int)($orderRow['quantity'] ?? 0);
    $item    = trim((string)($orderRow['item_name'] ?? ''));
    $ts      = (string)($orderRow['order_date'] ?? date('Y-m-d H:i:s'));
    $admin   = trim((string)($orderRow['admin_username'] ?? 'Admin'));
    $notification->type = 'order_confirmation';
    $notification->channel = 'system';
    $notification->recipient_type = 'supplier';
    $notification->recipient_id = (int)$supplier_id;
    $notification->order_id = $orderId;
    $notification->alert_id = null;
    $notification->message = "Admin {$admin} placed order #{$orderId}: {$qty} x {$item} on {$ts}";
    $notification->status = 'sent';
    $created = $notification->createWithDuplicateCheck(true, 5);
    // Optional email
    $emailOk = false;
    if (isEmailConfigured()) {
        $subject = "New Order #{$orderId} from Admin {$admin}";
        $body    = nl2br(htmlspecialchars($notification->message, ENT_QUOTES, 'UTF-8'));
        $emailOk = sendEmailNotificationToSupplier($pdo, (int)$supplier_id, $subject, $body);
    }
    return ['db_created' => (bool)$created, 'email_sent' => (bool)$emailOk];
}

function syncAdminOrdersForSupplier(PDO $pdo, int $supplier_id, ?string $since = null, bool $silent = false): array {
    $created = 0; $emails = 0; $errors = [];
    try {
        // Only sync orders from the last 24 hours to prevent recreating old notifications
        $since = $since ?: date('Y-m-d H:i:s', strtotime('-24 hours'));
        $rows = getNewAdminOrdersForSupplier($pdo, $supplier_id, $since);
        
        foreach ($rows as $r) {
            $oid = (int)$r['order_id'];
            
            // Skip if notification already exists
            if (notificationExistsForOrder($pdo, $supplier_id, $oid)) {
                continue;
            }
            
            // Skip if notification was deleted for an old order (prevents recreation)
            if (notificationWasDeletedForOrder($pdo, $supplier_id, $oid)) {
                logNotificationEvent('supplier_sync_skipped_deleted', ['supplier_id' => $supplier_id, 'order_id' => $oid]);
                continue;
            }
            
            // Only create notification for orders from last 24 hours
            $orderDate = strtotime($r['order_date'] ?? date('Y-m-d H:i:s'));
            $hoursSinceOrder = (time() - $orderDate) / 3600;
            if ($hoursSinceOrder > 24) {
                continue; // Skip old orders
            }
            
            $res = createSupplierOrderNotificationFromAdmin(new Notification($pdo), $pdo, $supplier_id, $r);
            if ($res['db_created']) { $created++; }
            if ($res['email_sent']) { $emails++; }
            logNotificationEvent('supplier_sync_admin_order_created', ['supplier_id' => $supplier_id, 'order_id' => $oid, 'result' => $res]);
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
        logNotificationEvent('supplier_sync_admin_orders_error', ['supplier_id' => $supplier_id, 'error' => $e->getMessage()]);
        if (!$silent) throw $e;
    }
    return ['created' => $created, 'emails' => $emails, 'errors' => $errors];
}

// API detection and routing (JSON)
$acceptJson = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
$isApi = isset($_GET['api']) || isset($_POST['api']) || $acceptJson;
if ($isApi) {
    $action = $_POST['action'] ?? $_GET['action'] ?? 'list';
    // Optional CSRF for state-changing actions
    $requiresCsrf = in_array($action, ['create_notification','update_status','delete_notification','delete_multiple','mark_read','mark_all_read','delete_expired','sync_admin_orders'], true);
    if ($requiresCsrf) {
        $csrf = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
        if (!$csrf || !hash_equals($_SESSION['csrf_token'], $csrf)) {
            respondJson(403, ['error' => 'Invalid CSRF token']);
        }
    }
    try {
        switch ($action) {
            case 'sync_admin_orders': {
                $since = $_GET['since'] ?? $_POST['since'] ?? null;
                $result = syncAdminOrdersForSupplier($pdo, (int)$supplier_id, $since);
                respondJson(201, ['synced' => $result]);
                break;
            }
            case 'create_notification': {
                $type = strtolower(trim((string)($_POST['type'] ?? '')));
                $message = trim((string)($_POST['message'] ?? ''));
                $channel = trim((string)($_POST['channel'] ?? 'system'));
                $order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : null;
                if ($type === '' || $message === '') {
                    respondJson(422, ['error' => 'type and message are required']);
                }
                $notification->type = htmlspecialchars($type, ENT_QUOTES, 'UTF-8');
                $notification->channel = htmlspecialchars($channel, ENT_QUOTES, 'UTF-8');
                $notification->recipient_type = 'supplier';
                $notification->recipient_id = (int)$supplier_id;
                $notification->order_id = $order_id ?: null;
                $notification->alert_id = null;
                $notification->message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
                $notification->status = 'sent';
                $created = $notification->createWithDuplicateCheck(true, 5);
                logNotificationEvent('supplier_create_notification', ['supplier_id' => (int)$supplier_id, 'type' => $type, 'result' => $created]);
                if ($created === false) {
                    respondJson(200, ['duplicate_prevented' => true]);
                    break;
                }
                respondJson(201, ['success' => true]);
                break;
            }
            case 'list': {
                $filters = [];
                $filters['type'] = isset($_GET['type']) ? strtolower(trim((string)$_GET['type'])) : null;
                $filters['status'] = validateStatus($_GET['status'] ?? null);
                $filters['from'] = parseDate($_GET['from'] ?? null);
                $filters['to'] = parseDate($_GET['to'] ?? null);
                $filters['date_field'] = ($_GET['date_field'] ?? 'created_at') === 'sent_at' ? 'sent_at' : 'created_at';
                $filters['order'] = strtoupper($_GET['order'] ?? 'DESC');
                $page = (int)($_GET['page'] ?? 1);
                $limit = (int)($_GET['limit'] ?? 20);
                $offset = ($page - 1) * $limit;
                $data = listSupplierNotifications($pdo, (int)$supplier_id, $filters, $limit, $offset);
                $total = countSupplierNotifications($pdo, (int)$supplier_id, $filters);
                respondJson(200, [
                    'data' => $data,
                    'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total]
                ]);
                break;
            }
            case 'update_status': {
                $id = (int)($_POST['id'] ?? 0);
                $newStatus = validateStatus($_POST['status'] ?? null);
                if (!$id || !$newStatus) { respondJson(422, ['error' => 'id and valid status required']); }
                if (!ensureOwnsNotification($pdo, (int)$supplier_id, $id)) { respondJson(404, ['error' => 'Notification not found']); }
                if ($newStatus === 'read') {
                    $stmt = $pdo->prepare("UPDATE notifications SET status = 'read', is_read = 1, read_at = NOW() WHERE id = :id");
                } else {
                    $stmt = $pdo->prepare("UPDATE notifications SET status = :status WHERE id = :id");
                    $stmt->bindValue(':status', $newStatus);
                }
                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $ok = $stmt->execute();
                logNotificationEvent('supplier_update_status', ['supplier_id' => (int)$supplier_id, 'id' => $id, 'status' => $newStatus, 'ok' => $ok]);
                respondJson($ok ? 200 : 500, ['success' => (bool)$ok]);
                break;
            }
            case 'mark_read': {
                $id = (int)($_POST['notification_id'] ?? 0);
                if (!$id || !ensureOwnsNotification($pdo, (int)$supplier_id, $id)) { respondJson(404, ['error' => 'Notification not found']); }
                $ok = $notification->markAsRead($id);
                logNotificationEvent('supplier_mark_read', ['supplier_id' => (int)$supplier_id, 'id' => $id, 'ok' => $ok]);
                respondJson($ok ? 200 : 500, ['success' => (bool)$ok]);
                break;
            }
            case 'mark_all_read': {
                $ok = $notification->markAllAsRead('supplier', (int)$supplier_id);
                logNotificationEvent('supplier_mark_all_read', ['supplier_id' => (int)$supplier_id, 'ok' => $ok]);
                respondJson($ok ? 200 : 500, ['success' => (bool)$ok]);
                break;
            }
            case 'delete_notification': {
                $id = (int)($_POST['notification_id'] ?? 0);
                if (!$id || !ensureOwnsNotification($pdo, (int)$supplier_id, $id)) { respondJson(404, ['error' => 'Notification not found']); }
                $ok = $notification->deleteNotification($id);
                logNotificationEvent('supplier_delete_notification', ['supplier_id' => (int)$supplier_id, 'id' => $id, 'ok' => $ok]);
                respondJson($ok ? 200 : 500, ['success' => (bool)$ok]);
                break;
            }
            case 'delete_multiple': {
                $idsJson = $_POST['ids'] ?? '[]';
                $ids = json_decode($idsJson, true);
                
                if (empty($ids) || !is_array($ids)) {
                    respondJson(422, ['error' => 'No notification IDs provided']);
                }
                
                $deleted = 0;
                $errors = [];
                
                foreach ($ids as $id) {
                    $id = (int)$id;
                    if (!$id) continue;
                    
                    // Verify ownership
                    if (!ensureOwnsNotification($pdo, (int)$supplier_id, $id)) {
                        $errors[] = "Notification #{$id} not found or unauthorized";
                        continue;
                    }
                    
                    $ok = $notification->deleteNotification($id);
                    if ($ok) {
                        $deleted++;
                    } else {
                        $errors[] = "Failed to delete notification #{$id}";
                    }
                }
                
                logNotificationEvent('supplier_delete_multiple', [
                    'supplier_id' => (int)$supplier_id, 
                    'count' => count($ids), 
                    'deleted' => $deleted,
                    'errors' => $errors
                ]);
                
                respondJson(200, [
                    'success' => $deleted > 0,
                    'deleted' => $deleted,
                    'total' => count($ids),
                    'errors' => $errors
                ]);
            }
            case 'get_count': {
                $count = (int)$notification->getUnreadCount('supplier', (int)$supplier_id);
                respondJson(200, ['count' => $count]);
                break;
            }
            case 'delete_expired': {
                $days = (int)($_POST['days'] ?? $_GET['days'] ?? 30);
                $before = date('Y-m-d H:i:s', strtotime("-{$days} days"));
                $stmt = $pdo->prepare("DELETE FROM notifications WHERE recipient_type = 'supplier' AND recipient_id = :rid AND created_at < :before");
                $stmt->bindValue(':rid', (int)$supplier_id, PDO::PARAM_INT);
                $stmt->bindValue(':before', $before);
                $stmt->execute();
                $deleted = $stmt->rowCount();
                logNotificationEvent('supplier_delete_expired', ['supplier_id' => (int)$supplier_id, 'days' => $days, 'deleted' => $deleted]);
                respondJson(200, ['deleted' => $deleted]);
                break;
            }
            default:
                respondJson(400, ['error' => 'Unknown action']);
        }
    } catch (Throwable $e) {
        logNotificationEvent('supplier_api_error', ['message' => $e->getMessage()]);
        respondJson(500, ['error' => 'Internal Server Error']);
    }
}

// Handle form submissions (UI)
$message = '';
$message_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'mark_read':
            $notificationId = $_POST['notification_id'] ?? '';
            if ($notificationId && ensureOwnsNotification($pdo, (int)$supplier_id, (int)$notificationId)) {
                $result = $notification->markAsRead((int)$notificationId);
                $message = $result ? "Notification marked as read" : "Failed to mark notification as read";
                $message_type = $result ? 'success' : 'danger';
                logNotificationEvent('supplier_ui_mark_read', ['supplier_id' => (int)$supplier_id, 'id' => (int)$notificationId, 'ok' => (bool)$result]);
            }
            break;
        case 'mark_all_read':
            $result = $notification->markAllAsRead('supplier', (int)$supplier_id);
            $message = $result ? "All notifications marked as read" : "Error marking all notifications as read";
            $message_type = $result ? 'success' : 'danger';
            logNotificationEvent('supplier_ui_mark_all_read', ['supplier_id' => (int)$supplier_id, 'ok' => (bool)$result]);
            break;
        case 'delete_notification':
            $notificationId = $_POST['notification_id'] ?? '';
            if ($notificationId && ensureOwnsNotification($pdo, (int)$supplier_id, (int)$notificationId)) {
                $result = $notification->deleteNotification((int)$notificationId);
                $message = $result ? "Notification deleted successfully" : "Failed to delete notification";
                $message_type = $result ? 'success' : 'danger';
                logNotificationEvent('supplier_ui_delete', ['supplier_id' => (int)$supplier_id, 'id' => (int)$notificationId, 'ok' => (bool)$result]);
            }
            break;
    }
}

// Pagination & filter-aware data
$page = (int)($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;
$filters = [];
$filters['type'] = isset($_GET['type']) ? strtolower(trim((string)$_GET['type'])) : null;
$filters['status'] = validateStatus($_GET['status'] ?? null);
$filters['from'] = parseDate($_GET['from'] ?? null);
$filters['to'] = parseDate($_GET['to'] ?? null);
$filters['date_field'] = ($_GET['date_field'] ?? 'created_at') === 'sent_at' ? 'sent_at' : 'created_at';
$filters['order'] = strtoupper($_GET['order'] ?? 'DESC');

// Get last viewed timestamp from session (BEFORE updating it)
$lastViewedKey = 'notifications_last_viewed_' . $supplier_id;
$lastViewedTimestamp = $_SESSION[$lastViewedKey] ?? null;

// Only trigger sync on initial page load AND only if explicitly requested via session flag
// This prevents creating duplicate notifications on every page view/refresh
$shouldSync = isset($_SESSION['supplier_notifications_sync_needed']) && $_SESSION['supplier_notifications_sync_needed'] === true;
if ($shouldSync && empty($_GET['page']) && empty($_GET['type']) && empty($_GET['status']) && empty($_POST['action']) && empty($_GET['api'])) {
    try {
        syncAdminOrdersForSupplier($pdo, (int)$supplier_id, null, true);
        // Clear the flag after syncing
        unset($_SESSION['supplier_notifications_sync_needed']);
    } catch (Throwable $e) {
        // already logged
    }
} else {
    // Don't sync automatically - only when explicitly needed (e.g., after a new order is placed)
    // This prevents deleted notifications from being recreated
}

$notifications = listSupplierNotifications($pdo, (int)$supplier_id, $filters, $limit, $offset);
$totalNotifications = countSupplierNotifications($pdo, (int)$supplier_id, $filters);
$totalPages = (int)ceil($totalNotifications / $limit);

$unreadCount = (int)$notification->getUnreadCount('supplier', (int)$supplier_id);
$recentCount = (int)$notification->getRecentNotificationCount('supplier', (int)$supplier_id);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token']) ?>" />
    <title>Notifications - Supplier Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" />
    <link rel="stylesheet" href="../assets/css/style.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="../admin/assets/js/notification-badge.js"></script>
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-dark: #1d4ed8;
            --secondary-color: #64748b;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --light-bg: #f8fafc;
            --card-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --card-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        body { min-height: 100vh; }
        .main-content { background: var(--light-bg); min-height: 100vh; border-radius: 20px 0 0 0; margin-left: 0; }
        .sidebar { min-height: 100vh; box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1); }

        .welcome-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }
        .welcome-header::before {
            content: '';
            position: absolute;
            top: 0; right: 0;
            width: 100px; height: 100px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(30px, -30px);
        }

        .stat-card { background: white; border: none; border-radius: 15px; box-shadow: var(--card-shadow); transition: all 0.3s ease; overflow: hidden; position: relative; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: var(--card-shadow-hover); }
        .stat-card .card-body { padding: 1.5rem; }

        .stat-icon { width: 60px; height: 60px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: white; margin-bottom: 1rem; }
        .stat-number { font-size: 2rem; font-weight: 700; color: #1e293b; margin-bottom: 0.5rem; }
        .stat-label { color: var(--secondary-color); font-weight: 500; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.5px; }

        .bg-primary-gradient { background: linear-gradient(135deg, var(--primary-color), var(--primary-dark)); }
        .bg-success-gradient { background: linear-gradient(135deg, var(--success-color), #059669); }
        .bg-warning-gradient { background: linear-gradient(135deg, var(--warning-color), #d97706); }
        .bg-danger-gradient { background: linear-gradient(135deg, var(--danger-color), #dc2626); }
        .bg-info-gradient { background: linear-gradient(135deg, #17a2b8, #138496); }

        /* Notification list styles */
        .notification-item { border-left: 4px solid #dee2e6; transition: all 0.3s ease; cursor: pointer; }
        .notification-item.unread { border-left-color: #6c757d; background-color: #e9ecef; color: #6c757d; }
        .notification-item.unread .text-primary { color: #6c757d !important; }
        .notification-item:hover { background-color: #f8f9fa; transform: translateX(2px); }
        .notification-item:active { transform: translateX(2px) scale(0.99); }
        .notification-meta { font-size: 0.875rem; color: #6c757d; }
        .notification-actions { opacity: 0; transition: opacity 0.3s ease; }
        .notification-item:hover .notification-actions { opacity: 1; }
        .notification-icon-wrapper { position: relative; display: inline-block; }
        
        /* Checkbox styling */
        .notification-checkbox {
            cursor: pointer;
            width: 18px;
            height: 18px;
            margin-top: 2px;
        }
        .notification-item:hover .notification-checkbox {
            opacity: 1;
        }
        .notification-checkbox:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        /* Responsive tweaks */
        @media (max-width: 576px) {
            .stat-number { font-size: 1.75rem; }
            .stat-icon { width: 50px; height: 50px; font-size: 1.25rem; }
        }
    </style>
</head>
<body>
<?php include_once 'includes/header.php'; ?>
<div class="container-fluid">
  <div class="row">
    <?php include_once 'includes/sidebar.php'; ?>

    <main class="col-md-9 ms-sm-auto col-lg-10 main-content p-4">
      <div class="welcome-header">
        <div class="row align-items-center">
          <div class="col">
            <h1 class="h2 mb-2">
              <i class="bi bi-bell me-2"></i>Notifications
              <?php if ($unreadCount > 0): ?>
                <span id="main-notification-badge" class="badge bg-light text-dark ms-2"><?= (int)$unreadCount ?></span>
              <?php endif; ?>
            </h1>
          </div>
          <div class="col-auto">
            <div class="d-flex gap-2">
              <button type="button" id="markAllReadBtn" class="btn btn-light btn-sm">
                <i class="bi bi-check-all me-1"></i>Mark All Read
              </button>
              <button type="button" id="deleteSelectedBtn" class="btn btn-danger btn-sm" style="display: none;">
                <i class="bi bi-trash me-1"></i>Delete Selected (<span id="selectedCount">0</span>)
              </button>
            </div>
          </div>
        </div>
      </div>

      <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
          <?= htmlspecialchars($message) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <!-- Statistics Cards: Notifications -->
      <div class="row mb-4">
        <div class="col-xl-4 col-md-6 mb-4">
          <div class="card stat-card h-100">
            <div class="card-body">
              <div class="stat-icon bg-primary-gradient">
                <i class="bi bi-bell"></i>
              </div>
              <div class="stat-number"><?= (int)$totalNotifications ?></div>
              <div class="stat-label">Total Notifications</div>
            </div>
          </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-4">
          <div class="card stat-card h-100">
            <div class="card-body">
              <div class="stat-icon bg-warning-gradient">
                <i class="bi bi-envelope"></i>
              </div>
              <div class="stat-number"><?= (int)$unreadCount ?></div>
              <div class="stat-label">Unread</div>
            </div>
          </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-4">
          <div class="card stat-card h-100">
            <div class="card-body">
              <div class="stat-icon bg-success-gradient">
                <i class="bi bi-clock-history"></i>
              </div>
              <div class="stat-number"><?= (int)$recentCount ?></div>
              <div class="stat-label">Recent</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Notifications List (mirror admin) -->
      <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-3">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="selectAllNotifications" style="cursor: pointer;">
              <label class="form-check-label" for="selectAllNotifications" style="cursor: pointer; font-weight: 500;">
                <strong>Select All</strong>
              </label>
            </div>
            <h6 class="m-0 font-weight-bold text-primary mb-0">All Notifications</h6>
          </div>
          <span class="badge badge-primary"><?= $totalNotifications ?> total</span>
        </div>
        <div class="card-body p-0">
          <?php if (empty($notifications)): ?>
            <div class="text-center py-5">
              <i class="bi bi-bell-slash text-muted" style="font-size: 3rem;"></i>
              <p class="text-muted mt-3">No notifications found</p>
            </div>
          <?php else: ?>
            <?php foreach ($notifications as $notif): ?>
              <?php
                $createdAt = isset($notif['sent_at']) && $notif['sent_at'] ? $notif['sent_at'] : ($notif['created_at'] ?? null);
                $statusValue = $notif['status'] ?? 'unread';
                $isReadValue = isset($notif['is_read']) ? (int)$notif['is_read'] : 0;
                $isUnread = ($isReadValue == 0 && $statusValue !== 'read');
                $isNew = false;
                if ($isUnread && $isReadValue == 0) {
                    if ($lastViewedTimestamp && $createdAt) {
                        $isNew = strtotime($createdAt) > strtotime($lastViewedTimestamp);
                    } else if ($createdAt) {
                        $minutesSinceCreation = (time() - strtotime($createdAt)) / 60;
                        $isNew = ($minutesSinceCreation <= 5);
                    }
                }
              ?>
              <div class="notification-item p-3 border-bottom <?= $isUnread ? 'unread' : '' ?><?= $isNew ? ' notification-new' : '' ?>" role="button" tabindex="0"
                   data-id="<?= (int)$notif['id'] ?>"
                   data-type="<?= htmlspecialchars($notif['type'] ?? '', ENT_QUOTES) ?>"
                   data-message="<?= htmlspecialchars($notif['message'] ?? '', ENT_QUOTES) ?>"
                   data-timestamp="<?= htmlspecialchars(($notif['created_at'] ?? $notif['sent_at'] ?? ''), ENT_QUOTES) ?>"
                   data-channel="<?= htmlspecialchars($notif['channel'] ?? '', ENT_QUOTES) ?>"
                   data-read="<?= !empty($notif['is_read']) ? '1' : '0' ?>">
                <div class="d-flex justify-content-between align-items-start">
                  <div class="d-flex align-items-start me-2">
                    <div class="form-check mt-1">
                      <input class="form-check-input notification-checkbox" type="checkbox" 
                             value="<?= (int)$notif['id'] ?>" 
                             id="notif-<?= (int)$notif['id'] ?>"
                             data-notification-id="<?= (int)$notif['id'] ?>"
                             onclick="event.stopPropagation();">
                    </div>
                  </div>
                  <div class="flex-grow-1">
                    <div class="d-flex align-items-center mb-2">
                      <?php
                        $iconClass = 'bi-bell';
                        $badgeClass = 'bg-secondary';
                        switch ($notif['type'] ?? '') {
                          case 'delivery_arrival':
                          case 'delivery_status_update':
                            $iconClass = 'bi-truck';
                            $badgeClass = 'bg-info';
                            break;
                          case 'order_status_update':
                          case 'order_confirmation':
                            $iconClass = 'bi-cart-check';
                            $badgeClass = 'bg-success';
                            break;
                          case 'low_stock':
                            $iconClass = 'bi-exclamation-triangle';
                            $badgeClass = 'bg-warning';
                            break;
                          case 'delivery_created':
                            $iconClass = 'bi-plus-circle';
                            $badgeClass = 'bg-primary';
                            break;
                          case 'supplier_message':
                            $iconClass = 'bi-chat-dots';
                            $badgeClass = 'bg-primary';
                            break;
                        }
                      ?>
                      <div class="notification-icon-wrapper me-2">
                        <i class="bi <?= $iconClass ?> text-primary"></i>
                      </div>
                      <span class="badge <?= $badgeClass ?> me-2"><?= ucfirst(str_replace('_', ' ', ($notif['type'] ?? 'Notification'))) ?></span>
                      <?php if ($isNew): ?>
                        <span class="badge bg-danger">New</span>
                      <?php endif; ?>
                    </div>
                    <p class="mb-2"><?= htmlspecialchars($notif['message'] ?? '') ?></p>
                    <div class="notification-meta">
                      <i class="bi bi-clock me-1"></i>
                      <?= isset($notif['created_at']) ? date('M j, Y g:i A', strtotime($notif['created_at'])) : (isset($notif['sent_at']) ? date('M j, Y g:i A', strtotime($notif['sent_at'])) : '') ?>
                      <?php if (!empty($notif['channel'])): ?>
                        <span class="ms-2">
                          <i class="bi bi-broadcast me-1"></i>
                          <?= ucfirst($notif['channel']) ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="notification-actions ms-3">
                    <?php if (!($notif['is_read'] ?? ($notif['status'] ?? '') === 'read')): ?>
                      <button type="button" class="btn btn-sm btn-outline-success me-1 btn-mark-read" data-id="<?= (int)$notif['id'] ?>" title="Mark as read">
                        <i class="bi bi-check"></i>
                      </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-outline-danger btn-delete" data-id="<?= (int)$notif['id'] ?>" title="Delete">
                      <i class="bi bi-trash"></i>
                    </button>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
        <nav aria-label="Notifications pagination" class="mt-4">
          <ul class="pagination justify-content-center">
            <?php if ($page > 1): ?>
              <li class="page-item">
                <a class="page-link" href="?page=<?= $page - 1 ?>">
                  <i class="bi bi-chevron-left"></i> Previous
                </a>
              </li>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
              <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
              </li>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
              <li class="page-item">
                <a class="page-link" href="?page=<?= $page + 1 ?>">
                  Next <i class="bi bi-chevron-right"></i>
                </a>
              </li>
            <?php endif; ?>
          </ul>
        </nav>
      <?php endif; ?>

    </main>
  </div>
</div>

<?php $_SESSION[$lastViewedKey] = date('Y-m-d H:i:s'); ?>

<script>
// Function to update notification counter (compatible)
function updateNotificationCounter() {
  // Update the badge in the header if it exists
  const badge = document.getElementById('main-notification-badge');
  if (badge) {
    // Count remaining unread notifications
    const unreadCount = document.querySelectorAll('.notification-item.unread').length;
    if (unreadCount > 0) {
      badge.textContent = unreadCount;
      badge.style.display = 'inline-block';
    } else {
      badge.style.display = 'none';
    }
  }
  
  // If a global badge manager exists, use it; otherwise no-op
  if (window.notificationBadgeManager && typeof window.notificationBadgeManager.forceUpdate === 'function') {
    window.notificationBadgeManager.forceUpdate();
  }
}

// Mark as read (mirror admin)
const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

function markAsRead(notificationId, element) {
  const formData = new FormData();
  formData.append('action', 'mark_read');
  formData.append('notification_id', notificationId);
  formData.append('csrf_token', csrfToken);

  fetch('notifications.php?api=1', { method: 'POST', body: formData })
    .then(response => {
      if (!response.ok) {
        throw new Error('Network response was not ok');
      }
      return response.json();
    })
    .then(data => {
      if (data.success) {
        const notificationItem = element.closest('.notification-item');
        if (notificationItem) {
          notificationItem.classList.remove('unread');
          element.style.display = 'none';
          const newBadge = notificationItem.querySelector('.badge.bg-danger');
          if (newBadge && newBadge.textContent === 'New') newBadge.remove();
        }
        updateNotificationCounter();
        showMessage('Notification marked as read', 'success');
      } else {
        showMessage(data.error || 'Failed to mark notification as read', 'danger');
      }
    })
    .catch(error => {
      console.error('Error marking notification as read:', error);
      showMessage('Error marking notification as read', 'danger');
    });
}

// Delete notification (mirror admin)
function deleteNotification(notificationId, element) {
  if (confirm('Are you sure you want to delete this notification?')) {
    const formData = new FormData();
    formData.append('action', 'delete_notification');
    formData.append('notification_id', notificationId);
    formData.append('csrf_token', csrfToken);

    fetch('notifications.php?api=1', { method: 'POST', body: formData })
      .then(response => {
        if (!response.ok) {
          throw new Error('Network response was not ok');
        }
        return response.json();
      })
      .then(data => {
        if (data.success) {
          const notificationItem = element.closest('.notification-item');
          if (notificationItem) {
            // Check if deleted notification was unread
            const wasUnread = notificationItem.classList.contains('unread');
            
            // Add fade out animation
            notificationItem.style.transition = 'opacity 0.3s ease';
            notificationItem.style.opacity = '0';
            setTimeout(() => {
              notificationItem.remove();
              
              // Force update notification count from server
              fetchUpdatedNotificationCount();
            }, 300);
          } else {
            // If element not found, reload page to get fresh count
            location.reload();
            return;
          }
          showMessage('Notification deleted successfully', 'success');
        } else {
          showMessage(data.error || 'Failed to delete notification', 'danger');
        }
      })
      .catch(error => {
        console.error('Error deleting notification:', error);
        showMessage('Error deleting notification. Please try again.', 'danger');
      });
  }
}

// Fetch updated notification count from server
function fetchUpdatedNotificationCount() {
  fetch('notifications.php?api=1&action=get_count&t=' + Date.now(), {
    method: 'GET',
    headers: {
      'Content-Type': 'application/json',
      'Cache-Control': 'no-cache',
      'Pragma': 'no-cache'
    },
    credentials: 'same-origin',
    cache: 'no-store'
  })
    .then(response => {
      if (!response.ok) {
        throw new Error('Network response was not ok');
      }
      return response.json();
    })
    .then(data => {
      const count = data.count || 0;
      
      // Update badge in header
      const badge = document.getElementById('main-notification-badge');
      if (badge) {
        if (count > 0) {
          badge.textContent = count;
          badge.style.display = 'inline-block';
        } else {
          badge.style.display = 'none';
          badge.textContent = '0';
        }
      }
      
      // Update notification counter using badge manager if available
      if (window.notificationBadgeManager) {
        window.notificationBadgeManager.forceUpdate();
      } else {
        // Fallback to DOM count
        updateNotificationCounter();
      }
    })
    .catch(error => {
      console.error('Error fetching notification count:', error);
      // Fallback to DOM count
      updateNotificationCounter();
    });
}

// Mark all as read (mirror admin)
function markAllAsRead() {
  if (confirm('Are you sure you want to mark all notifications as read?')) {
    const formData = new FormData();
    formData.append('action', 'mark_all_read');
    formData.append('csrf_token', csrfToken);

    fetch('notifications.php?api=1', { method: 'POST', body: formData })
      .then(response => {
        if (!response.ok) {
          throw new Error('Network response was not ok');
        }
        return response.json();
      })
      .then(data => {
        if (data.success) {
          document.querySelectorAll('.notification-item.unread').forEach(item => {
            item.classList.remove('unread');
            const markReadBtn = item.querySelector('.btn-mark-read');
            if (markReadBtn) markReadBtn.style.display = 'none';
            const newBadges = item.querySelectorAll('.badge.bg-danger');
            newBadges.forEach(badge => { if (badge.textContent === 'New') badge.remove(); });
          });
          
          // Fetch fresh count from server to ensure accuracy
          fetchUpdatedNotificationCount();
          
          showMessage('All notifications marked as read', 'success');
        } else {
          showMessage(data.error || 'Failed to mark all notifications as read', 'danger');
        }
      })
      .catch(error => {
        console.error('Error marking all notifications as read:', error);
        showMessage('Error marking all notifications as read', 'danger');
      });
  }
}

// Show message helper (mirror admin)
function showMessage(message, type) {
  const alertDiv = document.createElement('div');
  alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
  alertDiv.innerHTML = `${message}<button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\"></button>`;
  const container = document.querySelector('.container-fluid');
  container.insertBefore(alertDiv, container.firstChild);
  setTimeout(() => { alertDiv.remove(); }, 5000);
}

function setupNotificationClickHandlers() {
  document.querySelectorAll('.notification-item').forEach(item => {
    item.addEventListener('click', (e) => {
      // Don't open modal if clicking on checkbox or buttons
      if (e.target.closest('.notification-checkbox') || e.target.closest('.notification-actions')) {
        return;
      }
      openNotificationModal(item);
    });
    item.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') { 
        e.preventDefault(); 
        if (!e.target.closest('.notification-checkbox') && !e.target.closest('.notification-actions')) {
          openNotificationModal(item); 
        }
      }
    });
  });
  document.querySelectorAll('.btn-mark-read').forEach(btn => {
    btn.addEventListener('click', (e) => { e.stopPropagation(); const id = btn.getAttribute('data-id'); markAsRead(id, btn); });
  });
  document.querySelectorAll('.btn-delete').forEach(btn => {
    btn.addEventListener('click', (e) => { e.stopPropagation(); const id = btn.getAttribute('data-id'); deleteNotification(id, btn); });
  });
}

function openNotificationModal(item) {
  try {
    const id = item.getAttribute('data-id');
    const type = item.getAttribute('data-type') || 'Notification';
    const message = item.getAttribute('data-message') || '';
    const timestamp = item.getAttribute('data-timestamp') || '';
    const channel = item.getAttribute('data-channel') || '';
    const isRead = item.getAttribute('data-read') === '1';

    document.getElementById('notificationModalTitle').textContent = type.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    const typeBadge = document.getElementById('notificationTypeBadge');
    typeBadge.textContent = type.replace(/_/g, ' ');
    typeBadge.className = 'badge bg-secondary me-2';
    document.getElementById('notificationMessage').textContent = message;
    document.getElementById('notificationTimestamp').textContent = timestamp ? new Date(timestamp).toLocaleString() : '';
    document.getElementById('notificationChannel').textContent = channel ? `Channel: ${channel}` : '';

    const modalEl = document.getElementById('notificationDetailModal');
    const modal = new bootstrap.Modal(modalEl, { backdrop: true });
    modal.show();

    if (!isRead) {
      const markBtn = item.querySelector('.btn-mark-read');
      if (markBtn) {
        markAsRead(id, markBtn);
      } else {
        item.classList.remove('unread');
        const newBadge = item.querySelector('.badge.bg-danger');
        if (newBadge) newBadge.remove();
        updateNotificationCounter();
      }
    }
  } catch (err) {
    console.error('Failed to open notification modal:', err);
    showMessage('Error loading notification details', 'danger');
  }
}

// Multi-select functionality
let selectedNotifications = new Set();

// Update Select All checkbox state based on individual selections
function updateSelectAllState() {
  const selectAllCheckbox = document.getElementById('selectAllNotifications');
  if (!selectAllCheckbox) return;
  
  const allCheckboxes = document.querySelectorAll('.notification-checkbox');
  const checkedCount = Array.from(allCheckboxes).filter(cb => cb.checked).length;
  
  // Check "Select All" if all checkboxes are checked, uncheck if none or some are checked
  if (allCheckboxes.length > 0) {
    selectAllCheckbox.checked = checkedCount === allCheckboxes.length;
    selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < allCheckboxes.length;
  }
}

// Update delete button visibility and count
function updateDeleteButton() {
  const count = selectedNotifications.size;
  const selectedCountEl = document.getElementById('selectedCount');
  const deleteBtn = document.getElementById('deleteSelectedBtn');
  
  if (selectedCountEl) {
    selectedCountEl.textContent = count;
  }
  if (deleteBtn) {
    deleteBtn.style.display = count > 0 ? 'inline-block' : 'none';
  }
  
  // Update select all state
  updateSelectAllState();
}

function deleteSelectedNotifications() {
  const selectedIds = Array.from(selectedNotifications);
  
  if (selectedIds.length === 0) {
    showMessage('Please select at least one notification to delete', 'warning');
    return;
  }
  
  if (!confirm(`Are you sure you want to delete ${selectedIds.length} notification(s)?`)) {
    return;
  }
  
  const formData = new FormData();
  formData.append('action', 'delete_multiple');
  formData.append('ids', JSON.stringify(selectedIds));
  formData.append('csrf_token', csrfToken);
  
  fetch('notifications.php?api=1', { method: 'POST', body: formData })
    .then(response => {
      if (!response.ok) {
        throw new Error('Network response was not ok');
      }
      return response.json();
    })
    .then(data => {
      if (data.success) {
        // Remove deleted notifications from UI
        selectedIds.forEach(id => {
          const notificationItem = document.querySelector(`.notification-item[data-id="${id}"]`);
          if (notificationItem) {
            notificationItem.style.transition = 'opacity 0.3s ease';
            notificationItem.style.opacity = '0';
            setTimeout(() => {
              notificationItem.remove();
            }, 300);
          }
        });
        
        // Clear selection
        selectedNotifications.clear();
        
        // Reset select all checkbox
        const selectAllCheckbox = document.getElementById('selectAllNotifications');
        if (selectAllCheckbox) {
          selectAllCheckbox.checked = false;
          selectAllCheckbox.indeterminate = false;
        }
        
        updateDeleteButton();
        
        // Update count
        fetchUpdatedNotificationCount();
        
        showMessage(`${data.deleted} notification(s) deleted successfully`, 'success');
      } else {
        showMessage(data.errors ? data.errors.join(', ') : 'Failed to delete notifications', 'danger');
      }
    })
    .catch(error => {
      console.error('Error deleting notifications:', error);
      showMessage('Error deleting notifications. Please try again.', 'danger');
    });
}

document.addEventListener('DOMContentLoaded', function() {
  setupNotificationClickHandlers();
  const alerts = document.querySelectorAll('.alert');
  alerts.forEach(function(alert) {
    setTimeout(function() { alert.style.opacity = '0'; setTimeout(function() { alert.remove(); }, 300); }, 5000);
  });
  const markAllBtn = document.getElementById('markAllReadBtn');
  if (markAllBtn) { markAllBtn.addEventListener('click', markAllAsRead); }
  
  const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
  if (deleteSelectedBtn) {
    deleteSelectedBtn.addEventListener('click', deleteSelectedNotifications);
  }
  
  // Select All checkbox event handler
  const selectAllCheckbox = document.getElementById('selectAllNotifications');
  if (selectAllCheckbox) {
    selectAllCheckbox.addEventListener('change', function(e) {
      e.stopPropagation();
      const isChecked = this.checked;
      const allCheckboxes = document.querySelectorAll('.notification-checkbox');
      
      // Select/deselect all checkboxes
      allCheckboxes.forEach(function(checkbox) {
        checkbox.checked = isChecked;
        if (isChecked) {
          selectedNotifications.add(parseInt(checkbox.value));
        } else {
          selectedNotifications.delete(parseInt(checkbox.value));
        }
      });
      
      // Clear indeterminate state
      this.indeterminate = false;
      
      updateDeleteButton();
    });
  }
  
  // Individual checkbox change - use proper event delegation
  document.addEventListener('change', function(e) {
    if (e.target && e.target.classList.contains('notification-checkbox')) {
      e.stopPropagation();
      const checkbox = e.target;
      if (checkbox.checked) {
        selectedNotifications.add(parseInt(checkbox.value));
      } else {
        selectedNotifications.delete(parseInt(checkbox.value));
      }
      updateDeleteButton();
    }
  });
  
  // Initialize select all state on page load
  updateSelectAllState();
  
  // Override badge manager to use supplier endpoint if it exists
  if (window.notificationBadgeManager) {
    // Store original method
    const originalUpdate = window.notificationBadgeManager.updateNotificationBadge.bind(window.notificationBadgeManager);
    
    window.notificationBadgeManager.updateNotificationBadge = async function() {
      if (this.isUpdating) {
        return;
      }
      this.isUpdating = true;
      try {
        const response = await fetch('notifications.php?api=1&action=get_count&t=' + Date.now(), {
          method: 'GET',
          headers: {
            'Content-Type': 'application/json',
            'Cache-Control': 'no-cache',
            'Pragma': 'no-cache'
          },
          credentials: 'same-origin',
          cache: 'no-store'
        });
        
        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        const count = data.count || 0;
        this.updateBadgeElements(count);
        
        // Also update the header badge directly
        const badge = document.getElementById('main-notification-badge');
        if (badge) {
          if (count > 0) {
            badge.textContent = count;
            badge.style.display = 'inline-block';
          } else {
            badge.style.display = 'none';
            badge.textContent = '0';
          }
        }
      } catch (error) {
        console.error('Error updating notification badge:', error);
      } finally {
        this.isUpdating = false;
      }
    };
    
    // Force immediate update with correct count on page load
    setTimeout(() => {
      window.notificationBadgeManager.forceUpdate();
    }, 100);
  } else {
    // If badge manager doesn't exist, fetch count directly on page load
    fetchUpdatedNotificationCount();
  }
});
</script>

<!-- Modal identical to admin -->
<div class="modal fade" id="notificationDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="notificationModalTitle">Notification Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex align-items-center mb-3">
          <span class="badge me-2" id="notificationTypeBadge"></span>
          <small class="text-muted" id="notificationTimestamp"></small>
          <small class="text-muted ms-3" id="notificationChannel"></small>
        </div>
        <p id="notificationMessage" class="mb-0"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>