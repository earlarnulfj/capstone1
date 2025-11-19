<?php
// Mock SMS API integration with Unified Credits handling
// Credits are deducted ONLY when an SMS is successfully sent

header('Content-Type: application/json');

// Helper: ensure logs directory exists
function ensureLogsDir() {
    $logsDir = __DIR__ . '/../logs';
    if (!file_exists($logsDir)) {
        mkdir($logsDir, 0755, true);
    }
}

// Helper: read current credits from JSON file (initialize if missing)
function readCredits() {
    ensureLogsDir();
    $file = __DIR__ . '/../logs/unified_credits.json';
    if (!file_exists($file)) {
        // Initialize with a default amount; adjust as needed
        file_put_contents($file, json_encode(['credits' => 100], JSON_PRETTY_PRINT));
        return 100;
    }
    $data = json_decode(@file_get_contents($file), true);
    if (!is_array($data) || !isset($data['credits'])) {
        // Reset to default if file corrupted
        file_put_contents($file, json_encode(['credits' => 100], JSON_PRETTY_PRINT));
        return 100;
    }
    return (int)$data['credits'];
}

// Helper: write remaining credits back to file
function writeCredits($credits) {
    ensureLogsDir();
    $file = __DIR__ . '/../logs/unified_credits.json';
    file_put_contents($file, json_encode(['credits' => max(0, (int)$credits)], JSON_PRETTY_PRINT));
}

// Reject non-POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Parse JSON body
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

// Validate required fields
if (!isset($data['phone']) || !isset($data['message'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

$phone = trim($data['phone']);
$message = trim($data['message']);

// Validate phone number format (PH mobile: 09XXXXXXXXX)
if (!preg_match('/^09\d{9}$/', $phone)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid phone number format']);
    exit;
}

// Read current credits (for info; we still allow sending in this mock)
$currentCredits = readCredits();

// Mock sending SMS (in a real app, call SMS gateway)
// Simulate success/failure (98% success rate)
$success = (rand(1, 100) <= 98);

ensureLogsDir();

if ($success) {
    // Log the SMS
    $log_file = __DIR__ . '/../logs/sms_log.txt';
    $log_entry = date('Y-m-d H:i:s') . " | To: {$phone} | Message: {$message}\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);

    // Deduct ONE Unified Credit ONLY on successful send
    $remainingCredits = max(0, $currentCredits - 1);
    writeCredits($remainingCredits);

    echo json_encode([
        'success' => true,
        'phone' => $phone,
        'message_length' => strlen($message),
        'timestamp' => date('Y-m-d H:i:s'),
        'credits_remaining' => $remainingCredits
    ]);
} else {
    // Do NOT deduct credits on failure
    echo json_encode([
        'success' => false,
        'error' => 'Failed to send SMS',
        'phone' => $phone,
        'credits_remaining' => $currentCredits
    ]);
}
?>
