<?php
// ====== Access control & dependencies (corrected) ======
include_once '../config/session.php';   // namespaced sessions (admin/staff)
require_once '../config/database.php';  // DB connection

// Load all model classes this page uses
require_once '../models/inventory.php';
require_once '../models/supplier.php';
require_once '../models/order.php';
require_once '../models/sales_transaction.php';
require_once '../models/alert_log.php';
// If your dashboard uses more models, include them here with require_once

// ---- Admin auth guard (namespaced) ----
if (empty($_SESSION['admin']['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// DB connection
$db = (new Database())->getConnection();

// Instantiate model objects
$supplier = new Supplier($db);
$inventory = new Inventory($db);
$order = new Order($db);
$sales = new SalesTransaction($db);
$alerts = new AlertLog($db);

// Compute ordered inventory IDs (non-cancelled orders)
$orderedInventoryIds = [];
try {
    $orderedStmt = $db->query("SELECT DISTINCT inventory_id FROM orders WHERE confirmation_status <> 'cancelled'");
    while ($or = $orderedStmt->fetch(PDO::FETCH_ASSOC)) { $orderedInventoryIds[] = (int)$or['inventory_id']; }
} catch (PDOException $e) { /* ignore filtering if query fails */ }

$message = '';
$messageType = '';

// Unified Credits helpers (file-based; shared with API)
function ensureLogsDirAdmin() {
    $logsDir = __DIR__ . '/../logs';
    if (!file_exists($logsDir)) {
        mkdir($logsDir, 0755, true);
    }
}

function readUnifiedCredits() {
    ensureLogsDirAdmin();
    $file = __DIR__ . '/../logs/unified_credits.json';
    if (!file_exists($file)) {
        file_put_contents($file, json_encode(['credits' => 100], JSON_PRETTY_PRINT));
        return 100;
    }
    $data = json_decode(@file_get_contents($file), true);
    if (!is_array($data) || !isset($data['credits'])) {
        file_put_contents($file, json_encode(['credits' => 100], JSON_PRETTY_PRINT));
        return 100;
    }
    return (int)$data['credits'];
}

function writeUnifiedCredits($credits) {
    ensureLogsDirAdmin();
    $file = __DIR__ . '/../logs/unified_credits.json';
    file_put_contents($file, json_encode(['credits' => max(0, (int)$credits)], JSON_PRETTY_PRINT));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Add new supplier
        if ($_POST['action'] === 'add') {
            $supplier->name = $_POST['name'];
            $supplier->contact_phone = $_POST['contact_phone'];
            $supplier->email = $_POST['email'];
            $supplier->address = $_POST['address'];
            $supplier->status = $_POST['status'];

            if ($supplier->create()) {
                $message = "Supplier was created successfully.";
                $messageType = "success";
            } else {
                $message = "Unable to create supplier.";
                $messageType = "danger";
            }
        }
        // Update supplier
        else if ($_POST['action'] === 'update') {
            $supplier->id = $_POST['id'];
            $supplier->name = $_POST['name'];
            $supplier->contact_phone = $_POST['contact_phone'];
            $supplier->email = $_POST['email'];
            $supplier->address = $_POST['address'];
            $supplier->status = $_POST['status'];

            if ($supplier->update()) {
                $message = "Supplier was updated successfully.";
                $messageType = "success";
            } else {
                $message = "Unable to update supplier.";
                $messageType = "danger";
            }
        }
        // Delete supplier
        else if ($_POST['action'] === 'delete') {
            $supplier->id = $_POST['id'];
            if ($supplier->delete()) {
                $message = "Supplier was deleted successfully.";
                $messageType = "success";
            } else {
                $message = "Unable to delete supplier.";
                $messageType = "danger";
            }
        }
        // Send SMS via IPROG
        else if ($_POST['action'] === 'send_sms') {
            $phone = trim($_POST['phone'] ?? '');
            $text = trim($_POST['message'] ?? '');

            if ($phone === '' || $text === '') {
                $message = "Phone and message are required to send SMS.";
                $messageType = "danger";
            } else {
                $apiUrl = 'https://sms.iprogtech.com/api/v1/sms_messages';
                $apiToken = '6fdac0ca567c6720d239054f22ac35c850030cbe';

                $payload = [
                    'api_token'    => $apiToken,
                    'message'      => $text,
                    'phone_number' => $phone,
                ];

                $ch = curl_init($apiUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/x-www-form-urlencoded'
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr = curl_error($ch);
                curl_close($ch);

                if ($curlErr) {
                    $message = "SMS send failed: " . $curlErr;
                    $messageType = "danger";
                } else if ($httpCode >= 200 && $httpCode < 300) {
                    // Deduct ONE Unified Credit strictly on successful send
                    $currentCredits = readUnifiedCredits();
                    $remaining = max(0, $currentCredits - 1);
                    writeUnifiedCredits($remaining);

                    $message = "SMS sent to " . htmlspecialchars($phone) . ". Credits remaining: " . $remaining . ". Response: " . htmlspecialchars($response);
                    $messageType = "success";
                } else {
                    $message = "SMS send failed (HTTP " . $httpCode . "). Response: " . htmlspecialchars($response);
                    $messageType = "danger";
                }
            }
        }
    }
}

$stmt = $supplier->readAll();  // Get all suppliers

// Precompute product counts per supplier (matching supplier_details.php)
// Count from supplier_catalog table with is_deleted = 0 filter (same as supplier_details.php)
$supplierProductCounts = [];
try {
    $cntStmt = $db->query("SELECT supplier_id, COUNT(*) AS cnt FROM supplier_catalog WHERE COALESCE(is_deleted, 0) = 0 GROUP BY supplier_id");
    while ($r = $cntStmt->fetch(PDO::FETCH_ASSOC)) {
        $supplierProductCounts[(int)$r['supplier_id']] = (int)$r['cnt'];
    }
} catch (PDOException $e) {
    $supplierProductCounts = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Suppliers - Inventory & Stock Control System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" />
    <link rel="stylesheet" href="../assets/css/style.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" />
    <style>
    </style>
</head>
<body>
    <?php include_once 'includes/header.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <?php include_once 'includes/sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <h1 class="h2">Suppliers</h1>

                <div class="text-end mb-3">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSupplierModal">
                        <i class="bi bi-plus-circle me-2"></i>Add New Supplier
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
                        <i class="bi bi-table me-1"></i> Suppliers
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover" id="suppliersTable">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Contact Phone</th>
                                        <th>Email</th>
                                        <th>Status</th>
                                        <th>Products</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = $stmt->fetch(PDO::FETCH_ASSOC)):
                                        $product_count = $supplierProductCounts[(int)$row['id']] ?? 0;
                                    ?>
                                        <tr data-supplier-id="<?php echo $row['id']; ?>">
                                            <td><?php echo htmlspecialchars($row['id']); ?></td>
                                            <td>
                                                <a href="supplier_details.php?supplier_id=<?php echo $row['id']; ?>" 
                                                   class="btn btn-sm btn-primary fw-bold">
                                                    <?php echo htmlspecialchars($row['name']); ?>
                                                </a>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['contact_phone']); ?></td>
                            <td><?php echo htmlspecialchars($row['email']); ?></td>
                            <td>
                                                <?php if ($row['status'] == 'active'): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary fw-bold supplier-product-count" data-supplier-id="<?php echo $row['id']; ?>">
                                                    <?php echo $product_count; ?> Products
                                                </span>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-primary edit-btn"
                                                    data-id="<?php echo htmlspecialchars($row['id']); ?>"
                                                    data-name="<?php echo htmlspecialchars($row['name']); ?>"
                                                    data-phone="<?php echo htmlspecialchars($row['contact_phone']); ?>"
                                                    data-email="<?php echo htmlspecialchars($row['email']); ?>"
                                                    data-address="<?php echo htmlspecialchars($row['address']); ?>"
                                                    data-status="<?php echo htmlspecialchars($row['status']); ?>"
                                                    data-bs-toggle="modal" data-bs-target="#editSupplierModal">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-danger delete-btn"
                                                    data-id="<?php echo htmlspecialchars($row['id']); ?>"
                                                    data-name="<?php echo htmlspecialchars($row['name']); ?>"
                                                    data-bs-toggle="modal" data-bs-target="#deleteSupplierModal">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-info sms-btn"
                                                    data-name="<?php echo htmlspecialchars($row['name']); ?>"
                                                    data-phone="<?php echo htmlspecialchars($row['contact_phone']); ?>"
                                                    data-bs-toggle="modal" data-bs-target="#sendSmsModal">
                                                    <i class="bi bi-telephone me-2"></i>SMS
                                                </button>
                                                <button type="button" class="btn btn-sm btn-success chat-btn"
                                                    data-supplier-id="<?php echo htmlspecialchars($row['id']); ?>"
                                                    data-supplier-name="<?php echo htmlspecialchars($row['name']); ?>"
                                                    data-bs-toggle="modal" data-bs-target="#chatModal">
                                                    <i class="bi bi-chat-dots me-1"></i>Chat
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Add Supplier Modal -->
                <div class="modal fade" id="addSupplierModal" tabindex="-1" aria-labelledby="addSupplierModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="addSupplierModalLabel">Add New Supplier</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form method="POST">
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="add" />
                                    <div class="mb-3">
                                        <label for="name" class="form-label">Supplier Name</label>
                                        <input type="text" class="form-control" id="name" name="name" required />
                                    </div>
                                    <div class="mb-3">
                                        <label for="contact_phone" class="form-label">Contact Phone</label>
                                        <input type="text" class="form-control" id="contact_phone" name="contact_phone" required />
                                    </div>
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email</label>
                                        <input type="email" class="form-control" id="email" name="email" required />
                                    </div>
                                    <div class="mb-3">
                                        <label for="address" class="form-label">Address</label>
                                        <textarea class="form-control" id="address" name="address" rows="3" required></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label for="status" class="form-label">Status</label>
                                        <select class="form-select" id="status" name="status" required>
                                            <option value="active">Active</option>
                                            <option value="inactive">Inactive</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Add Supplier</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Edit Supplier Modal -->
                <div class="modal fade" id="editSupplierModal" tabindex="-1" aria-labelledby="editSupplierModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="editSupplierModalLabel">Edit Supplier</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form method="POST">
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="update" />
                                    <input type="hidden" name="id" id="edit-id" />
                                    <div class="mb-3">
                                        <label for="edit-name" class="form-label">Supplier Name</label>
                                        <input type="text" class="form-control" id="edit-name" name="name" required />
                                    </div>
                                    <div class="mb-3">
                                        <label for="edit-phone" class="form-label">Contact Phone</label>
                                        <input type="text" class="form-control" id="edit-phone" name="contact_phone" required />
                                    </div>
                                    <div class="mb-3">
                                        <label for="edit-email" class="form-label">Email</label>
                                        <input type="email" class="form-control" id="edit-email" name="email" required />
                                    </div>
                                    <div class="mb-3">
                                        <label for="edit-address" class="form-label">Address</label>
                                        <textarea class="form-control" id="edit-address" name="address" rows="3" required></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label for="edit-status" class="form-label">Status</label>
                                        <select class="form-select" id="edit-status" name="status" required>
                                            <option value="active">Active</option>
                                            <option value="inactive">Inactive</option>
                                        </select>
                                    </div>

                                </div>

                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Update Supplier</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Delete Supplier Modal -->
                <div class="modal fade" id="deleteSupplierModal" tabindex="-1" aria-labelledby="deleteSupplierModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="deleteSupplierModalLabel">Delete Supplier</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form method="POST">
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="delete" />
                                    <input type="hidden" name="id" id="delete-id" />
                                    <p>Are you sure you want to delete the supplier: <strong id="delete-name"></strong>?</p>
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

                <!-- Send SMS Modal -->
                <div class="modal fade" id="sendSmsModal" tabindex="-1" aria-labelledby="sendSmsModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="sendSmsModalLabel">Send SMS to Supplier</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form id="sendSmsForm" method="POST">
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="send_sms" />
                                    <div class="mb-3">
                                        <label for="sms-phone" class="form-label">Phone</label>
                                        <input type="text" class="form-control" id="sms-phone" name="phone" readonly />
                                    </div>
                                    <div class="mb-3">
                                        <label for="sms-message" class="form-label">Message</label>
                                        <textarea class="form-control" id="sms-message" name="message" rows="4" required></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Send SMS</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Enhanced Chat Modal -->
                <div class="modal fade" id="chatModal" tabindex="-1" aria-labelledby="chatModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg chat-modal-dialog">
                        <div class="modal-content chat-modal-content">
                            <div class="modal-header bg-primary text-white">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-chat-dots me-2"></i>
                                    <div>
                                        <h5 class="modal-title mb-0" id="chatModalLabel">Chat with <span id="chatSupplierName"></span></h5>
                                        <small id="connectionStatus" class="text-light opacity-75">
                                            <i class="bi bi-wifi-off"></i> Disconnected
                                        </small>
                                    </div>
                                </div>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-0">
                                <div id="chatMessages" class="chat-messages-container" style="height: 400px; overflow-y: auto; padding: 15px; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);">
                                    <!-- Messages will be loaded here -->
                                    <div class="text-center text-muted">
                                        <i class="bi bi-chat-square-dots fs-1"></i>
                                        <p>Start a conversation with this supplier</p>
                                    </div>
                                </div>
                                <div id="typingIndicator" class="px-3 py-2" style="display: none; min-height: 30px;">
                                    <!-- Typing indicator will appear here -->
                                </div>
                                <div class="border-top p-3 bg-white">
                                    <div class="input-group">
                                        <input type="text" id="chatMessageInput" class="form-control border-0 shadow-sm" 
                                               placeholder="Type your message..." maxlength="500"
                                               style="border-radius: 25px 0 0 25px; padding: 12px 20px;">
                                        <button class="btn btn-primary px-4" type="button" id="sendChatMessage"
                                                style="border-radius: 0 25px 25px 0;">
                                            <i class="bi bi-send"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted">Press Enter to send • Real-time messaging enabled</small>
                                </div>
                            </div>
                            <div class="modal-footer bg-light">
                                <div class="d-flex justify-content-between w-100">
                                    <div>
                                        <small class="text-muted">
                                            <i class="bi bi-shield-check"></i> Secure messaging
                                        </small>
                                    </div>
                                    <div>
                                        <button type="button" class="btn btn-outline-secondary btn-sm me-2" id="clearChatHistory">
                                            <i class="bi bi-trash"></i> Clear History
                                        </button>
                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
                                            <i class="bi bi-x-lg"></i> Close
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <style>
                /* Enhanced Chat Modal Responsiveness */
                .chat-modal-dialog {
                    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
                }
                
                .chat-modal-content {
                    transform: scale(0.7);
                    opacity: 0;
                    transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
                }
                
                #chatModal.show .chat-modal-content {
                    transform: scale(1);
                    opacity: 1;
                }
                
                /* Mobile Responsiveness */
                @media (max-width: 768px) {
                    .chat-modal-dialog {
                        max-width: 95%;
                        margin: 0.5rem auto;
                        height: calc(100vh - 1rem);
                    }
                    
                    .chat-modal-content {
                        height: 100%;
                        border-radius: 10px !important;
                    }
                    
                    .modal-body {
                        padding: 0 !important;
                    }
                    
                    .chat-messages-container {
                        height: calc(100vh - 280px) !important;
                        padding: 10px !important;
                    }
                    
                    .modal-header {
                        padding: 0.75rem 1rem;
                    }
                    
                    .modal-footer {
                        padding: 0.75rem 1rem;
                    }
                    
                    #chatMessageInput {
                        font-size: 16px; /* Prevents zoom on iOS */
                    }
                }
                
                @media (max-width: 480px) {
                    .chat-modal-dialog {
                        max-width: 100%;
                        margin: 0;
                        height: 100vh;
                    }
                    
                    .chat-modal-content {
                        border-radius: 0 !important;
                        height: 100vh;
                    }
                    
                    .chat-messages-container {
                        height: calc(100vh - 250px) !important;
                        padding: 8px !important;
                    }
                    
                    .modal-header {
                        padding: 0.5rem 0.75rem;
                    }
                    
                    .modal-header h5 {
                        font-size: 1rem;
                    }
                    
                    .modal-footer {
                        padding: 0.5rem 0.75rem;
                    }
                    
                    .input-group {
                        margin-bottom: 0.5rem;
                    }
                    
                    #chatMessageInput {
                        padding: 10px 15px;
                    }
                    
                    .btn {
                        padding: 10px 15px;
                    }
                }
                
                /* Enhanced Chat Container */
                .chat-messages-container {
                    background-image: 
                        radial-gradient(circle at 20% 80%, rgba(120, 119, 198, 0.1) 0%, transparent 50%),
                        radial-gradient(circle at 80% 20%, rgba(255, 119, 198, 0.1) 0%, transparent 50%);
                    scroll-behavior: smooth;
                }
                
                /* Custom Scrollbar */
                .chat-messages-container::-webkit-scrollbar {
                    width: 6px;
                }
                
                .chat-messages-container::-webkit-scrollbar-track {
                    background: rgba(0,0,0,0.1);
                    border-radius: 3px;
                }
                
                .chat-messages-container::-webkit-scrollbar-thumb {
                    background: rgba(0,0,0,0.3);
                    border-radius: 3px;
                }
                
                .chat-messages-container::-webkit-scrollbar-thumb:hover {
                    background: rgba(0,0,0,0.5);
                }

                .message-item {
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

                .typing-dots {
                    display: inline-flex;
                    align-items: center;
                    gap: 2px;
                }

                .typing-dots span {
                    width: 6px;
                    height: 6px;
                    border-radius: 50%;
                    background-color: #6c757d;
                    animation: typing 1.4s infinite ease-in-out;
                }

                .typing-dots span:nth-child(1) { animation-delay: -0.32s; }
                .typing-dots span:nth-child(2) { animation-delay: -0.16s; }

                @keyframes typing {
                    0%, 80%, 100% {
                        transform: scale(0.8);
                        opacity: 0.5;
                    }
                    40% {
                        transform: scale(1);
                        opacity: 1;
                    }
                }

                .unread-badge {
                    animation: pulse 2s infinite;
                }

                @keyframes pulse {
                    0% { transform: scale(1); }
                    50% { transform: scale(1.1); }
                    100% { transform: scale(1); }
                }

                #chatMessageInput:focus {
                    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
                    border-color: #80bdff;
                }

                .chat-btn {
                    transition: all 0.3s ease;
                }

                .chat-btn:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
                }
                </style>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script src="assets/sounds/notification.js"></script>
    <script>
        // Enhanced Chat System with Real-time Features
        class EnhancedChatSystem {
            constructor() {
                this.currentSupplierId = null;
                this.eventSource = null;
                this.lastMessageId = 0;
                this.typingTimer = null;
                this.isTyping = false;
                this.notificationSound = new NotificationSound();
                this.unreadCounts = new Map();
                this.isTabActive = true;
                this.notificationsEnabled = false;
                
                this.initEventListeners();
                this.requestNotificationPermission();
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

            async requestNotificationPermission() {
                if ('Notification' in window) {
                    const permission = await Notification.requestPermission();
                    this.notificationsEnabled = permission === 'granted';
                }
            }

            showBrowserNotification(title, body, icon = null) {
                if (!this.notificationsEnabled || this.isTabActive) return;

                const notification = new Notification(title, {
                    body: body,
                    icon: icon || '/favicon.ico',
                    badge: '/favicon.ico',
                    tag: 'chat-message',
                    requireInteraction: false,
                    silent: false
                });

                notification.onclick = () => {
                    window.focus();
                    notification.close();
                };

                setTimeout(() => notification.close(), 5000);
            }

            connectSSE(supplierId) {
                this.disconnectSSE();
                
                this.eventSource = new EventSource(`api/chat_sse.php?supplier_id=${supplierId}&last_message_id=${this.lastMessageId}`);
                
                this.eventSource.onopen = () => {
                    console.log('SSE connection established');
                    this.updateConnectionStatus(true);
                };

                this.eventSource.addEventListener('connected', (event) => {
                    const data = JSON.parse(event.data);
                    console.log('Connected to chat SSE:', data);
                });

                this.eventSource.addEventListener('new_message', (event) => {
                    const message = JSON.parse(event.data);
                    this.handleNewMessage(message);
                });

                this.eventSource.addEventListener('typing', (event) => {
                    const data = JSON.parse(event.data);
                    this.handleTypingIndicator(data.users);
                });

                this.eventSource.addEventListener('heartbeat', (event) => {
                    console.log('SSE heartbeat received');
                });

                this.eventSource.onerror = (error) => {
                    console.error('SSE connection error:', error);
                    this.updateConnectionStatus(false);
                    
                    // Attempt to reconnect after 5 seconds
                    setTimeout(() => {
                        if (this.currentSupplierId) {
                            this.connectSSE(this.currentSupplierId);
                        }
                    }, 5000);
                };
            }

            disconnectSSE() {
                if (this.eventSource) {
                    this.eventSource.close();
                    this.eventSource = null;
                }
                this.updateConnectionStatus(false);
            }

            updateConnectionStatus(connected) {
                const statusElement = $('#connectionStatus');
                if (connected) {
                    statusElement.html('<i class="bi bi-wifi text-success"></i> Connected').removeClass('text-danger').addClass('text-success');
                } else {
                    statusElement.html('<i class="bi bi-wifi-off text-danger"></i> Disconnected').removeClass('text-success').addClass('text-danger');
                }
            }

            handleNewMessage(message) {
                this.lastMessageId = Math.max(this.lastMessageId, message.id);
                
                // Add message to chat
                this.addMessageToChat(message);
                
                // Play sound notification
                this.notificationSound.playMessageSound();
                
                // Show browser notification for supplier messages
                if (message.sender_type === 'supplier') {
                    this.showBrowserNotification(
                        `New message from ${message.sender_name}`,
                        message.message.substring(0, 100) + (message.message.length > 100 ? '...' : '')
                    );
                    
                    // Update unread count
                    this.updateUnreadCount(message.supplier_id, 1);
                }
            }

            addMessageToChat(message) {
                const chatMessages = $('#chatMessages');
                const messageClass = message.sender_type === 'admin' ? 'admin-message' : 'supplier-message';
                const alignClass = message.sender_type === 'admin' ? 'text-end' : 'text-start';
                const bgClass = message.sender_type === 'admin' ? 'bg-primary text-white' : 'bg-light';
                
                // Message status indicator for admin messages
                let statusIndicator = '';
                if (message.sender_type === 'admin') {
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
                
                const messageHtml = `
                    <div class="mb-3 ${alignClass} message-item" data-message-id="${message.id}">
                        <div class="d-inline-block p-2 rounded ${bgClass}" style="max-width: 70%; animation: fadeInUp 0.3s ease;">
                            <div class="fw-bold small">${message.sender_name}</div>
                            <div>${this.escapeHtml(message.message)}</div>
                            <div class="small opacity-75">
                                ${message.created_at}
                                ${statusIndicator}
                            </div>
                        </div>
                    </div>
                `;
                
                chatMessages.append(messageHtml);
                this.scrollToBottom();
            }

            handleTypingIndicator(users) {
                const typingIndicator = $('#typingIndicator');
                const relevantUsers = users.filter(user => 
                    (user.sender_type === 'supplier' && this.currentSupplierId) ||
                    (user.sender_type === 'admin' && this.currentSupplierId)
                );

                if (relevantUsers.length > 0) {
                    const names = relevantUsers.map(user => user.sender_name).join(', ');
                    typingIndicator.html(`
                        <div class="text-muted small">
                            <span class="typing-dots">
                                <span></span><span></span><span></span>
                            </span>
                            ${names} ${relevantUsers.length === 1 ? 'is' : 'are'} typing...
                        </div>
                    `).show();
                    this.scrollToBottom();
                } else {
                    typingIndicator.hide();
                }
            }

            sendTypingIndicator() {
                if (!this.currentSupplierId) return;

                $.ajax({
                    url: 'api/typing_indicator.php',
                    method: 'POST',
                    data: {
                        supplier_id: this.currentSupplierId,
                        sender_type: 'admin',
                        sender_name: 'Admin'
                    },
                    dataType: 'json'
                });
            }

            stopTypingIndicator() {
                if (!this.currentSupplierId) return;

                $.ajax({
                    url: 'api/typing_indicator.php',
                    method: 'DELETE',
                    data: {
                        supplier_id: this.currentSupplierId,
                        sender_type: 'admin'
                    },
                    dataType: 'json'
                });
            }

            updateUnreadCount(supplierId, increment) {
                const currentCount = this.unreadCounts.get(supplierId) || 0;
                const newCount = currentCount + increment;
                this.unreadCounts.set(supplierId, Math.max(0, newCount));
                
                // Update UI badge
                const chatBtn = $(`.chat-btn[data-supplier-id="${supplierId}"]`);
                let badge = chatBtn.find('.unread-badge');
                
                if (newCount > 0) {
                    if (badge.length === 0) {
                        badge = $('<span class="badge bg-danger unread-badge ms-1"></span>');
                        chatBtn.append(badge);
                    }
                    badge.text(newCount);
                } else {
                    badge.remove();
                }
            }

            escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            scrollToBottom() {
                const chatMessages = $('#chatMessages')[0];
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }

            openChat(supplierId, supplierName) {
                this.currentSupplierId = supplierId;
                $('#chatSupplierName').text(supplierName);
                
                // Reset unread count
                this.updateUnreadCount(supplierId, -this.unreadCounts.get(supplierId) || 0);
                
                // Load initial messages
                this.loadChatMessages();
                
                // Mark messages as read
                this.markMessagesAsRead();
                
                // Connect to SSE
                this.connectSSE(supplierId);
            }

            closeChat() {
                this.disconnectSSE();
                this.stopTypingIndicator();
                this.currentSupplierId = null;
            }

            loadChatMessages() {
                if (!this.currentSupplierId) return;
                
                $.ajax({
                    url: 'api/chat_messages.php',
                    method: 'GET',
                    data: { supplier_id: this.currentSupplierId },
                    dataType: 'json',
                    success: (response) => {
                        if (response.success) {
                            this.displayMessages(response.messages);
                            if (response.messages.length > 0) {
                                this.lastMessageId = Math.max(...response.messages.map(m => m.id));
                            }
                        }
                    },
                    error: () => {
                        console.error('Error loading chat messages');
                    }
                });
            }

            displayMessages(messages) {
                const chatMessages = $('#chatMessages');
                chatMessages.empty();
                
                if (messages.length === 0) {
                    chatMessages.html(`
                        <div class="text-center text-muted">
                            <i class="bi bi-chat-square-dots fs-1"></i>
                            <p>Start a conversation with this supplier</p>
                        </div>
                    `);
                    return;
                }
                
                messages.forEach(message => this.addMessageToChat(message));
            }

            sendMessage() {
                const messageInput = $('#chatMessageInput');
                const message = messageInput.val().trim();
                
                // Only send when chat modal is open
                if (!$('#chatModal').hasClass('show')) return;
                
                if (!message || !this.currentSupplierId) return;
                
                // Stop typing indicator
                this.stopTypingIndicator();
                
                $.ajax({
                    url: 'api/chat_messages.php',
                    method: 'POST',
                    data: {
                        supplier_id: this.currentSupplierId,
                        message: message,
                        sender_type: 'admin'
                    },
                    dataType: 'json',
                    success: (response) => {
                        if (response.success) {
                            messageInput.val('');
                            
                            // Simulate delivery status update after a short delay
                            setTimeout(() => {
                                this.updateMessageStatus(response.message_id, 'delivered');
                            }, 1000);
                        } else {
                            alert('Error sending message: ' + response.message);
                        }
                    },
                    error: () => {
                        alert('Error sending message. Please try again.');
                    }
                });
            }

            updateMessageStatus(messageId, status) {
                $.ajax({
                    url: 'api/message_status.php',
                    method: 'POST',
                    data: {
                        message_id: messageId,
                        status: status
                    },
                    dataType: 'json',
                    success: (response) => {
                        if (response.success) {
                            this.updateMessageStatusUI(messageId, status);
                        }
                    },
                    error: () => {
                        console.error('Error updating message status');
                    }
                });
            }

            updateMessageStatusUI(messageId, status) {
                const messageElement = $(`.message-item[data-message-id="${messageId}"]`);
                const statusElement = messageElement.find('.message-status');
                
                if (statusElement.length > 0) {
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
                    
                    statusElement.attr('data-status', status)
                                 .css('color', statusColor)
                                 .text(statusIcon);
                }
            }

            markMessagesAsRead() {
                if (!this.currentSupplierId) return;
                
                $.ajax({
                    url: 'api/message_status.php',
                    method: 'POST',
                    data: {
                        supplier_id: this.currentSupplierId,
                        sender_type: 'admin',
                        mark_read: true
                    },
                    dataType: 'json',
                    success: (response) => {
                        if (response.success) {
                            // Update UI for all supplier messages to show as read
                            $('.message-item').each(function() {
                                const messageElement = $(this);
                                const isSupplierMessage = messageElement.find('.bg-light').length > 0;
                                if (isSupplierMessage) {
                                    // Supplier messages are now read by admin
                                    console.log('Marked supplier message as read');
                                }
                            });
                        }
                    }
                });
            }

            clearChatHistory() {
                if (!this.currentSupplierId) return;
                
                $.ajax({
                    url: 'api/chat_messages.php',
                    method: 'DELETE',
                    data: { supplier_id: this.currentSupplierId },
                    dataType: 'json',
                    success: (response) => {
                        if (response.success) {
                            this.loadChatMessages();
                        }
                    },
                    error: () => {
                        alert('Error clearing chat history');
                    }
                });
            }
        }

        // Initialize enhanced chat system
        const chatSystem = new EnhancedChatSystem();

        $(document).ready(function() {
            $('#suppliersTable').DataTable();

            // Edit button populates the Edit modal
            $('.edit-btn').click(function() {
                $('#edit-id').val($(this).data('id'));
                $('#edit-name').val($(this).data('name'));
                $('#edit-phone').val($(this).data('phone'));
                $('#edit-email').val($(this).data('email'));
                $('#edit-address').val($(this).data('address'));
                $('#edit-status').val($(this).data('status'));
            });

            $('.delete-btn').click(function() {
                $('#delete-id').val($(this).data('id'));
                $('#delete-name').text($(this).data('name'));
            });

            // Send SMS Button
            $('.sms-btn').click(function() {
                var phone = $(this).data('phone');
                var name = $(this).data('name');
                $('#sms-phone').val(phone);
                var defaultMessage = 'Hi ' + (name || 'Supplier') + ', Welcome to IPROG SMS API.';
                $('#sms-message').val(defaultMessage);
            });

            // Live product count updates
            function refreshSupplierProductCounts() {
                $.ajax({
                    url: 'ajax/get_supplier_product_counts.php',
                    method: 'GET',
                    dataType: 'json',
                    success: function(resp) {
                        if (resp.success && resp.counts) {
                            Object.keys(resp.counts).forEach(function(sid) {
                                var count = resp.counts[sid];
                                $(".supplier-product-count[data-supplier-id='" + sid + "']").text(count + ' Products');
                            });
                        }
                    }
                });
            }
            // Initial load and periodic refresh
            refreshSupplierProductCounts();
            setInterval(refreshSupplierProductCounts, 5000);

                            

            // Enhanced Chat functionality with real-time features
            let typingTimer;

            // Chat button click - ensure no auto-send and just open chat
            $('.chat-btn').click(function(e) {
                e.preventDefault();
                e.stopPropagation();

                const supplierId = $(this).data('supplier-id');
                const supplierName = $(this).data('supplier-name');

                // Clear any residual text to prevent unintended sends
                $('#chatMessageInput').val('');

                // Use enhanced chat system
                chatSystem.openChat(supplierId, supplierName);
            });

            // Enhanced send message with typing indicators
            $('#sendChatMessage').click(function() {
                chatSystem.sendMessage();
            });

            // Enhanced Enter key handling with typing indicators
            $('#chatMessageInput').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    chatSystem.sendMessage();
                }
            });

            // Typing indicator functionality
            $('#chatMessageInput').on('input', function() {
                if (chatSystem.currentSupplierId) {
                    chatSystem.sendTypingIndicator();
                    
                    clearTimeout(typingTimer);
                    typingTimer = setTimeout(() => {
                        chatSystem.stopTypingIndicator();
                    }, 1000);
                }
            });

            // Enhanced clear chat history
            $('#clearChatHistory').click(function() {
                if (confirm('Are you sure you want to clear the chat history? This action cannot be undone.')) {
                    chatSystem.clearChatHistory();
                }
            });

            // Enhanced modal event handling
            $('#chatModal').on('shown.bs.modal', function() {
                // Clear input on open to avoid any residual message resends
                $('#chatMessageInput').val('').focus();
            });

            $('#chatModal').on('hidden.bs.modal', function() {
                chatSystem.closeChat();
            });

            // Legacy functions for backward compatibility - now handled by enhanced chat system
            function loadChatMessages() {
                // Handled by chatSystem.loadChatMessages()
                console.log('loadChatMessages called - using enhanced chat system');
            }

            function sendMessage() {
                // Guard: only send when chat modal is visible
                if (!$('#chatModal').hasClass('show')) return;
                chatSystem.sendMessage();
            }

            function clearChatHistory() {
                // Handled by chatSystem.clearChatHistory()
                if (confirm('Are you sure you want to clear the chat history? This action cannot be undone.')) {
                    chatSystem.clearChatHistory();
                }
            }
        });
    </script>
</body>
</html>
