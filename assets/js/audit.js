/**
 * Audit Dashboard & Reports Controller
 * Powered by vanilla JS, Chart.js for data visualization, and SheetJS (XLSX) for Excel exports.
 */

(function () {
    'use strict';

    // Application state for Audit module
    const state = {
        range: 'daily', // 'daily' | 'weekly' | 'monthly' | 'custom'
        from: new Date().toISOString().split('T')[0],
        to: new Date().toISOString().split('T')[0],
        summaryData: null,
        topProductsData: null,
        pendingCustomersData: null,
        activeChartDataset: 'all' // 'all' | 'sales' | 'cash' | 'upi'
    };

    let auditChartInstance = null;

    // DOM Elements Cache
    const elements = {
        rangeTabs: document.getElementById('auditRangeTabs'),
        customRangeContainer: document.getElementById('customDateRangeContainer'),
        customRangeForm: document.getElementById('customDateRangeForm'),
        fromDateInput: document.getElementById('auditFromDate'),
        toDateInput: document.getElementById('auditToDate'),
        rangeBadge: document.getElementById('auditRangeBadge'),
        btnRefresh: document.getElementById('btnRefreshAudit'),
        btnExportExcel: document.getElementById('btnExportExcel'),
        btnExportExcelText: document.getElementById('btnExportExcelText'),

        // Summary Metric Cards
        statTotalSales: document.getElementById('statTotalSales'),
        statTotalCollected: document.getElementById('statTotalCollected'),
        statCashCollected: document.getElementById('statCashCollected'),
        statUpiCollected: document.getElementById('statUpiCollected'),
        statTotalBills: document.getElementById('statTotalBills'),
        statCompletedBills: document.getElementById('statCompletedBills'),
        statPendingBills: document.getElementById('statPendingBills'),
        statTotalDiscount: document.getElementById('statTotalDiscount'),
        statPendingBalance: document.getElementById('statPendingBalance'),
        statOverallPending: document.getElementById('statOverallPending'),

        // Chart Elements
        chartCanvas: document.getElementById('salesChart'),
        chartSkeleton: document.getElementById('chartSkeleton'),
        chartSubtitle: document.getElementById('chartSubtitle'),
        chartDatasetFilter: document.getElementById('chartDatasetFilter'),

        // Top Products Elements
        topProductsSkeleton: document.getElementById('topProductsSkeleton'),
        topProductsEmpty: document.getElementById('topProductsEmpty'),
        topProductsTable: document.getElementById('topProductsTable'),
        topProductsTbody: document.getElementById('topProductsTbody'),

        // Pending Customers Elements
        pendingCustomersBadge: document.getElementById('pendingCustomersBadge'),
        pendingCustomersSkeleton: document.getElementById('pendingCustomersSkeleton'),
        pendingCustomersEmpty: document.getElementById('pendingCustomersEmpty'),
        pendingCustomersTable: document.getElementById('pendingCustomersTable'),
        pendingCustomersTbody: document.getElementById('pendingCustomersTbody')
    };

    // ========================================================================
    // 1. Initialization & Event Listeners
    // ========================================================================
    document.addEventListener('DOMContentLoaded', () => {
        // Initialize tooltips
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.forEach(el => new bootstrap.Tooltip(el));

        initEventListeners();
        loadAllAuditData();
    });

    function initEventListeners() {
        // Range switcher tabs
        if (elements.rangeTabs) {
            elements.rangeTabs.addEventListener('click', (e) => {
                const btn = e.target.closest('button[data-range]');
                if (!btn) return;

                const selectedRange = btn.getAttribute('data-range');
                if (selectedRange === state.range && selectedRange !== 'custom') return;

                // Update tab styles
                elements.rangeTabs.querySelectorAll('button').forEach(b => {
                    b.classList.remove('active', 'btn-primary');
                    b.classList.add('btn-light', 'text-secondary', 'border-0');
                });
                btn.classList.add('active', 'btn-primary');
                btn.classList.remove('btn-light', 'text-secondary', 'border-0');

                state.range = selectedRange;

                if (selectedRange === 'custom') {
                    elements.customRangeContainer.classList.remove('d-none');
                } else {
                    elements.customRangeContainer.classList.add('d-none');
                    loadAllAuditData();
                }
            });
        }

        // Custom range form submission
        if (elements.customRangeForm) {
            elements.customRangeForm.addEventListener('submit', (e) => {
                e.preventDefault();
                const from = elements.fromDateInput.value;
                const to = elements.toDateInput.value;

                if (!from || !to) {
                    toast('Please select both From and To dates.', 'warning');
                    return;
                }

                if (from > to) {
                    toast('From date cannot be later than To date.', 'warning');
                    return;
                }

                state.from = from;
                state.to = to;
                loadAllAuditData();
            });
        }

        // Refresh button
        if (elements.btnRefresh) {
            elements.btnRefresh.addEventListener('click', () => {
                elements.btnRefresh.querySelector('i').classList.add('spin-animation');
                loadAllAuditData().finally(() => {
                    setTimeout(() => {
                        elements.btnRefresh.querySelector('i').classList.remove('spin-animation');
                    }, 400);
                });
            });
        }

        // Export to Excel Button
        if (elements.btnExportExcel) {
            elements.btnExportExcel.addEventListener('click', exportToExcel);
        }

        // Chart Dataset Filter (All, Sales, Cash, UPI)
        if (elements.chartDatasetFilter) {
            elements.chartDatasetFilter.addEventListener('click', (e) => {
                const btn = e.target.closest('button[data-dataset]');
                if (!btn) return;

                elements.chartDatasetFilter.querySelectorAll('button').forEach(b => {
                    b.classList.remove('active', 'btn-white', 'shadow-xs');
                    b.classList.add('btn-transparent', 'text-secondary');
                });
                btn.classList.add('active', 'btn-white', 'shadow-xs');
                btn.classList.remove('btn-transparent', 'text-secondary');

                state.activeChartDataset = btn.getAttribute('data-dataset');
                updateChartDatasetVisibility();
            });
        }
    }

    // ========================================================================
    // 2. Data Fetching
    // ========================================================================
    async function loadAllAuditData() {
        setLoadingState(true);

        const queryParams = new URLSearchParams({
            range: state.range
        });

        if (state.range === 'custom') {
            queryParams.set('from', state.from);
            queryParams.set('to', state.to);
        }

        try {
            // Concurrent fetch of summary, top products, and pending customers
            const [summaryRes, topProductsRes, pendingRes] = await Promise.all([
                api(`api/reports.php?action=summary&${queryParams.toString()}`),
                api(`api/reports.php?action=top_products&${queryParams.toString()}`),
                api('api/reports.php?action=pending')
            ]);

            state.summaryData = summaryRes?.data || null;
            state.topProductsData = topProductsRes?.data?.products || [];
            state.pendingCustomersData = pendingRes?.data?.customers || [];

            renderSummaryCards(state.summaryData);
            renderChart(state.summaryData?.chart, state.summaryData?.filter);
            renderTopProducts(state.topProductsData);
            renderPendingCustomers(state.pendingCustomersData);

            // Update badge text
            if (elements.rangeBadge && state.summaryData?.filter?.label) {
                elements.rangeBadge.textContent = state.summaryData.filter.label;
            }
        } catch (err) {
            console.error('Failed to load audit data:', err);
            toast('Failed to load audit metrics. Please try again.', 'danger');
        } finally {
            setLoadingState(false);
        }
    }

    function setLoadingState(isLoading) {
        if (isLoading) {
            elements.chartSkeleton?.classList.remove('d-none');
            elements.topProductsSkeleton?.classList.remove('d-none');
            elements.pendingCustomersSkeleton?.classList.remove('d-none');

            elements.topProductsTable?.classList.add('d-none');
            elements.pendingCustomersTable?.classList.add('d-none');
            elements.topProductsEmpty?.classList.add('d-none');
            elements.pendingCustomersEmpty?.classList.add('d-none');
        } else {
            elements.chartSkeleton?.classList.add('d-none');
            elements.topProductsSkeleton?.classList.add('d-none');
            elements.pendingCustomersSkeleton?.classList.add('d-none');
        }
    }

    // ========================================================================
    // 3. Render Summary Cards
    // ========================================================================
    function renderSummaryCards(data) {
        if (!data) return;

        // Total Sales (Recognized revenue formula: completed total + pending advances)
        elements.statTotalSales.textContent = formatINR(data.total_sales);

        // Total Collected Inflow
        elements.statTotalCollected.textContent = formatINR(data.total_collected);
        elements.statCashCollected.textContent = formatINR(data.cash_collected);
        elements.statUpiCollected.textContent = formatINR(data.upi_collected);

        // Total Bills & Status counts
        elements.statTotalBills.textContent = data.total_bills.toLocaleString('en-IN');
        elements.statCompletedBills.textContent = data.completed_bills.toLocaleString('en-IN');
        elements.statPendingBills.textContent = data.pending_bills.toLocaleString('en-IN');

        // Total Discount Given
        elements.statTotalDiscount.textContent = formatINR(data.total_discount);

        // Pending Receivables
        elements.statPendingBalance.textContent = formatINR(data.range_pending_balance);
        elements.statOverallPending.textContent = formatINR(data.overall_pending_balance);
    }

    // ========================================================================
    // 4. Render Chart.js Sales Trends
    // ========================================================================
    function renderChart(chartData, filter) {
        if (!elements.chartCanvas || !chartData || typeof Chart === 'undefined') return;

        // Subtitle text
        if (elements.chartSubtitle) {
            elements.chartSubtitle.textContent = chartData.interval === 'hourly'
                ? `Hourly performance for ${filter?.label || 'Today'}`
                : `Daily performance for ${filter?.label || 'Selected Period'}`;
        }

        // Destroy previous chart instance if exists
        if (auditChartInstance) {
            auditChartInstance.destroy();
            auditChartInstance = null;
        }

        const ctx = elements.chartCanvas.getContext('2d');

        // Linear gradient for Sales line fill
        const salesGradient = ctx.createLinearGradient(0, 0, 0, 300);
        salesGradient.addColorStop(0, 'rgba(37, 99, 235, 0.25)');
        salesGradient.addColorStop(1, 'rgba(37, 99, 235, 0.0)');

        auditChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartData.labels || [],
                datasets: [
                    {
                        label: 'Sales Revenue',
                        data: chartData.sales || [],
                        borderColor: '#2563eb', // Primary Blue
                        backgroundColor: salesGradient,
                        fill: true,
                        tension: 0.35,
                        borderWidth: 2.5,
                        pointRadius: chartData.labels.length > 20 ? 1.5 : 3.5,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#2563eb',
                        yAxisID: 'y'
                    },
                    {
                        label: 'Cash Inflow',
                        data: chartData.cash || [],
                        borderColor: '#10b981', // Emerald Green
                        backgroundColor: 'transparent',
                        fill: false,
                        tension: 0.35,
                        borderWidth: 2,
                        pointRadius: chartData.labels.length > 20 ? 1 : 3,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#10b981',
                        yAxisID: 'y'
                    },
                    {
                        label: 'UPI Inflow',
                        data: chartData.upi || [],
                        borderColor: '#8b5cf6', // Indigo / Purple
                        backgroundColor: 'transparent',
                        fill: false,
                        tension: 0.35,
                        borderWidth: 2,
                        pointRadius: chartData.labels.length > 20 ? 1 : 3,
                        pointHoverRadius: 5,
                        pointBackgroundColor: '#8b5cf6',
                        yAxisID: 'y'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 8,
                            font: { size: 12, family: 'Inter, system-ui, sans-serif' }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.9)',
                        titleFont: { size: 12, weight: 'bold' },
                        bodyFont: { size: 12 },
                        padding: 10,
                        cornerRadius: 8,
                        callbacks: {
                            label: function (context) {
                                const label = context.dataset.label || '';
                                const val = Number(context.parsed.y) || 0;
                                return `${label}: ${formatINR(val)}`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            maxTicksLimit: 12,
                            font: { size: 11, family: 'Inter, system-ui, sans-serif' }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(226, 232, 240, 0.6)' },
                        ticks: {
                            callback: function (val) {
                                if (val >= 100000) return '₹' + (val / 100000).toFixed(1) + 'L';
                                if (val >= 1000) return '₹' + (val / 1000).toFixed(1) + 'k';
                                return '₹' + val;
                            },
                            font: { size: 11, family: 'Inter, system-ui, sans-serif' }
                        }
                    }
                }
            }
        });

        updateChartDatasetVisibility();
    }

    function updateChartDatasetVisibility() {
        if (!auditChartInstance) return;

        const ds = state.activeChartDataset;
        // Dataset indexes: 0 = Sales, 1 = Cash, 2 = UPI
        if (ds === 'all') {
            auditChartInstance.setDatasetVisibility(0, true);
            auditChartInstance.setDatasetVisibility(1, true);
            auditChartInstance.setDatasetVisibility(2, true);
        } else if (ds === 'sales') {
            auditChartInstance.setDatasetVisibility(0, true);
            auditChartInstance.setDatasetVisibility(1, false);
            auditChartInstance.setDatasetVisibility(2, false);
        } else if (ds === 'cash') {
            auditChartInstance.setDatasetVisibility(0, false);
            auditChartInstance.setDatasetVisibility(1, true);
            auditChartInstance.setDatasetVisibility(2, false);
        } else if (ds === 'upi') {
            auditChartInstance.setDatasetVisibility(0, false);
            auditChartInstance.setDatasetVisibility(1, false);
            auditChartInstance.setDatasetVisibility(2, true);
        }
        auditChartInstance.update();
    }

    // ========================================================================
    // 5. Render Top Products Table
    // ========================================================================
    function renderTopProducts(products) {
        if (!elements.topProductsTbody) return;

        if (!products || products.length === 0) {
            elements.topProductsEmpty?.classList.remove('d-none');
            elements.topProductsTable?.classList.add('d-none');
            return;
        }

        elements.topProductsEmpty?.classList.add('d-none');
        elements.topProductsTable?.classList.remove('d-none');

        const rankBadges = [
            'bg-warning text-dark', // 1st
            'bg-secondary-subtle text-secondary', // 2nd
            'bg-warning-subtle text-warning-emphasis' // 3rd
        ];

        let html = '';
        products.forEach(p => {
            const badgeClass = rankBadges[p.rank - 1] || 'bg-light text-muted';
            const qtyStr = (p.total_qty % 1 === 0) ? p.total_qty.toString() : p.total_qty.toFixed(2);

            html += `
                <tr>
                    <td class="ps-3 py-2">
                        <span class="badge rounded-circle p-1 d-inline-flex align-items-center justify-content-center ${badgeClass}" style="width: 24px; height: 24px; font-size: 0.75rem;">
                            ${p.rank}
                        </span>
                    </td>
                    <td class="py-2">
                        <div class="fw-semibold text-dark text-truncate" style="max-width: 220px;">${escapeHTML(p.item_name)}</div>
                        <div class="text-muted" style="font-size: 0.72rem;">
                            ${escapeHTML(p.category)} ${p.is_custom ? '<span class="badge bg-light text-secondary border">Custom</span>' : ''}
                        </div>
                    </td>
                    <td class="py-2 text-center">
                        <span class="badge bg-slate-100 text-dark fw-medium px-2 py-1">${qtyStr}</span>
                    </td>
                    <td class="py-2 text-end pe-3">
                        <span class="fw-bold text-dark amount-text">${formatINR(p.total_revenue)}</span>
                    </td>
                </tr>
            `;
        });

        elements.topProductsTbody.innerHTML = html;
    }

    // ========================================================================
    // 6. Render Pending Customers Table
    // ========================================================================
    function renderPendingCustomers(customers) {
        if (!elements.pendingCustomersTbody) return;

        // Update badge count
        if (elements.pendingCustomersBadge) {
            elements.pendingCustomersBadge.textContent = (customers || []).length;
        }

        if (!customers || customers.length === 0) {
            elements.pendingCustomersEmpty?.classList.remove('d-none');
            elements.pendingCustomersTable?.classList.add('d-none');
            return;
        }

        elements.pendingCustomersEmpty?.classList.add('d-none');
        elements.pendingCustomersTable?.classList.remove('d-none');

        let html = '';
        customers.forEach(c => {
            const hasPhone = c.customer_phone && c.customer_phone !== '-';

            html += `
                <tr>
                    <td class="ps-3 py-2">
                        <div class="fw-semibold text-dark">${escapeHTML(c.customer_name)}</div>
                        <div class="small">
                            ${hasPhone 
                                ? `<a href="tel:${escapeHTML(c.customer_phone)}" class="text-decoration-none text-muted small d-inline-flex align-items-center gap-1">
                                    <i class="bi bi-telephone text-primary"></i> ${escapeHTML(c.customer_phone)}
                                   </a>`
                                : '<span class="text-muted small">-</span>'
                            }
                        </div>
                    </td>
                    <td class="py-2">
                        <span class="font-monospace text-primary fw-medium small">${escapeHTML(c.bill_no)}</span>
                        <div class="text-muted" style="font-size: 0.72rem;">${escapeHTML(c.date_formatted || c.created_at)}</div>
                    </td>
                    <td class="py-2 text-end">
                        <span class="text-muted amount-text">${formatINR(c.advance_paid)}</span>
                    </td>
                    <td class="py-2 text-end pe-3">
                        <span class="badge bg-danger-subtle text-danger fw-bold fs-6 amount-text">${formatINR(c.balance_due)}</span>
                    </td>
                </tr>
            `;
        });

        elements.pendingCustomersTbody.innerHTML = html;
    }

    // ========================================================================
    // 7. SheetJS Excel Export (.xlsx)
    // ========================================================================
    async function exportToExcel() {
        if (typeof XLSX === 'undefined') {
            toast('Excel export library (SheetJS) is loading. Please try again.', 'warning');
            return;
        }

        // Set button loading state
        const originalText = elements.btnExportExcelText.textContent;
        elements.btnExportExcel.disabled = true;
        elements.btnExportExcelText.textContent = 'Generating Excel...';
        elements.btnExportExcel.querySelector('i').className = 'spinner-border spinner-border-sm me-1';

        try {
            const queryParams = new URLSearchParams({
                action: 'export_data',
                range: state.range
            });

            if (state.range === 'custom') {
                queryParams.set('from', state.from);
                queryParams.set('to', state.to);
            }

            const response = await api(`api/reports.php?${queryParams.toString()}`);
            const data = response?.data;

            if (!data) {
                throw new Error('No data received from export API.');
            }

            const wb = XLSX.utils.book_new();
            const businessName = data.business_name || window.APP?.businessName || 'QuickPOS Mart';
            const filterLabel = data.filter?.label || 'All Time';
            const generatedAt = new Date().toLocaleString('en-IN', { timeZone: 'Asia/Kolkata' });
            const generatedBy = `${window.APP?.userName || 'Admin'} (${window.APP?.role || 'admin'})`;

            // ----------------------------------------------------------------
            // Sheet 1: Summary
            // ----------------------------------------------------------------
            const summaryAOA = [
                [`${businessName} - Financial Audit & Sales Report`],
                ['Report Range:', filterLabel],
                ['Generated At:', generatedAt],
                ['Generated By:', generatedBy],
                [],
                ['METRIC', 'VALUE'],
                ['Total Sales (Completed Bills + Advances)', data.summary.total_sales],
                ['Total Inflow Collected', data.summary.total_collected],
                ['  - Cash Inflow Collected', data.summary.cash_collected],
                ['  - UPI Inflow Collected', data.summary.upi_collected],
                ['Total Bills / Orders', data.summary.total_bills],
                ['  - Completed Bills Count', data.summary.completed_bills],
                ['  - Pending Bills Count', data.summary.pending_bills],
                ['Total Discount Granted', data.summary.total_discount],
                ['Pending Balance (For Filter Range)', data.summary.range_pending_balance],
                ['Total Outstanding Balance (All Time)', data.summary.overall_pending_balance]
            ];

            const summaryWs = XLSX.utils.aoa_to_sheet(summaryAOA);
            styleSheet(summaryWs, summaryAOA, [1], 5, '1E293B');
            XLSX.utils.book_append_sheet(wb, summaryWs, 'Summary');

            // ----------------------------------------------------------------
            // Sheet 2: Sales (One row per bill)
            // ----------------------------------------------------------------
            const salesHeaders = [
                'Bill No',
                'Date & Time',
                'Customer Name',
                'Customer Phone',
                'Items Summary',
                'Subtotal',
                'Discount',
                'Total',
                'Advance Paid',
                'Balance Due',
                'Status',
                'Payment Mode',
                'UPI Amount',
                'Cash Amount',
                'Cashier'
            ];

            const salesAOA = [salesHeaders];
            (data.bills || []).forEach(b => {
                salesAOA.push([
                    b.bill_no,
                    b.created_at,
                    b.customer_name,
                    b.customer_phone,
                    b.items_summary,
                    Number(b.subtotal) || 0,
                    Number(b.discount_amount) || 0,
                    Number(b.total) || 0,
                    Number(b.advance_paid) || 0,
                    Number(b.balance_due) || 0,
                    (b.status || '').toUpperCase(),
                    b.payment_mode,
                    Number(b.upi_amount) || 0,
                    Number(b.cash_amount) || 0,
                    b.cashier
                ]);
            });

            const salesWs = XLSX.utils.aoa_to_sheet(salesAOA);
            // Money columns: Subtotal(5), Discount(6), Total(7), Advance(8), Balance(9), UPI(12), Cash(13)
            styleSheet(salesWs, salesAOA, [5, 6, 7, 8, 9, 12, 13], 0, '2563EB');
            XLSX.utils.book_append_sheet(wb, salesWs, 'Sales');

            // ----------------------------------------------------------------
            // Sheet 3: Pending Customers
            // ----------------------------------------------------------------
            const pendingHeaders = [
                'Bill No',
                'Bill Date',
                'Customer Name',
                'Customer Phone',
                'Total Bill',
                'Advance Paid',
                'Balance Due',
                'Cashier'
            ];

            const pendingAOA = [pendingHeaders];
            (data.pending_customers || []).forEach(c => {
                pendingAOA.push([
                    c.bill_no,
                    c.created_at,
                    c.customer_name,
                    c.customer_phone,
                    Number(c.total) || 0,
                    Number(c.advance_paid) || 0,
                    Number(c.balance_due) || 0,
                    c.cashier
                ]);
            });

            const pendingWs = XLSX.utils.aoa_to_sheet(pendingAOA);
            // Money columns: Total(4), Advance(5), Balance(6)
            styleSheet(pendingWs, pendingAOA, [4, 5, 6], 0, 'DC2626');
            XLSX.utils.book_append_sheet(wb, pendingWs, 'Pending Customers');

            // Generate filename: sales_report_YYYY-MM-DD_to_YYYY-MM-DD.xlsx
            const startDate = data.filter?.start_date || state.from;
            const endDate = data.filter?.end_date || state.to;
            const fileName = `sales_report_${startDate}_to_${endDate}.xlsx`;

            // Trigger download
            XLSX.writeFile(wb, fileName);
            toast('Excel report exported successfully!', 'success');
        } catch (err) {
            console.error('Export error:', err);
            toast(err.message || 'Failed to generate Excel report.', 'danger');
        } finally {
            elements.btnExportExcel.disabled = false;
            elements.btnExportExcelText.textContent = originalText;
            elements.btnExportExcel.querySelector('i').className = 'bi bi-file-earmark-excel';
        }
    }

    /**
     * Apply styling, column auto-widths, frozen header rows, and currency formats to worksheet
     */
    function styleSheet(sheet, aoa, moneyColIndices, headerRowIndex = 0, headerFillHex = '2563EB') {
        if (!sheet || !sheet['!ref']) return;

        const range = XLSX.utils.decode_range(sheet['!ref']);

        // 1. Format Currency & Numbers
        for (let R = headerRowIndex + 1; R <= range.e.r; ++R) {
            if (moneyColIndices && moneyColIndices.length > 0) {
                for (const C of moneyColIndices) {
                    const cellRef = XLSX.utils.encode_cell({ r: R, c: C });
                    if (sheet[cellRef] && typeof sheet[cellRef].v === 'number') {
                        sheet[cellRef].t = 'n';
                        sheet[cellRef].z = '#,##0.00';
                    }
                }
            }
        }

        // 2. Header Row Styling (Bold, White Text, Background Fill)
        for (let C = 0; C <= range.e.c; ++C) {
            const cellRef = XLSX.utils.encode_cell({ r: headerRowIndex, c: C });
            if (sheet[cellRef]) {
                sheet[cellRef].s = {
                    font: { bold: true, color: { rgb: 'FFFFFF' }, sz: 11 },
                    fill: { fgColor: { rgb: headerFillHex } },
                    alignment: { horizontal: 'center', vertical: 'center' }
                };
            }
        }

        // 3. Freeze Header Row (Row above scrollable pane)
        sheet['!views'] = [{ state: 'frozen', ySplit: headerRowIndex + 1 }];
        sheet['!freeze'] = { xSplit: 0, ySplit: headerRowIndex + 1 };

        // 4. Dynamic Auto Column Widths
        const colWidths = [];
        aoa.forEach(row => {
            row.forEach((cellVal, colIdx) => {
                const str = (cellVal !== null && cellVal !== undefined) ? String(cellVal) : '';
                colWidths[colIdx] = Math.max(colWidths[colIdx] || 10, str.length + 3);
            });
        });
        sheet['!cols'] = colWidths.map(w => ({ wch: Math.min(Math.max(w, 12), 60) }));
    }

})();
