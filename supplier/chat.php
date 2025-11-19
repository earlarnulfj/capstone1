<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as supplier
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'supplier') {
    header("Location: ../login.php");
    exit();
}

// Create database connection for sidebar compatibility
$database = new Database();
$pdo = $database->getConnection();

$supplier_id = $_SESSION['user_id'];
$supplier_name = $_SESSION['username'];


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat with Admin - Supplier Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #3b82f6;
            --primary-dark: #1d4ed8;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --info-color: #06b6d4;
            --light-bg: #f8fafc;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --card-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        * {
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        
        .main-content {
            background: var(--light-bg);
            min-height: 100vh;
            border-radius: 20px 0 0 0;
            margin-left: 0;
        }
        
        .chat-container {
            background: white;
            border-radius: 20px;
            box-shadow: var(--card-shadow-hover);
            height: calc(100vh - 120px);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .chat-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }
        
        .chat-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(30px, -30px);
        }
        
        .chat-header h4 {
            margin: 0;
            font-weight: 600;
        }
        
        .online-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            opacity: 0.9;
        }
        
        .status-dot {
            width: 8px;
            height: 8px;
            background: #10b981;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        
        .chat-messages {
            flex: 1;
            padding: 1.5rem;
            overflow-y: auto;
            background: linear-gradient(to bottom, #f8fafc, #f1f5f9);
            position: relative;
        }
        
        .chat-messages::-webkit-scrollbar {
            width: 6px;
        }
        
        .chat-messages::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        
        .chat-messages::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        
        .chat-messages::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        
        .message {
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-end;
            animation: fadeInUp 0.3s ease;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .message.sent {
            justify-content: flex-end;
        }
        
        .message.received {
            justify-content: flex-start;
        }
        
        .message-bubble {
            max-width: 70%;
            padding: 0.75rem 1rem;
            border-radius: 18px;
            position: relative;
            word-wrap: break-word;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .message.sent .message-bubble {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            border-bottom-right-radius: 6px;
        }
        
        .message.received .message-bubble {
            background: white;
            color: var(--text-dark);
            border: 1px solid #e2e8f0;
            border-bottom-left-radius: 6px;
        }
        
        .message-time {
            font-size: 0.75rem;
            opacity: 0.7;
            margin-top: 0.25rem;
        }
        
        .message.sent .message-time {
            text-align: right;
        }
        
        .message.received .message-time {
            text-align: left;
        }
        
        .message-sender {
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: var(--text-muted);
        }
        
        .chat-input {
            padding: 1.5rem 2rem;
            background: white;
            border-top: 1px solid #e2e8f0;
            position: relative;
        }
        
        .chat-input::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--primary-color), transparent);
        }
        
        .input-group {
            display: flex;
            gap: 0.75rem;
            align-items: flex-end;
        }
        
        .form-control {
            border-radius: 20px;
            border: 2px solid #e2e8f0;
            padding: 0.75rem 1.25rem;
            transition: all 0.3s ease;
            resize: none;
            min-height: 44px;
            max-height: 120px;
            font-size: 0.875rem;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            outline: none;
        }
        
        .btn-send {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            border: none;
            border-radius: 50%;
            width: 44px;
            height: 44px;
            color: white;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .btn-send:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
        }
        
        .btn-send:active {
            transform: scale(0.95);
        }
        
        .typing-indicator {
            display: none;
            padding: 0.5rem 1rem;
            color: var(--text-muted);
            font-style: italic;
            font-size: 0.875rem;
        }
        
        .typing-dots {
            display: inline-flex;
            gap: 2px;
        }
        
        .typing-dots span {
            width: 4px;
            height: 4px;
            background: var(--text-muted);
            border-radius: 50%;
            animation: typing 1.4s infinite;
        }
        
        .typing-dots span:nth-child(2) {
            animation-delay: 0.2s;
        }
        
        .typing-dots span:nth-child(3) {
            animation-delay: 0.4s;
        }
        
        /* Enhanced Mobile Responsiveness */
        @media (max-width: 768px) {
            .chat-container {
                height: 100vh;
                border-radius: 0;
                margin: 0;
            }
            
            .chat-header {
                padding: 1rem 1.5rem;
                border-radius: 0;
            }
            
            .chat-header h2 {
                font-size: 1.25rem;
            }
            
            .chat-messages {
                padding: 1rem;
                height: calc(100vh - 180px);
            }
            
            .message-bubble {
                max-width: 85%;
                padding: 0.625rem 0.875rem;
                font-size: 0.875rem;
            }
            
            .chat-input {
                padding: 1rem 1.5rem;
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                background: white;
                border-top: 2px solid #e2e8f0;
                z-index: 1000;
            }
            
            .form-control {
                font-size: 16px; /* Prevents zoom on iOS */
                padding: 0.625rem 1rem;
                min-height: 40px;
            }
            
            .btn-send {
                width: 40px;
                height: 40px;
            }
            
            .quick-messages {
                padding: 0.75rem 1rem;
                gap: 0.5rem;
            }
            
            .quick-message-btn {
                padding: 0.5rem 0.75rem;
                font-size: 0.75rem;
            }
        }
        
        @media (max-width: 480px) {
            .chat-header {
                padding: 0.75rem 1rem;
            }
            
            .chat-header h2 {
                font-size: 1.125rem;
            }
            
            .status-indicator {
                font-size: 0.75rem;
            }
            
            .chat-messages {
                padding: 0.75rem;
                height: calc(100vh - 160px);
            }
            
            .message-bubble {
                max-width: 90%;
                padding: 0.5rem 0.75rem;
                font-size: 0.8125rem;
                border-radius: 16px;
            }
            
            .message.sent .message-bubble {
                border-bottom-right-radius: 4px;
            }
            
            .message.received .message-bubble {
                border-bottom-left-radius: 4px;
            }
            
            .message-time {
                font-size: 0.6875rem;
            }
            
            .message-sender {
                font-size: 0.6875rem;
            }
            
            .chat-input {
                padding: 0.75rem 1rem;
            }
            
            .input-group {
                gap: 0.5rem;
            }
            
            .form-control {
                padding: 0.5rem 0.875rem;
                min-height: 36px;
                border-radius: 18px;
            }
            
            .btn-send {
                width: 36px;
                height: 36px;
            }
            
            .quick-messages {
                padding: 0.5rem 0.75rem;
                gap: 0.375rem;
            }
            
            .quick-message-btn {
                padding: 0.375rem 0.625rem;
                font-size: 0.6875rem;
                border-radius: 12px;
            }
        }
        
        /* Enhanced Animations */
        .chat-container {
            animation: slideInUp 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .message {
            animation: messageSlideIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        @keyframes messageSlideIn {
            from {
                opacity: 0;
                transform: translateY(15px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        .btn-send {
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        .btn-send:hover {
            transform: scale(1.1) rotate(5deg);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
        }
        
        .btn-send:active {
            transform: scale(0.9) rotate(-2deg);
        }
        
        .form-control {
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        .form-control:focus {
            transform: scale(1.02);
        }
        
        .quick-message-btn {
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        .quick-message-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        /* Touch-friendly improvements */
        @media (hover: none) and (pointer: coarse) {
            .btn-send:hover {
                transform: none;
                box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
            }
            
            .quick-message-btn:hover {
                transform: none;
            }
            
            .form-control:focus {
                transform: none;
            }
        }
        
        @keyframes typing {
            0%, 60%, 100% {
                transform: translateY(0);
                opacity: 0.4;
            }
            30% {
                transform: translateY(-10px);
                opacity: 1;
            }
        }
        
        @keyframes pulse {
            0% {
                transform: scale(1);
                opacity: 1;
            }
            50% {
                transform: scale(1.2);
                opacity: 0.7;
            }
            100% {
                transform: scale(1);
                opacity: 1;
            }
        }
        
        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }
            100% {
                transform: rotate(360deg);
            }
        }
        
        /* Notification styles */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 10000;
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        .notification.show {
            opacity: 1;
            transform: translateX(0);
        }
        
        .notification.success {
            background: linear-gradient(135deg, #10b981, #059669);
        }
        
        .notification.error {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }
        
        /* Enhanced loading states */
        .sending-indicator {
            display: none;
            align-items: center;
            gap: 0.5rem;
            color: var(--text-muted);
            font-size: 0.875rem;
            padding: 0.5rem 1rem;
            background: #f8fafc;
            border-radius: 20px;
            margin: 0.5rem 0;
        }
        
        .sending-indicator.show {
            display: flex;
        }
        
        .sending-dots {
            display: flex;
            gap: 2px;
        }
        
        .sending-dots span {
            width: 4px;
            height: 4px;
            background: var(--primary-color);
            border-radius: 50%;
            animation: sending-pulse 1.4s infinite ease-in-out;
        }
        
        .sending-dots span:nth-child(1) { animation-delay: -0.32s; }
        .sending-dots span:nth-child(2) { animation-delay: -0.16s; }
        .sending-dots span:nth-child(3) { animation-delay: 0s; }
        
        @keyframes sending-pulse {
            0%, 80%, 100% {
                transform: scale(0.8);
                opacity: 0.5;
            }
            40% {
                transform: scale(1);
                opacity: 1;
            }
        }
        
        /* Enhanced message status indicators */
        .message-status {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .status-icon {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }
        
        .status-sending { background: #fbbf24; }
        .status-sent { background: #10b981; }
        .status-delivered { background: #3b82f6; }
        .status-read { background: #8b5cf6; }
        
        /* Improved input focus states */
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            outline: none;
        }
        
        /* Enhanced button hover effects */
        .btn-send:hover:not(:disabled) {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .btn-send:active {
            transform: scale(0.95);
        }
        
        /* Smooth scroll behavior */
        .chat-messages {
            scroll-behavior: smooth;
        }
        
        /* Enhanced quick message buttons */
        .quick-message-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .quick-message-btn:active {
            transform: translateY(0);
        }
        
        .quick-messages {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        
        .quick-msg-btn {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 20px;
            padding: 8px 15px;
            font-size: 0.85rem;
            color: #6c757d;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .quick-msg-btn:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .online-indicator {
            width: 12px;
            height: 12px;
            background: #28a745;
            border-radius: 50%;
            display: inline-block;
            margin-left: 10px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        .no-messages {
            text-align: center;
            color: #6c757d;
            padding: 40px;
            font-style: italic;
        }
        
        .typing-indicator {
            display: none;
            padding: 10px 20px;
            color: #6c757d;
            font-style: italic;
        }
        
        @media (max-width: 768px) {
            /* Use Bootstrap collapse classes; no custom transforms */
            .sidebar {}
            .sidebar.show {}
            
            .main-content {
                margin-left: 0;
            }
            
            .message-bubble {
                max-width: 85%;
            }
        }
    </style>
</head>
<body>
<?php include_once 'includes/header.php'; ?>
    <div class="container-fluid">
        <div class="row">
            <?php include_once 'includes/sidebar.php'; ?>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 main-content p-4">
                <div class="row">
                    <div class="col-12">
                        <div class="chat-container">
                            <!-- Chat Header -->
                            <div class="chat-header">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-chat-dots me-2"></i>
                                    <div>
                                        <h4 class="mb-0">Chat with Admin</h4>
                                        <small id="connectionStatus" class="text-light opacity-75">
                                            <i class="bi bi-wifi-off"></i> Disconnected
                                        </small>
                                    </div>
                                </div>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-light btn-sm" onclick="refreshChat(event)" title="Refresh Chat">
                                        <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                                    </button>
                                    <button class="btn btn-outline-danger btn-sm" onclick="clearChatHistory()" title="Clear Chat History">
                                        <i class="bi bi-trash me-1"></i>Clear
                                    </button>
                                </div>
                            </div>

                            <!-- Chat Messages Area -->
                            <div class="chat-messages" id="chatMessages">
                                <div class="no-messages" id="noMessages">
                                    <i class="bi bi-chat-text fa-3x mb-3 text-muted"></i>
                                    <p>No messages yet. Start a conversation with the admin!</p>
                                </div>
                            </div>
                            
                            <div class="typing-indicator" id="typingIndicator">
                                <div class="typing-dots">
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                </div>
                                <span>Admin is typing...</span>
                            </div>
                            
                            <div class="sending-indicator" id="sendingIndicator">
                                <span>Sending</span>
                                <div class="sending-dots">
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                </div>
                            </div>
                            
                            <!-- Quick Messages -->
                            <div class="quick-messages">
                                <span class="quick-msg-btn" onclick="insertQuickMessage('Hello, I need help with my account')">
                                    Account Help
                                </span>
                                <span class="quick-msg-btn" onclick="insertQuickMessage('I have a question about my orders')">
                                    Order Question
                                </span>
                                <span class="quick-msg-btn" onclick="insertQuickMessage('Can you help me with product management?')">
                                    Product Help
                                </span>
                                <span class="quick-msg-btn" onclick="insertQuickMessage('Thank you for your assistance!')">
                                    Thank You
                                </span>
                            </div>
                            
                            <!-- Chat Input -->
                            <div class="chat-input">
                                <div class="input-group">
                                    <input type="text" 
                                           class="form-control" 
                                           id="messageInput" 
                                           placeholder="Type your message here..."
                                           maxlength="500"
                                           onkeypress="handleKeyPress(event)">
                                    <button class="btn btn-send" onclick="sendMessage()">
                                        <i class="bi bi-send"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/sounds/notification.js"></script>
    <script>
        const supplierName = '<?php echo htmlspecialchars($supplier_name); ?>';
        let messagePolling;
        let lastMessageCount = 0;
        let notificationSounds;
        let chatSystem;

        // Enhanced Chat System for Supplier
        class SupplierChatSystem {
            constructor() {
                this.eventSource = null;
                this.isConnected = false;
                this.reconnectAttempts = 0;
                this.maxReconnectAttempts = 5;
                this.reconnectDelay = 1000;
                this.lastMessageId = 0;
                this.typingTimer = null;
                this.isTyping = false;
                this.notificationPermission = 'default';
                this.sounds = new NotificationSounds();
                this.isTabActive = true;
                this.connectionRetryTimer = null;
                
                this.initEventListeners();
                this.init();
            }

            initEventListeners() {
                // Tab visibility detection
                document.addEventListener('visibilitychange', () => {
                    this.isTabActive = !document.hidden;
                });

                // Window focus detection
                window.addEventListener('focus', () => {
                    this.isTabActive = true;
                });

                window.addEventListener('blur', () => {
                    this.isTabActive = false;
                });
            }

            async init() {
                await this.requestNotificationPermission();
                this.setupEventSource();
                this.setupTypingIndicator();
                this.updateConnectionStatus('connecting');
            }

            async requestNotificationPermission() {
                if ('Notification' in window) {
                    this.notificationPermission = await Notification.requestPermission();
                }
            }

            setupEventSource() {
                if (this.eventSource) {
                    this.eventSource.close();
                }

                this.eventSource = new EventSource(`../admin/api/chat_sse.php?supplier_id=${supplierId}&sender_type=supplier`);

                this.eventSource.onopen = () => {
                    this.isConnected = true;
                    this.reconnectAttempts = 0;
                    this.updateConnectionStatus('connected');
                    console.log('SSE connection established');
                };

                this.eventSource.onmessage = (event) => {
                    try {
                        const data = JSON.parse(event.data);
                        this.handleSSEMessage(data);
                    } catch (error) {
                        console.error('Error parsing SSE message:', error);
                    }
                };

                this.eventSource.onerror = () => {
                    this.isConnected = false;
                    this.updateConnectionStatus('disconnected');
                    this.handleReconnection();
                };
            }

            handleSSEMessage(data) {
                switch (data.type) {
                    case 'new_message':
                        if (data.message.sender_type !== 'supplier') {
                            this.handleNewMessage(data.message);
                        }
                        break;
                    case 'typing_start':
                        if (data.sender_type !== 'supplier') {
                            this.showTypingIndicator();
                        }
                        break;
                    case 'typing_stop':
                        if (data.sender_type !== 'supplier') {
                            this.hideTypingIndicator();
                        }
                        break;
                    case 'heartbeat':
                        // Keep connection alive
                        break;
                }
            }

            handleNewMessage(message) {
                // Play notification sound
                this.sounds.playMessageSound();
                
                // Show browser notification if tab is not active
                if (document.hidden && this.notificationPermission === 'granted') {
                    this.showBrowserNotification(message);
                }
                
                // Reload messages to show the new one
                loadMessages();
            }

            showBrowserNotification(message) {
                // Only show notification if tab is not active
                if (this.isTabActive || this.notificationPermission !== 'granted') {
                    return;
                }

                const notification = new Notification('New message from Admin', {
                    body: message.message.substring(0, 100) + (message.message.length > 100 ? '...' : ''),
                    icon: '/favicon.ico',
                    badge: '/favicon.ico',
                    tag: 'chat-message',
                    requireInteraction: false,
                    silent: false
                });

                notification.onclick = () => {
                    window.focus();
                    notification.close();
                };

                // Auto close after 5 seconds
                setTimeout(() => notification.close(), 5000);
            }

            async sendMessage(message) {
                this.stopTyping();
                
                // Validate message
                if (!message || message.trim().length === 0) {
                    throw new Error('Message cannot be empty');
                }
                
                if (message.length > 500) {
                    throw new Error('Message is too long. Maximum 500 characters allowed.');
                }
                
                if (!supplierId) {
                    throw new Error('Supplier ID not available');
                }
                
                try {
                    const response = await fetch('../admin/api/chat_messages.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            supplier_id: supplierId,
                            message: message.trim(),
                            sender_type: 'supplier'
                        })
                    });
                    
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    
                    const data = await response.json();
                    
                    if (!data.success) {
                        throw new Error(data.message || 'Failed to send message');
                    }
                    
                    // Update last message ID
                    if (data.message_id) {
                        this.lastMessageId = Math.max(this.lastMessageId, data.message_id);
                        
                        // Simulate delivery status update after a short delay
                        setTimeout(() => {
                            this.updateMessageStatus(data.message_id, 'delivered');
                        }, 1000);
                    }
                    
                    return { success: true, data: data };
                    
                } catch (error) {
                    console.error('Error sending message:', error);
                    throw error;
                }
            }



            updateMessageStatus(messageId, status) {
                fetch('../admin/api/message_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        message_id: messageId,
                        status: status
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        this.updateMessageStatusUI(messageId, status);
                    }
                })
                .catch(error => {
                    console.error('Error updating message status:', error);
                });
            }

            updateMessageStatusUI(messageId, status) {
                const messageElement = document.querySelector(`[data-message-id="${messageId}"]`);
                if (messageElement) {
                    const statusElement = messageElement.querySelector('.message-status');
                    
                    if (statusElement) {
                        let statusIcon = '';
                        let statusColor = '';
                        
                        switch (status) {
                            case 'sent':
                                statusIcon = '✓';
                                statusColor = '#6c757d';
                                break;
                            case 'delivered':
                                statusIcon = '✓✓';
                                statusColor = '#6c757d';
                                break;
                            case 'read':
                                statusIcon = '✓✓';
                                statusColor = '#007bff';
                                break;
                        }
                        
                        statusElement.setAttribute('data-status', status);
                        statusElement.style.color = statusColor;
                        statusElement.textContent = statusIcon;
                    }
                }
            }

            markMessagesAsRead() {
                fetch('../admin/api/message_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        supplier_id: supplierId,
                        sender_type: 'supplier',
                        mark_read: true
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('Messages marked as read');
                    }
                })
                .catch(error => {
                    console.error('Error marking messages as read:', error);
                });
            }

            setupTypingIndicator() {
                const messageInput = document.getElementById('messageInput');
                
                messageInput.addEventListener('input', () => {
                    if (!this.isTyping) {
                        this.startTyping();
                    }
                    
                    clearTimeout(this.typingTimer);
                    this.typingTimer = setTimeout(() => {
                        this.stopTyping();
                    }, 1000);
                });

                messageInput.addEventListener('blur', () => {
                    this.stopTyping();
                });
            }

            startTyping() {
                this.isTyping = true;
                fetch('../admin/api/typing_indicator.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `supplier_id=${supplierId}&sender_type=supplier&sender_name=${encodeURIComponent(supplierName)}`
                }).catch(error => console.error('Error sending typing indicator:', error));
            }

            stopTyping() {
                if (this.isTyping) {
                    this.isTyping = false;
                    clearTimeout(this.typingTimer);
                    
                    fetch('../admin/api/typing_indicator.php', {
                        method: 'DELETE',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `supplier_id=${supplierId}&sender_type=supplier`
                    }).catch(error => console.error('Error removing typing indicator:', error));
                }
            }

            showTypingIndicator() {
                const indicator = document.getElementById('typingIndicator');
                if (indicator) {
                    indicator.style.display = 'block';
                    this.sounds.playTypingSound();
                }
            }

            hideTypingIndicator() {
                const indicator = document.getElementById('typingIndicator');
                if (indicator) {
                    indicator.style.display = 'none';
                }
            }

            updateConnectionStatus(status) {
                const statusElement = document.querySelector('.online-status span:last-child');
                const statusDot = document.querySelector('.status-dot');
                
                if (statusElement && statusDot) {
                    switch (status) {
                        case 'connected':
                            statusElement.textContent = 'Connected to Admin';
                            statusDot.style.background = '#10b981';
                            statusDot.style.animation = 'none';
                            this.resetReconnection();
                            break;
                        case 'connecting':
                            statusElement.textContent = 'Connecting...';
                            statusDot.style.background = '#f59e0b';
                            statusDot.style.animation = 'pulse 2s infinite';
                            break;
                        case 'reconnecting':
                            statusElement.textContent = `Reconnecting... (${this.reconnectAttempts}/${this.maxReconnectAttempts})`;
                            statusDot.style.background = '#f59e0b';
                            statusDot.style.animation = 'pulse 1s infinite';
                            break;
                        case 'disconnected':
                            statusElement.textContent = 'Disconnected';
                            statusDot.style.background = '#ef4444';
                            statusDot.style.animation = 'none';
                            break;
                        case 'failed':
                            statusElement.textContent = 'Connection Failed';
                            statusDot.style.background = '#ef4444';
                            statusDot.style.animation = 'none';
                            break;
                    }
                }
            }

            handleReconnection() {
                if (this.reconnectAttempts < this.maxReconnectAttempts) {
                    this.reconnectAttempts++;
                    const delay = Math.min(this.reconnectDelay * Math.pow(2, this.reconnectAttempts - 1), 30000); // Max 30 seconds
                    
                    this.updateConnectionStatus('reconnecting');
                    
                    this.connectionRetryTimer = setTimeout(() => {
                        console.log(`Attempting to reconnect (${this.reconnectAttempts}/${this.maxReconnectAttempts})`);
                        this.setupEventSource();
                    }, delay);
                } else {
                    console.error('Max reconnection attempts reached');
                    this.updateConnectionStatus('failed');
                    showNotification('Connection lost. Please refresh the page to reconnect.', 'error');
                }
            }

            resetReconnection() {
                this.reconnectAttempts = 0;
                if (this.connectionRetryTimer) {
                    clearTimeout(this.connectionRetryTimer);
                    this.connectionRetryTimer = null;
                }
            }



            disconnect() {
                if (this.eventSource) {
                    this.eventSource.close();
                    this.eventSource = null;
                }
                this.stopTyping();
                this.resetReconnection();
                this.isConnected = false;
                this.updateConnectionStatus('disconnected');
            }
        }
        


        // Load messages when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize enhanced chat system
            chatSystem = new SupplierChatSystem();
            
            // Wait a bit for sidebar script to load, then load initial messages
            setTimeout(() => {
                loadMessages();
                // Don't start polling as we now use SSE
                // startMessagePolling();
            }, 100);

            // Focus on message input
            const messageInput = document.getElementById('messageInput');
            if (messageInput) {
                messageInput.focus();
            }
        });

        function loadMessages() {
            // Check if supplierId is available
            if (typeof supplierId === 'undefined' || !supplierId) {
                console.error('Supplier ID not available');
                return Promise.reject('Supplier ID not available');
            }
            
            return fetch(`../admin/api/chat_messages.php?supplier_id=${supplierId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayMessages(data.messages);
                        lastMessageCount = data.count;
                        // Mark messages as read when loaded
                        chatSystem.markMessagesAsRead();
                        return data;
                    } else {
                        console.error('Failed to load messages:', data.message);
                        throw new Error(data.message);
                    }
                })
                .catch(error => {
                    console.error('Error loading messages:', error);
                    throw error;
                });
        }

        function displayMessages(messages) {
            const chatMessages = document.getElementById('chatMessages');
            const noMessages = document.getElementById('noMessages');
            
            if (messages.length === 0) {
                noMessages.style.display = 'block';
                return;
            }
            
            noMessages.style.display = 'none';
            
            chatMessages.innerHTML = messages.map(message => {
                const messageClass = message.sender_type === 'supplier' ? 'sent' : 'received';
                const time = new Date(message.created_at).toLocaleString();
                
                // Message status indicator for supplier messages
                let statusIndicator = '';
                if (message.sender_type === 'supplier') {
                    const status = message.status || 'sent';
                    let statusIcon = '';
                    let statusColor = '';
                    
                    switch (status) {
                        case 'sent':
                            statusIcon = '✓';
                            statusColor = '#6c757d';
                            break;
                        case 'delivered':
                            statusIcon = '✓✓';
                            statusColor = '#6c757d';
                            break;
                        case 'read':
                            statusIcon = '✓✓';
                            statusColor = '#007bff';
                            break;
                    }
                    
                    statusIndicator = `<span class="message-status ms-1" style="color: ${statusColor}; font-size: 0.7rem;" data-status="${status}">${statusIcon}</span>`;
                }
                
                return `
                    <div class="message ${messageClass}" data-message-id="${message.id}">
                        <div class="message-bubble">
                            <div class="message-sender">${message.sender_name}</div>
                            <div class="message-content">${escapeHtml(message.message)}</div>
                            <div class="message-time">
                                ${time}
                                ${statusIndicator}
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
            
            // Scroll to bottom
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        function sendMessage() {
            const messageInput = document.getElementById('messageInput');
            const sendingIndicator = document.getElementById('sendingIndicator');
            const message = messageInput.value.trim();
            
            if (!message) {
                return;
            }
            
            // Disable input while sending
            messageInput.disabled = true;
            const sendButton = document.querySelector('.btn-send');
            const originalButtonContent = sendButton.innerHTML;
            sendButton.innerHTML = '<i class="bi bi-hourglass-split"></i>';
            sendButton.disabled = true;
            
            // Show sending indicator
            sendingIndicator.classList.add('show');
            
            // Use enhanced chat system
            chatSystem.sendMessage(message)
                .then(result => {
                    if (result.success) {
                        messageInput.value = '';
                        loadMessages();
                        
                        // Play send sound
                        if (chatSystem.sounds) {
                            chatSystem.sounds.playMessageSound();
                        }
                        
                        // Show success feedback
                        showNotification('Message sent successfully!', 'success');
                    }
                })
                .catch(error => {
                    console.error('Error sending message:', error);
                    showNotification('Failed to send message: ' + error.message, 'error');
                })
                .finally(() => {
                    // Hide sending indicator
                    sendingIndicator.classList.remove('show');
                    
                    // Re-enable input
                    messageInput.disabled = false;
                    sendButton.innerHTML = originalButtonContent;
                    sendButton.disabled = false;
                    messageInput.focus();
                });
        }
        
        function showNotification(message, type = 'info') {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `alert alert-${type === 'error' ? 'danger' : type === 'success' ? 'success' : 'info'} alert-dismissible fade show position-fixed`;
            notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            notification.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.body.appendChild(notification);
            
            // Auto remove after 3 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 3000);
        }



        function refreshChat(event) {
            // Show loading indicator
            const refreshBtn = event ? event.target.closest('button') : document.querySelector('button[onclick="refreshChat(event)"]');
            if (!refreshBtn) return;
            
            const originalIcon = refreshBtn.innerHTML;
            refreshBtn.innerHTML = '<i class="bi bi-arrow-clockwise" style="animation: spin 1s linear infinite;"></i>';
            refreshBtn.disabled = true;
            
            // Reload messages
            loadMessages().then(() => {
                // Show success indicator briefly
                refreshBtn.innerHTML = '<i class="bi bi-check-circle text-success"></i>';
                setTimeout(() => {
                    refreshBtn.innerHTML = originalIcon;
                    refreshBtn.disabled = false;
                }, 800);
            }).catch((error) => {
                // Show error indicator briefly
                refreshBtn.innerHTML = '<i class="bi bi-exclamation-circle text-danger"></i>';
                console.error('Refresh failed:', error);
                setTimeout(() => {
                    refreshBtn.innerHTML = originalIcon;
                    refreshBtn.disabled = false;
                }, 800);
            });
        }

        function clearChatHistory() {
            // Show confirmation dialog
            if (!confirm('Are you sure you want to clear all chat history? This action cannot be undone.')) {
                return;
            }
            
            // Show loading indicator
            const clearBtn = document.querySelector('button[onclick="clearChatHistory()"]');
            if (!clearBtn) return;
            
            const originalIcon = clearBtn.innerHTML;
            clearBtn.innerHTML = '<i class="bi bi-arrow-clockwise" style="animation: spin 1s linear infinite;"></i>';
            clearBtn.disabled = true;
            
            // Clear chat history via API
            fetch('../admin/api/chat_messages.php', {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    supplier_id: supplierId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Clear the chat messages display
                    const chatMessages = document.getElementById('chatMessages');
                    chatMessages.innerHTML = `
                        <div class="no-messages" id="noMessages">
                            <i class="bi bi-chat-text fa-3x mb-3 text-muted"></i>
                            <p>No messages yet. Start a conversation with the admin!</p>
                        </div>
                    `;
                    
                    // Show success notification
                    showNotification('Chat history cleared successfully!', 'success');
                    
                    // Show success indicator briefly
                    clearBtn.innerHTML = '<i class="bi bi-check-circle text-success"></i>';
                    setTimeout(() => {
                        clearBtn.innerHTML = originalIcon;
                        clearBtn.disabled = false;
                    }, 800);
                } else {
                    throw new Error(data.message || 'Failed to clear chat history');
                }
            })
            .catch(error => {
                console.error('Clear history failed:', error);
                showNotification('Failed to clear chat history. Please try again.', 'error');
                
                // Show error indicator briefly
                clearBtn.innerHTML = '<i class="bi bi-exclamation-circle text-danger"></i>';
                setTimeout(() => {
                    clearBtn.innerHTML = originalIcon;
                    clearBtn.disabled = false;
                }, 800);
            });
        }

        function insertQuickMessage(message) {
            document.getElementById('messageInput').value = message;
            document.getElementById('messageInput').focus();
        }

        function handleKeyPress(event) {
            if (event.key === 'Enter') {
                sendMessage();
            }
        }

        function startMessagePolling() {
            messagePolling = setInterval(() => {
                fetch(`../admin/api/chat_messages.php?supplier_id=${supplierId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.count > lastMessageCount) {
                            displayMessages(data.messages);
                            lastMessageCount = data.count;
                        }
                    })
                    .catch(error => {
                        console.error('Error polling messages:', error);
                    });
            }, 3000); // Poll every 3 seconds
        }

        function stopMessagePolling() {
            if (messagePolling) {
                clearInterval(messagePolling);
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Disconnect chat system when page is about to unload
        window.addEventListener('beforeunload', () => {
            if (chatSystem) {
                chatSystem.disconnect();
            }
            stopMessagePolling();
        });
    </script>
</body>
</html>
