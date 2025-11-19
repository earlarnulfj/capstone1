<?php
session_start();
require_once '../config/database.php';

// Initialize DB connection (fixes undefined $pdo)
$database = new Database();
$pdo = $database->getConnection();

// Check if user is logged in and is a supplier
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supplier') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

if (!isset($_POST['product_id']) || !isset($_FILES['product_image'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

$product_id = intval($_POST['product_id']);
$supplier_id = $_SESSION['user_id'];

try {
    // Verify that the product belongs to the current supplier
    $stmt = $pdo->prepare("SELECT id, image_path, image_url FROM inventory WHERE id = ? AND supplier_id = ?");
    $stmt->execute([$product_id, $supplier_id]);
    $old_product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$old_product) {
        echo json_encode(['success' => false, 'message' => 'Product not found or access denied']);
        exit();
    }

    $file = $_FILES['product_image'];
    
    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'File upload error']);
        exit();
    }

    // Check file size (2MB max)
    if ($file['size'] > 2 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'File size must be less than 2MB']);
        exit();
    }

    // Check file type via MIME
    $allowed_types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!array_key_exists($mime_type, $allowed_types)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, and GIF are allowed']);
        exit();
    }
    $extension = $allowed_types[$mime_type];

    // Create uploads directory if it doesn't exist
    $upload_dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR;
    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0755, true);
    }

    // Generate unique filename
    $filename = 'product_' . $product_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $filepath = $upload_dir . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save file']);
        exit();
    }

    // Normalize DB path (web-accessible relative path)
    $image_path = 'uploads/products/' . $filename;
    $image_url = $image_path;

    // Update database with image path and image_url to keep views in sync
    $stmt = $pdo->prepare("UPDATE inventory SET image_path = ?, image_url = ? WHERE id = ? AND supplier_id = ?");
    $stmt->execute([$image_path, $image_url, $product_id, $supplier_id]);

    // Delete old image if it exists and differs
    if (!empty($old_product['image_path']) && $old_product['image_path'] !== $image_path) {
        $old_file = dirname(__DIR__) . DIRECTORY_SEPARATOR . $old_product['image_path'];
        if (file_exists($old_file)) {
            @unlink($old_file);
        }
    }

    echo json_encode([
        'success' => true, 
        'message' => 'Image uploaded successfully',
        'image_path' => $image_path,
        'image_url' => $image_url
    ]);

} catch (Exception $e) {
    error_log("Image upload error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>