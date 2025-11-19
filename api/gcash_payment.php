<?php
// This is a mock GCash payment API integration
// In a real application, you would integrate with the actual GCash API

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
if (!isset($data['amount']) || !isset($data['reference'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

// Mock processing (in a real app, this would call the GCash API)
$amount = floatval($data['amount']);
$reference = $data['reference'];

// Simulate success/failure (95% success rate)
$success = (rand(1, 100) <= 95);

if ($success) {
    // Generate a mock transaction ID
    $transaction_id = 'GC' . time() . rand(1000, 9999);
    
    echo json_encode([
        'success' => true,
        'transaction_id' => $transaction_id,
        'amount' => $amount,
        'reference' => $reference,
        'status' => 'completed',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Payment processing failed',
        'reference' => $reference
    ]);
}
?>
