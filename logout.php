<?php
include_once 'config/session.php';

$role = $_GET['role'] ?? null;
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$token = $_GET['token'] ?? $_COOKIE['login_token'] ?? null; // Get token from URL or cookie

// Determine which role to logout based on parameter or current session
// If token is provided, only logout that specific login instance (for same-browser multi-login support)
if ($role === 'admin') {
    // Get current user ID from session before clearing
    $currentUserId = $_SESSION['admin']['user_id'] ?? null;
    clearRoleSession('admin', $userId ?? $currentUserId, $token);
} elseif ($role === 'staff') {
    $currentUserId = $_SESSION['staff']['user_id'] ?? null;
    clearRoleSession('staff', $userId ?? $currentUserId, $token);
} elseif ($role === 'supplier') {
    $currentUserId = $_SESSION['supplier']['user_id'] ?? $_SESSION['user_id'] ?? null;
    clearRoleSession('supplier', $userId ?? $currentUserId, $token);
} elseif ($role === 'all') {
    // Only clear all roles if explicitly requested with 'all' parameter
    // This still only affects the current browser session
    clearRoleSession('admin');
    clearRoleSession('staff');
    clearRoleSession('supplier');
} else {
    // If no specific role is provided, try to determine from current session
    // and only logout the currently active user in this browser session
    if (isset($_SESSION['admin']) && !empty($_SESSION['admin']) && isset($_SESSION['admin']['user_id'])) {
        clearRoleSession('admin', $_SESSION['admin']['user_id']);
    } elseif (isset($_SESSION['staff']) && !empty($_SESSION['staff']) && isset($_SESSION['staff']['user_id'])) {
        clearRoleSession('staff', $_SESSION['staff']['user_id']);
    } elseif (isset($_SESSION['supplier']) && !empty($_SESSION['supplier']) && isset($_SESSION['supplier']['user_id'])) {
        clearRoleSession('supplier', $_SESSION['supplier']['user_id']);
    } elseif (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'supplier') {
        // Legacy supplier session
        clearRoleSession('supplier', $_SESSION['user_id']);
    } else {
        // If no active sessions found, redirect to login anyway
        header("Location: login.php");
        exit();
    }
}

// CRITICAL: Do NOT call session_destroy() or affect sessions globally
// Each browser has its own unique session ID cookie (PHPSESSID)
// PHP stores session data in separate files keyed by session ID:
// - Browser 1: Session ID "abc123" -> File "sess_abc123" -> $_SESSION['staff'] = [user data]
// - Browser 2: Session ID "xyz789" -> File "sess_xyz789" -> $_SESSION['staff'] = [user data]
//
// When we clear $_SESSION['staff'], PHP only modifies THIS browser's session file
// Other browsers' session files remain completely untouched
//
// Example:
// - Browser 1 logs out: unset($_SESSION['staff']) clears data in file "sess_abc123"
// - Browser 2 refreshes: Reads file "sess_xyz789" which still has user data intact
// - Result: Browser 2 remains logged in

// Get the session ID for this browser before closing
$currentSessionId = session_id();

if (session_status() === PHP_SESSION_ACTIVE) {
    // Write and close - this saves the cleared session data to THIS session's file only
    // The session_write_close() writes $_SESSION changes to the file for THIS session ID
    // Other session IDs have their own files which are completely unaffected
    session_write_close();
}

// Redirect to login
header("Location: login.php");
exit();
