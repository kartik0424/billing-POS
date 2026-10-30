<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/helpers.php';

// Ensure user is authenticated before rendering layout
require_login();

$currentUser = current_user();
$userRole = $currentUser['role'] ?? 'cashier';
$userName = $currentUser['name'] ?? 'User';
$businessName = get_setting('business_name', 'QuickPOS Mart');
$businessAddress = get_setting('business_address', get_setting('address', ''));
$businessPhone = get_setting('business_phone', get_setting('phone', ''));
$businessGstin = get_setting('business_gstin', get_setting('gstin', ''));
$receiptFooter = get_setting('receipt_footer', get_setting('footer_message', 'Thank you for shopping with us! Visit again.'));
$paperWidth = get_setting('paper_width', '58');
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$csrfToken = csrf_token();

// Define allowed navigation items per role
$navItems = [
    [
        'file'  => 'billing.php',
        'label' => 'Billing',
        'icon'  => 'bi-receipt',
        'roles' => ['admin', 'cashier']
    ],
    [
        'file'  => 'history.php',
        'label' => 'History',
        'icon'  => 'bi-clock-history',
        'roles' => ['admin', 'cashier']
    ],
    [
        'file'  => 'products.php',
        'label' => 'Products',
        'icon'  => 'bi-box-seam',
        'roles' => ['admin']
    ],
    [
        'file'  => 'audit.php',
        'label' => 'Audit',
        'icon'  => 'bi-graph-up-arrow',
        'roles' => ['admin']
    ],
    [
        'file'  => 'settings.php',
        'label' => 'Settings',
        'icon'  => 'bi-gear',
        'roles' => ['admin']
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#2563eb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    
    <title><?= isset($pageTitle) ? e($pageTitle) . ' &bull; ' : '' ?><?= e($businessName) ?></title>

    <!-- PWA Web App Manifest -->
    <link rel="manifest" href="manifest.json">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192.png">
    <link rel="apple-touch-icon" href="assets/icons/icon-192.png">

    <!-- Bootstrap 5 & Bootstrap Icons CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <!-- Application Custom Styling -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/print.css" media="all">

    <!-- Global App State for JS -->
    <script>
        window.APP = {
            csrf: <?= json_encode($csrfToken) ?>,
            role: <?= json_encode($userRole) ?>,
            userName: <?= json_encode($userName) ?>,
            userId: <?= json_encode($currentUser['id'] ?? null) ?>,
            businessName: <?= json_encode($businessName) ?>,
            businessAddress: <?= json_encode($businessAddress) ?>,
            businessPhone: <?= json_encode($businessPhone) ?>,
            businessGstin: <?= json_encode($businessGstin) ?>,
            receiptFooter: <?= json_encode($receiptFooter) ?>,
            paperWidth: <?= json_encode($paperWidth) ?>,
            printerIp: <?= json_encode(get_setting('printer_ip', '')) ?>,
            printerPort: <?= json_encode(get_setting('printer_port', '9100')) ?>
        };
    </script>
</head>
<body class="bg-slate-50 d-flex flex-column min-vh-100">

<!-- Network Status Banner (Auto-managed by app.js) -->
<div id="networkStatusBanner" class="alert alert-warning text-center py-1 mb-0 rounded-0 border-0 small fw-semibold d-none" role="alert">
    <i class="bi bi-wifi-off me-1"></i> You are currently offline. Local changes will sync when connected.
</div>

<!-- Desktop & Tablet Top Navigation Bar -->
<header class="app-topbar sticky-top bg-white border-bottom shadow-xs">
    <div class="container-fluid px-3 px-lg-4">
        <div class="d-flex align-items-center justify-content-between py-2">
            <!-- Brand / Business Title -->
            <a href="<?= $userRole === 'admin' ? 'audit.php' : 'billing.php' ?>" class="navbar-brand d-flex align-items-center gap-2 text-decoration-none text-dark me-2 min-w-0">
                <span class="brand-icon bg-primary text-white rounded-3 d-inline-flex align-items-center justify-content-center shadow-xs flex-shrink-0">
                    <i class="bi bi-cart-check-fill fs-5"></i>
                </span>
                <span class="fw-bold fs-6 fs-sm-5 tracking-tight brand-text text-truncate"><?= e($businessName) ?></span>
            </a>

            <!-- Desktop Nav Links (Hidden on mobile) -->
            <nav class="d-none d-md-flex align-items-center gap-1 nav-desktop">
                <?php foreach ($navItems as $item): ?>
                    <?php if (in_array($userRole, $item['roles'], true)): ?>
                        <?php $isActive = ($currentPage === $item['file']); ?>
                        <a href="<?= e($item['file']) ?>" class="nav-link px-3 py-2 rounded-3 fw-medium d-flex align-items-center gap-2 <?= $isActive ? 'active text-primary bg-primary-subtle' : 'text-secondary hover-bg-light' ?>">
                            <i class="bi <?= e($item['icon']) ?>"></i>
                            <span><?= e($item['label']) ?></span>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>

            <!-- Right Controls: Printer Icon, Offline Sync Badge, Install App & User Menu -->
            <div class="d-flex align-items-center gap-1 gap-sm-2 flex-shrink-0">
                <!-- Global Printer Setup Button (Icon Only) -->
                <button type="button" 
                        class="btn btn-light btn-sm border rounded-circle shadow-xs d-inline-flex align-items-center justify-content-center btn-open-printer-modal nav-printer-btn" 
                        id="btnHeaderPrinterModal" 
                        data-bs-toggle="modal" 
                        data-bs-target="#printerConfigModal"
                        onclick="if (window.PRINTER) window.PRINTER.openModal();"
                        title="Printer Selection & Setup" 
                        aria-label="Printer Selection & Setup">
                    <i class="bi bi-printer text-primary fs-5"></i>
                </button>

                <!-- Offline Sync Indicator Badge -->
                <span class="badge bg-warning text-dark rounded-pill small offline-sync-indicator d-none shadow-xs px-2 py-1" title="Offline bills awaiting server sync">
                    <i class="bi bi-cloud-arrow-up"></i>
                    <span class="offline-count-num ms-1">0</span>
                    <span class="d-none d-sm-inline ms-1">queued</span>
                </span>

                <!-- PWA Install Button (Shown dynamically by pwa.js on beforeinstallprompt) -->
                <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-2 px-sm-3 py-1 d-none btn-install-pwa d-inline-flex align-items-center gap-1 shadow-xs" title="Install Web App">
                    <i class="bi bi-download"></i>
                    <span class="d-none d-sm-inline small fw-medium">Install</span>
                </button>

                <!-- User Status & Logout Menu -->
                <div class="dropdown">
                    <button class="btn btn-light bg-slate-100 border-0 rounded-pill d-inline-flex align-items-center gap-1 gap-sm-2 py-1 px-1 px-sm-2 pe-sm-3" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="<?= e($userName) ?> (<?= e($userRole) ?>)">
                        <span class="user-avatar rounded-circle bg-primary-subtle text-primary fw-bold d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 32px; height: 32px; font-size: 0.85rem;">
                            <?= strtoupper(substr($userName, 0, 1)) ?>
                        </span>
                        <span class="d-none d-sm-inline fw-semibold text-dark small text-truncate" style="max-width: 110px;"><?= e($userName) ?></span>
                        <span class="badge rounded-pill <?= $userRole === 'admin' ? 'bg-danger-subtle text-danger' : 'bg-primary-subtle text-primary' ?> text-capitalize small d-none d-md-inline">
                            <?= e($userRole) ?>
                        </span>
                        <i class="bi bi-chevron-down text-muted small pe-1"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 rounded-3 mt-1 py-1">
                        <li class="px-3 py-2 border-bottom">
                            <div class="fw-bold text-dark small"><?= e($userName) ?></div>
                            <div class="text-muted small">Role: <span class="text-capitalize fw-medium"><?= e($userRole) ?></span></div>
                        </li>
                        <li>
                            <a class="dropdown-item py-2 small d-flex align-items-center gap-2 btn-open-printer-modal" href="javascript:void(0);" onclick="if (window.PRINTER) window.PRINTER.openModal();">
                                <i class="bi bi-printer text-primary"></i> Printer Selection & Setup
                            </a>
                        </li>
                        <?php if ($userRole === 'admin'): ?>
                            <li>
                                <a class="dropdown-item py-2 small d-flex align-items-center gap-2" href="settings.php">
                                    <i class="bi bi-sliders text-muted"></i> System Settings
                                </a>
                            </li>
                        <?php endif; ?>
                        <li>
                            <a class="dropdown-item py-2 small text-danger d-flex align-items-center gap-2" href="logout.php">
                                <i class="bi bi-box-arrow-right"></i> Sign Out
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</header>

<!-- Toast Container for Dynamic Notifications -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1090;" id="toastContainer"></div>

<!-- Global Bootstrap Confirmation Modal -->
<div class="modal fade" id="appConfirmModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-body text-center p-4">
                <div id="confirmModalIcon" class="mb-3 text-warning">
                    <i class="bi bi-question-circle fs-1"></i>
                </div>
                <h6 class="fw-bold mb-2" id="confirmModalTitle">Are you sure?</h6>
                <p class="text-muted small mb-4" id="confirmModalMessage">Do you really want to proceed with this action?</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-light rounded-3 px-3 py-2 flex-grow-1" data-bs-dismiss="modal" id="confirmModalCancelBtn">Cancel</button>
                    <button type="button" class="btn btn-primary rounded-3 px-3 py-2 flex-grow-1" id="confirmModalConfirmBtn">Confirm</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main App Content Container -->
<main class="app-main flex-grow-1 container-fluid px-2 px-md-3 py-3">
