<?php
// Get supplier info for sidebar
$supplier_id = $_SESSION['user_id'] ?? 0;
$supplier_name = $_SESSION['username'] ?? 'Supplier';

// Count pending orders for badge (with error handling)
$pending_orders = 0;
try {
    if (isset($pdo)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE supplier_id = ? AND confirmation_status = 'pending'");
        $stmt->execute([$supplier_id]);
        $pending_orders = $stmt->fetchColumn();
    }
} catch (Exception $e) {
    // Handle error silently for now
    $pending_orders = 0;
}

// Get current page for active navigation
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!-- Sidebar -->
<nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block bg-light sidebar collapse">
    <div class="position-sticky pt-4">
        <div class="text-center mb-4 p-3">
            <div class="sidebar-logo">
                <i class="bi bi-truck"></i>
            </div>
            <h5 class="text-dark mt-3 mb-1">Supplier Portal</h5>
            <div class="supplier-info">
                <small class="text-muted"><?php echo htmlspecialchars($_SESSION['username'] ?? 'Supplier'); ?></small>
                <div class="status-indicator">
                    <span class="status-dot"></span>
                    <small class="text-muted">Online</small>
                </div>
            </div>
        </div>
        
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>" href="dashboard.php">
                    <i class="bi bi-speedometer2 me-2"></i>Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page == 'orders.php') ? 'active' : ''; ?>" href="orders.php">
                    <i class="bi bi-box-seam me-2"></i>Orders
                    <?php if ($pending_orders > 0): ?>
                        <span class="badge bg-warning text-dark ms-auto"><?php echo $pending_orders; ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page == 'products.php') ? 'active' : ''; ?>" href="products.php">
                    <i class="bi bi-grid me-2"></i>My Products
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page == 'deliveries.php') ? 'active' : ''; ?>" href="deliveries.php">
                    <i class="bi bi-truck me-2"></i>Deliveries
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page == 'notifications.php') ? 'active' : ''; ?>" href="notifications.php">
                    <i class="bi bi-bell me-2"></i>Notifications
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page == 'chat.php') ? 'active' : ''; ?>" href="chat.php">
                    <i class="bi bi-chat-dots me-2"></i>Chat
                    <span id="unread-count" class="badge bg-danger ms-auto" style="display: none;"></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo ($current_page == 'profile.php') ? 'active' : ''; ?>" href="profile.php">
                    <i class="bi bi-person me-2"></i>Profile
                </a>
            </li>
            <li class="nav-item mt-3">
                <a class="nav-link text-warning" href="../logout.php?role=supplier">
                    <i class="bi bi-box-arrow-right me-2"></i>Logout
                </a>
            </li>
        </ul>
    </div>
</nav>

<style>
.sidebar {
    min-height: 100vh;
    background-color: #ffffff;
    color: #212529;
    box-shadow: 2px 0 10px rgba(0, 0, 0, 0.05);
    border-right: 1px solid rgba(0, 0, 0, 0.08);
}

.sidebar-logo {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
}

.sidebar-logo i {
    font-size: 1.5rem;
    color: white;
}

.supplier-info {
    margin-top: 0.5rem;
}

.status-indicator {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    margin-top: 0.25rem;
}

.status-dot {
    width: 8px;
    height: 8px;
    background: #10b981;
    border-radius: 50%;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.5; }
    100% { opacity: 1; }
}

.nav-link {
    color: rgba(33, 37, 41, 0.8);
    transition: all 0.3s ease;
    margin: 3px 12px;
    border-radius: 12px;
    padding: 12px 16px;
    position: relative;
    overflow: hidden;
}

.nav-link::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(0,0,0,0.06), transparent);
    transition: left 0.5s;
}

.nav-link:hover::before {
    left: 100%;
}

.nav-link:hover, .nav-link.active {
    color: #212529;
    background: rgba(0, 0, 0, 0.05);
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.nav-link i {
    width: 20px;
    text-align: center;
}
.badge {
    font-size: 0.7rem;
}
</style>

<script>
// Define supplier ID globally for chat functionality
var supplierId = <?php echo (int)$supplier_id; ?>;

// Check for unread messages periodically
function checkUnreadMessages() {
    if (supplierId > 0) {
        fetch('../admin/api/chat_messages.php?supplier_id=' + supplierId + '&count_only=true')
            .then(function(response) { return response.json(); })
            .then(function(data) {
                var badge = document.getElementById('unread-count');
                if (badge) {
                    if (data.success && data.unread_count > 0) {
                        badge.textContent = data.unread_count;
                        badge.style.display = 'inline';
                    } else {
                        badge.style.display = 'none';
                    }
                }
            })
            .catch(function(error) {
                console.error('Error checking unread messages:', error);
            });
    }
}

// Check for unread messages every 30 seconds
if (supplierId > 0) {
    setInterval(checkUnreadMessages, 30000);
    // Check immediately on page load
    setTimeout(checkUnreadMessages, 1000);
}
</script>