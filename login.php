<?php
/**
 * Login Portal - Redirects to appropriate login page
 * Each role has its own dedicated login page
 */
include_once 'config/app.php';

// Redirect to staff login by default (or you can redirect to admin/login.php)
// For better UX, you might want to show a simple page with links
header('Location: ' . APP_BASE . '/staff/login.php');
exit;
?>
