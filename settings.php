<?php
declare(strict_types=1);

/**
 * System Settings & Store Configuration (Admin Only)
 * Allows administrators to manage store identity, billing formats, and thermal printer hardware.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

// Strict Server-Side Role Enforcement: Admin Only
require_role('admin');

$pageTitle = 'Settings & Hardware';
$pageScripts = [
    'assets/js/printer.js',
    'assets/js/settings.js'
];

require_once __DIR__ . '/includes/header.php';

// Fetch current settings from DB with defaults
$currentSettings = get_all_settings();
$businessName = $currentSettings['business_name'] ?? 'QuickPOS Mart';
$businessAddress = $currentSettings['business_address'] ?? $currentSettings['address'] ?? 'Shop 4, Market Complex, MG Road, Pune';
$businessPhone = $currentSettings['business_phone'] ?? $currentSettings['phone'] ?? '9876543210';
$businessGstin = $currentSettings['business_gstin'] ?? $currentSettings['gstin'] ?? '27AAAAA0000A1Z5';
$receiptFooter = $currentSettings['receipt_footer'] ?? $currentSettings['footer_message'] ?? 'Thank you for your visit! Please visit again.';
$paperWidth = $currentSettings['paper_width'] ?? '58';
$printerIp = $currentSettings['printer_ip'] ?? '';
$printerPort = $currentSettings['printer_port'] ?? '9100';
$billPrefix = $currentSettings['bill_prefix'] ?? 'INV-';
?>

<div class="container-fluid px-0" style="max-width: 900px;">
    <!-- Top Action Bar -->
    <div class="mb-4">
        <h4 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
            <span>System Settings</span>
            <span class="badge bg-secondary-subtle text-secondary rounded-pill fs-6">Admin</span>
        </h4>
        <p class="text-muted small mb-0">Manage business details, receipt formatting, and thermal ESC/POS hardware</p>
    </div>

    <!-- Settings Form -->
    <form id="settingsForm" novalidate>
        <!-- CARD 1: Store & Business Profile -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 pt-4 px-4 pb-2">
                <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                    <i class="bi bi-shop text-primary"></i>
                    <span>Store Identity & Contact Details</span>
                </h6>
                <small class="text-muted">Displayed on generated bills, thermal receipts, and reports</small>
            </div>
            <div class="card-body p-4 pt-2">
                <div class="row g-3">
                    <!-- Business Name -->
                    <div class="col-12 col-md-6">
                        <label for="settingBusinessName" class="form-label small fw-semibold text-secondary">
                            Business Name <span class="text-danger">*</span>
                        </label>
                        <input type="text" class="form-control rounded-3" id="settingBusinessName" name="business_name" value="<?= e($businessName) ?>" required maxlength="100" placeholder="e.g. QuickPOS Mart">
                        <div class="invalid-feedback small">Business name is required (max 100 characters).</div>
                    </div>

                    <!-- Contact Phone -->
                    <div class="col-12 col-md-6">
                        <label for="settingBusinessPhone" class="form-label small fw-semibold text-secondary">Store Phone Number</label>
                        <div class="input-group">
                            <span class="input-group-text bg-slate-50 border-end-0 text-muted"><i class="bi bi-telephone"></i></span>
                            <input type="tel" class="form-control rounded-end-3" id="settingBusinessPhone" name="business_phone" value="<?= e($businessPhone) ?>" maxlength="20" placeholder="e.g. 9876543210">
                        </div>
                    </div>

                    <!-- Store Address -->
                    <div class="col-12">
                        <label for="settingBusinessAddress" class="form-label small fw-semibold text-secondary">Store Physical Address</label>
                        <input type="text" class="form-control rounded-3" id="settingBusinessAddress" name="business_address" value="<?= e($businessAddress) ?>" maxlength="255" placeholder="e.g. Shop 4, Market Complex, MG Road, Pune">
                    </div>

                    <!-- GSTIN -->
                    <div class="col-12 col-md-6">
                        <label for="settingBusinessGstin" class="form-label small fw-semibold text-secondary">GSTIN / Tax ID (Optional)</label>
                        <input type="text" class="form-control rounded-3 text-uppercase font-monospace" id="settingBusinessGstin" name="business_gstin" value="<?= e($businessGstin) ?>" maxlength="20" placeholder="e.g. 27AAAAA0000A1Z5">
                    </div>

                    <!-- Receipt Footer Message -->
                    <div class="col-12 col-md-6">
                        <label for="settingReceiptFooter" class="form-label small fw-semibold text-secondary">Receipt Footer Note</label>
                        <input type="text" class="form-control rounded-3" id="settingReceiptFooter" name="receipt_footer" value="<?= e($receiptFooter) ?>" maxlength="255" placeholder="e.g. Thank you for your visit!">
                    </div>
                </div>
            </div>
        </div>

        <!-- CARD 2: Billing & Invoice Configuration -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 pt-4 px-4 pb-2">
                <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                    <i class="bi bi-receipt-cutoff text-primary"></i>
                    <span>Billing & Thermal Paper Specifications</span>
                </h6>
                <small class="text-muted">Invoice numbering formats and paper roll dimensions</small>
            </div>
            <div class="card-body p-4 pt-2">
                <div class="row g-3">
                    <!-- Bill Prefix -->
                    <div class="col-12 col-md-6">
                        <label for="settingBillPrefix" class="form-label small fw-semibold text-secondary">
                            Bill Number Prefix <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <input type="text" class="form-control font-monospace text-uppercase" id="settingBillPrefix" name="bill_prefix" value="<?= e($billPrefix) ?>" required maxlength="10" placeholder="INV-">
                            <span class="input-group-text bg-slate-50 small text-muted">000001</span>
                        </div>
                        <div class="form-text small" style="font-size: 0.75rem;">Sequential bills will appear as: <span class="font-monospace text-dark"><?= e($billPrefix) ?>000001</span></div>
                    </div>

                    <!-- Paper Width Toggle (58mm vs 80mm) -->
                    <div class="col-12 col-md-6">
                        <label class="form-label small fw-semibold text-secondary d-block">
                            Thermal Paper Width <span class="text-danger">*</span>
                        </label>
                        <div class="d-flex gap-2">
                            <label class="card p-2 flex-grow-1 border rounded-3 text-center paper-width-card <?= $paperWidth === '58' ? 'border-primary bg-primary-subtle' : 'bg-white' ?>" style="cursor: pointer;">
                                <input type="radio" name="paper_width" value="58" class="d-none" <?= $paperWidth === '58' ? 'checked' : '' ?>>
                                <div class="fw-bold fs-6 text-dark mb-0">58 mm (2-inch)</div>
                                <div class="text-muted small" style="font-size: 0.72rem;">32 characters per line</div>
                            </label>
                            <label class="card p-2 flex-grow-1 border rounded-3 text-center paper-width-card <?= $paperWidth === '80' ? 'border-primary bg-primary-subtle' : 'bg-white' ?>" style="cursor: pointer;">
                                <input type="radio" name="paper_width" value="80" class="d-none" <?= $paperWidth === '80' ? 'checked' : '' ?>>
                                <div class="fw-bold fs-6 text-dark mb-0">80 mm (3-inch)</div>
                                <div class="text-muted small" style="font-size: 0.72rem;">48 characters per line</div>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CARD 3: ESC/POS Network Socket Thermal Printer -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 pt-4 px-4 pb-2 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                <div>
                    <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                        <i class="bi bi-hdd-network text-primary"></i>
                        <span>WiFi & LAN Network Thermal Printer (ESC/POS)</span>
                    </h6>
                    <small class="text-muted">Direct TCP socket communication over your local WiFi / LAN subnet</small>
                </div>

                <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3 py-1 btn-open-printer-modal">
                    <i class="bi bi-sliders me-1"></i> Quick Test
                </button>
            </div>
            <div class="card-body p-4 pt-2">
                <div class="alert alert-info py-2 px-3 small border-0 bg-info-subtle text-info-emphasis rounded-3 mb-3 d-flex align-items-center gap-2">
                    <i class="bi bi-info-circle-fill fs-5 flex-shrink-0"></i>
                    <div>
                        <strong>Network Architecture Requirement:</strong> WiFi socket printing requires the host web server and the thermal receipt printer to reside on the same Local Area Network (LAN), typically communicating via raw TCP port 9100.
                    </div>
                </div>

                <div class="row g-3">
                    <!-- Printer IP -->
                    <div class="col-12 col-md-8">
                        <label for="settingPrinterIp" class="form-label small fw-semibold text-secondary">
                            Printer Static IP Address (Leave blank if using Bluetooth or Browser)
                        </label>
                        <div class="input-group">
                            <span class="input-group-text bg-slate-50 border-end-0 text-muted"><i class="bi bi-router"></i></span>
                            <input type="text" class="form-control rounded-end-3 font-monospace" id="settingPrinterIp" name="printer_ip" value="<?= e($printerIp) ?>" placeholder="e.g. 192.168.1.100">
                        </div>
                        <div class="form-text small" style="font-size: 0.75rem;">Must be a static or DHCP-reserved IP on your WiFi router.</div>
                    </div>

                    <!-- Printer Port -->
                    <div class="col-12 col-md-4">
                        <label for="settingPrinterPort" class="form-label small fw-semibold text-secondary">TCP Socket Port</label>
                        <input type="number" class="form-control rounded-3 font-monospace" id="settingPrinterPort" name="printer_port" value="<?= e($printerPort) ?>" min="1" max="65535" placeholder="9100">
                        <div class="form-text small" style="font-size: 0.75rem;">Standard thermal raw port is 9100.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Submit Bar -->
        <div class="d-flex justify-content-end align-items-center gap-2 mb-5">
            <button type="reset" class="btn btn-light rounded-pill px-4 py-2 border shadow-xs" id="btnResetSettings">
                Reset
            </button>
            <button type="submit" class="btn btn-primary rounded-pill px-4 py-2 fw-semibold shadow-xs d-flex align-items-center gap-2" id="btnSaveSettings">
                <i class="bi bi-check2"></i>
                <span id="btnSaveSettingsText">Save Settings</span>
            </button>
        </div>
    </form>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
