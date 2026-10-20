/**
 * Core Application JavaScript Helpers
 * PWA POS Application
 */

// ============================================================================
// 1. Fetch API Wrapper with CSRF & Error Handling
// ============================================================================
async function api(url, options = {}) {
    const defaultHeaders = {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
    };

    // Retrieve CSRF token from window.APP or meta tag
    const csrfToken = window.APP?.csrf || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (csrfToken) {
        defaultHeaders['X-CSRF-Token'] = csrfToken;
    }

    // Convert JavaScript object body to JSON string (unless FormData)
    if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
        defaultHeaders['Content-Type'] = 'application/json';
        options.body = JSON.stringify(options.body);
    }

    options.headers = {
        ...defaultHeaders,
        ...(options.headers || {})
    };

    try {
        const response = await fetch(url, options);

        // Session timeout / unauthorized
        if (response.status === 401) {
            toast('Session expired. Redirecting to login...', 'warning');
            setTimeout(() => {
                window.location.href = 'login.php';
            }, 1200);
            throw new Error('Unauthorized');
        }

        // Insufficient role permissions
        if (response.status === 403) {
            const errorJson = await response.json().catch(() => null);
            const msg = errorJson?.message || 'Access denied. You lack permissions for this action.';
            toast(msg, 'danger');
            throw new Error(msg);
        }

        const data = await response.json().catch(() => null);

        if (!response.ok || (data && data.success === false)) {
            const errorMsg = data?.message || `HTTP ${response.status}: Request failed.`;
            throw new Error(errorMsg);
        }

        return data;
    } catch (err) {
        console.error(`[API Error] ${url}:`, err);
        throw err;
    }
}

// ============================================================================
// 2. Toast Notification Helper (Bootstrap 5)
// ============================================================================
function toast(message, type = 'info', duration = 3500) {
    const container = document.getElementById('toastContainer');
    if (!container) return;

    const iconMap = {
        success: 'bi-check-circle-fill text-success',
        danger: 'bi-exclamation-octagon-fill text-danger',
        error: 'bi-exclamation-octagon-fill text-danger',
        warning: 'bi-exclamation-triangle-fill text-warning',
        info: 'bi-info-circle-fill text-primary'
    };

    const iconClass = iconMap[type] || iconMap.info;

    const toastEl = document.createElement('div');
    toastEl.className = 'toast align-items-center border-0 shadow-sm rounded-3 mb-2 bg-white';
    toastEl.setAttribute('role', 'alert');
    toastEl.setAttribute('aria-live', 'assertive');
    toastEl.setAttribute('aria-atomic', 'true');

    toastEl.innerHTML = `
        <div class="d-flex p-2">
            <div class="toast-body d-flex align-items-center gap-2 py-1 px-2 text-dark small">
                <i class="bi ${iconClass} fs-5 flex-shrink-0"></i>
                <div>${escapeHTML(message)}</div>
            </div>
            <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    `;

    container.appendChild(toastEl);
    const bsToast = new bootstrap.Toast(toastEl, { delay: duration });
    bsToast.show();

    toastEl.addEventListener('hidden.bs.toast', () => {
        toastEl.remove();
    });
}

// ============================================================================
// 3. Confirm Dialog Helper (Promise-based Bootstrap Modal)
// ============================================================================
function confirmDialog(options = {}) {
    return new Promise((resolve) => {
        const modalEl = document.getElementById('appConfirmModal');
        if (!modalEl) {
            resolve(confirm(options.message || 'Are you sure?'));
            return;
        }

        const titleEl = document.getElementById('confirmModalTitle');
        const msgEl = document.getElementById('confirmModalMessage');
        const iconEl = document.getElementById('confirmModalIcon');
        const confirmBtn = document.getElementById('confirmModalConfirmBtn');
        const cancelBtn = document.getElementById('confirmModalCancelBtn');

        const title = options.title || 'Are you sure?';
        const message = options.message || 'Do you want to proceed with this action?';
        const confirmText = options.confirmText || 'Confirm';
        const cancelText = options.cancelText || 'Cancel';
        const confirmBtnClass = options.confirmBtnClass || 'btn-primary';
        const iconClass = options.icon || 'bi-question-circle text-warning';

        titleEl.textContent = title;
        msgEl.textContent = message;
        iconEl.innerHTML = `<i class="bi ${iconClass} fs-1"></i>`;
        confirmBtn.textContent = confirmText;
        cancelBtn.textContent = cancelText;

        // Reset button classes
        confirmBtn.className = `btn ${confirmBtnClass} rounded-3 px-3 py-2 flex-grow-1`;

        const modal = new bootstrap.Modal(modalEl);
        let userConfirmed = false;

        const onConfirm = () => {
            userConfirmed = true;
            modal.hide();
        };

        confirmBtn.addEventListener('click', onConfirm, { once: true });

        modalEl.addEventListener('hidden.bs.modal', function handler() {
            modalEl.removeEventListener('hidden.bs.modal', handler);
            confirmBtn.removeEventListener('click', onConfirm);
            resolve(userConfirmed);
        }, { once: true });

        modal.show();
    });
}

// ============================================================================
// 4. Currency Formatting Helper (Indian Rupee INR)
// ============================================================================
function formatINR(amount) {
    const num = Number(amount) || 0;
    return '₹' + num.toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

// ============================================================================
// 5. Debounce Helper for Search & Inputs
// ============================================================================
function debounce(func, wait = 300) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// ============================================================================
// 6. Button Loading State Helper
// ============================================================================
function setLoading(button, isLoading, loadingText = 'Processing...') {
    if (!button) return;

    if (isLoading) {
        button.dataset.originalHtml = button.innerHTML;
        button.disabled = true;
        button.innerHTML = `<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> ${loadingText}`;
    } else {
        button.disabled = false;
        if (button.dataset.originalHtml) {
            button.innerHTML = button.dataset.originalHtml;
            delete button.dataset.originalHtml;
        }
    }
}

// ============================================================================
// 7. HTML Sanitizer / Escape Helper
// ============================================================================
function escapeHTML(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// ============================================================================
// 8. Offline & Online Event Handling
// ============================================================================
window.addEventListener('DOMContentLoaded', () => {
    const banner = document.getElementById('networkStatusBanner');
    if (!banner) return;

    function updateNetworkStatus() {
        if (!navigator.onLine) {
            banner.classList.remove('d-none');
            toast('You are now working offline.', 'warning');
        } else {
            if (!banner.classList.contains('d-none')) {
                banner.classList.add('d-none');
                toast('Internet connection restored.', 'success');
            }
        }
    }

    window.addEventListener('online', updateNetworkStatus);
    window.addEventListener('offline', updateNetworkStatus);

    // Initial check
    if (!navigator.onLine) {
        banner.classList.remove('d-none');
    }
});
