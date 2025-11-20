<?php
// ====== Access control & dependencies ======
include_once '../config/session.php';
require_once '../config/database.php';

requireManagementAuth();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// CSRF check
$t = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $t)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit();
}

if (!isset($_POST['product_id']) || !isset($_FILES['product_image'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

$product_id = intval($_POST['product_id']);

try {
    $db = (new Database())->getConnection();
    
    // Verify that the product exists
    $stmt = $db->prepare("SELECT id, image_path, image_url, name FROM inventory WHERE id = ?");
    $stmt->execute([$product_id]);
    $old_product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$old_product) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit();
    }

    $file = $_FILES['product_image'];
    
    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'File upload error']);
        exit();
    }

    // Check file size (5MB max)
    if ($file['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'File size must be less than 5MB']);
        exit();
    }

    // Check file type
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime_type, $allowed_types)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, and GIF are allowed']);
        exit();
    }

    // Create uploads directory if it doesn't exist
    $upload_dir = '../uploads/products/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'product_' . $product_id . '_' . time() . '.' . $extension;
    $filepath = $upload_dir . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save file']);
        exit();
    }

    // Update database with image path and image_url
    $image_path = 'uploads/products/' . $filename;
    $image_url = $image_path; // Same as image_path for consistency
    
    $stmt = $db->prepare("UPDATE inventory SET image_path = ?, image_url = ? WHERE id = ?");
    $stmt->execute([$image_path, $image_url, $product_id]);

    // Delete old image if it exists and differs
    if (!empty($old_product['image_path']) && $old_product['image_path'] !== $image_path) {
        $old_file = '../' . $old_product['image_path'];
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
    error_log("Admin POS Image upload error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>