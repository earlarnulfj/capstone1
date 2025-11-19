<?php
session_start();

// Database connection and models
require_once '../config/database.php';
require_once '../models/inventory.php';

// Check if supplier is logged in
if (!isset($_SESSION['supplier']) || empty($_SESSION['supplier'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Set content type to JSON
header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['sku']) || !isset($input['name']) || !isset($input['quantity'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameters']);
    exit();
}

try {
    // Database connection
    $db = (new Database())->getConnection();
    $inventory = new Inventory($db);
    
    $sku = $input['sku'];
    $name = $input['name'];
    $quantity = intval($input['quantity']);
    
    // Check admin inventory by SKU first, then by name
    $admin_quantity = $inventory->getAdminQuantityBySku($sku);
    
    if ($admin_quantity === null) {
        // If no match by SKU, try by name
        $admin_quantity = $inventory->getAdminQuantityByName($name);
    }
    
    // Return the admin quantity (null if no admin inventory exists)
    echo json_encode([
        'admin_quantity' => $admin_quantity,
        'supplier_quantity' => $quantity,
        'conflict' => ($admin_quantity !== null && $quantity === $admin_quantity)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>