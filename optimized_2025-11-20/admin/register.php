<?php
include_once '../config/app.php';
$base = defined('APP_BASE') ? APP_BASE : '';
if ($base === '/' || $base === '\\') $base = '';
$target = ($base !== '' ? $base : '') . '/register.php?role=management';
header('Location: ' . $target);
exit;