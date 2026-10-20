/**
 * Unified Thermal Printer Management & ESC/POS System
 * 
 * SUPPORTS THREE PRINTING METHODS:
 * 1. Bluetooth: Web Bluetooth API for Direct BLE Thermal Printers (58mm/80mm).
 *    NOTE: Requires HTTPS (or localhost) and Chrome/Edge on Android or Desktop. BLE only.
 * 2. WiFi / LAN Network: Direct raw socket printing to port 9100 via api/print_network.php.
 *    NOTE: Server host and thermal printer must reside on the same Local Area Network (LAN).
 * 3. Browser Print Preview: Standard window.print() thermal fallback styled via assets/css/print.css.
 */

(function () {
    'use strict';

    // BLE Service UUIDs commonly used by 58mm/80mm Bluetooth Thermal Printers
    const PRINTER_SERVICES = [
        '000018f0-0000-1000-8000-00805f9b34fb', // Standard BLE thermal printer service
        '0000ff00-0000-1000-8000-00805f9b34fb', // Common ESC/POS BLE service
        'e7810a71-73ae-499d-8c15-faa9aef0c3f2', // ESC/POS vendor service
        '49535343-fe7d-4ae5-8fa9-9fafd205e455', // ISSC transparent UART service
        '0000ae00-0000-1000-8000-00805f9b34fb', // Alternate POS printer service
        '0000fee7-0000-1000-8000-00805f9b34fb'  // Tencent / Micro-printer service
    ];

    // Local State
    let bleDevice = null;
    let bleServer = null;
    let bleCharacteristic = null;
    let isConnecting = false;

    // Preference keys in localStorage
    const STORAGE_KEY_METHOD = 'pos_printer_method'; // 'browser' | 'bluetooth' | 'wifi'
    const STORAGE_KEY_BLE_ID = 'pos_ble_device_id';
    const STORAGE_KEY_BLE_NAME = 'pos_ble_device_name';

    // ========================================================================
    // 1. ESC/POS Byte Stream Builder (Client-side)
    // ========================================================================
    const ESCPOS = {
        // Command byte arrays
        INIT: new Uint8Array([0x1B, 0x40]),
        ALIGN_LEFT: new Uint8Array([0x1B, 0x61, 0x00]),
        ALIGN_CENTER: new Uint8Array([0x1B, 0x61, 0x01]),
        ALIGN_RIGHT: new Uint8Array([0x1B, 0x61, 0x02]),
        BOLD_ON: new Uint8Array([0x1B, 0x45, 0x01]),
        BOLD_OFF: new Uint8Array([0x1B, 0x45, 0x00]),
        DOUBLE_SIZE: new Uint8Array([0x1D, 0x21, 0x11]),
        NORMAL_SIZE: new Uint8Array([0x1D, 0x21, 0x00]),
        FEED_4: new Uint8Array([0x1B, 0x64, 0x04]),
        PARTIAL_CUT: new Uint8Array([0x1D, 0x56, 0x41, 0x00]),

        /**
         * Helper to encode ASCII string to Uint8Array (Replacing ₹ with "Rs.")
         */
        encodeText(str) {
            if (!str) return new Uint8Array(0);
            // Replace Rupee symbols since most thermal font ROMs lack the glyph
            const sanitized = str.replace(/₹/g, 'Rs.').replace(/\u20B9/g, 'Rs.');
            return new TextEncoder().encode(sanitized);
        },

        /**
         * Concatenate multiple Uint8Arrays into one
         */
        concat(arrays) {
            let totalLen = arrays.reduce((acc, a) => acc + a.length, 0);
            let merged = new Uint8Array(totalLen);
            let offset = 0;
            for (let a of arrays) {
                merged.set(a, offset);
                offset += a.length;
            }
            return merged;
        },

        /**
         * Format a row with left-aligned label and right-aligned value
         */
        formatRow(label, val, width) {
            const cleanLabel = (label || '').replace(/₹/g, 'Rs.');
            const cleanVal = (val || '').replace(/₹/g, 'Rs.');
            const spaceNeeded = width - cleanLabel.length - cleanVal.length;
            const spaces = spaceNeeded > 0 ? ' '.repeat(spaceNeeded) : ' ';
            return this.encodeText(cleanLabel + spaces + cleanVal + '\n');
        },

        /**
         * Build complete ESC/POS binary receipt for a bill
         */
        buildReceiptBytes(bill, settings = {}) {
            const paperWidth = settings.paper_width || window.APP?.paperWidth || '58';
            const width = (paperWidth === '80') ? 48 : 32;
            const sepLine = '-'.repeat(width) + '\n';
            const doubleSep = '='.repeat(width) + '\n';

            const parts = [];

            // 1. Initialize printer
            parts.push(this.INIT);

            // 2. Header: Business Name (Double size, bold, center)
            parts.push(this.ALIGN_CENTER);
            parts.push(this.BOLD_ON);
            parts.push(this.DOUBLE_SIZE);
            const bName = (settings.business_name || window.APP?.businessName || 'QuickPOS Mart').toUpperCase();
            parts.push(this.encodeText(bName + '\n'));
            parts.push(this.NORMAL_SIZE);
            parts.push(this.BOLD_OFF);

            // Address & Contact details
            const addr = settings.business_address || settings.address || window.APP?.businessAddress || '';
            if (addr) parts.push(this.encodeText(addr + '\n'));
            const phone = settings.business_phone || settings.phone || window.APP?.businessPhone || '';
            if (phone) parts.push(this.encodeText('Ph: ' + phone + '\n'));
            const gstin = settings.business_gstin || settings.gstin || window.APP?.businessGstin || '';
            if (gstin) parts.push(this.encodeText('GSTIN: ' + gstin + '\n'));

            parts.push(this.encodeText(doubleSep));

            // 3. Bill Metadata (Left-aligned)
            parts.push(this.ALIGN_LEFT);
            parts.push(this.encodeText('BILL NO: ' + (bill.bill_no || 'N/A') + '\n'));
            parts.push(this.encodeText('DATE   : ' + (bill.date_formatted || bill.completed_at || bill.created_at || new Date().toLocaleString()) + '\n'));
            if (bill.cashier_name || bill.cashier) {
                parts.push(this.encodeText('CASHIER: ' + (bill.cashier_name || bill.cashier) + '\n'));
            }

            // Customer details (Only print if present)
            if (bill.customer_name && bill.customer_name !== 'Walk-in' && bill.customer_name !== 'Walk-in Customer') {
                parts.push(this.encodeText('CUSTOMER: ' + bill.customer_name + '\n'));
            }
            if (bill.customer_phone && bill.customer_phone !== '-') {
                parts.push(this.encodeText('PHONE   : ' + bill.customer_phone + '\n'));
            }

            parts.push(this.encodeText(sepLine));

            // 4. Items Table
            parts.push(this.BOLD_ON);
            if (width === 48) {
                // 80mm: Item(22) | Qty(5) | Price(9) | Total(10)
                parts.push(this.encodeText(
                    'ITEM'.padEnd(22) + ' ' +
                    'QTY'.padStart(5) + ' ' +
                    'PRICE'.padStart(9) + ' ' +
                    'TOTAL'.padStart(10) + '\n'
                ));
            } else {
                // 58mm: Item(14) | Qty(3) | Price(6) | Total(7)
                parts.push(this.encodeText(
                    'ITEM'.padEnd(14) + ' ' +
                    'QTY'.padStart(3) + ' ' +
                    'PRICE'.padStart(6) + ' ' +
                    'TOTAL'.padStart(7) + '\n'
                ));
            }
            parts.push(this.BOLD_OFF);
            parts.push(this.encodeText(sepLine));

            // Items List
            (bill.items || []).forEach(item => {
                const name = item.item_name || item.name || 'Item';
                const qty = Number(item.quantity) || 1;
                const qtyStr = (qty % 1 === 0) ? qty.toString() : qty.toFixed(2);
                const priceStr = Number(item.price || 0).toFixed(2);
                const totalStr = Number(item.line_total || (qty * Number(item.price || 0))).toFixed(2);

                if (width === 48) {
                    const truncated = name.length > 22 ? name.substring(0, 22) : name;
                    parts.push(this.encodeText(
                        truncated.padEnd(22) + ' ' +
                        qtyStr.padStart(5) + ' ' +
                        priceStr.padStart(9) + ' ' +
                        totalStr.padStart(10) + '\n'
                    ));
                    if (name.length > 22) {
                        parts.push(this.encodeText('  ' + name.substring(22, 46) + '\n'));
                    }
                } else {
                    const truncated = name.length > 14 ? name.substring(0, 14) : name;
                    parts.push(this.encodeText(
                        truncated.padEnd(14) + ' ' +
                        qtyStr.padStart(3) + ' ' +
                        priceStr.padStart(6) + ' ' +
                        totalStr.padStart(7) + '\n'
                    ));
                    if (name.length > 14) {
                        parts.push(this.encodeText(' ' + name.substring(14, 30) + '\n'));
                    }
                }
            });

            parts.push(this.encodeText(sepLine));

            // 5. Financial Totals
            const subtotal = Number(bill.subtotal || 0).toFixed(2);
            const discount = Number(bill.discount_amount || 0);
            const total = Number(bill.total || 0).toFixed(2);
            const advance = Number(bill.advance_paid || 0);
            const balance = Number(bill.balance_due || 0);

            parts.push(this.formatRow('Subtotal', 'Rs. ' + subtotal, width));

            // Only print discount line if present
            if (discount > 0) {
                parts.push(this.formatRow('Discount', '-Rs. ' + discount.toFixed(2), width));
            }

            parts.push(this.encodeText(sepLine));
            parts.push(this.BOLD_ON);
            parts.push(this.formatRow('GRAND TOTAL', 'Rs. ' + total, width));
            parts.push(this.BOLD_OFF);
            parts.push(this.encodeText(sepLine));

            // Payment Mode & Breakdown
            const mode = (bill.payment_mode || 'cash').toUpperCase();
            parts.push(this.formatRow('Payment Mode', mode, width));

            if (mode === 'MIXED') {
                parts.push(this.formatRow('  - UPI Amount', 'Rs. ' + Number(bill.upi_amount || 0).toFixed(2), width));
                parts.push(this.formatRow('  - Cash Amount', 'Rs. ' + Number(bill.cash_amount || 0).toFixed(2), width));
            }

            // Only print advance/balance if pending credit bill
            if (advance > 0 && balance > 0) {
                parts.push(this.formatRow('Advance Paid', 'Rs. ' + advance.toFixed(2), width));
                parts.push(this.BOLD_ON);
                parts.push(this.formatRow('BALANCE DUE', 'Rs. ' + balance.toFixed(2), width));
                parts.push(this.BOLD_OFF);
            }

            parts.push(this.encodeText(doubleSep));

            // 6. Footer Message & Cut
            parts.push(this.ALIGN_CENTER);
            const footer = settings.receipt_footer || settings.footer_message || window.APP?.receiptFooter || 'Thank you for your visit!';
            parts.push(this.encodeText(footer + '\n'));
            parts.push(this.encodeText('POWERED BY QUICKPOS PWA\n'));

            parts.push(this.FEED_4);
            parts.push(this.PARTIAL_CUT);

            return this.concat(parts);
        }
    };

    // ========================================================================
    // 2. Web Bluetooth BLE Connection & Transmission
    // ========================================================================

    /**
     * Check if Web Bluetooth is supported on the current device & browser
     */
    function isBluetoothSupported() {
        return !!(navigator.bluetooth && navigator.bluetooth.requestDevice);
    }

    /**
     * Scan for nearby Bluetooth thermal printers and connect to GATT Server
     */
    async function connectBluetooth() {
        if (!isBluetoothSupported()) {
            if (!window.isSecureContext) {
                throw new Error('Web Bluetooth requires HTTPS or localhost. Please open the app over a secure HTTPS connection.');
            }
            throw new Error('Web Bluetooth is not supported on this browser. Please use Chrome or Edge on Android or Windows.');
        }

        if (isConnecting) return;
        isConnecting = true;

        try {
            toast('Scanning for Bluetooth thermal printers...', 'info', 2500);

            // Request Bluetooth Device with standard thermal printer service filters
            bleDevice = await navigator.bluetooth.requestDevice({
                acceptAllDevices: true,
                optionalServices: PRINTER_SERVICES
            });

            if (!bleDevice) {
                throw new Error('No Bluetooth device selected.');
            }

            // Bind disconnect listener
            bleDevice.addEventListener('gattserverdisconnected', onDeviceDisconnected);

            toast(`Connecting to ${bleDevice.name || 'Printer'}...`, 'info', 2000);

            // Connect GATT Server
            bleServer = await bleDevice.gatt.connect();

            // Auto-discover writable characteristic across all services
            bleCharacteristic = await discoverWritableCharacteristic(bleServer);

            if (!bleCharacteristic) {
                throw new Error('Connected to Bluetooth device, but no writable ESC/POS print characteristic was found.');
            }

            // Save device details in localStorage for auto-reconnect
            localStorage.setItem(STORAGE_KEY_BLE_ID, bleDevice.id);
            localStorage.setItem(STORAGE_KEY_BLE_NAME, bleDevice.name || 'Thermal Printer');
            setPrintMethod('bluetooth');

            updateUIStatus();
            toast(`Connected to ${bleDevice.name || 'Thermal Printer'}!`, 'success');
            return true;
        } catch (err) {
            console.error('[BLE Connect Error]', err);
            onDeviceDisconnected();

            if (err.name === 'NotFoundError') {
                toast('Bluetooth scan cancelled.', 'warning');
            } else {
                toast(err.message || 'Failed to connect to Bluetooth printer.', 'danger');
            }
            throw err;
        } finally {
            isConnecting = false;
        }
    }

    /**
     * Discover a writable characteristic from the connected GATT server
     */
    async function discoverWritableCharacteristic(server) {
        // Iterate through all candidate printer services
        for (const serviceUuid of PRINTER_SERVICES) {
            try {
                const service = await server.getPrimaryService(serviceUuid);
                const characteristics = await service.getCharacteristics();

                for (const char of characteristics) {
                    if (char.properties.write || char.properties.writeWithoutResponse) {
                        return char;
                    }
                }
            } catch (e) {
                // Continue to next candidate service
            }
        }

        // If specific services didn't match, attempt general discovery of all primary services
        try {
            const allServices = await server.getPrimaryServices();
            for (const service of allServices) {
                try {
                    const characteristics = await service.getCharacteristics();
                    for (const char of characteristics) {
                        if (char.properties.write || char.properties.writeWithoutResponse) {
                            return char;
                        }
                    }
                } catch (e) {
                    // Ignore characteristic fetch error for restricted service
                }
            }
        } catch (e) {
            // Service discovery error
        }

        return null;
    }

    /**
     * Send byte stream to BLE thermal printer in chunks (100-180 bytes)
     * Microcontroller BLE buffers easily overflow without small delays between chunks.
     */
    async function sendBluetoothData(bytes) {
        if (!bleDevice || !bleDevice.gatt.connected || !bleCharacteristic) {
            // Attempt auto-reconnect if device reference is available
            if (bleDevice && bleDevice.gatt) {
                toast('Reconnecting to Bluetooth printer...', 'info', 1500);
                bleServer = await bleDevice.gatt.connect();
                bleCharacteristic = await discoverWritableCharacteristic(bleServer);
            } else {
                throw new Error('Bluetooth printer is not connected. Please click Connect in the Printer menu.');
            }
        }

        const CHUNK_SIZE = 120; // Safe chunk size for BLE MTU
        for (let i = 0; i < bytes.length; i += CHUNK_SIZE) {
            const chunk = bytes.slice(i, i + CHUNK_SIZE);
            if (bleCharacteristic.properties.writeWithoutResponse) {
                await bleCharacteristic.writeValueWithoutResponse(chunk);
            } else {
                await bleCharacteristic.writeValue(chunk);
            }
            // Small delay to prevent printer buffer overruns
            await new Promise(resolve => setTimeout(resolve, 25));
        }
    }

    /**
     * Disconnect active Bluetooth GATT connection
     */
    function disconnectBluetooth() {
        if (bleDevice && bleDevice.gatt.connected) {
            bleDevice.gatt.disconnect();
        }
        onDeviceDisconnected();
        toast('Bluetooth printer disconnected.', 'info');
    }

    function onDeviceDisconnected() {
        bleCharacteristic = null;
        bleServer = null;
        updateUIStatus();
    }

    /**
     * Attempt automatic background reconnect via navigator.bluetooth.getDevices() if available
     */
    async function tryAutoReconnect() {
        const savedId = localStorage.getItem(STORAGE_KEY_BLE_ID);
        if (!savedId || !navigator.bluetooth?.getDevices) return;

        try {
            const devices = await navigator.bluetooth.getDevices();
            const matched = devices.find(d => d.id === savedId);
            if (matched) {
                bleDevice = matched;
                bleDevice.addEventListener('gattserverdisconnected', onDeviceDisconnected);
                bleServer = await bleDevice.gatt.connect();
                bleCharacteristic = await discoverWritableCharacteristic(bleServer);
                updateUIStatus();
            }
        } catch (e) {
            // Auto-reconnect silently fails
        }
    }

    // ========================================================================
    // 3. Print Method Preferences & Main Print Dispatcher
    // ========================================================================

    function getPrintMethod() {
        return localStorage.getItem(STORAGE_KEY_METHOD) || 'browser';
    }

    function setPrintMethod(method) {
        if (['browser', 'bluetooth', 'wifi'].includes(method)) {
            localStorage.setItem(STORAGE_KEY_METHOD, method);
            updateUIStatus();
        }
    }

    /**
     * Primary Print Method
     * Automatically dispatches to Bluetooth, WiFi Socket, or Browser Fallback.
     *
     * @param {Object} bill - Bill object with items and payments
     * @param {Object} settings - Store settings (paper_width, etc.)
     * @returns {Promise<boolean>}
     */
    async function print(bill, settings = {}) {
        const method = getPrintMethod();

        // --------------------------------------------------------------------
        // A. BLUETOOTH METHOD
        // --------------------------------------------------------------------
        if (method === 'bluetooth') {
            try {
                if (!bleCharacteristic) {
                    const confirmed = await confirmDialog({
                        title: 'Connect Bluetooth Printer',
                        message: 'Bluetooth thermal printer is not connected. Would you like to scan and connect now?'
                    });
                    if (confirmed) {
                        await connectBluetooth();
                    } else {
                        // Fallback to browser print preview
                        return browserPrintFallback(bill, settings);
                    }
                }

                toast('Sending receipt to Bluetooth printer...', 'info', 2000);
                const bytes = ESCPOS.buildReceiptBytes(bill, settings);
                await sendBluetoothData(bytes);
                toast('Receipt printed successfully!', 'success');
                return true;
            } catch (err) {
                console.error('[Bluetooth Print Failed]', err);
                toast('Bluetooth printing failed: ' + (err.message || 'Write error'), 'danger');
                
                const fallback = await confirmDialog({
                    title: 'Print via Browser?',
                    message: 'Bluetooth print failed. Would you like to use browser print preview instead?'
                });
                if (fallback) {
                    return browserPrintFallback(bill, settings);
                }
                return false;
            }
        }

        // --------------------------------------------------------------------
        // B. WIFI / LAN NETWORK SOCKET METHOD
        // --------------------------------------------------------------------
        if (method === 'wifi') {
            try {
                toast('Dispatching receipt to network printer...', 'info', 2000);

                const response = await api('api/print_network.php', {
                    method: 'POST',
                    body: { bill_id: bill.id }
                });

                if (response && response.success) {
                    toast('Receipt printed on network printer!', 'success');
                    return true;
                } else {
                    throw new Error(response?.message || 'Network print failed');
                }
            } catch (err) {
                console.error('[WiFi Print Failed]', err);
                toast('Network printer error: ' + (err.message || 'Connection failed'), 'danger');

                const fallback = await confirmDialog({
                    title: 'Use Browser Print?',
                    message: 'Could not connect to the network printer. Would you like to use browser print preview instead?'
                });
                if (fallback) {
                    return browserPrintFallback(bill, settings);
                }
                return false;
            }
        }

        // --------------------------------------------------------------------
        // C. BROWSER THERMAL PRINT PREVIEW (Default Fallback)
        // --------------------------------------------------------------------
        return browserPrintFallback(bill, settings);
    }

    /**
     * Browser Thermal Print Fallback using window.print() and print.css
     */
    function browserPrintFallback(bill, settings = {}) {
        try {
            let receiptContainer = document.getElementById('printableThermalReceipt');
            let tempWrapper = null;

            if (!receiptContainer && typeof renderReceiptHTML === 'function') {
                tempWrapper = document.createElement('div');
                tempWrapper.id = 'tempPrintWrapper';
                tempWrapper.innerHTML = renderReceiptHTML(bill, settings);
                document.body.appendChild(tempWrapper);
                receiptContainer = document.getElementById('printableThermalReceipt');
            }

            window.print();

            if (tempWrapper) {
                setTimeout(() => tempWrapper.remove(), 1200);
            }
            return true;
        } catch (err) {
            console.error('[Browser Print Error]', err);
            window.print();
            return false;
        }
    }

    /**
     * Perform a Test Print on the currently configured printing method
     */
    async function testPrint() {
        const method = getPrintMethod();
        const sampleBill = {
            id: 0,
            bill_no: 'TEST-0001',
            date_formatted: new Date().toLocaleString(),
            customer_name: 'Test Customer',
            customer_phone: '9876543210',
            cashier_name: window.APP?.userName || 'Admin',
            subtotal: 150.00,
            discount_amount: 15.00,
            total: 135.00,
            payment_mode: 'cash',
            advance_paid: 0,
            balance_due: 0,
            items: [
                { item_name: 'Espresso Single', quantity: 1, price: 100.00, line_total: 100.00 },
                { item_name: 'Butter Croissant', quantity: 1, price: 50.00, line_total: 50.00 }
            ]
        };

        if (method === 'wifi') {
            toast('Sending test print to network printer...', 'info');
            try {
                const res = await api('api/print_network.php', {
                    method: 'POST',
                    body: { test_print: true }
                });
                toast(res?.message || 'Test receipt printed on network printer!', 'success');
            } catch (err) {
                toast(err.message || 'Test print failed on network printer.', 'danger');
            }
            return;
        }

        return print(sampleBill, { paper_width: window.APP?.paperWidth || '58' });
    }

    // ========================================================================
    // 4. UI: Printer Modal & Status Integration
    // ========================================================================

    /**
     * Dynamically inject the Printer Configuration Modal into the DOM if absent
     */
    function ensureModalInDOM() {
        if (document.getElementById('printerConfigModal')) return;

        const modalHTML = `
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
                                        <div class="text-muted" style="font-size: 0.68rem;">window.print</div>
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

                            <!-- Panel 2: WiFi Setup Note -->
                            <div id="printerPanelWifi" class="card bg-slate-50 border rounded-3 p-3 mb-3 d-none">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="small fw-semibold text-dark">Network Thermal Printer</span>
                                    <span class="badge bg-info-subtle text-info small rounded-pill">LAN Socket</span>
                                </div>
                                <div class="small text-muted mb-2" style="font-size: 0.75rem;">
                                    Prints over local WiFi to port 9100. Printer IP can be configured in <strong>Settings</strong>.
                                </div>
                                <a href="settings.php" class="btn btn-light btn-sm border rounded-3 small py-1 px-2 d-inline-flex align-items-center gap-1 text-decoration-none">
                                    <i class="bi bi-gear"></i> Configure IP & Port in Settings
                                </a>
                            </div>

                            <!-- Panel 3: Browser Fallback Note -->
                            <div id="printerPanelBrowser" class="card bg-slate-50 border rounded-3 p-3 mb-3">
                                <div class="small text-muted" style="font-size: 0.78rem;">
                                    Uses your operating system's standard print dialog. Supports any installed USB, network, or virtual PDF printer.
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
        `;

        document.body.insertAdjacentHTML('beforeend', modalHTML);
        bindModalEvents();
    }

    function bindModalEvents() {
        const modalEl = document.getElementById('printerConfigModal');
        if (!modalEl) return;

        // Method card click listeners
        modalEl.querySelectorAll('.printer-method-option').forEach(card => {
            card.addEventListener('click', () => {
                const method = card.getAttribute('data-method');
                setPrintMethod(method);
            });
        });

        // BLE Connect
        const btnBleConnect = document.getElementById('btnBleConnect');
        if (btnBleConnect) {
            btnBleConnect.addEventListener('click', async () => {
                try {
                    await connectBluetooth();
                } catch (e) {
                    // Handled inside connectBluetooth
                }
            });
        }

        // BLE Disconnect
        const btnBleDisconnect = document.getElementById('btnBleDisconnect');
        if (btnBleDisconnect) {
            btnBleDisconnect.addEventListener('click', disconnectBluetooth);
        }

        // Test Print
        const btnTest = document.getElementById('btnModalTestPrint');
        if (btnTest) {
            btnTest.addEventListener('click', testPrint);
        }
    }

    /**
     * Update UI status cards, badges, and buttons based on active state
     */
    function updateUIStatus() {
        const method = getPrintMethod();
        const isConnected = !!(bleDevice && bleDevice.gatt.connected && bleCharacteristic);
        const deviceName = bleDevice?.name || localStorage.getItem(STORAGE_KEY_BLE_NAME) || 'Thermal Printer';

        // 1. Highlight active method option card
        document.querySelectorAll('.printer-method-option').forEach(card => {
            const isMatch = card.getAttribute('data-method') === method;
            card.classList.toggle('border-primary', isMatch);
            card.classList.toggle('bg-primary-subtle', isMatch);
        });

        // 2. Toggle sub-panels in modal
        const panelBt = document.getElementById('printerPanelBluetooth');
        const panelWifi = document.getElementById('printerPanelWifi');
        const panelBrowser = document.getElementById('printerPanelBrowser');

        if (panelBt) panelBt.classList.toggle('d-none', method !== 'bluetooth');
        if (panelWifi) panelWifi.classList.toggle('d-none', method !== 'wifi');
        if (panelBrowser) panelBrowser.classList.toggle('d-none', method !== 'browser');

        // 3. Update BLE status badges and buttons
        const bleBadge = document.getElementById('bleStatusBadge');
        const btnBleConnect = document.getElementById('btnBleConnect');
        const btnBleDisconnect = document.getElementById('btnBleDisconnect');

        if (bleBadge) {
            if (isConnected) {
                bleBadge.className = 'badge bg-success-subtle text-success small rounded-pill';
                bleBadge.textContent = 'Connected: ' + deviceName;
            } else {
                bleBadge.className = 'badge bg-secondary-subtle text-secondary small rounded-pill';
                bleBadge.textContent = 'Disconnected';
            }
        }

        if (btnBleConnect) {
            btnBleConnect.classList.toggle('d-none', isConnected);
        }
        if (btnBleDisconnect) {
            btnBleDisconnect.classList.toggle('d-none', !isConnected);
        }

        // 4. Update any navbar / global status indicators (e.g. #printerStatusBadge)
        const globalBadges = document.querySelectorAll('.printer-status-badge');
        globalBadges.forEach(b => {
            if (method === 'bluetooth') {
                b.textContent = isConnected ? 'BT: ' + deviceName : 'BT: Offline';
                b.className = 'badge rounded-pill printer-status-badge ' + (isConnected ? 'bg-success' : 'bg-warning text-dark');
            } else if (method === 'wifi') {
                b.textContent = 'WiFi Printer';
                b.className = 'badge rounded-pill bg-info printer-status-badge';
            } else {
                b.textContent = 'Browser Print';
                b.className = 'badge rounded-pill bg-secondary printer-status-badge';
            }
        });
    }

    /**
     * Open the Printer Configuration Modal
     */
    function openModal() {
        ensureModalInDOM();
        updateUIStatus();
        const modalEl = document.getElementById('printerConfigModal');
        if (modalEl) {
            const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
            bsModal.show();
        }
    }

    /**
     * Get detailed status object for debugging or external calls
     */
    function getStatus() {
        return {
            method: getPrintMethod(),
            bluetooth: {
                supported: isBluetoothSupported(),
                connected: !!(bleDevice && bleDevice.gatt.connected && bleCharacteristic),
                deviceName: bleDevice?.name || localStorage.getItem(STORAGE_KEY_BLE_NAME) || null
            }
        };
    }

    // ========================================================================
    // 5. Expose Public API on window.PRINTER
    // ========================================================================
    window.PRINTER = {
        print,
        connectBluetooth,
        disconnect: disconnectBluetooth,
        getStatus,
        getPrintMethod,
        setPrintMethod,
        testPrint,
        openModal
    };

    // Auto-initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', () => {
        ensureModalInDOM();
        updateUIStatus();
        tryAutoReconnect();

        // Delegate listener for any button with class .btn-open-printer-modal or id #btnOpenPrinterModal
        document.addEventListener('click', (e) => {
            if (e.target.closest('#btnOpenPrinterModal') || e.target.closest('.btn-open-printer-modal')) {
                openModal();
            }
        });
    });

})();
