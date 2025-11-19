<?php
// ====== Access control & dependencies ======
include_once '../config/session.php';
require_once '../config/database.php';
require_once '../models/order.php';
require_once '../models/delivery.php';
require_once '../models/supplier.php';

// ---- Admin auth guard (namespaced) ----
if (empty($_SESSION['admin']['user_id'])) {
    header("Location: ../login.php");
    exit();
}
if (($_SESSION['admin']['role'] ?? null) !== 'management') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$supplier_filter = $_GET['supplier'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';

// Build WHERE clause for filtering
$where_conditions = [];
$params = [];

if (!empty($status_filter)) {
    $where_conditions[] = "d.status = ?";
    $params[] = $status_filter;
}

if (!empty($supplier_filter)) {
    $where_conditions[] = "s.id = ?";
    $params[] = $supplier_filter;
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(d.delivery_date) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(d.delivery_date) <= ?";
    $params[] = $date_to;
}

if (!empty($search)) {
    $where_conditions[] = "(o.id LIKE ? OR s.company_name LIKE ? OR i.name LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get all deliveries with order and supplier information for export
$query = "SELECT 
    d.id as delivery_id,
    d.status as delivery_status,
    d.delivery_date,
    d.driver_name,
    d.vehicle_info,
    d.tracking_number,
    d.delivery_address,
    d.notes,
    d.updated_at,
    o.id as order_id,
    o.quantity,
    o.unit_price,
    o.confirmation_status as order_status,
    o.order_date,
    s.id as supplier_id,
    s.company_name as supplier_name,
    s.contact_person,
    s.phone,
    s.email,
    i.name as item_name,
    i.category,
    COALESCE(o.quantity * o.unit_price, 0) as total_amount
    FROM deliveries d
    LEFT JOIN orders o ON d.order_id = o.id
    LEFT JOIN suppliers s ON o.supplier_id = s.id
    LEFT JOIN inventory i ON o.inventory_id = i.id
    {$where_clause}
    ORDER BY d.delivery_date DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers for CSV download
$filename = 'deliveries_export_' . date('Y-m-d_H-i-s') . '.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Create file pointer connected to the output stream
$output = fopen('php://output', 'w');

// Add CSV headers
$headers = [
    'Delivery ID',
    'Order ID',
    'Supplier Name',
    'Contact Person',
    'Phone',
    'Email',
    'Item Name',
    'Category',
    'Quantity',
    'Unit Price',
    'Total Amount',
    'Order Status',
    'Delivery Status',
    'Order Date',
    'Delivery Date',
    'Driver Name',
    'Vehicle Info',
    'Tracking Number',
    'Delivery Address',
    'Notes',
    'Last Updated'
];

fputcsv($output, $headers);

// Add data rows
foreach ($deliveries as $delivery) {
    $row = [
        $delivery['delivery_id'],
        $delivery['order_id'],
        $delivery['supplier_name'],
        $delivery['contact_person'],
        $delivery['phone'],
        $delivery['email'],
        $delivery['item_name'],
        $delivery['category'],
        $delivery['quantity'],
        $delivery['unit_price'],
        $delivery['total_amount'],
        ucfirst(str_replace('_', ' ', $delivery['order_status'])),
        ucfirst(str_replace('_', ' ', $delivery['delivery_status'])),
        $delivery['order_date'],
        $delivery['delivery_date'],
        $delivery['driver_name'],
        $delivery['vehicle_info'],
        $delivery['tracking_number'],
        $delivery['delivery_address'],
        $delivery['notes'],
        $delivery['updated_at']
    ];
    
    fputcsv($output, $row);
}

// Close the file pointer
fclose($output);
exit();
?>