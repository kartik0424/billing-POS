<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Route authenticated users to their landing page, or unauthenticated users to login
if (is_logged_in()) {
    $user = current_user();
    if ($user && $user['role'] === 'admin') {
        header('Location: audit.php');
    } else {
        header('Location: billing.php');
    }
    exit;
}

header('Location: login.php');
exit;
