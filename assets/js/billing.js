/**
 * Billing & POS Core Module
 * Product selection, category filtering, cart state management, live calculations,
 * step-by-step Checkout Wizard, Pending bills management, and Balance clearance.
 */

// Global namespace for POS
window.POS = window.POS || {};

document.addEventListener('DOMContentLoaded', () => {
    // =========================================================================
    // DOM Elements - Catalog & Products
    // =========================================================================
    const productsGrid = document.getElementById('billingProductsGrid');
    const catalogLoading = document.getElementById('catalogLoading');
    const catalogEmpty = document.getElementById('catalogEmpty');
    const searchInput = document.getElementById('billingSearchInput');
    const clearSearchBtn = document.getElementById('clearBillingSearchBtn');
    const categoryChipsContainer = document.getElementById('billingCategoryChips');

    // Cart Elements
    const cartItemsContainer = document.getElementById('cartItemsContainer');
    const cartItemsList = document.getElementById('cartItemsList');
    const emptyCartPlaceholder = document.getElementById('emptyCartPlaceholder');
    const cartItemCountBadge = document.getElementById('cartItemCountBadge');
    const cartSubtotalText = document.getElementById('cartSubtotalText');
    const cartDiscountRow = document.getElementById('cartDiscountRow');
    const cartDiscountText = document.getElementById('cartDiscountText');
    const cartTotalText = document.getElementById('cartTotalText');
    const btnNextCheckout = document.getElementById('btnNextCheckout');
    const btnClearCart = document.getElementById('btnClearCart');
    const btnAddCustomItem = document.getElementById('btnAddCustomItem');

    // Discount Elements
    const discountTypeRadios = document.querySelectorAll('input[name="discount_type_radio"]');
    const discountInputWrap = document.getElementById('discountInputWrap');
    const discountValueInput = document.getElementById('discountValueInput');
    const discountAddon = document.getElementById('discountAddon');

    // Custom Product Modal Elements
    const customProductModalEl = document.getElementById('customProductModal');
    const customProductModal = new bootstrap.Modal(customProductModalEl);
    const customProductForm = document.getElementById('customProductForm');
    const customItemNameInput = document.getElementById('customItemName');
    const customItemPriceInput = document.getElementById('customItemPrice');
    const customItemQtyInput = document.getElementById('customItemQty');

    // Mobile Elements
    const mobileCartBadge = document.getElementById('mobileCartBadge');
    const mobilePendingBadge = document.getElementById('mobilePendingBadge');
    const mobileStickyCartBar = document.getElementById('mobileStickyCartBar');
    const mobileStickyItemCount = document.getElementById('mobileStickyItemCount');
    const mobileStickyTotal = document.getElementById('mobileStickyTotal');
    const mobileStickyViewCartBtn = document.getElementById('mobileStickyViewCartBtn');
    const tabBtnCart = document.getElementById('tab-btn-cart');
    const tabBtnProducts = document.getElementById('tab-btn-products');

    // Checkout Wizard Elements
    const checkoutModalEl = document.getElementById('checkoutModal');
    const checkoutModal = new bootstrap.Modal(checkoutModalEl);
    const checkoutAlert = document.getElementById('checkoutAlert');
    const checkoutAlertText = document.getElementById('checkoutAlertText');
    const checkoutStep1 = document.getElementById('checkoutStep1');
    const checkoutStep2 = document.getElementById('checkoutStep2');
    const checkoutStepPreview = document.getElementById('checkoutStepPreview');
    const checkoutTotalDisplay = document.getElementById('checkoutTotalDisplay');
    const checkoutCustName = document.getElementById('checkoutCustName');
    const checkoutCustPhone = document.getElementById('checkoutCustPhone');
    const checkoutAdvanceAmount = document.getElementById('checkoutAdvanceAmount');
    const checkoutAdvanceHint = document.getElementById('checkoutAdvanceHint');
    const btnStep1Next = document.getElementById('btnStep1Next');
    const btnStep2Back = document.getElementById('btnStep2Back');
    const checkoutPayableNowText = document.getElementById('checkoutPayableNowText');
    const checkoutBillTypeBadge = document.getElementById('checkoutBillTypeBadge');
    const checkoutMixedSplit = document.getElementById('checkoutMixedSplit');
    const mixedUpiInput = document.getElementById('mixedUpiInput');
    const mixedCashInput = document.getElementById('mixedCashInput');
    const mixedSplitValidationHint = document.getElementById('mixedSplitValidationHint');
    const btnGenerateBill = document.getElementById('btnGenerateBill');
    const checkoutReceiptPreview = document.getElementById('checkoutReceiptPreview');
    const btnPrintReceipt = document.getElementById('btnPrintReceipt');
    const btnNewBill = document.getElementById('btnNewBill');
    const paymentModeCards = document.querySelectorAll('.payment-mode-card');

    // Pending Bills Elements (Part 6)
    const pendingList = document.getElementById('pendingList');
    const pendingCountBadge = document.getElementById('pendingCountBadge');
    const pendingSearchInput = document.getElementById('pendingSearchInput');
    const btnRefreshPending = document.getElementById('btnRefreshPending');

    // Mark Paid Modal Elements
    const markPaidModalEl = document.getElementById('markPaidModal');
    const markPaidModal = new bootstrap.Modal(markPaidModalEl);
    const markPaidAlert = document.getElementById('markPaidAlert');
    const markPaidAlertText = document.getElementById('markPaidAlertText');
    const markPaidBillNoText = document.getElementById('markPaidBillNoText');
    const markPaidCustomerText = document.getElementById('markPaidCustomerText');
    const markPaidBalanceDueText = document.getElementById('markPaidBalanceDueText');
    const markPaidModeCards = document.querySelectorAll('.mark-paid-mode-card');
    const markPaidMixedSplit = document.getElementById('markPaidMixedSplit');
    const markPaidUpiInput = document.getElementById('markPaidUpiInput');
    const markPaidCashInput = document.getElementById('markPaidCashInput');
    const markPaidMixedHint = document.getElementById('markPaidMixedHint');
    const btnConfirmMarkPaid = document.getElementById('btnConfirmMarkPaid');

    // Receipt Modal Elements (Reprint / View)
    const receiptModalEl = document.getElementById('receiptModal');
    const receiptModal = new bootstrap.Modal(receiptModalEl);
    const reprintReceiptContainer = document.getElementById('reprintReceiptContainer');
    const btnPrintModalReceipt = document.getElementById('btnPrintModalReceipt');

    // =========================================================================
    // Application States
    // =========================================================================
    let catalogProducts = [];
    let catalogCategories = [];
    let selectedCategory = 'all';

    let cartState = {
        items: [],
        discount_type: 'none',
        discount_value: 0.00,
        subtotal: 0.00,
        discount_amount: 0.00,
        total: 0.00
    };

    let checkoutState = {
        amountPayingNow: 0.00,
        selectedMode: 'cash',
        isAdvance: false,
        lastBill: null,
        lastSettings: null
    };

    let pendingBillsState = [];
    let currentMarkPaidBill = null;
    let currentReprintBill = null;
    let currentReprintSettings = null;

    // Restore cached cart from localStorage
    try {
        const savedCart = localStorage.getItem('quickpos_cart');
        if (savedCart) {
            const parsed = JSON.parse(savedCart);
            if (parsed && Array.isArray(parsed.items)) {
                cartState = {
                    items: parsed.items,
                    discount_type: parsed.discount_type || 'none',
                    discount_value: parseFloat(parsed.discount_value) || 0.00,
                    subtotal: 0.00,
                    discount_amount: 0.00,
                    total: 0.00
                };
            }
        }
    } catch (e) {
        console.warn('Failed to parse cached cart:', e);
    }

    // =========================================================================
    // 1. Products Catalog Loading & Rendering
    // =========================================================================
    async function loadCatalog() {
        catalogLoading.classList.remove('d-none');
        catalogEmpty.classList.add('d-none');
        productsGrid.innerHTML = '';

        try {
            const res = await api('api/products.php?action=list');
            if (res && res.success) {
                catalogProducts = res.data.products || [];
                catalogCategories = res.data.categories || [];
                renderCategoryChips();
                renderCatalogProducts();
            }
        } catch (err) {
            toast(err.message || 'Unable to load products.', 'danger');
        } finally {
            catalogLoading.classList.add('d-none');
        }
    }

    function renderCategoryChips() {
        let html = `
            <button type="button" class="btn btn-sm ${selectedCategory === 'all' ? 'btn-primary' : 'btn-light'} rounded-pill px-3 py-1 flex-shrink-0 category-chip" data-category="all">
                All Items
            </button>
        `;
        catalogCategories.forEach(cat => {
            const isActive = selectedCategory === cat;
            html += `
                <button type="button" class="btn btn-sm ${isActive ? 'btn-primary' : 'btn-light'} rounded-pill px-3 py-1 flex-shrink-0 category-chip" data-category="${escapeHTML(cat)}">
                    ${escapeHTML(cat)}
                </button>
            `;
        });
        categoryChipsContainer.innerHTML = html;
    }

    function renderCatalogProducts() {
        const query = (searchInput.value || '').trim().toLowerCase();
        const filtered = catalogProducts.filter(item => {
            const matchesQuery = item.name.toLowerCase().includes(query) || item.category.toLowerCase().includes(query);
            const matchesCat = (selectedCategory === 'all') || (item.category === selectedCategory);
            return matchesQuery && matchesCat;
        });

        if (filtered.length === 0) {
            catalogEmpty.classList.remove('d-none');
            productsGrid.innerHTML = '';
            return;
        }

        catalogEmpty.classList.add('d-none');
        productsGrid.innerHTML = filtered.map(item => {
            const cartItem = cartState.items.find(ci => ci.product_id === item.id);
            const inCartCount = cartItem ? cartItem.quantity : 0;

            return `
                <div class="col-6 col-sm-4 col-md-6 col-xl-4">
                    <div class="card h-100 border rounded-3 p-2 card-clickable position-relative product-card-select" data-id="${item.id}">
                        ${inCartCount > 0 ? `<span class="position-absolute top-0 end-0 badge rounded-pill bg-primary m-1 shadow-xs">${inCartCount}</span>` : ''}
                        <div class="small text-muted text-truncate mb-1" style="font-size: 0.72rem;">${escapeHTML(item.category)}</div>
                        <h6 class="fw-semibold text-dark mb-1 text-truncate" title="${escapeHTML(item.name)}" style="font-size: 0.88rem;">${escapeHTML(item.name)}</h6>
                        <div class="fw-bold text-primary amount-text mt-auto" style="font-size: 0.95rem;">${formatINR(item.price)}</div>
                    </div>
                </div>
            `;
        }).join('');
    }

    // =========================================================================
    // 2. Cart Operations & Live Calculations
    // =========================================================================
    function addToCart(product) {
        const existing = cartState.items.find(item => item.product_id === product.id);
        if (existing) {
            existing.quantity += 1;
            existing.line_total = Math.round((existing.quantity * existing.price) * 100) / 100;
        } else {
            cartState.items.push({
                key: 'prod_' + product.id,
                product_id: product.id,
                name: product.name,
                price: Number(product.price),
                quantity: 1,
                line_total: Number(product.price),
                is_custom: false
            });
        }
        calculateTotals();
        renderCart();
        renderCatalogProducts();
    }

    function changeQty(key, delta) {
        const item = cartState.items.find(i => i.key === key);
        if (!item) return;

        const newQty = item.quantity + delta;
        if (newQty <= 0) {
            removeItem(key);
        } else {
            item.quantity = newQty;
            item.line_total = Math.round((item.quantity * item.price) * 100) / 100;
            calculateTotals();
            renderCart();
            renderCatalogProducts();
        }
    }

    function removeItem(key) {
        cartState.items = cartState.items.filter(i => i.key !== key);
        calculateTotals();
        renderCart();
        renderCatalogProducts();
    }

    function addCustomItem({ name, price, quantity }) {
        const numPrice = parseFloat(price);
        const numQty = parseInt(quantity, 10);

        if (!name || isNaN(numPrice) || numPrice <= 0 || isNaN(numQty) || numQty <= 0) {
            toast('Please enter valid details for custom product.', 'warning');
            return;
        }

        const key = 'custom_' + Date.now();
        cartState.items.push({
            key,
            product_id: null,
            name: name.trim(),
            price: numPrice,
            quantity: numQty,
            line_total: Math.round((numPrice * numQty) * 100) / 100,
            is_custom: true
        });

        calculateTotals();
        renderCart();
        toast(`Added custom item: ${name}`, 'success');
    }

    function applyDiscount(type, value) {
        cartState.discount_type = type;
        cartState.discount_value = parseFloat(value) || 0.00;
        calculateTotals();
        renderCart();
    }

    async function clearCart() {
        if (cartState.items.length === 0) return;

        const confirmed = await confirmDialog({
            title: 'Clear Cart',
            message: 'Are you sure you want to remove all items from the current bill?',
            confirmText: 'Clear All',
            confirmBtnClass: 'btn-danger',
            icon: 'bi-cart-x text-danger'
        });

        if (confirmed) {
            cartState.items = [];
            cartState.discount_type = 'none';
            cartState.discount_value = 0.00;
            syncDiscountInputs();
            calculateTotals();
            renderCart();
            renderCatalogProducts();
            toast('Cart cleared.', 'info');
        }
    }

    function calculateTotals() {
        cartState.subtotal = cartState.items.reduce((sum, item) => sum + (item.price * item.quantity), 0);
        cartState.subtotal = Math.round(cartState.subtotal * 100) / 100;

        let discount = 0.00;
        if (cartState.discount_type === 'percent') {
            const pct = Math.max(0, Math.min(100, cartState.discount_value));
            discount = Math.round((cartState.subtotal * (pct / 100)) * 100) / 100;
        } else if (cartState.discount_type === 'flat') {
            discount = Math.max(0, Math.min(cartState.subtotal, cartState.discount_value));
        }

        cartState.discount_amount = discount;
        cartState.total = Math.max(0, Math.round((cartState.subtotal - discount) * 100) / 100);

        try {
            localStorage.setItem('quickpos_cart', JSON.stringify({
                items: cartState.items,
                discount_type: cartState.discount_type,
                discount_value: cartState.discount_value
            }));
        } catch (e) {
            console.error('Failed to write cart to localStorage:', e);
        }
    }

    function renderCart() {
        const totalItemsCount = cartState.items.reduce((sum, item) => sum + item.quantity, 0);

        cartItemCountBadge.textContent = `${totalItemsCount} ${totalItemsCount === 1 ? 'item' : 'items'}`;
        mobileCartBadge.textContent = totalItemsCount;
        mobileStickyItemCount.textContent = `${totalItemsCount} ${totalItemsCount === 1 ? 'item' : 'items'}`;

        if (cartState.items.length === 0) {
            emptyCartPlaceholder.classList.remove('d-none');
            cartItemsContainer.classList.add('d-none');
            btnNextCheckout.disabled = true;
            btnNextCheckout.innerHTML = `<span>Proceed to Pay</span> <i class="bi bi-arrow-right"></i>`;
            mobileStickyCartBar.classList.add('d-none');
        } else {
            emptyCartPlaceholder.classList.add('d-none');
            cartItemsContainer.classList.remove('d-none');
            btnNextCheckout.disabled = false;
            btnNextCheckout.innerHTML = `<span>Proceed to Pay &bull; ${formatINR(cartState.total)}</span> <i class="bi bi-arrow-right"></i>`;
            mobileStickyCartBar.classList.remove('d-none');
        }

        cartItemsList.innerHTML = cartState.items.map(item => `
            <li class="list-group-item px-1 py-2 border-bottom bg-transparent" id="cart-row-${item.key}">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <div class="flex-grow-1 pe-2">
                        <div class="fw-semibold text-dark text-truncate" title="${escapeHTML(item.name)}">
                            ${escapeHTML(item.name)}
                            ${item.is_custom ? '<span class="badge bg-info-subtle text-info small ms-1" style="font-size: 0.65rem;">Custom</span>' : ''}
                        </div>
                        <div class="text-muted small">${formatINR(item.price)} each</div>
                    </div>
                    <div class="fw-bold text-dark amount-text">
                        ${formatINR(item.line_total)}
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-1">
                    <div class="btn-group align-items-center bg-white border rounded-2 p-0">
                        <button type="button" class="btn btn-sm btn-light border-0 btn-qty btn-cart-minus" data-key="${item.key}" title="Decrease Quantity">
                            <i class="bi ${item.quantity === 1 ? 'bi-trash text-danger' : 'bi-dash'}"></i>
                        </button>
                        <span class="px-2 fw-semibold small amount-text" style="min-width: 28px; text-align: center;">${item.quantity}</span>
                        <button type="button" class="btn btn-sm btn-light border-0 btn-qty btn-cart-plus" data-key="${item.key}" title="Increase Quantity">
                            <i class="bi bi-plus"></i>
                        </button>
                    </div>

                    <button type="button" class="btn btn-link text-danger p-1 text-decoration-none btn-cart-remove" data-key="${item.key}" title="Remove Item">
                        <i class="bi bi-x-circle fs-6"></i>
                    </button>
                </div>
            </li>
        `).join('');

        cartSubtotalText.textContent = formatINR(cartState.subtotal);
        if (cartState.discount_amount > 0) {
            cartDiscountRow.classList.remove('d-none');
            cartDiscountText.textContent = `-${formatINR(cartState.discount_amount)}`;
        } else {
            cartDiscountRow.classList.add('d-none');
        }
        cartTotalText.textContent = formatINR(cartState.total);
        mobileStickyTotal.textContent = formatINR(cartState.total);
    }

    function syncDiscountInputs() {
        discountTypeRadios.forEach(radio => {
            radio.checked = (radio.value === cartState.discount_type);
        });

        if (cartState.discount_type === 'none') {
            discountInputWrap.classList.add('d-none');
            discountValueInput.value = '';
        } else {
            discountInputWrap.classList.remove('d-none');
            discountAddon.textContent = (cartState.discount_type === 'percent') ? '%' : '₹';
            discountValueInput.value = cartState.discount_value > 0 ? cartState.discount_value : '';
            discountValueInput.placeholder = (cartState.discount_type === 'percent') ? 'e.g. 10' : '0.00';
            discountValueInput.max = (cartState.discount_type === 'percent') ? '100' : cartState.subtotal;
        }
    }

    // =========================================================================
    // 3. Checkout Wizard Implementation
    // =========================================================================
    function showCheckoutError(msg) {
        checkoutAlertText.textContent = msg;
        checkoutAlert.classList.remove('d-none');
    }

    function hideCheckoutError() {
        checkoutAlert.classList.add('d-none');
        checkoutAlertText.textContent = '';
    }

    function openCheckout() {
        if (cartState.items.length === 0) {
            toast('Your cart is empty. Please add items to begin checkout.', 'warning');
            return;
        }

        hideCheckoutError();

        checkoutStep1.classList.remove('d-none');
        checkoutStep2.classList.add('d-none');
        checkoutStepPreview.classList.add('d-none');

        checkoutTotalDisplay.textContent = formatINR(cartState.total);
        checkoutCustName.value = '';
        checkoutCustPhone.value = '';
        checkoutAdvanceAmount.value = '';
        updateAdvanceHint();

        checkoutModal.show();
        setTimeout(() => checkoutCustName.focus(), 300);
    }

    function updateAdvanceHint() {
        const advVal = parseFloat(checkoutAdvanceAmount.value);
        if (isNaN(advVal) || advVal <= 0) {
            checkoutAdvanceHint.innerHTML = `<span class="text-success fw-medium"><i class="bi bi-check-circle"></i> Full payment (Completed Bill)</span>`;
        } else if (advVal >= cartState.total) {
            checkoutAdvanceHint.innerHTML = `<span class="text-success fw-medium"><i class="bi bi-check-circle"></i> Full payment of ${formatINR(cartState.total)}</span>`;
        } else {
            const balance = Math.round((cartState.total - advVal) * 100) / 100;
            checkoutAdvanceHint.innerHTML = `<span class="text-warning-emphasis fw-semibold"><i class="bi bi-clock-history"></i> Pending bill (Balance Due: ${formatINR(balance)})</span>`;
        }
    }

    checkoutAdvanceAmount.addEventListener('input', updateAdvanceHint);

    btnStep1Next.addEventListener('click', () => {
        hideCheckoutError();

        const phone = checkoutCustPhone.value.trim();
        if (phone && !/^(\+91|91|0)?[6-9]\d{9}$|^[0-9]{7,15}$/.test(phone.replace(/[\s\-\(\)]/g, ''))) {
            showCheckoutError('Please enter a valid 10-digit customer phone number.');
            checkoutCustPhone.focus();
            return;
        }

        const advVal = parseFloat(checkoutAdvanceAmount.value);
        if (!isNaN(advVal) && advVal > cartState.total) {
            showCheckoutError(`Advance amount cannot exceed the bill total (${formatINR(cartState.total)}).`);
            checkoutAdvanceAmount.focus();
            return;
        }

        if (!isNaN(advVal) && advVal > 0 && advVal < cartState.total) {
            checkoutState.amountPayingNow = advVal;
            checkoutState.isAdvance = true;
            checkoutBillTypeBadge.textContent = 'Advance Payment';
            checkoutBillTypeBadge.className = 'badge bg-warning-subtle text-warning-emphasis small';
        } else {
            checkoutState.amountPayingNow = cartState.total;
            checkoutState.isAdvance = false;
            checkoutBillTypeBadge.textContent = 'Full Payment';
            checkoutBillTypeBadge.className = 'badge bg-success-subtle text-success small';
        }

        checkoutPayableNowText.textContent = formatINR(checkoutState.amountPayingNow);

        checkoutState.selectedMode = 'cash';
        paymentModeCards.forEach(c => c.classList.toggle('active', c.dataset.mode === 'cash'));
        checkoutMixedSplit.classList.add('d-none');
        mixedUpiInput.value = '';
        mixedCashInput.value = '';

        checkoutStep1.classList.add('d-none');
        checkoutStep2.classList.remove('d-none');
    });

    btnStep2Back.addEventListener('click', () => {
        hideCheckoutError();
        checkoutStep2.classList.add('d-none');
        checkoutStep1.classList.remove('d-none');
    });

    paymentModeCards.forEach(card => {
        card.addEventListener('click', () => {
            paymentModeCards.forEach(c => c.classList.remove('active'));
            card.classList.add('active');

            const mode = card.dataset.mode;
            checkoutState.selectedMode = mode;

            if (mode === 'mixed') {
                checkoutMixedSplit.classList.remove('d-none');
                mixedUpiInput.value = '';
                mixedCashInput.value = checkoutState.amountPayingNow.toFixed(2);
                updateMixedSplitValidation();
                mixedUpiInput.focus();
            } else {
                checkoutMixedSplit.classList.add('d-none');
            }
        });
    });

    function updateMixedSplitValidation() {
        const upi = parseFloat(mixedUpiInput.value) || 0.00;
        const cash = parseFloat(mixedCashInput.value) || 0.00;
        const totalAllocated = Math.round((upi + cash) * 100) / 100;
        const target = checkoutState.amountPayingNow;
        const diff = Math.round((target - totalAllocated) * 100) / 100;

        if (Math.abs(diff) <= 0.01) {
            mixedSplitValidationHint.innerHTML = `<span class="text-success fw-medium"><i class="bi bi-check-circle"></i> Split matches amount payable (${formatINR(target)})</span>`;
            return true;
        } else if (diff > 0) {
            mixedSplitValidationHint.innerHTML = `<span class="text-danger fw-medium"><i class="bi bi-exclamation-circle"></i> Remaining to allocate: ${formatINR(diff)}</span>`;
            return false;
        } else {
            mixedSplitValidationHint.innerHTML = `<span class="text-danger fw-medium"><i class="bi bi-exclamation-circle"></i> Allocated exceeds total by: ${formatINR(Math.abs(diff))}</span>`;
            return false;
        }
    }

    mixedUpiInput.addEventListener('input', () => {
        const upi = parseFloat(mixedUpiInput.value) || 0.00;
        if (upi >= 0 && upi <= checkoutState.amountPayingNow) {
            mixedCashInput.value = (checkoutState.amountPayingNow - upi).toFixed(2);
        }
        updateMixedSplitValidation();
    });

    mixedCashInput.addEventListener('input', updateMixedSplitValidation);

    btnGenerateBill.addEventListener('click', async () => {
        hideCheckoutError();

        let upiAmount = 0.00;
        let cashAmount = 0.00;

        if (checkoutState.selectedMode === 'mixed') {
            const isValidSplit = updateMixedSplitValidation();
            if (!isValidSplit) {
                showCheckoutError('Mixed payment split must equal the exact amount payable now.');
                return;
            }
            upiAmount = parseFloat(mixedUpiInput.value) || 0.00;
            cashAmount = parseFloat(mixedCashInput.value) || 0.00;
        } else if (checkoutState.selectedMode === 'upi') {
            upiAmount = checkoutState.amountPayingNow;
        } else {
            cashAmount = checkoutState.amountPayingNow;
        }

        const advVal = parseFloat(checkoutAdvanceAmount.value);
        const payload = {
            items: cartState.items.map(i => ({
                product_id: i.product_id,
                item_name: i.name,
                price: i.price,
                quantity: i.quantity,
                is_custom: i.is_custom ? 1 : 0
            })),
            discount_type: cartState.discount_type,
            discount_value: cartState.discount_value,
            customer_name: checkoutCustName.value.trim(),
            customer_phone: checkoutCustPhone.value.trim(),
            advance_amount: (!isNaN(advVal) && advVal > 0) ? advVal : null,
            payment_mode: checkoutState.selectedMode,
            upi_amount: upiAmount,
            cash_amount: cashAmount
        };

        // Offline IndexedDB Queue: if offline, store locally with client_uuid
        if (!navigator.onLine && window.PWA && typeof window.PWA.queueBill === 'function') {
            try {
                const queued = await window.PWA.queueBill(payload);
                const offlineBill = {
                    bill_no: 'QUEUED-' + queued.client_uuid.substring(0, 8).toUpperCase(),
                    date_formatted: new Date().toLocaleString() + ' (Offline Queue)',
                    customer_name: payload.customer_name || 'Walk-in',
                    customer_phone: payload.customer_phone || '-',
                    subtotal: cartState.subtotal,
                    discount_amount: cartState.discount_amount,
                    total: cartState.total,
                    payment_mode: payload.payment_mode,
                    advance_paid: payload.advance_amount || 0,
                    balance_due: (payload.advance_amount && payload.advance_amount < cartState.total) ? (cartState.total - payload.advance_amount) : 0,
                    cashier_name: window.APP?.userName || 'Cashier',
                    items: payload.items
                };

                checkoutState.lastBill = offlineBill;
                checkoutState.lastSettings = {
                    business_name: window.APP?.businessName,
                    business_address: window.APP?.businessAddress,
                    address: window.APP?.businessAddress,
                    business_phone: window.APP?.businessPhone,
                    phone: window.APP?.businessPhone,
                    business_gstin: window.APP?.businessGstin,
                    gstin: window.APP?.businessGstin,
                    receipt_footer: window.APP?.receiptFooter,
                    footer_message: window.APP?.receiptFooter,
                    paper_width: window.APP?.paperWidth
                };

                checkoutReceiptPreview.innerHTML = `
                    <div class="alert alert-warning py-2 px-3 small mb-2 text-start">
                        <strong><i class="bi bi-cloud-arrow-up me-1"></i> Saved to Offline Queue:</strong>
                        This bill is stored safely on your device and will auto-sync with the server when reconnected. It has not been assigned a final server invoice number yet.
                    </div>
                ` + renderReceiptHTML(offlineBill, checkoutState.lastSettings);

                checkoutStep2.classList.add('d-none');
                checkoutStepPreview.classList.remove('d-none');

                // Clear cart
                cartState.items = [];
                cartState.discount_type = 'none';
                cartState.discount_value = 0.00;
                localStorage.removeItem('quickpos_cart');

                syncDiscountInputs();
                calculateTotals();
                renderCart();
                renderCatalogProducts();
                return;
            } catch (queueErr) {
                showCheckoutError('Could not queue bill offline: ' + queueErr.message);
                return;
            }
        }

        setLoading(btnGenerateBill, true, 'Generating Bill...');

        try {
            const res = await api('api/bills.php?action=create', {
                method: 'POST',
                body: payload
            });

            if (res && res.success) {
                checkoutState.lastBill = res.data.bill;
                checkoutState.lastSettings = res.data.settings;

                // Render thermal receipt HTML
                checkoutReceiptPreview.innerHTML = renderReceiptHTML(res.data.bill, res.data.settings);

                // Show step preview
                checkoutStep2.classList.add('d-none');
                checkoutStepPreview.classList.remove('d-none');

                // Clear active cart & localStorage
                cartState.items = [];
                cartState.discount_type = 'none';
                cartState.discount_value = 0.00;
                localStorage.removeItem('quickpos_cart');

                syncDiscountInputs();
                calculateTotals();
                renderCart();
                renderCatalogProducts();

                toast(`Bill ${res.data.bill.bill_no} created successfully!`, 'success');

                // Automatically refresh pending bills list if advance bill was placed
                loadPendingBills();
            }
        } catch (err) {
            showCheckoutError(err.message || 'Failed to generate bill.');
        } finally {
            setLoading(btnGenerateBill, false);
        }
    });

    btnPrintReceipt.addEventListener('click', () => {
        if (checkoutState.lastBill) {
            if (window.PRINTER && typeof window.PRINTER.print === 'function') {
                window.PRINTER.print(checkoutState.lastBill, checkoutState.lastSettings);
            } else {
                window.print();
            }
        }
    });

    btnNewBill.addEventListener('click', () => {
        checkoutModal.hide();
        setTimeout(() => {
            searchInput.value = '';
            searchInput.focus();
        }, 300);
    });

    // =========================================================================
    // 4. Pending Orders & Mark Paid Clearance (Part 6)
    // =========================================================================

    /**
     * Fetch pending bills list from API
     */
    async function loadPendingBills() {
        const query = (pendingSearchInput?.value || '').trim();
        try {
            const res = await api(`api/pending.php?action=list&search=${encodeURIComponent(query)}`);
            if (res && res.success) {
                pendingBillsState = res.data.bills || [];
                renderPendingBills();
            }
        } catch (err) {
            console.error('Failed to load pending bills:', err);
        }
    }

    /**
     * Render pending orders cards in Column 3 / Pending Tab
     */
    function renderPendingBills() {
        const count = pendingBillsState.length;
        if (pendingCountBadge) pendingCountBadge.textContent = count;
        if (mobilePendingBadge) mobilePendingBadge.textContent = count;

        if (!pendingList) return;

        if (count === 0) {
            pendingList.innerHTML = `
                <div class="text-center py-5 text-muted small">
                    <i class="bi bi-check2-circle fs-2 text-success opacity-75 mb-2 d-block"></i>
                    No pending credit bills
                </div>
            `;
            return;
        }

        pendingList.innerHTML = pendingBillsState.map(bill => {
            const formattedDate = formatReceiptDate(bill.created_at);
            const customerDisplay = bill.customer_name || 'Walk-in Customer';

            return `
                <div class="card border rounded-3 p-3 mb-2 shadow-xs bg-white pending-card" id="pending-card-${bill.id}">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <div>
                            <span class="fw-bold text-dark font-monospace">${escapeHTML(bill.bill_no)}</span>
                            <div class="small text-muted">${formattedDate}</div>
                        </div>
                        <span class="badge bg-danger-subtle text-danger fw-bold amount-text fs-6">
                            Due: ${formatINR(bill.balance_due)}
                        </span>
                    </div>

                    <div class="my-1">
                        <div class="fw-semibold text-dark small">${escapeHTML(customerDisplay)}</div>
                        ${bill.customer_phone ? `
                            <a href="tel:${escapeHTML(bill.customer_phone)}" class="text-muted small text-decoration-none">
                                <i class="bi bi-telephone-fill me-1 text-primary"></i>${escapeHTML(bill.customer_phone)}
                            </a>
                        ` : ''}
                    </div>

                    ${bill.items_summary ? `
                        <div class="small text-secondary text-truncate my-1" title="${escapeHTML(bill.items_summary)}" style="font-size: 0.78rem;">
                            <i class="bi bi-basket me-1"></i>${escapeHTML(bill.items_summary)}
                        </div>
                    ` : ''}

                    <div class="d-flex justify-content-between small text-muted pt-1 border-top mt-1" style="font-size: 0.75rem;">
                        <span>Total: ${formatINR(bill.total)}</span>
                        <span>Paid: ${formatINR(bill.advance_paid)}</span>
                    </div>

                    <div class="d-flex gap-2 mt-2 pt-1">
                        <button type="button" class="btn btn-light btn-sm rounded-2 py-1 px-2 flex-grow-1 text-secondary btn-view-pending-bill" data-id="${bill.id}" title="View Receipt">
                            <i class="bi bi-receipt me-1"></i> View
                        </button>
                        <button type="button" class="btn btn-success btn-sm rounded-2 py-1 px-2 flex-grow-1 btn-mark-paid-bill" data-id="${bill.id}" title="Mark Paid">
                            <i class="bi bi-check2-circle me-1"></i> Paid
                        </button>
                    </div>
                </div>
            `;
        }).join('');
    }

    // Refresh pending bills button
    if (btnRefreshPending) {
        btnRefreshPending.addEventListener('click', () => {
            const icon = btnRefreshPending.querySelector('i');
            if (icon) icon.classList.add('spin-animation');
            loadPendingBills().finally(() => {
                if (icon) icon.classList.remove('spin-animation');
            });
        });
    }

    // Search pending bills live
    if (pendingSearchInput) {
        pendingSearchInput.addEventListener('input', debounce(() => {
            loadPendingBills();
        }, 200));
    }

    // Pending Orders Action Handler (View Receipt & Mark Paid)
    if (pendingList) {
        pendingList.addEventListener('click', async (e) => {
            // View / Reprint Receipt
            const viewBtn = e.target.closest('.btn-view-pending-bill');
            if (viewBtn) {
                const billId = Number(viewBtn.dataset.id);
                try {
                    const res = await api(`api/bills.php?action=get&id=${billId}`);
                    if (res && res.success) {
                        currentReprintBill = res.data.bill;
                        currentReprintSettings = res.data.settings;
                        reprintReceiptContainer.innerHTML = renderReceiptHTML(res.data.bill, res.data.settings);
                        receiptModal.show();
                    }
                } catch (err) {
                    toast(err.message || 'Failed to fetch bill details.', 'danger');
                }
                return;
            }

            // Mark Paid Button
            const paidBtn = e.target.closest('.btn-mark-paid-bill');
            if (paidBtn) {
                const billId = Number(paidBtn.dataset.id);
                const bill = pendingBillsState.find(b => b.id === billId);
                if (bill) {
                    openMarkPaidModal(bill);
                }
            }
        });
    }

    // Print Receipt from Reprint Modal
    if (btnPrintModalReceipt) {
        btnPrintModalReceipt.addEventListener('click', () => {
            if (currentReprintBill) {
                if (window.PRINTER && typeof window.PRINTER.print === 'function') {
                    window.PRINTER.print(currentReprintBill, currentReprintSettings);
                } else {
                    window.print();
                }
            }
        });
    }

    // =========================================================================
    // 5. Mark Paid Modal Logic
    // =========================================================================
    let markPaidSelectedMode = 'cash';

    function openMarkPaidModal(bill) {
        currentMarkPaidBill = bill;
        markPaidAlert.classList.add('d-none');
        markPaidAlertText.textContent = '';

        markPaidBillNoText.textContent = `Bill: ${bill.bill_no}`;
        markPaidCustomerText.textContent = bill.customer_name ? `${bill.customer_name} ${bill.customer_phone ? '(' + bill.customer_phone + ')' : ''}` : 'Customer';
        markPaidBalanceDueText.textContent = formatINR(bill.balance_due);

        // Reset mode to Cash
        markPaidSelectedMode = 'cash';
        markPaidModeCards.forEach(c => c.classList.toggle('active', c.dataset.mode === 'cash'));
        markPaidMixedSplit.classList.add('d-none');
        markPaidUpiInput.value = '';
        markPaidCashInput.value = '';

        markPaidModal.show();
    }

    markPaidModeCards.forEach(card => {
        card.addEventListener('click', () => {
            markPaidModeCards.forEach(c => c.classList.remove('active'));
            card.classList.add('active');

            const mode = card.dataset.mode;
            markPaidSelectedMode = mode;

            if (mode === 'mixed') {
                markPaidMixedSplit.classList.remove('d-none');
                markPaidUpiInput.value = '';
                markPaidCashInput.value = currentMarkPaidBill.balance_due.toFixed(2);
                updateMarkPaidMixedHint();
                markPaidUpiInput.focus();
            } else {
                markPaidMixedSplit.classList.add('d-none');
            }
        });
    });

    function updateMarkPaidMixedHint() {
        if (!currentMarkPaidBill) return false;
        const upi = parseFloat(markPaidUpiInput.value) || 0.00;
        const cash = parseFloat(markPaidCashInput.value) || 0.00;
        const allocated = Math.round((upi + cash) * 100) / 100;
        const target = currentMarkPaidBill.balance_due;
        const diff = Math.round((target - allocated) * 100) / 100;

        if (Math.abs(diff) <= 0.01) {
            markPaidMixedHint.innerHTML = `<span class="text-success"><i class="bi bi-check-circle"></i> Matches balance of ${formatINR(target)}</span>`;
            return true;
        } else if (diff > 0) {
            markPaidMixedHint.innerHTML = `<span class="text-danger"><i class="bi bi-exclamation-circle"></i> Remaining: ${formatINR(diff)}</span>`;
            return false;
        } else {
            markPaidMixedHint.innerHTML = `<span class="text-danger"><i class="bi bi-exclamation-circle"></i> Exceeds by: ${formatINR(Math.abs(diff))}</span>`;
            return false;
        }
    }

    if (markPaidUpiInput && markPaidCashInput) {
        markPaidUpiInput.addEventListener('input', () => {
            const upi = parseFloat(markPaidUpiInput.value) || 0.00;
            if (currentMarkPaidBill && upi >= 0 && upi <= currentMarkPaidBill.balance_due) {
                markPaidCashInput.value = (currentMarkPaidBill.balance_due - upi).toFixed(2);
            }
            updateMarkPaidMixedHint();
        });
        markPaidCashInput.addEventListener('input', updateMarkPaidMixedHint);
    }

    if (btnConfirmMarkPaid) {
        btnConfirmMarkPaid.addEventListener('click', async () => {
            if (!currentMarkPaidBill) return;

            let upiAmount = 0.00;
            let cashAmount = 0.00;

            if (markPaidSelectedMode === 'mixed') {
                const isValid = updateMarkPaidMixedHint();
                if (!isValid) {
                    markPaidAlertText.textContent = 'Mixed split must equal the exact balance due.';
                    markPaidAlert.classList.remove('d-none');
                    return;
                }
                upiAmount = parseFloat(markPaidUpiInput.value) || 0.00;
                cashAmount = parseFloat(markPaidCashInput.value) || 0.00;
            } else if (markPaidSelectedMode === 'upi') {
                upiAmount = currentMarkPaidBill.balance_due;
            } else {
                cashAmount = currentMarkPaidBill.balance_due;
            }

            setLoading(btnConfirmMarkPaid, true, 'Updating...');

            try {
                const res = await api('api/pending.php?action=mark_paid', {
                    method: 'POST',
                    body: {
                        bill_id: currentMarkPaidBill.id,
                        payment_mode: markPaidSelectedMode,
                        upi_amount: upiAmount,
                        cash_amount: cashAmount
                    }
                });

                if (res && res.success) {
                    markPaidModal.hide();
                    toast(`Bill ${currentMarkPaidBill.bill_no} marked as paid!`, 'success');

                    // Remove card from DOM without full page refresh
                    const cardEl = document.getElementById(`pending-card-${currentMarkPaidBill.id}`);
                    if (cardEl) {
                        cardEl.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                        cardEl.style.opacity = '0';
                        cardEl.style.transform = 'scale(0.95)';
                        setTimeout(() => cardEl.remove(), 300);
                    }

                    // Update state and badges
                    pendingBillsState = pendingBillsState.filter(b => b.id !== currentMarkPaidBill.id);
                    const newCount = pendingBillsState.length;
                    if (pendingCountBadge) pendingCountBadge.textContent = newCount;
                    if (mobilePendingBadge) mobilePendingBadge.textContent = newCount;

                    if (newCount === 0) {
                        renderPendingBills();
                    }
                }
            } catch (err) {
                markPaidAlertText.textContent = err.message || 'Failed to update payment status.';
                markPaidAlert.classList.remove('d-none');
            } finally {
                setLoading(btnConfirmMarkPaid, false);
            }
        });
    }

    // =========================================================================
    // 6. Common Event Listeners & Public API
    // =========================================================================

    productsGrid.addEventListener('click', (e) => {
        const card = e.target.closest('.product-card-select');
        if (!card) return;
        const id = Number(card.dataset.id);
        const product = catalogProducts.find(p => p.id === id);
        if (product) addToCart(product);
    });

    cartItemsList.addEventListener('click', (e) => {
        const minusBtn = e.target.closest('.btn-cart-minus');
        if (minusBtn) { changeQty(minusBtn.dataset.key, -1); return; }

        const plusBtn = e.target.closest('.btn-cart-plus');
        if (plusBtn) { changeQty(plusBtn.dataset.key, 1); return; }

        const removeBtn = e.target.closest('.btn-cart-remove');
        if (removeBtn) { removeItem(removeBtn.dataset.key); return; }
    });

    btnClearCart.addEventListener('click', clearCart);

    searchInput.addEventListener('input', debounce(() => {
        clearSearchBtn.classList.toggle('d-none', searchInput.value.trim().length === 0);
        renderCatalogProducts();
    }, 150));

    clearSearchBtn.addEventListener('click', () => {
        searchInput.value = '';
        clearSearchBtn.classList.add('d-none');
        searchInput.focus();
        renderCatalogProducts();
    });

    categoryChipsContainer.addEventListener('click', (e) => {
        const chip = e.target.closest('.category-chip');
        if (!chip) return;
        selectedCategory = chip.dataset.category;
        renderCategoryChips();
        renderCatalogProducts();
    });

    discountTypeRadios.forEach(radio => {
        radio.addEventListener('change', () => {
            const type = radio.value;
            let val = parseFloat(discountValueInput.value) || 0.00;
            if (type === 'none') val = 0.00;
            applyDiscount(type, val);
            syncDiscountInputs();
        });
    });

    discountValueInput.addEventListener('input', debounce(() => {
        let val = parseFloat(discountValueInput.value) || 0.00;
        if (cartState.discount_type === 'percent' && val > 100) {
            val = 100;
            discountValueInput.value = 100;
        }
        applyDiscount(cartState.discount_type, val);
    }, 200));

    btnAddCustomItem.addEventListener('click', () => {
        customProductForm.reset();
        customItemQtyInput.value = 1;
        customProductModal.show();
        setTimeout(() => customItemNameInput.focus(), 300);
    });

    customProductForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const name = customItemNameInput.value.trim();
        const price = parseFloat(customItemPriceInput.value);
        const qty = parseInt(customItemQtyInput.value, 10);

        if (!name || isNaN(price) || price <= 0 || isNaN(qty) || qty <= 0) {
            toast('Please enter valid item details.', 'warning');
            return;
        }

        addCustomItem({ name, price, quantity: qty });
        customProductModal.hide();
    });

    if (mobileStickyViewCartBtn && tabBtnCart) {
        mobileStickyViewCartBtn.addEventListener('click', () => {
            const tabInstance = bootstrap.Tab.getOrCreateInstance(tabBtnCart);
            tabInstance.show();
        });
    }

    if (tabBtnCart && tabBtnProducts) {
        tabBtnCart.addEventListener('shown.bs.tab', () => {
            mobileStickyCartBar.classList.add('d-none');
        });
        tabBtnProducts.addEventListener('shown.bs.tab', () => {
            if (cartState.items.length > 0) {
                mobileStickyCartBar.classList.remove('d-none');
            }
        });
    }

    btnNextCheckout.addEventListener('click', openCheckout);

    // Public POS API
    window.POS.addToCart = addToCart;
    window.POS.changeQty = changeQty;
    window.POS.removeItem = removeItem;
    window.POS.addCustomItem = addCustomItem;
    window.POS.applyDiscount = applyDiscount;
    window.POS.calculateTotals = calculateTotals;
    window.POS.renderCart = renderCart;
    window.POS.clearCart = clearCart;
    window.POS.openCheckout = openCheckout;
    window.POS.loadPendingBills = loadPendingBills;

    window.POS.getCartPayload = function() {
        return {
            items: cartState.items.map(i => ({
                product_id: i.product_id,
                item_name: i.name,
                price: Number(i.price),
                quantity: Number(i.quantity),
                line_total: Number(i.line_total),
                is_custom: i.is_custom ? 1 : 0
            })),
            discount_type: cartState.discount_type,
            discount_value: Number(cartState.discount_value),
            discount_amount: Number(cartState.discount_amount),
            subtotal: Number(cartState.subtotal),
            total: Number(cartState.total)
        };
    };

    // Initial Load & Render
    calculateTotals();
    syncDiscountInputs();
    renderCart();
    loadCatalog();
    loadPendingBills();
});
