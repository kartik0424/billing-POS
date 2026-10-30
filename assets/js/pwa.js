/**
 * QuickPOS Progressive Web App Controller & Offline IndexedDB Sync Manager
 * 
 * Features:
 * 1. Service Worker lifecycle management & update notifications.
 * 2. beforeinstallprompt handling & custom "Install App" button.
 * 3. iOS Safari "Add to Home Screen" instructional banner.
 * 4. Online/offline connectivity detection & network status banner.
 * 5. Offline Billing Queue (IndexedDB) with client-UUID idempotency & auto-sync.
 */

(function () {
    'use strict';

    let deferredInstallPrompt = null;
    let swRegistration = null;

    const DB_NAME = 'QuickPOS_OfflineDB';
    const DB_VERSION = 1;
    const STORE_NAME = 'offline_bills';

    // ========================================================================
    // 1. IndexedDB Helper for Offline Billing Queue
    // ========================================================================
    function openDB() {
        return new Promise((resolve, reject) => {
            if (!window.indexedDB) {
                reject(new Error('IndexedDB is not supported on this device.'));
                return;
            }

            const request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = (e) => {
                const db = e.target.result;
                if (!db.objectStoreNames.contains(STORE_NAME)) {
                    const store = db.createObjectStore(STORE_NAME, { keyPath: 'client_uuid' });
                    store.createIndex('created_at', 'created_at', { unique: false });
                    store.createIndex('status', 'status', { unique: false });
                }
            };

            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Generate a cryptographic UUIDv4 for offline transaction deduplication
     */
    function generateUUID() {
        if (typeof crypto !== 'undefined' && crypto.randomUUID) {
            return crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            const r = (Math.random() * 16) | 0;
            const v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    /**
     * Save a bill payload locally into IndexedDB when offline
     */
    async function queueBillLocally(billPayload) {
        const db = await openDB();
        const clientUuid = billPayload.client_uuid || generateUUID();
        billPayload.client_uuid = clientUuid;

        const queueItem = {
            client_uuid: clientUuid,
            payload: billPayload,
            created_at: new Date().toISOString(),
            status: 'queued',
            retry_count: 0,
            customer_name: billPayload.customer_name || 'Walk-in',
            total: billPayload.items?.reduce((sum, i) => sum + (Number(i.price) * Number(i.quantity)), 0) || 0
        };

        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_NAME, 'readwrite');
            const store = tx.objectStore(STORE_NAME);
            const req = store.put(queueItem);

            req.onsuccess = () => {
                updateOfflineSyncIndicator();
                toast('Offline Mode: Bill queued locally. It will auto-sync once online.', 'warning', 5000);
                resolve(queueItem);
            };

            req.onerror = () => reject(req.error);
        });
    }

    /**
     * Retrieve all queued bills awaiting synchronization
     */
    async function getQueuedBills() {
        try {
            const db = await openDB();
            return new Promise((resolve, reject) => {
                const tx = db.transaction(STORE_NAME, 'readonly');
                const store = tx.objectStore(STORE_NAME);
                const req = store.getAll();

                req.onsuccess = () => resolve(req.result || []);
                req.onerror = () => reject(req.error);
            });
        } catch (e) {
            return [];
        }
    }

    /**
     * Remove a bill from the offline queue after successful server synchronization
     */
    async function removeQueuedBill(clientUuid) {
        try {
            const db = await openDB();
            return new Promise((resolve, reject) => {
                const tx = db.transaction(STORE_NAME, 'readwrite');
                const store = tx.objectStore(STORE_NAME);
                const req = store.delete(clientUuid);

                req.onsuccess = () => {
                    updateOfflineSyncIndicator();
                    resolve(true);
                };
                req.onerror = () => reject(req.error);
            });
        } catch (e) {
            console.error('Failed to remove queued bill:', e);
        }
    }

    /**
     * Synchronize all locally queued bills to the server
     */
    async function syncOfflineBills() {
        if (!navigator.onLine) return;

        const queuedBills = await getQueuedBills();
        if (queuedBills.length === 0) {
            updateOfflineSyncIndicator();
            return;
        }

        toast(`Syncing ${queuedBills.length} offline bill(s)...`, 'info', 2500);

        for (const item of queuedBills) {
            try {
                // Dispatch to api/bills.php?action=create with client_uuid for idempotency
                const response = await api('api/bills.php?action=create', {
                    method: 'POST',
                    body: item.payload
                });

                if (response && response.success) {
                    await removeQueuedBill(item.client_uuid);
                    toast(`Bill synced: ${response.data?.bill?.bill_no || 'Success'}`, 'success', 3000);
                } else if (response && response.message && response.message.includes('already')) {
                    // Duplicate already recorded on server; safe to remove from queue
                    await removeQueuedBill(item.client_uuid);
                }
            } catch (err) {
                console.error('[Sync Error] Could not sync bill:', item.client_uuid, err);
            }
        }

        updateOfflineSyncIndicator();
    }

    /**
     * Update the visible "X bills waiting to sync" indicator in the UI
     */
    async function updateOfflineSyncIndicator() {
        const queued = await getQueuedBills();
        const count = queued.length;

        const syncBadges = document.querySelectorAll('.offline-sync-indicator');
        syncBadges.forEach(el => {
            if (count > 0) {
                el.classList.remove('d-none');
                el.innerHTML = `<i class="bi bi-cloud-arrow-up me-1"></i> ${count}<span class="d-none d-sm-inline ms-1">bill${count > 1 ? 's' : ''} queued</span>`;
            } else {
                el.classList.add('d-none');
            }
        });
    }

    // ========================================================================
    // 2. Service Worker Registration & Update Lifecycle
    // ========================================================================
    function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) return;

        window.addEventListener('load', async () => {
            try {
                swRegistration = await navigator.serviceWorker.register('service-worker.js', { scope: './' });

                // Detect new service worker waiting
                if (swRegistration.waiting) {
                    showUpdateNotification(swRegistration.waiting);
                }

                swRegistration.addEventListener('updatefound', () => {
                    const newWorker = swRegistration.installing;
                    if (!newWorker) return;

                    newWorker.addEventListener('statechange', () => {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            showUpdateNotification(newWorker);
                        }
                    });
                });
            } catch (err) {
                console.warn('[SW Registration Failed]', err);
            }
        });

        // Reload page when the active service worker controller changes
        let refreshing = false;
        navigator.serviceWorker.addEventListener('controllerchange', () => {
            if (!refreshing) {
                refreshing = true;
                window.location.reload();
            }
        });
    }

    /**
     * Show notification when a new version of the app is available
     */
    function showUpdateNotification(worker) {
        const container = document.getElementById('toastContainer');
        if (!container) return;

        const toastEl = document.createElement('div');
        toastEl.className = 'toast align-items-center border-0 shadow-lg rounded-3 mb-2 bg-dark text-white show';
        toastEl.setAttribute('role', 'alert');
        toastEl.setAttribute('data-bs-autohide', 'false');

        toastEl.innerHTML = `
            <div class="d-flex p-2 align-items-center">
                <div class="toast-body d-flex align-items-center gap-2 py-1 px-2 small">
                    <i class="bi bi-arrow-repeat fs-5 text-primary"></i>
                    <div>
                        <div class="fw-bold">Update Available</div>
                        <div class="text-white-50" style="font-size: 0.75rem;">A new version of QuickPOS is ready.</div>
                    </div>
                </div>
                <button type="button" class="btn btn-primary btn-sm rounded-pill px-3 py-1 me-2 ms-auto fw-medium" id="btnApplyUpdate">
                    Refresh
                </button>
            </div>
        `;

        container.appendChild(toastEl);

        const btnApply = toastEl.querySelector('#btnApplyUpdate');
        btnApply.addEventListener('click', () => {
            worker.postMessage({ action: 'SKIP_WAITING' });
            toastEl.remove();
        });
    }

    // ========================================================================
    // 3. PWA "Install App" Button & iOS Add to Home Screen Banner
    // ========================================================================
    function initInstallPrompt() {
        const btnInstallList = document.querySelectorAll('.btn-install-pwa');

        window.addEventListener('beforeinstallprompt', (e) => {
            // Prevent Chrome 76+ from automatically showing prompt
            e.preventDefault();
            deferredInstallPrompt = e;

            // Show custom install buttons
            btnInstallList.forEach(btn => {
                btn.classList.remove('d-none');
                btn.addEventListener('click', async () => {
                    if (!deferredInstallPrompt) return;

                    deferredInstallPrompt.prompt();
                    const choice = await deferredInstallPrompt.userChoice;

                    if (choice.outcome === 'accepted') {
                        btnInstallList.forEach(b => b.classList.add('d-none'));
                    }
                    deferredInstallPrompt = null;
                });
            });
        });

        window.addEventListener('appinstalled', () => {
            deferredInstallPrompt = null;
            btnInstallList.forEach(btn => btn.classList.add('d-none'));
            toast('QuickPOS installed successfully!', 'success');
        });

        // iOS Safari Instructions Banner
        checkIOSPrompt();
    }

    /**
     * Check if user is on iOS Safari and not in standalone mode, then show instruction banner
     */
    function checkIOSPrompt() {
        const isIOS = /iphone|ipad|ipod/.test(window.navigator.userAgent.toLowerCase());
        const isStandalone = window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;

        if (isIOS && !isStandalone) {
            const hasSeenBanner = sessionStorage.getItem('pos_ios_banner_dismissed');
            if (hasSeenBanner) return;

            const banner = document.createElement('div');
            banner.id = 'iosInstallBanner';
            banner.className = 'position-fixed bottom-0 start-0 end-0 bg-white border-top shadow-lg p-3 d-flex align-items-center justify-content-between gap-2';
            banner.style.zIndex = '1080';
            banner.style.marginBottom = 'var(--bottom-nav-height, 60px)';

            banner.innerHTML = `
                <div class="d-flex align-items-center gap-2 small">
                    <span class="fs-4 text-primary"><i class="bi bi-phone"></i></span>
                    <div>
                        <strong>Install QuickPOS on iOS:</strong><br>
                        Tap <i class="bi bi-box-arrow-up text-primary"></i> Share below, then select <strong>'Add to Home Screen'</strong>.
                    </div>
                </div>
                <button type="button" class="btn-close btn-sm flex-shrink-0" aria-label="Close" id="btnCloseIosBanner"></button>
            `;

            document.body.appendChild(banner);

            document.getElementById('btnCloseIosBanner')?.addEventListener('click', () => {
                banner.remove();
                sessionStorage.setItem('pos_ios_banner_dismissed', 'true');
            });
        }
    }

    // ========================================================================
    // 4. Online / Offline Connectivity Listeners
    // ========================================================================
    function initConnectivityListeners() {
        const updateNetworkBanner = () => {
            const banner = document.getElementById('networkStatusBanner');
            if (!banner) return;

            if (navigator.onLine) {
                banner.classList.add('d-none');
                syncOfflineBills();
            } else {
                banner.classList.remove('d-none');
            }
        };

        window.addEventListener('online', () => {
            toast('Internet connection restored. Synchronizing...', 'success', 3000);
            updateNetworkBanner();
        });

        window.addEventListener('offline', () => {
            toast('You are now offline. POS is operating in local queue mode.', 'warning', 4000);
            updateNetworkBanner();
        });

        updateNetworkBanner();
        updateOfflineSyncIndicator();
    }

    // ========================================================================
    // 5. Expose Public PWA API
    // ========================================================================
    window.PWA = {
        queueBill: queueBillLocally,
        syncQueue: syncOfflineBills,
        getQueuedBills,
        updateSyncIndicator: updateOfflineSyncIndicator,
        isOnline: () => navigator.onLine,
        generateUUID
    };

    // Auto-initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', () => {
        registerServiceWorker();
        initInstallPrompt();
        initConnectivityListeners();
    });

})();
