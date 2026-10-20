<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Generate or retrieve existing CSRF token from active session
 *
 * @return string
 */
function generate_csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

/**
 * Alias to fetch the current CSRF token
 *
 * @return string
 */
function csrf_token(): string {
    return generate_csrf_token();
}

/**
 * Output HTML hidden input containing the CSRF token
 *
 * @return string
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Verify submitted CSRF token from POST parameter, JSON body, or X-CSRF-Token header
 *
 * @param string|null $token
 * @return bool
 */
function verify_csrf_token(?string $token = null): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (empty($sessionToken)) {
        return false;
    }

    if ($token !== null && $token !== '') {
        return hash_equals($sessionToken, $token);
    }

    // 1. Check $_POST['csrf_token']
    if (!empty($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
        return hash_equals($sessionToken, $_POST['csrf_token']);
    }

    // 2. Check X-CSRF-Token header (case-insensitive in PHP $_SERVER)
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!empty($headerToken) && is_string($headerToken)) {
        return hash_equals($sessionToken, $headerToken);
    }

    // 3. Check JSON request payload if applicable
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $data = json_decode($rawInput, true);
        if (is_array($data) && !empty($data['csrf_token']) && is_string($data['csrf_token'])) {
            return hash_equals($sessionToken, $data['csrf_token']);
        }
    }

    return false;
}

/**
 * Enforce CSRF token verification for modifying HTTP methods (POST, PUT, DELETE, PATCH)
 *
 * @return void
 */
function require_csrf(): void {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
        if (!verify_csrf_token()) {
            if (is_api_request()) {
                json_response(false, 'Invalid or expired CSRF token. Please refresh and try again.', null, 403);
            }

            http_response_code(403);
            die('<!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>403 CSRF Verification Failed</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #1e293b; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
                    .card { background: #fff; padding: 32px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); max-width: 420px; width: 100%; text-align: center; border: 1px solid #e2e8f0; }
                    h1 { color: #e11d48; margin-top: 0; font-size: 1.4rem; }
                    p { color: #64748b; margin-bottom: 24px; line-height: 1.5; font-size: 0.95rem; }
                    a { display: inline-block; padding: 10px 20px; background: #2563eb; color: #fff; text-decoration: none; border-radius: 6px; font-weight: 500; }
                </style>
            </head>
            <body>
                <div class="card">
                    <h1>Security Check Failed</h1>
                    <p>Your session or CSRF security token has expired or is invalid. Please refresh the page and try again.</p>
                    <a href="javascript:history.back()">Go Back</a>
                </div>
            </body>
            </html>');
        }
    }
}
