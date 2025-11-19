<?php
session_start();
include_once '../config/database.php';
include_once '../models/inventory.php';
include_once '../models/inventory_variation.php';
include_once '../models/order.php';
include_once '../models/supplier.php';

// Access control
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'management') {
    header("Location: ../login.php");
    exit();
}

$database = new Database();
$db       = $database->getConnection();

$inventory = new Inventory($db);
$invVar    = new InventoryVariation($db);
$order     = new Order($db);
$supplier  = new Supplier($db);

$message     = '';
$messageType = '';

// Validate supplier_id parameter
if (!isset($_GET['supplier_id'])) {
    header("Location: suppliers.php");
    exit();
}
$supplier_id = intval($_GET['supplier_id']);

// Fetch supplier details
$supplier->id = $supplier_id;
if (!method_exists($supplier, 'readOne') || !$supplier->readOne()) {
    header("Location: suppliers.php");
    exit();
}

// Handle order submission
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['quantities']) && is_array($_POST['quantities'])
) {
    foreach ($_POST['quantities'] as $product_id => $qty) {
        $qty = intval($qty);
        if ($qty > 0) {
            // Resolve unit type and variation selection
            $unitType = isset($_POST['unit_types'][$product_id]) ? trim($_POST['unit_types'][$product_id]) : 'per piece';
            $variation = isset($_POST['variations'][$product_id]) ? trim($_POST['variations'][$product_id]) : '';

            // Verify item belongs to supplier and get base unit price
            $stmtItem = $db->prepare("SELECT unit_price FROM inventory WHERE id = :id AND supplier_id = :sid LIMIT 1");
            $stmtItem->execute([':id' => $product_id, ':sid' => $supplier_id]);
            $itemData = $stmtItem->fetch(PDO::FETCH_ASSOC);
            if (!$itemData) {
                $message     = "Item ID {$product_id} does not belong to this supplier.";
                $messageType = 'danger';
                break;
            }

            $order->inventory_id        = $product_id;
            $order->quantity            = $qty;
            $order->supplier_id         = $supplier_id;
            $order->user_id             = $_SESSION['user_id'];
            $order->unit_price          = (float)$itemData['unit_price'];
            $order->unit_type           = $unitType;
            $order->variation           = $variation;
            $order->is_automated        = 0;
            $order->confirmation_status = 'pending';

            if (!$order->create()) {
                $message     = "Failed to create order for product ID {$product_id}.";
                $messageType = 'danger';
                break;
            }
        }
    }
    if (empty($message)) {
        $message     = 'Order created successfully.';
        $messageType = 'success';
    }
}

// Fetch products (inventory items) supplied by this supplier
if (!method_exists($inventory, 'readBySupplier')) {
    die('Please add a readBySupplier($supplier_id) method to models/inventory.php');
}
$stmt = $inventory->readBySupplier($supplier_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Supplier Details – Inventory & Stock Control</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css"
          rel="stylesheet" />
</head>
<body>
    <?php include_once '../includes/header.php'; ?>
    <div class="container mt-4">
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <h2>Products from <?= htmlspecialchars($supplier->name) ?></h2>
        <form method="POST">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Price</th>
                        <th>Unit Type</th>
                        <th>Variation</th>
                        <th>Quantity to Order</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                        <?php
                            $itemId = (int)$row['id'];
                            $variations = $invVar->getByInventory($itemId);
                            $hasVariations = !empty($variations);
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($row['name']) ?></td>
                            <td><?= number_format((float)($row['unit_price'] ?? 0), 2) ?></td>
                            <td>
                                <select name="unit_types[<?= $itemId ?>]" class="form-select">
                                    <option value="per piece">per piece</option>
                                    <option value="per box">per box</option>
                                    <option value="per kilo">per kilo</option>
                                    <option value="per meter">per meter</option>
                                </select>
                            </td>
                            <td>
                                <?php if ($hasVariations): ?>
                                    <select name="variations[<?= $itemId ?>]" class="form-select">
                                        <option value="">Select variation</option>
                                        <?php foreach ($variations as $v): ?>
                                            <option value="<?= htmlspecialchars($v['variation']) ?>">
                                                <?= htmlspecialchars($v['variation']) ?> (<?= htmlspecialchars($v['unit_type']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="text" class="form-control" placeholder="N/A" disabled />
                                <?php endif; ?>
                            </td>
                            <td>
                                <input type="number"
                                       name="quantities[<?= $itemId ?>]"
                                       value="0" min="0"
                                       class="form-control" />
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>

            <button type="submit" class="btn btn-primary">Place Order</button>
            <a href="suppliers.php" class="btn btn-secondary ms-2">Back to Suppliers</a>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js">
    </script>
</body>
</html>
