/**
 * Sales History Module JavaScript
 * Handles filtering, server-side pagination, expandable itemized orders, and thermal reprint.
 */

document.addEventListener('DOMContentLoaded', () => {
    // DOM Elements - Filters & Summary
    const searchInput = document.getElementById('historySearchInput');
    const clearSearchBtn = document.getElementById('clearHistorySearchBtn');
    const fromDateInput = document.getElementById('historyFromDate');
    const toDateInput = document.getElementById('historyToDate');
    const quickChips = document.querySelectorAll('.quick-date-chip');

    const totalCountBadge = document.getElementById('historyTotalCountBadge');
    const summaryTotalRevenue = document.getElementById('summaryTotalRevenue');
    const summaryTotalUpi = document.getElementById('summaryTotalUpi');
    const summaryTotalCash = document.getElementById('summaryTotalCash');

    // Content Containers & States
    const loadingState = document.getElementById('historyLoading');
    const emptyState = document.getElementById('historyEmpty');
    const desktopCard = document.getElementById('historyDesktopCard');
    const desktopTableBody = document.getElementById('historyDesktopTableBody');
    const mobileList = document.getElementById('historyMobileList');

    // Pagination Elements
    const paginationWrap = document.getElementById('historyPaginationWrap');
    const paginationInfo = document.getElementById('historyPaginationInfo');
    const btnPrev = document.getElementById('btnHistoryPrev');
    const btnNext = document.getElementById('btnHistoryNext');
    const pageNumberText = document.getElementById('historyPageNumber');
    const btnLoadMore = document.getElementById('btnHistoryLoadMore');

    // Receipt Modal Elements
    const receiptModalEl = document.getElementById('historyReceiptModal');
    const receiptModal = new bootstrap.Modal(receiptModalEl);
    const receiptContainer = document.getElementById('historyReceiptContainer');
    const btnPrintReceipt = document.getElementById('btnPrintHistoryReceipt');

    // State
    let currentPage = 1;
    const perPage = 15;
    let totalPages = 1;
    let totalCount = 0;
    let billsList = [];
    let appSettings = {};
    let activeReceiptBill = null;

    // Helper to format date as YYYY-MM-DD in local time
    function toISODate(d) {
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    // Initialize with Today filter
    const today = new Date();
    fromDateInput.value = toISODate(today);
    toDateInput.value = toISODate(today);

    // =========================================================================
    // 1. Fetch Sales History from API
    // =========================================================================
    async function loadHistory(page = 1, append = false) {
        currentPage = page;
        showLoading(!append);

        const params = new URLSearchParams({
            page: currentPage,
            per_page: perPage,
            search: (searchInput.value || '').trim(),
            from: fromDateInput.value || '',
            to: toDateInput.value || ''
        });

        try {
            const res = await api(`api/history.php?${params.toString()}`);
            if (res && res.success) {
                const data = res.data;
                appSettings = data.settings || {};

                if (append) {
                    billsList = [...billsList, ...(data.bills || [])];
                } else {
                    billsList = data.bills || [];
                }

                totalCount = data.pagination.total_count || 0;
                totalPages = data.pagination.total_pages || 1;

                updateSummaryBadges(data.summary || {});
                renderBills(append);
                updatePaginationControls();
            }
        } catch (err) {
            toast(err.message || 'Failed to load sales history.', 'danger');
        } finally {
            showLoading(false);
        }
    }

    // =========================================================================
    // 2. Summary Badges & Metrics
    // =========================================================================
    function updateSummaryBadges(summary) {
        totalCountBadge.textContent = summary.total_count ?? totalCount;
        summaryTotalRevenue.textContent = formatINR(summary.total_amount ?? 0);
        summaryTotalUpi.textContent = formatINR(summary.total_upi ?? 0);
        summaryTotalCash.textContent = formatINR(summary.total_cash ?? 0);
    }

    // =========================================================================
    // 3. Render Desktop Table and Mobile Cards
    // =========================================================================
    function renderBills(append = false) {
        if (billsList.length === 0) {
            emptyState.classList.remove('d-none');
            desktopCard?.classList.add('d-none');
            mobileList.innerHTML = '';
            paginationWrap.classList.add('d-none');
            return;
        }

        emptyState.classList.add('d-none');
        desktopCard?.classList.remove('d-none');
        paginationWrap.classList.remove('d-none');

        // Render Desktop Table Rows
        desktopTableBody.innerHTML = billsList.map(bill => renderDesktopRow(bill)).join('');

        // Render Mobile Cards (Replace or Append)
        const mobileCardsHtml = billsList.map(bill => renderMobileCard(bill)).join('');
        mobileList.innerHTML = mobileCardsHtml;
    }

    function renderDesktopRow(bill) {
        const dateStr = formatReceiptDate(bill.completed_at || bill.created_at);
        const customerName = bill.customer_name || 'Walk-in Customer';
        const phoneLink = bill.customer_phone ? 
            `<a href="tel:${escapeHTML(bill.customer_phone)}" class="text-muted small text-decoration-none d-block"><i class="bi bi-telephone"></i> ${escapeHTML(bill.customer_phone)}</a>` : '';

        // Payment mode badge with split breakdown
        let modeBadge = '';
        if (bill.payment_mode === 'mixed') {
            modeBadge = `
                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle rounded-pill">Mixed</span>
                <div class="small text-muted mt-1" style="font-size: 0.72rem;">
                    UPI: ${formatINR(bill.upi_amount)}<br>Cash: ${formatINR(bill.cash_amount)}
                </div>
            `;
        } else if (bill.payment_mode === 'upi') {
            modeBadge = `<span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill">UPI</span>`;
        } else {
            modeBadge = `<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill">Cash</span>`;
        }

        return `
            <tr class="bill-row" id="bill-row-${bill.id}">
                <td class="ps-3 fw-bold text-dark font-monospace">${escapeHTML(bill.bill_no)}</td>
                <td class="small text-muted">${dateStr}</td>
                <td>
                    <div class="fw-semibold text-dark small">${escapeHTML(customerName)}</div>
                    ${phoneLink}
                </td>
                <td>${modeBadge}</td>
                <td class="small text-secondary">${escapeHTML(bill.cashier_name || 'Cashier')}</td>
                <td class="text-end fw-bold text-dark amount-text fs-6">${formatINR(bill.total)}</td>
                <td class="text-center pe-3">
                    <div class="btn-group gap-1">
                        <button type="button" class="btn btn-light btn-sm rounded-2 text-primary p-1 btn-view-receipt" data-id="${bill.id}" title="View Receipt">
                            <i class="bi bi-receipt fs-6"></i>
                        </button>
                        <button type="button" class="btn btn-light btn-sm rounded-2 text-secondary p-1 btn-reprint-receipt" data-id="${bill.id}" title="Reprint Thermal Receipt">
                            <i class="bi bi-printer fs-6"></i>
                        </button>
                        <button type="button" class="btn btn-light btn-sm rounded-2 text-muted p-1 btn-toggle-expand" data-id="${bill.id}" title="Show Details">
                            <i class="bi bi-chevron-down fs-6" id="expand-icon-${bill.id}"></i>
                        </button>
                    </div>
                </td>
            </tr>
            <!-- Expandable Items and Audit Detail Row -->
            <tr class="d-none bg-slate-50 border-bottom" id="expand-row-${bill.id}">
                <td colspan="7" class="p-3">
                    <div class="row g-3">
                        <div class="col-12 col-md-7">
                            <h6 class="fw-bold small text-secondary text-uppercase mb-2">Itemized Breakdown</h6>
                            <table class="table table-sm table-bordered bg-white small mb-0 rounded-2 overflow-hidden">
                                <thead class="table-light">
                                    <tr>
                                        <th>Item</th>
                                        <th class="text-center" style="width: 50px;">Qty</th>
                                        <th class="text-end" style="width: 75px;">Rate</th>
                                        <th class="text-end" style="width: 85px;">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${(bill.items || []).map(item => `
                                        <tr>
                                            <td>${escapeHTML(item.item_name)}${item.is_custom ? ' *' : ''}</td>
                                            <td class="text-center">${item.quantity}</td>
                                            <td class="text-end">${formatINR(item.price)}</td>
                                            <td class="text-end fw-semibold">${formatINR(item.line_total)}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>

                        <div class="col-12 col-md-5">
                            <h6 class="fw-bold small text-secondary text-uppercase mb-2">Payment Timeline & Audit</h6>
                            <div class="list-group list-group-flush bg-white rounded-2 border small">
                                ${(bill.payments || []).map(p => `
                                    <div class="list-group-item px-3 py-2 d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="badge ${p.type === 'advance' ? 'bg-warning-subtle text-warning-emphasis' : (p.type === 'balance' ? 'bg-info-subtle text-info-emphasis' : 'bg-success-subtle text-success')} text-uppercase" style="font-size: 0.65rem;">
                                                ${escapeHTML(p.type)}
                                            </span>
                                            <div class="text-muted" style="font-size: 0.72rem;">${formatReceiptDate(p.created_at)}</div>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-bold text-dark amount-text">${formatINR(p.amount)}</div>
                                            <div class="text-muted" style="font-size: 0.7rem;">
                                                ${p.upi_amount > 0 ? 'UPI: ' + formatINR(p.upi_amount) : ''}
                                                ${p.upi_amount > 0 && p.cash_amount > 0 ? ' &bull; ' : ''}
                                                ${p.cash_amount > 0 ? 'Cash: ' + formatINR(p.cash_amount) : ''}
                                            </div>
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
        `;
    }

    function renderMobileCard(bill) {
        const dateStr = formatReceiptDate(bill.completed_at || bill.created_at);
        const customerName = bill.customer_name || 'Walk-in Customer';

        let modeBadge = '';
        if (bill.payment_mode === 'mixed') {
            modeBadge = `<span class="badge bg-info-subtle text-info-emphasis">Mixed (UPI: ${formatINR(bill.upi_amount)}, Cash: ${formatINR(bill.cash_amount)})</span>`;
        } else if (bill.payment_mode === 'upi') {
            modeBadge = `<span class="badge bg-success-subtle text-success">UPI</span>`;
        } else {
            modeBadge = `<span class="badge bg-secondary-subtle text-secondary">Cash</span>`;
        }

        return `
            <div class="card border rounded-4 p-3 mb-2 shadow-xs bg-white" id="mobile-card-${bill.id}">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <div>
                        <span class="fw-bold text-dark font-monospace">${escapeHTML(bill.bill_no)}</span>
                        <div class="small text-muted" style="font-size: 0.75rem;">${dateStr}</div>
                    </div>
                    <div class="text-end">
                        <span class="fs-5 fw-bold text-primary amount-text">${formatINR(bill.total)}</span>
                    </div>
                </div>

                <div class="my-1">
                    <div class="fw-semibold text-dark small">${escapeHTML(customerName)}</div>
                    ${bill.customer_phone ? `<a href="tel:${escapeHTML(bill.customer_phone)}" class="text-muted small text-decoration-none"><i class="bi bi-telephone"></i> ${escapeHTML(bill.customer_phone)}</a>` : ''}
                </div>

                <div class="d-flex justify-content-between align-items-center mt-1 pt-1 border-top" style="font-size: 0.78rem;">
                    <div>${modeBadge}</div>
                    <div class="text-muted">By: ${escapeHTML(bill.cashier_name || 'Cashier')}</div>
                </div>

                <!-- Collapsible Mobile Items -->
                <div class="collapse mt-2 pt-2 border-top" id="mobile-collapse-${bill.id}">
                    <div class="small fw-bold text-secondary mb-1">Order Items:</div>
                    <ul class="list-unstyled mb-2 small">
                        ${(bill.items || []).map(i => `
                            <li class="d-flex justify-content-between py-1 border-bottom border-light">
                                <span>${escapeHTML(i.item_name)} &times; ${i.quantity}</span>
                                <span class="fw-semibold">${formatINR(i.line_total)}</span>
                            </li>
                        `).join('')}
                    </ul>

                    <div class="small fw-bold text-secondary mb-1">Payments:</div>
                    <div class="small mb-2">
                        ${(bill.payments || []).map(p => `
                            <div class="d-flex justify-content-between text-muted py-0">
                                <span class="text-capitalize">${escapeHTML(p.type)}</span>
                                <span class="fw-medium">${formatINR(p.amount)}</span>
                            </div>
                        `).join('')}
                    </div>
                </div>

                <div class="d-flex gap-2 mt-2 pt-1 border-top">
                    <button type="button" class="btn btn-light btn-sm rounded-3 py-1 flex-grow-1 text-primary btn-view-receipt" data-id="${bill.id}">
                        <i class="bi bi-receipt me-1"></i> View
                    </button>
                    <button type="button" class="btn btn-light btn-sm rounded-3 py-1 flex-grow-1 text-secondary btn-reprint-receipt" data-id="${bill.id}">
                        <i class="bi bi-printer me-1"></i> Reprint
                    </button>
                    <button type="button" class="btn btn-light btn-sm rounded-3 px-2 text-muted" data-bs-toggle="collapse" data-bs-target="#mobile-collapse-${bill.id}">
                        <i class="bi bi-chevron-down"></i>
                    </button>
                </div>
            </div>
        `;
    }

    // =========================================================================
    // 4. Pagination Controls Handlers
    // =========================================================================
    function updatePaginationControls() {
        const start = totalCount === 0 ? 0 : (currentPage - 1) * perPage + 1;
        const end = Math.min(currentPage * perPage, totalCount);

        paginationInfo.textContent = `Showing ${start} to ${end} of ${totalCount} orders`;
        pageNumberText.textContent = `Page ${currentPage} of ${totalPages}`;

        btnPrev.disabled = (currentPage <= 1);
        btnNext.disabled = (currentPage >= totalPages);

        if (currentPage < totalPages) {
            btnLoadMore.classList.remove('d-none');
        } else {
            btnLoadMore.classList.add('d-none');
        }
    }

    btnPrev.addEventListener('click', () => {
        if (currentPage > 1) {
            loadHistory(currentPage - 1);
        }
    });

    btnNext.addEventListener('click', () => {
        if (currentPage < totalPages) {
            loadHistory(currentPage + 1);
        }
    });

    btnLoadMore.addEventListener('click', () => {
        if (currentPage < totalPages) {
            loadHistory(currentPage + 1, true);
        }
    });

    // =========================================================================
    // 5. Expandable Rows, View & Reprint Event Handlers
    // =========================================================================
    document.addEventListener('click', (e) => {
        // Toggle Desktop Row Expand
        const expandBtn = e.target.closest('.btn-toggle-expand');
        if (expandBtn) {
            const billId = Number(expandBtn.dataset.id);
            const expandRow = document.getElementById(`expand-row-${billId}`);
            const icon = document.getElementById(`expand-icon-${billId}`);

            if (expandRow) {
                const isHidden = expandRow.classList.contains('d-none');
                expandRow.classList.toggle('d-none', !isHidden);
                if (icon) {
                    icon.className = isHidden ? 'bi bi-chevron-up fs-6 text-primary' : 'bi bi-chevron-down fs-6';
                }
            }
            return;
        }

        // View Receipt Modal
        const viewBtn = e.target.closest('.btn-view-receipt');
        if (viewBtn) {
            const billId = Number(viewBtn.dataset.id);
            const bill = billsList.find(b => b.id === billId);
            if (bill) {
                openReceiptModal(bill);
            }
            return;
        }

        // Direct Reprint
        const reprintBtn = e.target.closest('.btn-reprint-receipt');
        if (reprintBtn) {
            const billId = Number(reprintBtn.dataset.id);
            const bill = billsList.find(b => b.id === billId);
            if (bill) {
                if (window.PRINTER && typeof window.PRINTER.print === 'function') {
                    window.PRINTER.print(bill, appSettings);
                } else {
                    openReceiptModal(bill);
                    window.print();
                }
            }
            return;
        }
    });

    function openReceiptModal(bill) {
        activeReceiptBill = bill;
        receiptContainer.innerHTML = renderReceiptHTML(bill, appSettings);
        receiptModal.show();
    }

    btnPrintReceipt.addEventListener('click', () => {
        if (activeReceiptBill) {
            if (window.PRINTER && typeof window.PRINTER.print === 'function') {
                window.PRINTER.print(activeReceiptBill, appSettings);
            } else {
                window.print();
            }
        }
    });

    // =========================================================================
    // 6. Filter Event Listeners & Quick Chips
    // =========================================================================
    searchInput.addEventListener('input', debounce(() => {
        clearSearchBtn.classList.toggle('d-none', searchInput.value.trim().length === 0);
        loadHistory(1);
    }, 250));

    clearSearchBtn.addEventListener('click', () => {
        searchInput.value = '';
        clearSearchBtn.classList.add('d-none');
        searchInput.focus();
        loadHistory(1);
    });

    fromDateInput.addEventListener('change', () => loadHistory(1));
    toDateInput.addEventListener('change', () => loadHistory(1));

    quickChips.forEach(chip => {
        chip.addEventListener('click', () => {
            quickChips.forEach(c => c.classList.remove('active'));
            chip.classList.add('active');

            const range = chip.dataset.range;
            const now = new Date();

            if (range === 'today') {
                fromDateInput.value = toISODate(now);
                toDateInput.value = toISODate(now);
            } else if (range === 'yesterday') {
                const yest = new Date();
                yest.setDate(yest.getDate() - 1);
                fromDateInput.value = toISODate(yest);
                toDateInput.value = toISODate(yest);
            } else if (range === 'week') {
                const weekAgo = new Date();
                weekAgo.setDate(weekAgo.getDate() - 6);
                fromDateInput.value = toISODate(weekAgo);
                toDateInput.value = toISODate(now);
            } else if (range === 'all') {
                fromDateInput.value = '';
                toDateInput.value = '';
            }

            loadHistory(1);
        });
    });

    function showLoading(isLoading) {
        if (isLoading) {
            loadingState.classList.remove('d-none');
            desktopCard?.classList.add('d-none');
            mobileList.classList.add('d-none');
            emptyState.classList.add('d-none');
        } else {
            loadingState.classList.add('d-none');
            mobileList.classList.remove('d-none');
        }
    }

    // Initial Load
    loadHistory(1);
});
