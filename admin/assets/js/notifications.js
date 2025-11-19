// Admin Notification System
class AdminNotificationSystem {
    constructor() {
        this.apiUrl = 'api/notifications.php';
        this.pollInterval = 30000; // 30 seconds
        this.pollTimer = null;
        this.isPolling = false;
        this.lastNotificationTime = null;
        this.notificationSound = null;
        
        this.init();
    }
    
    init() {
        this.setupElements();
        this.setupEventListeners();
        this.loadNotifications();
        this.startPolling();
        this.setupNotificationSound();
    }
    
    setupElements() {
        // Get notification elements
        this.notificationBell = document.getElementById('notification-bell');
        this.notificationBadge = document.getElementById('notification-badge');
        this.notificationDropdown = document.getElementById('notification-dropdown');
        this.notificationList = document.getElementById('notification-list');
        
        // Create notification container if it doesn't exist
        if (!this.notificationList) {
            console.error('Notification list element not found');
            return;
        }
    }
    
    setupEventListeners() {
        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.notification-dropdown')) {
                this.closeDropdown();
            }
        });
        
        // Notification bell click
        if (this.notificationBell) {
            this.notificationBell.addEventListener('click', (e) => {
                e.preventDefault();
                this.toggleDropdown();
            });
        }
    }
    
    setupNotificationSound() {
        // Create audio element for notification sound
        this.notificationSound = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBSuBzvLZiTYIG2m98OScTgwOUarm7blmGgU7k9n1unEiBC13yO/eizEIHWq+8+OWT');
    }
    
    async loadNotifications() {
        try {
            const response = await fetch(`${this.apiUrl}?action=get_notifications`);
            const data = await response.json();
            
            if (data.success) {
                this.updateNotificationDisplay(data.notifications, data.stats);
                this.lastNotificationTime = data.stats.last_notification_time;
            } else {
                console.error('Failed to load notifications:', data.message);
            }
        } catch (error) {
            console.error('Error loading notifications:', error);
        }
    }
    
    updateNotificationDisplay(notifications, stats) {
        // Update badge count
        if (this.notificationBadge) {
            const unreadCount = stats.unread_count || 0;
            if (unreadCount > 0) {
                this.notificationBadge.textContent = unreadCount > 99 ? '99+' : unreadCount;
                this.notificationBadge.style.display = 'inline-block';
                this.notificationBell.classList.add('has-notifications');
            } else {
                this.notificationBadge.style.display = 'none';
                this.notificationBell.classList.remove('has-notifications');
            }
        }
        
        // Update notification list
        if (this.notificationList) {
            this.notificationList.innerHTML = '';
            
            if (notifications.length === 0) {
                this.notificationList.innerHTML = `
                    <div class="notification-item no-notifications">
                        <i class="fas fa-bell-slash"></i>
                        <span>No new notifications</span>
                    </div>
                `;
            } else {
                notifications.forEach(notification => {
                    const notificationElement = this.createNotificationElement(notification);
                    this.notificationList.appendChild(notificationElement);
                });
            }
        }
    }
    
    createNotificationElement(notification) {
        const div = document.createElement('div');
        div.className = `notification-item ${notification.status === 'sent' ? 'unread' : 'read'}`;
        div.dataset.notificationId = notification.id;
        
        // Determine icon based on notification type
        let icon = 'fas fa-truck';
        let iconColor = '#007bff';
        
        switch (notification.type) {
            case 'delivery_arrival':
                icon = 'fas fa-truck-loading';
                iconColor = '#28a745';
                break;
            case 'delivery_confirmation':
                icon = 'fas fa-check-circle';
                iconColor = '#17a2b8';
                break;
            case 'inventory_update':
                icon = 'fas fa-boxes';
                iconColor = '#ffc107';
                break;
            case 'delivery_status_update':
                icon = 'fas fa-route';
                iconColor = '#6f42c1';
                break;
        }
        
        // Format delivery details
        let deliveryInfo = '';
        if (notification.delivery_details) {
            const details = notification.delivery_details;
            deliveryInfo = `
                <div class="delivery-details">
                    <small class="text-muted">
                        Status: <span class="status-badge status-${details.status}">${details.status}</span>
                        ${details.delivery_date ? `• Delivery: ${new Date(details.delivery_date).toLocaleDateString()}` : ''}
                    </small>
                </div>
            `;
        }
        
        div.innerHTML = `
            <div class="notification-content">
                <div class="notification-icon" style="color: ${iconColor}">
                    <i class="${icon}"></i>
                </div>
                <div class="notification-text">
                    <div class="notification-message">${notification.message}</div>
                    ${deliveryInfo}
                    <div class="notification-time">
                        <small class="text-muted">${notification.time_ago}</small>
                    </div>
                </div>
                <div class="notification-actions">
                    <button class="btn btn-sm btn-outline-primary mark-read-btn" 
                            onclick="adminNotifications.markAsRead(${notification.id})">
                        <i class="fas fa-check"></i>
                    </button>
                </div>
            </div>
        `;
        
        // Add click handler to mark as read
        div.addEventListener('click', (e) => {
            if (!e.target.closest('.notification-actions')) {
                this.markAsRead(notification.id);
            }
        });
        
        return div;
    }
    
    async markAsRead(notificationId) {
        try {
            const response = await fetch(this.apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'mark_as_read',
                    notification_id: notificationId
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Update UI immediately
                const notificationElement = document.querySelector(`[data-notification-id="${notificationId}"]`);
                if (notificationElement) {
                    notificationElement.classList.remove('unread');
                    notificationElement.classList.add('read');
                    
                    // Remove the mark as read button
                    const markReadBtn = notificationElement.querySelector('.mark-read-btn');
                    if (markReadBtn) {
                        markReadBtn.remove();
                    }
                }
                
                // Update badge count
                this.updateBadgeCount(-1);
            } else {
                console.error('Failed to mark notification as read:', data.message);
            }
        } catch (error) {
            console.error('Error marking notification as read:', error);
        }
    }
    
    async markAllAsRead() {
        try {
            const response = await fetch(this.apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'mark_all_as_read'
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Reload notifications to update display
                this.loadNotifications();
                this.showToast('All notifications marked as read', 'success');
            } else {
                console.error('Failed to mark all notifications as read:', data.message);
                this.showToast('Failed to mark notifications as read', 'error');
            }
        } catch (error) {
            console.error('Error marking all notifications as read:', error);
            this.showToast('Error marking notifications as read', 'error');
        }
    }
    
    updateBadgeCount(change) {
        if (this.notificationBadge) {
            const currentCount = parseInt(this.notificationBadge.textContent) || 0;
            const newCount = Math.max(0, currentCount + change);
            
            if (newCount > 0) {
                this.notificationBadge.textContent = newCount > 99 ? '99+' : newCount;
                this.notificationBadge.style.display = 'inline-block';
                this.notificationBell.classList.add('has-notifications');
            } else {
                this.notificationBadge.style.display = 'none';
                this.notificationBell.classList.remove('has-notifications');
            }
        }
    }
    
    toggleDropdown() {
        if (this.notificationDropdown) {
            this.notificationDropdown.classList.toggle('show');
            
            if (this.notificationDropdown.classList.contains('show')) {
                // Refresh notifications when opening dropdown
                this.loadNotifications();
            }
        }
    }
    
    closeDropdown() {
        if (this.notificationDropdown) {
            this.notificationDropdown.classList.remove('show');
        }
    }
    
    startPolling() {
        if (this.isPolling) return;
        
        this.isPolling = true;
        this.pollTimer = setInterval(() => {
            this.checkForNewNotifications();
        }, this.pollInterval);
    }
    
    stopPolling() {
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
        this.isPolling = false;
    }
    
    async checkForNewNotifications() {
        try {
            const response = await fetch(`${this.apiUrl}?action=get_notification_summary`);
            const data = await response.json();
            
            if (data.success) {
                const stats = data.notification_stats;
                
                // Check if there are new notifications
                if (this.lastNotificationTime && 
                    stats.last_notification_time && 
                    new Date(stats.last_notification_time) > new Date(this.lastNotificationTime)) {
                    
                    // New notifications detected
                    this.playNotificationSound();
                    this.showToast('New delivery notification received!', 'info');
                    
                    // Reload full notifications
                    this.loadNotifications();
                }
                
                this.lastNotificationTime = stats.last_notification_time;
            }
        } catch (error) {
            console.error('Error checking for new notifications:', error);
        }
    }
    
    playNotificationSound() {
        if (this.notificationSound) {
            this.notificationSound.play().catch(e => {
                console.log('Could not play notification sound:', e);
            });
        }
    }
    
    showToast(message, type = 'info') {
        // Create toast notification
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <div class="toast-content">
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                <span>${message}</span>
            </div>
        `;
        
        // Add to page
        document.body.appendChild(toast);
        
        // Show toast
        setTimeout(() => toast.classList.add('show'), 100);
        
        // Remove toast after 3 seconds
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => document.body.removeChild(toast), 300);
        }, 3000);
    }
    
    // Public method to refresh notifications
    refresh() {
        this.loadNotifications();
    }
    
    // Public method to get notification stats
    async getStats() {
        try {
            const response = await fetch(`${this.apiUrl}?action=get_notification_summary`);
            const data = await response.json();
            return data.success ? data : null;
        } catch (error) {
            console.error('Error getting notification stats:', error);
            return null;
        }
    }
}

// Initialize notification system when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    window.adminNotifications = new AdminNotificationSystem();
});

// Add CSS styles for notifications
const notificationStyles = `
<style>
.notification-dropdown {
    position: absolute;
    top: 100%;
    right: 0;
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    width: 350px;
    max-height: 400px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
}

.notification-dropdown.show {
    display: block;
}

.notification-header {
    padding: 15px;
    border-bottom: 1px solid #eee;
    background: #f8f9fa;
    border-radius: 8px 8px 0 0;
}

.notification-header h6 {
    margin: 0;
    font-weight: 600;
}

.notification-list {
    max-height: 300px;
    overflow-y: auto;
}

.notification-item {
    padding: 12px 15px;
    border-bottom: 1px solid #f0f0f0;
    cursor: pointer;
    transition: background-color 0.2s;
}

.notification-item:hover {
    background-color: #f8f9fa;
}

.notification-item.unread {
    background-color: #e3f2fd;
    border-left: 3px solid #2196f3;
}

.notification-content {
    display: flex;
    align-items: flex-start;
    gap: 10px;
}

.notification-icon {
    font-size: 18px;
    margin-top: 2px;
}

.notification-text {
    flex: 1;
}

.notification-message {
    font-size: 14px;
    margin-bottom: 4px;
    line-height: 1.4;
}

.notification-time {
    font-size: 12px;
    color: #666;
}

.delivery-details {
    margin: 4px 0;
}

.status-badge {
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 500;
    text-transform: uppercase;
}

.status-pending { background: #fff3cd; color: #856404; }
.status-in_transit { background: #d1ecf1; color: #0c5460; }
.status-delivered { background: #d4edda; color: #155724; }

.notification-actions {
    display: flex;
    gap: 5px;
}

.mark-read-btn {
    padding: 4px 8px;
    font-size: 12px;
}

.notification-footer {
    padding: 10px 15px;
    border-top: 1px solid #eee;
    background: #f8f9fa;
    display: flex;
    justify-content: space-between;
    border-radius: 0 0 8px 8px;
}

.no-notifications {
    text-align: center;
    padding: 30px 15px;
    color: #666;
}

.no-notifications i {
    font-size: 24px;
    margin-bottom: 10px;
    display: block;
}

.notification-bell.has-notifications {
    color: #ffc107 !important;
}

.notification-badge {
    position: absolute;
    top: -8px;
    right: -8px;
    background: #dc3545;
    color: white;
    border-radius: 50%;
    padding: 2px 6px;
    font-size: 11px;
    font-weight: bold;
    min-width: 18px;
    text-align: center;
    display: none;
}

.toast {
    position: fixed;
    top: 20px;
    right: 20px;
    background: white;
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 12px 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 9999;
    transform: translateX(100%);
    transition: transform 0.3s ease;
}

.toast.show {
    transform: translateX(0);
}

.toast-content {
    display: flex;
    align-items: center;
    gap: 8px;
}

.toast-success { border-left: 4px solid #28a745; }
.toast-error { border-left: 4px solid #dc3545; }
.toast-info { border-left: 4px solid #17a2b8; }
</style>
`;

// Add styles to head
document.head.insertAdjacentHTML('beforeend', notificationStyles);