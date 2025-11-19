<?php
session_start();
require_once '../vendor/autoload.php';
require_once '../config/session.php';
require_once '../config/database.php';

use Twilio\Rest\Client;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (empty($_SESSION['admin']['user_id']) || (($_SESSION['admin']['role'] ?? '') !== 'management')) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$inputToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $inputToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$phone = trim($_POST['phone_number'] ?? '');
$body  = trim($_POST['message'] ?? '');

if ($phone === '' || !preg_match('/^\+?[0-9]{10,15}$/', $phone)) {
    echo json_encode(['success' => false, 'message' => 'Invalid phone number']);
    exit;
}
if ($body === '' || strlen($body) > 500) {
    echo json_encode(['success' => false, 'message' => 'Invalid message body']);
    exit;
}

$sid   = getenv('TWILIO_SID') ?: '';
$token = getenv('TWILIO_TOKEN') ?: '';
$from  = getenv('TWILIO_FROM') ?: '';
if ($sid === '' || $token === '' || $from === '') {
    error_log('Twilio credentials missing');
    echo json_encode(['success' => false, 'message' => 'SMS service not configured']);
    exit;
}

try {
    $twilio = new Client($sid, $token);
    $message = $twilio->messages->create($phone, ['from' => $from, 'body' => $body]);
    echo json_encode(['success' => true, 'sid' => $message->sid]);
} catch (\Throwable $e) {
    error_log('Twilio send error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to send SMS']);
}
?>