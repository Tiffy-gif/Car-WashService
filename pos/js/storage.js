/**
 * POS Local & Offline Storage Manager
 * Handles caching of catalog, customers, user sessions,
 * pending offline sync queue, and local transaction logs.
 */
class PosStorage {
    static KEYS = {
        USER: 'cw_pos_staff_user',
        CATALOG: 'cw_pos_catalog_cache',
        CATALOG_UPDATED_AT: 'cw_pos_catalog_updated_at',
        CUSTOMERS: 'cw_pos_customers_cache',
        CUSTOMERS_UPDATED_AT: 'cw_pos_customers_updated_at',
        PENDING_ORDERS: 'cw_pos_pending_orders',
        SYNCED_ORDERS: 'cw_pos_synced_orders',
        LANGUAGE: 'cw_pos_language',
        SHIFT: 'cw_pos_active_shift',
        TODAY_ORDERS: 'cw_pos_today_orders_cache',
        DRAWER_COUNTS: 'cw_pos_drawer_counts',
        SETTINGS: 'cw_pos_settings_cache',
        LOGO_DATA_URI: 'cw_pos_logo_data_uri',
        KHQR_DATA_URI: 'cw_pos_khqr_data_uri',
        SHOW_PICTURES: 'cw_pos_show_pictures',
        THEME: 'cw_pos_theme',
        OFFLINE_STATUS_QUEUE: 'cw_pos_offline_status_queue',
        OIL_REMINDERS_CACHE: 'cw_pos_oil_reminders_cache',
        OIL_REMINDERS_UPDATED_AT: 'cw_pos_oil_reminders_updated_at',
        DAILY_CLOSE_STATUS: 'cw_pos_daily_close_status',
        LAYOUT: 'cw_pos_layout_device',
        CURRENT_SHIFT: 'cw_pos_current_shift',
        PENDING_RECEIPT_ACKS: 'cw_pos_pending_receipt_acks'
    };

    /**
     * Safe localStorage wrapper that catches quota errors and displays a user toast.
     */
    static setItem(key, value) {
        try {
            localStorage.setItem(key, value);
            return true;
        } catch (e) {
            const isQuota = (
                e.name === 'QuotaExceededError' ||
                e.name === 'NS_ERROR_DOM_QUOTA_REACHED' ||
                e.code === 22 ||
                e.code === 1014
            );
            console.error(`PosStorage: Failed to write "${key}" to localStorage:`, e);
            if (isQuota) {
                if (typeof PosApp !== 'undefined' && typeof PosApp.showToastNotice === 'function') {
                    PosApp.showToastNotice('Storage full! Browser storage quota exceeded. Some offline data could not be saved.', 'danger');
                }
            }
            return false;
        }
    }

    // User Session & Token
    static getSessionToken() {
        try {
            return sessionStorage.getItem('cw_pos_session_token') || localStorage.getItem('cw_pos_session_token') || null;
        } catch (e) {
            return null;
        }
    }

    static setSessionToken(token) {
        try {
            if (token) {
                sessionStorage.setItem('cw_pos_session_token', token);
                localStorage.setItem('cw_pos_session_token', token);
            } else {
                sessionStorage.removeItem('cw_pos_session_token');
                localStorage.removeItem('cw_pos_session_token');
            }
        } catch (e) {
            console.error('Failed to update session token in storage:', e);
        }
    }

    static getUser() {
        try {
            const data = localStorage.getItem(this.KEYS.USER);
            return data ? JSON.parse(data) : null;
        } catch (e) {
            return null;
        }
    }

    static setUser(user) {
        if (user) {
            // Strip any raw token from persistent localStorage user object
            const userToStore = { ...user };
            delete userToStore.session_token;
            this.setItem(this.KEYS.USER, JSON.stringify(userToStore));
        } else {
            localStorage.removeItem(this.KEYS.USER);
            this.setSessionToken(null);
        }
    }

    static clearUser() {
        try {
            localStorage.removeItem(this.KEYS.USER);
        } catch (e) {}
        this.setSessionToken(null);
    }

    // Catalog Cache (Services, Prices, Products, Categories)
    static getCatalog() {
        try {
            const data = localStorage.getItem(this.KEYS.CATALOG);
            return data ? JSON.parse(data) : null;
        } catch (e) {
            return null;
        }
    }

    static getCategories() {
        const catalog = this.getCatalog();
        return (catalog && Array.isArray(catalog.categories)) ? catalog.categories : [];
    }

    static setCatalog(catalog, timestamp = null) {
        this.setItem(this.KEYS.CATALOG, JSON.stringify(catalog));
        const time = timestamp || new Date().toISOString();
        this.setItem(this.KEYS.CATALOG_UPDATED_AT, time);
    }

    static getCatalogUpdatedAt() {
        return localStorage.getItem(this.KEYS.CATALOG_UPDATED_AT);
    }

    // Customers Cache
    static getCustomers() {
        try {
            const data = localStorage.getItem(this.KEYS.CUSTOMERS);
            return data ? JSON.parse(data) : { customers: [], vehicles: [] };
        } catch (e) {
            return { customers: [], vehicles: [] };
        }
    }

    static setCustomers(customersData) {
        this.setItem(this.KEYS.CUSTOMERS, JSON.stringify(customersData));
        const time = customersData?.synced_at || new Date().toISOString();
        this.setItem(this.KEYS.CUSTOMERS_UPDATED_AT, time);
    }

    static getCustomersLastUpdated() {
        const direct = localStorage.getItem(this.KEYS.CUSTOMERS_UPDATED_AT);
        if (direct) return direct;
        const data = this.getCustomers();
        return data?.synced_at || null;
    }

    // Pending Offline Orders Queue
    static getPendingOrders() {
        try {
            const data = localStorage.getItem(this.KEYS.PENDING_ORDERS);
            return data ? JSON.parse(data) : [];
        } catch (e) {
            return [];
        }
    }

    static addPendingOrder(order) {
        const queue = this.getPendingOrders();
        // Prevent duplicate by idempotency_key
        const existingIdx = queue.findIndex(o => o.idempotency_key === order.idempotency_key);
        if (existingIdx >= 0) {
            queue[existingIdx] = order;
        } else {
            queue.push(order);
        }
        this.setItem(this.KEYS.PENDING_ORDERS, JSON.stringify(queue));
        return queue.length;
    }

    static removePendingOrder(idempotencyKey) {
        const queue = this.getPendingOrders().filter(o => o.idempotency_key !== idempotencyKey);
        this.setItem(this.KEYS.PENDING_ORDERS, JSON.stringify(queue));
        return queue.length;
    }

    static clearPendingOrders() {
        this.setItem(this.KEYS.PENDING_ORDERS, JSON.stringify([]));
    }

    // Pending Electronic Receipt Acknowledgements Queue
    static getPendingReceiptAcks() {
        try {
            const data = localStorage.getItem(this.KEYS.PENDING_RECEIPT_ACKS);
            return data ? JSON.parse(data) : [];
        } catch (e) {
            return [];
        }
    }

    static addPendingReceiptAck(orderId, orderNumber) {
        const queue = this.getPendingReceiptAcks();
        const existingIdx = queue.findIndex(a => (orderNumber && a.order_number === orderNumber) || (orderId && a.order_id === orderId));
        const item = { order_id: orderId, order_number: orderNumber, queued_at: new Date().toISOString() };
        if (existingIdx >= 0) {
            queue[existingIdx] = item;
        } else {
            queue.push(item);
        }
        this.setItem(this.KEYS.PENDING_RECEIPT_ACKS, JSON.stringify(queue));
        return queue.length;
    }

    static removePendingReceiptAck(orderNumber, orderId = null) {
        const queue = this.getPendingReceiptAcks().filter(a => {
            if (orderNumber && a.order_number === orderNumber) return false;
            if (orderId && a.order_id === orderId) return false;
            return true;
        });
        this.setItem(this.KEYS.PENDING_RECEIPT_ACKS, JSON.stringify(queue));
        return queue.length;
    }

    static clearPendingReceiptAcks() {
        this.setItem(this.KEYS.PENDING_RECEIPT_ACKS, JSON.stringify([]));
    }

    // Synced Orders (For daily reporting and receipt re-printing)
    static getSyncedOrders() {
        try {
            const data = localStorage.getItem(this.KEYS.SYNCED_ORDERS);
            return data ? JSON.parse(data) : [];
        } catch (e) {
            return [];
        }
    }

    static setSyncedOrders(orders) {
        if (!Array.isArray(orders)) orders = [];
        this.setItem(this.KEYS.SYNCED_ORDERS, JSON.stringify(orders.slice(0, 200)));
    }

    static addSyncedOrder(order) {
        const list = this.getSyncedOrders();
        // Remove existing copy if updated
        const filtered = list.filter(o => o.idempotency_key !== order.idempotency_key && o.order_number !== order.order_number);
        filtered.unshift(order);
        // Keep last 200 orders in memory
        if (filtered.length > 200) filtered.length = 200;
        this.setItem(this.KEYS.SYNCED_ORDERS, JSON.stringify(filtered));
    }

    static updatePendingOrderFailure(idempotencyKey, reason) {
        const queue = this.getPendingOrders();
        const ord = queue.find(o => o.idempotency_key === idempotencyKey);
        if (ord) {
            ord.failure_reason = reason;
            ord.failed_at = new Date().toISOString();
            ord.retry_count = (ord.retry_count || 0) + 1;
            this.setItem(this.KEYS.PENDING_ORDERS, JSON.stringify(queue));
        }
    }

    // Today Orders Cache
    static getTodayOrders() {
        try {
            const data = localStorage.getItem(this.KEYS.TODAY_ORDERS);
            return data ? JSON.parse(data) : [];
        } catch (e) {
            return [];
        }
    }

    static setTodayOrders(orders) {
        this.setItem(this.KEYS.TODAY_ORDERS, JSON.stringify(orders || []));
    }

    // Cash Drawer Counts (for Daily Close Reconciliation)
    static getDrawerCount() {
        try {
            const data = localStorage.getItem(this.KEYS.DRAWER_COUNTS);
            return data ? JSON.parse(data) : null;
        } catch (e) {
            return null;
        }
    }

    static setDrawerCount(data) {
        this.setItem(this.KEYS.DRAWER_COUNTS, JSON.stringify(data));
    }

    // Language
    static getLanguage() {
        return localStorage.getItem(this.KEYS.LANGUAGE) || 'km';
    }

    static setLanguage(lang) {
        this.setItem(this.KEYS.LANGUAGE, lang || 'km');
    }

    // Settings Cache (System settings, Shop profile, Receipt config, Exchange rate)
    static getSettings() {
        try {
            const data = localStorage.getItem(this.KEYS.SETTINGS);
            if (data) return JSON.parse(data);
            const catalog = this.getCatalog();
            if (catalog?.settings || catalog?.shop) {
                return { ...(catalog.settings || {}), ...(catalog.shop || {}) };
            }
            return {};
        } catch (e) {
            return {};
        }
    }

    static setSettings(settings) {
        this.setItem(this.KEYS.SETTINGS, JSON.stringify(settings || {}));
    }

    // Cached Offline Asset URIs (Data URIs for Logo & KHQR)
    static getLogoDataUri() {
        return localStorage.getItem(this.KEYS.LOGO_DATA_URI) || null;
    }

    static setLogoDataUri(dataUri) {
        if (dataUri) {
            this.setItem(this.KEYS.LOGO_DATA_URI, dataUri);
        } else {
            localStorage.removeItem(this.KEYS.LOGO_DATA_URI);
        }
    }

    static getKhqrDataUri() {
        return localStorage.getItem(this.KEYS.KHQR_DATA_URI) || null;
    }

    static setKhqrDataUri(dataUri) {
        if (dataUri) {
            this.setItem(this.KEYS.KHQR_DATA_URI, dataUri);
        } else {
            localStorage.removeItem(this.KEYS.KHQR_DATA_URI);
        }
    }

    // Active Cashier Shift
    static getCurrentShift() {
        try {
            const data = localStorage.getItem(this.KEYS.CURRENT_SHIFT);
            return data ? JSON.parse(data) : null;
        } catch (e) {
            return null;
        }
    }

    static setCurrentShift(shift) {
        if (shift) {
            this.setItem(this.KEYS.CURRENT_SHIFT, JSON.stringify(shift));
        } else {
            localStorage.removeItem(this.KEYS.CURRENT_SHIFT);
        }
    }

    static removeCurrentShift() {
        localStorage.removeItem(this.KEYS.CURRENT_SHIFT);
    }

    // Toggle Product Pictures Preference (per staff user on this device, with device fallback)
    static getShowPictures(staffId = null) {
        try {
            let id = staffId;
            if (!id) {
                const u = this.getUser();
                id = u?.id || u?.username;
            }
            if (id) {
                const userVal = localStorage.getItem(`cw_pos_pictures_user_${id}`);
                if (userVal !== null) return userVal === 'true';
            }
            const val = localStorage.getItem(this.KEYS.SHOW_PICTURES);
            return val === null ? true : (val === 'true');
        } catch (e) {
            return true;
        }
    }

    static setShowPictures(enabled, staffId = null) {
        try {
            const boolVal = !!enabled;
            const strVal = boolVal ? 'true' : 'false';
            let id = staffId;
            if (!id) {
                const u = this.getUser();
                id = u?.id || u?.username;
            }
            if (id) {
                this.setItem(`cw_pos_pictures_user_${id}`, strVal);
            } else {
                this.setItem(this.KEYS.SHOW_PICTURES, strVal);
            }
            return boolVal;
        } catch (e) {
            return true;
        }
    }

    // Theme (Strictly light default, dark only if explicitly chosen in settings)
    static getTheme() {
        return localStorage.getItem(this.KEYS.THEME) || 'light';
    }

    static setTheme(theme) {
        this.setItem(this.KEYS.THEME, theme === 'dark' ? 'dark' : 'light');
    }

    // Layout Preference (per staff user on this device, with device fallback to 'right')
    static getLayoutPreference(staffId = null) {
        try {
            let id = staffId;
            if (!id) {
                const u = this.getUser();
                id = u?.id || u?.username;
            }
            if (id) {
                const userVal = localStorage.getItem(`cw_pos_layout_user_${id}`);
                if (userVal === 'left' || userVal === 'right') return userVal;
            }
            // Device default when user has no preference yet is 'right'
            const devVal = localStorage.getItem(this.KEYS.LAYOUT);
            return (devVal === 'left' || devVal === 'right') ? devVal : 'right';
        } catch (e) {
            return 'right';
        }
    }

    static setLayoutPreference(layout, staffId = null) {
        try {
            const val = layout === 'left' ? 'left' : 'right';
            let id = staffId;
            if (!id) {
                const u = this.getUser();
                id = u?.id || u?.username;
            }
            if (id) {
                this.setItem(`cw_pos_layout_user_${id}`, val);
            } else {
                this.setItem(this.KEYS.LAYOUT, val);
            }
            return val;
        } catch (e) {
            return 'right';
        }
    }

    static resetLayoutPreference(staffId = null) {
        try {
            let id = staffId;
            if (!id) {
                const u = this.getUser();
                id = u?.id || u?.username;
            }
            if (id) {
                localStorage.removeItem(`cw_pos_layout_user_${id}`);
            }
            return 'right';
        } catch (e) {
            return 'right';
        }
    }

    // Offline Status Changes Queue (Start, Finish, Pay)
    static getOfflineStatusQueue() {
        try {
            return JSON.parse(localStorage.getItem(this.KEYS.OFFLINE_STATUS_QUEUE) || '[]');
        } catch {
            return [];
        }
    }

    static addOfflineStatusChange(change) {
        const queue = this.getOfflineStatusQueue();
        const existingIdx = queue.findIndex(q => q.orderNumber === change.orderNumber);
        if (existingIdx >= 0) {
            queue[existingIdx] = { ...queue[existingIdx], ...change, updatedAt: Date.now() };
        } else {
            queue.push({ ...change, updatedAt: Date.now() });
        }
        this.setItem(this.KEYS.OFFLINE_STATUS_QUEUE, JSON.stringify(queue));
    }

    static removeOfflineStatusChange(orderNumber) {
        const queue = this.getOfflineStatusQueue().filter(q => q.orderNumber !== orderNumber);
        this.setItem(this.KEYS.OFFLINE_STATUS_QUEUE, JSON.stringify(queue));
    }

    // Cached Oil Reminders
    static getOilRemindersCache() {
        try {
            const data = JSON.parse(localStorage.getItem(this.KEYS.OIL_REMINDERS_CACHE) || '[]');
            const updatedAt = localStorage.getItem(this.KEYS.OIL_REMINDERS_UPDATED_AT) || null;
            return { reminders: data, updatedAt };
        } catch {
            return { reminders: [], updatedAt: null };
        }
    }

    static setOilRemindersCache(reminders, updatedAt = null) {
        this.setItem(this.KEYS.OIL_REMINDERS_CACHE, JSON.stringify(reminders || []));
        this.setItem(this.KEYS.OIL_REMINDERS_UPDATED_AT, updatedAt || new Date().toISOString());
    }

    // Daily Close Status Cache
    static getDailyCloseStatus(date = null) {
        const d = date || new Date().toISOString().substring(0, 10);
        try {
            const all = JSON.parse(localStorage.getItem(this.KEYS.DAILY_CLOSE_STATUS) || '{}');
            return all[d] || null;
        } catch {
            return null;
        }
    }

    static setDailyCloseStatus(date, record) {
        const d = date || new Date().toISOString().substring(0, 10);
        try {
            const all = JSON.parse(localStorage.getItem(this.KEYS.DAILY_CLOSE_STATUS) || '{}');
            all[d] = record;
            this.setItem(this.KEYS.DAILY_CLOSE_STATUS, JSON.stringify(all));
        } catch (e) {}
    }
}

/**
 * PosImageCache - Offline Persistent Image Storage
 * Uses IndexedDB with in-memory cache map for zero-latency synchronous access
 * and automatic fallback to localStorage / memory.
 */
class PosImageCache {
    static DB_NAME = 'cw_pos_media_db';
    static STORE_NAME = 'product_images';
    static DB_VERSION = 1;
    static _db = null;
    static _memoryMap = new Map();
    static _initPromise = null;

    static async init() {
        if (this._initPromise) return this._initPromise;
        this._initPromise = new Promise((resolve) => {
            if (typeof indexedDB === 'undefined') {
                console.warn('IndexedDB not supported; using in-memory image cache.');
                this._loadFallback();
                return resolve(false);
            }

            try {
                const req = indexedDB.open(this.DB_NAME, this.DB_VERSION);
                req.onupgradeneeded = (e) => {
                    const db = e.target.result;
                    if (!db.objectStoreNames.contains(this.STORE_NAME)) {
                        db.createObjectStore(this.STORE_NAME, { keyPath: 'id' });
                    }
                };
                req.onsuccess = (e) => {
                    this._db = e.target.result;
                    this._loadAllFromDb().then(() => resolve(true)).catch(() => resolve(true));
                };
                req.onerror = () => {
                    console.warn('Could not open IndexedDB for image cache; fallback active.');
                    this._loadFallback();
                    resolve(false);
                };
            } catch (err) {
                this._loadFallback();
                resolve(false);
            }
        });
        return this._initPromise;
    }

    static async _loadAllFromDb() {
        if (!this._db) return;
        return new Promise((resolve) => {
            try {
                const tx = this._db.transaction(this.STORE_NAME, 'readonly');
                const store = tx.objectStore(this.STORE_NAME);
                const req = store.getAll();
                req.onsuccess = () => {
                    const records = req.result || [];
                    records.forEach(r => {
                        if (r && r.id) {
                            this._memoryMap.set(String(r.id), r);
                        }
                    });
                    resolve();
                };
                req.onerror = () => resolve();
            } catch (e) {
                resolve();
            }
        });
    }

    static _loadFallback() {
        try {
            const raw = localStorage.getItem('cw_pos_img_cache_meta');
            if (raw) {
                const map = JSON.parse(raw);
                Object.keys(map).forEach(k => this._memoryMap.set(k, map[k]));
            }
        } catch (e) {}
    }

    static get(id) {
        return this._memoryMap.get(String(id)) || null;
    }

    static getUri(id) {
        return this._memoryMap.get(String(id))?.dataUri || null;
    }

    static getVersion(id) {
        return this._memoryMap.get(String(id))?.version || null;
    }

    static async save(id, dataUri, version = null, originalUrl = '') {
        const key = String(id);
        const record = {
            id: key,
            dataUri: dataUri,
            version: version || '',
            originalUrl: originalUrl || '',
            updatedAt: Date.now()
        };
        this._memoryMap.set(key, record);

        if (this._db) {
            try {
                const tx = this._db.transaction(this.STORE_NAME, 'readwrite');
                tx.objectStore(this.STORE_NAME).put(record);
            } catch (e) {
                console.warn('Failed to write image to IndexedDB:', e);
            }
        }
    }

    static async remove(id) {
        const key = String(id);
        this._memoryMap.delete(key);
        if (this._db) {
            try {
                const tx = this._db.transaction(this.STORE_NAME, 'readwrite');
                tx.objectStore(this.STORE_NAME).delete(key);
            } catch (e) {}
        }
    }

    static async clear() {
        this._memoryMap.clear();
        if (this._db) {
            try {
                const tx = this._db.transaction(this.STORE_NAME, 'readwrite');
                tx.objectStore(this.STORE_NAME).clear();
            } catch (e) {}
        }
    }
}

window.PosStorage = PosStorage;
window.PosImageCache = PosImageCache;

