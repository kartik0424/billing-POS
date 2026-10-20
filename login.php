<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';

// Redirect already authenticated users to their landing page
if (is_logged_in()) {
    $role = current_user()['role'] ?? 'cashier';
    header('Location: ' . ($role === 'admin' ? 'audit.php' : 'billing.php'));
    exit;
}

// Basic Brute Force Protection (Session-based rate limiter)
$maxAttempts = 5;
$lockoutDuration = 60; // seconds

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['last_attempt_time'] = time();
}

// Check lockout state
$isLockedOut = false;
$remainingLockout = 0;
if (isset($_SESSION['lockout_until']) && $_SESSION['lockout_until'] > time()) {
    $isLockedOut = true;
    $remainingLockout = $_SESSION['lockout_until'] - time();
} elseif (isset($_SESSION['lockout_until']) && $_SESSION['lockout_until'] <= time()) {
    // Reset lockout
    unset($_SESSION['lockout_until']);
    $_SESSION['login_attempts'] = 0;
}

$errorMessage = '';
$usernameVal = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isLockedOut) {
        $errorMessage = "Too many failed login attempts. Please wait {$remainingLockout} seconds.";
    } elseif (!verify_csrf_token()) {
        $errorMessage = 'Invalid or expired session security token. Please try again.';
    } else {
        $username = sanitize_text($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $usernameVal = $username;

        if (empty($username) || empty($password)) {
            $errorMessage = 'Please enter both username and password.';
        } else {
            try {
                $db = get_db();
                $stmt = $db->prepare("SELECT id, name, username, password_hash, role FROM users WHERE username = :username LIMIT 1");
                $stmt->execute([':username' => $username]);
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password_hash'])) {
                    // Successful login: reset brute-force counters
                    $_SESSION['login_attempts'] = 0;
                    unset($_SESSION['lockout_until']);

                    // Regenerate session ID to prevent session fixation
                    session_regenerate_id(true);

                    // Set user session data
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['user_name'] = (string)$user['name'];
                    $_SESSION['username'] = (string)$user['username'];
                    $_SESSION['user_role'] = (string)$user['role'];

                    // Role-based landing page redirect
                    $redirectUrl = ($user['role'] === 'admin') ? 'audit.php' : 'billing.php';

                    // Check if an authorized prior page was requested
                    if (!empty($_SESSION['redirect_after_login'])) {
                        $target = $_SESSION['redirect_after_login'];
                        unset($_SESSION['redirect_after_login']);
                        // Cashiers cannot be redirected to admin-only pages
                        $adminOnly = ['products.php', 'audit.php', 'settings.php'];
                        $isTargetAdmin = false;
                        foreach ($adminOnly as $page) {
                            if (strpos($target, $page) !== false) {
                                $isTargetAdmin = true;
                                break;
                            }
                        }
                        if (!($user['role'] === 'cashier' && $isTargetAdmin)) {
                            $redirectUrl = $target;
                        }
                    }

                    header("Location: {$redirectUrl}");
                    exit;
                } else {
                    // Failed login
                    $_SESSION['login_attempts']++;
                    $_SESSION['last_attempt_time'] = time();

                    if ($_SESSION['login_attempts'] >= $maxAttempts) {
                        $_SESSION['lockout_until'] = time() + $lockoutDuration;
                        $errorMessage = "Too many failed attempts. Account temporarily locked for {$lockoutDuration} seconds.";
                    } else {
                        $attemptsLeft = $maxAttempts - $_SESSION['login_attempts'];
                        $errorMessage = "Invalid username or password. ({$attemptsLeft} attempt" . ($attemptsLeft === 1 ? '' : 's') . " remaining)";
                    }
                }
            } catch (\Throwable $e) {
                $errorMessage = 'An unexpected system error occurred. Please try again.';
            }
        }
    }
}

$businessName = get_setting('business_name', 'QuickPOS Mart');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#2563eb">
    <title>Login &bull; <?= e($businessName) ?></title>
    
    <!-- Bootstrap 5 CSS & Icons CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="manifest" href="manifest.json">
</head>
<body class="bg-light d-flex align-items-center justify-content-center min-vh-100 p-3">

<div class="login-wrapper w-100" style="max-width: 400px;">
    <div class="text-center mb-4">
        <div class="brand-badge bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-2 shadow" style="width: 64px; height: 64px;">
            <i class="bi bi-cart-check-fill fs-2"></i>
        </div>
        <h4 class="fw-bold mb-1 text-dark"><?= e($businessName) ?></h4>
        <p class="text-muted small mb-0">Point of Sale System</p>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <h5 class="fw-semibold text-center mb-3">Sign In</h5>

            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger py-2 px-3 small d-flex align-items-center mb-3" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2 flex-shrink-0"></i>
                    <div><?= e($errorMessage) ?></div>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['logged_out'])): ?>
                <div class="alert alert-success py-2 px-3 small d-flex align-items-center mb-3" role="alert">
                    <i class="bi bi-check-circle-fill me-2 flex-shrink-0"></i>
                    <div>You have been safely signed out.</div>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" autocomplete="off" id="loginForm">
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label for="username" class="form-label small fw-semibold text-secondary">Username</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-person"></i></span>
                        <input type="text" class="form-control border-start-0 ps-0" id="username" name="username" 
                               value="<?= e($usernameVal) ?>" required autofocus placeholder="Enter username"
                               autocomplete="username" autocapitalize="none" <?= $isLockedOut ? 'disabled' : '' ?>>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label small fw-semibold text-secondary">Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control border-start-0 border-end-0 ps-0" id="password" name="password" 
                               required placeholder="Enter password" autocomplete="current-password" <?= $isLockedOut ? 'disabled' : '' ?>>
                        <button class="btn btn-outline-secondary border-start-0 bg-white text-muted" type="button" id="togglePassword">
                            <i class="bi bi-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold rounded-3 shadow-sm btn-touch" <?= $isLockedOut ? 'disabled' : '' ?>>
                    <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
                </button>
            </form>
        </div>
    </div>

    <div class="text-center mt-4 text-muted small">
        <p class="mb-0">Demo Logins: <strong>admin</strong> / <code>admin123</code> &bull; <strong>cashier</strong> / <code>cashier123</code></p>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const toggleBtn = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    const toggleIcon = document.getElementById('toggleIcon');

    if (toggleBtn && passwordInput && toggleIcon) {
        toggleBtn.addEventListener('click', () => {
            const isPassword = passwordInput.getAttribute('type') === 'password';
            passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
            toggleIcon.className = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
        });
    }
});
</script>
</body>
</html>
