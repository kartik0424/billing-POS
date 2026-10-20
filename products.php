<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

// Strict server-side role enforcement: Admin Only
require_role('admin');

$pageTitle = 'Products';
$pageScripts = ['assets/js/products.js'];

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-0">
    <!-- Top Action Bar -->
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-3">
        <div>
            <h4 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                <span>Products Management</span>
                <span class="badge bg-primary-subtle text-primary rounded-pill fs-6" id="productCountBadge">0</span>
            </h4>
            <p class="text-muted small mb-0">Create, update, and manage your inventory catalog</p>
        </div>
        <div>
            <button type="button" class="btn btn-primary fw-semibold rounded-3 px-3 btn-touch shadow-sm w-100 w-sm-auto" id="btnOpenAddModal">
                <i class="bi bi-plus-lg me-1"></i> Add Product
            </button>
        </div>
    </div>

    <!-- Filters & Search Toolbar -->
    <div class="card border-0 shadow-sm rounded-4 mb-3">
        <div class="card-body p-3">
            <div class="row g-2 align-items-center">
                <!-- Search Input -->
                <div class="col-12 col-md-5">
                    <div class="input-group">
                        <span class="input-group-text bg-slate-50 border-end-0 text-muted">
                            <i class="bi bi-search"></i>
                        </span>
                        <input type="text" id="productSearchInput" class="form-control bg-slate-50 border-start-0 ps-0" placeholder="Search by name or category..." autocomplete="off">
                        <button class="btn btn-link text-muted border-start-0 text-decoration-none d-none" type="button" id="clearSearchBtn">
                            <i class="bi bi-x-circle-fill"></i>
                        </button>
                    </div>
                </div>

                <!-- Category Filter Pills / Dropdown -->
                <div class="col-12 col-md-4">
                    <select id="categorySelectFilter" class="form-select bg-slate-50">
                        <option value="all">All Categories</option>
                    </select>
                </div>

                <!-- Inactive Items Toggle -->
                <div class="col-12 col-md-3">
                    <div class="form-check form-switch d-flex align-items-center gap-2 ps-0 justify-content-md-end">
                        <input class="form-check-input ms-0 mt-0" type="checkbox" id="showInactiveToggle" checked>
                        <label class="form-check-label small fw-semibold text-secondary user-select-none" for="showInactiveToggle">
                            Show Inactive Items
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading State -->
    <div id="productsLoadingState" class="text-center py-5">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading products...</span>
        </div>
        <p class="text-muted small mt-2">Loading products catalog...</p>
    </div>

    <!-- Empty State -->
    <div id="productsEmptyState" class="card border-0 shadow-sm rounded-4 text-center py-5 px-3 d-none">
        <div class="card-body">
            <div class="text-muted mb-3">
                <i class="bi bi-box-seam fs-1 text-secondary opacity-50"></i>
            </div>
            <h5 class="fw-bold text-dark mb-1">No Products Found</h5>
            <p class="text-muted small mb-3" id="emptyStateSubtext">No items match your selected filters. Try searching with different keywords or add a new item.</p>
            <button type="button" class="btn btn-primary rounded-3 px-4 py-2" id="emptyStateAddBtn">
                <i class="bi bi-plus-circle me-1"></i> Add First Product
            </button>
        </div>
    </div>

    <!-- Products Card Grid Container -->
    <div id="productsContainer" class="row g-3 d-none">
        <!-- Injected dynamically by assets/js/products.js -->
    </div>
</div>

<!-- Add / Edit Product Modal -->
<div class="modal fade" id="productModal" tabindex="-1" aria-labelledby="productModalTitle" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <form id="productForm" novalidate autocomplete="off">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold" id="productModalTitle">Add Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <!-- Inline Validation Error Alert -->
                    <div id="modalAlert" class="alert alert-danger py-2 px-3 small d-none mb-3" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <span id="modalAlertText"></span>
                    </div>

                    <input type="hidden" id="productId" name="id" value="">

                    <!-- Product Name -->
                    <div class="mb-3">
                        <label for="productName" class="form-label small fw-semibold text-secondary">Product Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="productName" name="name" required maxlength="150" placeholder="e.g. Masala Chai">
                        <div class="invalid-feedback">Please enter a valid product name.</div>
                    </div>

                    <!-- Price & Category Grid -->
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-sm-6">
                            <label for="productPrice" class="form-label small fw-semibold text-secondary">Price (INR) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-white text-muted">₹</span>
                                <input type="number" class="form-control ps-2" id="productPrice" name="price" step="0.01" min="0.01" max="9999999.99" required placeholder="0.00">
                                <div class="invalid-feedback">Enter a price greater than ₹0.00.</div>
                            </div>
                        </div>

                        <div class="col-12 col-sm-6">
                            <label for="productCategory" class="form-label small fw-semibold text-secondary">Category <span class="text-danger">*</span></label>
                            <input class="form-control" list="categoryDatalist" id="productCategory" name="category" required maxlength="50" placeholder="Select or type...">
                            <datalist id="categoryDatalist">
                                <!-- Populated dynamically from distinct DB categories -->
                            </datalist>
                            <div class="invalid-feedback">Please specify a category.</div>
                        </div>
                    </div>

                    <!-- Is Active Switch -->
                    <div class="card bg-slate-50 border-0 rounded-3 p-3 mt-2">
                        <div class="form-check form-switch d-flex align-items-center justify-content-between ps-0 mb-0">
                            <div>
                                <label class="form-check-label fw-semibold text-dark mb-0 user-select-none" for="productIsActive">
                                    Product Active
                                </label>
                                <div class="text-muted small">Active items are available on cashier billing screens</div>
                            </div>
                            <input class="form-check-input ms-0 mt-0 fs-5" type="checkbox" id="productIsActive" name="is_active" checked>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-top-0 pt-0 px-4 pb-4">
                    <button type="button" class="btn btn-light rounded-3 px-3 py-2 btn-touch" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4 py-2 btn-touch fw-semibold" id="btnSaveProduct">
                        Save Product
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
?>
