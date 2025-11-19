<?php
// This API endpoint allows suppliers to update their delivery location

include_once '../config/database.php';
include_once '../models/delivery.php';

header('Content-Type: application/json');

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($data['delivery_id']) || !isset($data['latitude']) || !isset($data['longitude'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

$delivery_id = intval($data['delivery_id']);
$latitude = floatval($data['latitude']);
$longitude = floatval($data['longitude']);

// Validate coordinates
if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid coordinates']);
    exit;
}

// Update delivery location
$database = new Database();
$db = $database->getConnection();

$delivery = new Delivery($db);

if ($delivery->updateLocation($delivery_id, $latitude, $longitude)) {
    echo json_encode([
        'success' => true,
        'delivery_id' => $delivery_id,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to update delivery location'
    ]);
}
?>
