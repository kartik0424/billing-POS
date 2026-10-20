<?php
declare(strict_types=1);

/**
 * Audit Dashboard & Business Analytics (Admin Only)
 * Comprehensive revenue auditing, trend analysis, top products, pending collections, and Excel export.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

// Strict Server-Side Role Enforcement: Admin Only
require_role('admin');

$pageTitle = 'Audit & Reports';
$pageScripts = [
    'https://cdn.jsdelivr.net/npm/chart.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
    'assets/js/audit.js'
];

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-0">
    <!-- Top Action Bar -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div>
            <h4 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                <span>Audit & Business Analytics</span>
                <span class="badge bg-primary-subtle text-primary rounded-pill fs-6" id="auditRangeBadge">Today</span>
            </h4>
            <p class="text-muted small mb-0">Financial audit, sales trends, inventory velocity, and balance receivables</p>
        </div>

        <!-- Action Buttons -->
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-3 py-2 d-flex align-items-center gap-1 shadow-xs" id="btnRefreshAudit" title="Reload data">
                <i class="bi bi-arrow-clockwise"></i>
                <span>Refresh</span>
            </button>
            <button type="button" class="btn btn-success btn-sm rounded-pill px-3 py-2 d-flex align-items-center gap-2 shadow-xs fw-medium" id="btnExportExcel">
                <i class="bi bi-file-earmark-excel"></i>
                <span id="btnExportExcelText">Export to Excel</span>
            </button>
        </div>
    </div>

    <!-- Filter Bar Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-3">
        <div class="card-body p-3">
            <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                <!-- Range Switcher Tabs / Chips -->
                <div class="d-flex flex-wrap gap-1" id="auditRangeTabs" role="tablist">
                    <button type="button" class="btn btn-sm rounded-pill px-3 py-2 fw-medium btn-primary active" data-range="daily">
                        <i class="bi bi-calendar-day me-1"></i> Today
                    </button>
                    <button type="button" class="btn btn-sm rounded-pill px-3 py-2 fw-medium btn-light text-secondary border-0" data-range="weekly">
                        <i class="bi bi-calendar-week me-1"></i> Last 7 Days
                    </button>
                    <button type="button" class="btn btn-sm rounded-pill px-3 py-2 fw-medium btn-light text-secondary border-0" data-range="monthly">
                        <i class="bi bi-calendar-month me-1"></i> This Month
                    </button>
                    <button type="button" class="btn btn-sm rounded-pill px-3 py-2 fw-medium btn-light text-secondary border-0" data-range="custom">
                        <i class="bi bi-calendar-range me-1"></i> Custom Range
                    </button>
                </div>

                <!-- Custom Date Range Form (Visible when 'custom' is active) -->
                <div id="customDateRangeContainer" class="d-none">
                    <form id="customDateRangeForm" class="d-flex flex-wrap align-items-center gap-2">
                        <div class="d-flex align-items-center gap-1">
                            <label for="auditFromDate" class="small text-muted fw-medium mb-0">From:</label>
                            <input type="date" id="auditFromDate" class="form-control form-control-sm bg-slate-50 rounded-3" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" style="width: 140px;">
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <label for="auditToDate" class="small text-muted fw-medium mb-0">To:</label>
                            <input type="date" id="auditToDate" class="form-control form-control-sm bg-slate-50 rounded-3" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" style="width: 140px;">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm rounded-pill px-3 py-1">
                            <i class="bi bi-filter me-1"></i> Apply
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Metrics Cards Grid -->
    <div class="row g-2 g-md-3 row-cols-2 row-cols-md-3 row-cols-xl-5 mb-3">
        <!-- 1. Total Sales -->
        <div class="col">
            <div class="card border-0 shadow-xs rounded-4 h-100 bg-white p-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;">TOTAL SALES</span>
                    <span class="badge bg-primary-subtle text-primary rounded-pill p-1 px-2" data-bs-toggle="tooltip" title="Completed bills total + advance paid on pending bills">
                        <i class="bi bi-info-circle"></i>
                    </span>
                </div>
                <div class="fw-bold fs-5 text-primary amount-text mb-1" id="statTotalSales">₹0.00</div>
                <div class="text-muted small" style="font-size: 0.75rem;" id="statSalesSubtext">Recognized revenue</div>
            </div>
        </div>

        <!-- 2. Total Collected Inflow -->
        <div class="col">
            <div class="card border-0 shadow-xs rounded-4 h-100 bg-white p-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;">TOTAL COLLECTED</span>
                    <span class="badge bg-success-subtle text-success rounded-pill p-1 px-2">
                        <i class="bi bi-wallet2"></i>
                    </span>
                </div>
                <div class="fw-bold fs-5 text-success amount-text mb-1" id="statTotalCollected">₹0.00</div>
                <div class="text-muted small text-truncate" style="font-size: 0.75rem;" id="statCollectedSubtext">
                    Cash: <span id="statCashCollected">₹0</span> &bull; UPI: <span id="statUpiCollected">₹0</span>
                </div>
            </div>
        </div>

        <!-- 3. Total Bills -->
        <div class="col">
            <div class="card border-0 shadow-xs rounded-4 h-100 bg-white p-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;">TOTAL BILLS</span>
                    <span class="badge bg-info-subtle text-info rounded-pill p-1 px-2">
                        <i class="bi bi-receipt"></i>
                    </span>
                </div>
                <div class="fw-bold fs-5 text-dark mb-1" id="statTotalBills">0</div>
                <div class="text-muted small text-truncate" style="font-size: 0.75rem;" id="statBillsSubtext">
                    <span id="statCompletedBills" class="text-success fw-medium">0</span> done &bull; <span id="statPendingBills" class="text-warning fw-medium">0</span> pending
                </div>
            </div>
        </div>

        <!-- 4. Total Discount -->
        <div class="col">
            <div class="card border-0 shadow-xs rounded-4 h-100 bg-white p-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;">TOTAL DISCOUNT</span>
                    <span class="badge bg-warning-subtle text-warning rounded-pill p-1 px-2">
                        <i class="bi bi-tag-fill"></i>
                    </span>
                </div>
                <div class="fw-bold fs-5 text-warning-emphasis amount-text mb-1" id="statTotalDiscount">₹0.00</div>
                <div class="text-muted small" style="font-size: 0.75rem;">Price deductions</div>
            </div>
        </div>

        <!-- 5. Pending Receivables -->
        <div class="col col-12 col-md-6 col-xl">
            <div class="card border-0 shadow-xs rounded-4 h-100 bg-white p-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;">PENDING BALANCE</span>
                    <span class="badge bg-danger-subtle text-danger rounded-pill p-1 px-2">
                        <i class="bi bi-hourglass-split"></i>
                    </span>
                </div>
                <div class="fw-bold fs-5 text-danger amount-text mb-1" id="statPendingBalance">₹0.00</div>
                <div class="text-muted small text-truncate" style="font-size: 0.75rem;" id="statPendingSubtext">
                    All-time: <span id="statOverallPending" class="fw-medium text-dark">₹0.00</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Sales & Collections Chart Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-3">
        <div class="card-header bg-white border-0 pt-3 px-3 pb-2 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
            <div>
                <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                    <i class="bi bi-graph-up text-primary"></i>
                    <span>Sales & Payment Breakdown</span>
                </h6>
                <small class="text-muted" id="chartSubtitle">Hourly trend for today</small>
            </div>

            <!-- Chart Dataset Filters -->
            <div class="btn-group btn-group-sm rounded-pill p-1 bg-slate-100" role="group" id="chartDatasetFilter">
                <button type="button" class="btn btn-sm rounded-pill px-3 py-1 btn-white shadow-xs fw-medium active" data-dataset="all">All</button>
                <button type="button" class="btn btn-sm rounded-pill px-3 py-1 btn-transparent text-secondary fw-medium" data-dataset="sales">Sales</button>
                <button type="button" class="btn btn-sm rounded-pill px-3 py-1 btn-transparent text-secondary fw-medium" data-dataset="cash">Cash</button>
                <button type="button" class="btn btn-sm rounded-pill px-3 py-1 btn-transparent text-secondary fw-medium" data-dataset="upi">UPI</button>
            </div>
        </div>
        <div class="card-body p-3 pt-0">
            <!-- Loading Skeleton for Chart -->
            <div id="chartSkeleton" class="py-5 text-center">
                <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                <span class="text-muted small">Loading sales trends...</span>
            </div>

            <!-- Chart Canvas Container -->
            <div class="position-relative" style="height: 320px; width: 100%;">
                <canvas id="salesChart"></canvas>
            </div>
        </div>
    </div>

    <!-- 2-Column Responsive Layout: Top Products & Pending Customers -->
    <div class="row g-3 mb-4">
        <!-- Column 1: Top 10 Products -->
        <div class="col-12 col-xl-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-0 pt-3 px-3 pb-2 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                            <i class="bi bi-trophy text-warning"></i>
                            <span>Top 10 Performing Products</span>
                        </h6>
                        <small class="text-muted">Ranked by revenue in selected period</small>
                    </div>
                </div>
                <div class="card-body p-0">
                    <!-- Loading Skeleton -->
                    <div id="topProductsSkeleton" class="p-4 text-center">
                        <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                        <span class="text-muted small">Loading top products...</span>
                    </div>

                    <!-- Empty State -->
                    <div id="topProductsEmpty" class="p-4 text-center d-none">
                        <i class="bi bi-box-seam fs-1 text-muted opacity-50 mb-2 d-block"></i>
                        <p class="text-muted small mb-0">No product sales recorded in this period.</p>
                    </div>

                    <!-- Products Table -->
                    <div class="table-responsive" id="topProductsTableContainer">
                        <table class="table table-hover align-middle mb-0" id="topProductsTable">
                            <thead class="bg-slate-50 text-muted small text-uppercase" style="font-size: 0.72rem;">
                                <tr>
                                    <th class="ps-3 py-2" style="width: 48px;">#</th>
                                    <th class="py-2">Product Name</th>
                                    <th class="py-2 text-center" style="width: 80px;">Qty</th>
                                    <th class="py-2 text-end pe-3" style="width: 120px;">Revenue</th>
                                </tr>
                            </thead>
                            <tbody id="topProductsTbody" class="small">
                                <!-- Populated dynamically by audit.js -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Column 2: Pending Customers / Unpaid Receivables -->
        <div class="col-12 col-xl-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-0 pt-3 px-3 pb-2 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                            <i class="bi bi-people-fill text-danger"></i>
                            <span>Unpaid & Pending Customers</span>
                            <span class="badge bg-danger-subtle text-danger rounded-pill small" id="pendingCustomersBadge">0</span>
                        </h6>
                        <small class="text-muted">All outstanding balances requiring collection</small>
                    </div>
                </div>
                <div class="card-body p-0">
                    <!-- Loading Skeleton -->
                    <div id="pendingCustomersSkeleton" class="p-4 text-center">
                        <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                        <span class="text-muted small">Loading pending balances...</span>
                    </div>

                    <!-- Empty State -->
                    <div id="pendingCustomersEmpty" class="p-4 text-center d-none">
                        <i class="bi bi-check-circle-fill fs-1 text-success opacity-75 mb-2 d-block"></i>
                        <h6 class="fw-semibold text-dark mb-1">All Clear!</h6>
                        <p class="text-muted small mb-0">No unpaid or pending customer balances at this time.</p>
                    </div>

                    <!-- Pending Customers Table -->
                    <div class="table-responsive" id="pendingCustomersTableContainer">
                        <table class="table table-hover align-middle mb-0" id="pendingCustomersTable">
                            <thead class="bg-slate-50 text-muted small text-uppercase" style="font-size: 0.72rem;">
                                <tr>
                                    <th class="ps-3 py-2">Customer / Phone</th>
                                    <th class="py-2">Bill No / Date</th>
                                    <th class="py-2 text-end" style="width: 100px;">Advance</th>
                                    <th class="py-2 text-end pe-3" style="width: 110px;">Balance Due</th>
                                </tr>
                            </thead>
                            <tbody id="pendingCustomersTbody" class="small">
                                <!-- Populated dynamically by audit.js -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
