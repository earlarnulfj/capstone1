<?php
// config/session.php
// Configure session settings for proper multi-user support
// Each browser/device gets its own isolated session
if (session_status() === PHP_SESSION_NONE) {
    // CRITICAL: Configure session cookies for proper isolation
    // Each browser MUST have its own unique session ID cookie
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');
    
    // Set session cookie path to root to ensure proper isolation
    // Each browser gets a unique PHPSESSID cookie value
    ini_set('session.cookie_path', '/');
    
    // CRITICAL: Do not regenerate session ID - keep existing session ID if cookie exists
    // This ensures each browser maintains its own persistent session
    // If we regenerate IDs, it could cause sessions to be lost
    ini_set('session.use_strict_mode', '1'); // Prevents session fixation but allows reuse
    
    // Start the session - each browser will get its own unique session ID
    // If a PHPSESSID cookie exists, use that session ID (maintains browser's session)
    // If no cookie exists, PHP creates a new unique session ID
    // Session data is stored server-side keyed by session ID
    // Browser 1: Session ID "abc123" -> Session file "sess_abc123"
    // Browser 2: Session ID "xyz789" -> Session file "sess_xyz789"
    // These are completely separate and independent
    session_start();
    
    // Store session ID in session for debugging/verification (optional)
    // This helps verify each browser has its own unique session
    if (!isset($_SESSION['_session_id'])) {
        $_SESSION['_session_id'] = session_id();
        $_SESSION['_session_created'] = time();
    }
}

// Initialize multi-instance session storage for same-browser multiple logins
// This allows multiple tabs/windows in the same browser to have separate login sessions
if (!isset($_SESSION['_logins'])) {
    $_SESSION['_logins'] = []; // Array of active login instances: ['token' => ['role' => 'staff', 'data' => [...], ...], ...]
}

/**
 * Generate a unique token for a new login instance (per tab/window)
 */
function generateLoginToken(): string {
    return bin2hex(random_bytes(16)) . '_' . time();
}

/**
 * Set a user session under a given role namespace with a unique token.
 * This allows multiple tabs in the same browser to login separately.
 * @param string $role 'admin', 'staff', or 'supplier'
 * @param array $data  ['user_id'=>..., 'username'=>..., 'email'=>..., 'role'=>...]
 * @return string The login token for this instance
 */
function setRoleSession(string $role, array $data): string {
    // Generate unique token for this login instance
    $token = generateLoginToken();
    
    // Store login instance in session
    $_SESSION['_logins'][$token] = [
        'role' => $role,
        'data' => $data,
        'created_at' => time(),
        'last_activity' => time()
    ];
    
    // Also maintain backward compatibility - set the role directly
    // This will be the "active" login if no token is specified
    if ($role === 'admin') {
        $_SESSION['admin'] = $data;
    } elseif ($role === 'staff') {
        $_SESSION['staff'] = $data;
    } elseif ($role === 'supplier') {
        $_SESSION['supplier'] = $data;
    }
    
    return $token;
}

/**
 * Get the current session user for a role, optionally by token.
 * @param string $role 'admin', 'staff', or 'supplier'
 * @param string|null $token Optional: token to get specific login instance
 * @return array|null User data or null if not found
 */
function getRoleSession(string $role, ?string $token = null): ?array {
    // If token is provided, get that specific login instance
    if ($token !== null && isset($_SESSION['_logins'][$token])) {
        $login = $_SESSION['_logins'][$token];
        if ($login['role'] === $role) {
            $login['last_activity'] = time(); // Update activity
            return $login['data'];
        }
    }
    
    // Fallback: check if there's an active login for this role (backward compatibility)
    // Also check all login instances for this role
    if (isset($_SESSION['_logins'])) {
        foreach ($_SESSION['_logins'] as $loginToken => $login) {
            if ($login['role'] === $role) {
                // Return the first matching login (or could return most recent)
                $login['last_activity'] = time();
                return $login['data'];
            }
        }
    }
    
    // Legacy check for direct role session
    $sessionData = $_SESSION[$role] ?? null;
    if (is_array($sessionData) && isset($sessionData['user_id'])) {
        return $sessionData;
    }
    
    return null;
}

/**
 * Clear a role session, optionally by token for multi-instance support.
 * @param string $role 'admin', 'staff', or 'supplier'
 * @param int|null $userId Optional: only clear if this specific user is logged in
 * @param string|null $token Optional: clear specific login instance by token
 */
function clearRoleSession(string $role, ?int $userId = null, ?string $token = null): void {
    // If token is provided, clear that specific login instance
    if ($token !== null && isset($_SESSION['_logins'][$token])) {
        $login = $_SESSION['_logins'][$token];
        // Verify role matches
        if ($login['role'] === $role) {
            // If userId is specified, verify it matches
            if ($userId === null || (isset($login['data']['user_id']) && $login['data']['user_id'] == $userId)) {
                unset($_SESSION['_logins'][$token]);
                // If this was the last login for this role, clear the direct role session too
                $hasOtherLogin = false;
                foreach ($_SESSION['_logins'] ?? [] as $otherLogin) {
                    if ($otherLogin['role'] === $role) {
                        $hasOtherLogin = true;
                        break;
                    }
                }
                if (!$hasOtherLogin && isset($_SESSION[$role])) {
                    unset($_SESSION[$role]);
                }
                return;
            }
        }
    }
    
    // If no token, clear all logins for this role (or check userId if specified)
    if (isset($_SESSION['_logins'])) {
        foreach ($_SESSION['_logins'] as $loginToken => $login) {
            if ($login['role'] === $role) {
                // If userId is specified, only clear matching logins
                if ($userId === null || (isset($login['data']['user_id']) && $login['data']['user_id'] == $userId)) {
                    unset($_SESSION['_logins'][$loginToken]);
                }
            }
        }
    }
    
    // Legacy: clear direct role session if userId matches or not specified
    if (isset($_SESSION[$role])) {
        if ($userId === null || (isset($_SESSION[$role]['user_id']) && $_SESSION[$role]['user_id'] == $userId)) {
            unset($_SESSION[$role]);
        }
    }
    
    // Also clear legacy supplier session variables if supplier
    if ($role === 'supplier') {
        unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['name'], $_SESSION['role']);
    }
}

function requireManagementAuth(): void {
    $isAdmin = !empty($_SESSION['admin']['user_id']);
    $role = $_SESSION['admin']['role'] ?? null;
    if (!$isAdmin) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit();
    }
    if ($role !== 'management') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit();
    }
}

function requireManagementPage(): void {
    if (empty($_SESSION['admin']['user_id']) || (($_SESSION['admin']['role'] ?? null) !== 'management')) {
        header('Location: ../login.php');
        exit();
    }
}

function requireStaffPage(): void {
    if (empty($_SESSION['staff']['user_id'])) {
        header('Location: ../login.php');
        exit();
    }
}

function requireSupplierPage(): void {
    $ok = false;
    if (!empty($_SESSION['supplier']['user_id'])) { $ok = true; }
    elseif (!empty($_SESSION['user_id']) && (($_SESSION['role'] ?? '') === 'supplier')) { $ok = true; }
    if (!$ok) { header('Location: ../login.php'); exit(); }
}

function ensureCsrf(): void {
    $t = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $t)) {
        http_response_code(403);
        exit();
    }
}
