<?php
// Sidebar included after session already started

// --- Use namespaced admin session consistently ---
$isAdmin = (($_SESSION['admin']['role'] ?? null) === 'management');

// --- (Optional) alert count ---
include_once '../config/database.php';
include_once '../models/alert_log.php';
include_once '../models/notification.php';
$database = new Database();
$db       = $database->getConnection();
$alert    = new AlertLog($db);
$notification = new Notification($db);

// Use Active Stock Alerts count if available (from alerts.php), otherwise fallback to unresolved alerts
// Note: active_stock_alerts_count is set in alerts.php and represents the count from Active Stock Alerts
if (isset($_SESSION['active_stock_alerts_count'])) {
    $unresolvedCount = (int)$_SESSION['active_stock_alerts_count'];
} else {
    $unresolvedCount = $alert->getUnresolvedAlerts()->rowCount();
}

// Get unread notification count for admin users
$unreadNotificationCount = 0;
if ($isAdmin && isset($_SESSION['admin']['user_id'])) {
    $unreadNotificationCount = $notification->getUnreadCount('management', 1);
}

// For active state using current file name
$current = basename($_SERVER['PHP_SELF']);
?>

<nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block bg-light sidebar collapse">
  <div class="position-sticky pt-3">
    <ul class="nav flex-column">

      <?php if ($isAdmin): ?>
        <li class="nav-item">
          <a class="nav-link <?= $current==='dashboard.php' ? 'active' : '' ?>" href="dashboard.php">
            <i class="bi bi-speedometer2 me-2"></i> Dashboard
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link <?= $current==='inventory.php' ? 'active' : '' ?>" href="inventory.php">
            <i class="bi bi-box-seam me-2"></i> Inventory
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link <?= $current==='suppliers.php' ? 'active' : '' ?>" href="suppliers.php">
            <i class="bi bi-truck me-2"></i> Suppliers
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link <?= $current==='orders.php' ? 'active' : '' ?>" href="orders.php">
            <i class="bi bi-cart-check me-2"></i> Orders
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link <?= $current==='sales.php' ? 'active' : '' ?>" href="sales.php">
            <i class="bi bi-cash-stack me-2"></i> Sales
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link <?= $current==='deliveries.php' ? 'active' : '' ?>" href="deliveries.php">
            <i class="bi bi-geo-alt me-2"></i> Deliveries
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link d-flex align-items-center <?= $current==='notifications.php' ? 'active' : '' ?>" href="notifications.php">
            <i class="bi bi-bell me-2" aria-hidden="true"></i> Notifications
            <?php if ($unreadNotificationCount > 0): ?>
              <span id="sidebar-notification-badge" class="badge bg-primary ms-1" role="status" aria-live="polite" aria-atomic="true" aria-label="Unread notifications: <?= (int)$unreadNotificationCount ?>"><?= (int)$unreadNotificationCount ?></span>
            <?php else: ?>
              <span id="sidebar-notification-badge" class="badge bg-primary ms-1" role="status" aria-live="polite" aria-atomic="true" aria-label="No unread notifications" style="display:none"></span>
            <?php endif; ?>
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link d-flex align-items-center <?= $current==='alerts.php' ? 'active' : '' ?>" href="alerts.php">
            <i class="bi bi-exclamation-triangle me-2"></i> Alerts
            <?php if ($unresolvedCount > 0): ?>
              <span class="badge bg-danger ms-1" id="sidebarAlertBadge"><?= (int)$unresolvedCount ?></span>
            <?php else: ?>
              <span class="badge bg-danger ms-1" id="sidebarAlertBadge" style="display: none;"></span>
            <?php endif; ?>
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link <?= $current==='reports.php' ? 'active' : '' ?>" href="reports.php">
            <i class="bi bi-file-earmark-text me-2"></i> Reports
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link <?= $current==='users.php' ? 'active' : '' ?>" href="users.php">
            <i class="bi bi-people me-2"></i> Users
          </a>
        </li>

        <li class="nav-item">
          <a class="nav-link <?= $current==='settings.php' ? 'active' : '' ?>" href="settings.php">
            <i class="bi bi-gear me-2"></i> Settings
          </a>
        </li>
      <?php endif; ?>
    </ul>

    <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-4 mb-1 text-muted">
      <span>System</span>
    </h6>
    <ul class="nav flex-column mb-2">
      <?php if ($isAdmin): ?>
        <li class="nav-item">
          <a class="nav-link <?= $current==='admin_pos.php' ? 'active' : '' ?>" href="admin_pos.php">
            <i class="bi bi-calculator me-2"></i> POS System (Admin)
          </a>
        </li>
      <?php endif; ?>
      <li class="nav-item">
        <a class="nav-link" href="../logout.php?role=admin">
          <i class="bi bi-box-arrow-right me-2"></i> Logout
        </a>
      </li>
    </ul>
  </div>
</nav>
