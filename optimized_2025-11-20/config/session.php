<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_path', '/');
    ini_set('session.use_strict_mode', '1');
    session_start();
    if (!isset($_SESSION['_session_id'])) {
        $_SESSION['_session_id'] = session_id();
        $_SESSION['_session_created'] = time();
    }
}

if (!isset($_SESSION['_logins'])) {
    $_SESSION['_logins'] = [];
}

function generateLoginToken(): string { return bin2hex(random_bytes(16)) . '_' . time(); }

function setRoleSession(string $role, array $data): string {
    $token = generateLoginToken();
    $_SESSION['_logins'][$token] = ['role' => $role, 'data' => $data, 'created_at' => time(), 'last_activity' => time()];
    if ($role === 'admin') { $_SESSION['admin'] = $data; }
    elseif ($role === 'staff') { $_SESSION['staff'] = $data; }
    elseif ($role === 'supplier') { $_SESSION['supplier'] = $data; }
    return $token;
}

function getRoleSession(string $role, ?string $token = null): ?array {
    if ($token !== null && isset($_SESSION['_logins'][$token])) {
        $login = $_SESSION['_logins'][$token];
        if ($login['role'] === $role) { $login['last_activity'] = time(); return $login['data']; }
    }
    foreach ($_SESSION['_logins'] as $login) {
        if ($login['role'] === $role) { $login['last_activity'] = time(); return $login['data']; }
    }
    $sessionData = $_SESSION[$role] ?? null;
    if (is_array($sessionData) && isset($sessionData['user_id'])) { return $sessionData; }
    return null;
}

function clearRoleSession(string $role, ?int $userId = null, ?string $token = null): void {
    if ($token !== null && isset($_SESSION['_logins'][$token])) {
        $login = $_SESSION['_logins'][$token];
        if ($login['role'] === $role) {
            if ($userId === null || (isset($login['data']['user_id']) && $login['data']['user_id'] == $userId)) {
                unset($_SESSION['_logins'][$token]);
                $hasOtherLogin = false;
                foreach ($_SESSION['_logins'] as $otherLogin) { if ($otherLogin['role'] === $role) { $hasOtherLogin = true; break; } }
                if (!$hasOtherLogin && isset($_SESSION[$role])) { unset($_SESSION[$role]); }
                return;
            }
        }
    }
    foreach ($_SESSION['_logins'] as $loginToken => $login) {
        if ($login['role'] === $role) {
            if ($userId === null || (isset($login['data']['user_id']) && $login['data']['user_id'] == $userId)) {
                unset($_SESSION['_logins'][$loginToken]);
            }
        }
    }
    if (isset($_SESSION[$role])) {
        if ($userId === null || (isset($_SESSION[$role]['user_id']) && $_SESSION[$role]['user_id'] == $userId)) { unset($_SESSION[$role]); }
    }
    if ($role === 'supplier') { unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['name'], $_SESSION['role']); }
}

function requireManagementAuth(): void {
    $isAdmin = !empty($_SESSION['admin']['user_id']);
    $role = $_SESSION['admin']['role'] ?? null;
    if (!$isAdmin) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Unauthorized']); exit(); }
    if ($role !== 'management') { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'Forbidden']); exit(); }
}