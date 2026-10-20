<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

// Configure and start session with secure cookie parameters
if (session_status() === PHP_SESSION_NONE) {
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');

    session_name('pos_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

/**
 * Check if the incoming HTTP request is targeted at an API or requests JSON
 *
 * @return bool
 */
function is_api_request(): bool {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, '/api/') !== false) {
        return true;
    }

    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (strpos($accept, 'application/json') !== false) {
        return true;
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        return true;
    }

    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        return true;
    }

    return false;
}

/**
 * Check whether a user is currently logged in
 *
 * @return bool
 */
function is_logged_in(): bool {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['user_role']);
}

/**
 * Return current logged in user details, or null if unauthenticated
 *
 * @return array{id: int, name: string, username: string, role: string}|null
 */
function current_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }

    return [
        'id'       => (int)$_SESSION['user_id'],
        'name'     => (string)($_SESSION['user_name'] ?? ''),
        'username' => (string)($_SESSION['username'] ?? ''),
        'role'     => (string)$_SESSION['user_role']
    ];
}

/**
 * Helper to respond with 401/403 JSON or perform page redirect
 *
 * @param int $statusCode
 * @param string $message
 * @param string $redirectUrl
 * @return never
 */
function auth_deny(int $statusCode, string $message, string $redirectUrl = 'login.php'): void {
    if (is_api_request()) {
        json_response(false, $message, null, $statusCode);
    }

    http_response_code($statusCode);
    header("Location: {$redirectUrl}");
    exit;
}

/**
 * Require active user session; responds with 401 JSON for APIs or redirects to login.php
 *
 * @return void
 */
function require_login(): void {
    if (!is_logged_in()) {
        if (!empty($_SERVER['REQUEST_URI']) && !is_api_request()) {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        }
        auth_deny(401, 'Unauthorized. Please log in to continue.', 'login.php');
    }
}

/**
 * Require specific user role(s); responds with 403 JSON for APIs or redirects with notice
 *
 * @param string|array<string> $roles
 * @return void
 */
function require_role($roles): void {
    require_login();

    $user = current_user();
    $allowed = is_array($roles) ? $roles : [$roles];

    if (!$user || !in_array($user['role'], $allowed, true)) {
        auth_deny(403, 'Forbidden. You do not have permission to access this resource.', 'billing.php?error=access_denied');
    }
}

/**
 * Check if the currently logged in user has a specific role
 *
 * @param string $role
 * @return bool
 */
function has_role(string $role): bool {
    $user = current_user();
    return $user !== null && $user['role'] === $role;
}
