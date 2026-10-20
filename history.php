<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

// Accessible by both admin and cashier
require_role(['admin', 'cashier']);

$pageTitle = 'Sales History';
$pageScripts = [
    'assets/js/receipt.js',
    'assets/js/printer.js',
    'assets/js/history.js'
];

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-0">
    <!-- Page Header & Summary Badges -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div>
            <h4 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                <span>Sales History</span>
                <span class="badge bg-primary-subtle text-primary rounded-pill fs-6" id="historyTotalCountBadge">0</span>
            </h4>
            <p class="text-muted small mb-0">Browse completed bills, audit payments, and reprint thermal receipts</p>
        </div>

        <!-- Revenue Summary Pills -->
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <div class="bg-white border rounded-3 px-3 py-2 shadow-xs text-center flex-grow-1 flex-md-grow-0">
                <div class="text-muted small" style="font-size: 0.72rem;">TOTAL SALES</div>
                <div class="fw-bold text-primary fs-6 amount-text" id="summaryTotalRevenue">₹0.00</div>
            </div>
            <div class="bg-white border rounded-3 px-3 py-2 shadow-xs text-center flex-grow-1 flex-md-grow-0">
                <div class="text-muted small" style="font-size: 0.72rem;">UPI COLLECTED</div>
                <div class="fw-bold text-success fs-6 amount-text" id="summaryTotalUpi">₹0.00</div>
            </div>
            <div class="bg-white border rounded-3 px-3 py-2 shadow-xs text-center flex-grow-1 flex-md-grow-0">
                <div class="text-muted small" style="font-size: 0.72rem;">CASH COLLECTED</div>
                <div class="fw-bold text-dark fs-6 amount-text" id="summaryTotalCash">₹0.00</div>
            </div>
        </div>
    </div>

    <!-- Filter Bar Card -->
    <div class="card border-0 shadow-sm rounded-4 mb-3">
        <div class="card-body p-3">
            <div class="row g-2 align-items-center">
                <!-- Search Input -->
                <div class="col-12 col-md-4">
                    <div class="input-group">
                        <span class="input-group-text bg-slate-50 border-end-0 text-muted">
                            <i class="bi bi-search"></i>
                        </span>
                        <input type="text" id="historySearchInput" class="form-control bg-slate-50 border-start-0 ps-0" placeholder="Search bill#, customer, phone...">
                        <button class="btn btn-link text-muted border-start-0 text-decoration-none d-none" type="button" id="clearHistorySearchBtn">
                            <i class="bi bi-x-circle-fill"></i>
                        </button>
                    </div>
                </div>

                <!-- From & To Date Pickers -->
                <div class="col-6 col-md-2">
                    <input type="date" id="historyFromDate" class="form-control bg-slate-50" title="From Date">
                </div>
                <div class="col-6 col-md-2">
                    <input type="date" id="historyToDate" class="form-control bg-slate-50" title="To Date">
                </div>

                <!-- Quick Date Chips Row -->
                <div class="col-12 col-md-4">
                    <div class="d-flex gap-1 overflow-x-auto no-scrollbar justify-content-md-end">
                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-2 py-1 quick-date-chip active" data-range="today">Today</button>
                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-2 py-1 quick-date-chip" data-range="yesterday">Yesterday</button>
                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-2 py-1 quick-date-chip" data-range="week">This Week</button>
                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-2 py-1 quick-date-chip" data-range="all">All Time</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading State -->
    <div id="historyLoading" class="text-center py-5">
        <div class="spinner-border text-primary" role="status"></div>
        <p class="text-muted small mt-2">Loading sales history...</p>
    </div>

    <!-- Empty State -->
    <div id="historyEmpty" class="card border-0 shadow-sm rounded-4 text-center py-5 px-3 d-none">
        <div class="card-body">
            <i class="bi bi-receipt-cutoff fs-1 text-muted opacity-50 mb-2 d-block"></i>
            <h5 class="fw-bold text-dark mb-1">No Orders Found</h5>
            <p class="text-muted small mb-0">No completed orders match your selected filters or date range.</p>
        </div>
    </div>

    <!-- Desktop Data Table View (Hidden on mobile < lg) -->
    <div class="d-none d-lg-block">
        <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-3" id="historyDesktopCard">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="historyDesktopTable">
                    <thead class="table-light text-secondary small text-uppercase fw-semibold">
                        <tr>
                            <th class="ps-3 py-3" style="width: 140px;">Bill No</th>
                            <th style="width: 170px;">Date & Time</th>
                            <th>Customer</th>
                            <th>Payment Split</th>
                            <th>Cashier</th>
                            <th class="text-end">Total</th>
                            <th class="text-center pe-3" style="width: 130px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="historyDesktopTableBody">
                        <!-- Injected dynamically by history.js -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Mobile Card List View (Visible only on < lg) -->
    <div class="d-lg-none" id="historyMobileList">
        <!-- Injected dynamically by history.js -->
    </div>

    <!-- Pagination Toolbar -->
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2 mt-3 pb-4" id="historyPaginationWrap">
        <div class="text-muted small" id="historyPaginationInfo">
            Showing 0 to 0 of 0 entries
        </div>

        <!-- Desktop Pagination Buttons -->
        <div class="d-none d-sm-flex gap-1 align-items-center">
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-2 px-3 py-1 btn-touch" id="btnHistoryPrev" disabled>
                <i class="bi bi-chevron-left me-1"></i> Previous
            </button>
            <span class="small fw-semibold px-2 text-dark" id="historyPageNumber">Page 1 of 1</span>
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-2 px-3 py-1 btn-touch" id="btnHistoryNext" disabled>
                Next <i class="bi bi-chevron-right ms-1"></i>
            </button>
        </div>

        <!-- Mobile "Load More" Button -->
        <div class="d-sm-none w-100">
            <button type="button" class="btn btn-light border w-100 py-2 rounded-3 fw-semibold text-primary btn-touch d-none" id="btnHistoryLoadMore">
                Load More Orders
            </button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- RECEIPT PREVIEW & REPRINT MODAL                                           -->
<!-- ========================================================================= -->
<div class="modal fade" id="historyReceiptModal" tabindex="-1" aria-labelledby="historyReceiptModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4 overflow-hidden">
            <div class="modal-header border-bottom py-2">
                <h6 class="modal-title fw-bold" id="historyReceiptModalTitle">Receipt Preview</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3">
                <div class="receipt-preview-wrapper bg-white border rounded-3 p-2 mb-3 shadow-xs" style="max-height: 420px; overflow-y: auto;">
                    <div id="historyReceiptContainer">
                        <!-- Populated by receipt.js -->
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary rounded-3 px-3 py-2 btn-touch flex-grow-1" id="btnPrintHistoryReceipt">
                        <i class="bi bi-printer me-1"></i> Print Receipt
                    </button>
                    <button type="button" class="btn btn-light rounded-3 px-3 py-2 btn-touch" data-bs-dismiss="modal">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
