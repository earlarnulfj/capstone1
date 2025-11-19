<?php
// ====== Access control & dependencies ======
include_once '../config/session.php';
require_once '../config/database.php';
require_once '../models/notification.php';

// ---- Admin auth guard (namespaced) ----
if (empty($_SESSION['admin']['user_id'])) {
    header("Location: ../login.php");
    exit();
}
if (($_SESSION['admin']['role'] ?? null) !== 'management') {
    header("Location: ../login.php");
    exit();
}

// ---- Instantiate dependencies ----
$db = (new Database())->getConnection();
$notification = new Notification($db);

// CSRF token setup
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle actions
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'mark_read':
            $notificationId = $_POST['notification_id'] ?? '';
            if ($notificationId) {
                $result = $notification->markAsRead($notificationId);
                if ($result) {
                    $message = "Notification marked as read";
                    $messageType = 'success';
                } else {
                    $message = "Failed to mark notification as read";
                    $messageType = 'danger';
                }
            }
            break;
            
        case 'test_duplicate_prevention':
            // Test duplicate prevention functionality
            if (isset($_POST['order_id']) && isset($_POST['supplier_id'])) {
                $order_id = $_POST['order_id'];
                $supplier_id = $_POST['supplier_id'];
                
                // Try to create the same notification multiple times
                $results = [];
                for ($i = 0; $i < 3; $i++) {
                    $result = $notification->createOrderNotification(
                        $order_id, 
                        $supplier_id, 
                        'Test Item', 
                        10, 
                        true // Enable duplicate prevention
                    );
                    $results[] = $result;
                }
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Duplicate prevention test completed',
                    'results' => $results,
                    'note' => 'Only the first notification should be created, others should be prevented'
                ]);
                exit;
            }
            break;
            
        case 'mark_all_read':
            $result = $notification->markAllAsRead('management', 1);
            if ($result) {
                $message = "All notifications marked as read";
                $messageType = 'success';
            } else {
                $message = "Failed to mark all notifications as read";
                $messageType = 'danger';
            }
            break;
            
        case 'delete_notification':
            $notificationId = $_POST['notification_id'] ?? '';
            if ($notificationId) {
                $result = $notification->deleteNotification($notificationId);
                if ($result) {
                    $message = "Notification deleted";
                    $messageType = 'success';
                } else {
                    $message = "Failed to delete notification";
                    $messageType = 'danger';
                }
            }
            break;
            
        case 'delete_selected':
            $notificationIds = isset($_POST['notification_ids']) ? json_decode($_POST['notification_ids'], true) : [];
            
            if (empty($notificationIds) || !is_array($notificationIds)) {
                $message = "No notifications selected for deletion.";
                $messageType = "warning";
            } else {
                $deletedCount = 0;
                $failedCount = 0;
                
                foreach ($notificationIds as $notifId) {
                    $notifId = intval($notifId);
                    if ($notifId > 0) {
                        if ($notification->deleteNotification($notifId)) {
                            $deletedCount++;
                        } else {
                            $failedCount++;
                        }
                    }
                }
                
                if ($deletedCount > 0) {
                    $message = "Successfully deleted {$deletedCount} notification(s).";
                    if ($failedCount > 0) {
                        $message .= " {$failedCount} notification(s) could not be deleted.";
                        $messageType = "warning";
                    } else {
                        $messageType = "success";
                    }
                } else {
                    $message = "Unable to delete selected notifications.";
                    $messageType = "danger";
                }
            }
            break;
            
    }
}

// Get notifications with pagination
$page = $_GET['page'] ?? 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Get last viewed timestamp from session (BEFORE updating it)
$lastViewedKey = 'notifications_last_viewed_' . $_SESSION['admin']['user_id'];
$lastViewedTimestamp = $_SESSION[$lastViewedKey] ?? null;

// Note: We'll update the timestamp AFTER displaying notifications
// so that new notifications can be highlighted correctly

// Get all notifications for management
$notificationsStmt = $notification->getNotificationsByRecipient('management', 1, $limit, $offset);
$notifications = $notificationsStmt->fetchAll(PDO::FETCH_ASSOC);
$totalNotifications = $notification->getNotificationCount('management', 1);
$totalPages = ceil($totalNotifications / $limit);

// Get notification statistics
$unreadCount = $notification->getUnreadCount('management', 1);
$recentCount = $notification->getRecentNotificationCount('management', 1);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Notifications - Inventory & Stock Control</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
  <link href="../assets/css/style.css" rel="stylesheet">
  <script src="assets/js/notification-badge.js"></script>
  <style>
    .notification-item {
      border-left: 4px solid #dee2e6;
      transition: all 0.3s ease;
    }
    .notification-item.unread {
      border-left-color: #6c757d;
      background-color: #e9ecef;
      color: #6c757d;
    }
    .notification-item.unread .text-primary {
      color: #6c757d !important;
    }
    .notification-item:hover {
      background-color: #f8f9fa;
      transform: translateX(2px);
    }
    .notification-item:active { transform: translateX(2px) scale(0.99); }
    .notification-meta {
      font-size: 0.875rem;
      color: #6c757d;
    }
    .notification-actions {
      opacity: 0;
      transition: opacity 0.3s ease;
    }
    .notification-item:hover .notification-actions {
      opacity: 1;
    }
    .notification-badge {
      position: absolute;
      top: -8px;
      right: -8px;
      min-width: 20px;
      height: 20px;
      border-radius: 50%;
      font-size: 0.75rem;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: bold;
    }
    .notification-icon-wrapper {
      position: relative;
      display: inline-block;
    }
    .notification-item { cursor: pointer; }
    .message-snippet { overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
    .notification-item.expanded .notification-message { display: block !important; }
    .notification-message { display: none; white-space: pre-wrap; }
    .notification-new {
      animation: pulseHighlight 2s ease-in-out;
      animation-fill-mode: forwards;
      border-left-width: 6px !important;
      border-left-color: #dc3545 !important;
    }
    /* Ensure read notifications never have red styling */
    .notification-item[data-is-read="1"].notification-new,
    .notification-item[data-status="read"].notification-new,
    .notification-item:not(.unread).notification-new {
      animation: none !important;
      border-left-width: 4px !important;
      border-left-color: #dee2e6 !important;
      background-color: transparent !important;
      box-shadow: none !important;
    }
    @keyframes pulseHighlight {
      0% { background-color: rgba(220, 53, 69, 0.15); box-shadow: 0 2px 8px rgba(220, 53, 69, 0.3); }
      50% { background-color: rgba(220, 53, 69, 0.2); box-shadow: 0 2px 8px rgba(220, 53, 69, 0.4); }
      100% { background-color: transparent; box-shadow: none; }
    }
    @media (max-width: 576px) {
      .notification-actions { width: 100%; margin-top: 0.5rem; }
    }
  </style>
</head>
<body>
  <?php include_once 'includes/header.php'; ?>
  <div class="container-fluid">
    <div class="row">
      <?php include_once 'includes/sidebar.php'; ?>

      <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
          <h1 class="h2">
            <i class="bi bi-bell me-2" aria-hidden="true"></i>Notifications
            <?php if ($unreadCount > 0): ?>
              <span id="main-notification-badge" class="badge bg-primary ms-2" role="status" aria-live="polite" aria-atomic="true" aria-label="Unread notifications: <?= (int)$unreadCount ?>"><?= (int)$unreadCount ?></span>
            <?php else: ?>
              <span id="main-notification-badge" class="badge bg-primary ms-2" role="status" aria-live="polite" aria-atomic="true" aria-label="No unread notifications" style="display:none"></span>
            <?php endif; ?>
          </h1>
          <div class="btn-toolbar mb-2 mb-md-0">
            <button type="button" id="deleteSelectedBtn" class="btn btn-outline-danger btn-sm me-2" style="display: none;">
              <i class="bi bi-trash me-1"></i>Delete Selected (<span id="selectedCount">0</span>)
            </button>
            <button type="button" id="markAllReadBtn" class="btn btn-outline-primary btn-sm">
              <i class="bi bi-check-all me-1"></i>Mark All Read
            </button>
          </div>
        </div>

        <?php if ($message): ?>
          <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row mb-4">
          <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
              <div class="card-body">
                <div class="row no-gutters align-items-center">
                  <div class="col mr-2">
                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Notifications</div>
                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $totalNotifications ?></div>
                  </div>
                  <div class="col-auto">
                    <i class="bi bi-bell-fill fa-2x text-gray-300"></i>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
              <div class="card-body">
                <div class="row no-gutters align-items-center">
                  <div class="col mr-2">
                    <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Unread</div>
                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $unreadCount ?></div>
                  </div>
                  <div class="col-auto">
                    <i class="bi bi-exclamation-circle-fill fa-2x text-gray-300"></i>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
              <div class="card-body">
                <div class="row no-gutters align-items-center">
                  <div class="col mr-2">
                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Recent</div>
                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $recentCount ?></div>
                  </div>
                  <div class="col-auto">
                    <i class="bi bi-clock-fill fa-2x text-gray-300"></i>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Notifications List -->
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
          <div class="card-body p-0" id="notification-list">
            <?php if (empty($notifications)): ?>
              <div class="text-center py-5">
                <i class="bi bi-bell-slash text-muted" style="font-size: 3rem;"></i>
                <p class="text-muted mt-3">No notifications found</p>
              </div>
            <?php else: ?>
              <?php foreach ($notifications as $n): ?>
                    <?php
                        $icon = 'bi-bell';
                        $badge = 'bg-secondary';
                        if ($n['type'] === 'delivery_arrival') { $icon = 'bi-truck'; $badge = 'bg-primary'; }
                        elseif ($n['type'] === 'delivery_confirmation') { $icon = 'bi-check2-square'; $badge = 'bg-success'; }
                        elseif ($n['type'] === 'product_confirmation') { $icon = 'bi-check-circle'; $badge = 'bg-success'; }
                        elseif ($n['type'] === 'order_cancelled') { $icon = 'bi-x-circle'; $badge = 'bg-danger'; }
                        elseif ($n['type'] === 'low_stock_alert') { $icon = 'bi-exclamation-triangle'; $badge = 'bg-warning'; }

                        $createdAt = isset($n['sent_at']) && $n['sent_at'] ? $n['sent_at'] : $n['created_at'];
                        $createdDisplay = date('M d, Y h:i A', strtotime($createdAt));
                        
                        // Determine if notification is new (created after last viewed) AND unread
                        // Check is_read field (primary check) and status (fallback)
                        $isReadValue = isset($n['is_read']) ? (int)$n['is_read'] : 0;
                        $statusValue = $n['status'] ?? 'unread';
                        $isUnread = ($isReadValue == 0 && $statusValue !== 'read');
                        $isNew = false;
                        
                        // Only mark as "new" if notification is unread AND created after last viewed timestamp
                        // NEVER mark read notifications as new
                        if ($isUnread && $isReadValue == 0) {
                            if ($lastViewedTimestamp && $createdAt) {
                                // Check if notification was created after last viewed
                                $isNew = strtotime($createdAt) > strtotime($lastViewedTimestamp);
                            } else {
                                // If no last viewed timestamp in session, only mark as new if very recent (last 5 minutes)
                                // This prevents marking old unread notifications as "new" on first visit
                                if ($createdAt) {
                                    $minutesSinceCreation = (time() - strtotime($createdAt)) / 60;
                                    $isNew = ($minutesSinceCreation <= 5);
                                }
                            }
                        }
                        
                        // Additional class for highlighting new notifications
                        $newClass = $isNew ? 'notification-new' : '';
                        $unreadClass = $isUnread ? 'unread' : '';
                    ?>
                    <div class="notification-item d-flex justify-content-between align-items-start p-2 mb-2 border rounded <?php echo $unreadClass . ' ' . $newClass; ?>" data-notification-id="<?php echo (int)$n['id']; ?>" data-type="<?php echo htmlspecialchars($n['type']); ?>" data-order-id="<?php echo isset($n['order_id']) ? (int)$n['order_id'] : ''; ?>" data-channel="<?php echo htmlspecialchars($n['channel'] ?? 'system'); ?>" data-timestamp="<?php echo htmlspecialchars($createdAt); ?>" data-status="<?php echo htmlspecialchars($statusValue); ?>" data-is-read="<?php echo $isReadValue; ?>" role="button" tabindex="0" aria-expanded="false">
                        <div class="d-flex align-items-start flex-grow-1">
                            <div class="form-check me-3 mt-1">
                              <input type="checkbox" class="form-check-input notification-select-checkbox" value="<?php echo (int)$n['id']; ?>" data-notification-id="<?php echo (int)$n['id']; ?>" onclick="event.stopPropagation();" style="cursor: pointer;">
                            </div>
                            <i class="bi <?php echo $icon; ?> fs-4 me-2"></i>
                            <div class="flex-grow-1">
                                <div class="fw-bold d-flex align-items-center">
                                    <span class="badge <?php echo $badge; ?> me-2 text-uppercase"><?php echo htmlspecialchars(str_replace('_', ' ', $n['type'])); ?></span>
                                    <?php if ($isNew): ?>
                                        <span class="badge bg-danger me-2">New</span>
                                    <?php endif; ?>
                                    <small class="text-muted"><?php echo $createdDisplay; ?></small>
                                </div>
                                <div class="message-snippet mt-1"><?php echo htmlspecialchars($n['message']); ?></div>
                                <div class="notification-message mt-2">
                                    <?php echo nl2br(htmlspecialchars($n['message'])); ?>
                                    <?php if (!empty($n['order_id']) && ($n['type'] === 'product_confirmation' || $n['type'] === 'order_confirmation')): ?>
                                        <div class="mt-2">
                                            <a href="orders.php?focus_order_id=<?php echo (int)$n['order_id']; ?>" class="btn btn-sm btn-outline-primary">View Order</a>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($n['alert_id']) && $n['type'] === 'low_stock_alert'): ?>
                                        <div class="mt-2">
                                            <a href="alerts.php" class="btn btn-sm btn-outline-warning">View Alert</a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="notification-actions ms-3">
                            <?php if ($isUnread): ?>
                                <button type="button" class="btn btn-sm btn-outline-success me-2 btn-mark-read" data-id="<?php echo (int)$n['id']; ?>">Mark Read</button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-delete" data-id="<?php echo (int)$n['id']; ?>">Delete</button>
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
  
  <?php 
  // Update last viewed timestamp AFTER displaying notifications
  // This ensures the next page load will show new notifications correctly
  $_SESSION[$lastViewedKey] = date('Y-m-d H:i:s');
  ?>

  <!-- Bootstrap JS (needed for modal) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>

<!-- Notification Detail Modal -->
<div class="modal fade" id="notificationDetailModal" tabindex="-1" aria-labelledby="notificationDetailLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="notificationDetailLabel">Notification Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex align-items-center gap-2 mb-2">
          <span id="detailTypeBadge" class="badge bg-secondary">General</span>
          <span id="detailStatusBadge" class="badge bg-light text-dark">Sent</span>
        </div>
        <div class="small text-muted" id="detailTimestamp"></div>
        <div class="small text-muted" id="detailChannel"></div>
        <hr/>
        <div id="detailMessage" class="fs-6"></div>
        <div id="detailActions" class="mt-3"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-success" id="detailMarkReadBtn">Mark Read</button>
        <button type="button" class="btn btn-outline-danger" id="detailDeleteBtn">Delete</button>
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Real-time notifications via SSE -->
  <script>
    (function(){
      try {
        var lastId = (function(){
          try { return Math.max.apply(null, Array.from(document.querySelectorAll('[data-notification-id]')).map(function(el){ return parseInt(el.getAttribute('data-notification-id'), 10)||0; })) || 0; } catch(e){ return 0; }
        })();
        var es = new EventSource('api/notifications_sse.php?last_notification_id=' + lastId);
        es.addEventListener('connected', function(){ console.log('Notifications SSE connected'); });
        es.addEventListener('heartbeat', function(){ /* keep-alive */ });
        es.addEventListener('new_notification', function(ev){
          try {
            var n = JSON.parse(ev.data);
            var list = document.getElementById('notification-list');
            if (!list) return;
            var div = document.createElement('div');
            var type = String(n.type || 'notification');
            var icon = 'bi-bell';
            var badge = 'bg-secondary';
            if (type === 'delivery_arrival') { icon = 'bi-truck'; badge = 'bg-primary'; }
            else if (type === 'delivery_confirmation') { icon = 'bi-check2-square'; badge = 'bg-success'; }
            else if (type === 'product_confirmation') { icon = 'bi-check-circle'; badge = 'bg-success'; }
            else if (type === 'order_cancelled') { icon = 'bi-x-circle'; badge = 'bg-danger'; }
            else if (type === 'low_stock_alert') { icon = 'bi-exclamation-triangle'; badge = 'bg-warning'; }
            // Populate basic attributes
            var isUnread = (n.status || 'unread') === 'unread' || (n.is_read || 0) == 0;
            div.className = 'notification-item d-flex justify-content-between align-items-start p-2 mb-2 border rounded';
            div.setAttribute('data-notification-id', String(n.id));
            div.setAttribute('data-type', type);
            div.setAttribute('data-order-id', n.order_id || '');
            div.setAttribute('data-channel', n.channel || 'system');
            div.setAttribute('data-timestamp', n.sent_at || n.created_at || '');
            div.setAttribute('data-status', n.status || 'unread');
            div.setAttribute('data-is-read', (n.is_read || 0));
            div.setAttribute('role', 'button');
            div.setAttribute('tabindex', '0');
            div.setAttribute('aria-expanded', 'false');
            // Only add highlight class if notification is unread (real-time notifications are always new)
            if (isUnread) {
              div.classList.add('unread', 'notification-new');
            }
            div.innerHTML = '<div class="d-flex align-items-start flex-grow-1">\n' +
              '  <div class="form-check me-3 mt-1">\n' +
              '    <input type="checkbox" class="form-check-input notification-select-checkbox" value="' + n.id + '" data-notification-id="' + n.id + '" onclick="event.stopPropagation();" style="cursor: pointer;">\n' +
              '  </div>\n' +
              '  <i class="bi ' + icon + ' fs-4 me-2"></i>\n' +
              '  <div class="flex-grow-1">\n' +
              '    <div class="fw-bold d-flex align-items-center">\n' +
              '      <span class="badge ' + badge + ' me-2 text-uppercase">' + type.replace(/_/g, ' ') + '</span>\n' +
              (isUnread ? '      <span class="badge bg-danger me-2">New</span>\n' : '') +
              '      <small class="text-muted">' + (n.created_at || '') + '</small>\n' +
              '    </div>\n' +
              '    <div class="message-snippet mt-1">' + (n.message || '') + '</div>\n' +
              '    <div class="notification-message mt-2" style="display:none">' +
              '      ' + (n.message || '') + '\n' +
              (n.order_id ? '      <div class="mt-2"><a href="orders.php?focus_order_id=' + n.order_id + '" class="btn btn-sm btn-outline-primary">View Order</a></div>\n' : '') +
              '    </div>\n' +
              '  </div>\n' +
              '</div>\n' +
              '<div class="notification-actions ms-3">\n' +
              (isUnread ? '  <button type="button" class="btn btn-sm btn-outline-success me-1 btn-mark-read" data-id="' + n.id + '" title="Mark as read"><i class="bi bi-check"></i></button>\n' : '') +
              '  <button type="button" class="btn btn-sm btn-outline-danger btn-delete" data-id="' + n.id + '" title="Delete"><i class="bi bi-trash"></i></button>\n' +
              '</div>';
            list.insertBefore(div, list.firstChild);
            try { window.updateNotificationCounter && window.updateNotificationCounter(); } catch(e){}
          } catch (e) {
            console.error('Failed to render incoming notification', e);
          }
        });
        es.addEventListener('error', function(ev){ console.warn('Notifications SSE error', ev); });
      } catch (e) {
        console.warn('SSE not available, falling back to existing polling');
      }
    })();
  </script>
  <script>
  // Multi-select functionality
  let selectedNotifications = new Set();
  
  // Function to update notification counter using the global badge manager
  function updateNotificationCounter() {
    if (window.notificationBadgeManager) {
      window.notificationBadgeManager.forceUpdate();
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
  }
  
  // Update Select All checkbox state based on individual selections
  function updateSelectAllState() {
    const selectAllCheckbox = document.getElementById('selectAllNotifications');
    if (!selectAllCheckbox) return;
    
    const allCheckboxes = document.querySelectorAll('.notification-select-checkbox');
    const checkedCount = Array.from(allCheckboxes).filter(cb => cb.checked).length;
    
    // Check "Select All" if all checkboxes are checked, uncheck if none or some are checked
    if (allCheckboxes.length > 0) {
      selectAllCheckbox.checked = checkedCount === allCheckboxes.length;
      selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < allCheckboxes.length;
    }
  }
  
  // Select All checkbox
  document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('selectAllNotifications');
    if (selectAllCheckbox) {
      selectAllCheckbox.addEventListener('change', function(e) {
        e.stopPropagation();
        const isChecked = this.checked;
        const allCheckboxes = document.querySelectorAll('.notification-select-checkbox');
        
        // Select/deselect all checkboxes
        allCheckboxes.forEach(function(checkbox) {
          checkbox.checked = isChecked;
          if (isChecked) {
            selectedNotifications.add(checkbox.value);
          } else {
            selectedNotifications.delete(checkbox.value);
          }
        });
        
        // Clear indeterminate state
        this.indeterminate = false;
        
        updateDeleteButton();
      });
    }
    
    // Individual checkbox change - use proper event delegation
    document.addEventListener('change', function(e) {
      if (e.target && e.target.classList.contains('notification-select-checkbox')) {
        e.stopPropagation();
        const checkbox = e.target;
        if (checkbox.checked) {
          selectedNotifications.add(checkbox.value);
        } else {
          selectedNotifications.delete(checkbox.value);
        }
        updateDeleteButton();
        updateSelectAllState();
      }
    });
    
    // Delete selected button click
    const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
    if (deleteSelectedBtn) {
      deleteSelectedBtn.addEventListener('click', function() {
        const selectedArray = Array.from(selectedNotifications);
        if (selectedArray.length === 0) {
          alert('Please select at least one notification to delete.');
          return;
        }
        
        if (confirm('Are you sure you want to delete ' + selectedArray.length + ' selected notification(s)?')) {
          deleteSelectedNotifications(selectedArray);
        }
      });
    }
    
    // Initialize select all state on page load
    updateSelectAllState();
    
    // Also update when notifications are loaded dynamically (SSE)
    // We'll add this handler after SSE is initialized
    const originalObserver = window.MutationObserver || window.WebKitMutationObserver;
    if (originalObserver) {
      const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
          if (mutation.addedNodes.length > 0) {
            // Check if new notification items were added
            const hasNewNotifications = Array.from(mutation.addedNodes).some(function(node) {
              return node.nodeType === 1 && node.classList.contains('notification-item');
            });
            if (hasNewNotifications) {
              // Update select all state if "Select All" is currently checked
              const selectAllCheckbox = document.getElementById('selectAllNotifications');
              if (selectAllCheckbox && selectAllCheckbox.checked) {
                // Auto-select new notifications if "Select All" is active
                document.querySelectorAll('.notification-select-checkbox').forEach(function(checkbox) {
                  if (!checkbox.checked) {
                    checkbox.checked = true;
                    selectedNotifications.add(checkbox.value);
                  }
                });
                updateDeleteButton();
              } else {
                updateSelectAllState();
              }
            }
          }
        });
      });
      
      const notificationList = document.getElementById('notification-list');
      if (notificationList) {
        observer.observe(notificationList, {
          childList: true,
          subtree: true
        });
      }
    }
  });
  
  // Function to delete selected notifications
  function deleteSelectedNotifications(notificationIds) {
    const formData = new FormData();
    formData.append('action', 'delete_selected');
    formData.append('notification_ids', JSON.stringify(notificationIds));
    
    fetch('notifications.php', {
      method: 'POST',
      body: formData
    })
    .then(response => {
      if (!response.ok) {
        throw new Error('Network response was not ok');
      }
      return response.text();
    })
    .then(data => {
      // Remove deleted notifications from UI
      notificationIds.forEach(function(id) {
        const notificationItem = document.querySelector('[data-notification-id="' + id + '"]');
        if (notificationItem) {
          notificationItem.style.transition = 'opacity 0.3s ease-out';
          notificationItem.style.opacity = '0';
          setTimeout(function() {
            notificationItem.remove();
          }, 300);
        }
        selectedNotifications.delete(id);
      });
      
      // Update counters
      updateNotificationCounter();
      updateDeleteButton();
      
      // Reset select all and selection state
      const selectAllCheckbox = document.getElementById('selectAllNotifications');
      if (selectAllCheckbox) {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = false;
      }
      
      // Clear selection set
      selectedNotifications.clear();
      
      // Update UI
      updateSelectAllState();
      
      // Show success message
      showMessage('Successfully deleted ' + notificationIds.length + ' notification(s)', 'success');
      
      // Reload page to refresh the list
      setTimeout(function() {
        window.location.reload();
      }, 1000);
    })
    .catch(error => {
      console.error('Error deleting notifications:', error);
      showMessage('Error deleting notifications', 'danger');
    });
  }
  
  // Function to handle mark as read action
  function markAsRead(notificationId, element) {
    if (!notificationId) {
      console.error('No notification ID provided');
      return;
    }
    
    const formData = new FormData();
    formData.append('action', 'mark_read');
    formData.append('notification_id', notificationId);
  
    fetch('notifications.php', {
      method: 'POST',
      body: formData
    })
    .then(response => {
      if (!response.ok) {
        throw new Error('Network response was not ok');
      }
      return response.text();
    })
    .then(data => {
      // Update UI - remove unread styling and hide mark as read button
      const notificationItem = element?.closest('.notification-item') || document.querySelector(`[data-notification-id="${notificationId}"]`);
      if (notificationItem) {
        notificationItem.classList.remove('unread', 'notification-new');
        notificationItem.setAttribute('data-status', 'read');
        notificationItem.setAttribute('data-is-read', '1');
        
        // Hide mark as read button
        const markReadBtn = notificationItem.querySelector('.btn-mark-read');
        if (markReadBtn) {
          markReadBtn.style.display = 'none';
        }
        
        // Remove "New" badge if present
        const newBadge = notificationItem.querySelector('.badge.bg-danger');
        if (newBadge && newBadge.textContent.trim() === 'New') {
          newBadge.remove();
        }
      }
      
      // Update counters
      updateNotificationCounter();
      
      // Show success message
      showMessage('Notification marked as read', 'success');
    })
    .catch(error => {
      console.error('Error marking notification as read:', error);
      showMessage('Error marking notification as read', 'danger');
    });
  }
  
  // Function to handle delete action
  function deleteNotification(notificationId, element) {
    if (!notificationId) {
      console.error('No notification ID provided');
      return;
    }
    
    if (confirm('Are you sure you want to delete this notification?')) {
      const formData = new FormData();
      formData.append('action', 'delete_notification');
      formData.append('notification_id', notificationId);
  
      fetch('notifications.php', {
        method: 'POST',
        body: formData
      })
      .then(response => {
        if (!response.ok) {
          throw new Error('Network response was not ok');
        }
        return response.text();
      })
      .then(data => {
        // Remove the notification from UI
        const notificationItem = element?.closest('.notification-item') || document.querySelector(`[data-notification-id="${notificationId}"]`);
        if (notificationItem) {
          // Add fade out animation
          notificationItem.style.transition = 'opacity 0.3s ease-out';
          notificationItem.style.opacity = '0';
          setTimeout(() => {
            notificationItem.remove();
          }, 300);
        }
        
        // Update counters
        updateNotificationCounter();
        
        // Show success message
        showMessage('Notification deleted successfully', 'success');
      })
      .catch(error => {
        console.error('Error deleting notification:', error);
        showMessage('Error deleting notification', 'danger');
      });
    }
  }
  
  // Function to handle mark all as read action
  function markAllAsRead() {
    if (confirm('Are you sure you want to mark all notifications as read?')) {
      const formData = new FormData();
      formData.append('action', 'mark_all_read');
  
      fetch('notifications.php', {
        method: 'POST',
        body: formData
      })
      .then(response => {
        if (!response.ok) {
          throw new Error('Network response was not ok');
        }
        return response.text();
      })
      .then(data => {
        // Update UI - mark all as read
        document.querySelectorAll('.notification-item.unread, .notification-item.notification-new').forEach(item => {
          item.classList.remove('unread', 'notification-new');
          item.setAttribute('data-status', 'read');
          item.setAttribute('data-is-read', '1');
          
          const markReadBtn = item.querySelector('.btn-mark-read');
          if (markReadBtn) {
            markReadBtn.style.display = 'none';
          }
          
          // Remove "New" badges
          const newBadges = item.querySelectorAll('.badge.bg-danger');
          newBadges.forEach(badge => {
            if (badge.textContent.trim() === 'New') {
              badge.remove();
            }
          });
        });
        
        // Update counters
        updateNotificationCounter();
        
        // Show success message
        showMessage('All notifications marked as read', 'success');
      })
      .catch(error => {
        console.error('Error marking all notifications as read:', error);
        showMessage('Error marking all notifications as read', 'danger');
      });
    }
  }
  
  // Function to show messages
  function showMessage(text, type) {
    try {
      const container = document.querySelector('main') || document.body;
      const alert = document.createElement('div');
      alert.className = 'alert alert-' + (type || 'info') + ' alert-dismissible fade show';
      alert.setAttribute('role', 'alert');
      alert.innerHTML = `${text} <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
      container.prepend(alert);
      setTimeout(() => {
        if (alert && alert.parentNode) {
          alert.classList.remove('show');
          alert.remove();
        }
      }, 4000);
    } catch (e) { console.warn('showMessage failed', e); }
  }
  
  // Open notification details modal
  function openNotificationModal(item) {
    const id = item.getAttribute('data-notification-id');
    const type = item.getAttribute('data-type') || 'general';
    const status = item.getAttribute('data-status') || 'sent';
    const channel = item.getAttribute('data-channel') || 'system';
    const timestamp = item.getAttribute('data-timestamp') || '';
    const orderId = item.getAttribute('data-order-id') || '';
  
    const title = document.getElementById('notificationDetailLabel');
    const typeBadge = document.getElementById('detailTypeBadge');
    const statusBadge = document.getElementById('detailStatusBadge');
    const tsEl = document.getElementById('detailTimestamp');
    const chEl = document.getElementById('detailChannel');
    const msgEl = document.getElementById('detailMessage');
    const actionsEl = document.getElementById('detailActions');
    const markBtn = document.getElementById('detailMarkReadBtn');
    const delBtn = document.getElementById('detailDeleteBtn');
  
    // Title
    title.textContent = 'Notification #' + id;
  
    // Type badge
    let badgeClass = 'bg-secondary';
    if (type === 'order_update') badgeClass = 'bg-info';
    else if (type === 'order_cancelled') badgeClass = 'bg-danger';
    else if (type === 'low_stock_alert') badgeClass = 'bg-warning';
    else if (type === 'product_confirmation') badgeClass = 'bg-primary';
    typeBadge.className = 'badge ' + badgeClass;
    typeBadge.textContent = type.replace(/_/g, ' ');
  
    // Status badge
    statusBadge.className = 'badge ' + (status === 'unread' ? 'bg-success' : 'bg-light text-dark');
    statusBadge.textContent = status.charAt(0).toUpperCase() + status.slice(1);
  
    // Metadata
    tsEl.textContent = timestamp ? ('Timestamp: ' + timestamp) : '';
    chEl.textContent = channel ? ('Channel: ' + channel) : '';
  
    // Message content from the snippet/expanded message
    const expanded = item.querySelector('.notification-message');
    const snippet = item.querySelector('.message-snippet');
    let messageHtml = '';
    if (expanded && expanded.dataset && expanded.dataset.fullMessage) {
      messageHtml = expanded.dataset.fullMessage;
    } else if (expanded && expanded.innerHTML.trim()) {
      messageHtml = expanded.innerHTML;
    } else if (snippet) {
      messageHtml = snippet.textContent;
    } else {
      messageHtml = 'No message content available.';
    }
    msgEl.innerHTML = messageHtml;
  
    // Actions
    actionsEl.innerHTML = '';
    if (orderId) {
      const viewLink = document.createElement('a');
      viewLink.href = 'orders.php?order_id=' + encodeURIComponent(orderId) + '#order-' + encodeURIComponent(orderId);
      viewLink.className = 'btn btn-outline-primary';
      viewLink.textContent = 'View Order #' + orderId;
      actionsEl.appendChild(viewLink);
    }
  
    // Wire buttons
    markBtn.onclick = () => { 
      markAsRead(id, document.querySelector(`[data-notification-id="${id}"]`)); 
      const modal = bootstrap.Modal.getInstance(document.getElementById('notificationDetailModal'));
      if (modal) modal.hide();
    };
    delBtn.onclick = () => { 
      deleteNotification(id, document.querySelector(`[data-notification-id="${id}"]`)); 
      const modal = bootstrap.Modal.getInstance(document.getElementById('notificationDetailModal'));
      if (modal) modal.hide();
    };
  
    // Show modal
    const modalEl = document.getElementById('notificationDetailModal');
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
  }
  
  document.addEventListener('click', function(e) {
    // Don't trigger if clicking on checkbox or form elements
    if (e.target.type === 'checkbox' || e.target.closest('.form-check') || e.target.closest('.notification-actions')) {
      return;
    }
    
    const item = e.target.closest('.notification-item');
    if (item) {
      e.preventDefault();
      // Maintain inline expand for quick glance
      const msgEl = item.querySelector('.notification-message');
      const isExpanded = item.getAttribute('aria-expanded') === 'true';
      if (msgEl) {
        msgEl.style.display = isExpanded ? 'none' : 'block';
        item.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
      }
      // Open modal with full details (but don't auto-mark as read - let user decide)
      openNotificationModal(item);
    }
  }, false);
  document.addEventListener('DOMContentLoaded', function() {
    const list = document.getElementById('notification-list');
    const markAllBtn = document.getElementById('markAllReadBtn');

    function toggleExpand(item) {
      const expanded = item.getAttribute('aria-expanded') === 'true';
      item.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      item.classList.toggle('expanded');
      const panel = item.querySelector('.notification-message');
      if (panel) { panel.style.display = expanded ? 'none' : 'block'; }
      // Mark unread as read on first expand
      if (!expanded && item.classList.contains('unread')) {
        const id = item.getAttribute('data-notification-id');
        const btn = item.querySelector('.btn-mark-read');
        if (id) { markAsRead(id, btn || item); }
      }
    }

    if (markAllBtn) {
      markAllBtn.addEventListener('click', function() {
        markAllAsRead();
        // Optimistically update UI
        document.querySelectorAll('.notification-item.unread').forEach(function(el){ 
          el.classList.remove('unread'); 
          const btn = el.querySelector('.btn-mark-read');
          if (btn) { btn.style.display = 'none'; }
        });
      });
    }

    if (list) {
      list.addEventListener('click', function(e) {
        // Don't do anything if clicking on checkbox
        if (e.target.type === 'checkbox' || e.target.closest('.form-check')) {
          return;
        }
        
        const btnRead = e.target.closest('.btn-mark-read');
        const btnDelete = e.target.closest('.btn-delete');
        const item = e.target.closest('.notification-item');

        if (btnRead) {
          const id = btnRead.getAttribute('data-id');
          markAsRead(id, btnRead);
          e.preventDefault();
          return;
        }
        if (btnDelete) {
          const id = btnDelete.getAttribute('data-id');
          deleteNotification(id, btnDelete);
          e.preventDefault();
          return;
        }
        if (item && !e.target.closest('.notification-actions') && !e.target.closest('.form-check')) {
          toggleExpand(item);
          e.preventDefault();
        }
      });

      list.addEventListener('keydown', function(e) {
        const item = e.target.closest('.notification-item');
        if ((e.key === 'Enter' || e.key === ' ') && item) {
          toggleExpand(item);
          e.preventDefault();
        }
      });
    }
  });
</script>

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