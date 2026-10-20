/**
 * Store & Hardware Settings Controller (Admin Only)
 * Handles form validation, saving settings to api/settings.php, and interactive hardware feedback.
 */

(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('settingsForm');
        const btnSave = document.getElementById('btnSaveSettings');
        const btnSaveText = document.getElementById('btnSaveSettingsText');
        const paperWidthCards = document.querySelectorAll('.paper-width-card');

        // Handle Paper Width Radio Card Toggles
        paperWidthCards.forEach(card => {
            const radio = card.querySelector('input[type="radio"]');
            card.addEventListener('click', () => {
                paperWidthCards.forEach(c => {
                    c.classList.remove('border-primary', 'bg-primary-subtle');
                    c.classList.add('bg-white');
                });
                card.classList.add('border-primary', 'bg-primary-subtle');
                card.classList.remove('bg-white');
                radio.checked = true;
            });
        });

        // Form Submit Handler
        if (form) {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();

                // Validate required fields
                const businessName = document.getElementById('settingBusinessName')?.value.trim();
                const billPrefix = document.getElementById('settingBillPrefix')?.value.trim();
                const printerIp = document.getElementById('settingPrinterIp')?.value.trim();
                const printerPort = document.getElementById('settingPrinterPort')?.value.trim();

                if (!businessName) {
                    toast('Business name is required.', 'warning');
                    document.getElementById('settingBusinessName')?.focus();
                    return;
                }

                if (!billPrefix) {
                    toast('Bill number prefix is required.', 'warning');
                    document.getElementById('settingBillPrefix')?.focus();
                    return;
                }

                // IP Format validation if supplied
                if (printerIp) {
                    const ipRegex = /^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$/;
                    if (!ipRegex.test(printerIp)) {
                        toast('Please enter a valid IP address (e.g. 192.168.1.100).', 'warning');
                        document.getElementById('settingPrinterIp')?.focus();
                        return;
                    }
                }

                if (printerPort) {
                    const portNum = parseInt(printerPort, 10);
                    if (isNaN(portNum) || portNum < 1 || portNum > 65535) {
                        toast('Printer port must be an integer between 1 and 65535.', 'warning');
                        document.getElementById('settingPrinterPort')?.focus();
                        return;
                    }
                }

                // Prepare Payload
                const formData = new FormData(form);
                const payload = {};
                formData.forEach((value, key) => {
                    payload[key] = typeof value === 'string' ? value.trim() : value;
                });

                // Set loading button state
                btnSave.disabled = true;
                const origText = btnSaveText.textContent;
                btnSaveText.textContent = 'Saving Settings...';
                btnSave.querySelector('i').className = 'spinner-border spinner-border-sm me-1';

                try {
                    const res = await api('api/settings.php?action=update', {
                        method: 'POST',
                        body: payload
                    });

                    if (res && res.success) {
                        toast('Settings updated successfully!', 'success');

                        // Update global APP object if values changed
                        if (window.APP) {
                            window.APP.businessName = payload.business_name;
                            window.APP.businessAddress = payload.business_address;
                            window.APP.businessPhone = payload.business_phone;
                            window.APP.businessGstin = payload.business_gstin;
                            window.APP.receiptFooter = payload.receipt_footer;
                            window.APP.paperWidth = payload.paper_width;
                        }
                    } else {
                        throw new Error(res?.message || 'Failed to update settings.');
                    }
                } catch (err) {
                    console.error('Save Settings Error:', err);
                    toast(err.message || 'Error updating settings. Please try again.', 'danger');
                } finally {
                    btnSave.disabled = false;
                    btnSaveText.textContent = origText;
                    btnSave.querySelector('i').className = 'bi bi-check2';
                }
            });
        }
    });
})();
