/**
 * Thermal Receipt Rendering & Printing Module
 * Supports 58mm and 80mm paper widths, thermal print CSS formatting, and fallback print.
 */

// Global PRINTER namespace (Part 9 will integrate raw network ESC/POS printer)
window.PRINTER = window.PRINTER || {
    print: function (bill, settings) {
        // Fallback to native browser print dialog
        window.print();
    }
};

/**
 * Format a raw date string or timestamp into Indian Standard Time display
 */
function formatReceiptDate(dateStr) {
    if (!dateStr) return '';
    try {
        const d = new Date(dateStr.replace(' ', 'T'));
        return d.toLocaleString('en-IN', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: true
        });
    } catch (e) {
        return dateStr;
    }
}

/**
 * Generate standard HTML for thermal receipt
 *
 * @param {Object} bill - Bill record including items and payments
 * @param {Object} settings - Store settings (business_name, address, gstin, phone, paper_width, footer_message)
 * @returns {string} - Styled HTML string
 */
function renderReceiptHTML(bill, settings = {}) {
    const widthClass = (settings.paper_width === '80') ? 'receipt-80mm' : 'receipt-58mm';
    const businessName = settings.business_name || settings.businessName || window.APP?.businessName || 'QuickPOS Mart';
    const address = settings.business_address || settings.address || settings.businessAddress || window.APP?.businessAddress || '';
    const phone = settings.business_phone || settings.phone || settings.businessPhone || window.APP?.businessPhone || '';
    const gstin = settings.business_gstin || settings.gstin || settings.businessGstin || window.APP?.businessGstin || '';
    const footerMsg = settings.receipt_footer || settings.footer_message || settings.receiptFooter || window.APP?.receiptFooter || 'Thank you for your visit! Please visit again.';

    const items = bill.items || [];
    const hasDiscount = (Number(bill.discount_amount) > 0);
    const isAdvance = (bill.status === 'pending' || Number(bill.balance_due) > 0);

    // Mixed split details
    let paymentModeText = (bill.payment_mode || 'cash').toUpperCase();
    if (bill.payment_mode === 'mixed') {
        paymentModeText += ` (UPI: ₹${Number(bill.upi_amount).toFixed(2)}, Cash: ₹${Number(bill.cash_amount).toFixed(2)})`;
    }

    return `
        <div class="pos-receipt ${widthClass} text-dark" id="printableThermalReceipt">
            <!-- Header -->
            <div class="receipt-header text-center mb-2">
                <div class="receipt-title fw-bold text-uppercase fs-5 mb-1">${escapeHTML(businessName)}</div>
                ${address ? `<div class="receipt-sub">${escapeHTML(address)}</div>` : ''}
                ${phone ? `<div class="receipt-sub">Ph: ${escapeHTML(phone)}</div>` : ''}
                ${gstin ? `<div class="receipt-sub fw-bold">GSTIN: ${escapeHTML(gstin)}</div>` : ''}
            </div>

            <div class="receipt-divider my-2"></div>

            <!-- Metadata Info -->
            <div class="receipt-meta my-2">
                <div class="d-flex justify-content-between">
                    <span><strong>Bill:</strong> ${escapeHTML(bill.bill_no)}</span>
                    <span>${formatReceiptDate(bill.created_at)}</span>
                </div>
                ${bill.cashier_name ? `<div><span class="text-muted">Cashier:</span> ${escapeHTML(bill.cashier_name)}</div>` : ''}
                
                <!-- Customer info lines rendered ONLY if provided -->
                ${bill.customer_name ? `<div><strong>Customer:</strong> ${escapeHTML(bill.customer_name)}</div>` : ''}
                ${bill.customer_phone ? `<div><strong>Phone:</strong> ${escapeHTML(bill.customer_phone)}</div>` : ''}
            </div>

            <div class="receipt-divider my-2"></div>

            <!-- Items Table -->
            <table class="receipt-table w-100 my-2">
                <thead>
                    <tr class="receipt-table-header">
                        <th class="text-start pb-1">Item</th>
                        <th class="text-center pb-1" style="width: 32px;">Qty</th>
                        <th class="text-end pb-1" style="width: 58px;">Rate</th>
                        <th class="text-end pb-1" style="width: 62px;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    ${items.map(item => `
                        <tr class="receipt-item-row">
                            <td class="text-start py-1 text-break">
                                ${escapeHTML(item.item_name)}
                                ${item.is_custom ? '<span class="small font-monospace"> *</span>' : ''}
                            </td>
                            <td class="text-center py-1 align-top">${Number(item.quantity)}</td>
                            <td class="text-end py-1 align-top">${Number(item.price).toFixed(2)}</td>
                            <td class="text-end py-1 align-top fw-semibold">${Number(item.line_total).toFixed(2)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>

            <div class="receipt-divider my-2"></div>

            <!-- Summary & Totals -->
            <div class="receipt-totals">
                <div class="d-flex justify-content-between py-0">
                    <span>Subtotal</span>
                    <span>₹${Number(bill.subtotal).toFixed(2)}</span>
                </div>

                ${hasDiscount ? `
                    <div class="d-flex justify-content-between py-0 receipt-discount-line text-danger">
                        <span>Discount (${bill.discount_type === 'percent' ? Number(bill.discount_value) + '%' : 'Flat'})</span>
                        <span>-₹${Number(bill.discount_amount).toFixed(2)}</span>
                    </div>
                ` : ''}

                <div class="receipt-grand-total d-flex justify-content-between align-items-center py-1 my-1">
                    <span>GRAND TOTAL</span>
                    <span>₹${Number(bill.total).toFixed(2)}</span>
                </div>

                ${isAdvance ? `
                    <div class="d-flex justify-content-between py-0 receipt-advance-line fw-semibold">
                        <span>Advance Paid</span>
                        <span>₹${Number(bill.advance_paid).toFixed(2)}</span>
                    </div>
                    <div class="d-flex justify-content-between py-0 receipt-balance-line fw-bold">
                        <span>BALANCE DUE</span>
                        <span>₹${Number(bill.balance_due).toFixed(2)}</span>
                    </div>
                ` : ''}

                <div class="d-flex justify-content-between py-1 mt-1 receipt-payment-line">
                    <span class="text-muted">Payment Mode</span>
                    <span class="fw-bold text-uppercase">${escapeHTML(paymentModeText)}</span>
                </div>
            </div>

            <div class="receipt-divider my-2"></div>

            <!-- Footer Greeting -->
            <div class="receipt-footer text-center my-2">
                <div class="mb-1 receipt-footer-msg">${escapeHTML(footerMsg)}</div>
                <div class="receipt-footer-sub text-muted">Software Powered by QuickPOS</div>
            </div>
        </div>
    `;
}
