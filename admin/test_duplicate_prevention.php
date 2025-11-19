    <?php
// Test script for duplicate notification prevention
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

include_once '../config/database.php';
include_once '../models/notification.php';

$database = new Database();
$db = $database->getConnection();
$notification = new Notification($db);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Duplicate Prevention</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <div class="card">
                    <div class="card-header">
                        <h3>Duplicate Notification Prevention Test</h3>
                    </div>
                    <div class="card-body">
                        <form id="testForm">
                            <div class="mb-3">
                                <label for="order_id" class="form-label">Order ID</label>
                                <input type="number" class="form-control" id="order_id" name="order_id" value="999" required>
                            </div>
                            <div class="mb-3">
                                <label for="supplier_id" class="form-label">Supplier ID</label>
                                <input type="number" class="form-control" id="supplier_id" name="supplier_id" value="1" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Test Duplicate Prevention</button>
                        </form>
                        
                        <div id="results" class="mt-4" style="display: none;">
                            <h5>Test Results:</h5>
                            <div id="resultContent"></div>
                        </div>
                        
                        <div class="mt-4">
                            <h5>How it works:</h5>
                            <ul>
                                <li>This test will attempt to create 3 identical notifications</li>
                                <li>Only the first one should be created successfully</li>
                                <li>The subsequent attempts should be prevented due to duplicate detection</li>
                                <li>Duplicate prevention uses a 5-minute time window</li>
                            </ul>
                        </div>
                        
                        <div class="mt-3">
                            <a href="notifications.php" class="btn btn-secondary">Back to Notifications</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('testForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData();
            formData.append('action', 'test_duplicate_prevention');
            formData.append('order_id', document.getElementById('order_id').value);
            formData.append('supplier_id', document.getElementById('supplier_id').value);
            
            fetch('notifications.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                const resultsDiv = document.getElementById('results');
                const contentDiv = document.getElementById('resultContent');
                
                if (data.success) {
                    contentDiv.innerHTML = `
                        <div class="alert alert-success">
                            <strong>Test completed successfully!</strong><br>
                            ${data.message}<br>
                            <small class="text-muted">${data.note}</small>
                        </div>
                        <div class="mt-3">
                            <h6>Results for each attempt:</h6>
                            <ul>
                                ${data.results.map((result, index) => 
                                    `<li>Attempt ${index + 1}: ${result ? 'Success' : 'Prevented (duplicate)'}</li>`
                                ).join('')}
                            </ul>
                        </div>
                    `;
                } else {
                    contentDiv.innerHTML = `
                        <div class="alert alert-danger">
                            <strong>Test failed:</strong> ${data.message}
                        </div>
                    `;
                }
                
                resultsDiv.style.display = 'block';
            })
            .catch(error => {
                console.error('Error:', error);
                const resultsDiv = document.getElementById('results');
                const contentDiv = document.getElementById('resultContent');
                contentDiv.innerHTML = `
                    <div class="alert alert-danger">
                        <strong>Error:</strong> Failed to run test
                    </div>
                `;
                resultsDiv.style.display = 'block';
            });
        });
    </script>
</body>
</html>