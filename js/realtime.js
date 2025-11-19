/**
 * Real-time Updates Library
 * Handles real-time communication between supplier and admin interfaces
 */

class RealTimeUpdates {
    constructor(options = {}) {
        this.apiUrl = options.apiUrl || '../api/realtime_updates.php';
        this.refreshInterval = options.refreshInterval || 30000; // 30 seconds
        this.callbacks = {};
        this.isPolling = false;
        this.pollTimer = null;
        
        // Initialize event listeners
        this.init();
    }
    
    init() {
        // Start polling when page loads
        this.startPolling();
        
        // Stop polling when page is hidden
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.stopPolling();
            } else {
                this.startPolling();
            }
        });
        
        // Stop polling before page unload
        window.addEventListener('beforeunload', () => {
            this.stopPolling();
        });
    }
    
    // Event subscription
    on(event, callback) {
        if (!this.callbacks[event]) {
            this.callbacks[event] = [];
        }
        this.callbacks[event].push(callback);
    }
    
    // Trigger event callbacks
    trigger(event, data) {
        if (this.callbacks[event]) {
            this.callbacks[event].forEach(callback => {
                try {
                    callback(data);
                } catch (error) {
                    console.error('Error in callback:', error);
                }
            });
        }
    }
    
    // Start polling for updates
    startPolling() {
        if (this.isPolling) return;
        
        this.isPolling = true;
        this.poll();
        this.pollTimer = setInterval(() => this.poll(), this.refreshInterval);
        
        console.log('Real-time polling started');
    }
    
    // Stop polling
    stopPolling() {
        if (!this.isPolling) return;
        
        this.isPolling = false;
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }
        
        console.log('Real-time polling stopped');
    }
    
    // Poll for updates
    async poll() {
        try {
            const stats = await this.getStats();
            this.trigger('stats_updated', stats);
            
            const orders = await this.getOrders();
            this.trigger('orders_updated', orders);
            
            const deliveries = await this.getDeliveries();
            this.trigger('deliveries_updated', deliveries);
            
        } catch (error) {
            console.error('Polling error:', error);
            this.trigger('error', error);
        }
    }
    
    // API Methods
    async makeRequest(url, options = {}) {
        const defaultOptions = {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin'
        };
        
        const response = await fetch(url, { ...defaultOptions, ...options });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        return await response.json();
    }
    
    // Get system statistics
    async getStats() {
        return await this.makeRequest(`${this.apiUrl}?action=stats`);
    }
    
    // Get all orders
    async getOrders() {
        return await this.makeRequest(`${this.apiUrl}?action=orders`);
    }
    
    // Get supplier-specific orders
    async getSupplierOrders() {
        return await this.makeRequest(`${this.apiUrl}?action=supplier_orders`);
    }
    
    // Get all deliveries
    async getDeliveries() {
        return await this.makeRequest(`${this.apiUrl}?action=deliveries`);
    }
    
    // Get all payments
    async getPayments() {
        return await this.makeRequest(`${this.apiUrl}?action=payments`);
    }
    
    // Update order status
    async updateOrderStatus(orderId, status) {
        const response = await this.makeRequest(`${this.apiUrl}?action=update_order_status`, {
            method: 'POST',
            body: JSON.stringify({
                order_id: orderId,
                status: status
            })
        });
        
        if (response.success) {
            this.trigger('order_status_updated', { orderId, status });
            // Trigger immediate refresh
            this.poll();
        }
        
        return response;
    }
    
    // Update delivery status
    async updateDeliveryStatus(deliveryId, status) {
        const response = await this.makeRequest(`${this.apiUrl}?action=update_delivery_status`, {
            method: 'POST',
            body: JSON.stringify({
                delivery_id: deliveryId,
                status: status
            })
        });
        
        if (response.success) {
            this.trigger('delivery_status_updated', { deliveryId, status });
            // Trigger immediate refresh
            this.poll();
        }
        
        return response;
    }
    
    // Process payment
    async processPayment(orderId, amount, method) {
        const response = await this.makeRequest(`${this.apiUrl}?action=process_payment`, {
            method: 'POST',
            body: JSON.stringify({
                order_id: orderId,
                amount: amount,
                method: method
            })
        });
        
        if (response.success) {
            this.trigger('payment_processed', { orderId, amount, method });
            // Trigger immediate refresh
            this.poll();
        }
        
        return response;
    }
    
    // Cancel order
    async cancelOrder(orderId, reason) {
        const response = await this.makeRequest(`${this.apiUrl}?action=cancel_order`, {
            method: 'POST',
            body: JSON.stringify({
                order_id: orderId,
                reason: reason
            })
        });
        
        if (response.success) {
            this.trigger('order_cancelled', { orderId, reason });
            // Trigger immediate refresh
            this.poll();
        }
        
        return response;
    }
    
    // Confirm receipt
    async confirmReceipt(orderId) {
        const response = await this.makeRequest(`${this.apiUrl}?action=confirm_receipt`, {
            method: 'PUT',
            body: JSON.stringify({
                order_id: orderId
            })
        });
        
        if (response.success) {
            this.trigger('receipt_confirmed', { orderId });
            // Trigger immediate refresh
            this.poll();
        }
        
        return response;
    }
}

// Utility functions for UI updates
class UIUpdater {
    static updateStatsCards(stats) {
        if (stats.data && stats.data.orders) {
            const orders = stats.data.orders;
            
            // Update order statistics
            UIUpdater.updateElement('#total-orders', orders.total);
            UIUpdater.updateElement('#pending-orders', orders.pending);
            UIUpdater.updateElement('#confirmed-orders', orders.confirmed);
            UIUpdater.updateElement('#completed-orders', orders.completed);
            UIUpdater.updateElement('#cancelled-orders', orders.cancelled);
        }
        
        if (stats.data && stats.data.deliveries) {
            const deliveries = stats.data.deliveries;
            
            // Update delivery statistics
            UIUpdater.updateElement('#total-deliveries', deliveries.total);
            UIUpdater.updateElement('#pending-deliveries', deliveries.pending);
            UIUpdater.updateElement('#in-transit-deliveries', deliveries.in_transit);
            UIUpdater.updateElement('#delivered-deliveries', deliveries.delivered);
        }
        
        if (stats.data && stats.data.payments) {
            const payments = stats.data.payments;
            
            // Update payment statistics
            UIUpdater.updateElement('#total-payments', payments.total);
            UIUpdater.updateElement('#pending-payments', payments.pending);
            UIUpdater.updateElement('#completed-payments', payments.completed);
            UIUpdater.updateElement('#failed-payments', payments.failed);
        }
    }
    
    static updateElement(selector, value) {
        const element = document.querySelector(selector);
        if (element) {
            element.textContent = value;
            
            // Add animation effect
            element.style.transform = 'scale(1.1)';
            setTimeout(() => {
                element.style.transform = 'scale(1)';
            }, 200);
        }
    }
    
    static updateDataTable(tableId, data) {
        const table = $(`#${tableId}`);
        if (table.length && $.fn.DataTable.isDataTable(table)) {
            table.DataTable().clear();
            
            if (data && data.data) {
                table.DataTable().rows.add(data.data);
            }
            
            table.DataTable().draw();
        }
    }
    
    static showNotification(message, type = 'info') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
        notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(notification);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 5000);
    }
    
    static updateLastRefresh() {
        const now = new Date();
        const timeString = now.toLocaleTimeString();
        UIUpdater.updateElement('#last-refresh', `Last updated: ${timeString}`);
    }
}

// Global instance
let realTimeUpdates;

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    realTimeUpdates = new RealTimeUpdates();
    
    // Set up event listeners
    realTimeUpdates.on('stats_updated', (stats) => {
        UIUpdater.updateStatsCards(stats);
        UIUpdater.updateLastRefresh();
    });
    
    realTimeUpdates.on('orders_updated', (orders) => {
        UIUpdater.updateDataTable('ordersTable', orders);
    });
    
    realTimeUpdates.on('deliveries_updated', (deliveries) => {
        UIUpdater.updateDataTable('deliveriesTable', deliveries);
    });
    
    realTimeUpdates.on('order_status_updated', (data) => {
        UIUpdater.showNotification(`Order #${data.orderId} status updated to ${data.status}`, 'success');
    });
    
    realTimeUpdates.on('delivery_status_updated', (data) => {
        UIUpdater.showNotification(`Delivery #${data.deliveryId} status updated to ${data.status}`, 'info');
    });
    
    realTimeUpdates.on('payment_processed', (data) => {
        UIUpdater.showNotification(`Payment processed for Order #${data.orderId}`, 'success');
    });
    
    realTimeUpdates.on('order_cancelled', (data) => {
        UIUpdater.showNotification(`Order #${data.orderId} has been cancelled`, 'warning');
    });
    
    realTimeUpdates.on('receipt_confirmed', (data) => {
        UIUpdater.showNotification(`Receipt confirmed for Order #${data.orderId}`, 'success');
    });
    
    realTimeUpdates.on('error', (error) => {
        console.error('Real-time update error:', error);
        UIUpdater.showNotification('Connection error. Retrying...', 'danger');
    });
});

// Export for use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { RealTimeUpdates, UIUpdater };
}