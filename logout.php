<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Unset all session variables
$_SESSION = [];

// Delete the session cookie if present
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy session on server
session_destroy();

// Redirect to login page with notice
header('Location: login.php?logged_out=1');
exit;
