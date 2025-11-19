<?php
include_once 'config/session.php';

// Prefer admin dashboard if both are present
if (!empty($_SESSION['admin']['user_id'])) {
    header("Location: admin/dashboard.php");
    exit();
}
if (!empty($_SESSION['staff']['user_id'])) {
    header("Location: staff/pos.php");
    exit();
}

header("Location: login.php");
exit();
