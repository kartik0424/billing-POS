<?php
declare(strict_types=1);

// Ensure variables from header exist
$userRole = $userRole ?? ($_SESSION['user_role'] ?? 'cashier');
$currentPage = $currentPage ?? basename($_SERVER['SCRIPT_NAME'] ?? '');
$navItems = $navItems ?? [];
?>
</main>

<!-- Mobile Bottom Navigation Bar (Visible only on < md screens) -->
<nav class="mobile-bottom-nav d-md-none fixed-bottom bg-white border-top shadow-lg">
    <div class="d-flex align-items-center justify-content-around h-100 px-1">
        <?php foreach ($navItems as $item): ?>
            <?php if (in_array($userRole, $item['roles'], true)): ?>
                <?php $isActive = ($currentPage === $item['file']); ?>
                <a href="<?= e($item['file']) ?>" class="bottom-nav-item d-flex flex-column align-items-center justify-content-center text-decoration-none <?= $isActive ? 'active text-primary' : 'text-secondary' ?>">
                    <div class="icon-wrap position-relative">
                        <i class="bi <?= e($item['icon']) ?> fs-5"></i>
                    </div>
                    <span class="label small fw-medium mt-1"><?= e($item['label']) ?></span>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</nav>

<!-- Global Thermal Printer Configuration Modal -->
<div class="modal fade" id="printerConfigModal" tabindex="-1" aria-labelledby="printerConfigModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4 overflow-hidden">
            <div class="modal-header border-bottom py-3">
                <h6 class="modal-title fw-bold text-dark d-flex align-items-center gap-2" id="printerConfigModalTitle">
                    <i class="bi bi-printer text-primary"></i>
                    <span>Thermal Printer Setup</span>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3">
                <!-- Print Method Selector -->
                <label class="form-label small fw-semibold text-secondary mb-2">Select Active Print Method</label>
                <div class="row g-2 mb-3" id="printerMethodCards">
                    <div class="col-4">
                        <div class="card h-100 p-2 text-center border rounded-3 printer-method-option" data-method="browser" style="cursor: pointer;">
                            <i class="bi bi-window fs-4 text-primary mb-1"></i>
                            <div class="fw-semibold small">Browser</div>
                            <div class="text-muted" style="font-size: 0.68rem;">USB / PDF</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="card h-100 p-2 text-center border rounded-3 printer-method-option" data-method="bluetooth" style="cursor: pointer;">
                            <i class="bi bi-bluetooth fs-4 text-primary mb-1"></i>
                            <div class="fw-semibold small">Bluetooth</div>
                            <div class="text-muted" style="font-size: 0.68rem;">Direct BLE</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="card h-100 p-2 text-center border rounded-3 printer-method-option" data-method="wifi" style="cursor: pointer;">
                            <i class="bi bi-wifi fs-4 text-primary mb-1"></i>
                            <div class="fw-semibold small">WiFi / LAN</div>
                            <div class="text-muted" style="font-size: 0.68rem;">Socket 9100</div>
                        </div>
                    </div>
                </div>

                <!-- Panel 1: Bluetooth Setup -->
                <div id="printerPanelBluetooth" class="card bg-slate-50 border rounded-3 p-3 mb-3 d-none">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small fw-semibold text-dark">Bluetooth Connection</span>
                        <span class="badge bg-secondary-subtle text-secondary small rounded-pill" id="bleStatusBadge">Disconnected</span>
                    </div>
                    <div class="small text-muted mb-3" style="font-size: 0.75rem;">
                        Pairs directly with ESC/POS BLE thermal receipt printers. Requires Chrome or Edge on Android with HTTPS.
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary btn-sm rounded-3 px-3 py-2 flex-grow-1" id="btnBleConnect">
                            <i class="bi bi-bluetooth me-1"></i> Connect Printer
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm rounded-3 px-3 py-2 d-none" id="btnBleDisconnect">
                            Disconnect
                        </button>
                    </div>
                </div>

                <!-- Panel 2: WiFi Setup Note & Configuration -->
                <div id="printerPanelWifi" class="card bg-slate-50 border rounded-3 p-3 mb-3 d-none">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small fw-semibold text-dark">Network Thermal Printer</span>
                        <span class="badge bg-info-subtle text-info small rounded-pill">LAN Socket 9100</span>
                    </div>
                    <div class="small text-muted mb-2" style="font-size: 0.75rem;">
                        Direct RAW ESC/POS socket connection to network thermal printer.
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-8">
                            <label for="modalPrinterIp" class="form-label small fw-semibold text-secondary mb-1">Printer IP Address</label>
                            <input type="text" class="form-control form-control-sm font-monospace" id="modalPrinterIp" placeholder="e.g. 192.168.1.100" value="<?= e(function_exists('get_setting') ? get_setting('printer_ip', '') : '') ?>">
                        </div>
                        <div class="col-4">
                            <label for="modalPrinterPort" class="form-label small fw-semibold text-secondary mb-1">Port</label>
                            <input type="number" class="form-control form-control-sm font-monospace" id="modalPrinterPort" placeholder="9100" value="<?= e(function_exists('get_setting') ? get_setting('printer_port', '9100') : '9100') ?>">
                        </div>
                    </div>
                    <div class="d-flex gap-2 mb-2">
                        <button type="button" class="btn btn-primary btn-sm rounded-3 flex-grow-1" id="btnModalSavePrinterIp">
                            <i class="bi bi-check2 me-1"></i> Save IP
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm rounded-3" id="btnModalTestWifiLink">
                            <i class="bi bi-plug me-1"></i> Test Link
                        </button>
                    </div>
                    <div class="small text-muted" id="modalWifiStatusHint" style="font-size: 0.72rem;">
                        <i class="bi bi-info-circle me-1"></i> If no network printer is reachable on your LAN, select <strong>Browser</strong> above for USB & desktop printing.
                    </div>
                </div>

                <!-- Panel 3: Browser Fallback Note -->
                <div id="printerPanelBrowser" class="card bg-slate-50 border rounded-3 p-3 mb-3">
                    <div class="d-flex align-items-center gap-2 text-success fw-semibold small mb-1">
                        <i class="bi bi-check-circle-fill"></i>
                        <span>Browser Print (Recommended)</span>
                    </div>
                    <div class="small text-muted" style="font-size: 0.78rem;">
                        Uses your operating system's standard print dialog. Supports any installed USB thermal printer, virtual PDF, or network office printer without socket timeouts.
                    </div>
                </div>

                <!-- Receipt Paper Roll Width -->
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-secondary mb-1">Receipt Paper Roll Width</label>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary flex-grow-1 rounded-3 py-2 btn-modal-paper-width" data-width="58">
                            58mm (2-inch roll)
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary flex-grow-1 rounded-3 py-2 btn-modal-paper-width" data-width="80">
                            80mm (3-inch roll)
                        </button>
                    </div>
                </div>

                <!-- Test Print Button -->
                <button type="button" class="btn btn-outline-primary btn-sm rounded-3 w-100 py-2 d-flex align-items-center justify-content-center gap-2 fw-medium" id="btnModalTestPrint">
                    <i class="bi bi-printer"></i>
                    <span>Send Test Print</span>
                </button>
            </div>
            <div class="modal-footer border-top-0 pt-0 px-3 pb-3">
                <button type="button" class="btn btn-light rounded-3 px-3 py-2 btn-touch w-100" data-bs-dismiss="modal">
                    Done
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap 5 Bundle JS CDN (Includes Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<!-- Global App Core Script -->
<script src="assets/js/app.js"></script>

<!-- Progressive Web App & Offline Sync Script -->
<script src="assets/js/pwa.js"></script>

<!-- Thermal Printer System (Global) -->
<script src="assets/js/printer.js"></script>

<?php if (!empty($pageScripts) && is_array($pageScripts)): ?>
    <?php foreach ($pageScripts as $script): ?>
        <?php if ($script !== 'assets/js/printer.js'): ?>
            <script src="<?= e($script) ?>"></script>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
