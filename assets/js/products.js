/**
 * Products Management Module JavaScript
 * Handles CRUD operations, filtering, modal validation, and DOM updates
 */

document.addEventListener('DOMContentLoaded', () => {
    // DOM Elements
    const productsContainer = document.getElementById('productsContainer');
    const loadingState = document.getElementById('productsLoadingState');
    const emptyState = document.getElementById('productsEmptyState');
    const emptyStateSubtext = document.getElementById('emptyStateSubtext');
    const productCountBadge = document.getElementById('productCountBadge');
    const searchInput = document.getElementById('productSearchInput');
    const clearSearchBtn = document.getElementById('clearSearchBtn');
    const categorySelect = document.getElementById('categorySelectFilter');
    const showInactiveToggle = document.getElementById('showInactiveToggle');
    const btnOpenAddModal = document.getElementById('btnOpenAddModal');
    const emptyStateAddBtn = document.getElementById('emptyStateAddBtn');
    
    // Modal Elements
    const productModalEl = document.getElementById('productModal');
    const productModal = new bootstrap.Modal(productModalEl);
    const productModalTitle = document.getElementById('productModalTitle');
    const productForm = document.getElementById('productForm');
    const modalAlert = document.getElementById('modalAlert');
    const modalAlertText = document.getElementById('modalAlertText');
    const productIdInput = document.getElementById('productId');
    const productNameInput = document.getElementById('productName');
    const productPriceInput = document.getElementById('productPrice');
    const productCategoryInput = document.getElementById('productCategory');
    const productIsActiveInput = document.getElementById('productIsActive');
    const categoryDatalist = document.getElementById('categoryDatalist');
    const btnSaveProduct = document.getElementById('btnSaveProduct');

    // State
    let productsList = [];
    let categoriesList = [];

    // =========================================================================
    // 1. Fetch Products from Server
    // =========================================================================
    async function loadProducts() {
        showLoading(true);
        try {
            const includeInactive = showInactiveToggle.checked ? '1' : '0';
            const response = await api(`api/products.php?action=list&include_inactive=${includeInactive}`);
            
            if (response && response.success) {
                productsList = response.data.products || [];
                categoriesList = response.data.categories || [];
                
                updateCategoryDropdowns();
                renderProducts();
            }
        } catch (error) {
            toast(error.message || 'Failed to load products list.', 'danger');
        } finally {
            showLoading(false);
        }
    }

    // =========================================================================
    // 2. Render Products Cards Grid
    // =========================================================================
    function renderProducts() {
        const searchTerm = (searchInput.value || '').trim().toLowerCase();
        const selectedCategory = categorySelect.value || 'all';

        // Filter products based on search term and category
        const filtered = productsList.filter(item => {
            const matchesSearch = item.name.toLowerCase().includes(searchTerm) || 
                                  item.category.toLowerCase().includes(searchTerm);
            const matchesCategory = (selectedCategory === 'all') || (item.category === selectedCategory);
            return matchesSearch && matchesCategory;
        });

        productCountBadge.textContent = filtered.length;

        if (filtered.length === 0) {
            productsContainer.classList.add('d-none');
            emptyState.classList.remove('d-none');
            
            if (searchTerm || selectedCategory !== 'all') {
                emptyStateSubtext.textContent = `No products found matching your current filter criteria.`;
            } else {
                emptyStateSubtext.textContent = 'Your catalog is currently empty. Add your first item to begin sales.';
            }
            return;
        }

        emptyState.classList.add('d-none');
        productsContainer.classList.remove('d-none');

        productsContainer.innerHTML = filtered.map(item => `
            <div class="col-12 col-md-6 col-lg-4" id="product-card-${item.id}">
                <div class="card h-100 border-0 shadow-sm rounded-4 p-3 position-relative ${!item.is_active ? 'opacity-75 bg-slate-100' : 'bg-white'}">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <div>
                            <span class="badge bg-secondary-subtle text-secondary small rounded-pill px-2 py-1 mb-1">
                                ${escapeHTML(item.category)}
                            </span>
                            <h6 class="fw-bold text-dark mb-0 text-truncate" title="${escapeHTML(item.name)}">
                                ${escapeHTML(item.name)}
                            </h6>
                        </div>
                        <div class="text-end">
                            <span class="fs-5 fw-bold text-primary amount-text">
                                ${formatINR(item.price)}
                            </span>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top border-slate-100">
                        <!-- Active / Inactive Switch -->
                        <div class="form-check form-switch mb-0 d-flex align-items-center gap-1">
                            <input class="form-check-input mt-0 btn-toggle-active" type="checkbox" role="switch" 
                                   id="toggle-${item.id}" data-id="${item.id}" ${item.is_active ? 'checked' : ''}>
                            <label class="form-check-label small user-select-none ${item.is_active ? 'text-success fw-medium' : 'text-muted'}" for="toggle-${item.id}">
                                ${item.is_active ? 'Active' : 'Inactive'}
                            </label>
                        </div>

                        <!-- Card Action Buttons -->
                        <div class="btn-group gap-1">
                            <button type="button" class="btn btn-light btn-sm rounded-2 text-primary p-2 btn-edit-product" 
                                    data-id="${item.id}" title="Edit Product" style="min-width: 44px; min-height: 44px;">
                                <i class="bi bi-pencil-square fs-6"></i>
                            </button>
                            <button type="button" class="btn btn-light btn-sm rounded-2 text-danger p-2 btn-delete-product" 
                                    data-id="${item.id}" data-name="${escapeHTML(item.name)}" title="Delete Product" style="min-width: 44px; min-height: 44px;">
                                <i class="bi bi-trash3 fs-6"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `).join('');
    }

    // =========================================================================
    // 3. Update Category Filter Dropdown & Modal Datalist
    // =========================================================================
    function updateCategoryDropdowns() {
        const currentSelected = categorySelect.value;
        
        // Populate filter dropdown
        categorySelect.innerHTML = `<option value="all">All Categories (${categoriesList.length})</option>` + 
            categoriesList.map(cat => `<option value="${escapeHTML(cat)}">${escapeHTML(cat)}</option>`).join('');
            
        if (categoriesList.includes(currentSelected)) {
            categorySelect.value = currentSelected;
        } else {
            categorySelect.value = 'all';
        }

        // Populate modal datalist
        categoryDatalist.innerHTML = categoriesList.map(cat => `<option value="${escapeHTML(cat)}">`).join('');
    }

    // =========================================================================
    // 4. Modal Open Handlers (Add vs Edit)
    // =========================================================================
    function openAddModal() {
        resetModalForm();
        productModalTitle.textContent = 'Add New Product';
        btnSaveProduct.textContent = 'Create Product';
        productIsActiveInput.checked = true;
        productModal.show();
        setTimeout(() => productNameInput.focus(), 300);
    }

    function openEditModal(productId) {
        resetModalForm();
        const item = productsList.find(p => p.id === productId);
        if (!item) return;

        productModalTitle.textContent = 'Edit Product';
        btnSaveProduct.textContent = 'Update Product';
        productIdInput.value = item.id;
        productNameInput.value = item.name;
        productPriceInput.value = Number(item.price).toFixed(2);
        productCategoryInput.value = item.category;
        productIsActiveInput.checked = item.is_active === 1;

        productModal.show();
        setTimeout(() => productNameInput.focus(), 300);
    }

    function resetModalForm() {
        productForm.reset();
        productForm.classList.remove('was-validated');
        productIdInput.value = '';
        modalAlert.classList.add('d-none');
        modalAlertText.textContent = '';
    }

    // =========================================================================
    // 5. Submit Form (Create / Update Product)
    // =========================================================================
    productForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        const id = productIdInput.value ? Number(productIdInput.value) : null;
        const name = (productNameInput.value || '').trim();
        const price = parseFloat(productPriceInput.value);
        const category = (productCategoryInput.value || '').trim();
        const isActive = productIsActiveInput.checked ? 1 : 0;

        // Client-side validation checks
        if (!name || isNaN(price) || price <= 0 || !category) {
            productForm.classList.add('was-validated');
            showModalError('Please ensure all required fields are filled with valid values.');
            return;
        }

        setLoading(btnSaveProduct, true, id ? 'Updating...' : 'Saving...');
        modalAlert.classList.add('d-none');

        try {
            const action = id ? 'update' : 'create';
            const payload = {
                id,
                name,
                price,
                category,
                is_active: isActive
            };

            const response = await api(`api/products.php?action=${action}`, {
                method: 'POST',
                body: payload
            });

            if (response && response.success) {
                toast(response.message || (id ? 'Product updated successfully.' : 'Product created successfully.'), 'success');
                productModal.hide();
                await loadProducts();
            }
        } catch (error) {
            showModalError(error.message || 'Operation failed. Please try again.');
        } finally {
            setLoading(btnSaveProduct, false);
        }
    });

    function showModalError(msg) {
        modalAlertText.textContent = msg;
        modalAlert.classList.remove('d-none');
    }

    // =========================================================================
    // 6. Inline Status Toggle & Delete Handlers
    // =========================================================================
    productsContainer.addEventListener('click', async (e) => {
        // Toggle Active status directly from card switch
        const toggleSwitch = e.target.closest('.btn-toggle-active');
        if (toggleSwitch) {
            const id = Number(toggleSwitch.dataset.id);
            try {
                const res = await api('api/products.php?action=toggle_active', {
                    method: 'POST',
                    body: { id }
                });
                
                if (res && res.success) {
                    toast(res.message, 'success');
                    // Update local state
                    const item = productsList.find(p => p.id === id);
                    if (item) {
                        item.is_active = res.data.is_active;
                    }
                    renderProducts();
                }
            } catch (err) {
                // Revert switch on failure
                toggleSwitch.checked = !toggleSwitch.checked;
                toast(err.message || 'Failed to update product status.', 'danger');
            }
            return;
        }

        // Edit Button Click
        const editBtn = e.target.closest('.btn-edit-product');
        if (editBtn) {
            const id = Number(editBtn.dataset.id);
            openEditModal(id);
            return;
        }

        // Delete Button Click
        const deleteBtn = e.target.closest('.btn-delete-product');
        if (deleteBtn) {
            const id = Number(deleteBtn.dataset.id);
            const name = deleteBtn.dataset.name;

            const confirmed = await confirmDialog({
                title: 'Delete Product',
                message: `Are you sure you want to delete "${name}"? Historical bills will preserve this item's details.`,
                confirmText: 'Delete Product',
                confirmBtnClass: 'btn-danger',
                icon: 'bi-trash3 text-danger'
            });

            if (confirmed) {
                try {
                    const res = await api('api/products.php?action=delete', {
                        method: 'POST',
                        body: { id }
                    });

                    if (res && res.success) {
                        toast(res.message, 'success');
                        productsList = productsList.filter(p => p.id !== id);
                        renderProducts();
                    }
                } catch (err) {
                    toast(err.message || 'Failed to delete product.', 'danger');
                }
            }
        }
    });

    // =========================================================================
    // 7. Event Listeners for Filters & Modals
    // =========================================================================
    btnOpenAddModal.addEventListener('click', openAddModal);
    emptyStateAddBtn.addEventListener('click', openAddModal);

    // Live search input with debounce
    searchInput.addEventListener('input', debounce(() => {
        if (searchInput.value.trim().length > 0) {
            clearSearchBtn.classList.remove('d-none');
        } else {
            clearSearchBtn.classList.add('d-none');
        }
        renderProducts();
    }, 200));

    clearSearchBtn.addEventListener('click', () => {
        searchInput.value = '';
        clearSearchBtn.classList.add('d-none');
        searchInput.focus();
        renderProducts();
    });

    categorySelect.addEventListener('change', renderProducts);

    showInactiveToggle.addEventListener('change', () => {
        loadProducts();
    });

    function showLoading(isLoading) {
        if (isLoading) {
            loadingState.classList.remove('d-none');
            productsContainer.classList.add('d-none');
            emptyState.classList.add('d-none');
        } else {
            loadingState.classList.add('d-none');
        }
    }

    // Initial load
    loadProducts();
});
