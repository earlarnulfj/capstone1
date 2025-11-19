/**
 * Real-time Notification Badge Update System
 * Updates notification badges in sidebar and main pages
 */

class NotificationBadgeManager {
    constructor() {
        this.updateInterval = 30000; // 30 seconds
        this.intervalId = null;
        this.isUpdating = false;
        
        this.init();
    }
    
    init() {
        // Update immediately on page load
        this.updateNotificationBadge();
        
        // Set up periodic updates
        this.startPeriodicUpdates();
        
        // Update when page becomes visible (user switches back to tab)
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.updateNotificationBadge();
            }
        });
        
        // Update when window gains focus
        window.addEventListener('focus', () => {
            this.updateNotificationBadge();
        });
    }
    
    startPeriodicUpdates() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
        }
        
        this.intervalId = setInterval(() => {
            this.updateNotificationBadge();
        }, this.updateInterval);
    }
    
    stopPeriodicUpdates() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
            this.intervalId = null;
        }
    }
    
    async updateNotificationBadge() {
        if (this.isUpdating) {
            return; // Prevent multiple simultaneous requests
        }
        
        this.isUpdating = true;
        
        try {
            const response = await fetch('ajax/get_notification_count.php', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            this.updateBadgeElements(data.count);
            
        } catch (error) {
            console.error('Error updating notification badge:', error);
            // Retry after a shorter interval on error
            setTimeout(() => {
                this.updateNotificationBadge();
            }, 5000);
        } finally {
            this.isUpdating = false;
        }
    }
    
    updateBadgeElements(count) {
        const sidebarBadge = document.getElementById('sidebar-notification-badge');
        const mainBadge = document.getElementById('main-notification-badge');
        const ariaLabel = count > 0 ? `Unread notifications: ${count}` : 'No unread notifications';
        
        if (count > 0) {
            // Update or create sidebar badge
            if (sidebarBadge) {
                sidebarBadge.textContent = count;
                sidebarBadge.style.display = 'inline';
                sidebarBadge.setAttribute('role', 'status');
                sidebarBadge.setAttribute('aria-live', 'polite');
                sidebarBadge.setAttribute('aria-atomic', 'true');
                sidebarBadge.setAttribute('aria-label', ariaLabel);
                this.animateBadge(sidebarBadge);
            } else {
                this.createSidebarBadge(count);
            }
            
            // Update or create main page badge (if on notifications page)
            if (mainBadge) {
                mainBadge.textContent = count;
                mainBadge.style.display = 'inline';
                mainBadge.setAttribute('role', 'status');
                mainBadge.setAttribute('aria-live', 'polite');
                mainBadge.setAttribute('aria-atomic', 'true');
                mainBadge.setAttribute('aria-label', ariaLabel);
                this.animateBadge(mainBadge);
            }
            
            // Update page title with notification count
            this.updatePageTitle(count);
            
        } else {
            // Hide badges when no unread notifications
            if (sidebarBadge) {
                sidebarBadge.style.display = 'none';
                sidebarBadge.setAttribute('aria-label', ariaLabel);
            }
            if (mainBadge) {
                mainBadge.style.display = 'none';
                mainBadge.setAttribute('aria-label', ariaLabel);
            }
            
            // Reset page title
            this.resetPageTitle();
        }
        
        // Trigger custom event for other scripts to listen to
        window.dispatchEvent(new CustomEvent('notificationCountUpdated', {
            detail: { count: count }
        }));
    }
    
    createSidebarBadge(count) {
        const notificationLink = document.querySelector('a[href="notifications.php"]');
        if (notificationLink) {
            const badge = document.createElement('span');
            badge.id = 'sidebar-notification-badge';
            badge.className = 'badge bg-primary ms-1';
            badge.textContent = count;
            badge.style.display = 'inline';
            badge.setAttribute('role', 'status');
            badge.setAttribute('aria-live', 'polite');
            badge.setAttribute('aria-atomic', 'true');
            badge.setAttribute('aria-label', `Unread notifications: ${count}`);
            notificationLink.appendChild(badge);
            this.animateBadge(badge);
        }
    }
    
    animateBadge(badgeElement) {
        // Add a subtle pulse animation to draw attention
        badgeElement.style.animation = 'none';
        setTimeout(() => {
            badgeElement.style.animation = 'pulse 0.5s ease-in-out';
        }, 10);
        
        // Remove animation after it completes
        setTimeout(() => {
            badgeElement.style.animation = 'none';
        }, 500);
    }
    
    updatePageTitle(count) {
        const originalTitle = document.title.replace(/^\(\d+\)\s*/, '');
        document.title = `(${count}) ${originalTitle}`;
    }
    
    resetPageTitle() {
        document.title = document.title.replace(/^\(\d+\)\s*/, '');
    }
    
    // Method to manually trigger an update (useful after marking notifications as read)
    forceUpdate() {
        this.updateNotificationBadge();
    }
    
    // Method to temporarily increase update frequency (useful when expecting new notifications)
    setHighFrequencyMode(duration = 60000) { // 1 minute default
        this.stopPeriodicUpdates();
        this.updateInterval = 5000; // 5 seconds
        this.startPeriodicUpdates();
        
        setTimeout(() => {
            this.updateInterval = 30000; // Back to 30 seconds
            this.startPeriodicUpdates();
        }, duration);
    }
}

// CSS for pulse animation
const style = document.createElement('style');
style.textContent = `
    @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.1); }
        100% { transform: scale(1); }
    }
    
    .notification-badge-new {
        animation: pulse 0.5s ease-in-out;
    }
`;
document.head.appendChild(style);

// Initialize the notification badge manager when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    window.notificationBadgeManager = new NotificationBadgeManager();
});

// Export for use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = NotificationBadgeManager;
}