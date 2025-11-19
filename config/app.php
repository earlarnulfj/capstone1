<?php
// Compute a dynamic base path so links work both at root and in subfolders.
// Examples:
// - Built-in server (docroot = c:\xampp\htdocs\haha): /forgot-password.php -> APP_BASE = ''
// - Apache (docroot = c:\xampp\htdocs): /haha/forgot-password.php -> APP_BASE = '/haha'
// - Nested pages (e.g., /admin/login.php) -> APP_BASE = '/admin'

if (!defined('APP_BASE')) {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = str_replace('\\', '/', dirname($script));
    if ($dir === '/' || $dir === '\\' || $dir === '.') {
        define('APP_BASE', '');
    } else {
        define('APP_BASE', rtrim($dir, '/'));
    }
}

// Global toggle to control whether sample supplier products are auto-seeded.
// Set to true only in development/demo environments.
if (!defined('APP_ENABLE_SAMPLE_SEEDING')) {
    define('APP_ENABLE_SAMPLE_SEEDING', false);
}