<?php
// config/session.php - Session management functions

// Set session configuration for security BEFORE starting session
if (session_status() === PHP_SESSION_NONE) {
    // Configure session settings before starting
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Strict');
    
    // Start the session
    session_start();
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Login user and create session
 */
function loginUser($user) {
    if (!$user || !isset($user['id'])) {
        return false;
    }
    
    // Regenerate session ID for security
    session_regenerate_id(true);
    
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'] ?? 'user';
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    
    return true;
}

/**
 * Logout user and destroy session
 */
function logoutUser() {
    // Clear session variables
    $_SESSION = array();
    
    // Delete session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destroy session
    session_destroy();
    
    // Start new session
    session_start();
    session_regenerate_id(true);
    
    return true;
}

/**
 * Get current user information
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    return [
        'id' => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'],
        'email' => $_SESSION['user_email'],
        'role' => $_SESSION['user_role'] ?? 'user'
    ];
}

/**
 * Check session timeout (optional - 24 hours)
 */
function checkSessionTimeout($timeout = 86400) { // 24 hours default
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
        logoutUser();
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * Require login - redirect to login page if not logged in
 */
function requireLogin($redirect_url = 'login.php') {
    if (!isLoggedIn()) {
        // Save the current page to redirect back after login
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header("Location: " . $redirect_url);
        exit();
    }
    
    // Check session timeout
    if (!checkSessionTimeout()) {
        setMessage('Your session has expired. Please login again.', 'info');
        header("Location: " . $redirect_url);
        exit();
    }
}

/**
 * Set flash message
 */
function setMessage($text, $type = 'info') {
    $_SESSION['flash_message'] = [
        'text' => $text,
        'type' => $type
    ];
}

/**
 * Get and clear flash message
 */
function getMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}

/**
 * Check if user has specific role
 */
function hasRole($required_role) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $user_role = $_SESSION['user_role'] ?? 'user';
    
    // Admin has access to everything
    if ($user_role === 'admin') {
        return true;
    }
    
    return $user_role === $required_role;
}

/**
 * Require specific role
 */
function requireRole($required_role, $redirect_url = 'dashboard.php') {
    requireLogin();
    
    if (!hasRole($required_role)) {
        setMessage('You do not have permission to access this page.', 'error');
        header("Location: " . $redirect_url);
        exit();
    }
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Basic settings functions
 */
function getBasicSetting($key, $default = null) {
    // Simple settings - can be expanded to use database
    $settings = [
        'registration_enabled' => true,
        'site_name' => 'EduHive',
        'maintenance_mode' => false
    ];
    
    return $settings[$key] ?? $default;
}

/**
 * Update last activity timestamp
 */
function updateLastActivity() {
    if (isLoggedIn()) {
        $_SESSION['last_activity'] = time();
    }
}

// Auto-update last activity on every page load
updateLastActivity();
?>