<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

// Accessible by both admin and cashier
require_role(['admin', 'cashier']);

$pageTitle = 'Billing & POS';
$pageScripts = [
    'assets/js/receipt.js',
    'assets/js/printer.js',
    'assets/js/billing.js'
];

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-0">
    <!-- POS Terminal Page Title (Desktop only, mobile uses navbar) -->
    <div class="d-none d-md-block mb-2">
        <h5 class="fw-bold mb-0 text-dark">POS Terminal</h5>
    </div>

    <!-- Mobile Tab Navigation Bar (Visible only on < lg screens) -->
    <div class="d-lg-none mb-3">
        <ul class="nav nav-pills nav-fill bg-white p-1 rounded-3 shadow-xs border" id="billingMobileTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active py-2 fw-semibold d-flex align-items-center justify-content-center gap-1" 
                        id="tab-btn-products" data-bs-toggle="pill" data-bs-target="#pane-products" type="button" role="tab">
                    <i class="bi bi-grid-fill"></i>
                    <span>Products</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link py-2 fw-semibold d-flex align-items-center justify-content-center gap-1" 
                        id="tab-btn-cart" data-bs-toggle="pill" data-bs-target="#pane-cart" type="button" role="tab">
                    <i class="bi bi-cart3"></i>
                    <span>Cart</span>
                    <span class="badge bg-primary rounded-pill ms-1" id="mobileCartBadge">0</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link py-2 fw-semibold d-flex align-items-center justify-content-center gap-1" 
                        id="tab-btn-pending" data-bs-toggle="pill" data-bs-target="#pane-pending" type="button" role="tab">
                    <i class="bi bi-hourglass-split"></i>
                    <span>Pending</span>
                    <span class="badge bg-warning text-dark rounded-pill ms-1" id="mobilePendingBadge">0</span>
                </button>
            </li>
        </ul>
    </div>

    <!-- Main Content: Tab panes on mobile, 3 Columns on Desktop -->
    <div class="tab-content billing-tab-content row g-3">
        
        <!-- ================================================================= -->
        <!-- SECTION A: PRODUCTS CATALOG (Col 1 on Desktop)                    -->
        <!-- ================================================================= -->
        <section class="tab-pane fade show active col-12 col-lg-5 col-xl-5" id="pane-products" role="tabpanel" aria-labelledby="tab-btn-products">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-3 d-flex flex-column">
                    <!-- Search Input -->
                    <div class="input-group mb-2">
                        <span class="input-group-text bg-slate-50 border-end-0 text-muted">
                            <i class="bi bi-search"></i>
                        </span>
                        <input type="text" id="billingSearchInput" class="form-control bg-slate-50 border-start-0 ps-0" placeholder="Search menu or scan..." autocomplete="off">
                        <button class="btn btn-link text-muted border-start-0 text-decoration-none d-none" type="button" id="clearBillingSearchBtn">
                            <i class="bi bi-x-circle-fill"></i>
                        </button>
                    </div>

                    <!-- Category Chips Filter -->
                    <div class="category-chips-scroller d-flex gap-2 overflow-x-auto no-scrollbar pb-2 mb-2" id="billingCategoryChips">
                        <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 py-1 flex-shrink-0 category-chip active" data-category="all">
                            All Items
                        </button>
                    </div>

                    <!-- Products Loading Indicator -->
                    <div id="catalogLoading" class="text-center py-5">
                        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                        <span class="text-muted small ms-2">Loading items...</span>
                    </div>

                    <!-- Products Empty State -->
                    <div id="catalogEmpty" class="text-center py-5 d-none">
                        <i class="bi bi-search fs-2 text-muted opacity-50 mb-2"></i>
                        <p class="text-muted small mb-0">No matching items found</p>
                    </div>

                    <!-- Products Grid -->
                    <div id="billingProductsGrid" class="row g-2 overflow-y-auto flex-grow-1" style="max-height: calc(100vh - 240px);">
                        <!-- Injected dynamically by billing.js -->
                    </div>
                </div>
            </div>
        </section>

        <!-- ================================================================= -->
        <!-- SECTION B: CART (Col 2 on Desktop)                                -->
        <!-- ================================================================= -->
        <section class="tab-pane fade col-12 col-lg-4 col-xl-4" id="pane-cart" role="tabpanel" aria-labelledby="tab-btn-cart">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-3 d-flex flex-column">
                    <!-- Cart Header -->
                    <div class="d-flex align-items-center justify-content-between pb-2 mb-2 border-bottom">
                        <div class="d-flex align-items-center gap-2">
                            <h5 class="fw-bold mb-0 text-dark">Current Bill</h5>
                            <span class="badge bg-primary-subtle text-primary rounded-pill" id="cartItemCountBadge">0 items</span>
                        </div>
                        <div class="d-flex gap-1">
                            <button type="button" class="btn btn-light btn-sm text-primary rounded-2 px-2 py-1 fw-medium" id="btnAddCustomItem" title="Add custom non-inventory item">
                                <i class="bi bi-plus-circle me-1"></i> Custom
                            </button>
                            <button type="button" class="btn btn-light btn-sm text-danger rounded-2 px-2 py-1" id="btnClearCart" title="Clear Cart">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Cart Empty State Placeholder -->
                    <div id="emptyCartPlaceholder" class="text-center py-5 my-auto">
                        <div class="text-muted opacity-50 mb-2">
                            <i class="bi bi-cart-x fs-1"></i>
                        </div>
                        <h6 class="fw-semibold text-secondary mb-1">Cart is Empty</h6>
                        <p class="text-muted small mb-0">Tap products on the left or add a custom item to begin.</p>
                    </div>

                    <!-- Cart Items Scrollable List -->
                    <div id="cartItemsContainer" class="flex-grow-1 overflow-y-auto mb-3 d-none" style="max-height: calc(100vh - 430px); min-height: 180px;">
                        <ul class="list-group list-group-flush" id="cartItemsList">
                            <!-- Injected dynamically by billing.js -->
                        </ul>
                    </div>

                    <!-- Bill Calculation & Discount Summary -->
                    <div class="cart-summary bg-slate-50 rounded-3 p-3 mt-auto border">
                        <!-- Discount Selector Accordion / Controls -->
                        <div class="mb-2">
                            <div class="d-flex align-items-center justify-content-between mb-1">
                                <label class="small fw-semibold text-secondary mb-0">Discount</label>
                                <div class="btn-group btn-group-sm" role="group" id="discountTypeGroup">
                                    <input type="radio" class="btn-check" name="discount_type_radio" id="discTypeNone" value="none" checked>
                                    <label class="btn btn-outline-secondary py-0 px-2" for="discTypeNone">None</label>

                                    <input type="radio" class="btn-check" name="discount_type_radio" id="discTypeFlat" value="flat">
                                    <label class="btn btn-outline-secondary py-0 px-2" for="discTypeFlat">₹ Flat</label>

                                    <input type="radio" class="btn-check" name="discount_type_radio" id="discTypePercent" value="percent">
                                    <label class="btn btn-outline-secondary py-0 px-2" for="discTypePercent">% Off</label>
                                </div>
                            </div>
                            <div class="input-group input-group-sm d-none" id="discountInputWrap">
                                <span class="input-group-text bg-white" id="discountAddon">₹</span>
                                <input type="number" step="0.01" min="0" class="form-control" id="discountValueInput" placeholder="0.00">
                            </div>
                        </div>

                        <!-- Subtotal, Discount Amount, Grand Total -->
                        <div class="d-flex justify-content-between text-muted small mb-1">
                            <span>Subtotal</span>
                            <span class="amount-text" id="cartSubtotalText">₹0.00</span>
                        </div>
                        <div class="d-flex justify-content-between text-muted small mb-2 d-none" id="cartDiscountRow">
                            <span class="text-danger">Discount</span>
                            <span class="amount-text text-danger" id="cartDiscountText">-₹0.00</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-baseline pt-2 border-top">
                            <span class="fw-bold text-dark fs-6">Grand Total</span>
                            <span class="fw-bold text-primary fs-4 amount-text" id="cartTotalText">₹0.00</span>
                        </div>
                    </div>

                    <!-- Next / Checkout Trigger Button -->
                    <div class="mt-3">
                        <button type="button" class="btn btn-primary w-100 py-3 fw-semibold rounded-3 shadow-sm btn-touch d-flex align-items-center justify-content-center gap-2" id="btnNextCheckout" disabled>
                            <span>Proceed to Pay</span>
                            <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- ================================================================= -->
        <!-- SECTION C: PENDING BILLS (Col 3 on Desktop)                       -->
        <!-- ================================================================= -->
        <section class="tab-pane fade col-12 col-lg-3 col-xl-3" id="pane-pending" role="tabpanel" aria-labelledby="tab-btn-pending">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-3 d-flex flex-column">
                    <div class="d-flex align-items-center justify-content-between pb-2 mb-2 border-bottom">
                        <div class="d-flex align-items-center gap-2">
                            <h6 class="fw-bold mb-0 text-dark">Pending Orders</h6>
                            <span class="badge bg-warning text-dark rounded-pill" id="pendingCountBadge">0</span>
                        </div>
                        <button type="button" class="btn btn-light btn-sm text-secondary rounded-2 p-1" id="btnRefreshPending" title="Refresh Pending Orders">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </div>

                    <!-- Search Pending Input -->
                    <div class="input-group input-group-sm mb-2">
                        <span class="input-group-text bg-slate-50 border-end-0 text-muted"><i class="bi bi-search"></i></span>
                        <input type="text" id="pendingSearchInput" class="form-control bg-slate-50 border-start-0 ps-0" placeholder="Search customer, phone, bill#...">
                    </div>

                    <!-- Pending Orders List Container -->
                    <div id="pendingList" class="flex-grow-1 overflow-y-auto" style="max-height: calc(100vh - 280px);">
                        <!-- Injected dynamically by billing.js -->
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<!-- ========================================================================= -->
<!-- Custom Product Modal (Non-inventory item)                                 -->
<!-- ========================================================================= -->
<div class="modal fade" id="customProductModal" tabindex="-1" aria-labelledby="customProductModalTitle" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow rounded-4">
            <form id="customProductForm" novalidate autocomplete="off">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold" id="customProductModalTitle">Custom Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-3">
                    <div class="mb-2">
                        <label for="customItemName" class="form-label small fw-semibold text-secondary">Item Description <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="customItemName" required maxlength="150" placeholder="e.g. Special Dessert">
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-7">
                            <label for="customItemPrice" class="form-label small fw-semibold text-secondary">Price (₹) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0.01" class="form-control" id="customItemPrice" required placeholder="0.00">
                        </div>
                        <div class="col-5">
                            <label for="customItemQty" class="form-label small fw-semibold text-secondary">Qty <span class="text-danger">*</span></label>
                            <input type="number" step="1" min="1" max="999" class="form-control" id="customItemQty" value="1" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0 px-3 pb-3">
                    <button type="button" class="btn btn-light rounded-3 px-3 py-2 btn-touch" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-3 py-2 btn-touch fw-semibold">Add to Cart</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- CHECKOUT WIZARD MODAL (Step 1 Customer -> Step 2 Pay -> Step 3 Receipt)   -->
<!-- ========================================================================= -->
<div class="modal fade" id="checkoutModal" tabindex="-1" aria-labelledby="checkoutModalTitle" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4 overflow-hidden">
            <div class="modal-header border-bottom pb-3">
                <h5 class="modal-title fw-bold d-flex align-items-center gap-2" id="checkoutModalTitle">
                    <i class="bi bi-wallet2 text-primary"></i>
                    <span>Checkout & Pay</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" id="checkoutCloseTopBtn"></button>
            </div>

            <div class="modal-body p-4">
                <!-- Inline Error Alert -->
                <div id="checkoutAlert" class="alert alert-danger py-2 px-3 small d-none mb-3" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    <span id="checkoutAlertText"></span>
                </div>

                <!-- STEP 1: Customer Details & Advance Payment -->
                <div id="checkoutStep1">
                    <div class="bg-primary-subtle rounded-3 p-3 text-center mb-3">
                        <div class="text-muted small text-uppercase fw-semibold">Total Bill Amount</div>
                        <div class="fw-bold text-primary fs-2 amount-text" id="checkoutTotalDisplay">₹0.00</div>
                    </div>

                    <div class="mb-3">
                        <label for="checkoutCustName" class="form-label small fw-semibold text-secondary mb-1" id="checkoutCustNameLabel">Customer Name <span id="checkoutCustNameBadge" class="text-muted fw-normal">(Optional)</span></label>
                        <input type="text" class="form-control" id="checkoutCustName" placeholder="e.g. Rahul Sharma" maxlength="100">
                    </div>

                    <div class="mb-3">
                        <label for="checkoutCustPhone" class="form-label small fw-semibold text-secondary mb-1" id="checkoutCustPhoneLabel">Customer Contact / Phone <span id="checkoutCustPhoneBadge" class="text-muted fw-normal">(Optional)</span></label>
                        <input type="tel" class="form-control" id="checkoutCustPhone" placeholder="10-digit mobile number" maxlength="15">
                        <div class="text-muted small mt-1" id="checkoutCustPhoneHelp" style="font-size: 0.75rem;">Required if advance amount is entered (pending/credit bill)</div>
                    </div>

                    <div class="mb-3">
                        <label for="checkoutAdvanceAmount" class="form-label small fw-semibold text-secondary mb-1">Advance Amount (Optional)</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white">₹</span>
                            <input type="number" step="0.01" min="0" class="form-control" id="checkoutAdvanceAmount" placeholder="Leave blank for full payment">
                        </div>
                        <div class="form-text small mt-1" id="checkoutAdvanceHint">
                            <span class="text-success fw-medium"><i class="bi bi-check-circle"></i> Full payment (Completed Bill)</span>
                        </div>
                    </div>

                    <div class="d-flex gap-2 pt-2">
                        <button type="button" class="btn btn-light rounded-3 px-3 py-2 btn-touch flex-grow-1" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary rounded-3 px-4 py-2 btn-touch fw-semibold flex-grow-1" id="btnStep1Next">
                            <span>Next: Payment Mode</span>
                            <i class="bi bi-arrow-right ms-1"></i>
                        </button>
                    </div>
                </div>

                <!-- STEP 2: Payment Mode Selection & Mixed Split -->
                <div id="checkoutStep2" class="d-none">
                    <div class="d-flex justify-content-between align-items-center bg-slate-50 border rounded-3 p-3 mb-3">
                        <div>
                            <div class="small text-muted">Amount Payable Now</div>
                            <div class="fs-4 fw-bold text-dark amount-text" id="checkoutPayableNowText">₹0.00</div>
                        </div>
                        <div class="badge bg-secondary-subtle text-secondary small" id="checkoutBillTypeBadge">Full Payment</div>
                    </div>

                    <label class="form-label small fw-semibold text-secondary mb-2">Select Payment Method</label>
                    <div class="row g-2 mb-3">
                        <div class="col-4">
                            <button type="button" class="btn btn-outline-primary w-100 py-3 rounded-3 d-flex flex-column align-items-center justify-content-center gap-1 payment-mode-card active" data-mode="cash">
                                <i class="bi bi-cash-stack fs-4"></i>
                                <span class="fw-semibold small">Cash</span>
                            </button>
                        </div>
                        <div class="col-4">
                            <button type="button" class="btn btn-outline-primary w-100 py-3 rounded-3 d-flex flex-column align-items-center justify-content-center gap-1 payment-mode-card" data-mode="upi">
                                <i class="bi bi-qr-code fs-4"></i>
                                <span class="fw-semibold small">UPI</span>
                            </button>
                        </div>
                        <div class="col-4">
                            <button type="button" class="btn btn-outline-primary w-100 py-3 rounded-3 d-flex flex-column align-items-center justify-content-center gap-1 payment-mode-card" data-mode="mixed">
                                <i class="bi bi-pie-chart fs-4"></i>
                                <span class="fw-semibold small">Mixed</span>
                            </button>
                        </div>
                    </div>

                    <!-- Mixed Split Inputs (Shown only if Mixed selected) -->
                    <div id="checkoutMixedSplit" class="card bg-slate-50 border rounded-3 p-3 mb-3 d-none">
                        <div class="row g-2">
                            <div class="col-6">
                                <label for="mixedUpiInput" class="form-label small fw-semibold text-secondary mb-1">UPI Amount (₹)</label>
                                <input type="number" step="0.01" min="0" class="form-control" id="mixedUpiInput" placeholder="0.00">
                            </div>
                            <div class="col-6">
                                <label for="mixedCashInput" class="form-label small fw-semibold text-secondary mb-1">Cash Amount (₹)</label>
                                <input type="number" step="0.01" min="0" class="form-control" id="mixedCashInput" placeholder="0.00">
                            </div>
                        </div>
                        <div class="small mt-2" id="mixedSplitValidationHint">
                            <!-- Populated dynamically: Remaining: ₹XX.XX -->
                        </div>
                    </div>

                    <div class="d-flex gap-2 pt-2">
                        <button type="button" class="btn btn-light rounded-3 px-3 py-2 btn-touch" id="btnStep2Back">
                            <i class="bi bi-arrow-left me-1"></i> Back
                        </button>
                        <button type="button" class="btn btn-success rounded-3 px-4 py-2 btn-touch fw-semibold flex-grow-1" id="btnGenerateBill">
                            <i class="bi bi-check2-circle me-1"></i> Generate Bill
                        </button>
                    </div>
                </div>

                <!-- STEP 3: Receipt Preview & Success Screen -->
                <div id="checkoutStepPreview" class="d-none">
                    <div class="alert alert-success py-2 px-3 small d-flex align-items-center mb-3">
                        <i class="bi bi-check-circle-fill fs-5 me-2 text-success"></i>
                        <div>
                            <strong>Bill Generated Successfully!</strong>
                            <div class="small">The receipt is ready for print or new billing.</div>
                        </div>
                    </div>

                    <!-- Thermal Receipt Preview Canvas -->
                    <div class="receipt-preview-wrapper bg-white border rounded-3 p-2 mb-3 shadow-xs" style="max-height: 380px; overflow-y: auto;">
                        <div id="checkoutReceiptPreview">
                            <!-- Populated dynamically by receipt.js -->
                        </div>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-primary rounded-3 px-3 py-2 btn-touch flex-grow-1" id="btnPrintReceipt">
                            <i class="bi bi-printer me-1"></i> Print Receipt
                        </button>
                        <button type="button" class="btn btn-outline-primary rounded-3 px-3 py-2 btn-touch flex-grow-1" id="btnNewBill">
                            <i class="bi bi-plus-circle me-1"></i> New Bill
                        </button>
                        <button type="button" class="btn btn-light rounded-3 px-3 py-2 btn-touch" data-bs-dismiss="modal">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- MARK PAID MODAL (Pending balance clearance)                               -->
<!-- ========================================================================= -->
<div class="modal fade" id="markPaidModal" tabindex="-1" aria-labelledby="markPaidModalTitle" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header border-bottom pb-2">
                <h6 class="modal-title fw-bold" id="markPaidModalTitle">Collect Balance Due</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3">
                <div id="markPaidAlert" class="alert alert-danger py-2 px-3 small d-none mb-3" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    <span id="markPaidAlertText"></span>
                </div>

                <div class="bg-slate-50 border rounded-3 p-2 mb-3 text-center">
                    <div class="small text-muted" id="markPaidBillNoText">Bill #</div>
                    <div class="fw-bold text-dark" id="markPaidCustomerText">Customer</div>
                    <div class="fs-4 fw-bold text-danger amount-text mt-1" id="markPaidBalanceDueText">₹0.00</div>
                    <div class="small text-muted">Balance Payable</div>
                </div>

                <label class="form-label small fw-semibold text-secondary mb-1">Payment Method</label>
                <div class="row g-1 mb-2">
                    <div class="col-4">
                        <button type="button" class="btn btn-sm btn-outline-primary w-100 py-2 rounded-2 mark-paid-mode-card active" data-mode="cash">
                            <i class="bi bi-cash-stack d-block fs-5"></i>
                            <span class="small">Cash</span>
                        </button>
                    </div>
                    <div class="col-4">
                        <button type="button" class="btn btn-sm btn-outline-primary w-100 py-2 rounded-2 mark-paid-mode-card" data-mode="upi">
                            <i class="bi bi-qr-code d-block fs-5"></i>
                            <span class="small">UPI</span>
                        </button>
                    </div>
                    <div class="col-4">
                        <button type="button" class="btn btn-sm btn-outline-primary w-100 py-2 rounded-2 mark-paid-mode-card" data-mode="mixed">
                            <i class="bi bi-pie-chart d-block fs-5"></i>
                            <span class="small">Mixed</span>
                        </button>
                    </div>
                </div>

                <!-- Mixed Split Inputs -->
                <div id="markPaidMixedSplit" class="card bg-slate-50 border rounded-3 p-2 mb-3 d-none">
                    <div class="row g-2">
                        <div class="col-6">
                            <label for="markPaidUpiInput" class="form-label small fw-semibold text-secondary mb-1">UPI (₹)</label>
                            <input type="number" step="0.01" min="0" class="form-control form-control-sm" id="markPaidUpiInput" placeholder="0.00">
                        </div>
                        <div class="col-6">
                            <label for="markPaidCashInput" class="form-label small fw-semibold text-secondary mb-1">Cash (₹)</label>
                            <input type="number" step="0.01" min="0" class="form-control form-control-sm" id="markPaidCashInput" placeholder="0.00">
                        </div>
                    </div>
                    <div class="small mt-1" id="markPaidMixedHint" style="font-size: 0.75rem;"></div>
                </div>
            </div>
            <div class="modal-footer border-top-0 pt-0 px-3 pb-3">
                <button type="button" class="btn btn-light btn-sm rounded-3 px-3 py-2 btn-touch" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success btn-sm rounded-3 px-3 py-2 btn-touch fw-semibold flex-grow-1" id="btnConfirmMarkPaid">
                    Confirm & Complete
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- BILL REPRINT / VIEW RECEIPT MODAL                                         -->
<!-- ========================================================================= -->
<div class="modal fade" id="receiptModal" tabindex="-1" aria-labelledby="receiptModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4 overflow-hidden">
            <div class="modal-header border-bottom py-2">
                <h6 class="modal-title fw-bold" id="receiptModalTitle">Receipt Preview</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3">
                <div class="receipt-preview-wrapper bg-white border rounded-3 p-2 mb-3 shadow-xs" style="max-height: 420px; overflow-y: auto;">
                    <div id="reprintReceiptContainer">
                        <!-- Populated by receipt.js -->
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary rounded-3 px-3 py-2 btn-touch flex-grow-1" id="btnPrintModalReceipt">
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

<!-- ========================================================================= -->
<!-- Mobile Sticky Bottom Cart Summary Bar (Quick 1-tap view)                   -->
<!-- ========================================================================= -->
<div class="mobile-cart-bar d-lg-none py-2 px-3 shadow-lg d-none" id="mobileStickyCartBar">
    <div class="d-flex align-items-center justify-content-between">
        <div>
            <div class="small text-muted" id="mobileStickyItemCount">0 items</div>
            <div class="fw-bold text-primary fs-5 amount-text" id="mobileStickyTotal">₹0.00</div>
        </div>
        <button type="button" class="btn btn-primary fw-semibold rounded-3 px-3 py-2 btn-touch d-flex align-items-center gap-2" id="mobileStickyViewCartBtn">
            <span>View Cart</span>
            <i class="bi bi-chevron-right"></i>
        </button>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
