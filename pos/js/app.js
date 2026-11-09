/**
 * Main POS Application Controller
 * Handles authentication, catalog rendering, vehicle type pricing,
 * customer lookup, cart calculations, checkout, receipt printing,
 * live job queue, daily close report, and offline idempotent sync engine.
 */
class PosApp {
    static escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    static stripHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str).replace(/<\/?[^>]+(>|$)/g, '').trim();
    }

    static formatCustomerName(name) {
        if (!name) return I18nManager.t('walkInGuest') || 'Walk-in Guest';
        const clean = this.stripHtml(name);
        return this.escapeHtml(clean || 'Walk-in Guest');
    }

    static getCustomerInitials(name) {
        if (!name) return '?';
        const clean = this.stripHtml(name);
        if (!clean) return '?';
        const parts = clean.split(/\s+/).filter(Boolean);
        if (parts.length === 1) {
            return Array.from(parts[0]).slice(0, 2).join('').toUpperCase();
        }
        const first = Array.from(parts[0])[0] || '';
        const last = Array.from(parts[parts.length - 1])[0] || '';
        return (first + last).toUpperCase();
    }

    static generateIdempotencyKey() {
        if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
            try {
                return crypto.randomUUID();
            } catch (e) {
                // fallback to timestamp + random
            }
        }
        return `pos_${Date.now()}_${Math.random().toString(36).substring(2, 9)}`;
    }

    static highlightMatch(text, query) {
        if (!text) return '';
        const plainText = this.stripHtml(String(text));
        const q = (query || '').trim();
        if (!q) return this.escapeHtml(plainText);

        const escapedQ = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const regex = new RegExp(`(${escapedQ})`, 'gi');
        const parts = plainText.split(regex);
        return parts.map(part => {
            if (part.toLowerCase() === q.toLowerCase()) {
                return `<mark class="search-match-hl">${this.escapeHtml(part)}</mark>`;
            }
            return this.escapeHtml(part);
        }).join('');
    }

    static custSearchHighlightedIndex = -1;

    static updateCustSearchHighlight(rows) {
        if (!rows || !rows.length) return;
        rows.forEach((row, idx) => {
            if (idx === this.custSearchHighlightedIndex) {
                row.classList.add('selected');
                row.scrollIntoView({ block: 'nearest' });
            } else {
                row.classList.remove('selected');
            }
        });
    }

    static currentView = 'pos';
    static ordersPage = 1;
    static ordersPageSize = 25;

    static wasFullscreenPreferred = false;

    static isFullscreen() {
        const doc = window.document;
        return !!(doc.fullscreenElement || doc.webkitFullscreenElement || doc.mozFullScreenElement || doc.msFullscreenElement);
    }

    static async enterFullscreen() {
        const doc = window.document;
        const docEl = doc.documentElement;
        if (this.isFullscreen()) {
            this.updateFullscreenIcons();
            return true;
        }

        const requestFs = docEl.requestFullscreen || docEl.webkitRequestFullscreen || docEl.mozRequestFullScreen || docEl.msRequestFullscreen;
        if (requestFs) {
            try {
                const res = requestFs.call(docEl);
                if (res && typeof res.catch === 'function') {
                    await res;
                }
                this.updateFullscreenIcons();
                return true;
            } catch (err) {
                console.warn('Enter fullscreen prevented:', err);
                return false;
            }
        }
        return false;
    }

    static async exitFullscreen() {
        const doc = window.document;
        if (!this.isFullscreen()) {
            this.updateFullscreenIcons();
            return true;
        }

        const exitFs = doc.exitFullscreen || doc.webkitExitFullscreen || doc.mozCancelFullScreen || doc.msExitFullscreen;
        if (exitFs) {
            try {
                const res = exitFs.call(doc);
                if (res && typeof res.catch === 'function') {
                    await res;
                }
                this.updateFullscreenIcons();
                return true;
            } catch (err) {
                console.warn('Exit fullscreen prevented:', err);
                return false;
            }
        }
        return false;
    }

    static async toggleFullscreen() {
        if (this.isFullscreen()) {
            this.wasFullscreenPreferred = false;
            await this.exitFullscreen();
        } else {
            this.wasFullscreenPreferred = true;
            await this.enterFullscreen();
        }
    }

    static setupPrintFullscreenPersistence() {
        // Track fullscreen state before printing starts
        window.addEventListener('beforeprint', () => {
            if (this.isFullscreen()) {
                this.wasFullscreenPreferred = true;
            }
        });

        // Auto-restore fullscreen when printing concludes
        const attemptRestore = async () => {
            if (!this.wasFullscreenPreferred) return;

            // Attempt immediate restoration
            const success = await this.enterFullscreen();
            if (!success) {
                // If browser enforces transient user activation after system print dialog,
                // arm a one-time gesture capture so the very next click, tap, or key immediately re-enters fullscreen
                const onUserGesture = async () => {
                    if (this.wasFullscreenPreferred && !this.isFullscreen()) {
                        await this.enterFullscreen();
                    }
                    window.removeEventListener('pointerdown', onUserGesture, true);
                    window.removeEventListener('keydown', onUserGesture, true);
                    window.removeEventListener('click', onUserGesture, true);
                };
                window.addEventListener('pointerdown', onUserGesture, true);
                window.addEventListener('keydown', onUserGesture, true);
                window.addEventListener('click', onUserGesture, true);
            }
        };

        window.addEventListener('afterprint', () => {
            setTimeout(attemptRestore, 150);
        });
    }

    static updateFullscreenIcons() {
        const isFs = this.isFullscreen();

        const btnFs = document.getElementById('btnToggleFullscreen');
        if (btnFs) {
            const icon = btnFs.querySelector('i');
            if (icon) {
                icon.className = isFs ? 'fa-solid fa-compress' : 'fa-solid fa-expand';
            }
            btnFs.title = isFs ? (I18nManager.t('exitFullscreen') || 'Exit Fullscreen') : (I18nManager.t('toggleFullscreen') || 'Fullscreen');
        }

        const menuFsIcon = document.getElementById('menuFullscreenIcon');
        if (menuFsIcon) {
            menuFsIcon.className = isFs ? 'fa-solid fa-compress' : 'fa-solid fa-expand';
        }
        const menuFsText = document.getElementById('menuFullscreenText');
        if (menuFsText) {
            menuFsText.textContent = isFs ? (I18nManager.t('exitFullscreen') || 'Exit Fullscreen') : (I18nManager.t('toggleFullscreen') || 'Fullscreen');
        }
    }

    static showToastNotice(msg, type = 'info') {
        const alertBox = document.getElementById('posNoticeAlert');
        const alertContent = document.getElementById('posNoticeAlertContent');
        if (alertBox && alertContent) {
            alertContent.innerHTML = this.escapeHtml(msg);
            alertBox.className = `alert alert-${type}`;
            alertBox.style.display = 'flex';
            clearTimeout(this._noticeTimer);
            this._noticeTimer = setTimeout(() => {
                alertBox.style.display = 'none';
            }, 4000);
        }
    }

    static currentDailyReportData = null;
    static isDayClosed = false;
    static closeOrdersPage = 1;
    static closeOrdersPageSize = 15;
    static currentQueueOrdersMap = {};
    static currentCustomerSearchResults = [];
    static oilAutoRefreshTimer = null;

    static currentUser = null;
    static isOnline = true;
    static pingInterval = null;
    static syncInterval = null;
    static currentPin = '';
    static lockTimerInterval = null;
    static lockSecondsRemaining = 0;
    static idleTimerInterval = null;
    static lastActivityTime = Date.now();

    // FontAwesome Icon Resolution & Verification
    static validFaIcons = null;

    static initIconLibrary() {
        if (this.validFaIcons) return;
        this.validFaIcons = new Set();
        try {
            for (let i = 0; i < document.styleSheets.length; i++) {
                const sheet = document.styleSheets[i];
                try {
                    const rules = sheet.cssRules || sheet.rules;
                    if (!rules) continue;
                    for (let j = 0; j < rules.length; j++) {
                        const sel = rules[j].selectorText;
                        if (sel && sel.includes(':before')) {
                            const m = sel.match(/\.(fa-[a-z0-9-]+):before/);
                            if (m) this.validFaIcons.add(m[1]);
                        }
                    }
                } catch (e) {}
            }
        } catch (e) {}

        // Guarantee core standard icons
        [
            'fa-shower', 'fa-broom', 'fa-soap', 'fa-gears', 'fa-shield-halved',
            'fa-circle-dot', 'fa-arrows-rotate', 'fa-compact-disc', 'fa-magnifying-glass-chart',
            'fa-car-battery', 'fa-lightbulb', 'fa-wind', 'fa-hands-wash', 'fa-hands-bubbles',
            'fa-spray-can', 'fa-wrench', 'fa-oil-can', 'fa-filter', 'fa-box', 'fa-bottle-droplet',
            'fa-hourglass-half', 'fa-hourglass', 'fa-play', 'fa-circle-check', 'fa-ban',
            'fa-car', 'fa-car-side', 'fa-motorcycle', 'fa-truck-pickup', 'fa-van-shuttle', 'fa-truck',
            'fa-users', 'fa-user', 'fa-user-gear', 'fa-network-wired', 'fa-server', 'fa-print'
        ].forEach(ic => this.validFaIcons.add(ic));
    }

    static isFaIconValid(iconName) {
        if (!iconName || typeof iconName !== 'string') return false;
        this.initIconLibrary();
        let clean = iconName.trim();
        const parts = clean.split(/\s+/);
        for (const p of parts) {
            if (p.startsWith('fa-') && !['fa-solid', 'fa-regular', 'fa-brands', 'fa-sharp', 'fa-spin', 'fa-2x', 'fa-lg', 'fa-sm'].includes(p)) {
                clean = p;
                break;
            }
        }
        if (!clean.startsWith('fa-')) {
            clean = 'fa-' + clean;
        }
        return this.validFaIcons.has(clean) ? clean : false;
    }

    static getValidServiceIcon(icon, category, nameEn) {
        const nameLower = (nameEn || '').toLowerCase();
        
        // Full Wash check: guarantees Full Wash service always displays fa-soap icon
        if (nameLower.includes('full wash') || (nameLower.includes('exterior') && nameLower.includes('interior'))) {
            return 'fa-soap';
        }

        const valid = this.isFaIconValid(icon);
        if (valid) return valid;

        // Safe fallback based on category or service name
        const cat = (category || '').toLowerCase();
        if (cat === 'repair') {
            if (nameLower.includes('tire') || nameLower.includes('puncture')) return 'fa-circle-dot';
            if (nameLower.includes('wheel') || nameLower.includes('balance')) return 'fa-arrows-rotate';
            if (nameLower.includes('brake pad')) return 'fa-compact-disc';
            if (nameLower.includes('brake') || nameLower.includes('inspect')) return 'fa-magnifying-glass-chart';
            if (nameLower.includes('battery')) return 'fa-car-battery';
            if (nameLower.includes('bulb') || nameLower.includes('light')) return 'fa-lightbulb';
            if (nameLower.includes('wiper')) return 'fa-wind';
            return 'fa-wrench';
        }

        if (cat === 'wash' || cat === 'detailing') {
            if (nameLower.includes('interior')) return 'fa-broom';
            if (nameLower.includes('engine')) return 'fa-gears';
            if (nameLower.includes('wax') || nameLower.includes('polish')) return 'fa-shield-halved';
            return 'fa-shower';
        }

        if (cat === 'maintenance') return 'fa-oil-can';

        return 'fa-shower';
    }

    static getValidProductIcon(icon, category, nameEn) {
        const valid = this.isFaIconValid(icon);
        if (valid) return valid;

        const cat = (category || '').toLowerCase();
        const nameLower = (nameEn || '').toLowerCase();

        if (cat === 'engine_oil' || nameLower.includes('oil') || nameLower.includes('synthetic')) return 'fa-oil-can';
        if (cat === 'oil_filter' || nameLower.includes('filter')) return 'fa-filter';
        if (cat === 'fluid' || nameLower.includes('coolant') || nameLower.includes('fluid')) return 'fa-bottle-droplet';
        if (cat === 'care_product' || nameLower.includes('freshener') || nameLower.includes('scent') || nameLower.includes('wax')) return 'fa-spray-can';
        if (cat === 'spare_part') return 'fa-wrench';

        return 'fa-box';
    }

    // POS State
    static selectedVehicleType = 'small_car'; // motorbike, small_car, suv_pickup, van_truck
    static selectedCategory = 'all';
    static currentCustomer = null; // { id, name, phone, plate, vehicle_type }
    static cart = []; // [ { id, type, name_en, name_km, price_usd, price_khr, qty, product_id, service_id } ]
    static currentDiscount = { type: 'none', value: 0 }; // type: 'none'|'percent'|'fixed', value: number
    static paymentMethod = 'cash';
    static lastCompletedOrder = null;

    // Member, Upsell & Loyalty Reward States
    static currentCustomerBenefits = [];
    static activeLoyaltyReward = null;
    static loyaltyRewardGiven = null;
    static loyaltyRewardActionChosen = false;
    static activeUpsellOffer = null;
    static _currentUpsellModalOpen = false;
    static _dismissedUpsellIds = new Set();
    static _upsellCheckTimeout = null;

    // ========================================================
    // BACK OFFICE SETTINGS & BRANDING INTEGRATION
    // ========================================================
    static getSettings() {
        const catalog = PosStorage.getCatalog();
        const stored = PosStorage.getSettings();
        return {
            shop_name_en: 'Car Wash & Detailing',
            shop_name_km: 'សេវាកម្មលាងរថយន្ត និងម៉ូតូទំនើប',
            shop_address: 'St. 271, Sangkat Boeung Tumpun, Khan Mean Chey, Phnom Penh',
            shop_phone: '012 334 556 / 098 776 543',
            shop_logo: '',
            receipt_footer: 'Thank you for your visit! / សូមអរគុណ!',
            receipt_show_logo: true,
            receipt_language: 'both',
            exchange_rate: 4100,
            aba_account_name: '',
            aba_account_num: '',
            khqr_image: '',
            default_oil_mileage: 10000,
            default_oil_months: 3,
            ...(catalog?.settings || {}),
            ...(catalog?.shop || {}),
            ...(stored || {})
        };
    }

    static getExchangeRate() {
        const settings = this.getSettings();
        if (settings.exchange_rate) {
            const parsed = parseInt(settings.exchange_rate);
            if (!isNaN(parsed) && parsed > 0) return parsed;
        }
        const config = PosConfig.get();
        return parseInt(config.exchangeRate) || 4100;
    }

    static resolveAssetUrl(urlOrPath) {
        if (!urlOrPath) return null;
        const str = String(urlOrPath).trim();
        if (!str) return null;
        if (str.startsWith('data:') || str.startsWith('blob:')) return str;

        const config = PosConfig.get();
        const base = (config.backofficeUrl || PosConfig.getDefaultBackofficeUrl() || '').replace(/\/+$/, '');

        // If it's a relative path (e.g. public/uploads/...), attach configured backofficeUrl
        if (!str.startsWith('http://') && !str.startsWith('https://')) {
            return `${base}/${str.replace(/^\/+/, '')}`;
        }

        // If it's an absolute URL, check if it points to a backoffice uploads path
        // (e.g. generated as /public/uploads/...)
        // When POS is run on a phone/tablet or cloud, re-anchor to the user-configured backofficeUrl:
        const uploadsIdx = str.indexOf('/public/uploads/');
        if (uploadsIdx !== -1) {
            const relPath = str.substring(uploadsIdx + 1); // "public/uploads/..."
            return `${base}/${relPath}`;
        }

        return str;
    }

    static async cacheImageAsDataUri(url) {
        if (!url) return null;
        try {
            const res = await fetch(url);
            if (!res.ok) return null;
            const blob = await res.blob();
            return new Promise((resolve) => {
                const reader = new FileReader();
                reader.onloadend = () => resolve(reader.result);
                reader.onerror = () => resolve(null);
                reader.readAsDataURL(blob);
            });
        } catch (e) {
            return null;
        }
    }

    static async cacheSettingsAssets(settings) {
        try {
            const logoPath = settings.shop_logo_url || settings.logo_url || settings.shop_logo || settings.logo;
            const logoUrl = this.resolveAssetUrl(logoPath);
            if (logoUrl) {
                const logoData = await this.cacheImageAsDataUri(logoUrl);
                if (logoData) PosStorage.setLogoDataUri(logoData);
            }
            const khqrPath = settings.khqr_url || settings.khqr_image;
            const khqrUrl = this.resolveAssetUrl(khqrPath);
            if (khqrUrl) {
                const khqrData = await this.cacheImageAsDataUri(khqrUrl);
                if (khqrData) PosStorage.setKhqrDataUri(khqrData);
            }
        } catch (e) {
            console.warn('Could not cache settings assets offline:', e);
        }
    }

    static applyShopBranding() {
        const settings = this.getSettings();
        const lang = PosStorage.getLanguage();

        // 1. Logo
        const logoPath = settings.shop_logo_url || settings.logo_url || settings.shop_logo || settings.logo;
        const logoSrc = PosStorage.getLogoDataUri() || this.resolveAssetUrl(logoPath);
        const brandLogoImg = document.getElementById('brandLogoImg');
        const brandDefaultIcon = document.getElementById('brandDefaultIcon');
        const loginLogoImg = document.getElementById('loginLogoImg');
        const loginDefaultIcon = document.getElementById('loginDefaultIcon');

        if (logoSrc) {
            if (brandLogoImg) {
                brandLogoImg.src = logoSrc;
                brandLogoImg.style.display = 'block';
            }
            if (brandDefaultIcon) brandDefaultIcon.style.display = 'none';

            const brandIconWrap = document.querySelector('.brand-logo-icon');
            if (brandIconWrap) {
                brandIconWrap.classList.add('has-custom-logo');
                brandIconWrap.style.background = 'transparent';
                brandIconWrap.style.boxShadow = 'none';
            }

            if (loginLogoImg) {
                loginLogoImg.src = logoSrc;
                loginLogoImg.style.display = 'block';
            }
            if (loginDefaultIcon) loginDefaultIcon.style.display = 'none';

            const loginBadge = document.getElementById('loginLogoBadge');
            if (loginBadge) {
                loginBadge.style.background = 'transparent';
                loginBadge.style.boxShadow = 'none';
            }
        } else {
            // If no logo, keep what is shown today
            if (brandLogoImg) brandLogoImg.style.display = 'none';
            if (brandDefaultIcon) brandDefaultIcon.style.display = 'inline-block';

            const brandIconWrap = document.querySelector('.brand-logo-icon');
            if (brandIconWrap) {
                brandIconWrap.classList.remove('has-custom-logo');
                brandIconWrap.style.background = '';
                brandIconWrap.style.boxShadow = '';
            }

            if (loginLogoImg) loginLogoImg.style.display = 'none';
            if (loginDefaultIcon) loginDefaultIcon.style.display = 'inline-block';

            const loginBadge = document.getElementById('loginLogoBadge');
            if (loginBadge) {
                loginBadge.style.background = '';
                loginBadge.style.boxShadow = '';
            }
        }

        // 2. Shop Name (Show only active language, no secondary dual lines)
        const nameKm = settings.shop_name_km || '';
        const nameEn = settings.shop_name_en || '';
        const brandPrimary = document.getElementById('brandShopNamePrimary');
        const brandSecondary = document.getElementById('brandShopNameSecondary');

        if (lang === 'en') {
            if (brandPrimary) brandPrimary.textContent = nameEn || I18nManager.t('appName');
            if (brandSecondary) {
                brandSecondary.textContent = '';
                brandSecondary.style.display = 'none';
            }
        } else {
            if (brandPrimary) brandPrimary.textContent = nameKm || I18nManager.t('appName');
            if (brandSecondary) {
                brandSecondary.textContent = '';
                brandSecondary.style.display = 'none';
            }
        }

        // Login screen shop title
        const loginShopWrap = document.getElementById('loginShopNameWrap');
        const loginShopName = document.getElementById('loginShopName');
        if (loginShopWrap && loginShopName) {
            const displayName = (lang === 'en') ? (nameEn || nameKm) : (nameKm || nameEn);
            if (displayName) {
                loginShopName.textContent = displayName;
                loginShopWrap.style.display = 'block';
            } else {
                loginShopWrap.style.display = 'none';
            }
        }

        // Read-only settings exchange rate
        const cfgRate = document.getElementById('cfgExchangeRate');
        if (cfgRate) cfgRate.value = this.getExchangeRate();
    }

    static currentLayout = 'right';

    static applyLayoutPreference(layout = null) {
        const staffId = this.currentUser?.id || this.currentUser?.username;
        const pref = layout || PosStorage.getLayoutPreference(staffId) || 'right';
        this.currentLayout = (pref === 'left') ? 'left' : 'right';

        const workspace = document.querySelector('.pos-workspace');
        if (workspace) {
            if (this.currentLayout === 'left') {
                workspace.classList.add('layout-cart-left');
                workspace.classList.remove('layout-cart-right');
            } else {
                workspace.classList.add('layout-cart-right');
                workspace.classList.remove('layout-cart-left');
            }
        }

        this.updateLayoutSettingsUI(this.currentLayout);
    }

    static setLayoutPreference(layout) {
        const valid = (layout === 'left') ? 'left' : 'right';
        const staffId = this.currentUser?.id || this.currentUser?.username;
        PosStorage.setLayoutPreference(valid, staffId);
        this.applyLayoutPreference(valid);
    }

    static updateLayoutSettingsUI(activeLayout = null) {
        const pref = activeLayout || this.currentLayout || 'right';
        const cardRight = document.getElementById('layoutCardRight');
        const cardLeft = document.getElementById('layoutCardLeft');
        if (cardRight) cardRight.classList.toggle('active', pref === 'right');
        if (cardLeft) cardLeft.classList.toggle('active', pref === 'left');

        const userHint = document.getElementById('layoutUserHint');
        if (userHint) {
            const staffName = this.currentUser?.full_name || this.currentUser?.username || 'Cashier';
            const lang = PosStorage.getLanguage() || 'km';
            if (lang === 'km') {
                userHint.textContent = `រក្សាទុកសម្រាប់៖ ${staffName} (នៅលើឧបករណ៍នេះ)`;
            } else {
                userHint.textContent = `Saved for: ${staffName} (on this terminal)`;
            }
        }
    }

    static applyTheme(theme = null) {
        const activeTheme = theme || PosStorage.getTheme() || 'light';
        const menuIcon = document.getElementById('menuThemeIcon');
        const menuText = document.getElementById('menuThemeText');
        if (activeTheme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
            const icon = document.getElementById('topThemeIcon');
            if (icon) icon.className = 'fa-solid fa-sun';
            if (menuIcon) menuIcon.className = 'fa-solid fa-sun';
            if (menuText) {
                menuText.setAttribute('data-i18n', 'lightMode');
                if (typeof I18nManager !== 'undefined') {
                    menuText.textContent = I18nManager.t('lightMode') || 'Light Mode';
                }
            }
            const cfg = document.getElementById('cfgDarkTheme');
            if (cfg) cfg.checked = true;
        } else {
            document.documentElement.setAttribute('data-theme', 'light');
            const icon = document.getElementById('topThemeIcon');
            if (icon) icon.className = 'fa-solid fa-moon';
            if (menuIcon) menuIcon.className = 'fa-solid fa-moon';
            if (menuText) {
                menuText.setAttribute('data-i18n', 'darkMode');
                if (typeof I18nManager !== 'undefined') {
                    menuText.textContent = I18nManager.t('darkMode') || 'Dark Mode';
                }
            }
            const cfg = document.getElementById('cfgDarkTheme');
            if (cfg) cfg.checked = false;
        }
    }

    static init() {
        console.log('AquaGleam Car Wash POS initializing...');

        // Requirement 3: Override native browser alert with app styled in-app alert dialog
        window.alert = (msg) => {
            this.showAppAlert(msg);
        };

        // Theme initialization (Light theme by default, dark only if selected)
        this.applyTheme();
        this.applyLayoutPreference();

        // 0. Initialize FontAwesome Icon Library & Media Cache
        this.initIconLibrary();
        if (typeof PosImageCache !== 'undefined') {
            PosImageCache.init().then(() => {
                const cat = PosStorage.getCatalog();
                if (cat && cat.products) {
                    this.syncProductImagesSilently(cat.products);
                }
            });
        }
        this.updatePicturesToggleUI();

        // 1. Initialize Language & Shop Branding
        I18nManager.applyToDOM();
        this.applyShopBranding();
        this.renderCategories();
        this.populateCrewDropdowns();

        // 2. Setup Event Listeners
        this.bindEvents();

        // 3. Monitor Online / Offline Network Status & Auto-Sync Engine
        this.setupNetworkMonitor();

        // 4. Check for active staff session
        this.checkSession();

        // 5. Populate Settings Modal Form
        this.loadSettingsForm();

        // 6. Test initial connection & sync catalog
        this.checkConnectionHealth();
        this.updatePendingSyncBadge();
        this.updateCatalogSyncStatus();

        // 7. If the POS has never been connected, show settings automatically on first open
        const hasConnected = localStorage.getItem('carwash_pos_has_connected');
        if (hasConnected !== 'true') {
            setTimeout(() => {
                this.loadSettingsForm();
                this.showAlert(
                    'settingsAlert',
                    {
                        type: 'info',
                        title: 'សូមស្វាគមន៍ (Welcome)!',
                        message: 'សូមពិនិត្យអាសយដ្ឋាន Back Office, Device ID, និង Device Key រួចចុច "Test Link" ដើម្បីភ្ជាប់ទំនាក់ទំនងដំបូង។'
                    }
                );
                document.getElementById('modalSettings')?.classList.add('open');
            }, 350);
        }

        // 8. Register Service Worker (PWA)
        this.registerServiceWorker();
    }

    static registerServiceWorker() {
        const isLocalhost = window.location.hostname === 'localhost' ||
                            window.location.hostname === '127.0.0.1' ||
                            window.location.hostname === '::1';
        const isHttps = window.location.protocol === 'https:';

        if (!('serviceWorker' in navigator)) {
            return;
        }

        // SW must register only over HTTPS or localhost/127.0.0.1; skip silently on plain LAN http
        if (!isHttps && !isLocalhost) {
            return;
        }

        navigator.serviceWorker.register('./sw.js', { scope: './' })
            .then((reg) => {
                // If a new worker is already waiting (e.g. refreshed page while waiting)
                if (reg.waiting) {
                    PosApp.showUpdateToast(reg.waiting);
                }

                reg.addEventListener('updatefound', () => {
                    const newWorker = reg.installing;
                    if (!newWorker) return;

                    newWorker.addEventListener('statechange', () => {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            PosApp.showUpdateToast(newWorker);
                        }
                    });
                });
            })
            .catch((err) => {
                console.warn('SW registration failed:', err);
            });

        let refreshing = false;
        navigator.serviceWorker.addEventListener('controllerchange', () => {
            if (!refreshing) {
                refreshing = true;
                window.location.reload();
            }
        });
    }

    static showUpdateToast(worker) {
        let toast = document.getElementById('posUpdateToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'posUpdateToast';
            toast.style.cssText = 'position: fixed; bottom: 24px; right: 24px; z-index: 99999; background: #0284c7; color: #ffffff; padding: 12px 20px; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); font-weight: 600; font-size: 15px; cursor: pointer; display: flex; align-items: center; gap: 10px; transition: transform 0.2s ease, opacity 0.2s ease;';
            toast.innerHTML = '<i class="fa-solid fa-arrows-rotate fa-spin-pulse"></i> <span>New version available - tap to reload</span>';
            document.body.appendChild(toast);
        }
        toast.style.display = 'flex';
        toast.onclick = () => {
            if (worker) {
                worker.postMessage({ action: 'skipWaiting' });
            } else {
                window.location.reload();
            }
        };

        const alertContent = document.getElementById('posNoticeAlertContent');
        const alertBox = document.getElementById('posNoticeAlert');
        if (alertBox && alertContent) {
            alertContent.innerHTML = '<i class="fa-solid fa-arrows-rotate"></i> <span>New version available - tap to reload</span>';
            alertBox.className = 'alert alert-info';
            alertBox.style.display = 'flex';
            alertBox.style.cursor = 'pointer';
            alertBox.onclick = () => {
                if (worker) {
                    worker.postMessage({ action: 'skipWaiting' });
                } else {
                    window.location.reload();
                }
            };
        }
    }

    static startLiveClock() {
        const update = () => {
            const dateEl = document.getElementById('topbarDateText');
            const timeEl = document.getElementById('topbarTimeText');
            if (!dateEl || !timeEl) return;

            const now = new Date();
            const isKm = (I18nManager.getLang() === 'km');

            try {
                const timeStr = now.toLocaleTimeString(isKm ? 'km-KH' : 'en-US', {
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: true
                });
                const dateStr = now.toLocaleDateString(isKm ? 'km-KH' : 'en-US', {
                    weekday: 'short',
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric'
                });
                dateEl.textContent = dateStr;
                timeEl.textContent = timeStr;
            } catch (e) {
                dateEl.textContent = now.toLocaleDateString();
                timeEl.textContent = now.toLocaleTimeString();
            }
        };

        update();
        if (!this._clockInterval) {
            this._clockInterval = setInterval(update, 1000);
        }
    }

    static onLanguageChanged() {
        this.updateUserHeaderInfo();
        this.applyShopBranding();
        this.updateCatalogSyncStatus();
        this.calculateDrawerVariance();
        this.updateLayoutSettingsUI();
        this.startLiveClock();

        if (!this.currentUser) return;

        const current = this.currentView || 'pos';
        const activeSection = document.getElementById(`view${current.charAt(0).toUpperCase() + current.slice(1)}`);
        const prevScroll = activeSection ? activeSection.scrollTop : 0;

        if (current === 'pos') {
            this.renderCategories();
            this.renderCatalog();
            this.renderCart();
            this.populateCrewDropdowns();
        } else if (current === 'queue') {
            this.renderJobQueue();
        } else if (current === 'orders') {
            const search = document.getElementById('searchOrdersPlateInput')?.value.trim() || '';
            this.renderTodayOrders(search, this.currentOrderFilter || 'all', true);
        } else if (current === 'oil') {
            this.renderOilReminders();
        } else if (current === 'close') {
            this.renderDailyClose();
        } else if (current === 'washer') {
            this.renderWasherScreen();
        } else if (current === 'mechanic') {
            this.renderMechanicScreen();
        }

        if (activeSection) {
            requestAnimationFrame(() => {
                activeSection.scrollTop = prevScroll;
            });
        }
    }

    static handleGlobalSearch(query) {
        const q = (query || '').trim().toLowerCase();
        const cards = document.querySelectorAll('#catalogGrid .pos-item-card');
        if (!cards.length) return;

        let visibleCount = 0;
        cards.forEach(card => {
            if (!q) {
                card.style.display = '';
                visibleCount++;
                return;
            }
            const nameKm = (card.querySelector('.item-name-km')?.textContent || '').toLowerCase();
            const nameEn = (card.querySelector('.item-name-en')?.textContent || '').toLowerCase();
            const badgeType = (card.querySelector('.card-tag-type')?.textContent || '').toLowerCase();
            const svcId = card.getAttribute('data-svc-id');
            const prodId = card.getAttribute('data-prod-id');

            const matches = nameKm.includes(q) || nameEn.includes(q) || badgeType.includes(q) ||
                            (svcId && svcId.includes(q)) || (prodId && prodId.includes(q));
            if (matches) {
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });
    }

    static bindEvents() {
        // Topbar Live Clock
        this.startLiveClock();

        // Language Toggle Button (Legacy/fallback)
        const btnLang = document.getElementById('btnLangToggle');
        if (btnLang) {
            btnLang.addEventListener('click', () => {
                const current = I18nManager.getLang();
                const next = current === 'km' ? 'en' : 'km';
                I18nManager.setLang(next);
                this.onLanguageChanged();
            });
        }

        // Language Segmented Control Buttons (ខ្មែរ / EN)
        const btnLangKm = document.getElementById('btnLangKm');
        const btnLangEn = document.getElementById('btnLangEn');
        if (btnLangKm) {
            btnLangKm.addEventListener('click', () => {
                if (I18nManager.getLang() !== 'km') {
                    I18nManager.setLang('km');
                    this.onLanguageChanged();
                }
            });
        }
        if (btnLangEn) {
            btnLangEn.addEventListener('click', () => {
                if (I18nManager.getLang() !== 'en') {
                    I18nManager.setLang('en');
                    this.onLanguageChanged();
                }
            });
        }

        // Fullscreen Toggle (Direct button & event listeners)
        const btnFs = document.getElementById('btnToggleFullscreen');
        if (btnFs) {
            btnFs.addEventListener('click', (e) => {
                e.stopPropagation();
                this.toggleFullscreen();
            });
        }
        ['fullscreenchange', 'webkitfullscreenchange', 'mozfullscreenchange', 'MSFullscreenChange'].forEach(evt => {
            document.addEventListener(evt, () => this.updateFullscreenIcons());
        });
        this.setupPrintFullscreenPersistence();

        // Topbar Theme Toggle Button
        const btnTheme = document.getElementById('btnToggleTheme');
        if (btnTheme) {
            btnTheme.addEventListener('click', () => {
                const cur = PosStorage.getTheme() || 'light';
                const next = (cur === 'dark') ? 'light' : 'dark';
                PosStorage.setTheme(next);
                this.applyTheme(next);
            });
        }

        // Topbar Menu Button & Popover
        const btnTopMenu = document.getElementById('btnTopMenu');
        const menuDropdown = document.getElementById('topbarMenuDropdown');
        if (btnTopMenu && menuDropdown) {
            btnTopMenu.addEventListener('click', (e) => {
                e.stopPropagation();
                const isShowing = (menuDropdown.style.display === 'block');
                menuDropdown.style.display = isShowing ? 'none' : 'block';
                btnTopMenu.classList.toggle('active', !isShowing);
            });
            document.addEventListener('click', (e) => {
                if (!e.target.closest('#topbarMenuWrapper')) {
                    menuDropdown.style.display = 'none';
                    btnTopMenu.classList.remove('active');
                }
            });
        }

        // Topbar Customer Selector Dropdown & Popover
        const btnCustDropdown = document.getElementById('btnCustomerDropdown');
        const popoverCust = document.getElementById('topbarCustomerPopover');
        if (btnCustDropdown && popoverCust) {
            btnCustDropdown.addEventListener('click', (e) => {
                e.stopPropagation();
                const isShowing = (popoverCust.style.display === 'block');
                popoverCust.style.display = isShowing ? 'none' : 'block';
                btnCustDropdown.classList.toggle('active', !isShowing);
                if (!isShowing) {
                    setTimeout(() => document.getElementById('custSearchInput')?.focus(), 60);
                }
            });
            document.addEventListener('click', (e) => {
                if (!e.target.closest('#topbarCustomerWrapper') && !e.target.closest('#modalCustomer')) {
                    popoverCust.style.display = 'none';
                    btnCustDropdown.classList.remove('active');
                }
            });
        }

        // POS Global Search Bar (Real-time product filtering)
        const posGlobalSearch = document.getElementById('posGlobalSearchInput');
        if (posGlobalSearch) {
            posGlobalSearch.addEventListener('input', (e) => {
                this.handleGlobalSearch(e.target.value);
            });
        }

        // Cart Action Pills (Clear, Saved/Queue, Discount)
        document.getElementById('btnCartPillClear')?.addEventListener('click', () => this.clearCart());
        document.getElementById('btnCartPillQueue')?.addEventListener('click', () => this.switchView('queue'));
        document.getElementById('btnCartPillDiscount')?.addEventListener('click', () => {
            this.openDiscountModal();
        });

        // Cart Discount Summary Row - Remove Button (x)
        document.getElementById('btnRemoveCartDiscount')?.addEventListener('click', (e) => {
            e.stopPropagation();
            this.setDiscount('0');
        });

        // Discount Modal Event Listeners
        document.getElementById('btnCloseDiscountModal')?.addEventListener('click', () => this.closeDiscountModal());
        document.getElementById('btnCancelDiscountModal')?.addEventListener('click', () => this.closeDiscountModal());
        document.getElementById('btnRemoveDiscountModal')?.addEventListener('click', () => {
            this.setDiscount('0');
            this.closeDiscountModal();
        });
        document.getElementById('btnApplyDiscountModal')?.addEventListener('click', () => {
            this.applyDiscountFromModal();
        });

        // Unit Switcher (% vs $)
        document.getElementById('btnDiscountUnitPercent')?.addEventListener('click', () => {
            this.setDiscountModalUnit('percent');
        });
        document.getElementById('btnDiscountUnitFixed')?.addEventListener('click', () => {
            this.setDiscountModalUnit('fixed');
        });

        // Quick choices in modal
        const modalDiscBtns = document.querySelectorAll('#modalDiscount .btn-modal-disc');
        modalDiscBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                modalDiscBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const customInput = document.getElementById('discountCustomInput');
                if (customInput) customInput.value = '';
                this.clearDiscountModalAlert();
                this.updateDiscountModalPreview();
            });
        });

        // Custom amount input
        const discCustomInput = document.getElementById('discountCustomInput');
        if (discCustomInput) {
            discCustomInput.addEventListener('input', () => {
                modalDiscBtns.forEach(b => b.classList.remove('active'));
                this.clearDiscountModalAlert();
                this.updateDiscountModalPreview();
            });
            discCustomInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    this.applyDiscountFromModal();
                }
            });
        }

        // Modal backdrop click and keyboard handling (Enter / Escape)
        const modalDisc = document.getElementById('modalDiscount');
        if (modalDisc) {
            modalDisc.addEventListener('click', (e) => {
                if (e.target === modalDisc) {
                    this.closeDiscountModal();
                }
            });
            modalDisc.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    this.closeDiscountModal();
                } else if (e.key === 'Enter' && e.target.tagName !== 'BUTTON') {
                    e.preventDefault();
                    this.applyDiscountFromModal();
                }
            });
        }

        // Settings Modal Open / Close
        const btnOpenSettings = document.getElementById('btnOpenSettings');
        const btnCloseSettings = document.getElementById('btnCloseSettings');
        const btnCancelSettings = document.getElementById('btnCancelSettings');
        const modalSettings = document.getElementById('modalSettings');

        const openSettings = () => {
            this.loadSettingsForm();
            this.clearAlert('settingsAlert');
            document.getElementById('modalSettings')?.classList.add('open');
        };

        const closeSettings = () => {
            document.getElementById('modalSettings')?.classList.remove('open');
        };

        if (btnOpenSettings) {
            btnOpenSettings.addEventListener('click', openSettings);
        }

        if (btnCloseSettings) {
            btnCloseSettings.addEventListener('click', closeSettings);
        }

        if (btnCancelSettings) {
            btnCancelSettings.addEventListener('click', closeSettings);
        }

        // Test Connection in Settings
        const btnTest = document.getElementById('btnTestConnection');
        if (btnTest) {
            btnTest.addEventListener('click', () => this.handleTestConnection());
        }

        // Tab switching in Settings Modal (Back Office vs Thermal Printer vs Layout)
        const tabBtnBackoffice = document.getElementById('tabBtnSettingsBackoffice');
        const tabBtnPrinter = document.getElementById('tabBtnSettingsPrinter');
        const tabBtnLayout = document.getElementById('tabBtnSettingsLayout');
        const paneBackoffice = document.getElementById('settingsPaneBackoffice');
        const panePrinter = document.getElementById('settingsPanePrinter');
        const paneLayout = document.getElementById('settingsPaneLayout');

        const switchSettingsTab = (tabName) => {
            if (tabBtnBackoffice) tabBtnBackoffice.className = (tabName === 'backoffice') ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-outline';
            if (tabBtnPrinter) tabBtnPrinter.className = (tabName === 'printer') ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-outline';
            if (tabBtnLayout) tabBtnLayout.className = (tabName === 'layout') ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-outline';

            if (paneBackoffice) paneBackoffice.style.display = (tabName === 'backoffice') ? 'block' : 'none';
            if (panePrinter) panePrinter.style.display = (tabName === 'printer') ? 'block' : 'none';
            if (paneLayout) paneLayout.style.display = (tabName === 'layout') ? 'block' : 'none';
        };

        if (tabBtnBackoffice) tabBtnBackoffice.addEventListener('click', () => switchSettingsTab('backoffice'));
        if (tabBtnPrinter) tabBtnPrinter.addEventListener('click', () => switchSettingsTab('printer'));
        if (tabBtnLayout) tabBtnLayout.addEventListener('click', () => switchSettingsTab('layout'));

        // Layout Preview Cards Selection (Immediate live switch, no page reload)
        const cardRight = document.getElementById('layoutCardRight');
        const cardLeft = document.getElementById('layoutCardLeft');
        if (cardRight) {
            cardRight.addEventListener('click', () => {
                this.setLayoutPreference('right');
            });
        }
        if (cardLeft) {
            cardLeft.addEventListener('click', () => {
                this.setLayoutPreference('left');
            });
        }

        // Reset Layout to Default Button
        const btnResetLayout = document.getElementById('btnResetLayoutDefault');
        if (btnResetLayout) {
            btnResetLayout.addEventListener('click', () => {
                const staffId = this.currentUser?.id || this.currentUser?.username;
                PosStorage.resetLayoutPreference(staffId);
                this.applyLayoutPreference('right');
                this.showToast('success', I18nManager.t('layoutResetBtn') || 'Reset to Default');
            });
        }

        // Connection Type Change in Printer Settings
        const cfgPrinterType = document.getElementById('cfgPrinterType');
        if (cfgPrinterType) {
            cfgPrinterType.addEventListener('change', (e) => {
                const netGroup = document.getElementById('cfgPrinterNetworkGroup');
                if (netGroup) {
                    netGroup.style.display = (e.target.value === 'usb') ? 'none' : 'block';
                }
            });
        }

        // Test Printer button in Settings
        const btnTestPrinter = document.getElementById('btnTestPrinter');
        if (btnTestPrinter) {
            btnTestPrinter.addEventListener('click', () => this.handleTestPrinter());
        }

        // Save Settings
        const btnSaveSettings = document.getElementById('btnSaveSettings');
        if (btnSaveSettings) {
            btnSaveSettings.addEventListener('click', () => this.handleSaveSettings());
        }

        // Device Key Show/Hide Toggle & Paste Validation
        const cfgKeyEl = document.getElementById('cfgDeviceKey');
        const btnToggleKey = document.getElementById('btnToggleCfgDeviceKey');
        const iconToggleKey = document.getElementById('iconToggleCfgDeviceKey');
        if (btnToggleKey && cfgKeyEl) {
            btnToggleKey.addEventListener('click', () => {
                const isPass = cfgKeyEl.type === 'password';
                cfgKeyEl.type = isPass ? 'text' : 'password';
                if (iconToggleKey) {
                    iconToggleKey.className = isPass ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
                }
            });
        }
        if (cfgKeyEl) {
            cfgKeyEl.addEventListener('paste', (e) => {
                const pasted = (e.clipboardData || window.clipboardData)?.getData('text') || '';
                const clean = pasted.trim();
                if (clean && !clean.startsWith('pos_sec_')) {
                    e.preventDefault();
                    this.showAlert('settingsAlert', 'This is not a device key. The real key is shown only once when the device is created or its key is regenerated.', 'danger');
                }
            });
        }

        // Manual Refresh Catalog & Prices Button
        const btnManualSyncCatalog = document.getElementById('btnManualSyncCatalog');
        if (btnManualSyncCatalog) {
            btnManualSyncCatalog.addEventListener('click', () => {
                this.refreshCatalogSilently(true);
            });
        }
        const catalogSyncWrap = document.getElementById('catalogSyncWrap');
        if (catalogSyncWrap) {
            catalogSyncWrap.addEventListener('click', (e) => {
                if (e.target.closest('#btnManualSyncCatalog')) return;
                this.refreshCatalogSilently(true);
            });
        }

        // Staff Login Form
        const formLogin = document.getElementById('formLogin');
        if (formLogin) {
            formLogin.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleLogin();
            });
        }

        // 6-Digit PIN Keypad & Keyboard Listener (Requirement 1)
        this.bindPinEvents();

        // Auto-logout Idle Timer Activity Monitor (Requirement 3)
        this.setupIdleTimer();

        // Logout
        const btnLogout = document.getElementById('btnLogout');
        if (btnLogout) {
            btnLogout.addEventListener('click', () => this.handleLogout());
        }

        // Header Menu Dropdown (Replaces individual topbar tool buttons)
        const btnHeaderMenu = document.getElementById('btnHeaderMenu');
        const headerMenuDropdown = document.getElementById('headerMenuDropdown');
        if (btnHeaderMenu && headerMenuDropdown) {
            btnHeaderMenu.addEventListener('click', (e) => {
                e.stopPropagation();
                const isShowing = (headerMenuDropdown.style.display === 'block');
                headerMenuDropdown.style.display = isShowing ? 'none' : 'block';
                btnHeaderMenu.classList.toggle('active', !isShowing);
                btnHeaderMenu.setAttribute('aria-expanded', !isShowing ? 'true' : 'false');
            });

            document.addEventListener('click', (e) => {
                if (!e.target.closest('#headerMenuWrapper')) {
                    headerMenuDropdown.style.display = 'none';
                    btnHeaderMenu.classList.remove('active');
                    btnHeaderMenu.setAttribute('aria-expanded', 'false');
                }
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && headerMenuDropdown.style.display === 'block') {
                    headerMenuDropdown.style.display = 'none';
                    btnHeaderMenu.classList.remove('active');
                    btnHeaderMenu.setAttribute('aria-expanded', 'false');
                }
            });

            document.getElementById('menuItemTheme')?.addEventListener('click', () => {
                headerMenuDropdown.style.display = 'none';
                btnHeaderMenu.classList.remove('active');
                btnHeaderMenu.setAttribute('aria-expanded', 'false');
                const cur = PosStorage.getTheme() || 'light';
                const next = (cur === 'dark') ? 'light' : 'dark';
                PosStorage.setTheme(next);
                this.applyTheme(next);
            });

            document.getElementById('menuItemFullscreen')?.addEventListener('click', () => {
                headerMenuDropdown.style.display = 'none';
                btnHeaderMenu.classList.remove('active');
                btnHeaderMenu.setAttribute('aria-expanded', 'false');
                this.toggleFullscreen();
            });

            document.getElementById('menuItemSettings')?.addEventListener('click', () => {
                headerMenuDropdown.style.display = 'none';
                btnHeaderMenu.classList.remove('active');
                btnHeaderMenu.setAttribute('aria-expanded', 'false');
                openSettings();
            });

            document.getElementById('menuItemLogout')?.addEventListener('click', () => {
                headerMenuDropdown.style.display = 'none';
                btnHeaderMenu.classList.remove('active');
                btnHeaderMenu.setAttribute('aria-expanded', 'false');
                this.handleLogout();
            });
        }

        // Navigation Tabs (POS, Queue, Close Day)
        const navTabs = document.querySelectorAll('.nav-tab');
        navTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const viewName = tab.getAttribute('data-view');
                this.switchView(viewName);
            });
        });

        // Offline Sync Badge Click (Opens Pending Orders & Failure Reasons Modal - Requirement 12)
        const syncBadge = document.getElementById('syncBadge');
        if (syncBadge) {
            syncBadge.addEventListener('click', () => {
                this.openPendingOrdersModal();
            });
        }

        // Vehicle Type Switcher Buttons (Requirement 1)
        const vBtns = document.querySelectorAll('.vehicle-btn');
        vBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                vBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const prevVType = this.selectedVehicleType;
                this.selectedVehicleType = btn.getAttribute('data-vtype');
                // Dim categories with no active items for this vehicle type & refresh catalog
                this.renderCategories();
                this.renderCatalog();
                if (prevVType !== this.selectedVehicleType) {
                    this.updateVehiclePricesInCart(true);
                }
            });
        });

        const cfgPic = document.getElementById('cfgShowPictures');
        if (cfgPic) {
            cfgPic.addEventListener('change', (e) => {
                const staffId = this.currentUser?.id || this.currentUser?.username;
                PosStorage.setShowPictures(e.target.checked, staffId);
                this.updatePicturesToggleUI();
                this.renderCatalog();
            });
        }
        const cfgDark = document.getElementById('cfgDarkTheme');
        if (cfgDark) {
            cfgDark.addEventListener('change', (e) => {
                PosStorage.setTheme(e.target.checked ? 'dark' : 'light');
                this.applyTheme();
            });
        }

        // Product Image Lightbox Preview Modal Listeners (Requirement 6)
        const modalPreview = document.getElementById('modalProductImagePreview');
        document.getElementById('btnCloseImagePreview')?.addEventListener('click', () => this.closeProductImageModal());
        document.getElementById('btnCancelImagePreview')?.addEventListener('click', () => this.closeProductImageModal());
        if (modalPreview) {
            modalPreview.addEventListener('click', (e) => {
                if (e.target === modalPreview) this.closeProductImageModal();
            });
        }
        window.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeProductImageModal();
            }
        });

        // Customer Search Input & Autocomplete
        const searchInput = document.getElementById('custSearchInput');
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                const panel = document.getElementById('panelSavedCustomers');
                if (panel && panel.style.display !== 'none') {
                    document.getElementById('custDropdown')?.classList.remove('open');
                    this.handleSavedCustomersSearch(e.target.value);
                } else {
                    this.handleCustomerSearch(e.target.value);
                }
            });
            searchInput.addEventListener('focus', (e) => {
                const panel = document.getElementById('panelSavedCustomers');
                if (panel && panel.style.display !== 'none') {
                    document.getElementById('custDropdown')?.classList.remove('open');
                    this.handleSavedCustomersSearch(e.target.value);
                } else {
                    this.handleCustomerSearch(e.target.value);
                }
            });
            searchInput.addEventListener('keydown', (e) => {
                const dropdown = document.getElementById('custDropdown');
                const isDropdownOpen = dropdown && dropdown.classList.contains('open');

                if (isDropdownOpen) {
                    const rows = dropdown.querySelectorAll('.cust-search-row');
                    const total = rows.length;

                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        if (total > 0) {
                            this.custSearchHighlightedIndex = (this.custSearchHighlightedIndex + 1) % total;
                            this.updateCustSearchHighlight(rows);
                        }
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        if (total > 0) {
                            this.custSearchHighlightedIndex = (this.custSearchHighlightedIndex - 1 + total) % total;
                            this.updateCustSearchHighlight(rows);
                        }
                    } else if (e.key === 'Enter') {
                        e.preventDefault();
                        if (total > 0) {
                            const targetIdx = this.custSearchHighlightedIndex >= 0 ? this.custSearchHighlightedIndex : 0;
                            const targetRow = rows[targetIdx];
                            if (targetRow) {
                                targetRow.click();
                            }
                        }
                    } else if (e.key === 'Escape') {
                        e.preventDefault();
                        dropdown.classList.remove('open');
                        this.custSearchHighlightedIndex = -1;
                    }
                }
            });
            document.addEventListener('click', (e) => {
                if (!e.target.closest('.search-box-wrap')) {
                    document.getElementById('custDropdown')?.classList.remove('open');
                    this.custSearchHighlightedIndex = -1;
                    this.closeSavedCustomersPanel();
                }
            });
            window.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    this.closeSavedCustomersPanel();
                    document.getElementById('custDropdown')?.classList.remove('open');
                    this.custSearchHighlightedIndex = -1;
                }
            });
        }

        // Initialize Saved Customers Directory Panel
        this.initSavedCustomersPanel();

        // Walk-in Guest Button
        const btnWalkIn = document.getElementById('btnWalkIn');
        if (btnWalkIn) {
            btnWalkIn.addEventListener('click', () => this.setCustomerToWalkIn());
        }

        // Reset Selected Customer
        const btnResetCust = document.getElementById('btnResetCustomer');
        if (btnResetCust) {
            btnResetCust.addEventListener('click', () => this.resetCustomer());
        }

        // Customer Modal (Open & Close)
        const btnOpenNewCust = document.getElementById('btnOpenNewCustomerModal');
        const btnCloseCust = document.getElementById('btnCloseCustomerModal');
        const btnCancelCust = document.getElementById('btnCancelCustomerModal');
        const modalCustomer = document.getElementById('modalCustomer');

        if (btnOpenNewCust) {
            btnOpenNewCust.addEventListener('click', () => {
                document.getElementById('formAddCustomer').reset();
                const vtypeEl = document.getElementById('newCustVehicleType');
                if (vtypeEl) {
                    vtypeEl.value = this.selectedVehicleType;
                    vtypeEl.dispatchEvent(new Event('change'));
                }
                modalCustomer.classList.add('open');
            });
        }

        const closeCustModal = () => modalCustomer.classList.remove('open');
        if (btnCloseCust) btnCloseCust.addEventListener('click', closeCustModal);
        if (btnCancelCust) btnCancelCust.addEventListener('click', closeCustModal);

        // Save New Customer
        const btnSaveCust = document.getElementById('btnSaveNewCustomer');
        if (btnSaveCust) {
            btnSaveCust.addEventListener('click', () => this.handleSaveNewCustomer());
        }

        // Clear Cart Button
        const btnClearCart = document.getElementById('btnClearCart');
        if (btnClearCart) {
            btnClearCart.addEventListener('click', () => this.clearCart());
        }

        // Discount Buttons
        const discBtns = document.querySelectorAll('.disc-btn');
        discBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                discBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const val = btn.getAttribute('data-disc');
                this.setDiscount(val);
            });
        });

        // Checkout Button (Opens Payment Modal)
        const btnCheckout = document.getElementById('btnOpenCheckout');
        if (btnCheckout) {
            btnCheckout.addEventListener('click', () => {
                if (this.isDayClosed) {
                    this.showSaleBlockedNotice(I18nManager.t('salesBlockedDayClosed') || 'Register is closed for today! New sales are blocked until reopened by a manager.', true);
                    return;
                }
                this.openPaymentModal();
            });
        }

        // Payment Modal Tabs (Cash, ABA, KHQR)
        const payTabs = document.querySelectorAll('.pay-tab');
        payTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                payTabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                this.switchPaymentMethod(tab.getAttribute('data-method'));
            });
        });

        // Close Payment Modal
        const btnClosePay = document.getElementById('btnClosePaymentModal');
        const btnCancelPay = document.getElementById('btnCancelPaymentModal');
        const modalPayment = document.getElementById('modalPayment');
        const closePayModal = () => modalPayment.classList.remove('open');
        if (btnClosePay) btnClosePay.addEventListener('click', closePayModal);
        if (btnCancelPay) btnCancelPay.addEventListener('click', closePayModal);

        // Cash Tender Input
        const cashTender = document.getElementById('cashTenderInput');
        if (cashTender) {
            cashTender.addEventListener('input', () => this.calculateChange());
        }

        // Exact Cash Button
        const btnExact = document.getElementById('btnExactCash');
        if (btnExact) {
            btnExact.addEventListener('click', () => {
                const total = this.calculateTotals().totalUsd;
                document.getElementById('cashTenderInput').value = total.toFixed(2);
                this.calculateChange();
            });
        }

        // Confirm Payment & Print Button
        const btnConfirmPay = document.getElementById('btnConfirmPaymentAndPrint');
        if (btnConfirmPay) {
            btnConfirmPay.addEventListener('click', () => this.handleConfirmPayment());
        }

        // Receipt Modal Close & Direct Print
        const btnCloseReceipt = document.getElementById('btnCloseReceiptModal');
        const btnCloseReceiptBtn = document.getElementById('btnCloseReceiptBtn');
        const modalReceipt = document.getElementById('modalReceipt');
        const closeReceipt = async () => {
            modalReceipt.classList.remove('open');
            if (this.wasFullscreenPreferred && !this.isFullscreen()) {
                await this.enterFullscreen();
            }
        };
        if (btnCloseReceipt) btnCloseReceipt.addEventListener('click', closeReceipt);
        if (btnCloseReceiptBtn) btnCloseReceiptBtn.addEventListener('click', closeReceipt);
        modalReceipt?.addEventListener('click', (e) => {
            if (e.target === modalReceipt) closeReceipt();
        });

        const btnPrintDirect = document.getElementById('btnPrintReceiptDirect');
        if (btnPrintDirect) {
            btnPrintDirect.addEventListener('click', () => {
                this.handlePrintReceiptButton();
            });
        }

        const btnPrintWasher = document.getElementById('btnPrintWasherReceipt');
        if (btnPrintWasher) {
            btnPrintWasher.addEventListener('click', () => {
                const ord = this.currentReceiptOrder || this.lastCompletedOrder;
                if (ord) PosThermalPrinter.printWasherTicket(ord);
            });
        }

        // Customer Arrived Button in Receipt Modal (Requirement 1)
        const btnReceiptArrived = document.getElementById('btnReceiptCustomerArrived');
        if (btnReceiptArrived) {
            btnReceiptArrived.addEventListener('click', () => {
                this.handleReceiptCustomerArrived();
            });
        }

        // Notification Bell & Dropdown (Requirement 4)
        const btnNotifBell = document.getElementById('btnNotificationBell');
        const notifDropdown = document.getElementById('notificationDropdown');
        if (btnNotifBell && notifDropdown) {
            btnNotifBell.addEventListener('click', (e) => {
                e.stopPropagation();
                const isHidden = notifDropdown.style.display === 'none';
                notifDropdown.style.display = isHidden ? 'block' : 'none';
                if (isHidden) {
                    this.pollNotifications();
                }
            });
            document.addEventListener('click', (e) => {
                if (!e.target.closest('#notifBellWrapper')) {
                    notifDropdown.style.display = 'none';
                }
            });
        }
        const btnMarkAllRead = document.getElementById('btnMarkAllNotifsRead');
        if (btnMarkAllRead) {
            btnMarkAllRead.addEventListener('click', (e) => {
                e.stopPropagation();
                this.handleMarkAllNotifsRead();
            });
        }

        // Washer Finish / Handshake Modal (Requirement 2 & 3)
        const btnCloseWasherFinish = document.getElementById('btnCloseWasherFinishModal');
        if (btnCloseWasherFinish) {
            btnCloseWasherFinish.addEventListener('click', () => {
                document.getElementById('modalWasherFinish')?.classList.remove('open');
            });
        }
        const btnFinishOnTime = document.getElementById('btnFinishOnTime');
        if (btnFinishOnTime) {
            btnFinishOnTime.addEventListener('click', () => {
                this.handleConfirmFinish(0);
            });
        }
        const btnFinishTookLonger = document.getElementById('btnFinishTookLonger');
        if (btnFinishTookLonger) {
            btnFinishTookLonger.addEventListener('click', () => {
                this.handleConfirmFinish(1);
            });
        }

        // Request Parts Modal (Requirement 3)
        const btnCloseReqParts = document.getElementById('btnCloseRequestPartsModal');
        const btnCancelReqParts = document.getElementById('btnCancelRequestParts');
        if (btnCloseReqParts) {
            btnCloseReqParts.addEventListener('click', () => {
                document.getElementById('modalRequestParts')?.classList.remove('open');
            });
        }
        if (btnCancelReqParts) {
            btnCancelReqParts.addEventListener('click', () => {
                document.getElementById('modalRequestParts')?.classList.remove('open');
            });
        }
        const btnSubmitReqParts = document.getElementById('btnSubmitRequestParts');
        if (btnSubmitReqParts) {
            btnSubmitReqParts.addEventListener('click', () => {
                this.handleSubmitRequestParts();
            });
        }
        const btnRefreshWasher = document.getElementById('btnRefreshWasherJobs');
        if (btnRefreshWasher) {
            btnRefreshWasher.addEventListener('click', () => {
                this.renderWasherScreen();
            });
        }
        const btnRefreshTech = document.getElementById('btnRefreshMechanicJobs');
        if (btnRefreshTech) {
            btnRefreshTech.addEventListener('click', () => {
                this.renderMechanicScreen();
            });
        }

        // Refresh Queue Button
        const btnRefreshQueue = document.getElementById('btnRefreshQueue');
        if (btnRefreshQueue) {
            btnRefreshQueue.addEventListener('click', () => this.renderJobQueue());
        }

        // Print Daily Closing Report Button
        const btnPrintDaily = document.getElementById('btnPrintDailyReport');
        if (btnPrintDaily) {
            btnPrintDaily.addEventListener('click', () => this.handlePrintDailyReport());
        }

        // Refresh Oil Reminders Button
        const btnRefreshOil = document.getElementById('btnRefreshOilReminders');
        if (btnRefreshOil) {
            btnRefreshOil.addEventListener('click', () => this.renderOilReminders());
        }

        // Pay Later Button
        const btnPayLater = document.getElementById('btnPayLater');
        if (btnPayLater) {
            btnPayLater.addEventListener('click', () => this.handlePayLaterOrder());
        }

        // Change Crew Modal Close Buttons
        const modalChangeCrew = document.getElementById('modalChangeCrew');
        const closeCrewModal = () => modalChangeCrew?.classList.remove('open');
        document.getElementById('btnCloseChangeCrewModal')?.addEventListener('click', closeCrewModal);
        document.getElementById('btnCancelChangeCrewModal')?.addEventListener('click', closeCrewModal);

        // Save Change Crew Button
        const btnSaveCrew = document.getElementById('btnSaveChangeCrewModal');
        if (btnSaveCrew) {
            btnSaveCrew.addEventListener('click', () => this.handleSaveChangeCrew());
        }

        // Cancel Order Modal Buttons
        const modalCancelOrder = document.getElementById('modalCancelOrder');
        const closeCancelModal = () => modalCancelOrder?.classList.remove('open');
        document.getElementById('btnCloseCancelModal')?.addEventListener('click', closeCancelModal);
        document.getElementById('btnDismissCancelModal')?.addEventListener('click', closeCancelModal);
        document.getElementById('btnConfirmCancelOrder')?.addEventListener('click', () => this.handleConfirmCancelOrder());

        // Pay Unpaid Order Modal Buttons
        const modalPayOrder = document.getElementById('modalPayOrder');
        const closePayOrder = () => modalPayOrder?.classList.remove('open');
        document.getElementById('btnClosePayOrderModal')?.addEventListener('click', closePayOrder);
        document.getElementById('btnCancelPayOrderModal')?.addEventListener('click', closePayOrder);
        document.getElementById('btnConfirmPayOrderSubmit')?.addEventListener('click', () => this.handleConfirmPayOrderSubmit());

        document.querySelectorAll('.pay-modal-method-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.pay-modal-method-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const m = btn.getAttribute('data-pm');
                this.payingMethod = m;
                const cashRow = document.getElementById('payModalCashRow');
                const refRow = document.getElementById('payModalRefRow');
                const abaBox = document.getElementById('payModalAbaBox');
                const khqrBox = document.getElementById('payModalKhqrBox');

                if (cashRow) cashRow.style.display = (m === 'cash') ? 'block' : 'none';
                if (refRow) refRow.style.display = (m === 'cash') ? 'none' : 'block';

                const settings = this.getSettings();
                if (abaBox) {
                    abaBox.style.display = (m === 'aba') ? 'block' : 'none';
                    if (m === 'aba') {
                        const numEl = document.getElementById('payOrderAbaAccNum');
                        const nameEl = document.getElementById('payOrderAbaAccName');
                        if (numEl) numEl.textContent = settings.aba_account_num || '';
                        if (nameEl) nameEl.textContent = settings.aba_account_name || '';
                    }
                }
                if (khqrBox) {
                    khqrBox.style.display = (m === 'khqr') ? 'block' : 'none';
                    if (m === 'khqr') {
                        const khqrImgEl = document.getElementById('payOrderKhqrImageElement');
                        const khqrIconEl = document.getElementById('payOrderKhqrDefaultIcon');
                        const khqrSrc = PosStorage.getKhqrDataUri() || this.resolveAssetUrl(settings.khqr_url || settings.khqr_image);
                        if (khqrSrc && khqrImgEl) {
                            khqrImgEl.src = khqrSrc;
                            khqrImgEl.style.display = 'block';
                            if (khqrIconEl) khqrIconEl.style.display = 'none';
                        } else {
                            if (khqrImgEl) khqrImgEl.style.display = 'none';
                            if (khqrIconEl) khqrIconEl.style.display = 'block';
                        }
                    }
                }
                this.updatePayOrderCalculations();
            });
        });

        document.getElementById('payOrderModalDiscount')?.addEventListener('input', () => this.updatePayOrderCalculations());
        document.getElementById('payOrderModalTendered')?.addEventListener('input', () => this.updatePayOrderCalculations());

        // Mileage Input & Next Oil Change Reminder (Requirement 2)
        const cartMileageInput = document.getElementById('cartMileageInput');
        if (cartMileageInput) {
            cartMileageInput.addEventListener('input', (e) => this.handleMileageChange(e.target.value));
        }

        // Today's Orders Search by Plate Input (Requirement 8)
        const searchOrdersPlate = document.getElementById('searchOrdersPlateInput');
        if (searchOrdersPlate) {
            searchOrdersPlate.addEventListener('input', (e) => {
                this.ordersPage = 1;
                this.ordersPageSize = 25;
                this.renderTodayOrders(e.target.value.trim(), this.currentOrderFilter || 'all');
            });
        }

        // Today's Orders Filter Buttons (All, Paid, Unpaid, Cancelled - Requirement 8)
        const orderFilterBtns = document.querySelectorAll('.orders-filter-bar button');
        orderFilterBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                orderFilterBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                const filter = btn.getAttribute('data-order-filter');
                this.currentOrderFilter = filter;
                this.ordersPage = 1;
                this.ordersPageSize = 25;
                const search = document.getElementById('searchOrdersPlateInput')?.value.trim() || '';
                this.renderTodayOrders(search, filter);
            });
        });

        // Refresh Today's Orders Button
        document.getElementById('btnRefreshTodayOrders')?.addEventListener('click', () => {
            this.ordersPage = 1;
            this.ordersPageSize = 25;
            const search = document.getElementById('searchOrdersPlateInput')?.value.trim() || '';
            this.renderTodayOrders(search, this.currentOrderFilter || 'all');
        });

        // Today's Orders Pagination & Load More Controls
        document.getElementById('btnOrdersPrevPage')?.addEventListener('click', () => {
            if (this.ordersPage > 1) {
                this.ordersPage--;
                const search = document.getElementById('searchOrdersPlateInput')?.value.trim() || '';
                this.renderTodayOrders(search, this.currentOrderFilter || 'all', true);
            }
        });
        document.getElementById('btnOrdersNextPage')?.addEventListener('click', () => {
            this.ordersPage++;
            const search = document.getElementById('searchOrdersPlateInput')?.value.trim() || '';
            this.renderTodayOrders(search, this.currentOrderFilter || 'all', true);
        });
        document.getElementById('btnOrdersLoadMore')?.addEventListener('click', () => {
            this.ordersPageSize = (this.ordersPageSize || 25) + 25;
            const search = document.getElementById('searchOrdersPlateInput')?.value.trim() || '';
            this.renderTodayOrders(search, this.currentOrderFilter || 'all', true);
        });
        document.getElementById('btnOrdersShowAll')?.addEventListener('click', () => {
            this.ordersPageSize = 999999;
            this.ordersPage = 1;
            const search = document.getElementById('searchOrdersPlateInput')?.value.trim() || '';
            this.renderTodayOrders(search, this.currentOrderFilter || 'all', true);
        });

        // URL hash change listener for tab switching
        window.addEventListener('hashchange', () => {
            const h = (window.location.hash || '').replace(/^#/, '');
            if (h && h !== this.currentView && document.getElementById(`view${h.charAt(0).toUpperCase() + h.slice(1)}`)) {
                this.switchView(h);
            }
        });

        // Refresh Worker Jobs Buttons (Requirement 13)
        document.getElementById('btnRefreshWasherJobs')?.addEventListener('click', () => this.renderWasherScreen());
        document.getElementById('btnRefreshMechanicJobs')?.addEventListener('click', () => this.renderMechanicScreen());

        // Edit Repair Notes Modal Close & Save Buttons (Requirement 3, 13)
        const modalEditNotes = document.getElementById('modalEditRepairNotes');
        const closeEditNotes = () => modalEditNotes?.classList.remove('open');
        document.getElementById('btnCloseEditNotesModal')?.addEventListener('click', closeEditNotes);
        document.getElementById('btnCancelEditNotesModal')?.addEventListener('click', closeEditNotes);
        document.getElementById('btnSaveRepairNotesModal')?.addEventListener('click', () => this.handleSaveRepairNotesModal());

        // Stock Alert Modal Close Buttons (Requirement 11)
        const modalStockAlert = document.getElementById('modalStockAlert');
        const closeStockAlert = () => modalStockAlert?.classList.remove('open');
        document.getElementById('btnCloseStockAlert')?.addEventListener('click', closeStockAlert);
        document.getElementById('btnOkStockAlert')?.addEventListener('click', closeStockAlert);

        // Pending Offline Orders Modal Close & Retry All Buttons (Requirement 12)
        const modalPending = document.getElementById('modalPendingOrders');
        const closePending = () => modalPending?.classList.remove('open');
        document.getElementById('btnClosePendingModal')?.addEventListener('click', closePending);
        document.getElementById('btnDismissPendingModal')?.addEventListener('click', closePending);
        document.getElementById('btnRetryAllPending')?.addEventListener('click', () => this.syncPendingOrdersNow());

        // Cash Drawer Audit Calculations & Save (Requirement 10)
        document.getElementById('drawerCountUsd')?.addEventListener('input', () => this.calculateDrawerVariance());
        document.getElementById('drawerCountKhr')?.addEventListener('input', () => this.calculateDrawerVariance());
        document.getElementById('btnSaveDrawerAudit')?.addEventListener('click', () => this.saveDrawerAudit());

        // Queue error retry
        document.getElementById('btnRetryQueue')?.addEventListener('click', () => this.renderJobQueue());

        // Orders retry and date filter
        document.getElementById('btnRetryOrders')?.addEventListener('click', () => this.renderTodayOrders());
        document.getElementById('ordersDateFilter')?.addEventListener('change', () => this.renderTodayOrders());

        // Oil retry, search, filter pills, auto-refresh
        document.getElementById('btnRetryOil')?.addEventListener('click', () => this.renderOilReminders());
        document.getElementById('oilSearchInput')?.addEventListener('input', () => this.renderOilReminders());
        document.querySelectorAll('#oilFilterPills button').forEach(b => {
            b.addEventListener('click', () => {
                document.querySelectorAll('#oilFilterPills button').forEach(btn => btn.classList.remove('active'));
                b.classList.add('active');
                this.currentOilFilter = b.getAttribute('data-oil-filter') || 'all';
                this.renderOilReminders();
            });
        });
        const oilAutoToggle = document.getElementById('oilAutoRefreshToggle');
        if (oilAutoToggle) {
            clearInterval(this.oilAutoRefreshTimer);
            this.oilAutoRefreshTimer = setInterval(() => {
                if (document.getElementById('oilAutoRefreshToggle')?.checked && document.getElementById('viewOil')?.style.display !== 'none') {
                    this.renderOilReminders();
                }
            }, 60000);
        }

        // Daily Close filters, buttons, pagination
        document.getElementById('closeDateFilter')?.addEventListener('change', () => this.renderDailyClose());
        document.getElementById('closeDeviceFilter')?.addEventListener('change', () => this.renderDailyClose());
        document.getElementById('btnRefreshDailyReport')?.addEventListener('click', () => this.renderDailyClose());
        document.getElementById('btnCloseDaySubmit')?.addEventListener('click', () => this.closeDaySubmit());
        document.getElementById('btnReopenDay')?.addEventListener('click', () => this.reopenDaySubmit());
        document.getElementById('btnClosePrevPage')?.addEventListener('click', () => {
            if (this.closeOrdersPage > 1) {
                this.closeOrdersPage--;
                this.renderDailyClose();
            }
        });
        document.getElementById('btnCloseNextPage')?.addEventListener('click', () => {
            this.closeOrdersPage++;
            this.renderDailyClose();
        });

        // Order Success Modal Buttons (Requirement 1: Customer Arrived button)
        document.getElementById('btnCustomerArrivedOrderSuccess')?.addEventListener('click', async () => {
            const order = this.lastCreatedOrderRecord;
            document.getElementById('modalOrderSuccess')?.classList.remove('open');
            if (order) {
                await this.handleCustomerArrived(order.id || order.order_id, order.plate_number);
                this.switchView('queue');
            }
        });
        document.getElementById('btnCloseOrderSuccessModal')?.addEventListener('click', () => {
            document.getElementById('modalOrderSuccess')?.classList.remove('open');
            this.switchView('queue');
        });
        document.getElementById('btnPrintTicketOrderSuccess')?.addEventListener('click', () => {
            document.getElementById('modalOrderSuccess')?.classList.remove('open');
            if (this.lastCreatedOrderRecord) {
                this.currentReceiptOrder = this.lastCreatedOrderRecord;
                this.renderReceipt(this.lastCreatedOrderRecord);
                const cfg = PosConfig.get();
                if (cfg.printerDirectEnabled) {
                    this.executeSilentPrint(this.lastCreatedOrderRecord);
                } else {
                    document.getElementById('modalReceipt')?.classList.add('open');
                }
            }
        });
        document.getElementById('btnPrintWasherOrderSuccess')?.addEventListener('click', () => {
            if (this.lastCreatedOrderRecord) {
                PosThermalPrinter.printWasherTicket(this.lastCreatedOrderRecord);
            }
        });

        // Choose Bay Modal Close / Cancel
        const closeChooseBay = () => document.getElementById('modalChooseBay')?.classList.remove('open');
        document.getElementById('btnCloseChooseBayModal')?.addEventListener('click', closeChooseBay);
        document.getElementById('btnCancelChooseBayModal')?.addEventListener('click', closeChooseBay);

        // Refresh Washer Screen Button
        document.getElementById('btnRefreshWasherJobs')?.addEventListener('click', async () => {
            await this.fetchBaysAndQueue();
            await this.renderWasherScreen();
        });

        // Floating Thermal Printer Alert Banner Actions (Requirement 4)
        document.getElementById('btnDismissPrinterBanner')?.addEventListener('click', () => {
            this.hidePrinterAlert();
        });
        document.getElementById('btnRetryPrinterBanner')?.addEventListener('click', () => {
            if (this.lastFailedPrintOrder) {
                this.executeSilentPrint(this.lastFailedPrintOrder);
            }
        });

        // ========================================================
        // CASHIER CONTROLS & SECURITY EVENT LISTENERS
        // ========================================================
        // 1. Shift Controls
        document.getElementById('btnConfirmStartShift')?.addEventListener('click', () => this.handleStartShiftSubmit());
        document.getElementById('menuItemEndShift')?.addEventListener('click', () => {
            document.getElementById('headerMenuDropdown').style.display = 'none';
            this.showEndShiftModal();
        });
        document.getElementById('btnCloseEndShiftModal')?.addEventListener('click', () => {
            document.getElementById('modalEndShift')?.classList.remove('open');
        });
        document.getElementById('btnCancelEndShift')?.addEventListener('click', () => {
            document.getElementById('modalEndShift')?.classList.remove('open');
        });
        document.getElementById('btnConfirmEndShift')?.addEventListener('click', () => this.handleEndShiftSubmit());

        // 2. Manager PIN Modal
        document.getElementById('btnCancelMgrPin')?.addEventListener('click', () => {
            document.getElementById('modalManagerPin')?.classList.remove('open');
            this.mgrPinInput = '';
            this.managerPinCallback = null;
        });
        document.getElementById('btnConfirmMgrPin')?.addEventListener('click', () => this.handleConfirmMgrPin());

        document.querySelectorAll('#mgrPinNumpad .pin-key[data-digit]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const digit = btn.getAttribute('data-digit');
                if (this.mgrPinInput.length < 6) {
                    this.mgrPinInput += digit;
                    this.updateMgrPinDots();
                    if (this.mgrPinInput.length === 6) {
                        setTimeout(() => this.handleConfirmMgrPin(), 120);
                    }
                }
            });
        });
        document.getElementById('btnMgrPinClear')?.addEventListener('click', (e) => {
            e.stopPropagation();
            this.mgrPinInput = '';
            this.updateMgrPinDots();
        });
        document.getElementById('btnMgrPinBackspace')?.addEventListener('click', (e) => {
            e.stopPropagation();
            if (this.mgrPinInput.length > 0) {
                this.mgrPinInput = this.mgrPinInput.slice(0, -1);
                this.updateMgrPinDots();
            }
        });

        // 3. Stock Override via Manager PIN
        document.getElementById('btnStockOverrideMgr')?.addEventListener('click', () => {
            document.getElementById('modalStockAlert')?.classList.remove('open');
            const prod = this.pendingStockOverrideProd;
            if (!prod) return;
            this.requestManagerPin(
                "Stock Override",
                `Manager PIN authorization required to add zero-stock item: ${prod.name_en}`,
                (mgr) => {
                    this.addOverrideProductToCart(prod, mgr);
                }
            );
        });

        // 4. Spot Audit
        document.getElementById('menuItemSpotAudit')?.addEventListener('click', () => {
            document.getElementById('headerMenuDropdown').style.display = 'none';
            this.showSpotAuditOverlay();
        });
        document.getElementById('btnCancelSpotAudit')?.addEventListener('click', () => {
            document.getElementById('overlaySpotAudit').style.display = 'none';
        });
        document.getElementById('btnSubmitSpotAudit')?.addEventListener('click', () => this.handleSpotAuditSubmit());

        // 5. Slip Photo Capture in Payment Modal
        this.setupSlipPhotoHandlers();

        // 6. Wait Time & Appointment Modal Listeners
        const closeLongWait = () => {
            document.getElementById('modalLongWaitWarning')?.classList.remove('open');
            this._dismissedOver45Key = this.getCartFingerprint();
        };
        document.getElementById('btnCloseLongWaitModal')?.addEventListener('click', closeLongWait);
        document.getElementById('btnContinueAnyway')?.addEventListener('click', closeLongWait);
        document.getElementById('btnOfferBookAppointment')?.addEventListener('click', () => {
            console.log('btnOfferBookAppointment clicked! Switching to appointments view...');
            document.getElementById('modalLongWaitWarning')?.classList.remove('open');
            this._dismissedOver45Key = this.getCartFingerprint();
            this.switchView('appointments');
            const firstSvc = this.cart.find(it => it.type === 'service');
            if (firstSvc) {
                const sel = document.getElementById('apptServiceSelect');
                if (sel) sel.value = firstSvc.service_id || firstSvc.id;
            }
            if (this.currentCustomer?.plate) {
                const pIn = document.getElementById('apptPlateInput');
                if (pIn) pIn.value = this.currentCustomer.plate;
            }
            if (this.currentCustomer?.name) {
                const nIn = document.getElementById('apptCustomerName');
                if (nIn) nIn.value = this.currentCustomer.name;
            }
            if (this.currentCustomer?.phone) {
                const phIn = document.getElementById('apptCustomerPhone');
                if (phIn) phIn.value = this.currentCustomer.phone;
            }
            this.loadAppointmentSlots();
        });

        // 7. Appointments View Event Handlers
        document.getElementById('apptBookingDate')?.addEventListener('change', () => this.loadAppointmentSlots());
        document.getElementById('apptServiceSelect')?.addEventListener('change', () => this.loadAppointmentSlots());
        document.getElementById('formBookAppt')?.addEventListener('submit', (e) => this.handleSaveAppointment(e));
        document.getElementById('btnSaveAppt')?.addEventListener('click', (e) => this.handleSaveAppointment(e));
        document.getElementById('apptViewDate')?.addEventListener('change', () => this.loadAppointmentsList());
        document.getElementById('btnRefreshAppointments')?.addEventListener('click', async () => {
            await this.loadAppointmentsList();
            await this.loadAppointmentSlots();
        });

        // 8. Vehicle Profile Auto-Lookup on Customer Search / Plate Input
        const custSearchInput = document.getElementById('custSearchInput');
        if (custSearchInput) {
            custSearchInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    const q = (custSearchInput.value || '').trim();
                    if (q.length >= 2) {
                        this.loadVehicleProfile(q);
                    }
                }
            });
        }

        // 9. Cart Price Change Modal Listeners (Requirement 2)
        document.getElementById('btnClosePriceChangeModal')?.addEventListener('click', () => this.handleRejectPriceChange());
        document.getElementById('btnKeepOldPrice')?.addEventListener('click', () => this.handleRejectPriceChange());
        document.getElementById('btnUseNewPrice')?.addEventListener('click', () => this.handleAcceptPriceChange());

        // 10. Message Permission Toggle in Add Customer Modal (Requirement 4)
        const lblMsgYes = document.getElementById('lblMsgConsentYes');
        const lblMsgNo = document.getElementById('lblMsgConsentNo');
        const radMsgYes = document.getElementById('msgConsentYes');
        const radMsgNo = document.getElementById('msgConsentNo');

        const setConsent = (isYes) => {
            if (radMsgYes) radMsgYes.checked = isYes;
            if (radMsgNo) radMsgNo.checked = !isYes;
            lblMsgYes?.classList.toggle('active', isYes);
            lblMsgNo?.classList.toggle('active', !isYes);
        };

        lblMsgYes?.addEventListener('click', (e) => {
            e.preventDefault();
            setConsent(true);
        });
        lblMsgNo?.addEventListener('click', (e) => {
            e.preventDefault();
            setConsent(false);
        });

        // 11. Loyalty Reward Prompt Action Buttons (Requirement 3)
        document.getElementById('btnGiveLoyaltyReward')?.addEventListener('click', () => this.handleGiveLoyaltyReward());
        document.getElementById('btnSkipLoyaltyReward')?.addEventListener('click', () => this.handleSkipLoyaltyReward());

        // 12. Upsell Modal Listeners (Requirement 2: Friendly, non-blocking window with Accept and Not now)
        document.getElementById('btnAcceptUpsell')?.addEventListener('click', () => this.handleAcceptUpsell());
        document.getElementById('btnDeclineUpsell')?.addEventListener('click', () => this.handleDeclineUpsell());
        document.getElementById('btnCloseUpsellModal')?.addEventListener('click', () => this.handleDeclineUpsell());
    }

    /**
     * Online / Offline Network Monitor & Periodic Auto-Sync
     */
    static setupNetworkMonitor() {
        // Continuous Offline Banner Monitor (Requirement 3: Lost connection for >60s)
        setInterval(() => {
            this.updateOfflineManualBanner();
        }, 2000);

        window.addEventListener('online', () => {
            this.setOnlineStatus(true);
            this.handleBaysOnline();
            this.checkConnectionHealth();
            this.syncPendingOrdersNow();
            this.refreshCatalogSilently(false);
            this.fetchBaysAndQueue();
        });

        window.addEventListener('offline', () => {
            this.setOnlineStatus(false);
            this.handleBaysOffline();
        });

        // Periodic ping to back office every 20 seconds
        this.pingInterval = setInterval(() => {
            this.checkConnectionHealth();
        }, 20000);

        // Background offline sync retry every 30 seconds
        this.syncInterval = setInterval(() => {
            if (this.isOnline) {
                this.syncPendingOrdersNow();
            }
        }, 30000);

        // Automatic background catalog & price refresh every 75 seconds (1.25 minutes) without interrupting cashier
        this.catalogInterval = setInterval(() => {
            if (this.currentUser) {
                this.refreshCatalogSilently(false);
            }
        }, 75000);

        // Automatic Shop Floor polling every 3.5 seconds (Requirement 5)
        this.baysPollingInterval = setInterval(() => {
            if (this.currentUser) {
                this.pollShopFloorData();
            }
        }, 3500);
    }

    /**
     * Silent Background / Manual Catalog Refresh
     * Updates services, prices, products, and stock from back office
     * without interrupting the cashier or modifying open order prices.
     */
    static async refreshCatalogSilently(isManual = false) {
        const icon = document.getElementById('syncCatalogIcon');
        if (isManual && icon) {
            icon.classList.add('fa-spin');
        }

        try {
            const data = await PosApi.syncCatalog();
            if (data && data.success) {
                this.setOnlineStatus(true);
                PosStorage.setItem('carwash_pos_has_connected', 'true');
                if (data.system_revision?.hash) {
                    this.lastSystemRevision = data.system_revision.hash;
                }

                // Cache logo and KHQR as offline data URIs
                await this.cacheSettingsAssets(data.settings || data.shop || {});

                // Apply shop logo and branding
                this.applyShopBranding();

                // Re-render categories and catalog cards with new prices/products/stock
                this.renderCategories();
                this.renderCatalog();
                this.populateCrewDropdowns();

                // Background sync and cache product pictures (Requirement 4)
                if (data.products) {
                    this.syncProductImagesSilently(data.products).catch(e => console.warn('Product image sync err:', e));
                }

                // Update timestamp display
                this.updateCatalogSyncStatus(true);

                // Background sync customers list only if staff session token is active
                if (typeof PosStorage !== 'undefined' && PosStorage.getSessionToken()) {
                    PosApi.syncCustomers().catch(() => {});
                }
            } else {
                this.setOnlineStatus(false);
                this.updateCatalogSyncStatus(false);
                this.applyShopBranding();
            }
        } catch (err) {
            console.warn('Background catalog sync failed (server unreachable):', err);
            this.setOnlineStatus(false);
            this.updateCatalogSyncStatus(false);
            this.applyShopBranding();
        } finally {
            if (icon) {
                setTimeout(() => icon.classList.remove('fa-spin'), 350);
            }
        }
    }

    /**
     * Updates the "Last updated" time or "Offline" status in top bar
     * and shows stale price warning banner if offline.
     */
    static updateCatalogSyncStatus(isOnlineState = null) {
        if (isOnlineState !== null) {
            this.isOnline = isOnlineState;
        }

        const indicator = document.getElementById('catalogSyncIndicator');
        const dot = document.getElementById('catalogSyncDot');
        const text = document.getElementById('catalogLastUpdatedText');
        const notice = document.getElementById('stalePriceNotice');
        const noticeTime = document.getElementById('stalePriceTime');

        const updatedAt = PosStorage.getCatalogUpdatedAt();
        let formattedTime = 'Never';

        if (updatedAt) {
            try {
                const date = new Date(updatedAt);
                if (!isNaN(date.getTime())) {
                    formattedTime = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                } else {
                    formattedTime = updatedAt;
                }
            } catch (e) {
                formattedTime = updatedAt;
            }
        }

        const lang = I18nManager.getLang();
        const prefix = lang === 'km' ? 'ធ្វើបច្ចុប្បន្នភាពចុងក្រោយ: ' : 'Last updated: ';
        const offlineText = lang === 'km' ? 'ដាច់បណ្តាញ' : 'Offline';

        if (this.isOnline) {
            if (dot) dot.className = 'sync-dot';
            if (indicator) {
                indicator.className = 'catalog-sync-indicator';
                indicator.title = `${prefix}${formattedTime}`;
            }
            if (text) {
                text.innerHTML = `<span class="sync-time">${formattedTime}</span>`;
            }
            const wrap = document.getElementById('catalogSyncWrap');
            if (wrap) wrap.title = `${prefix}${formattedTime}`;
            if (notice) notice.style.display = 'none';
        } else {
            if (dot) dot.className = 'sync-dot offline';
            if (indicator) {
                indicator.className = 'catalog-sync-indicator offline';
                indicator.title = `${prefix}${offlineText}`;
            }
            if (text) {
                text.innerHTML = `<span class="sync-time">${offlineText}</span>`;
            }
            const wrap = document.getElementById('catalogSyncWrap');
            if (wrap) wrap.title = offlineText;
            if (notice) {
                notice.style.display = 'flex';
                if (noticeTime) noticeTime.textContent = formattedTime;
            }
        }
    }

    static async checkConnectionHealth() {
        try {
            const res = await PosApi.testConnection();
            if (res.success) {
                this.setOnlineStatus(true);
                PosStorage.setItem('carwash_pos_has_connected', 'true');
                // Refresh catalog, prices, and shop branding from back office
                await this.refreshCatalogSilently(false);
            } else {
                this.setOnlineStatus(false);
            }
        } catch (e) {
            this.setOnlineStatus(false);
        }
    }

    static setOnlineStatus(online) {
        this.isOnline = online;
        const pill = document.getElementById('networkPill');
        const text = document.getElementById('networkText');
        if (pill && text) {
            if (online) {
                pill.className = 'status-pill online';
                text.textContent = I18nManager.t('online');
            } else {
                pill.className = 'status-pill offline';
                text.textContent = I18nManager.t('offline');
            }
        }
        this.updateCatalogSyncStatus(online);
        this.updateOfflineManualBanner();
        if (online) {
            this.handleBaysOnline();
        } else {
            this.handleBaysOffline();
        }
    }

    static updatePendingSyncBadge() {
        const queue = PosStorage.getPendingOrders();
        const badge = document.getElementById('syncBadge');
        const count = document.getElementById('pendingCount');
        if (!badge || !count) return;

        if (queue.length > 0) {
            badge.classList.remove('synced');
            count.textContent = queue.length;
        } else {
            badge.classList.add('synced');
            count.textContent = '0';
        }
    }

    /**
     * Check active staff session
     */
    static async checkSession() {
        let user = PosStorage.getUser();
        let token = PosStorage.getSessionToken();

        // If no token, check if server has an active web session (e.g. from Back Office login)
        if (!token) {
            try {
                const res = await PosApi.getWebSession();
                if (res && res.success && res.session_token && res.user) {
                    token = res.session_token;
                    user = res.user;
                    PosStorage.setSessionToken(token);
                    PosStorage.setUser(user);
                }
            } catch (e) {
                // Not authenticated in web session
            }
        }

        if (user && token) {
            this.currentUser = user;
            this.renderLoggedInState();
            // Automatically update catalog & prices in background without interrupting
            this.refreshCatalogSilently(false);
            // Retry pending offline orders automatically
            this.syncPendingOrdersNow();
        } else {
            PosStorage.clearUser();
            this.currentUser = null;
            this.renderLoggedOutState();
            // Check test mode silently on initial startup
            this.refreshCatalogSilently(false).then(() => {
                this.updateTestModeQuickAccounts();
            }).catch(() => {});
        }
    }

    /**
     * Requirement 1: 6-Digit PIN Event Binding & Keyboard Handling
     */
    static bindPinEvents() {
        const pinPad = document.getElementById('pinPadGrid');
        if (pinPad) {
            pinPad.addEventListener('click', (e) => {
                const btn = e.target.closest('.pin-pad-btn');
                if (!btn) return;
                const key = btn.getAttribute('data-key');
                if (key) {
                    this.handlePinInput(key);
                }
            });
        }

        const pinDotsDisplay = document.getElementById('pinDotsDisplay');
        const pinHiddenInput = document.getElementById('loginPin');
        if (pinDotsDisplay && pinHiddenInput) {
            pinDotsDisplay.addEventListener('click', () => {
                pinHiddenInput.focus();
            });
            pinHiddenInput.addEventListener('input', (e) => {
                const val = e.target.value.replace(/\D/g, '').substring(0, 6);
                this.currentPin = val;
                this.updatePinDots();
                if (this.currentPin.length === 6) {
                    setTimeout(() => {
                        if (this.currentPin.length === 6) {
                            this.handleLogin();
                        }
                    }, 120);
                }
            });
        }

        // Global keyboard handling for PIN
        window.addEventListener('keydown', (e) => {
            const loginView = document.getElementById('viewLogin');
            if (!loginView || loginView.style.display === 'none') return;

            // When user is typing inside username input
            if (document.activeElement && document.activeElement.id === 'loginUsername') {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (pinHiddenInput) pinHiddenInput.focus();
                }
                return;
            }

            if (/^[0-9]$/.test(e.key)) {
                e.preventDefault();
                this.handlePinInput(e.key);
            } else if (e.key === 'Backspace') {
                e.preventDefault();
                this.handlePinInput('backspace');
            } else if (e.key === 'Escape' || e.key.toLowerCase() === 'c') {
                e.preventDefault();
                this.handlePinInput('clear');
            } else if (e.key === 'Enter') {
                e.preventDefault();
                this.handleLogin();
            }
        });
    }

    static handlePinInput(key) {
        if (this.lockSecondsRemaining > 0) return;

        if (key === 'clear') {
            this.currentPin = '';
            this.updatePinDots();
            return;
        }

        if (key === 'backspace') {
            this.currentPin = this.currentPin.slice(0, -1);
            this.updatePinDots();
            return;
        }

        if (/^[0-9]$/.test(key)) {
            if (this.currentPin.length < 6) {
                this.currentPin += key;
                this.updatePinDots();
                if (this.currentPin.length === 6) {
                    setTimeout(() => {
                        if (this.currentPin.length === 6) {
                            this.handleLogin();
                        }
                    }, 120);
                }
            }
        }
    }

    static updatePinDots() {
        const dots = document.querySelectorAll('#pinDotsDisplay .pin-dot');
        dots.forEach((dot, idx) => {
            dot.classList.toggle('filled', idx < this.currentPin.length);
        });

        const badge = document.getElementById('pinCountBadge');
        if (badge) {
            badge.textContent = `${this.currentPin.length} / 6`;
        }

        const hiddenInp = document.getElementById('loginPin');
        if (hiddenInp && hiddenInp.value !== this.currentPin) {
            hiddenInp.value = this.currentPin;
        }
    }

    static shakePinDots() {
        const display = document.getElementById('pinDotsDisplay');
        if (!display) return;
        display.classList.remove('shake');
        void display.offsetWidth; // trigger reflow
        display.classList.add('shake');
        setTimeout(() => {
            display.classList.remove('shake');
        }, 500);
    }

    /**
     * Requirement 1 & 2: Quick Fill Sample Credentials
     */
    static fillCredentials(username, pin) {
        const uInp = document.getElementById('loginUsername');
        if (uInp) uInp.value = username;

        this.currentPin = String(pin || '').trim();
        this.updatePinDots();
        this.clearAlert('loginAlert');

        setTimeout(() => {
            this.handleLogin();
        }, 120);
    }

    /**
     * Requirement 1: Handle Staff Login with 6-Digit PIN & Lockout Countdown
     */
    static async handleLogin() {
        if (this.lockSecondsRemaining > 0) return;

        const username = document.getElementById('loginUsername').value.trim();
        const pin = this.currentPin || document.getElementById('loginPin')?.value.trim() || '';
        const submitBtn = document.getElementById('btnLoginSubmit');

        if (!username) {
            this.showAlert('loginAlert', I18nManager.t('username') + ' required', 'danger');
            document.getElementById('loginUsername')?.focus();
            return;
        }

        if (!pin || pin.length !== 6) {
            this.showAlert('loginAlert', I18nManager.t('pinPlaceholder') || 'Please enter a 6-digit PIN', 'danger');
            this.shakePinDots();
            return;
        }

        submitBtn.disabled = true;
        submitBtn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> ${I18nManager.t('loggingIn')}`;
        this.clearAlert('loginAlert');

        try {
            const response = await PosApi.staffLogin(username, pin);
            if (response.success && response.user) {
                // Clear any active lock timers
                if (this.lockTimerInterval) {
                    clearInterval(this.lockTimerInterval);
                    this.lockTimerInterval = null;
                }
                this.lockSecondsRemaining = 0;
                const lockBanner = document.getElementById('loginLockBanner');
                if (lockBanner) lockBanner.style.display = 'none';
                const keypad = document.getElementById('pinPadGrid');
                if (keypad) keypad.classList.remove('disabled');

                this.currentPin = '';
                this.updatePinDots();

                this.currentUser = response.user;
                PosStorage.setUser(response.user);
                if (response.session_token) {
                    PosStorage.setSessionToken(response.session_token);
                }

                // Cache settings immediately if returned on login
                if (response.settings || response.shop) {
                    const merged = { ...(response.settings || {}), ...(response.shop || {}) };
                    PosStorage.setSettings(merged);
                    if (response.exchange_rate) {
                        PosConfig.save({ exchangeRate: parseInt(response.exchange_rate) });
                    }
                    this.applyShopBranding();
                    this.updateTestModeQuickAccounts();
                }

                this.lastActivityTime = Date.now();

                // Update services, prices, products automatically every time staff logs in
                await this.refreshCatalogSilently(false);
                await PosApi.syncCustomers().catch(() => {});

                this.renderLoggedInState();

                // Retry any pending offline orders queued on device
                this.syncPendingOrdersNow();
            } else {
                this.currentPin = '';
                this.updatePinDots();
                this.shakePinDots();
                this.showAlert('loginAlert', response.message || I18nManager.t('loginFailed'), 'danger');
            }
        } catch (err) {
            this.currentPin = '';
            this.updatePinDots();
            this.shakePinDots();

            const errData = err.data || {};
            const errCode = err.code || errData.error || '';

            if (errCode === 'ACCOUNT_LOCKED' || err.status === 423) {
                const remaining = parseInt(errData.remaining_minutes) || 10;
                this.startLockCountdown(remaining);
            } else if (errCode === 'INVALID_PIN') {
                const attempts = errData.remaining_attempts;
                const msg = (attempts !== undefined && attempts !== null)
                    ? (I18nManager.getLang() === 'km'
                        ? `លេខកូដ PIN មិនត្រឹមត្រូវ។ នៅសល់ ${attempts} លើកទៀតមុននឹងចាក់សោរ។`
                        : `Incorrect PIN. ${attempts} attempt${attempts === 1 ? '' : 's'} remaining before account lockout.`)
                    : (errData.message || err.message);
                this.showAlert('loginAlert', msg, 'danger');
            } else if (errCode === 'DEMO_DISABLED') {
                const msg = (I18nManager.getLang() === 'km')
                    ? 'គណនីសាកល្បងត្រូវបានបិទ ពីព្រោះ Test Mode ត្រូវបានបិទនៅក្នុង Back Office។'
                    : 'Sample account login is disabled because Test Mode is OFF in Back Office.';
                this.showAlert('loginAlert', msg, 'danger');
            } else {
                const msg = err.isOffline
                    ? 'Back Office is currently unreachable. Check connection settings.'
                    : (errData.message || err.message || I18nManager.t('loginFailed'));
                this.showAlert('loginAlert', msg, 'danger');
            }
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = `<i class="fa-solid fa-arrow-right-to-bracket"></i> <span data-i18n="loginBtn">${I18nManager.t('loginBtn')}</span>`;
        }
    }

    /**
     * Requirement 1: Countdown Timer after Lock Message from Back Office
     */
    static startLockCountdown(minutes) {
        this.lockSecondsRemaining = Math.max(1, parseInt(minutes) || 10) * 60;

        const banner = document.getElementById('loginLockBanner');
        const alertEl = document.getElementById('loginAlert');
        const keypad = document.getElementById('pinPadGrid');
        const submitBtn = document.getElementById('btnLoginSubmit');

        if (alertEl) alertEl.style.display = 'none';
        if (banner) banner.style.display = 'block';
        if (keypad) keypad.classList.add('disabled');
        if (submitBtn) submitBtn.disabled = true;

        if (this.lockTimerInterval) clearInterval(this.lockTimerInterval);

        const updateDisplay = () => {
            const m = Math.floor(this.lockSecondsRemaining / 60);
            const s = this.lockSecondsRemaining % 60;
            const mmss = `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;

            const timerEl = document.getElementById('lockCountdownTimer');
            if (timerEl) timerEl.textContent = mmss;

            const textEl = document.getElementById('lockMinutesText');
            if (textEl) {
                const isKm = (I18nManager.getLang() === 'km');
                if (m > 0) {
                    textEl.textContent = isKm
                        ? `សូមរង់ចាំ ${m} នាទី ${s > 0 ? s + ' វិនាទី ' : ''}ទៀត`
                        : `Please wait ${m} minute(s) ${s > 0 ? s + 's ' : ''}before retrying`;
                } else {
                    textEl.textContent = isKm
                        ? `សូមរង់ចាំ ${s} វិនាទីទៀត`
                        : `Please wait ${s} second(s) before retrying`;
                }
            }
        };

        updateDisplay();

        this.lockTimerInterval = setInterval(() => {
            this.lockSecondsRemaining--;
            if (this.lockSecondsRemaining <= 0) {
                clearInterval(this.lockTimerInterval);
                this.lockSecondsRemaining = 0;
                if (banner) banner.style.display = 'none';
                if (keypad) keypad.classList.remove('disabled');
                if (submitBtn) submitBtn.disabled = false;

                const expireMsg = (I18nManager.getLang() === 'km')
                    ? 'ការចាក់សោរបានផុតកំណត់ហើយ។ លោកអ្នកអាចបញ្ចូលលេខកូដ PIN ម្តងទៀតបាន។'
                    : 'Account lock expired. You may now enter your PIN.';
                this.showAlert('loginAlert', expireMsg, 'info');
            } else {
                updateDisplay();
            }
        }, 1000);
    }

    /**
     * Requirement 2: Toggle Quick Sample-Account Buttons (Only when Test Mode is ON)
     */
    static updateTestModeQuickAccounts() {
        const settings = PosStorage.getSettings();
        const isTestMode = (settings?.demo_mode == '1' || settings?.demo_mode == 1 || settings?.demo_mode === true);
        const hints = document.getElementById('quickLoginHints');
        if (hints) {
            hints.style.display = isTestMode ? 'flex' : 'none';
        }
    }

    /**
     * Requirement 3: Auto-logout Idle Timer Activity Monitor & Settings
     */
    static setupIdleTimer() {
        const resetActivity = () => {
            this.lastActivityTime = Date.now();
        };

        const events = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click'];
        let throttleTimeout = null;
        const throttledReset = () => {
            if (!throttleTimeout) {
                resetActivity();
                throttleTimeout = setTimeout(() => {
                    throttleTimeout = null;
                }, 1000);
            }
        };

        events.forEach(ev => {
            window.addEventListener(ev, throttledReset, { passive: true });
        });

        if (this.idleTimerInterval) clearInterval(this.idleTimerInterval);
        this.idleTimerInterval = setInterval(() => {
            this.checkIdleTimeout();
        }, 5000);
    }

    static checkIdleTimeout() {
        if (!this.currentUser) return;
        const cfg = PosConfig.get();
        const timeoutMins = parseInt(cfg.idleTimeoutMinutes);
        if (isNaN(timeoutMins) || timeoutMins <= 0) return; // 0 = disabled

        const elapsedMs = Date.now() - this.lastActivityTime;
        if (elapsedMs >= timeoutMins * 60 * 1000) {
            this.handleIdleTimeout();
        }
    }

    static handleIdleTimeout() {
        const lastUser = this.currentUser?.username || '';
        this.handleLogout(true);

        if (lastUser) {
            const userInp = document.getElementById('loginUsername');
            if (userInp) userInp.value = lastUser;
        }

        const msg = (I18nManager.getLang() === 'km')
            ? 'បានចាកចេញដោយស្វ័យប្រវត្តដោយសារគ្មានសកម្មភាព។ សូមបញ្ចូល PIN ឡើងវិញ។'
            : 'Session logged out due to inactivity. Please enter your PIN to continue.';
        this.showAlert('loginAlert', msg, 'warning');

        setTimeout(() => {
            document.getElementById('loginPin')?.focus();
        }, 200);
    }

    static async handleLogout(isIdle = false) {
        try {
            await PosApi.staffLogout();
        } catch (e) {}
        PosStorage.clearUser();
        this.currentUser = null;
        this.applyLayoutPreference('right');
        this.renderLoggedOutState(isIdle);
    }

    /**
     * Handle 401 SESSION_INVALID: clear user & show login screen while keeping pending orders
     */
    static handleSessionInvalid(message = null) {
        PosStorage.clearUser();
        this.currentUser = null;
        this.applyLayoutPreference('right');
        this.renderLoggedOutState(false);
        const msg = message || (I18nManager.getLang() === 'km'
            ? 'វគ្គការងាររបស់អ្នកបានផុតកំណត់។ សូមចូលប្រើប្រាស់ឡើងវិញ។'
            : 'Session expired or invalid. Please enter your PIN to continue.');
        this.showAlert('loginAlert', msg, 'warning');
    }

    /**
     * Requirement 3: Localized Role Display for Header User Pill
     */
    static getLocalizedRole(role) {
        const lang = I18nManager.getLang();
        const r = (role || '').toLowerCase();
        if (lang === 'km') {
            if (r === 'cashier') return 'អ្នកគិតលុយ';
            if (r === 'washer') return 'អ្នកលាងរថយន្ត';
            if (r === 'mechanic' || r === 'technical') return 'មេកានិច';
            if (r === 'owner') return 'ម្ចាស់';
            if (r === 'admin' || r === 'system_admin') return 'រដ្ឋបាលប្រព័ន្ធ';
            if (r === 'manager') return 'អ្នកគ្រប់គ្រង';
            if (r === 'wash_supervisor' || r === 'supervisor') return 'មេការលាងរថយន្ត';
            if (r === 'crm') return 'ទំនាក់ទំនងអតិថិជន';
            if (r === 'stock_control') return 'គ្រប់គ្រងស្តុក';
            return role || 'បុគ្គលិក';
        } else {
            if (r === 'cashier') return 'Cashier';
            if (r === 'washer') return 'Washer';
            if (r === 'mechanic' || r === 'technical') return 'Mechanic';
            if (r === 'owner') return 'Owner';
            if (r === 'admin' || r === 'system_admin') return 'System Admin';
            if (r === 'manager') return 'Manager';
            if (r === 'wash_supervisor' || r === 'supervisor') return 'Wash Supervisor';
            if (r === 'crm') return 'CRM Specialist';
            if (r === 'stock_control') return 'Stock Control';
            return role ? (role.charAt(0).toUpperCase() + role.slice(1)) : 'Staff';
        }
    }

    /**
     * Requirement 3: Show Logged-in Name and Role at Top
     */
    static updateUserHeaderInfo() {
        if (!this.currentUser) return;
        const roleName = this.currentUser.role_name_km && I18nManager.getLang() === 'km'
            ? this.currentUser.role_name_km
            : (this.currentUser.role_name_en && I18nManager.getLang() === 'en'
                ? this.currentUser.role_name_en
                : this.getLocalizedRole(this.currentUser.role));

        const rawName = this.currentUser.full_name || this.currentUser.username || 'Cashier';
        const cleanName = rawName.replace(/\s*\([^)]*\)/g, '').trim() || rawName;

        const userFullNameEl = document.getElementById('userFullName');
        const userRoleBadgeEl = document.getElementById('userRoleBadge');
        if (userFullNameEl) userFullNameEl.textContent = cleanName;
        if (userRoleBadgeEl) userRoleBadgeEl.textContent = roleName;

        const menuUserFull = document.getElementById('menuUserFullName');
        const menuUserRole = document.getElementById('menuUserRoleBadge');
        if (menuUserFull) menuUserFull.textContent = cleanName;
        if (menuUserRole) menuUserRole.textContent = roleName;

        const userNameEl = document.getElementById('userName');
        if (userNameEl && !userFullNameEl) {
            userNameEl.innerHTML = `<span class="user-fullname">${this.escapeHtml(cleanName)}</span><span class="user-role-badge">${this.escapeHtml(roleName)}</span>`;
        }

        const userPill = document.getElementById('userPill');
        if (userPill) {
            userPill.style.display = 'flex';
            userPill.title = `${cleanName} (${roleName})`;
        }
    }

    /**
     * Requirement 4: The POS must not decide what a user is allowed to do.
     * It shows only what the back office says is allowed.
     */
    static hasPermission(permissionCode) {
        if (!this.currentUser) return false;
        if (this.currentUser.role === 'owner') return true;
        const perms = Array.isArray(this.currentUser.permissions) ? this.currentUser.permissions : [];
        return perms.includes(permissionCode);
    }

    static canAnyPermission(permissionCodes) {
        if (!this.currentUser) return false;
        if (this.currentUser.role === 'owner') return true;
        const perms = Array.isArray(this.currentUser.permissions) ? this.currentUser.permissions : [];
        return permissionCodes.some(p => perms.includes(p));
    }

    static canAccessCustomerList() {
        return this.hasPermission('customers.list') || this.hasPermission('customers.view') || this.hasPermission('pos.access');
    }

    /**
     * Requirement 4: Render UI according strictly to back office permissions
     */
    static renderLoggedInState() {
        document.getElementById('viewLogin').style.display = 'none';
        document.getElementById('userPill').style.display = 'flex';
        document.getElementById('btnLogout').style.display = 'inline-flex';
        const menuItemLogout = document.getElementById('menuItemLogout');
        if (menuItemLogout) menuItemLogout.style.display = 'flex';

        this.updateUserHeaderInfo();
        this.applyLayoutPreference();
        this.updatePicturesToggleUI();
        this.renderCatalog();

        const btnSavedCust = document.getElementById('btnOpenSavedCustomers');
        if (btnSavedCust) {
            btnSavedCust.style.display = this.canAccessCustomerList() ? 'inline-flex' : 'none';
        }

        // Show direct topbar Back Office link for Managers & Owners
        const curRoleForHeader = (this.currentUser?.role || '').toLowerCase();
        const btnBo = document.getElementById('btnHeaderBackoffice');
        if (btnBo) {
            const isManagerOrAdmin = ['manager', 'owner', 'admin', 'system_admin'].includes(curRoleForHeader) || this.hasPermission('dashboard.view') || this.hasPermission('orders.list');
            btnBo.style.display = isManagerOrAdmin ? 'inline-flex' : 'none';
        }

        const mainNav = document.getElementById('mainNav');
        const washerNav = document.getElementById('washerNav');
        const mechanicNav = document.getElementById('mechanicNav');

        // Check Back Office Permissions & Roles
        const curRole = (this.currentUser?.role || '').toLowerCase();
        const canWasherDuty = this.hasPermission('washer_jobs.view') || curRole === 'washer';
        const canMechanicDuty = this.hasPermission('mechanic_jobs.view') || curRole === 'technical' || curRole === 'mechanic';
        const canPosAccess = (this.hasPermission('pos.access') || this.hasPermission('pos.create_order')) && curRole !== 'washer' && curRole !== 'technical' && curRole !== 'mechanic';
        const canQueueAccess = this.hasPermission('orders.list') || this.hasPermission('bays.view');
        const canOrdersAccess = this.hasPermission('orders.list');
        const canOilAccess = this.hasPermission('oil_care.list') || this.hasPermission('vehicle_history.list');
        const canCloseDayAccess = (this.hasPermission('reports.daily_close') || this.hasPermission('pos.close_day') || this.hasPermission('reports.view')) && curRole !== 'washer' && curRole !== 'technical';

        // Apply tab visibility based on Back Office permissions
        const tabPos = mainNav?.querySelector('[data-view="pos"]');
        const tabQueue = mainNav?.querySelector('[data-view="queue"]');
        const tabOrders = mainNav?.querySelector('[data-view="orders"]');
        const tabOil = mainNav?.querySelector('[data-view="oil"]');
        const tabClose = mainNav?.querySelector('[data-view="close"]');

        if (tabPos) tabPos.style.display = canPosAccess ? '' : 'none';
        if (tabQueue) tabQueue.style.display = canQueueAccess ? '' : 'none';
        if (tabOrders) tabOrders.style.display = canOrdersAccess ? '' : 'none';
        if (tabOil) tabOil.style.display = canOilAccess ? '' : 'none';
        if (tabClose) tabClose.style.display = canCloseDayAccess ? '' : 'none';

        // Check specialized worker screens (Washer tablet & Technical tablet)
        if (curRole === 'washer' || (canWasherDuty && !canPosAccess && !canCloseDayAccess)) {
            if (mainNav) mainNav.style.display = 'none';
            if (mechanicNav) mechanicNav.style.display = 'none';
            if (washerNav) washerNav.style.display = 'flex';
            const sub = document.getElementById('washerProfileSubtitle');
            if (sub) sub.textContent = `${this.currentUser.full_name} (Washer Duty Screen)`;
            this.switchView('washer');
            this.renderWasherScreen();
        } else if (curRole === 'technical' || curRole === 'mechanic' || (canMechanicDuty && !canPosAccess && !canCloseDayAccess)) {
            if (mainNav) mainNav.style.display = 'none';
            if (washerNav) washerNav.style.display = 'none';
            if (mechanicNav) mechanicNav.style.display = 'flex';
            const sub = document.getElementById('mechanicProfileSubtitle');
            if (sub) sub.textContent = `${this.currentUser.full_name} (Technical Duty Screen)`;
            this.switchView('mechanic');
            this.renderMechanicScreen();
        } else {
            // General / Multi-role (Cashier, Owner, Admin, Manager, Supervisor)
            if (washerNav) washerNav.style.display = 'none';
            if (mechanicNav) mechanicNav.style.display = 'none';
            if (mainNav) mainNav.style.display = 'flex';
            this.renderCatalog();
            this.renderCart();
            this.populateCrewDropdowns();

            // Resolve initial view
            if (canPosAccess) {
                let savedHash = (window.location.hash || '').replace(/^#/, '');
                if (savedHash === 'oil-service' || savedHash === 'oil_service') savedHash = 'oil';
                const savedTab = savedHash || sessionStorage.getItem('carwash_pos_active_tab') || 'pos';
                this.switchView(savedTab);
            } else if (canQueueAccess) {
                this.switchView('queue');
            } else if (canOrdersAccess) {
                this.switchView('orders');
            } else if (canCloseDayAccess) {
                this.switchView('close');
            } else {
                this.switchView('pos');
            }
        }

        // Apply Cashier Controls & Role State (Requirements 1, 2, 6, 8)
        const role = (this.currentUser?.role || '').toLowerCase();
        document.body.classList.toggle('role-cashier', role === 'cashier');

        const endShiftItem = document.getElementById('menuItemEndShift');
        if (endShiftItem) {
            endShiftItem.style.display = (role === 'cashier' || role === 'manager' || role === 'owner') ? 'flex' : 'none';
        }
        const spotAuditItem = document.getElementById('menuItemSpotAudit');
        if (spotAuditItem) {
            spotAuditItem.style.display = (role === 'manager' || role === 'owner' || role === 'admin') ? 'flex' : 'none';
        }

        if (role === 'cashier') {
            this.checkCashierShift();
        }

        this.checkDayLockStatus();
        this.updateOfflineManualBanner();
    }

    static renderLoggedOutState(isIdle = false) {
        document.getElementById('viewLogin').style.display = 'flex';
        document.getElementById('mainNav').style.display = 'none';
        document.getElementById('washerNav').style.display = 'none';
        document.getElementById('mechanicNav').style.display = 'none';
        document.getElementById('userPill').style.display = 'none';
        document.getElementById('btnLogout').style.display = 'none';
        const menuItemLogout = document.getElementById('menuItemLogout');
        if (menuItemLogout) menuItemLogout.style.display = 'none';

        document.body.classList.remove('role-cashier');
        const endShiftItem = document.getElementById('menuItemEndShift');
        if (endShiftItem) endShiftItem.style.display = 'none';
        const spotAuditItem = document.getElementById('menuItemSpotAudit');
        if (spotAuditItem) spotAuditItem.style.display = 'none';
        const dayLockBanner = document.getElementById('posDayLockBanner');
        if (dayLockBanner) dayLockBanner.style.display = 'none';
        document.getElementById('modalStartShift')?.classList.remove('open');
        document.getElementById('modalEndShift')?.classList.remove('open');
        document.getElementById('modalManagerPin')?.classList.remove('open');
        const spotOverlay = document.getElementById('overlaySpotAudit');
        if (spotOverlay) spotOverlay.style.display = 'none';

        document.querySelectorAll('.view-section').forEach(view => view.classList.remove('active'));

        // Reset PIN and update quick hints
        this.currentPin = '';
        this.updatePinDots();
        this.updateTestModeQuickAccounts();

        if (!isIdle) {
            this.clearAlert('loginAlert');
        }
    }

    static switchView(viewName) {
        if (!viewName) viewName = 'pos';
        if (viewName === 'oil-service' || viewName === 'oil_service') viewName = 'oil';
        this.currentView = viewName;
        try {
            sessionStorage.setItem('carwash_pos_active_tab', viewName);
            if (window.location.hash !== '#' + viewName) {
                history.replaceState(null, '', '#' + viewName);
            }
        } catch (e) {
            console.warn('Could not store active tab in sessionStorage/hash', e);
        }

        document.querySelectorAll('.nav-tab').forEach(tab => {
            if (tab.getAttribute('data-view') === viewName) {
                tab.classList.add('active');
            } else {
                tab.classList.remove('active');
            }
        });

        document.querySelectorAll('.view-section').forEach(view => view.classList.remove('active'));

        const target = document.getElementById(`view${viewName.charAt(0).toUpperCase() + viewName.slice(1)}`);
        if (target) {
            target.classList.add('active');
            target.setAttribute('tabindex', '-1');
            target.focus({ preventScroll: true });
        }

        if (viewName === 'pos') {
            this.populateCrewDropdowns();
        } else if (viewName === 'queue') {
            this.renderJobQueue();
        } else if (viewName === 'orders') {
            const search = document.getElementById('searchOrdersPlateInput')?.value.trim() || '';
            this.renderTodayOrders(search, this.currentOrderFilter || 'all');
        } else if (viewName === 'washer') {
            this.renderWasherScreen();
        } else if (viewName === 'mechanic') {
            this.renderMechanicScreen();
        } else if (viewName === 'oil') {
            this.renderOilReminders();
        } else if (viewName === 'close') {
            this.renderDailyClose();
        } else if (viewName === 'appointments') {
            this.renderAppointmentsScreen();
        }
    }

    // ========================================================
    // ========================================================
    // CATALOG & VEHICLE PRICING (REQUIREMENTS 1, 2, 3, 5, 6)
    // ========================================================
    static cardPressTimer = null;
    static cardPressTriggered = false;
    static cardPressStartPos = null;

    static handleCardPressStart(e, id) {
        this.cardPressTriggered = false;
        const clientX = e.touches ? e.touches[0].clientX : e.clientX;
        const clientY = e.touches ? e.touches[0].clientY : e.clientY;
        this.cardPressStartPos = { x: clientX, y: clientY };

        clearTimeout(this.cardPressTimer);
        this.cardPressTimer = setTimeout(() => {
            this.cardPressTriggered = true;
            this.openProductImageModal(id);
        }, 480);
    }

    static handleCardPressMove(e) {
        if (!this.cardPressStartPos) return;
        const clientX = e.touches ? e.touches[0].clientX : e.clientX;
        const clientY = e.touches ? e.touches[0].clientY : e.clientY;
        const dx = Math.abs(clientX - this.cardPressStartPos.x);
        const dy = Math.abs(clientY - this.cardPressStartPos.y);
        if (dx > 10 || dy > 10) {
            clearTimeout(this.cardPressTimer);
        }
    }

    static handleCardPressEnd(e) {
        clearTimeout(this.cardPressTimer);
        this.cardPressStartPos = null;
    }

    static handleCardPressCancel(e) {
        clearTimeout(this.cardPressTimer);
        this.cardPressStartPos = null;
    }

    static toggleShowPictures() {
        const staffId = this.currentUser?.id || this.currentUser?.username;
        const cur = PosStorage.getShowPictures(staffId);
        const next = !cur;
        PosStorage.setShowPictures(next, staffId);
        this.updatePicturesToggleUI();
        this.renderCatalog();
    }

    static updatePicturesToggleUI() {
        const staffId = this.currentUser?.id || this.currentUser?.username;
        const show = PosStorage.getShowPictures(staffId);
        const cfg = document.getElementById('cfgShowPictures');
        if (cfg) {
            cfg.checked = show;
        }
    }

    static handleCardImageLoaded(imgEl) {
        if (!imgEl) return;
        imgEl.classList.add('loaded');
        const skeleton = imgEl.parentElement?.querySelector('.media-skeleton-placeholder');
        if (skeleton) skeleton.style.display = 'none';
    }

    static handleCardImageError(imgEl, id) {
        if (!imgEl) return;
        imgEl.style.display = 'none';
        const skeleton = imgEl.parentElement?.querySelector('.media-skeleton-placeholder');
        if (skeleton) skeleton.style.display = 'none';
        const fallback = imgEl.parentElement?.querySelector('.item-card-fallback-icon');
        if (fallback) fallback.style.display = 'flex';
        const btnMagnify = imgEl.parentElement?.querySelector('.btn-card-magnifier');
        if (btnMagnify) btnMagnify.style.display = 'none';
    }

    static async syncProductImagesSilently(products) {
        if (!Array.isArray(products) || products.length === 0) return;
        if (typeof PosImageCache === 'undefined') return;
        await PosImageCache.init();

        for (const prod of products) {
            const prodId = prod.id;
            const imgUrl = prod.image_url || prod.thumb_url;
            const version = prod.image_updated_at || imgUrl || '';

            if (!imgUrl) {
                if (PosImageCache.get(prodId)) {
                    await PosImageCache.remove(prodId);
                }
                continue;
            }

            const cached = PosImageCache.get(prodId);
            // Requirement 4: download picture again ONLY when back office says it changed
            if (cached && cached.version === version && cached.dataUri) {
                continue;
            }

            try {
                const resolvedUrl = this.resolveAssetUrl(imgUrl);
                const dataUri = await this.cacheImageAsDataUri(resolvedUrl);
                if (dataUri) {
                    await PosImageCache.save(prodId, dataUri, version, resolvedUrl);
                }
            } catch (e) {
                console.warn(`Could not cache image for product #${prodId}:`, e);
            }
        }
    }

    static openProductImageModal(id) {
        const catalog = PosStorage.getCatalog();
        if (!catalog) return;

        let item = null;
        let isSvc = false;

        if (String(id).startsWith('svc_')) {
            const svcId = parseInt(String(id).replace('svc_', ''));
            item = (catalog.services || []).find(s => s.id === svcId);
            isSvc = true;
        } else {
            const prodId = parseInt(id);
            item = (catalog.products || []).find(p => p.id === prodId);
        }

        if (!item) return;

        const modal = document.getElementById('modalProductImagePreview');
        if (!modal) return;

        const nameKmEl = document.getElementById('previewProdNameKm');
        const nameEnEl = document.getElementById('previewProdNameEn');
        const imgEl = document.getElementById('previewModalImg');
        const fallbackEl = document.getElementById('previewModalFallbackIcon');
        const priceUsdEl = document.getElementById('previewProdPriceUsd');
        const priceKhrEl = document.getElementById('previewProdPriceKhr');
        const stockEl = document.getElementById('previewProdStock');
        const catEl = document.getElementById('previewProdCategory');
        const brandEl = document.getElementById('previewProdBrand');
        const btnAdd = document.getElementById('btnPreviewAddToCart');

        if (nameKmEl) nameKmEl.textContent = item.name_km || item.name_en;
        if (nameEnEl) nameEnEl.textContent = item.name_en || '';

        const exchangeRate = this.getExchangeRate();
        const priceUsd = isSvc
            ? (catalog.pricing_matrix?.[item.id]?.[this.selectedVehicleType]?.usd || 3.00)
            : parseFloat(item.price_usd || 0);
        const priceKhr = isSvc
            ? (catalog.pricing_matrix?.[item.id]?.[this.selectedVehicleType]?.khr || Math.round(priceUsd * exchangeRate))
            : (item.price_khr ? parseInt(item.price_khr) : Math.round(priceUsd * exchangeRate));

        if (priceUsdEl) priceUsdEl.textContent = `$${priceUsd.toFixed(2)}`;
        if (priceKhrEl) priceKhrEl.textContent = `(${priceKhr.toLocaleString()} ៛)`;

        if (isSvc) {
            if (stockEl) stockEl.textContent = `${item.duration_min || 20} mins`;
            if (catEl) {
                catEl.className = 'item-badge service';
                catEl.textContent = 'Service';
            }
            if (brandEl) brandEl.style.display = 'none';
        } else {
            const stockQty = parseInt(item.stock_qty || 0);
            if (stockEl) {
                if (stockQty <= 0) {
                    stockEl.innerHTML = `<span style="color: var(--danger); font-weight: 700;"><i class="fa-solid fa-circle-xmark"></i> Out of Stock</span>`;
                } else if (stockQty <= (item.min_stock_alert || 5)) {
                    stockEl.innerHTML = `<span style="color: var(--warning); font-weight: 700;"><i class="fa-solid fa-triangle-exclamation"></i> Low: ${stockQty} ${this.escapeHtml(item.unit || '')}</span>`;
                } else {
                    stockEl.innerHTML = `<span style="color: var(--success); font-weight: 700;"><i class="fa-solid fa-circle-check"></i> ${stockQty} ${this.escapeHtml(item.unit || '')}</span>`;
                }
            }
            const isSpare = (item.category === 'spare_part' || item.category === 'oil_filter');
            if (catEl) {
                catEl.className = `item-badge ${isSpare ? 'spare' : 'product'}`;
                catEl.textContent = isSpare ? 'Spare Part' : 'Product';
            }
            if (brandEl) {
                if (item.brand) {
                    brandEl.textContent = item.brand;
                    brandEl.style.display = 'inline-block';
                } else {
                    brandEl.style.display = 'none';
                }
            }
        }

        // Set high-res image (try 600px image_url first, fallback to thumb_url or cached URI)
        const cachedUri = (typeof PosImageCache !== 'undefined') ? PosImageCache.getUri(item.id) : null;
        const fullImgUrl = item.image_url || item.thumb_url;
        const resolvedUrl = fullImgUrl ? this.resolveAssetUrl(fullImgUrl) : null;
        const finalImgSrc = resolvedUrl || cachedUri;

        if (finalImgSrc) {
            if (imgEl) {
                imgEl.style.display = 'block';
                imgEl.src = finalImgSrc;
                imgEl.onerror = () => {
                    imgEl.style.display = 'none';
                    if (fallbackEl) fallbackEl.style.display = 'block';
                };
            }
            if (fallbackEl) fallbackEl.style.display = 'none';
        } else {
            if (imgEl) imgEl.style.display = 'none';
            if (fallbackEl) {
                fallbackEl.style.display = 'block';
                const iconName = isSvc
                    ? this.getValidServiceIcon(item.icon, item.category, item.name_en)
                    : this.getValidProductIcon(item.icon, item.category, item.name_en);
                fallbackEl.innerHTML = `<i class="fa-solid ${iconName}"></i>`;
            }
        }

        if (btnAdd) {
            btnAdd.onclick = () => {
                modal.classList.remove('open');
                if (isSvc) {
                    this.addServiceToCart(item.id);
                } else {
                    this.addProductToCart(item.id);
                }
            };
        }

        modal.classList.add('open');
    }

    static closeProductImageModal() {
        const modal = document.getElementById('modalProductImagePreview');
        if (modal) modal.classList.remove('open');
    }

    /**
     * Check if a service or product matches the currently selected category
     * Supports dynamic category_id from back office with fallback to category code & legacy names
     */
    static itemMatchesCategory(item, type = 'service') {
        if (!this.selectedCategory || this.selectedCategory === 'all') return true;

        const selCat = String(this.selectedCategory);
        const catalog = PosStorage.getCatalog() || {};

        // 1. Direct category_id match (numeric or string)
        if (item.category_id !== undefined && item.category_id !== null && item.category_id !== '') {
            if (String(item.category_id) === selCat) return true;
        }

        // 2. Match by category code looked up from back office categories list
        const categories = catalog.categories || [];
        const catObj = categories.find(c => String(c.id) === selCat);
        if (catObj && catObj.code && item.category) {
            if (item.category === catObj.code) return true;
        }

        // 3. Backward-compatibility for legacy string filters ('wash', 'oil', 'products', 'spare_parts')
        if (type === 'service') {
            if (selCat === 'wash') {
                return ['wash', 'detailing'].includes(item.category) || item.category_id == 1;
            }
            if (selCat === 'oil') {
                return item.category === 'maintenance' || item.category_id == 2;
            }
        } else {
            if (selCat === 'oil') {
                return (item.category === 'engine_oil' || item.category === 'oil_filter' || item.category_id == 3 || item.category_id == 4);
            }
            if (selCat === 'products') {
                return (['care_product', 'fluid', 'engine_oil'].includes(item.category) || item.category_id == 5 || item.category_id == 6);
            }
            if (selCat === 'spare_parts') {
                const isSpare = (item.category === 'spare_part' || item.category === 'oil_filter');
                return isSpare || item.category_id == 4 || item.category_id == 7;
            }
        }

        return false;
    }

    /**
     * Get clean short category display name for compact POS buttons
     */
    static getShortCategoryName(name, lang = 'en') {
        if (!name) return '';
        const trimmed = name.trim();
        const shortMap = {
            // English
            'Car Wash & Detailing': 'Car Wash',
            'Wash & Detailing Services': 'Car Wash',
            'Basic Maintenance & Repair': 'Maintenance',
            'Maintenance & Repair': 'Maintenance',
            'Car Care Products': 'Car Care',
            'Care Products': 'Car Care',
            'Spare Parts & Hardware': 'Spare Parts',
            'Accessories & Spare Parts': 'Spare Parts',
            'Engine Oils': 'Engine Oils',
            'Oil Filters': 'Oil Filters',
            'Fluids & Coolants': 'Fluids & Coolants',
            // Khmer
            'លាងសម្អាត & ថែទាំរថយន្ត': 'លាង & ប៉ូលា',
            'សេវាកម្មលាង & ប៉ូលា': 'លាង & ប៉ូលា',
            'ជួសជុល & ថែទាំទូទៅ': 'ជួសជុល & ថែទាំ',
            'ទំនិញថែទាំរថយន្ត': 'ផលិតផលថែទាំ',
            'គ្រឿងបន្លាស់ & ផ្នែករឹង': 'គ្រឿងបន្លាស់',
            'ទឹកស្អំ & ទឹកកញ្ចក់': 'ទឹកស្អំ & ទឹកកញ្ចក់',
            'ប្រេងម៉ាស៊ីន': 'ប្រេងម៉ាស៊ីន',
            'តម្រងប្រេង': 'តម្រងប្រេង'
        };
        return shortMap[trimmed] || trimmed;
    }

    /**
     * Render vertical Category Column buttons dynamically from Back Office categories
     * First button is 'All', followed by categories in back office order (sort_order ASC, id ASC).
     * Buttons display short names without icons for clean spacing and zero word breaking.
     * Dims categories with no active items for the currently selected vehicle type.
     */
    static renderCategories() {
        const sidebar = document.getElementById('categorySidebar');
        if (!sidebar) return;

        const btnPictures = document.getElementById('btnTogglePictures');
        const catalog = PosStorage.getCatalog() || {};
        const lang = I18nManager.getLang() || 'km';
        const vtype = this.selectedVehicleType;
        const matrix = catalog.pricing_matrix || {};
        const services = catalog.services || [];
        const products = catalog.products || [];

        // 1. Get categories from catalog (back office API) or fallback
        let categories = [];
        const rawCategories = catalog.categories;
        const hasServerCategories = Array.isArray(rawCategories) && rawCategories.length > 0;

        if (hasServerCategories) {
            categories = rawCategories
                .filter(c => c.type !== 'expense' && (c.is_active === undefined || c.is_active == 1 || c.is_active === true))
                .sort((a, b) => (parseInt(a.sort_order || 0) - parseInt(b.sort_order || 0)) || (parseInt(a.id || 0) - parseInt(b.id || 0)));
        } else {
            // Fallback to legacy categories if cached catalog has no categories list
            categories = [
                { id: 'wash', code: 'wash', name_en: 'Car Wash', name_km: 'លាង & ប៉ូលា' },
                { id: 'oil', code: 'oil', name_en: 'Engine Oils', name_km: 'ប្រេងម៉ាស៊ីន' },
                { id: 'repair', code: 'repair', name_en: 'Maintenance', name_km: 'ជួសជុល & ថែទាំ' },
                { id: 'products', code: 'products', name_en: 'Car Care', name_km: 'ផលិតផលថែទាំ' },
                { id: 'spare_parts', code: 'spare_parts', name_en: 'Spare Parts', name_km: 'គ្រឿងបន្លាស់' }
            ];
        }

        // If currently selected category no longer exists in categories list (and not 'all'), reset to 'all'
        if (this.selectedCategory !== 'all') {
            const exists = categories.some(c => String(c.id) === String(this.selectedCategory) || c.code === this.selectedCategory);
            if (!exists) {
                this.selectedCategory = 'all';
            }
        }

        // Helper to check if category has active items for selected vehicle type
        const hasActiveItems = (cat) => {
            const catId = String(cat.id);
            const catCode = cat.code;

            // Check active services with valid price for vehicle type
            const hasSvc = services.some(svc => {
                if (svc.is_active !== undefined && svc.is_active != 1 && svc.is_active !== true) return false;
                const matches = (svc.category_id && String(svc.category_id) === catId) || (svc.category && svc.category === catCode);
                if (!matches) return false;
                const p = matrix[svc.id]?.[vtype];
                return p !== undefined && p !== null && ((typeof p === 'object' && (p.usd > 0 || p.khr > 0)) || (typeof p === 'number' && p > 0));
            });
            if (hasSvc) return true;

            // Check active products
            const hasProd = products.some(prod => {
                if (prod.is_active !== undefined && prod.is_active != 1 && prod.is_active !== true) return false;
                return (prod.category_id && String(prod.category_id) === catId) || (prod.category && prod.category === catCode);
            });
            return hasProd;
        };

        const targetContainer = document.getElementById('categoryListWrap') || sidebar;
        const prevScroll = targetContainer.scrollTop;

        // Remove old category buttons (preserve Pictures toggle button)
        targetContainer.querySelectorAll('.category-pill:not(.pictures-toggle-pill)').forEach(el => el.remove());
        if (targetContainer !== sidebar) {
            sidebar.querySelectorAll(':scope > .category-pill:not(.pictures-toggle-pill)').forEach(el => el.remove());
        }

        // 2. Build "All" Button (No icon)
        const allBtn = document.createElement('button');
        allBtn.type = 'button';
        allBtn.className = 'category-pill' + (this.selectedCategory === 'all' ? ' active' : '');
        allBtn.setAttribute('data-cat', 'all');
        const allText = (lang === 'km') ? (I18nManager.t('allCategories') || 'ទាំងអស់') : (I18nManager.t('allCategories') || 'All');
        allBtn.innerHTML = `<span>${allText}</span>`;
        allBtn.title = (lang === 'km') ? 'មុខទំនិញទាំងអស់' : 'All Items';
        allBtn.addEventListener('click', () => {
            sidebar.querySelectorAll('.category-pill:not(.pictures-toggle-pill)').forEach(p => p.classList.remove('active'));
            allBtn.classList.add('active');
            this.selectedCategory = 'all';
            this.renderCatalog();
        });
        if (targetContainer !== sidebar) {
            targetContainer.appendChild(allBtn);
        } else if (btnPictures) {
            sidebar.insertBefore(allBtn, btnPictures);
        } else {
            sidebar.appendChild(allBtn);
        }

        // 3. Build Category Buttons from Back Office (Short names, No icon)
        categories.forEach(cat => {
            const catId = String(cat.id);
            const isSelected = (String(this.selectedCategory) === catId || this.selectedCategory === cat.code);
            const activeItemsExist = hasActiveItems(cat);

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'category-pill' + (isSelected ? ' active' : '') + (!activeItemsExist ? ' dimmed' : '');
            btn.setAttribute('data-cat', catId);
            if (cat.code) btn.setAttribute('data-code', cat.code);

            // Display short name in active language only; fall back to English if no Khmer name exists
            let rawName = '';
            if (lang === 'km') {
                rawName = (cat.name_km && cat.name_km.trim()) ? cat.name_km.trim() : (cat.name_en || cat.code || '');
            } else {
                rawName = (cat.name_en && cat.name_en.trim()) ? cat.name_en.trim() : (cat.name_km || cat.code || '');
            }

            const displayName = this.getShortCategoryName(rawName, lang);
            const fullName = (lang === 'km') ? (cat.name_km || cat.name_en) : (cat.name_en || cat.name_km);

            btn.innerHTML = `<span>${this.escapeHtml(displayName)}</span>`;
            btn.title = fullName || displayName;

            if (!activeItemsExist) {
                const noItemsMsg = (lang === 'km') ? 'គ្មានសេវាកម្ម ឬទំនិញសម្រាប់ប្រភេទយានយន្តនេះទេ' : 'No active items for this vehicle type';
                btn.title = `${fullName || displayName} (${noItemsMsg})`;
            }

            btn.addEventListener('click', () => {
                sidebar.querySelectorAll('.category-pill:not(.pictures-toggle-pill)').forEach(p => p.classList.remove('active'));
                btn.classList.add('active');
                this.selectedCategory = catId;
                this.renderCatalog();
            });

            if (targetContainer !== sidebar) {
                targetContainer.appendChild(btn);
            } else if (btnPictures) {
                sidebar.insertBefore(btn, btnPictures);
            } else {
                sidebar.appendChild(btn);
            }
        });

        if (prevScroll > 0) {
            targetContainer.scrollTop = prevScroll;
        }
    }

    static renderCatalog() {
        const grid = document.getElementById('catalogGrid');
        if (!grid) return;

        // Ensure category buttons exist in sidebar
        const currentPills = document.querySelectorAll('#categorySidebar .category-pill:not(.pictures-toggle-pill)');
        if (currentPills.length <= 1) {
            this.renderCategories();
        }

        const catalog = PosStorage.getCatalog();
        if (!catalog) {
            grid.innerHTML = `
                <div style="grid-column: 1 / -1; text-align: center; padding: 3rem; color: var(--text-muted);">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                    <p>Loading Catalog from Back Office...</p>
                </div>
            `;
            return;
        }

        const showPictures = PosStorage.getShowPictures();
        grid.className = showPictures ? 'catalog-grid picture-grid' : 'catalog-grid compact-grid';
        const vtype = this.selectedVehicleType;
        const matrix = catalog.pricing_matrix || {};
        const exchangeRate = this.getExchangeRate();
        let html = '';

        // 1. Services
        const services = catalog.services || [];
        services.forEach(svc => {
            if (!this.itemMatchesCategory(svc, 'service')) return;

            const priceObj = matrix[svc.id]?.[vtype] || { usd: 3.00, khr: 12300 };
            const priceUsd = (typeof priceObj === 'number') ? priceObj : (priceObj.usd || 0);
            const priceKhr = (typeof priceObj === 'object' && priceObj.khr) ? priceObj.khr : Math.round(priceUsd * exchangeRate);
            const svcIcon = this.getValidServiceIcon(svc.icon, svc.category, svc.name_en);
            const rawSvcImg = svc.image_url || svc.thumb_url;
            const svcImg = rawSvcImg ? this.resolveAssetUrl(rawSvcImg) : null;
            const svcImgWithVer = svcImg ? `${svcImg}?v=${encodeURIComponent(svc.image_updated_at || '')}` : null;

            const isKm = (I18nManager.getLang() === 'km');
            const svcWait = (this.currentWaitData?.services_wait || this.currentWaitData?.services)?.[svc.id];
            const svcWaitText = svcWait ? (isKm ? (svcWait.wait_text_km || svcWait.wait_text_en) : svcWait.wait_text_en) : '';
            const hideWait = (!this.isOnline || !navigator.onLine || (Date.now() - (this.lastSyncSuccessTime || Date.now()) > 60000));
            const waitDisplay = (svcWaitText && !hideWait) ? 'inline-flex' : 'none';

            if (showPictures) {
                // Picture Mode: Identical Top Media Box & Sizing
                const hasSvcImg = !!svcImgWithVer;
                html += `
                    <div class="pos-item-card card-picture-mode"
                         data-svc-id="${svc.id}"
                         onclick="PosApp.addServiceToCart(${svc.id})"
                         onmousedown="PosApp.handleCardPressStart(event, 'svc_${svc.id}')"
                         onmouseup="PosApp.handleCardPressEnd(event)"
                         onmouseleave="PosApp.handleCardPressCancel(event)"
                         ontouchstart="PosApp.handleCardPressStart(event, 'svc_${svc.id}')"
                         ontouchend="PosApp.handleCardPressEnd(event)"
                         ontouchmove="PosApp.handleCardPressMove(event)">
                        <span class="card-tag-type service">SERVICE</span>
                        <span class="card-tag-status duration"><i class="fa-regular fa-clock"></i> ${svc.duration_min || 20}m</span>
                        <span class="service-wait-time-badge" data-service-id="${svc.id}" style="display: ${waitDisplay};"><i class="fa-regular fa-clock"></i> ${svcWaitText || ''}</span>
                        <div class="item-card-media-box">
                            ${hasSvcImg ? `
                                <div class="media-skeleton-placeholder"></div>
                                <img class="item-card-img" src="${svcImgWithVer}" loading="lazy" alt="" onload="PosApp.handleCardImageLoaded(this)" onerror="PosApp.handleCardImageError(this, 'svc_${svc.id}')">
                                <div class="item-card-fallback-icon" style="color: var(--primary); display: none;">
                                    <i class="fa-solid ${svcIcon}"></i>
                                </div>
                                <button type="button" class="btn-card-magnifier" title="Zoom" onclick="event.stopPropagation(); PosApp.openProductImageModal('svc_${svc.id}');">
                                    <i class="fa-solid fa-magnifying-glass-plus"></i>
                                </button>
                            ` : `
                                <div class="item-card-fallback-icon" style="color: var(--primary); display: flex;">
                                    <i class="fa-solid ${svcIcon}"></i>
                                </div>
                            `}
                        </div>
                        <div class="item-card-body">
                            <div class="item-titles">
                                <div class="item-name-km">${this.escapeHtml(svc.name_km || svc.name_en)}</div>
                                <div class="item-name-en">${this.escapeHtml(svc.name_en)}</div>
                            </div>
                            <div class="item-meta-row">
                                <div class="item-price-block">
                                    <span class="item-price-usd">$${priceUsd.toFixed(2)}</span>
                                    <span class="item-price-khr">${priceKhr.toLocaleString()} ៛</span>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                // Compact Mode
                const svcIconHtml = svcImgWithVer
                    ? `<img src="${svcImgWithVer}" alt="" style="width: 100%; height: 100%; object-fit: contain; border-radius: var(--radius-sm);" onerror="this.style.display='none'; if (this.nextElementSibling) this.nextElementSibling.style.display='inline-block';"><i class="fa-solid ${svcIcon}" style="display: none;"></i>`
                    : `<i class="fa-solid ${svcIcon}"></i>`;

                html += `
                    <div class="pos-item-card compact-mode" onclick="PosApp.addServiceToCart(${svc.id})">
                        <div class="compact-card-badges">
                            <span class="item-duration-badge"><i class="fa-regular fa-clock"></i> ${svc.duration_min || 20}m</span>
                            <span class="service-wait-time-badge" data-service-id="${svc.id}" style="display: ${waitDisplay};"><i class="fa-regular fa-clock"></i> ${svcWaitText || ''}</span>
                            <span class="item-badge service">Service</span>
                        </div>
                        <div class="item-icon">
                            ${svcIconHtml}
                        </div>
                        <div class="item-titles">
                            <div class="item-name-km">${this.escapeHtml(svc.name_km || svc.name_en)}</div>
                            <div class="item-name-en">${this.escapeHtml(svc.name_en)}</div>
                        </div>
                        <div class="item-meta-row">
                            <div class="item-price-block">
                                <span class="item-price-usd">$${priceUsd.toFixed(2)}</span>
                                <span class="item-price-khr">${priceKhr.toLocaleString()} ៛</span>
                            </div>
                        </div>
                    </div>
                `;
            }
        });

        // 2. Products & Spare Parts
        const products = catalog.products || [];
        products.forEach(prod => {
            const isSpare = (prod.category === 'spare_part' || prod.category === 'oil_filter');
            if (!this.itemMatchesCategory(prod, 'product')) return;

            const priceUsd = parseFloat(prod.price_usd || 0);
            const priceKhr = prod.price_khr ? parseInt(prod.price_khr) : Math.round(priceUsd * exchangeRate);
            const stockQty = parseInt(prod.stock_qty || 0);
            const prodIcon = this.getValidProductIcon(prod.icon, prod.category, prod.name_en);

            // Check offline cache first, then image_url, then thumb_url
            const cachedUri = (typeof PosImageCache !== 'undefined') ? PosImageCache.getUri(prod.id) : null;
            const rawProdImg = prod.image_url || prod.thumb_url;
            const netImgUrl = rawProdImg ? this.resolveAssetUrl(rawProdImg) : null;
            const netImgWithVer = netImgUrl ? `${netImgUrl}?v=${encodeURIComponent(prod.image_updated_at || '')}` : null;
            const finalImgSrc = cachedUri || netImgWithVer;

            let iconColor = '#0284c7';
            if (isSpare) iconColor = '#475569';
            else if (prod.category === 'engine_oil') iconColor = '#d97706';
            else if (prod.category === 'oil_filter') iconColor = '#0891b2';
            else if (prod.category === 'fluid') iconColor = '#0284c7';
            else if (prod.category === 'care_product') iconColor = '#7c3aed';

            let stockTag = `<span class="item-stock-tag">Stock: ${stockQty}</span>`;
            let stockStatusBadge = `<span class="card-tag-status in-stock">${stockQty} in stock</span>`;
            if (stockQty <= 0) {
                stockTag = `<span class="item-stock-tag out">Out of Stock</span>`;
                stockStatusBadge = `<span class="card-tag-status out-of-stock">Out of Stock</span>`;
            } else if (stockQty <= (prod.min_stock_alert || 5)) {
                stockTag = `<span class="item-stock-tag low">Low: ${stockQty}</span>`;
                stockStatusBadge = `<span class="card-tag-status low-stock">Low: ${stockQty}</span>`;
            }

            if (showPictures) {
                // Picture Mode: Identical Top Media Box & Sizing
                const hasPic = !!finalImgSrc;
                html += `
                    <div class="pos-item-card card-picture-mode card-product"
                         data-prod-id="${prod.id}"
                         onclick="PosApp.addProductToCart(${prod.id})"
                         onmousedown="PosApp.handleCardPressStart(event, ${prod.id})"
                         onmouseup="PosApp.handleCardPressEnd(event)"
                         onmouseleave="PosApp.handleCardPressCancel(event)"
                         ontouchstart="PosApp.handleCardPressStart(event, ${prod.id})"
                         ontouchend="PosApp.handleCardPressEnd(event)"
                         ontouchmove="PosApp.handleCardPressMove(event)">
                        <span class="card-tag-type ${isSpare ? 'part' : 'product'}">${isSpare ? 'PART' : 'PRODUCT'}</span>
                        ${stockStatusBadge}
                        <div class="item-card-media-box">
                            ${hasPic ? `
                                <div class="media-skeleton-placeholder"></div>
                                <img class="item-card-img" src="${finalImgSrc}" loading="lazy" alt="" onload="PosApp.handleCardImageLoaded(this)" onerror="PosApp.handleCardImageError(this, ${prod.id})">
                                <div class="item-card-fallback-icon" style="color: ${iconColor}; display: none;">
                                    <i class="fa-solid ${prodIcon}"></i>
                                </div>
                                <button type="button" class="btn-card-magnifier" title="Zoom" onclick="event.stopPropagation(); PosApp.openProductImageModal(${prod.id});">
                                    <i class="fa-solid fa-magnifying-glass-plus"></i>
                                </button>
                            ` : `
                                <div class="item-card-fallback-icon" style="color: ${iconColor}; display: flex;">
                                    <i class="fa-solid ${prodIcon}"></i>
                                </div>
                            `}
                        </div>
                        <div class="item-card-body">
                            <div class="item-titles">
                                <div class="item-name-km">${this.escapeHtml(prod.name_km || prod.name_en)}</div>
                                <div class="item-name-en">${this.escapeHtml(prod.name_en)}</div>
                            </div>
                            <div class="item-meta-row">
                                <div class="item-price-block">
                                    <span class="item-price-usd">$${priceUsd.toFixed(2)}</span>
                                    <span class="item-price-khr">${priceKhr.toLocaleString()} ៛</span>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                // Compact Mode
                const prodIconHtml = finalImgSrc
                    ? `<img src="${finalImgSrc}" alt="" style="width: 100%; height: 100%; object-fit: contain; border-radius: var(--radius-sm);" onerror="this.style.display='none'; if (this.nextElementSibling) this.nextElementSibling.style.display='inline-block';"><i class="fa-solid ${prodIcon}" style="display: none;"></i>`
                    : `<i class="fa-solid ${prodIcon}"></i>`;

                html += `
                    <div class="pos-item-card compact-mode" onclick="PosApp.addProductToCart(${prod.id})">
                        <div class="compact-card-badges">
                            ${stockTag}
                            <span class="item-badge ${isSpare ? 'spare' : 'product'}">${isSpare ? 'Part' : 'Product'}</span>
                        </div>
                        <div class="item-icon" style="color: ${iconColor};">
                            ${prodIconHtml}
                        </div>
                        <div class="item-titles">
                            <div class="item-name-km">${this.escapeHtml(prod.name_km || prod.name_en)}</div>
                            <div class="item-name-en">${this.escapeHtml(prod.name_en)}</div>
                        </div>
                        <div class="item-meta-row">
                            <div class="item-price-block">
                                <span class="item-price-usd">$${priceUsd.toFixed(2)}</span>
                                <span class="item-price-khr">${priceKhr.toLocaleString()} ៛</span>
                            </div>
                        </div>
                    </div>
                `;
            }
        });

        if (html === '') {
            const noItemsText = (I18nManager.getLang() === 'km') ? 'មិនមានសេវាកម្ម ឬទំនិញក្នុងក្រុមនេះទេ' : 'No items found in this category';
            html = `
                <div style="grid-column: 1 / -1; text-align: center; padding: 3rem; color: var(--text-muted);">
                    <i class="fa-solid fa-box-open" style="font-size: 2.2rem; display: block; margin-bottom: 0.6rem; opacity: 0.4;"></i>
                    <p style="font-size: 0.95rem; font-weight: 600; line-height: 1.5;">${noItemsText}</p>
                </div>
            `;
        }

        grid.innerHTML = html;
    }

    static updateVehiclePricesInCart(showNotice = false) {
        const catalog = PosStorage.getCatalog();
        if (!catalog) return;

        const matrix = catalog.pricing_matrix || {};
        const vtype = this.selectedVehicleType;
        const exchangeRate = this.getExchangeRate();
        let changedCount = 0;

        this.cart.forEach(item => {
            if (item.is_member_benefit || item.is_upsell) return;
            if (item.type === 'service' && item.service_id) {
                const priceObj = matrix[item.service_id]?.[vtype];
                if (priceObj) {
                    const newUsd = (typeof priceObj === 'number') ? priceObj : (priceObj.usd !== undefined ? priceObj.usd : item.price_usd);
                    if (Math.abs(item.price_usd - newUsd) > 0.001) {
                        changedCount++;
                        item.price_usd = newUsd;
                        item.price_khr = (typeof priceObj === 'object' && priceObj.khr) ? priceObj.khr : Math.round(newUsd * exchangeRate);
                    }
                }
            }
        });

        this.renderCart();

        if (showNotice && changedCount > 0) {
            const notice = I18nManager.t('cartRepricedNotice') || 'Cart services re-priced for new vehicle type';
            this.showToastNotice(notice, 'info');
        }
    }

    // ========================================================
    // CART OPERATIONS
    // ========================================================
    static addServiceToCart(serviceId) {
        if (this.cardPressTriggered) {
            this.cardPressTriggered = false;
            return;
        }

        const catalog = PosStorage.getCatalog();
        if (!catalog) return;

        const svc = (catalog.services || []).find(s => s.id === serviceId);
        if (!svc) return;

        const vtype = this.selectedVehicleType;
        const matrix = catalog.pricing_matrix || {};
        const exchangeRate = this.getExchangeRate();

        const priceObj = matrix[svc.id]?.[vtype] || { usd: 3.00, khr: 12300 };
        const priceUsd = (typeof priceObj === 'number') ? priceObj : (priceObj.usd || 3.00);
        const priceKhr = (typeof priceObj === 'object' && priceObj.khr) ? priceObj.khr : Math.round(priceUsd * exchangeRate);

        let finalPriceUsd = priceUsd;
        let finalPriceKhr = priceKhr;
        let isMemberBenefit = false;
        let benefitNote = '';

        // Check if customer has an included free benefit for this service (Requirement 1: Included services show at no charge automatically)
        if (this.currentCustomerBenefits && Array.isArray(this.currentCustomerBenefits)) {
            const benefit = this.currentCustomerBenefits.find(b => Number(b.service_id) === Number(svc.id) && b.auto_free && !b.is_used_up);
            if (benefit) {
                const usedInCart = this.cart.filter(it => it.type === 'service' && Number(it.service_id) === Number(svc.id) && it.is_member_benefit).reduce((sum, it) => sum + (it.qty || 1), 0);
                if (usedInCart < benefit.remaining) {
                    finalPriceUsd = 0.00;
                    finalPriceKhr = 0;
                    isMemberBenefit = true;
                    benefitNote = 'Included Member Benefit (No Charge)';
                }
            }
        }

        const existing = this.cart.find(it => it.type === 'service' && it.service_id === svc.id && Math.abs(it.price_usd - finalPriceUsd) < 0.001);
        if (existing) {
            existing.qty += 1;
        } else {
            this.cart.push({
                type: 'service',
                service_id: svc.id,
                product_id: null,
                name_en: svc.name_en,
                name_km: svc.name_km,
                price_usd: finalPriceUsd,
                price_khr: finalPriceKhr,
                is_member_benefit: isMemberBenefit,
                note: benefitNote,
                qty: 1
            });
        }

        this.renderCart();
    }

    static addProductToCart(productId) {
        if (this.cardPressTriggered) {
            this.cardPressTriggered = false;
            return;
        }

        const catalog = PosStorage.getCatalog();
        if (!catalog) return;

        const prod = (catalog.products || []).find(p => p.id == productId || String(p.id) === String(productId));
        if (!prod) return;

        // Requirement 5: Out of stock check with Manager PIN override
        const stockQty = parseInt(prod.stock_qty !== undefined ? prod.stock_qty : 99);
        if (stockQty <= 0) {
            this.pendingStockOverrideProd = prod;
            const modal = document.getElementById('modalStockAlert');
            if (modal) {
                const titleEl = document.getElementById('stockAlertTitle');
                const nameEl = document.getElementById('stockAlertItemName');
                const msgEl = document.getElementById('stockAlertMessage');
                if (titleEl) titleEl.textContent = 'Out of Stock / អស់ពីស្តុក';
                if (nameEl) nameEl.textContent = `${prod.name_km || prod.name_en} (${prod.name_en})`;
                if (msgEl) msgEl.textContent = 'This product is out of stock (Stock: 0). Adding this item is blocked unless authorized by Manager PIN.';
                modal.classList.add('open');
            } else {
                alert('This product is out of stock. Manager PIN override required.');
            }
            return;
        }

        const exchangeRate = this.getExchangeRate();
        const priceUsd = parseFloat(prod.price_usd || 0);
        const priceKhr = prod.price_khr ? parseInt(prod.price_khr) : Math.round(priceUsd * exchangeRate);
        const type = (prod.category === 'spare_part' || prod.category === 'oil_filter') ? 'spare_part' : 'product';

        // Never change price of items already in open order (Requirement 2: Stock limit check)
        const existing = this.cart.find(it => it.product_id === prod.id && Math.abs(it.price_usd - priceUsd) < 0.001);
        if (existing) {
            if (existing.qty + 1 > stockQty) {
                const limitMsg = (I18nManager.t('stockLimitReached') || 'Stock limit reached (only {n} available in stock)').replace('{n}', stockQty);
                this.showToastNotice(limitMsg, 'warning');
                return;
            }
            existing.qty += 1;
        } else {
            this.cart.push({
                type,
                service_id: null,
                product_id: prod.id,
                name_en: prod.name_en,
                name_km: prod.name_km,
                price_usd: priceUsd,
                price_khr: priceKhr,
                qty: 1
            });
        }

        this.renderCart();
    }

    static updateCartQty(index, delta) {
        if (!this.cart[index]) return;
        const item = this.cart[index];
        if (delta > 0 && item.product_id) {
            const catalog = PosStorage.getCatalog();
            const prod = (catalog?.products || []).find(p => p.id === item.product_id);
            const stockQty = parseInt(prod?.stock_qty !== undefined ? prod.stock_qty : 99);
            if (item.qty + delta > stockQty) {
                const limitMsg = (I18nManager.t('stockLimitReached') || 'Stock limit reached (only {n} available in stock)').replace('{n}', stockQty);
                this.showToastNotice(limitMsg, 'warning');
                return;
            }
        }
        item.qty += delta;
        if (item.qty <= 0) {
            this.cart.splice(index, 1);
        }
        this.renderCart();
    }

    static removeCartItem(index) {
        this.cart.splice(index, 1);
        this.renderCart();
    }

    static clearCart() {
        this.cart = [];
        this.currentDiscount = { type: 'none', value: 0 };
        this.loyaltyRewardGiven = null;
        this.loyaltyRewardActionChosen = false;
        this.activeLoyaltyReward = null;
        this.activeUpsellOffer = null;
        this._currentUpsellModalOpen = false;
        if (this._dismissedUpsellIds) this._dismissedUpsellIds.clear();
        document.getElementById('cartLoyaltyRewardBanner')?.setAttribute('style', 'display: none;');
        document.getElementById('cartLoyaltyRewardStatusBadge')?.setAttribute('style', 'display: none;');
        document.querySelectorAll('.disc-btn').forEach(b => b.classList.remove('active'));
        document.querySelector('.disc-btn[data-disc="0"]')?.classList.add('active');
        this.renderCart();
    }

    static setDiscount(discString) {
        if (discString === '0') {
            this.currentDiscount = { type: 'none', value: 0 };
        } else if (discString.endsWith('%')) {
            this.currentDiscount = { type: 'percent', value: parseFloat(discString) };
        } else if (discString.startsWith('$')) {
            this.currentDiscount = { type: 'fixed', value: parseFloat(discString.replace('$', '')) };
        }
        this.renderCart();
    }

    // ========================================================
    // DISCOUNT POPUP MODAL METHODS
    // ========================================================
    static openDiscountModal() {
        if (!this.cart || this.cart.length === 0) {
            return;
        }

        const modal = document.getElementById('modalDiscount');
        if (!modal) return;

        this.clearDiscountModalAlert();

        const quickBtns = modal.querySelectorAll('.btn-modal-disc');
        const customInput = document.getElementById('discountCustomInput');
        const btnRemove = document.getElementById('btnRemoveDiscountModal');

        if (this.currentDiscount.type !== 'none' && this.currentDiscount.value > 0) {
            if (btnRemove) btnRemove.style.display = 'inline-flex';

            let matchedQuick = false;
            quickBtns.forEach(btn => {
                const disc = btn.getAttribute('data-disc');
                if (this.currentDiscount.type === 'percent' && disc === `${this.currentDiscount.value}%`) {
                    btn.classList.add('active');
                    matchedQuick = true;
                } else if (this.currentDiscount.type === 'fixed' && disc === `$${this.currentDiscount.value}`) {
                    btn.classList.add('active');
                    matchedQuick = true;
                } else {
                    btn.classList.remove('active');
                }
            });

            if (matchedQuick) {
                if (customInput) customInput.value = '';
            } else {
                quickBtns.forEach(b => b.classList.remove('active'));
                if (customInput) customInput.value = this.currentDiscount.value;
                this.setDiscountModalUnit(this.currentDiscount.type);
            }
        } else {
            if (btnRemove) btnRemove.style.display = 'none';
            quickBtns.forEach(b => {
                if (b.getAttribute('data-disc') === '0') b.classList.add('active');
                else b.classList.remove('active');
            });
            if (customInput) customInput.value = '';
            this.setDiscountModalUnit('percent');
        }

        this.updateDiscountModalPreview();
        modal.classList.add('open');

        setTimeout(() => {
            if (customInput && customInput.value) {
                customInput.focus();
                customInput.select();
            } else {
                const activeQuick = modal.querySelector('.btn-modal-disc.active');
                activeQuick?.focus();
            }
        }, 80);
    }

    static closeDiscountModal() {
        const modal = document.getElementById('modalDiscount');
        if (modal) {
            modal.classList.remove('open');
            this.clearDiscountModalAlert();
        }
    }

    static clearDiscountModalAlert() {
        const alertBox = document.getElementById('discountModalAlert');
        const alertMsg = document.getElementById('discountModalAlertMsg');
        if (alertBox) alertBox.style.display = 'none';
        if (alertMsg) alertMsg.textContent = '';
    }

    static showDiscountModalAlert(msg) {
        const alertBox = document.getElementById('discountModalAlert');
        const alertMsg = document.getElementById('discountModalAlertMsg');
        if (alertBox && alertMsg) {
            alertMsg.textContent = msg;
            alertBox.style.display = 'flex';
        }
    }

    static setDiscountModalUnit(unit) {
        const btnPercent = document.getElementById('btnDiscountUnitPercent');
        const btnFixed = document.getElementById('btnDiscountUnitFixed');
        const prefix = document.getElementById('discountInputPrefix');

        if (unit === 'fixed') {
            btnFixed?.classList.add('active');
            btnPercent?.classList.remove('active');
            if (prefix) prefix.textContent = '$';
        } else {
            btnPercent?.classList.add('active');
            btnFixed?.classList.remove('active');
            if (prefix) prefix.textContent = '%';
        }
        this.updateDiscountModalPreview();
    }

    static getDiscountModalState() {
        const customInput = document.getElementById('discountCustomInput');
        const customValStr = (customInput?.value || '').trim();
        const isCustom = customValStr !== '';

        if (isCustom) {
            const num = parseFloat(customValStr);
            const unitPercent = document.getElementById('btnDiscountUnitPercent')?.classList.contains('active');
            return {
                isCustom: true,
                type: unitPercent ? 'percent' : 'fixed',
                value: num,
                rawString: customValStr
            };
        } else {
            const activeQuick = document.querySelector('#modalDiscount .btn-modal-disc.active');
            const discAttr = activeQuick ? activeQuick.getAttribute('data-disc') : '0';
            if (discAttr === '0') {
                return { isCustom: false, type: 'none', value: 0, rawString: '0' };
            } else if (discAttr.endsWith('%')) {
                return { isCustom: false, type: 'percent', value: parseFloat(discAttr), rawString: discAttr };
            } else if (discAttr.startsWith('$')) {
                return { isCustom: false, type: 'fixed', value: parseFloat(discAttr.replace('$', '')), rawString: discAttr };
            }
            return { isCustom: false, type: 'none', value: 0, rawString: '0' };
        }
    }

    static updateDiscountModalPreview() {
        const exchangeRate = this.getExchangeRate();
        let subtotalUsd = 0;
        (this.cart || []).forEach(item => {
            subtotalUsd += item.price_usd * item.qty;
        });
        const subtotalKhr = Math.round(subtotalUsd * exchangeRate);

        const state = this.getDiscountModalState();
        let discountUsd = 0;

        if (!isNaN(state.value) && state.value > 0) {
            if (state.type === 'percent') {
                discountUsd = subtotalUsd * (Math.min(100, Math.max(0, state.value)) / 100);
            } else if (state.type === 'fixed') {
                discountUsd = Math.min(subtotalUsd, Math.max(0, state.value));
            }
        }

        const discountKhr = Math.round(discountUsd * exchangeRate);
        const newTotalUsd = Math.max(0, subtotalUsd - discountUsd);
        const newTotalKhr = Math.round(newTotalUsd * exchangeRate);

        const elSubtotal = document.getElementById('discPreviewSubtotal');
        const elAmount = document.getElementById('discPreviewAmount');
        const elTotalUsd = document.getElementById('discPreviewTotalUsd');
        const elTotalKhr = document.getElementById('discPreviewTotalKhr');

        if (elSubtotal) elSubtotal.textContent = `$${subtotalUsd.toFixed(2)} (${subtotalKhr.toLocaleString()} ៛)`;
        if (elAmount) elAmount.textContent = `-$${discountUsd.toFixed(2)} (-${discountKhr.toLocaleString()} ៛)`;
        if (elTotalUsd) elTotalUsd.textContent = `$${newTotalUsd.toFixed(2)}`;
        if (elTotalKhr) elTotalKhr.textContent = `${newTotalKhr.toLocaleString()} ៛`;
    }

    static applyDiscountFromModal() {
        this.clearDiscountModalAlert();

        let subtotalUsd = 0;
        (this.cart || []).forEach(item => {
            subtotalUsd += item.price_usd * item.qty;
        });

        const state = this.getDiscountModalState();

        if (state.isCustom) {
            if (isNaN(state.value)) {
                this.showDiscountModalAlert(I18nManager.t('errDiscountNegative') || 'Please enter a valid discount value');
                return;
            }
            if (state.value < 0) {
                this.showDiscountModalAlert(I18nManager.t('errDiscountNegative') || 'Discount cannot be negative');
                return;
            }
            if (state.type === 'percent' && state.value > 100) {
                this.showDiscountModalAlert(I18nManager.t('errDiscountPercentMax') || 'Percentage discount cannot exceed 100%');
                return;
            }
            if (state.type === 'fixed' && state.value > subtotalUsd) {
                this.showDiscountModalAlert(I18nManager.t('errDiscountAmountMax') || 'Discount amount cannot exceed subtotal');
                return;
            }

            if (state.value === 0) {
                this.setDiscount('0');
            } else if (state.type === 'percent') {
                this.setDiscount(`${state.value}%`);
            } else {
                this.setDiscount(`$${state.value}`);
            }
        } else {
            this.setDiscount(state.rawString);
        }

        this.closeDiscountModal();
    }

    static calculateTotals() {
        const exchangeRate = this.getExchangeRate();

        let subtotalUsd = 0;
        this.cart.forEach(item => {
            subtotalUsd += item.price_usd * item.qty;
        });

        let discountUsd = 0;
        if (this.currentDiscount.type === 'percent') {
            discountUsd = subtotalUsd * (this.currentDiscount.value / 100);
        } else if (this.currentDiscount.type === 'fixed') {
            discountUsd = Math.min(subtotalUsd, this.currentDiscount.value);
        }

        const totalUsd = Math.max(0, subtotalUsd - discountUsd);
        const subtotalKhr = Math.round(subtotalUsd * exchangeRate);
        const totalKhr = Math.round(totalUsd * exchangeRate);

        return {
            subtotalUsd,
            subtotalKhr,
            discountUsd,
            totalUsd,
            totalKhr,
            exchangeRate
        };
    }

    static renderCart() {
        const cartList = document.getElementById('cartList');
        const countBadge = document.getElementById('cartCountBadge');
        const checkoutBtn = document.getElementById('btnOpenCheckout');

        if (!cartList) return;

        const totalQty = this.cart.reduce((sum, it) => sum + it.qty, 0);
        if (countBadge) {
            countBadge.textContent = totalQty;
            countBadge.style.display = totalQty > 0 ? 'inline-flex' : 'none';
        }

        const btnCartPillClear = document.getElementById('btnCartPillClear');

        if (this.cart.length === 0) {
            cartList.innerHTML = `
                <div class="cart-empty-state">
                    <div class="cart-empty-icon-wrap">
                        <i class="fa-solid fa-cart-shopping"></i>
                    </div>
                    <h3 class="cart-empty-title" data-i18n="cartEmptyTitle">${I18nManager.t('cartEmptyTitle')}</h3>
                    <p class="cart-empty-subtitle" data-i18n="cartEmptySubtitle">${I18nManager.t('cartEmptySubtitle')}</p>
                </div>
            `;
            if (checkoutBtn) checkoutBtn.disabled = true;
            const payLaterBtn = document.getElementById('btnPayLater');
            if (payLaterBtn) payLaterBtn.disabled = true;
            if (btnCartPillClear) btnCartPillClear.disabled = true;
            const btnCartPillDiscount = document.getElementById('btnCartPillDiscount');
            if (btnCartPillDiscount) {
                btnCartPillDiscount.disabled = true;
                btnCartPillDiscount.classList.remove('active');
                btnCartPillDiscount.setAttribute('title', I18nManager.t('cartEmptyForDiscount') || 'Add items to cart first');
            }
            const cartDiscountBadge = document.getElementById('cartDiscountBadge');
            if (cartDiscountBadge) cartDiscountBadge.style.display = 'none';
        } else {
            if (btnCartPillClear) btnCartPillClear.disabled = false;
            const btnCartPillDiscount = document.getElementById('btnCartPillDiscount');
            if (btnCartPillDiscount) {
                btnCartPillDiscount.disabled = false;
                btnCartPillDiscount.setAttribute('title', I18nManager.t('discount') || 'Discount');
            }
            let html = '';
            this.cart.forEach((it, idx) => {
                const lineTotalUsd = it.price_usd * it.qty;
                const lineTotalKhr = it.price_khr * it.qty;
                const isFree = (it.is_member_benefit || it.price_usd === 0);
                const freeBadge = isFree ? `<span class="cart-free-badge">${I18nManager.t('includedNoCharge') || 'FREE'}</span>` : '';
                const upsellBadge = it.is_upsell ? `<span class="badge-accent" style="font-size: 0.65rem; margin-left: 0.35rem; font-weight: 700; background: #ede9fe; color: #7c3aed; padding: 1px 5px; border-radius: 4px;">UPSELL</span>` : '';

                html += `
                    <div class="cart-item-row">
                        <div class="cart-item-main">
                            <div class="cart-item-info">
                                <span class="cart-item-title">${this.escapeHtml(it.name_km || it.name_en)} ${freeBadge}${upsellBadge}</span>
                                <span class="cart-item-sub">${this.escapeHtml(it.name_en)}${it.note ? ` • ${this.escapeHtml(it.note)}` : ''}</span>
                            </div>
                            <div>
                                <div class="cart-item-price">${isFree ? '$0.00' : '$' + lineTotalUsd.toFixed(2)}</div>
                                <div class="cart-item-khr">${isFree ? '0 ៛' : lineTotalKhr.toLocaleString() + ' ៛'}</div>
                            </div>
                        </div>
                        <div class="cart-item-actions">
                            <div class="qty-control">
                                <button type="button" class="qty-btn" onclick="PosApp.updateCartQty(${idx}, -1)">-</button>
                                <span class="qty-val">${it.qty}</span>
                                <button type="button" class="qty-btn" onclick="PosApp.updateCartQty(${idx}, 1)">+</button>
                            </div>
                            <button type="button" class="btn-remove-item" onclick="PosApp.removeCartItem(${idx})">
                                <i class="fa-solid fa-trash-can"></i> Remove
                            </button>
                        </div>
                    </div>
                `;
            });
            cartList.innerHTML = html;
            if (checkoutBtn) checkoutBtn.disabled = false;
            const payLaterBtn = document.getElementById('btnPayLater');
            if (payLaterBtn) payLaterBtn.disabled = false;
        }

        // Update Totals Summary
        const totals = this.calculateTotals();
        document.getElementById('cartSubtotalText').textContent = `$${totals.subtotalUsd.toFixed(2)} (${totals.subtotalKhr.toLocaleString()} ៛)`;

        const discRow = document.getElementById('discountSummaryRow');
        const discText = document.getElementById('cartDiscountText');
        const discLabel = document.getElementById('cartDiscountLabel');
        const btnCartPillDiscount = document.getElementById('btnCartPillDiscount');
        const cartDiscountBadge = document.getElementById('cartDiscountBadge');

        if (totals.discountUsd > 0) {
            if (discRow) discRow.style.display = 'flex';
            if (discText) discText.textContent = `-$${totals.discountUsd.toFixed(2)}`;
            if (discLabel) {
                let detail = '';
                if (this.currentDiscount.type === 'percent') {
                    detail = ` (${this.currentDiscount.value}%)`;
                } else if (this.currentDiscount.type === 'fixed') {
                    detail = ` ($${this.currentDiscount.value.toFixed(2)})`;
                }
                discLabel.textContent = `${I18nManager.t('discount')}${detail}`;
            }
            if (btnCartPillDiscount) {
                btnCartPillDiscount.classList.add('active');
            }
            if (cartDiscountBadge) {
                cartDiscountBadge.style.display = 'inline-flex';
                if (this.currentDiscount.type === 'percent') {
                    cartDiscountBadge.textContent = `${this.currentDiscount.value}%`;
                } else {
                    cartDiscountBadge.textContent = `$${this.currentDiscount.value}`;
                }
            }
        } else {
            if (discRow) discRow.style.display = 'none';
            if (btnCartPillDiscount) {
                btnCartPillDiscount.classList.remove('active');
            }
            if (cartDiscountBadge) {
                cartDiscountBadge.style.display = 'none';
            }
        }

        document.getElementById('cartTotalUsd').textContent = `$${totals.totalUsd.toFixed(2)}`;
        document.getElementById('cartTotalKhr').textContent = `${totals.totalKhr.toLocaleString()} ៛`;

        // Update Cart Wait Time & Trigger Server Refresh
        this.updateWaitTimesUI();
        this.fetchWaitTimes();

        // Requirement 2: Check for upsell offer (show at most one offer, never block the sale)
        this.checkCartUpsellRules();
    }

    // ========================================================
    // CUSTOMER & VEHICLE LOOKUP
    // ========================================================
    static handleCustomerSearch(query) {
        const q = (query || '').trim().toLowerCase();
        const dropdown = document.getElementById('custDropdown');
        if (!dropdown) return;

        const panel = document.getElementById('panelSavedCustomers');
        if (panel && panel.style.display !== 'none') {
            dropdown.classList.remove('open');
            this.custSearchHighlightedIndex = -1;
            return;
        }

        if (q.length < 1) {
            dropdown.classList.remove('open');
            this.custSearchHighlightedIndex = -1;
            return;
        }

        const data = PosStorage.getCustomers();
        const vehicles = Array.isArray(data.vehicles) ? data.vehicles : [];
        const customers = Array.isArray(data.customers) ? data.customers : [];
        const isKm = (I18nManager.getLang() === 'km');

        // Requirement 4: Group multi-vehicle customers into one row per customer with +N badge
        const matches = [];
        const seenCustIds = new Set();

        // 1. Search customers and their vehicles
        customers.forEach(cust => {
            const custVehicles = vehicles.filter(v => v.customer_id == cust.id);
            const matchName = (cust.name || '').toLowerCase().includes(q);
            const matchPhone = (cust.phone || '').toLowerCase().includes(q);
            const matchedVeh = custVehicles.filter(v => (v.plate_number || '').toLowerCase().includes(q));

            if (matchName || matchPhone || matchedVeh.length > 0) {
                seenCustIds.add(cust.id);
                let sortedVehicles = [...custVehicles];
                if (matchedVeh.length > 0) {
                    sortedVehicles.sort((a, b) => {
                        const aMatch = (a.plate_number || '').toLowerCase().includes(q);
                        const bMatch = (b.plate_number || '').toLowerCase().includes(q);
                        return (bMatch ? 1 : 0) - (aMatch ? 1 : 0);
                    });
                }
                matches.push({
                    customer_id: cust.id,
                    name: cust.name || 'Customer',
                    phone: cust.phone || '',
                    membership_level: cust.membership_level || 'standard',
                    loyalty_points: parseInt(cust.loyalty_points || 0),
                    total_visits: parseInt(cust.total_visits || 0),
                    vehicles: sortedVehicles
                });
            }
        });

        // 2. Also check for vehicles whose customer wasn't matched or is orphaned
        vehicles.forEach(v => {
            if (v.customer_id && seenCustIds.has(v.customer_id)) return;
            const matchPlate = (v.plate_number || '').toLowerCase().includes(q);
            if (matchPlate) {
                const cust = customers.find(c => c.id == v.customer_id) || {};
                matches.push({
                    customer_id: cust.id || null,
                    name: cust.name || 'Customer',
                    phone: cust.phone || '',
                    membership_level: cust.membership_level || 'standard',
                    loyalty_points: parseInt(cust.loyalty_points || 0),
                    total_visits: parseInt(cust.total_visits || 0),
                    vehicles: [v]
                });
            }
        });

        if (matches.length === 0) {
            const noCustText = I18nManager.t('noCustomerFound') || 'No customer found';
            const newCustBtnText = I18nManager.t('newCustomerBtn') || '+ New Customer';
            dropdown.innerHTML = `
                <div class="cust-dropdown-empty">
                    <span class="cust-empty-msg">${this.escapeHtml(noCustText)}</span>
                    <button type="button" class="btn btn-sm btn-outline btn-dropdown-new-cust" id="btnDropdownNewCust">
                        <i class="fa-solid fa-plus"></i> <span>${this.escapeHtml(newCustBtnText)}</span>
                    </button>
                </div>
            `;
            const btnNew = dropdown.querySelector('#btnDropdownNewCust');
            if (btnNew) {
                btnNew.addEventListener('click', (e) => {
                    e.stopPropagation();
                    dropdown.classList.remove('open');
                    this.custSearchHighlightedIndex = -1;
                    document.getElementById('formAddCustomer')?.reset();
                    const vtypeEl = document.getElementById('newCustVehicleType');
                    if (vtypeEl) {
                        vtypeEl.value = this.selectedVehicleType;
                        vtypeEl.dispatchEvent(new Event('change'));
                    }
                    const curQuery = (document.getElementById('custSearchInput')?.value || '').trim();
                    if (/^\d+$/.test(curQuery)) {
                        const phoneEl = document.getElementById('newCustPhone');
                        if (phoneEl) phoneEl.value = curQuery;
                    } else if (curQuery) {
                        const plateEl = document.getElementById('newCustPlate');
                        if (plateEl) plateEl.value = curQuery.toUpperCase();
                    }
                    document.getElementById('modalCustomer')?.classList.add('open');
                });
            }
            dropdown.classList.add('open');
            this.custSearchHighlightedIndex = -1;
            return;
        }

        this.currentCustomerSearchResults = matches;
        let html = '';
        this.currentCustomerSearchResults.forEach((m, idx) => {
            const plainName = this.stripHtml(m.name || 'Customer') || 'Customer';
            const highlightedName = this.highlightMatch(plainName, q);
            const initials = this.getCustomerInitials(plainName);
            const highlightedPhone = this.highlightMatch(m.phone || '', q);
            const points = parseInt(m.loyalty_points || 0);
            const level = (m.membership_level || 'standard').toLowerCase();
            const visits = parseInt(m.total_visits || 0);

            const isVip = (level === 'gold' || level === 'vip' || level === 'platinum' || plainName.toLowerCase().includes('vip'));

            let tierClass = 'tier-standard';
            let tierIcon = 'fa-shield-halved';
            let tierLabel = m.membership_level || 'Standard';
            if (isVip || level === 'gold') {
                tierClass = 'tier-gold';
                tierIcon = 'fa-crown';
                tierLabel = 'VIP';
            } else if (level === 'silver') {
                tierClass = 'tier-silver';
                tierIcon = 'fa-medal';
                tierLabel = 'Silver';
            } else if (level === 'platinum') {
                tierClass = 'tier-platinum';
                tierIcon = 'fa-gem';
                tierLabel = 'Platinum';
            }

            const tierHtml = `<span class="tier-pill ${tierClass}"><i class="fa-solid ${tierIcon}"></i> ${this.escapeHtml(tierLabel)}</span>`;
            const pointsHtml = (points > 0)
                ? `<span class="points-pill"><i class="fa-solid fa-star"></i> ${points} pts</span>`
                : '';
            const visitsHtml = (visits > 0)
                ? `<span class="visit-badge"><i class="fa-solid fa-car"></i> ${visits} ${isKm ? 'លើក' : 'visits'}</span>`
                : '';

            const vehCount = (m.vehicles || []).length;
            let chipsHtml = '';
            let detailHtml = '';

            if (vehCount > 0) {
                const firstVeh = m.vehicles[0];
                const firstPlate = this.escapeHtml(firstVeh.plate_number || '');
                const firstVtype = firstVeh.vehicle_type || 'small_car';
                let firstIcon = 'fa-car';
                if (firstVtype === 'motorbike') firstIcon = 'fa-motorcycle';
                else if (firstVtype === 'van_truck') firstIcon = 'fa-truck';
                else if (firstVtype === 'suv_pickup') firstIcon = 'fa-truck-pickup';

                const highlightedFirstPlate = this.highlightMatch(firstPlate, q);

                chipsHtml = `
                    <button type="button" class="cust-vehicle-chip btn-search-chip"
                        data-plate="${firstPlate}"
                        data-vtype="${firstVtype}"
                        data-name="${this.escapeHtml(plainName)}"
                        data-phone="${this.escapeHtml(m.phone || '')}"
                        data-cid="${m.customer_id || ''}"
                        data-vid="${firstVeh.id || ''}"
                        title="${firstPlate}">
                        <i class="fa-solid ${firstIcon}"></i>
                        <span>${highlightedFirstPlate}</span>
                    </button>
                `;

                if (vehCount > 1) {
                    chipsHtml += `
                        <button type="button" class="btn-toggle-vehicles btn-search-toggle-veh" data-idx="${idx}" title="View all ${vehCount} vehicles">
                            <i class="fa-solid fa-chevron-down"></i>
                            <span>+${vehCount - 1}</span>
                        </button>
                    `;

                    m.vehicles.forEach(v => {
                        const vPlate = this.escapeHtml(v.plate_number || '');
                        const vtype = this.escapeHtml(v.vehicle_type || 'small_car');
                        const model = this.escapeHtml(v.make_model || '');
                        const color = this.escapeHtml(v.color || '');

                        let vtypeLabel = vtype;
                        if (vtype === 'small_car') vtypeLabel = isKm ? 'ឡានតូច' : 'Small Car';
                        else if (vtype === 'suv_pickup') vtypeLabel = isKm ? 'ឡានធំ/SUV' : 'SUV/Pickup';
                        else if (vtype === 'motorbike') vtypeLabel = isKm ? 'ម៉ូតូ' : 'Motorbike';
                        else if (vtype === 'van_truck') vtypeLabel = isKm ? 'ឡានដឹក' : 'Van/Truck';

                        const highlightedVPlate = this.highlightMatch(vPlate, q);

                        detailHtml += `
                            <div class="vehicle-detail-row">
                                <div class="vehicle-detail-info">
                                    <span class="vehicle-detail-plate">${highlightedVPlate}</span>
                                    <span class="vehicle-detail-vtype">${this.escapeHtml(vtypeLabel)}</span>
                                    ${model || color ? `<span class="vehicle-detail-extra">${model}${color ? ` (${color})` : ''}</span>` : ''}
                                </div>
                                <button type="button" class="btn btn-sm btn-outline btn-select-vehicle-sub btn-search-select-sub"
                                    data-plate="${vPlate}"
                                    data-vtype="${vtype}"
                                    data-name="${this.escapeHtml(plainName)}"
                                    data-phone="${this.escapeHtml(m.phone || '')}"
                                    data-cid="${m.customer_id || ''}"
                                    data-vid="${v.id || ''}">
                                    ${I18nManager.t('selectVehicleBtn') || 'Select'}
                                </button>
                            </div>
                        `;
                    });
                }
            }

            html += `
                <div class="saved-cust-card cust-search-row" data-cust-idx="${idx}">
                    <div class="saved-cust-row-main">
                        <div class="saved-cust-avatar ${isVip ? 'avatar-vip' : ''}">${initials}</div>
                        <div class="saved-cust-info">
                            <div class="saved-cust-name-row">
                                <span class="saved-cust-name" title="${this.escapeHtml(plainName)}">${highlightedName}</span>
                                ${tierHtml}
                                ${pointsHtml}
                            </div>
                            <div class="saved-cust-meta-row">
                                ${m.phone ? `<span class="saved-cust-phone"><i class="fa-solid fa-phone"></i> ${highlightedPhone}</span>` : ''}
                                ${visitsHtml}
                            </div>
                        </div>
                        <div class="saved-cust-vehicles">
                            ${chipsHtml}
                        </div>
                    </div>
                    ${detailHtml ? `
                        <div class="cust-vehicles-detail" style="display: none;">
                            ${detailHtml}
                        </div>
                    ` : ''}
                </div>
            `;
        });

        dropdown.innerHTML = html;
        this.custSearchHighlightedIndex = -1;

        const rows = dropdown.querySelectorAll('.cust-search-row');
        rows.forEach((row, idx) => {
            row.addEventListener('mouseenter', () => {
                this.custSearchHighlightedIndex = idx;
                this.updateCustSearchHighlight(rows);
            });

            // Toggle sub-vehicles
            const btnToggle = row.querySelector('.btn-search-toggle-veh');
            const detailBox = row.querySelector('.cust-vehicles-detail');
            if (btnToggle && detailBox) {
                btnToggle.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isClosed = (detailBox.style.display === 'none' || !detailBox.style.display);
                    detailBox.style.display = isClosed ? 'flex' : 'none';
                    row.classList.toggle('expanded', isClosed);
                    const icon = btnToggle.querySelector('i');
                    if (icon) {
                        icon.className = isClosed ? 'fa-solid fa-chevron-up' : 'fa-solid fa-chevron-down';
                    }
                });
            }

            // Sub-vehicle select button click
            row.querySelectorAll('.btn-search-select-sub').forEach(subBtn => {
                subBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const plate = subBtn.getAttribute('data-plate');
                    const name = subBtn.getAttribute('data-name');
                    const phone = subBtn.getAttribute('data-phone');
                    const vtype = subBtn.getAttribute('data-vtype');
                    const cid = subBtn.getAttribute('data-cid') ? parseInt(subBtn.getAttribute('data-cid')) : null;
                    const vid = subBtn.getAttribute('data-vid') ? parseInt(subBtn.getAttribute('data-vid')) : null;
                    this.selectCustomer(plate, name, phone, vtype, cid, vid);
                    dropdown.classList.remove('open');
                    this.custSearchHighlightedIndex = -1;
                });
            });

            // Chip button click
            const chipBtn = row.querySelector('.btn-search-chip');
            if (chipBtn) {
                chipBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const plate = chipBtn.getAttribute('data-plate');
                    const name = chipBtn.getAttribute('data-name');
                    const phone = chipBtn.getAttribute('data-phone');
                    const vtype = chipBtn.getAttribute('data-vtype');
                    const cid = chipBtn.getAttribute('data-cid') ? parseInt(chipBtn.getAttribute('data-cid')) : null;
                    const vid = chipBtn.getAttribute('data-vid') ? parseInt(chipBtn.getAttribute('data-vid')) : null;
                    this.selectCustomer(plate, name, phone, vtype, cid, vid);
                    dropdown.classList.remove('open');
                    this.custSearchHighlightedIndex = -1;
                });
            }

            // Whole row click
            row.addEventListener('click', (e) => {
                if (e.target.closest('.btn-search-select-sub') || e.target.closest('.btn-search-toggle-veh') || e.target.closest('.btn-search-chip')) {
                    return;
                }
                const m = this.currentCustomerSearchResults[idx];
                if (!m) return;

                const vehCount = (m.vehicles || []).length;
                if (vehCount > 1 && detailBox) {
                    // Expand vehicle list so user can pick vehicle
                    const isClosed = (detailBox.style.display === 'none' || !detailBox.style.display);
                    detailBox.style.display = isClosed ? 'flex' : 'none';
                    row.classList.toggle('expanded', isClosed);
                    if (btnToggle) {
                        const icon = btnToggle.querySelector('i');
                        if (icon) icon.className = isClosed ? 'fa-solid fa-chevron-up' : 'fa-solid fa-chevron-down';
                    }
                } else {
                    const firstVeh = (m.vehicles && m.vehicles[0]) || {};
                    this.selectCustomer(firstVeh.plate_number || '', m.name, m.phone, firstVeh.vehicle_type || 'small_car', m.customer_id, firstVeh.id || null);
                    dropdown.classList.remove('open');
                    this.custSearchHighlightedIndex = -1;
                }
            });
        });

        dropdown.classList.add('open');
    }

    static selectCustomer(plate, name, phone, vehicleType, customerId = null, vehicleId = null) {
        const cleanName = this.stripHtml(name) || 'Customer';
        const cleanPlate = this.stripHtml(plate);
        this.currentCustomer = {
            plate: cleanPlate,
            name: cleanName,
            phone: phone || '',
            vehicle_type: vehicleType || this.selectedVehicleType,
            customer_id: customerId,
            vehicle_id: vehicleId
        };

        // Update UI
        document.getElementById('custDropdown')?.classList.remove('open');
        this.closeSavedCustomersPanel();
        document.getElementById('custSearchInput').value = '';
        document.getElementById('selectedCustBadgeWrap').style.display = 'block';
        document.getElementById('badgePlate').textContent = cleanPlate;
        document.getElementById('badgeName').textContent = cleanName;
        document.getElementById('badgePhone').textContent = phone || '-';

        // Update topbar customer dropdown display
        const topCustName = document.getElementById('topbarCustomerName');
        if (topCustName) {
            topCustName.textContent = cleanPlate ? `${cleanName} (${cleanPlate})` : cleanName;
        }
        const popover = document.getElementById('topbarCustomerPopover');
        if (popover) {
            popover.style.display = 'none';
        }
        document.getElementById('btnCustomerDropdown')?.classList.remove('active');

        // Auto-switch vehicle type if known
        if (vehicleType) {
            this.selectedVehicleType = vehicleType;
            document.querySelectorAll('.vehicle-btn').forEach(btn => {
                btn.classList.toggle('active', btn.getAttribute('data-vtype') === vehicleType);
            });
            this.updateVehiclePricesInCart();
            this.renderCatalog();
        }

        // Vehicle Recognition - Load Customer & Vehicle Profile (Requirement 4)
        this.loadVehicleProfile(cleanPlate);
    }

    static setCustomerToWalkIn() {
        const randomPlate = 'W-' + Math.floor(1000 + Math.random() * 9000);
        this.selectCustomer(randomPlate, 'Walk-in Guest', '', this.selectedVehicleType);
    }

    static resetCustomer() {
        this.currentCustomer = null;
        this.currentCustomerBenefits = [];
        this.activeLoyaltyReward = null;
        this.loyaltyRewardGiven = null;
        this.loyaltyRewardActionChosen = false;
        this.activeUpsellOffer = null;
        this._currentUpsellModalOpen = false;
        if (this._dismissedUpsellIds) this._dismissedUpsellIds.clear();
        document.getElementById('modalUpsellPrompt')?.classList.remove('open');
        document.getElementById('selectedCustBadgeWrap').style.display = 'none';
        document.getElementById('custSearchInput').value = '';
        const topCustName = document.getElementById('topbarCustomerName');
        if (topCustName) {
            topCustName.textContent = I18nManager.t('generalCustomer');
        }
        const profWrap = document.getElementById('cartVehicleProfile');
        if (profWrap) profWrap.style.display = 'none';
        document.getElementById('profMemberPanel')?.setAttribute('style', 'display: none;');
        document.getElementById('cartLoyaltyRewardBanner')?.setAttribute('style', 'display: none;');
        document.getElementById('cartLoyaltyRewardStatusBadge')?.setAttribute('style', 'display: none;');
        document.getElementById('custSearchInput').focus();
    }

    // ========================================================
    // SAVED CUSTOMERS DIRECTORY PANEL
    // ========================================================
    static savedCustActiveTab = 'all';
    static savedCustQuery = '';
    static savedCustDebounceTimer = null;
    static savedCustFilteredList = [];
    static savedCustRenderedCount = 0;
    static savedCustBatchSize = 30;
    static savedCustExpandedVehicles = new Set();

    static canAccessCustomerList() {
        const role = (this.currentUser?.role || '').toLowerCase();
        return (role !== 'washer' && role !== 'mechanic');
    }

    static initSavedCustomersPanel() {
        const btnOpen = document.getElementById('btnOpenSavedCustomers');
        if (btnOpen) {
            btnOpen.addEventListener('click', (e) => {
                e.stopPropagation();
                this.toggleSavedCustomersPanel();
            });
        }

        const listWrap = document.getElementById('savedCustListWrap');
        if (listWrap) {
            listWrap.addEventListener('scroll', () => {
                if (listWrap.scrollTop + listWrap.clientHeight >= listWrap.scrollHeight - 60) {
                    this.renderSavedCustomersBatch();
                }
            });
        }

        const itemsContainer = document.getElementById('savedCustItems');
        if (itemsContainer) {
            itemsContainer.addEventListener('click', (e) => {
                const btnToggle = e.target.closest('.btn-toggle-vehicles');
                if (btnToggle) {
                    e.stopPropagation();
                    const custId = parseInt(btnToggle.getAttribute('data-cust-id'));
                    if (this.savedCustExpandedVehicles.has(custId)) {
                        this.savedCustExpandedVehicles.delete(custId);
                    } else {
                        this.savedCustExpandedVehicles.add(custId);
                    }
                    this.renderSavedCustomersList(false);
                    return;
                }

                const vehicleChip = e.target.closest('.cust-vehicle-chip') || e.target.closest('.btn-select-vehicle-sub');
                if (vehicleChip) {
                    e.stopPropagation();
                    const plate = vehicleChip.getAttribute('data-plate');
                    const vtype = vehicleChip.getAttribute('data-vtype');
                    const name = vehicleChip.getAttribute('data-name');
                    const phone = vehicleChip.getAttribute('data-phone');
                    const cid = vehicleChip.getAttribute('data-cid') ? parseInt(vehicleChip.getAttribute('data-cid')) : null;
                    const vid = vehicleChip.getAttribute('data-vid') ? parseInt(vehicleChip.getAttribute('data-vid')) : null;
                    this.selectCustomer(plate, name, phone, vtype, cid, vid);
                    this.closeSavedCustomersPanel();
                    return;
                }

                const card = e.target.closest('.saved-cust-card');
                if (card) {
                    const custId = parseInt(card.getAttribute('data-cust-id'));
                    const cust = (this.savedCustFilteredList || []).find(c => c.id === custId);
                    if (cust && cust.vehicles && cust.vehicles.length === 1) {
                        const firstVeh = cust.vehicles[0];
                        const cleanCustName = this.stripHtml(cust.name || 'Customer');
                        this.selectCustomer(firstVeh.plate_number, cleanCustName, cust.phone, firstVeh.vehicle_type, cust.id, firstVeh.id);
                        this.closeSavedCustomersPanel();
                    } else if (cust && cust.vehicles && cust.vehicles.length > 1) {
                        if (this.savedCustExpandedVehicles.has(custId)) {
                            this.savedCustExpandedVehicles.delete(custId);
                        } else {
                            this.savedCustExpandedVehicles.add(custId);
                        }
                        this.renderSavedCustomersList(false);
                    } else if (cust) {
                        const cleanCustName = this.stripHtml(cust.name || 'Customer');
                        const randomPlate = 'W-' + Math.floor(1000 + Math.random() * 9000);
                        this.selectCustomer(randomPlate, cleanCustName, cust.phone, this.selectedVehicleType, cust.id, null);
                        this.closeSavedCustomersPanel();
                    }
                }
            });
        }
    }

    static openSavedCustomersPanel() {
        if (!this.canAccessCustomerList()) {
            this.showToast('warning', I18nManager.t('accessRestricted') || 'Access restricted');
            return;
        }

        const panel = document.getElementById('panelSavedCustomers');
        const iconBtn = document.getElementById('btnOpenSavedCustomers');
        if (!panel) return;

        // Hide autocomplete dropdown so they do not collide
        document.getElementById('custDropdown')?.classList.remove('open');

        panel.style.display = 'flex';
        if (iconBtn) {
            iconBtn.classList.add('active');
            if (!iconBtn.dataset.origTitle && iconBtn.hasAttribute('title')) {
                iconBtn.dataset.origTitle = iconBtn.getAttribute('title');
            }
            iconBtn.removeAttribute('title');
            iconBtn.blur();
        }

        // Search using main search bar value
        const mainInput = document.getElementById('custSearchInput');
        this.savedCustQuery = (mainInput?.value || '').trim().toLowerCase();

        this.renderSavedCustomersList(true);

        if (mainInput) {
            mainInput.focus();
        }

        const data = PosStorage.getCustomers();
        const isOffline = !navigator.onLine || (typeof this.isOnline !== 'undefined' && !this.isOnline);
        if ((!data.customers || data.customers.length === 0) && !isOffline) {
            this.refreshSavedCustomersList(false);
        }
    }

    static closeSavedCustomersPanel() {
        const panel = document.getElementById('panelSavedCustomers');
        if (panel) panel.style.display = 'none';
        const iconBtn = document.getElementById('btnOpenSavedCustomers');
        if (iconBtn) {
            iconBtn.classList.remove('active');
            if (iconBtn.dataset.origTitle) {
                iconBtn.setAttribute('title', iconBtn.dataset.origTitle);
            }
        }
    }

    static toggleSavedCustomersPanel() {
        const panel = document.getElementById('panelSavedCustomers');
        if (panel && panel.style.display !== 'none') {
            this.closeSavedCustomersPanel();
        } else {
            this.openSavedCustomersPanel();
        }
    }

    static updateSavedCustomersLastUpdatedUI() {
        const timeEl = document.getElementById('savedCustLastUpdated');
        if (!timeEl) return;
        const lastUpdated = PosStorage.getCustomersLastUpdated();
        if (!lastUpdated) {
            timeEl.textContent = '--';
            return;
        }
        try {
            const date = new Date(lastUpdated);
            if (isNaN(date.getTime())) {
                timeEl.textContent = lastUpdated;
                return;
            }
            const isKm = (I18nManager.getLang() === 'km');
            timeEl.textContent = date.toLocaleTimeString(isKm ? 'km-KH' : 'en-US', {
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            });
        } catch (e) {
            timeEl.textContent = lastUpdated;
        }
    }

    static handleSavedCustomersSearch(query) {
        clearTimeout(this.savedCustDebounceTimer);
        this.savedCustDebounceTimer = setTimeout(() => {
            this.savedCustQuery = (query || '').trim().toLowerCase();
            this.renderSavedCustomersList(true);
        }, 120);
    }

    static setSavedCustomersTab(tab) {
        this.savedCustActiveTab = tab || 'all';
        this.renderSavedCustomersList(true);
    }

    static async refreshSavedCustomersList(forceApi = false) {
        const loadingBox = document.getElementById('savedCustLoading');
        const noMatchBox = document.getElementById('savedCustNoMatch');
        const itemsBox = document.getElementById('savedCustItems');

        const currentData = PosStorage.getCustomers();
        const hasExisting = currentData?.customers?.length > 0;
        if (!hasExisting && loadingBox) {
            loadingBox.style.display = 'block';
            if (noMatchBox) noMatchBox.style.display = 'none';
            if (itemsBox) itemsBox.style.display = 'none';
        }

        try {
            const res = await PosApi.syncCustomers();
            if (res && res.success) {
                this.updateSavedCustomersLastUpdatedUI();
                if (forceApi) {
                    this.showToast('success', (I18nManager.t('savedCustomersTitle') || 'Customers') + ' ' + (I18nManager.t('refresh') || 'updated'));
                }
            }
        } catch (e) {
            console.warn('Customer sync offline / failed, using local cache:', e);
        } finally {
            if (loadingBox) loadingBox.style.display = 'none';
            if (itemsBox) itemsBox.style.display = 'flex';
            this.renderSavedCustomersList(true);
        }
    }

    static filterAndSortCustomers() {
        const data = PosStorage.getCustomers();
        const customers = Array.isArray(data.customers) ? data.customers : [];
        const vehicles = Array.isArray(data.vehicles) ? data.vehicles : [];

        const vehicleMap = new Map();
        vehicles.forEach(v => {
            const cid = v.customer_id;
            if (!vehicleMap.has(cid)) vehicleMap.set(cid, []);
            vehicleMap.get(cid).push(v);
        });

        let enriched = customers.map(c => {
            return {
                ...c,
                vehicles: vehicleMap.get(c.id) || []
            };
        });

        const q = this.savedCustQuery;
        if (q) {
            enriched = enriched.filter(c => {
                const matchName = (c.name || '').toLowerCase().includes(q);
                const matchPhone = (c.phone || '').toLowerCase().includes(q);
                const matchVehicle = c.vehicles.some(v =>
                    (v.plate_number || '').toLowerCase().includes(q) ||
                    (v.make_model || '').toLowerCase().includes(q) ||
                    (v.vehicle_type || '').toLowerCase().includes(q)
                );
                return matchName || matchPhone || matchVehicle;
            });
        }

        // Sort: recent customers first (by total_visits DESC, then ID DESC)
        enriched.sort((a, b) => {
            const visitsA = parseInt(a.total_visits || 0);
            const visitsB = parseInt(b.total_visits || 0);
            if (visitsB !== visitsA) return visitsB - visitsA;
            return (b.id || 0) - (a.id || 0);
        });

        this.savedCustFilteredList = enriched;
        return enriched;
    }

    static renderSavedCustomersList(resetScroll = true) {
        const listWrap = document.getElementById('savedCustListWrap');
        const itemsBox = document.getElementById('savedCustItems');
        const noMatch = document.getElementById('savedCustNoMatch');
        const loadMoreIndicator = document.getElementById('savedCustLoadMore');

        if (resetScroll) {
            this.savedCustRenderedCount = 0;
            if (listWrap) listWrap.scrollTop = 0;
            if (itemsBox) itemsBox.innerHTML = '';
        }

        const data = PosStorage.getCustomers();
        const totalInCache = (data.customers || []).length;

        if (totalInCache === 0) {
            if (noMatch) {
                noMatch.style.display = 'block';
                noMatch.textContent = I18nManager.t('noSavedCustomers') || 'No saved customers found';
            }
            if (itemsBox) itemsBox.style.display = 'none';
            if (loadMoreIndicator) loadMoreIndicator.style.display = 'none';
            return;
        }

        const filtered = this.filterAndSortCustomers();

        if (filtered.length === 0) {
            if (noMatch) {
                noMatch.style.display = 'block';
                noMatch.textContent = I18nManager.t('noSavedCustomers') || 'No saved customers found';
            }
            if (itemsBox) itemsBox.style.display = 'none';
            if (loadMoreIndicator) loadMoreIndicator.style.display = 'none';
            return;
        } else {
            if (noMatch) noMatch.style.display = 'none';
            if (itemsBox) itemsBox.style.display = 'flex';
        }

        this.renderSavedCustomersBatch();
    }

    static renderSavedCustomersBatch() {
        const itemsBox = document.getElementById('savedCustItems');
        const loadMoreIndicator = document.getElementById('savedCustLoadMore');
        if (!itemsBox) return;

        const total = this.savedCustFilteredList.length;
        if (this.savedCustRenderedCount >= total) {
            if (loadMoreIndicator) loadMoreIndicator.style.display = 'none';
            return;
        }

        const nextBatch = this.savedCustFilteredList.slice(
            this.savedCustRenderedCount,
            this.savedCustRenderedCount + this.savedCustBatchSize
        );

        let html = '';
        const isKm = (I18nManager.getLang() === 'km');

        nextBatch.forEach(c => {
            const custId = c.id;
            const plainName = this.stripHtml(c.name || 'Customer') || 'Customer';
            const name = this.escapeHtml(plainName);
            const initials = this.getCustomerInitials(plainName);
            const phone = this.escapeHtml(c.phone || '');
            const points = parseInt(c.loyalty_points || 0);
            const level = (c.membership_level || 'standard').toLowerCase();
            const visits = parseInt(c.total_visits || 0);
            const vehCount = c.vehicles ? c.vehicles.length : 0;
            const isExpanded = this.savedCustExpandedVehicles.has(custId);

            const isVip = (level === 'gold' || level === 'vip' || level === 'platinum' || plainName.toLowerCase().includes('vip'));

            let tierClass = 'tier-standard';
            let tierIcon = 'fa-shield-halved';
            let tierLabel = c.membership_level || 'Standard';
            if (isVip || level === 'gold') {
                tierClass = 'tier-gold';
                tierIcon = 'fa-crown';
                tierLabel = 'VIP';
            } else if (level === 'silver') {
                tierClass = 'tier-silver';
                tierIcon = 'fa-medal';
                tierLabel = 'Silver';
            } else if (level === 'platinum') {
                tierClass = 'tier-platinum';
                tierIcon = 'fa-gem';
                tierLabel = 'Platinum';
            }

            const tierHtml = `<span class="tier-pill ${tierClass}"><i class="fa-solid ${tierIcon}"></i> ${this.escapeHtml(tierLabel)}</span>`;
            const pointsHtml = (points > 0)
                ? `<span class="points-pill"><i class="fa-solid fa-star"></i> ${points} pts</span>`
                : '';
            const visitsHtml = (visits > 0)
                ? `<span class="visit-badge"><i class="fa-solid fa-car"></i> ${visits} ${isKm ? 'លើក' : 'visits'}</span>`
                : '';

            let chipsHtml = '';
            let detailHtml = '';

            if (vehCount > 0) {
                const firstVeh = c.vehicles[0];
                const firstPlate = this.escapeHtml(firstVeh.plate_number || '');
                const firstVtype = this.escapeHtml(firstVeh.vehicle_type || 'small_car');
                let firstIcon = 'fa-car';
                if (firstVtype === 'motorbike') firstIcon = 'fa-motorcycle';
                else if (firstVtype === 'van_truck') firstIcon = 'fa-truck';
                else if (firstVtype === 'suv_pickup') firstIcon = 'fa-truck-pickup';

                chipsHtml = `
                    <button type="button" class="cust-vehicle-chip"
                        data-plate="${firstPlate}"
                        data-vtype="${firstVtype}"
                        data-name="${name}"
                        data-phone="${phone}"
                        data-cid="${custId}"
                        data-vid="${firstVeh.id}"
                        title="${firstPlate}">
                        <i class="fa-solid ${firstIcon}"></i>
                        <span>${firstPlate}</span>
                    </button>
                `;

                if (vehCount > 1) {
                    chipsHtml += `
                        <button type="button" class="btn-toggle-vehicles" data-cust-id="${custId}" title="View all ${vehCount} vehicles">
                            <i class="fa-solid ${isExpanded ? 'fa-chevron-up' : 'fa-chevron-down'}"></i>
                            <span>+${vehCount - 1}</span>
                        </button>
                    `;
                }

                c.vehicles.forEach(v => {
                    const plate = this.escapeHtml(v.plate_number || '');
                    const vtype = this.escapeHtml(v.vehicle_type || 'small_car');
                    const model = this.escapeHtml(v.make_model || '');
                    const color = this.escapeHtml(v.color || '');

                    let vtypeLabel = vtype;
                    if (vtype === 'small_car') vtypeLabel = isKm ? 'ឡានតូច' : 'Small Car';
                    else if (vtype === 'suv_pickup') vtypeLabel = isKm ? 'ឡានធំ/SUV' : 'SUV/Pickup';
                    else if (vtype === 'motorbike') vtypeLabel = isKm ? 'ម៉ូតូ' : 'Motorbike';
                    else if (vtype === 'van_truck') vtypeLabel = isKm ? 'ឡានដឹក' : 'Van/Truck';

                    detailHtml += `
                        <div class="vehicle-detail-row">
                            <div class="vehicle-detail-info">
                                <span class="vehicle-detail-plate">${plate}</span>
                                <span class="vehicle-detail-vtype">${this.escapeHtml(vtypeLabel)}</span>
                                ${model || color ? `<span class="vehicle-detail-extra">${model}${color ? ` (${color})` : ''}</span>` : ''}
                            </div>
                            <button type="button" class="btn btn-sm btn-outline btn-select-vehicle-sub"
                                data-plate="${plate}"
                                data-vtype="${vtype}"
                                data-name="${name}"
                                data-phone="${phone}"
                                data-cid="${custId}"
                                data-vid="${v.id}">
                                ${I18nManager.t('selectVehicleBtn') || 'Select'}
                            </button>
                        </div>
                    `;
                });
            }

            html += `
                <div class="saved-cust-card" data-cust-id="${custId}">
                    <div class="saved-cust-row-main">
                        <div class="saved-cust-avatar ${isVip ? 'avatar-vip' : ''}">${initials}</div>
                        <div class="saved-cust-info">
                            <div class="saved-cust-name-row">
                                <span class="saved-cust-name" title="${name}">${name}</span>
                                ${tierHtml}
                                ${pointsHtml}
                            </div>
                            <div class="saved-cust-meta-row">
                                ${phone ? `<span class="saved-cust-phone"><i class="fa-solid fa-phone"></i> ${phone}</span>` : ''}
                                ${visitsHtml}
                            </div>
                        </div>
                        <div class="saved-cust-vehicles">
                            ${chipsHtml}
                        </div>
                    </div>
                    ${(isExpanded && detailHtml) ? `
                        <div class="cust-vehicles-detail">
                            ${detailHtml}
                        </div>
                    ` : ''}
                </div>
            `;
        });

        itemsBox.insertAdjacentHTML('beforeend', html);
        this.savedCustRenderedCount += nextBatch.length;

        if (loadMoreIndicator) {
            loadMoreIndicator.style.display = (this.savedCustRenderedCount < total) ? 'block' : 'none';
        }
    }

    static async handleSaveNewCustomer() {
        const plate = document.getElementById('newCustPlate').value.trim().toUpperCase();
        const name = document.getElementById('newCustName').value.trim() || `Customer (${plate})`;
        const phone = document.getElementById('newCustPhone').value.trim();
        const vtype = document.getElementById('newCustVehicleType').value;
        const model = document.getElementById('newCustModel').value.trim();
        const color = document.getElementById('newCustColor').value.trim();

        if (!plate) {
            alert('Please enter a vehicle plate number');
            return;
        }

        const msgConsentVal = document.querySelector('input[name="newCustMsgPermission"]:checked')?.value || 'yes';
        const msgPermission = msgConsentVal === 'yes' ? 1 : 0;

        const newCustData = {
            customer_name: name,
            customer_phone: phone,
            plate_number: plate,
            vehicle_type: vtype,
            make_model: model,
            color: color,
            message_permission: msgPermission
        };

        try {
            const res = await PosApi.addCustomerVehicle(newCustData);
            this.selectCustomer(plate, name, phone, vtype, res.customer_id, res.vehicle_id);
            // Refresh customers cache
            PosApi.syncCustomers().catch(() => {});
        } catch (e) {
            console.error('Save customer error:', e);
            // If offline, still select customer locally
            this.selectCustomer(plate, name, phone, vtype);
        }

        document.getElementById('modalCustomer').classList.remove('open');
    }

    // ========================================================
    // CHECKOUT & PAYMENTS
    // ========================================================
    static showSaleBlockedNotice(message, isDayClosed = false) {
        if (isDayClosed || (message && String(message).toLowerCase().includes('closed'))) {
            this.isDayClosed = true;
        }

        // Close payment modals so user remains on register screen with cart intact
        document.getElementById('modalPayment')?.classList.remove('open');
        document.getElementById('modalPayOrder')?.classList.remove('open');

        // Re-enable action buttons
        const btnPay = document.getElementById('btnConfirmPaymentAndPrint');
        if (btnPay) {
            btnPay.disabled = false;
            btnPay.innerHTML = `<i class="fa-solid fa-print"></i> ${I18nManager.t('confirmPayment') || 'Complete & Print'}`;
        }
        const btnLater = document.getElementById('btnPayLater');
        if (btnLater) {
            btnLater.disabled = false;
        }
        const btnCheckout = document.getElementById('btnOpenCheckout');
        if (btnCheckout) {
            btnCheckout.disabled = false;
        }

        // Display clear red warning banner at top of POS catalog
        const alertBox = document.getElementById('posNoticeAlert');
        const alertContent = document.getElementById('posNoticeAlertContent');
        if (alertBox && alertContent) {
            alertBox.className = 'alert alert-danger';
            alertBox.style.display = 'flex';
            const icon = this.isDayClosed ? 'fa-lock' : 'fa-ban';
            const title = this.isDayClosed ? (I18nManager.t('dayAlreadyClosed') || 'Day Closed') : (I18nManager.t('actionBlocked') || 'Action Not Allowed');
            alertContent.innerHTML = `
                <i class="fa-solid ${icon}" style="font-size: 1.25rem; color: #dc2626; flex-shrink: 0;"></i>
                <div>
                    <strong>${this.escapeHtml(title)}:</strong> ${this.escapeHtml(message)}
                </div>
            `;
            alertBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        alert(`⛔ ${message}`);
    }

    static openPaymentModal() {
        if (this.cart.length === 0) return;

        if (this.isDayClosed || this.isDayLocked) {
            this.showSaleBlockedNotice(I18nManager.t('salesBlockedDayClosed') || 'Register is closed for today! New sales are blocked until reopened by a manager.', true);
            return;
        }

        if (this.currentUser?.role === 'cashier' && !this.activeShift) {
            this.showStartShiftModal();
            return;
        }

        // Auto assign walk-in if no customer selected
        if (!this.currentCustomer) {
            this.setCustomerToWalkIn();
        }

        const totals = this.calculateTotals();
        document.getElementById('payModalTotalUsd').textContent = `$${totals.totalUsd.toFixed(2)}`;
        document.getElementById('payModalTotalKhr').textContent = `${totals.totalKhr.toLocaleString()} ៛`;

        // Reset slip upload
        this.currentBankSlipBase64 = null;
        const slipFileInp = document.getElementById('bankSlipFileInput');
        if (slipFileInp) slipFileInp.value = '';
        const slipPreviewImg = document.getElementById('bankSlipPreviewImg');
        if (slipPreviewImg) slipPreviewImg.src = '';
        const slipPreviewContainer = document.getElementById('bankSlipPreviewContainer');
        if (slipPreviewContainer) slipPreviewContainer.style.display = 'none';
        const triggerBtn = document.getElementById('btnTriggerSlipCapture');
        if (triggerBtn) triggerBtn.style.display = 'inline-flex';

        // Check if bank account is configured in Back Office Settings (Requirement 1)
        const settings = this.getSettings();
        const hasBank = !!((settings.aba_account_num && settings.aba_account_num.trim()) || (settings.aba_account_name && settings.aba_account_name.trim()));
        const tabAba = document.getElementById('payTabAba');
        const tabKhqr = document.getElementById('payTabKhqr');
        const noBankNotice = document.getElementById('noBankNotice');

        if (tabAba) tabAba.style.display = hasBank ? '' : 'none';
        if (tabKhqr) tabKhqr.style.display = hasBank ? '' : 'none';
        if (noBankNotice) noBankNotice.style.display = hasBank ? 'none' : 'flex';

        // Reset payment method to cash
        this.switchPaymentMethod('cash');
        document.getElementById('cashTenderInput').value = totals.totalUsd.toFixed(2);
        this.calculateChange();

        document.getElementById('modalPayment').classList.add('open');
    }

    static switchPaymentMethod(method) {
        const settings = this.getSettings();
        const hasBank = !!((settings.aba_account_num && settings.aba_account_num.trim()) || (settings.aba_account_name && settings.aba_account_name.trim()));

        // If no bank account is configured, ABA and KHQR are not available (Requirement 1)
        if (!hasBank && (method === 'aba' || method === 'khqr')) {
            method = 'cash';
        }

        this.paymentMethod = method;

        // Update active tab buttons
        document.querySelectorAll('.payment-tabs .pay-tab').forEach(b => {
            b.classList.toggle('active', b.getAttribute('data-method') === method);
        });

        const paneCash = document.getElementById('payPaneCash');
        const paneAba = document.getElementById('payPaneAba');
        const paneKhqr = document.getElementById('payPaneKhqr');
        const slipContainer = document.getElementById('bankSlipUploadContainer');

        if (paneCash) paneCash.style.display = (method === 'cash') ? 'flex' : 'none';
        if (paneAba) paneAba.style.display = (method === 'aba') ? 'flex' : 'none';
        if (paneKhqr) paneKhqr.style.display = (method === 'khqr') ? 'flex' : 'none';
        if (slipContainer) slipContainer.style.display = (method === 'aba' || method === 'khqr') ? 'block' : 'none';

        if (method === 'aba') {
            const numEl = document.getElementById('abaAccNum');
            const nameEl = document.getElementById('abaAccName');
            if (numEl) numEl.textContent = settings.aba_account_num || '';
            if (nameEl) nameEl.textContent = settings.aba_account_name || '';
        } else if (method === 'khqr') {
            const khqrImgEl = document.getElementById('khqrImageElement');
            const khqrIconEl = document.getElementById('khqrDefaultIcon');
            const khqrSrc = PosStorage.getKhqrDataUri() || this.resolveAssetUrl(settings.khqr_url || settings.khqr_image);
            if (khqrSrc && khqrImgEl) {
                khqrImgEl.src = khqrSrc;
                khqrImgEl.style.display = 'block';
                if (khqrIconEl) khqrIconEl.style.display = 'none';
            } else {
                if (khqrImgEl) khqrImgEl.style.display = 'none';
                if (khqrIconEl) khqrIconEl.style.display = 'block';
            }
        }
    }

    static setCashTender(amountUsd) {
        document.getElementById('cashTenderInput').value = amountUsd.toFixed(2);
        this.calculateChange();
    }

    static setCashTenderKhr(amountKhr) {
        const totals = this.calculateTotals();
        const usdVal = (amountKhr / totals.exchangeRate);
        document.getElementById('cashTenderInput').value = usdVal.toFixed(2);
        this.calculateChange();
    }

    static calculateChange() {
        const totals = this.calculateTotals();
        const tendered = parseFloat(document.getElementById('cashTenderInput').value) || 0;
        const changeUsd = Math.max(0, tendered - totals.totalUsd);
        const changeKhr = Math.round(changeUsd * totals.exchangeRate);

        document.getElementById('payChangeUsd').textContent = `$${changeUsd.toFixed(2)}`;
        document.getElementById('payChangeKhr').textContent = `${changeKhr.toLocaleString()} ៛`;
    }

    static populateCrewDropdowns() {
        const catalog = PosStorage.getCatalog();
        if (!catalog) return;

        const wSelect = document.getElementById('selectDutyWasher');
        const mSelect = document.getElementById('selectDutyMechanic');
        const cartWSelect = document.getElementById('cartWasherSelect');
        const cartMSelect = document.getElementById('cartMechanicSelect');

        const washers = catalog.washers || [];
        let wHtml = '<option value="">-- All Active Washers --</option>';
        (catalog.washers || []).forEach(w => {
            wHtml += `<option value="${w.id}">${this.escapeHtml(w.full_name)}</option>`;
        });

        let mHtml = '<option value="">-- Duty Mechanic --</option>';
        (catalog.mechanics || []).forEach(m => {
            mHtml += `<option value="${m.id}">${this.escapeHtml(m.full_name)}</option>`;
        });

        if (wSelect) wSelect.innerHTML = wHtml;
        if (mSelect) mSelect.innerHTML = mHtml;
        if (cartWSelect) cartWSelect.innerHTML = wHtml;
        if (cartMSelect) cartMSelect.innerHTML = mHtml;
    }

    /**
     * Submit Order (Online or Offline with Idempotency)
     */
    static async handleConfirmPayment() {
        const btn = document.getElementById('btnConfirmPaymentAndPrint');
        btn.disabled = true;
        btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Processing...`;

        // Requirement 20: Block sales after day closing unless reopened by manager
        if (this.isDayClosed || this.isDayLocked) {
            this.lastPaymentError = 'Day Closed or Locked';
            btn.disabled = false;
            btn.innerHTML = `<i class="fa-solid fa-circle-check"></i> Complete & Print Receipt`;
            alert(I18nManager.t('salesBlockedDayClosed') || 'Register is closed for today! New sales are blocked until reopened by a manager.');
            return;
        }

        if (this.currentUser?.role === 'cashier' && !this.activeShift) {
            this.lastPaymentError = 'No Active Cashier Shift';
            btn.disabled = false;
            btn.innerHTML = `<i class="fa-solid fa-circle-check"></i> Complete & Print Receipt`;
            this.showStartShiftModal();
            return;
        }

        // Requirement 4: Slip Photo required for ABA and KHQR
        if ((this.paymentMethod === 'aba' || this.paymentMethod === 'khqr') && !this.currentBankSlipBase64) {
            this.lastPaymentError = 'Slip Photo Required for ABA/KHQR';
            btn.disabled = false;
            btn.innerHTML = `<i class="fa-solid fa-print"></i> ${I18nManager.t('confirmPayment') || 'Confirm Payment'}`;
            alert('A photo of the bank transfer slip is required for ABA and KHQR payments. Please take or attach a photo of the customer slip.');
            return;
        }

        const totals = this.calculateTotals();
        const customer = this.currentCustomer || {
            plate: 'WALK-IN',
            name: 'Walk-in Guest',
            phone: '',
            vehicle_type: this.selectedVehicleType
        };

        // Requirement 2: Clean app window for vehicle plate & type prompt
        let plate = (customer.plate || '').trim();
        if (!plate || plate.toUpperCase() === 'WALK-IN') {
            const plateRes = await this.promptVehiclePlate(customer.vehicle_type || this.selectedVehicleType || 'small_car');
            if (!plateRes || !plateRes.plate) {
                btn.disabled = false;
                btn.innerHTML = `<i class="fa-solid fa-circle-check"></i> Complete & Print Receipt`;
                return;
            }
            plate = plateRes.plate;
            customer.plate = plate;
            if (plateRes.vehicleType) {
                customer.vehicle_type = plateRes.vehicleType;
                this.selectedVehicleType = plateRes.vehicleType;
                this.updateVehicleTypeUI(plateRes.vehicleType);
            }
            if (this.currentCustomer) {
                this.currentCustomer.plate = plate;
                this.currentCustomer.vehicle_type = customer.vehicle_type;
            }
            const pInput = document.getElementById('searchCustomerInput');
            if (pInput) pInput.value = plate;
        }

        const mileage = parseInt(document.getElementById('cartMileageInput')?.value) || null;
        const nextOilMileage = parseInt(document.getElementById('cartNextOilMileageInput')?.value) || null;
        const nextOilDate = document.getElementById('cartNextOilDateInput')?.value || null;

        // Requirement 4: Empty cash must not count as paid in full; block payment when cash is less than total
        let tendered = totals.totalUsd;
        let change = 0;
        if (this.paymentMethod === 'cash') {
            const rawTendered = document.getElementById('cashTenderInput').value.trim();
            if (rawTendered === '' || isNaN(parseFloat(rawTendered))) {
                btn.disabled = false;
                btn.innerHTML = `<i class="fa-solid fa-circle-check"></i> Complete & Print Receipt`;
                alert(I18nManager.t('cashTenderEmpty') || 'Please enter cash amount tendered');
                document.getElementById('cashTenderInput').focus();
                return;
            }
            tendered = parseFloat(rawTendered);
            if (tendered < (totals.totalUsd - 0.009)) {
                btn.disabled = false;
                btn.innerHTML = `<i class="fa-solid fa-circle-check"></i> Complete & Print Receipt`;
                alert(I18nManager.t('cashTenderInsufficient') || 'Cash tendered is less than the total order amount');
                document.getElementById('cashTenderInput').focus();
                return;
            }
            change = Math.max(0, tendered - totals.totalUsd);
        }
        const paymentRef = (this.paymentMethod === 'aba') ? document.getElementById('abaRefInput').value.trim() : '';

        // Requirement 1 & 3: Washers, Mechanics, and Repair Notes
        const selectedWasher = document.getElementById('selectDutyWasher')?.value || document.getElementById('cartWasherSelect')?.value;
        const selectedMechanic = document.getElementById('selectDutyMechanic')?.value || document.getElementById('cartMechanicSelect')?.value;
        const repairNotes = document.getElementById('cartRepairNotesInput')?.value.trim() || '';

        // Unique Idempotency Key
        const idempotencyKey = this.generateIdempotencyKey();

        const orderPayload = {
            idempotency_key: idempotencyKey,
            plate_number: customer.plate,
            customer_name: customer.name,
            customer_phone: customer.phone,
            vehicle_type: customer.vehicle_type || this.selectedVehicleType,
            customer_id: customer.customer_id,
            vehicle_id: customer.vehicle_id,
            items: this.cart.map(it => ({
                id: it.service_id || it.product_id,
                service_id: it.service_id,
                product_id: it.product_id,
                type: it.type,
                name: it.name_en,
                price_usd: it.price_usd,
                qty: it.qty,
                is_member_benefit: it.is_member_benefit || false,
                is_upsell: it.is_upsell || false,
                offer_price_usd: it.offer_price_usd !== undefined ? it.offer_price_usd : null
            })),
            loyalty_reward_given: this.loyaltyRewardGiven,
            loyalty_reward_name: this.activeLoyaltyReward ? this.activeLoyaltyReward.reward_name : null,
            discount_usd: totals.discountUsd,
            is_paid: 1,
            payment_method: this.paymentMethod,
            amount_tendered_usd: tendered,
            change_usd: change,
            payment_ref: paymentRef,
            current_mileage: mileage,
            next_oil_mileage: nextOilMileage,
            next_oil_date: nextOilDate,
            repair_notes: repairNotes,
            staff_id: this.currentUser?.id || null,
            slip_base64: (this.currentBankSlipBase64 && (this.paymentMethod === 'aba' || this.paymentMethod === 'khqr')) ? this.currentBankSlipBase64 : null,
            manager_username: (this.cart.find(it => it.manager_override)?.manager_username) || null,
            manager_pin: (this.cart.find(it => it.manager_override)?.manager_pin) || null,
            washers: selectedWasher ? [parseInt(selectedWasher)] : [],
            mechanics: selectedMechanic ? [parseInt(selectedMechanic)] : []
        };

        let completedOrderRecord = null;

        try {
            // Attempt back office order submission
            const res = await PosApi.createOrder(orderPayload, idempotencyKey);
            if (res.success) {
                const finalTotalUsd = (res.order?.total_usd !== undefined) ? parseFloat(res.order.total_usd) : totals.totalUsd;
                const finalTotalKhr = (res.order?.total_khr !== undefined) ? parseInt(res.order.total_khr) : totals.totalKhr;

                completedOrderRecord = {
                    ...orderPayload,
                    id: res.order?.id || res.order_id,
                    order_number: res.order?.order_number || `ORD-${Date.now()}`,
                    status: res.order?.status || 'in_progress',
                    payment_status: res.order?.payment_status || (this.paymentMethod === 'cash' ? 'paid' : 'pending_verification'),
                    total_usd: finalTotalUsd,
                    total_khr: finalTotalKhr,
                    created_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
                    cashier_name: this.currentUser?.full_name || 'Cashier',
                    synced: true
                };
                PosStorage.addSyncedOrder(completedOrderRecord);
            } else {
                throw new Error(res.message || 'Back office order failure');
            }
        } catch (err) {
            if (err.isOffline) {
                console.warn('Network offline, queuing order locally:', err);
                completedOrderRecord = {
                    ...orderPayload,
                    order_number: `ORD-OFFLINE-${Date.now().toString().slice(-6)}`,
                    total_usd: totals.totalUsd,
                    total_khr: totals.totalKhr,
                    created_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
                    created_by_staff_id: this.currentUser?.id || null,
                    is_offline_sync: true,
                    cashier_name: this.currentUser?.full_name || 'Cashier',
                    synced: false
                };

                PosStorage.addPendingOrder(completedOrderRecord);
                PosStorage.addSyncedOrder(completedOrderRecord);
                this.updatePendingSyncBadge();

                alert('Network offline: Order saved safely on device and queued for automatic sync!');
            } else {
                // Server rejected the order (e.g. day closed, action not allowed, permission denied, out of stock)
                btn.disabled = false;
                btn.innerHTML = `<i class="fa-solid fa-print"></i> ${I18nManager.t('confirmPayment')}`;
                const errMsg = err.data?.message || err.message || 'Order could not be processed';
                this.lastPaymentError = errMsg;
                const isClosed = err.code === 'DAY_CLOSED' || err.data?.error === 'DAY_CLOSED' || errMsg.toLowerCase().includes('closed');
                this.showSaleBlockedNotice(errMsg, isClosed);
                return;
            }
        } finally {
            btn.disabled = false;
            btn.innerHTML = `<i class="fa-solid fa-print"></i> ${I18nManager.t('confirmPayment')}`;
        }

        // Close Payment Modal & Clear Cart
        document.getElementById('modalPayment').classList.remove('open');
        this.lastCompletedOrder = completedOrderRecord;
        this.clearCart();
        this.resetCustomer();
        if (document.getElementById('cartMileageInput')) document.getElementById('cartMileageInput').value = '';
        if (document.getElementById('cartNextOilMileageInput')) document.getElementById('cartNextOilMileageInput').value = '';
        if (document.getElementById('cartNextOilDateInput')) document.getElementById('cartNextOilDateInput').value = '';
        if (document.getElementById('cartRepairNotesInput')) document.getElementById('cartRepairNotesInput').value = '';
        if (document.getElementById('cartOilReminderBox')) document.getElementById('cartOilReminderBox').style.display = 'none';

        // Open 80mm Receipt Preview
        this.currentReceiptOrder = completedOrderRecord;
        this.renderReceipt(completedOrderRecord);
        document.getElementById('modalReceipt').classList.add('open');

        // Acknowledge electronic receipt receipt to backend
        if (completedOrderRecord && completedOrderRecord.payment_status === 'paid' && completedOrderRecord.synced) {
            this.sendReceiptAcknowledgement(completedOrderRecord.id, completedOrderRecord.order_number);
        }

        // Direct Silent Thermal Printing (Requirement 1, 2, 7)
        const cfg = PosConfig.get();
        if (cfg.printerDirectEnabled) {
            this.executeSilentPrint(completedOrderRecord);
        }
    }

    /**
     * Submit Order as Unpaid (Pay Later - Requirement 4 & New Checklist)
     */
    static isSubmittingPayLater = false;
    static lastCreatedOrderRecord = null;

    static async handlePayLaterOrder() {
        if (this.isSubmittingPayLater) return; // Prevent duplicate click
        if (this.cart.length === 0) return;

        const btn = document.getElementById('btnPayLater');
        const alertBox = document.getElementById('posNoticeAlert');
        const alertContent = document.getElementById('posNoticeAlertContent');
        if (alertBox) alertBox.style.display = 'none';

        // Requirement 20: Block sales after day closing unless reopened by manager
        if (this.isDayClosed) {
            this.showSaleBlockedNotice(I18nManager.t('salesBlockedDayClosed') || 'Register is closed for today! New sales are blocked until reopened by a manager.', true);
            return;
        }

        const customer = this.currentCustomer || {
            plate: 'WALK-IN',
            name: 'Walk-in Guest',
            phone: '',
            vehicle_type: this.selectedVehicleType
        };

        // Requirement 2: Clean app window for vehicle plate & type prompt (Pay Later continues ONLY after plate is entered)
        let plate = (customer.plate || '').trim();
        if (!plate || plate.toUpperCase() === 'WALK-IN') {
            const plateRes = await this.promptVehiclePlate(customer.vehicle_type || this.selectedVehicleType || 'small_car');
            if (!plateRes || !plateRes.plate) {
                // User cancelled or provided no plate: Pay Later does NOT proceed
                return;
            }
            plate = plateRes.plate;
            customer.plate = plate;
            if (plateRes.vehicleType) {
                customer.vehicle_type = plateRes.vehicleType;
                this.selectedVehicleType = plateRes.vehicleType;
                this.updateVehicleTypeUI(plateRes.vehicleType);
            }
            if (this.currentCustomer) {
                this.currentCustomer.plate = plate;
                this.currentCustomer.vehicle_type = customer.vehicle_type;
            }
            const pInput = document.getElementById('searchCustomerInput');
            if (pInput) pInput.value = plate;
        }

        this.isSubmittingPayLater = true;
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Saving...`;
        }

        const totals = this.calculateTotals();

        const mileageVal = document.getElementById('cartMileageInput')?.value?.trim();
        const mileage = (mileageVal && !isNaN(parseInt(mileageVal))) ? parseInt(mileageVal) : null;
        const nextOilMileage = parseInt(document.getElementById('cartNextOilMileageInput')?.value) || null;
        const nextOilDate = document.getElementById('cartNextOilDateInput')?.value || null;
        const selectedWasher = document.getElementById('cartWasherSelect')?.value || document.getElementById('selectDutyWasher')?.value;
        const selectedMechanic = document.getElementById('cartMechanicSelect')?.value || document.getElementById('selectDutyMechanic')?.value;
        const repairNotes = document.getElementById('cartRepairNotesInput')?.value?.trim() || '';

        const idempotencyKey = this.generateIdempotencyKey();

        const orderPayload = {
            idempotency_key: idempotencyKey,
            plate_number: customer.plate,
            customer_name: customer.name,
            customer_phone: customer.phone,
            vehicle_type: customer.vehicle_type || this.selectedVehicleType,
            customer_id: customer.customer_id,
            vehicle_id: customer.vehicle_id,
            items: this.cart.map(it => ({
                id: it.service_id || it.product_id,
                service_id: it.service_id,
                product_id: it.product_id,
                type: it.type,
                name: it.name_en,
                price_usd: it.price_usd,
                qty: it.qty,
                is_member_benefit: it.is_member_benefit || false,
                is_upsell: it.is_upsell || false,
                offer_price_usd: it.offer_price_usd !== undefined ? it.offer_price_usd : null
            })),
            loyalty_reward_given: this.loyaltyRewardGiven,
            loyalty_reward_name: this.activeLoyaltyReward ? this.activeLoyaltyReward.reward_name : null,
            discount_usd: totals.discountUsd,
            is_paid: 0,
            status: 'waiting',
            payment_status: 'unpaid',
            payment_method: 'unpaid',
            current_mileage: mileage,
            next_oil_mileage: nextOilMileage,
            next_oil_date: nextOilDate,
            repair_notes: repairNotes,
            staff_id: this.currentUser?.id || null,
            washers: (selectedWasher && parseInt(selectedWasher) > 0) ? [parseInt(selectedWasher)] : [],
            mechanics: (selectedMechanic && parseInt(selectedMechanic) > 0) ? [parseInt(selectedMechanic)] : []
        };

        let savedOrderRecord = null;

        try {
            const res = await PosApi.createOrder(orderPayload, idempotencyKey);
            if (res.success && res.order) {
                const finalTotalUsd = (res.order.total_usd !== undefined) ? parseFloat(res.order.total_usd) : totals.totalUsd;
                const finalTotalKhr = (res.order.total_khr !== undefined) ? parseInt(res.order.total_khr) : totals.totalKhr;

                savedOrderRecord = {
                    ...orderPayload,
                    order_number: res.order.order_number,
                    total_usd: finalTotalUsd,
                    total_khr: finalTotalKhr,
                    created_at: res.order.created_at || new Date().toISOString().replace('T', ' ').substring(0, 19),
                    cashier_name: this.currentUser?.full_name || 'Cashier',
                    synced: true
                };
                PosStorage.addSyncedOrder(savedOrderRecord);
                this.lastCreatedOrderRecord = savedOrderRecord;

                // Clear current order
                this.clearCart();
                this.resetCustomer();
                if (document.getElementById('cartMileageInput')) document.getElementById('cartMileageInput').value = '';
                if (document.getElementById('cartNextOilMileageInput')) document.getElementById('cartNextOilMileageInput').value = '';
                if (document.getElementById('cartNextOilDateInput')) document.getElementById('cartNextOilDateInput').value = '';
                if (document.getElementById('cartRepairNotesInput')) document.getElementById('cartRepairNotesInput').value = '';
                if (document.getElementById('cartOilReminderBox')) document.getElementById('cartOilReminderBox').style.display = 'none';

                // Show clear green order success modal offering to print ticket
                this.showOrderSuccessModal(savedOrderRecord, false);
            } else {
                throw new Error(res.message || res.error || 'Server rejected order creation');
            }
        } catch (err) {
            if (err.isOffline) {
                // Offline fallback: save order on device and queue
                const offlineNum = `ORD-OFFLINE-${Date.now().toString().slice(-6)}`;
                savedOrderRecord = {
                    ...orderPayload,
                    order_number: offlineNum,
                    total_usd: totals.totalUsd,
                    total_khr: totals.totalKhr,
                    failure_reason: err.originalMessage || err.message || 'Connection offline: Server unreachable',
                    created_at: new Date().toISOString().replace('T', ' ').substring(0, 19),
                    created_by_staff_id: this.currentUser?.id || null,
                    is_offline_sync: true,
                    cashier_name: this.currentUser?.full_name || 'Cashier',
                    synced: false
                };

                PosStorage.addPendingOrder(savedOrderRecord);
                PosStorage.addSyncedOrder(savedOrderRecord);
                this.updatePendingSyncBadge();
                this.lastCreatedOrderRecord = savedOrderRecord;

                // Clear cart
                this.clearCart();
                this.resetCustomer();
                if (document.getElementById('cartMileageInput')) document.getElementById('cartMileageInput').value = '';
                if (document.getElementById('cartNextOilMileageInput')) document.getElementById('cartNextOilMileageInput').value = '';
                if (document.getElementById('cartNextOilDateInput')) document.getElementById('cartNextOilDateInput').value = '';
                if (document.getElementById('cartRepairNotesInput')) document.getElementById('cartRepairNotesInput').value = '';
                if (document.getElementById('cartOilReminderBox')) document.getElementById('cartOilReminderBox').style.display = 'none';

                // Show clear green success modal with offline note
                this.showOrderSuccessModal(savedOrderRecord, true);
            } else {
                // Real server error: display clear red message on screen and block sale
                const realReason = err.data?.message || err.message || 'An unknown error occurred while saving the order';
                console.error('Pay Later rejected by server:', err);
                const isClosed = err.code === 'DAY_CLOSED' || err.data?.error === 'DAY_CLOSED' || realReason.toLowerCase().includes('closed');
                this.showSaleBlockedNotice(realReason, isClosed);
                return;
            }
        } finally {
            this.isSubmittingPayLater = false;
            if (btn) {
                btn.disabled = (this.cart.length === 0);
                btn.innerHTML = `<i class="fa-solid fa-clock"></i> <span data-i18n="payLater">${I18nManager.t('payLater')}</span>`;
            }
        }
    }

    static showOrderSuccessModal(order, isOffline = false) {
        const modal = document.getElementById('modalOrderSuccess');
        if (!modal) {
            this.showAppAlert(`Order #${order.order_number} saved as Unpaid to Queue!`);
            return;
        }

        const numEl = document.getElementById('orderSuccessOrderNum');
        const plateEl = document.getElementById('orderSuccessPlate');
        const titleEl = document.getElementById('orderSuccessTitle');
        const msgEl = document.getElementById('orderSuccessMessage');

        if (numEl) numEl.textContent = order.order_number;
        if (plateEl) plateEl.textContent = order.plate_number;

        const isKm = (I18nManager.getLang() === 'km');
        if (titleEl) {
            titleEl.textContent = isKm ? 'រក្សាទុកជោគជ័យ' : 'Order Saved Successfully';
        }

        if (msgEl) {
            if (isOffline) {
                msgEl.innerHTML = isKm
                    ? `ការបញ្ជាទិញត្រូវបានរក្សាទុក <strong>លើឧបករណ៍ (Offline)</strong> ដោយជោគជ័យ ស្ថានភាព <strong>កំពុងរង់ចាំ</strong> និងរង់ចាំការបញ្ជូន។`
                    : `Order saved <strong>safely on device (Offline)</strong> with status <strong>Waiting</strong> and queued for sync.`;
            } else {
                msgEl.innerHTML = isKm
                    ? `ការបញ្ជាទិញត្រូវបានរក្សាទុកជា <strong>មិនទាន់បង់ប្រាក់</strong> ស្ថានភាព <strong>កំពុងរង់ចាំ</strong> និងបានបញ្ចូលក្នុងបញ្ជីការងារ។`
                    : `Order saved as <strong>Unpaid</strong> with status <strong>Waiting</strong> and added to the Job Queue.`;
            }
        }

        I18nManager.applyToDOM();
        modal.classList.add('open');
    }

    // ========================================================
    // 80MM CLEAN THERMAL RECEIPT & PRINT OUTPUTS
    // ========================================================
    static isPrintingDailyReport = false;
    static isPrintingWorkshopTicket = false;

    static renderReceipt(order, options = {}) {
        if (!order) {
            alert(I18nManager.t('noOrderToPrint') || 'No order selected to print');
            return;
        }

        this.isPrintingDailyReport = false;
        this.isPrintingWorkshopTicket = false;
        this.currentReceiptOrder = order;

        const isReprint = !!options.isReprint;
        const isCancelled = order.status === 'cancelled';
        const isUnpaid = (order.payment_status === 'unpaid' || order.status === 'unpaid') && !isCancelled;
        const settings = this.getSettings();
        const currentLang = I18nManager.getLang();
        const rate = this.getExchangeRate();

        // Update modal title and button visibility
        const modalTitle = document.querySelector('#modalReceipt h3');
        if (modalTitle) modalTitle.textContent = I18nManager.t('printReceipt') || 'វិក្កយបត្រ';
        const btnWasher = document.getElementById('btnPrintWasherReceipt');
        if (btnWasher) btnWasher.style.display = 'inline-flex';

        // 1. Shop Logo in Black & White, Small & Centered (Requirement 2)
        const showLogo = (settings.receipt_show_logo !== 0 && settings.receipt_show_logo !== '0' && settings.receipt_show_logo !== false);
        const logoSrc = PosStorage.getLogoDataUri() || this.resolveAssetUrl(settings.shop_logo_url || settings.shop_logo);
        const logoHtml = (showLogo && logoSrc) ? `
            <div style="text-align: center; margin-bottom: 6px;">
                <img src="${logoSrc}" alt="Shop Logo" class="receipt-logo"
                    style="max-height: 44px; max-width: 130px; object-fit: contain; margin: 0 auto; display: block; filter: grayscale(100%) contrast(140%); -webkit-filter: grayscale(100%) contrast(140%);">
            </div>
        ` : '';

        // 2. Shop Name (Respects Language Selection)
        const rcptLangSetting = settings.receipt_language || 'both';
        let shopNameHtml = '';
        if (currentLang === 'km' && rcptLangSetting !== 'en') {
            shopNameHtml = `<div class="receipt-shop-name">${this.escapeHtml(settings.shop_name_km || 'សេវាកម្មលាងរថយន្ត និងម៉ូតូទំនើប')}</div>`;
        } else if (currentLang === 'en' && rcptLangSetting !== 'km') {
            shopNameHtml = `<div class="receipt-shop-name">${this.escapeHtml(settings.shop_name_en || 'Car Wash & Detailing')}</div>`;
        } else {
            shopNameHtml = `
                <div class="receipt-shop-name">${this.escapeHtml(settings.shop_name_km || 'សេវាកម្មលាងរថយន្ត និងម៉ូតូទំនើប')}</div>
                <div style="font-size: 13px; font-weight: bold; margin-top: 2px;">${this.escapeHtml(settings.shop_name_en || 'Car Wash & Detailing')}</div>
            `;
        }

        const shopAddressHtml = settings.shop_address ? `<div style="font-size: 10px; margin-top: 2px;">${this.escapeHtml(settings.shop_address)}</div>` : '';
        const shopPhoneHtml = settings.shop_phone ? `<div style="font-size: 10px;">Tel: ${this.escapeHtml(settings.shop_phone)}</div>` : '';

        // 3. Status Banners (Reprint, Cancelled, Unpaid)
        let bannersHtml = '';
        if (isReprint) {
            bannersHtml += `
                <div class="receipt-badge-banner reprint">
                    ${I18nManager.t('copyReprint')}
                    <div style="font-size: 9px; font-weight: normal; margin-top: 2px;">
                        ${I18nManager.t('reprintedAt')} ${new Date().toLocaleString()}
                    </div>
                </div>
            `;
        }
        if (isCancelled) {
            bannersHtml += `<div class="receipt-badge-banner cancelled">${I18nManager.t('orderCancelledBadge')}</div>`;
        } else if (isUnpaid) {
            bannersHtml += `<div class="receipt-badge-banner unpaid">${I18nManager.t('orderUnpaidBadge')}</div>`;
        }

        // 4. Order Information
        const metaHtml = `
            <div style="font-size: 11px; line-height: 1.45;">
                <div style="display: flex; justify-content: space-between;">
                    <span>${I18nManager.t('invoiceNo')}</span>
                    <strong style="font-family: monospace;">${this.escapeHtml(order.order_number || 'ORD-000')}</strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span>${I18nManager.t('dateTimeLabel')}</span>
                    <span>${this.escapeHtml(order.created_at || order.paid_at || new Date().toLocaleString())}</span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span>${I18nManager.t('cashierLabel')}</span>
                    <span>${this.escapeHtml(order.cashier_name || this.currentUser?.full_name || 'Cashier')}</span>
                </div>
                <div style="display: flex; justify-content: space-between; border-top: 1px dashed #000; padding-top: 3px; margin-top: 3px;">
                    <span>${I18nManager.t('plateNoLabel')}</span>
                    <strong style="font-size: 13px;">${this.escapeHtml(order.plate_number || 'WALK-IN')}</strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span>${I18nManager.t('customerLabel')}</span>
                    <span>${this.formatCustomerName(order.customer_name || 'Walk-in Guest')}</span>
                </div>
            </div>
        `;

        // 5. Items Table (Word-wrap, Right aligned price, Tabular font)
        const items = order.items || [];
        const itemsHtml = items.map(it => {
            const lineTotal = ((it.price_usd || 0) * (it.qty || 1)).toFixed(2);
            const itemName = (currentLang === 'km' && it.name_km) ? it.name_km : (it.name || it.name_en || 'Item');
            return `
                <tr>
                    <td class="text-left" style="word-break: break-word; overflow-wrap: break-word; padding-right: 4px;">
                        ${this.escapeHtml(itemName)}
                    </td>
                    <td class="text-center" style="width: 28px; white-space: nowrap;">${it.qty || 1}</td>
                    <td class="text-right" style="width: 58px; white-space: nowrap; font-family: monospace;">$${lineTotal}</td>
                </tr>
            `;
        }).join('');

        // 6. Totals & Payment Calculations
        const subtotal = items.reduce((sum, it) => sum + ((it.price_usd || 0) * (it.qty || 1)), 0);
        const discountUsd = parseFloat(order.discount_usd || 0);
        const totalUsd = parseFloat(order.total_usd !== undefined ? order.total_usd : (subtotal - discountUsd));
        const totalKhr = parseInt(order.total_khr || Math.round(totalUsd * rate));

        let discHtml = '';
        if (discountUsd > 0) {
            discHtml = `
                <div style="display: flex; justify-content: space-between;">
                    <span>${I18nManager.t('discountLabel')}</span>
                    <span style="font-family: monospace;">-$${discountUsd.toFixed(2)}</span>
                </div>
            `;
        }

        let methodStr = (order.payment_method || 'cash').toUpperCase();
        if (methodStr === 'CASH') methodStr = I18nManager.t('cash') || 'CASH';
        else if (methodStr === 'ABA') methodStr = 'ABA PAY';
        else if (methodStr === 'KHQR') methodStr = 'KHQR SCAN';

        if (isUnpaid) {
            methodStr = `${I18nManager.t('unpaid') || 'UNPAID'} (PAY LATER)`;
        }

        let paymentDetailHtml = '';
        if (!isUnpaid && (order.payment_method || 'cash').toLowerCase() === 'cash') {
            const tendered = parseFloat(order.amount_tendered_usd || totalUsd);
            const change = parseFloat(order.change_usd || 0);
            paymentDetailHtml = `
                <div style="display: flex; justify-content: space-between;">
                    <span>${I18nManager.t('tenderedLabel')}</span>
                    <span style="font-family: monospace;">$${tendered.toFixed(2)}</span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span>${I18nManager.t('changeLabel')}</span>
                    <span style="font-family: monospace;">$${change.toFixed(2)}</span>
                </div>
            `;
        } else if (isUnpaid) {
            paymentDetailHtml = `
                <div style="display: flex; justify-content: space-between; font-weight: bold; color: #b45309; margin-top: 2px;">
                    <span>${I18nManager.t('amountDueLabel')}</span>
                    <span style="font-family: monospace;">$${totalUsd.toFixed(2)} (${totalKhr.toLocaleString()} ៛)</span>
                </div>
            `;
        }

        // 7. Mileage & Next Oil Care Reminder
        let oilCareHtml = '';
        if (order.current_mileage && order.current_mileage > 0) {
            const nextMileageVal = order.next_oil_mileage || (order.current_mileage + (parseInt(settings.default_oil_mileage) || 5000));
            const nextDateVal = order.next_oil_date ? ` (${order.next_oil_date})` : '';
            oilCareHtml = `
                <div style="border: 1px dashed #000; padding: 4px; margin-top: 6px; font-size: 10px; text-align: center;">
                    <div style="font-weight: bold;">${I18nManager.t('oilCareReminderTitle')}</div>
                    <div>${I18nManager.t('currentMileageLabel')} ${order.current_mileage.toLocaleString()} km | ${I18nManager.t('nextDueMileageLabel')} ${nextMileageVal.toLocaleString()} km${nextDateVal}</div>
                </div>
            `;
        }

        // 8. Repair Notes Box
        let repairNotesHtml = '';
        if (order.repair_notes) {
            repairNotesHtml = `
                <div style="border: 1px dashed #000; padding: 4px; margin-top: 6px; font-size: 10px;">
                    <strong>${I18nManager.t('repairNotes')}:</strong> ${this.escapeHtml(order.repair_notes)}
                </div>
            `;
        }

        const footerMsg = settings.receipt_footer || 'Thank you for your visit! / សូមអរគុណ!';

        // Assemble into the 80mm printable wrapper
        const printArea = document.getElementById('receiptPrintArea');
        if (printArea) {
            printArea.innerHTML = `
                <div class="receipt-header">
                    ${logoHtml}
                    ${shopNameHtml}
                    ${shopAddressHtml}
                    ${shopPhoneHtml}
                </div>

                ${bannersHtml}
                ${metaHtml}

                <div class="receipt-divider"></div>

                <table class="receipt-table">
                    <thead>
                        <tr>
                            <th class="text-left">${I18nManager.t('itemLabel')}</th>
                            <th class="text-center" style="width: 28px;">${I18nManager.t('qtyLabel')}</th>
                            <th class="text-right" style="width: 58px;">${I18nManager.t('priceLabel')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${itemsHtml}
                    </tbody>
                </table>

                <div class="receipt-divider"></div>

                <div style="font-size: 11px; line-height: 1.45;">
                    <div style="display: flex; justify-content: space-between;">
                        <span>${I18nManager.t('subtotalLabel')}</span>
                        <span style="font-family: monospace;">$${subtotal.toFixed(2)}</span>
                    </div>
                    ${discHtml}
                    <div class="receipt-divider"></div>
                    <div style="display: flex; justify-content: space-between; font-size: 13.5px; font-weight: bold;">
                        <span>${I18nManager.t('totalUsdLabel')}</span>
                        <span style="font-family: monospace;">$${totalUsd.toFixed(2)}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-weight: bold; font-size: 11.5px;">
                        <span>${I18nManager.t('totalKhrLabel')}</span>
                        <span style="font-family: monospace;">${totalKhr.toLocaleString()} ៛</span>
                    </div>
                    <div class="receipt-divider"></div>
                    <div style="display: flex; justify-content: space-between;">
                        <span>${I18nManager.t('paymentMethodLabel')}</span>
                        <span style="text-transform: uppercase; font-weight: bold;">${methodStr}</span>
                    </div>
                    ${paymentDetailHtml}
                </div>

                ${oilCareHtml}
                ${repairNotesHtml}

                <div class="receipt-divider"></div>

                <div class="text-center" style="font-size: 10px; margin-top: 6px;">
                    <div style="white-space: pre-line; line-height: 1.4;">${this.escapeHtml(footerMsg)}</div>
                    <div style="margin-top: 4px; font-size: 9px; color: #555;">${this.escapeHtml(I18nManager.t('appSubtitle'))}</div>
                </div>
            `;
        }
    }

    static renderWorkshopTicket(order) {
        if (!order) {
            alert(I18nManager.t('noOrderToPrint') || 'No order selected');
            return;
        }

        this.isPrintingDailyReport = false;
        this.isPrintingWorkshopTicket = true;
        this.currentReceiptOrder = order;

        const currentLang = I18nManager.getLang();
        const catalog = PosStorage.getCatalog() || {};
        const washers = catalog.washers || [];
        const mechanics = catalog.mechanics || [];

        const washerNames = (order.washers || []).map(id => washers.find(w => w.id == id)?.full_name || `#${id}`).join(', ');
        const mechanicNames = (order.mechanics || []).map(id => mechanics.find(m => m.id == id)?.full_name || `#${id}`).join(', ');

        const modalTitle = document.querySelector('#modalReceipt h3');
        if (modalTitle) modalTitle.textContent = I18nManager.t('washerTicket') || 'Washer Ticket';
        const btnWasher = document.getElementById('btnPrintWasherReceipt');
        if (btnWasher) btnWasher.style.display = 'none';

        const vtypeStr = PosThermalPrinter.formatVehicleType ? PosThermalPrinter.formatVehicleType(order.vehicle_type) : order.vehicle_type;

        const items = order.items || [];
        const itemsHtml = items.map((it, idx) => {
            const name = (currentLang === 'km' && it.name_km) ? it.name_km : (it.name || it.name_en || 'Service');
            return `
                <tr>
                    <td class="text-left" style="font-weight: 700; word-break: break-word; padding: 3px 0;">
                        ${idx + 1}. ${this.escapeHtml(name)}
                    </td>
                    <td class="text-right" style="width: 45px; font-weight: bold; white-space: nowrap;">
                        x${it.qty || 1}
                    </td>
                </tr>
            `;
        }).join('');

        const printArea = document.getElementById('receiptPrintArea');
        if (printArea) {
            printArea.innerHTML = `
                <div class="receipt-header">
                    <div style="font-size: 15px; font-weight: 800; text-transform: uppercase;">
                        ${I18nManager.t('workshopTicketTitle')}
                    </div>
                    <div style="font-size: 13px; font-weight: bold; margin-top: 2px;">
                        #${this.escapeHtml(order.order_number || '')}
                    </div>
                    <div style="font-size: 10px; margin-top: 2px;">
                        ${this.escapeHtml(order.created_at || new Date().toLocaleString())}
                    </div>
                </div>

                <!-- Giant License Plate -->
                <div style="background: #000000; color: #ffffff; padding: 8px 4px; text-align: center; border-radius: 4px; margin: 6px 0;">
                    <div style="font-size: 10px; letter-spacing: 1px; text-transform: uppercase;">
                        ${I18nManager.t('plateNoLabel')}
                    </div>
                    <div style="font-size: 26px; font-weight: 900; letter-spacing: 1.5px; line-height: 1.2;">
                        ${this.escapeHtml(order.plate_number || 'WALK-IN')}
                    </div>
                </div>

                <div style="font-size: 11px; line-height: 1.45; margin-top: 6px;">
                    <div style="display: flex; justify-content: space-between;">
                        <span>${I18nManager.t('vehicleType') || 'Vehicle'}:</span>
                        <strong>${this.escapeHtml(vtypeStr)}</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span>${I18nManager.t('customerLabel')}</span>
                        <span>${this.formatCustomerName(order.customer_name || 'Walk-in')}</span>
                    </div>
                    ${washerNames ? `
                    <div style="margin-top: 3px;">
                        <strong>${I18nManager.t('assignedWashers')}</strong> ${this.escapeHtml(washerNames)}
                    </div>` : ''}
                    ${mechanicNames ? `
                    <div style="margin-top: 2px;">
                        <strong>${I18nManager.t('assignedMechanics')}</strong> ${this.escapeHtml(mechanicNames)}
                    </div>` : ''}
                </div>

                <div class="receipt-divider"></div>

                <div style="font-weight: 800; font-size: 11.5px; margin-bottom: 4px;">
                    ${I18nManager.t('servicesRequired')}
                </div>
                <table class="receipt-table">
                    <tbody>
                        ${itemsHtml}
                    </tbody>
                </table>

                ${order.repair_notes ? `
                <div style="border: 1.5px solid #000; padding: 5px; margin-top: 6px; font-size: 10.5px;">
                    <strong>${I18nManager.t('repairNotes')}:</strong>
                    <div style="margin-top: 2px; word-break: break-word;">${this.escapeHtml(order.repair_notes)}</div>
                </div>` : ''}

                ${order.current_mileage ? `
                <div style="font-size: 10.5px; margin-top: 4px;">
                    <strong>${I18nManager.t('mileage')}:</strong> ${order.current_mileage.toLocaleString()} km
                </div>` : ''}

                <div class="receipt-divider" style="margin-top: 10px;"></div>
                <div class="text-center" style="font-size: 9.5px; color: #555;">
                    -- ${I18nManager.t('workshopTicketTitle')} --
                </div>
            `;
        }
    }

    static renderDailyCloseZReport(report) {
        if (!report) return;

        this.isPrintingDailyReport = true;
        this.isPrintingWorkshopTicket = false;

        const modalTitle = document.querySelector('#modalReceipt h3');
        if (modalTitle) modalTitle.textContent = I18nManager.t('printCloseReport') || 'Z-Report';
        const btnWasher = document.getElementById('btnPrintWasherReceipt');
        if (btnWasher) btnWasher.style.display = 'none';

        const rate = this.getExchangeRate();
        const isOffline = !!report.is_offline;
        const settings = this.getSettings();
        const currentLang = I18nManager.getLang();

        // 1. Logo
        const showLogo = (settings.receipt_show_logo !== 0 && settings.receipt_show_logo !== '0' && settings.receipt_show_logo !== false);
        const logoSrc = PosStorage.getLogoDataUri() || this.resolveAssetUrl(settings.shop_logo_url || settings.shop_logo);
        const logoHtml = (showLogo && logoSrc) ? `
            <div style="text-align: center; margin-bottom: 6px;">
                <img src="${logoSrc}" alt="Shop Logo" class="receipt-logo"
                    style="max-height: 44px; max-width: 130px; object-fit: contain; margin: 0 auto; display: block; filter: grayscale(100%) contrast(140%); -webkit-filter: grayscale(100%) contrast(140%);">
            </div>
        ` : '';

        // 2. Shop Name
        const shopName = currentLang === 'km' ? (settings.shop_name_km || 'សេវាកម្មលាងរថយន្ត និងម៉ូតូទំនើប') : (settings.shop_name_en || 'Car Wash & Detailing');
        const shopAddressHtml = settings.shop_address ? `<div style="font-size: 10px; margin-top: 2px;">${this.escapeHtml(settings.shop_address)}</div>` : '';
        const shopPhoneHtml = settings.shop_phone ? `<div style="font-size: 10px;">Tel: ${this.escapeHtml(settings.shop_phone)}</div>` : '';

        // Device name
        const devSelect = document.getElementById('closeDeviceFilter');
        let deviceName = (devSelect && devSelect.selectedIndex > 0) ? devSelect.options[devSelect.selectedIndex].text : (report.device_name || report.pos_device_name || 'Terminal 1');

        const cashierName = this.currentUser?.full_name || 'Cashier';

        // Order Counts
        const paidCount = parseInt(report.paid_orders !== undefined ? report.paid_orders : (report.paid_orders_count || 0));
        const unpaidCount = parseInt(report.unpaid_orders !== undefined ? report.unpaid_orders : (report.unpaid_orders_count || 0));
        const cancelledCount = parseInt(report.cancelled_orders !== undefined ? report.cancelled_orders : (report.cancelled_orders_count || 0));

        // Sales totals
        const netSalesUsd = parseFloat(report.total_sales_usd || 0);
        const discountUsd = parseFloat(report.discount_total_usd || 0);
        const grossSalesUsd = netSalesUsd + discountUsd;

        const cashSales = parseFloat(report.cash_sales_usd || 0);
        const abaSales = parseFloat(report.aba_sales_usd || 0);
        const khqrSales = parseFloat(report.khqr_sales_usd || 0);

        const unpaidUsd = parseFloat(report.unpaid_sales_usd || 0);
        const cancelledUsd = parseFloat(report.cancelled_sales_usd || 0);
        const expensesUsd = parseFloat(report.expenses_usd || 0);
        const openingCashUsd = parseFloat(report.opening_cash_usd || 0);
        const expectedCash = parseFloat(report.expected_cash_usd !== undefined ? report.expected_cash_usd : Math.max(0, cashSales - expensesUsd));

        const countUsd = parseFloat(document.getElementById('drawerCountUsd')?.value || 0);
        const countKhr = parseFloat(document.getElementById('drawerCountKhr')?.value || 0);
        const actualTotalUsd = countUsd + (countKhr / rate);
        const diffUsd = actualTotalUsd - expectedCash;

        const printArea = document.getElementById('receiptPrintArea');
        if (printArea) {
            printArea.innerHTML = `
                <div class="receipt-header">
                    ${logoHtml}
                    <div class="receipt-shop-name">${this.escapeHtml(shopName)}</div>
                    ${shopAddressHtml}
                    ${shopPhoneHtml}
                    <div class="receipt-badge-banner ${isOffline ? 'unpaid' : ''}" style="margin-top: 8px;">
                        ${isOffline ? I18nManager.t('offlineNotFinal') : I18nManager.t('zReportTitle')}
                    </div>
                    <div style="font-size: 10px; margin-top: 3px;">
                        ${I18nManager.t('dateTimeLabel')} ${new Date().toLocaleString()}
                    </div>
                </div>

                <div style="font-size: 11px; line-height: 1.45;">
                    <div style="display: flex; justify-content: space-between;">
                        <span>${I18nManager.t('reportDateLabel')}</span>
                        <strong>${this.escapeHtml(report.report_date || new Date().toISOString().slice(0, 10))}</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span>${I18nManager.t('deviceLabel')}</span>
                        <strong>${this.escapeHtml(deviceName)}</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span>${I18nManager.t('cashierLabel')}</span>
                        <strong>${this.escapeHtml(cashierName)}</strong>
                    </div>
                </div>

                <div class="receipt-divider"></div>

                <!-- Order counts: Paid, Unpaid, Cancelled -->
                <div style="font-size: 11px; line-height: 1.45;">
                    <div style="display: flex; justify-content: space-between;">
                        <span>${I18nManager.t('paidOrdersCountLabel')}</span>
                        <strong>${paidCount}</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; color: #555;">
                        <span>${I18nManager.t('unpaidOrdersLabel')}</span>
                        <span>${unpaidCount}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; color: #555;">
                        <span>${I18nManager.t('cancelledOrdersLabel')}</span>
                        <span>${cancelledCount}</span>
                    </div>
                </div>

                <div class="receipt-divider"></div>

                <!-- Sales Summary (Gross, Discounts, Net) in USD and KHR -->
                <div style="font-weight: 800; font-size: 11.5px; margin-bottom: 3px;">
                    ${I18nManager.t('salesSummary') || 'SALES SUMMARY'}
                </div>
                <table class="receipt-table">
                    <tbody>
                        <tr>
                            <td class="text-left">${I18nManager.t('grossSales')}</td>
                            <td class="text-right" style="font-family: monospace;">$${grossSalesUsd.toFixed(2)}</td>
                        </tr>
                        <tr>
                            <td class="text-left">${I18nManager.t('discountsGiven')}</td>
                            <td class="text-right" style="font-family: monospace;">-$${discountUsd.toFixed(2)}</td>
                        </tr>
                        <tr style="border-top: 1px solid #000; font-weight: bold; font-size: 12px;">
                            <td class="text-left">${I18nManager.t('netSales')}</td>
                            <td class="text-right" style="font-family: monospace;">$${netSalesUsd.toFixed(2)}</td>
                        </tr>
                        <tr style="font-weight: bold;">
                            <td class="text-left">${I18nManager.t('totalKhrLabel')}</td>
                            <td class="text-right" style="font-family: monospace;">${Math.round(netSalesUsd * rate).toLocaleString()} ៛</td>
                        </tr>
                    </tbody>
                </table>

                <div class="receipt-divider"></div>

                <!-- Payments by method (Cash, ABA, KHQR) in USD and KHR -->
                <div style="font-weight: 800; font-size: 11.5px; margin-bottom: 3px;">
                    ${I18nManager.t('paymentsByMethod')}
                </div>
                <table class="receipt-table">
                    <tbody>
                        <tr>
                            <td class="text-left">${I18nManager.t('cashSalesLabel')}</td>
                            <td class="text-right" style="font-family: monospace;">$${cashSales.toFixed(2)} (${Math.round(cashSales * rate).toLocaleString()} ៛)</td>
                        </tr>
                        <tr>
                            <td class="text-left">${I18nManager.t('abaSalesLabel')}</td>
                            <td class="text-right" style="font-family: monospace;">$${abaSales.toFixed(2)} (${Math.round(abaSales * rate).toLocaleString()} ៛)</td>
                        </tr>
                        <tr>
                            <td class="text-left">${I18nManager.t('khqrSalesLabel')}</td>
                            <td class="text-right" style="font-family: monospace;">$${khqrSales.toFixed(2)} (${Math.round(khqrSales * rate).toLocaleString()} ៛)</td>
                        </tr>
                    </tbody>
                </table>

                <div class="receipt-divider"></div>

                <!-- Non-Sales (Unpaid & Cancelled) -->
                <table class="receipt-table" style="color: #444; font-size: 10.5px;">
                    <tbody>
                        <tr>
                            <td class="text-left">${I18nManager.t('unpaidTotalLabel')} (${unpaidCount})</td>
                            <td class="text-right" style="font-family: monospace;">$${unpaidUsd.toFixed(2)} (${Math.round(unpaidUsd * rate).toLocaleString()} ៛)</td>
                        </tr>
                        <tr>
                            <td class="text-left">${I18nManager.t('cancelledOrdersLabel')} (${cancelledCount})</td>
                            <td class="text-right" style="font-family: monospace;">$${cancelledUsd.toFixed(2)}</td>
                        </tr>
                    </tbody>
                </table>

                <div class="receipt-divider"></div>

                <!-- Cash Drawer Audit -->
                <div style="font-weight: 800; font-size: 11.5px; margin-bottom: 3px;">
                    ${I18nManager.t('cashDrawerTitle') || 'CASH DRAWER AUDIT'}
                </div>
                <table class="receipt-table">
                    <tbody>
                        ${openingCashUsd > 0 ? `
                        <tr>
                            <td class="text-left">${I18nManager.t('openingCashLabel')}</td>
                            <td class="text-right" style="font-family: monospace;">$${openingCashUsd.toFixed(2)}</td>
                        </tr>` : ''}
                        <tr>
                            <td class="text-left">${I18nManager.t('cashSalesLabel')}</td>
                            <td class="text-right" style="font-family: monospace;">$${cashSales.toFixed(2)}</td>
                        </tr>
                        ${expensesUsd > 0 ? `
                        <tr>
                            <td class="text-left">${I18nManager.t('expensesDeductedLabel')}</td>
                            <td class="text-right" style="font-family: monospace;">-$${expensesUsd.toFixed(2)}</td>
                        </tr>` : ''}
                        <tr style="border-top: 1px dashed #000; font-weight: bold;">
                            <td class="text-left">${I18nManager.t('expectedCashLabel')}</td>
                            <td class="text-right" style="font-family: monospace;">$${expectedCash.toFixed(2)} (${Math.round(expectedCash * rate).toLocaleString()} ៛)</td>
                        </tr>
                        <tr style="font-weight: bold;">
                            <td class="text-left">${I18nManager.t('countedCashLabel')}</td>
                            <td class="text-right" style="font-family: monospace;">$${actualTotalUsd.toFixed(2)} (${Math.round(actualTotalUsd * rate).toLocaleString()} ៛)</td>
                        </tr>
                        <tr style="border-top: 1px solid #000; font-weight: 800; font-size: 12px;">
                            <td class="text-left">${I18nManager.t('varianceLabel')}</td>
                            <td class="text-right" style="font-family: monospace;">${diffUsd >= 0 ? '+' : ''}$${diffUsd.toFixed(2)} (${diffUsd >= 0 ? '+' : ''}${Math.round(diffUsd * rate).toLocaleString()} ៛)</td>
                        </tr>
                    </tbody>
                </table>

                <div class="receipt-divider"></div>

                <!-- Signatures -->
                <div style="margin-top: 20px; font-size: 10.5px;">
                    <div style="display: flex; justify-content: space-between; gap: 16px;">
                        <div style="flex: 1; text-align: center;">
                            <div class="receipt-signature-line"></div>
                            <div>${I18nManager.t('cashierSignature')}</div>
                        </div>
                        <div style="flex: 1; text-align: center;">
                            <div class="receipt-signature-line"></div>
                            <div>${I18nManager.t('managerSignature')}</div>
                        </div>
                    </div>
                </div>

                <div class="receipt-divider" style="margin-top: 14px;"></div>
                <div class="text-center" style="font-size: 9px; color: #555;">
                    ${isOffline ? I18nManager.t('offlineNotFinal') : `${this.escapeHtml(I18nManager.t('appSubtitle'))} • End of Report`}
                </div>
            `;
        }
    }

    // ========================================================
    // BAYS & QUEUE MANAGEMENT (Requirements 1, 2, 3, 4, 5)
    // ========================================================
    static currentBaysData = null;
    static isBaysPolling = false;

    static async fetchBaysAndQueue(isAutoPoll = false) {
        if (!this.isOnline) {
            this.handleBaysOffline();
            return null;
        }

        try {
            const res = await PosApi.getBaysAndQueue();
            if (res && res.success) {
                this.currentBaysData = res;
                this.handleBaysOnline();

                const totalWaiting = res.total_waiting ?? (res.wash_queue?.length || 0);
                const qBadge = document.getElementById('queueTotalWaitingBadge');
                if (qBadge) qBadge.textContent = totalWaiting;
                const wBadge = document.getElementById('washerWaitingBadge');
                if (wBadge) wBadge.textContent = res.wash_queue?.length || 0;

                this.renderCashierBays(res.bays, res.wash_queue, res.technical_queue);
                this.renderWasherBays(res.bays, res.wash_queue);
                this.renderBayStatusStrip(res.bays, res.wash_queue, res.technical_queue);

                return res;
            } else {
                throw new Error(res?.message || 'Failed to load bays');
            }
        } catch (e) {
            console.warn('Bays fetch error:', e);
            if (e.isOffline || !navigator.onLine || !this.isOnline || e.message === 'NETWORK_OFFLINE') {
                this.handleBaysOffline();
            } else if (!isAutoPoll) {
                this.showAppAlert(e.message || 'Failed to fetch bays from back office');
            }
            return null;
        }
    }

    static async pollBaysAndQueue() {
        if (!this.isOnline || this.isBaysPolling) return;
        this.isBaysPolling = true;
        try {
            await this.fetchBaysAndQueue(true);
        } finally {
            this.isBaysPolling = false;
        }
    }

    static handleBaysOffline() {
        const offlineBanner = document.getElementById('queueOfflineBanner');
        if (offlineBanner) offlineBanner.style.display = 'flex';
        const washerBanner = document.getElementById('washerOfflineBanner');
        if (washerBanner) washerBanner.style.display = 'flex';

        const qSync = document.getElementById('queueSyncIndicator');
        if (qSync) {
            qSync.style.background = '#fef2f2';
            qSync.style.color = '#dc2626';
            qSync.style.borderColor = '#fca5a5';
            qSync.innerHTML = `<i class="fa-solid fa-circle" style="font-size: 0.55rem; color: #dc2626;"></i> <span>Offline</span>`;
        }
        const wSync = document.getElementById('washerSyncIndicator');
        if (wSync) {
            wSync.style.background = '#fef2f2';
            wSync.style.color = '#dc2626';
            wSync.style.borderColor = '#fca5a5';
            wSync.innerHTML = `<i class="fa-solid fa-circle" style="font-size: 0.55rem; color: #dc2626;"></i> <span>Offline</span>`;
        }

        document.querySelectorAll('.btn-assign-next, .btn-washer-assign, [data-action="assign-bay-modal"]').forEach(btn => {
            btn.disabled = true;
            btn.setAttribute('title', I18nManager.t('offlineAssignDisabled') || 'Offline: cannot assign bay');
        });
    }

    static handleBaysOnline() {
        const offlineBanner = document.getElementById('queueOfflineBanner');
        if (offlineBanner) offlineBanner.style.display = 'none';
        const washerBanner = document.getElementById('washerOfflineBanner');
        if (washerBanner) washerBanner.style.display = 'none';

        const qSync = document.getElementById('queueSyncIndicator');
        if (qSync) {
            qSync.style.background = '#f0fdf4';
            qSync.style.color = '#166534';
            qSync.style.borderColor = '#bbf7d0';
            qSync.innerHTML = `<i class="fa-solid fa-circle live-pulse-dot"></i> <span>Live</span>`;
        }
        const wSync = document.getElementById('washerSyncIndicator');
        if (wSync) {
            wSync.style.background = '#f0fdf4';
            wSync.style.color = '#166534';
            wSync.style.borderColor = '#bbf7d0';
            wSync.innerHTML = `<i class="fa-solid fa-circle live-pulse-dot"></i> <span>Live</span>`;
        }

        document.querySelectorAll('.btn-assign-next, .btn-washer-assign, [data-action="assign-bay-modal"]').forEach(btn => {
            btn.disabled = false;
            btn.removeAttribute('title');
        });
    }

    static renderCashierBays(bays, washQueue = [], techQueue = []) {
        const container = document.getElementById('cashierBaysGrid');
        if (!container) return;

        if (!Array.isArray(bays) || bays.length === 0) {
            container.innerHTML = `<div style="grid-column: 1/-1; text-align: center; padding: 1.5rem; color: var(--text-muted);">${I18nManager.t('noBaysFound') || 'No bays found'}</div>`;
            return;
        }

        let html = '';
        bays.forEach(bay => {
            const isFree = (bay.status === 'free');
            const isWash = (bay.bay_type === 'wash');
            const typeLabel = isWash ? 'Wash' : 'Oil / Tech';
            const typeClass = isWash ? 'wash' : 'oil_change';
            const statusLabel = isFree ? (I18nManager.t('free') || 'FREE') : (I18nManager.t('busy') || 'BUSY');

            const relevantQueue = isWash ? washQueue : techQueue;
            const nextCar = relevantQueue && relevantQueue.length > 0 ? relevantQueue[0] : null;

            let cardContentHtml = '';
            if (isFree) {
                if (nextCar) {
                    const assignTitle = I18nManager.t('assignNextCar') || 'Assign Next';
                    cardContentHtml = `
                        <div class="bay-empty-state" style="border-color: #86efac; background: #f0fdf4;">
                            <span style="font-weight: 700; color: #15803d; font-size: 0.85rem;"><i class="fa-solid fa-check-circle"></i> ${I18nManager.t('free') || 'Free & Ready'}</span>
                            <span style="font-size: 0.78rem; color: #166534;">${I18nManager.t('nextInLine') || 'Next'}: <strong>${this.escapeHtml(nextCar.plate_number)}</strong></span>
                        </div>
                        <button type="button" class="btn-assign-next" ${!this.isOnline ? 'disabled title="Offline"' : ''} onclick="PosApp.handleAssignBayOneTap(${bay.id}, ${nextCar.order_id}, '${this.escapeHtml(nextCar.plate_number)}', '${this.escapeHtml(bay.bay_name)}')">
                            <i class="fa-solid fa-play"></i> ${assignTitle}: <strong>${this.escapeHtml(nextCar.plate_number)}</strong>
                        </button>
                    `;
                } else {
                    cardContentHtml = `
                        <div class="bay-empty-state">
                            <i class="fa-solid fa-circle-check" style="color: var(--success); font-size: 1.5rem; margin-bottom: 2px;"></i>
                            <span style="font-weight: 700; color: var(--text-main);">${I18nManager.t('free') || 'Free & Ready'}</span>
                            <span style="font-size: 0.76rem; color: var(--text-muted);">${I18nManager.t('noCarsWaiting') || 'No cars waiting'}</span>
                        </div>
                    `;
                }
            } else {
                const plate = bay.plate_number || '--';
                const custName = bay.customer_name ? this.stripHtml(bay.customer_name) : 'Walk-in';
                const service = bay.service_names ? this.stripHtml(bay.service_names) : 'Car Wash';
                const duration = bay.in_bay_minutes ?? 0;
                cardContentHtml = `
                    <div class="bay-occupied-info">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span class="bay-plate-badge">${this.escapeHtml(plate)}</span>
                            <span style="font-size: 0.78rem; font-weight: 700; color: #1d4ed8; background: #eff6ff; padding: 2px 7px; border-radius: 4px;">
                                <i class="fa-regular fa-clock"></i> ${duration}m in bay
                            </span>
                        </div>
                        <div class="bay-service-text" title="${this.escapeHtml(service)}">${this.escapeHtml(service)}</div>
                        <div class="bay-meta-row">
                            <span><i class="fa-regular fa-user"></i> ${this.escapeHtml(custName)}</span>
                            <span>#${this.escapeHtml(bay.order_number || '')}</span>
                        </div>
                    </div>
                `;
            }

            html += `
                <div class="bay-card ${isFree ? 'bay-free' : 'bay-busy'}" id="cashierBayCard-${bay.id}">
                    <div class="bay-card-top">
                        <div class="bay-name-title">
                            <i class="fa-solid fa-warehouse" style="color: ${isFree ? 'var(--success)' : '#2563eb'};"></i>
                            <span>${this.escapeHtml(bay.bay_name)}</span>
                            <span class="bay-type-badge ${typeClass}">${typeLabel}</span>
                        </div>
                        <span class="bay-status-badge ${isFree ? 'free' : 'busy'}">
                            <i class="fa-solid ${isFree ? 'fa-circle-check' : 'fa-circle live-pulse-dot'}"></i>
                            ${statusLabel}
                        </span>
                    </div>
                    ${cardContentHtml}
                </div>
            `;
        });

        container.innerHTML = html;
    }

    static renderWasherBays(bays, washQueue = []) {
        const container = document.getElementById('washerBaysGrid');
        if (!container) return;

        // Requirement 3: Washer screen shows the 3 bays (wash bays)
        const washBays = (bays || []).filter(b => b.bay_type === 'wash');
        const displayBays = washBays.length > 0 ? washBays : bays;

        const nextCar = washQueue && washQueue.length > 0 ? washQueue[0] : null;

        let html = '';
        displayBays.forEach(bay => {
            const isFree = (bay.status === 'free');
            const statusLabel = isFree ? (I18nManager.t('free') || 'FREE') : (I18nManager.t('busy') || 'BUSY');

            let cardContentHtml = '';
            if (isFree) {
                if (nextCar) {
                    const putTitle = I18nManager.t('putInBay') || 'Put Next Car in Bay';
                    cardContentHtml = `
                        <div class="bay-empty-state" style="border-color: #86efac; background: #f0fdf4;">
                            <span style="font-weight: 700; color: #15803d; font-size: 0.85rem;"><i class="fa-solid fa-check-circle"></i> ${I18nManager.t('free') || 'Free'}</span>
                            <span style="font-size: 0.78rem; color: #166534;">Next: <strong>${this.escapeHtml(nextCar.plate_number)}</strong></span>
                        </div>
                        <button type="button" class="btn-assign-next" ${!this.isOnline ? 'disabled title="Offline"' : ''} onclick="PosApp.handleWasherAssignBay(${bay.id}, ${nextCar.order_id}, '${this.escapeHtml(nextCar.plate_number)}', '${this.escapeHtml(bay.bay_name)}')">
                            <i class="fa-solid fa-arrow-right-to-bracket"></i> ${putTitle}: <strong>${this.escapeHtml(nextCar.plate_number)}</strong>
                        </button>
                    `;
                } else {
                    cardContentHtml = `
                        <div class="bay-empty-state">
                            <i class="fa-solid fa-circle-check" style="color: var(--success); font-size: 1.5rem; margin-bottom: 2px;"></i>
                            <span style="font-weight: 700; color: var(--text-main);">${I18nManager.t('free') || 'Free'}</span>
                            <span style="font-size: 0.76rem; color: var(--text-muted);">${I18nManager.t('noCarsWaiting') || 'No cars waiting'}</span>
                        </div>
                    `;
                }
            } else {
                // Requirement 3: A busy bay cannot be chosen! Shows Finished button when done.
                const plate = bay.plate_number || '--';
                const service = bay.service_names ? this.stripHtml(bay.service_names) : 'Car Wash';
                const duration = bay.in_bay_minutes ?? 0;
                const isMixed = (bay.is_mixed == 1 || (bay.current_car && bay.current_car.is_mixed == 1));

                const actionBtn = isMixed
                    ? `<button type="button" class="btn btn-sm btn-send-tech" style="width: 100%; margin-top: 0.5rem; padding: 0.5rem 0.75rem; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem;" onclick="PosApp.openWasherFinishPrompt(${bay.id}, ${bay.current_order_id || 0}, '${this.escapeHtml(plate)}', '${this.escapeHtml(bay.bay_name)}', 1)">
                        <i class="fa-solid fa-paper-plane"></i> Send to Tech
                       </button>`
                    : `<button type="button" class="btn-washer-finish" style="width: 100%; margin-top: 0.5rem;" onclick="PosApp.openWasherFinishPrompt(${bay.id}, ${bay.current_order_id || 0}, '${this.escapeHtml(plate)}', '${this.escapeHtml(bay.bay_name)}', 0)">
                        <i class="fa-solid fa-circle-check"></i> Finish
                       </button>`;

                cardContentHtml = `
                    <div class="bay-occupied-info">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span class="bay-plate-badge">${this.escapeHtml(plate)}</span>
                            <span style="font-size: 0.78rem; font-weight: 700; color: #1d4ed8; background: #eff6ff; padding: 2px 7px; border-radius: 4px;">
                                <i class="fa-regular fa-clock"></i> ${duration}m in bay
                            </span>
                        </div>
                        <div class="bay-service-text" title="${this.escapeHtml(service)}">${this.escapeHtml(service)}</div>
                    </div>
                    ${actionBtn}
                `;
            }

            html += `
                <div class="bay-card ${isFree ? 'bay-free' : 'bay-busy'}" id="washerBayCard-${bay.id}">
                    <div class="bay-card-top">
                        <div class="bay-name-title">
                            <i class="fa-solid fa-warehouse" style="color: ${isFree ? 'var(--success)' : '#2563eb'};"></i>
                            <span>${this.escapeHtml(bay.bay_name)}</span>
                        </div>
                        <span class="bay-status-badge ${isFree ? 'free' : 'busy'}">
                            <i class="fa-solid ${isFree ? 'fa-circle-check' : 'fa-circle live-pulse-dot'}"></i>
                            ${statusLabel}
                        </span>
                    </div>
                    ${cardContentHtml}
                </div>
            `;
        });

        container.innerHTML = html;
    }

    static async handleAssignBayOneTap(bayId, orderId, plate, bayName) {
        if (!this.isOnline) {
            this.showAppAlert(I18nManager.t('offlineAssignDisabled') || 'Offline: cannot assign bay while disconnected.');
            return;
        }

        try {
            const res = await PosApi.assignBay(bayId, orderId, this.currentUser?.id);
            if (res && res.success) {
                await this.fetchBaysAndQueue();
                await this.renderJobQueue();
            } else {
                if (res?.error === 'RECEIPT_REQUIRED') {
                    this.requestManagerPin(
                        "Receipt Required Override",
                        `Receipt not printed for ${plate}. Enter Manager PIN and override reason to proceed:`,
                        async (mgr) => {
                            const reason = prompt("Enter manager override reason:", "Customer requested wash before payment/receipt") || "Manager Override";
                            try {
                                const overrideRes = await PosApi.assignBay(bayId, orderId, {
                                    manager_pin: mgr.pin,
                                    manager_username: mgr.username,
                                    reason: reason
                                });
                                if (overrideRes && overrideRes.success) {
                                    this.showToastNotice(`Manager override authorized: ${plate} assigned to ${bayName}`, 'success');
                                    await this.fetchBaysAndQueue();
                                    await this.renderJobQueue();
                                } else {
                                    this.showAppAlert(overrideRes?.message || 'Override failed');
                                }
                            } catch (err) {
                                this.showAppAlert(err?.data?.message || err.message || 'Override failed');
                            }
                        }
                    );
                } else if (res?.error === 'BAY_ALREADY_TAKEN' || res?.error === 'BAY_BUSY') {
                    this.showAppAlert(I18nManager.t('bayAlreadyTaken') || 'Bay already taken! Another terminal or washer just occupied this bay.');
                    await this.fetchBaysAndQueue();
                } else {
                    this.showAppAlert(res?.message || 'Failed to assign bay');
                }
            }
        } catch (e) {
            console.error('Assign bay error:', e);
            if (e.data?.error === 'RECEIPT_REQUIRED' || e.message?.includes('RECEIPT_REQUIRED')) {
                this.requestManagerPin(
                    "Receipt Required Override",
                    `Receipt not printed for ${plate}. Enter Manager PIN and override reason to proceed:`,
                    async (mgr) => {
                        const reason = prompt("Enter manager override reason:", "Customer requested wash before payment/receipt") || "Manager Override";
                        try {
                            const overrideRes = await PosApi.assignBay(bayId, orderId, {
                                manager_pin: mgr.pin,
                                manager_username: mgr.username,
                                reason: reason
                            });
                            if (overrideRes && overrideRes.success) {
                                this.showToastNotice(`Manager override authorized: ${plate} assigned to ${bayName}`, 'success');
                                await this.fetchBaysAndQueue();
                                await this.renderJobQueue();
                            } else {
                                this.showAppAlert(overrideRes?.message || 'Override failed');
                            }
                        } catch (err) {
                            this.showAppAlert(err?.data?.message || err.message || 'Override failed');
                        }
                    }
                );
            } else if (e.data?.error === 'BAY_ALREADY_TAKEN' || e.message?.includes('taken') || e.message?.includes('busy')) {
                this.showAppAlert(I18nManager.t('bayAlreadyTaken') || 'Bay already taken! Another terminal or washer just occupied this bay.');
                await this.fetchBaysAndQueue();
            } else {
                this.showAppAlert(e.data?.message || e.message || 'Failed to assign bay');
            }
        }
    }

    static async handleWasherAssignBay(bayId, orderId, plate, bayName) {
        await this.handleAssignBayOneTap(bayId, orderId, plate, bayName);
        if (typeof this.renderWasherScreen === 'function') {
            await this.renderWasherScreen();
        }
    }

    static openWasherFinishPrompt(bayId, orderId, plate, bayName, isMixed = 0, role = 'washer') {
        const modal = document.getElementById('modalWasherFinish');
        if (!modal) return;
        const targetId = bayId > 0 ? bayId : orderId;
        document.getElementById('finishPromptTargetId').value = targetId;
        document.getElementById('finishPromptIsMixed').value = isMixed ? '1' : '0';
        document.getElementById('finishPromptRole').value = role;
        document.getElementById('finishPromptVehicle').textContent = `Vehicle: ${plate}`;
        document.getElementById('finishPromptTitle').textContent = isMixed ? 'Send to Tech (Mixed Order)' : (role === 'technical' ? 'Finish Technical Job' : 'Complete Wash');
        modal.classList.add('open');
    }

    static async handleConfirmFinish(tookLonger = 0) {
        const modal = document.getElementById('modalWasherFinish');
        const targetId = parseInt(document.getElementById('finishPromptTargetId')?.value || 0);
        const isMixed = document.getElementById('finishPromptIsMixed')?.value === '1';
        const role = document.getElementById('finishPromptRole')?.value || 'washer';

        if (modal) modal.classList.remove('open');
        if (targetId <= 0) return;

        try {
            let res;
            if (role === 'technical') {
                res = await PosApi.finishTechJob(targetId, tookLonger);
                if (res && res.success) {
                    this.showToastNotice('Technical Job completed! Vehicle is ready for cashier checkout.', 'success');
                    this.playChimeSound();
                    await this.renderMechanicScreen();
                } else {
                    this.showAppAlert(res?.message || res?.error || 'Failed to complete technical job');
                }
            } else {
                res = await PosApi.finishWasherCar(targetId, tookLonger);
                if (res && res.success) {
                    if (res.is_handshake) {
                        this.showToastNotice(`Wash completed. Car ${res.plate_number || ''} sent to Technical screen! Wash Bay is now Free.`, 'info');
                    } else {
                        this.showToastNotice(`Wash completed for car ${res.plate_number || ''}. Free & Ready for payment.`, 'success');
                    }
                    this.playChimeSound();
                    await this.fetchBaysAndQueue(true);
                    await this.renderWasherScreen();
                } else {
                    this.showAppAlert(res?.message || res?.error || 'Failed to finish wash');
                }
            }
        } catch (e) {
            this.showAppAlert(e.data?.message || e.message || 'Error completing job');
        }
    }

    static async handleWasherFinishBay(bayId, orderId, plate, bayName) {
        this.openWasherFinishPrompt(bayId, orderId, plate, bayName, 0, 'washer');
    }

    static openChooseBayModal(orderId, plateNumber) {
        if (!this.isOnline) {
            this.showAppAlert(I18nManager.t('offlineAssignDisabled') || 'Offline: cannot assign bay while disconnected.');
            return;
        }

        const modal = document.getElementById('modalChooseBay');
        const plateEl = document.getElementById('chooseBayPlate');
        const listEl = document.getElementById('chooseBayList');
        if (!modal || !listEl) return;

        if (plateEl) plateEl.textContent = plateNumber || '--';

        const bays = (this.currentBaysData?.bays || []);
        const freeBays = bays.filter(b => b.status === 'free');

        if (freeBays.length === 0) {
            this.showAppAlert(I18nManager.t('allBaysBusy') || 'All bays are currently busy! Please wait for a bay to be finished.');
            return;
        }

        let html = '';
        freeBays.forEach(bay => {
            const isWash = (bay.bay_type === 'wash');
            html += `
                <div class="choose-bay-option" onclick="PosApp.handleChooseBaySelect(${bay.id}, ${orderId}, '${this.escapeHtml(plateNumber)}', '${this.escapeHtml(bay.bay_name)}')">
                    <div style="display: flex; align-items: center; gap: 0.65rem;">
                        <i class="fa-solid fa-warehouse" style="color: var(--primary); font-size: 1.25rem;"></i>
                        <div>
                            <strong style="font-size: 0.95rem; color: var(--text-main); display: block;">${this.escapeHtml(bay.bay_name)}</strong>
                            <span style="font-size: 0.75rem; color: var(--text-muted);">${isWash ? 'Wash Bay' : 'Oil / Technical Bay'}</span>
                        </div>
                    </div>
                    <span class="bay-status-badge free"><i class="fa-solid fa-arrow-right"></i> ${I18nManager.t('assignBay') || 'Assign'}</span>
                </div>
            `;
        });

        listEl.innerHTML = html;
        modal.classList.add('open');
    }

    static async handleChooseBaySelect(bayId, orderId, plate, bayName) {
        document.getElementById('modalChooseBay')?.classList.remove('open');
        await this.handleAssignBayOneTap(bayId, orderId, plate, bayName);
    }

    static async handleCustomerArrived(orderId, plateNumber) {
        if (!this.isOnline) {
            this.showAppAlert(I18nManager.t('offlineShowingSavedData') || 'Offline: Customer arrival cannot be registered while disconnected.');
            return;
        }

        try {
            const res = await PosApi.markCustomerArrived(orderId, plateNumber, this.currentUser?.id);
            if (res && res.success) {
                await this.fetchBaysAndQueue();
                await this.renderJobQueue();
            } else {
                this.showAppAlert(res?.message || 'Failed to register customer arrival');
            }
        } catch (e) {
            this.showAppAlert(e.data?.message || e.message || 'Failed to register customer arrival');
        }
    }

    // ========================================================
    // JOB QUEUE BOARD (Live updates from web and staff - Requirements 6, 7, 8, 9)
    static async renderJobQueue() {
        const grid = document.getElementById('queueGrid');
        if (!grid) return;

        const errorBanner = document.getElementById('queueErrorBanner');
        const offlineBanner = document.getElementById('queueOfflineBanner');
        if (errorBanner) errorBanner.style.display = 'none';
        if (offlineBanner) offlineBanner.style.display = 'none';

        // Requirement 1 & 4: Fetch bays and queue data
        await this.fetchBaysAndQueue(true);

        let orders = [];
        let isOfflineFallback = false;

        if (this.isOnline) {
            try {
                const res = await PosApi.getQueue();
                if (res && res.success && Array.isArray(res.orders)) {
                    orders = res.orders;
                    PosStorage.setSyncedOrders(orders);
                } else {
                    throw new Error(res?.message || 'Failed to load queue');
                }
            } catch (e) {
                console.warn('Queue fetch error:', e);
                if (e.isOffline || !navigator.onLine || !this.isOnline || e.message === 'NETWORK_OFFLINE') {
                    isOfflineFallback = true;
                    if (offlineBanner) offlineBanner.style.display = 'flex';
                    // Requirement 6: Offline fallback show only active jobs (waiting, washing, finished)
                    const localOrders = PosStorage.getSyncedOrders() || [];
                    orders = localOrders.filter(o => ['waiting', 'washing', 'finished'].includes(o.status || 'waiting'));
                } else {
                    // Requirement 6: Show clear error message with retry button, NOT "no jobs"
                    if (errorBanner) {
                        const errText = document.getElementById('queueErrorText');
                        if (errText) errText.textContent = e.data?.message || e.message || I18nManager.t('queueLoadError') || 'Failed to fetch queue from Back Office';
                        errorBanner.style.display = 'flex';
                    }
                    grid.innerHTML = '';
                    return;
                }
            }
        } else {
            isOfflineFallback = true;
            if (offlineBanner) offlineBanner.style.display = 'flex';
            // Requirement 6: Offline fallback show only active jobs (waiting, washing, finished)
            const localOrders = PosStorage.getSyncedOrders() || [];
            orders = localOrders.filter(o => ['waiting', 'washing', 'finished'].includes(o.status || 'waiting'));
        }

        // Requirement 7: Merge pending offline status changes
        const offlineQueue = PosStorage.getOfflineStatusQueue();
        const offlineStatusMap = {};
        offlineQueue.forEach(item => {
            offlineStatusMap[item.orderNumber] = item.status;
        });

        this.currentQueueOrdersMap = {};

        orders.forEach(ord => {
            if (offlineStatusMap[ord.order_number]) {
                ord.status = offlineStatusMap[ord.order_number];
                ord._isPendingSync = true;
            }
            this.currentQueueOrdersMap[ord.order_number] = ord;
        });

        if (orders.length === 0) {
            const emptyMsg = I18nManager.t('noActiveQueue') || 'No active wash or service jobs currently in queue.';
            grid.innerHTML = `
                <div style="grid-column: 1 / -1; text-align: center; padding: 3rem; color: var(--text-muted);">
                    <i class="fa-solid fa-car-wash" style="font-size: 2.5rem; opacity: 0.3; margin-bottom: 0.5rem;"></i>
                    <p>${emptyMsg}</p>
                </div>
            `;
            return;
        }

        // Map queue positions from back office
        const washQueue = this.currentBaysData?.wash_queue || [];
        const techQueue = this.currentBaysData?.technical_queue || [];
        const queueMap = {};
        [...washQueue, ...techQueue].forEach(q => {
            if (q.order_number) queueMap[q.order_number] = q;
            if (q.order_id) queueMap[q.order_id] = q;
        });

        let html = '';
        const isCashier = (this.currentUser?.role || '').toLowerCase() === 'cashier';
        orders.forEach(ord => {
            const status = ord.status || (ord.is_paid ? 'paid' : 'waiting');
            const isUnpaid = (ord.payment_status === 'unpaid' || status === 'waiting' || status === 'washing' || status === 'finished') && status !== 'paid';
            const isCancelled = (status === 'cancelled');

            let badgeClass = 'waiting';
            let badgeIcon = 'fa-hourglass-half';
            let statusText = status.toUpperCase();

            // Position & Waiting Status Badge
            const qItem = queueMap[ord.order_number] || queueMap[ord.id];
            let positionBadgeHtml = '';

            if (status === 'washing') {
                badgeClass = 'washing';
                badgeIcon = 'fa-spinner fa-spin';
                statusText = I18nManager.t('statusWashing') || 'WASHING';
            } else if (status === 'finished') {
                // Requirement 5: When the washer finishes a car, cashier screen shows "Ready to pay" on that car
                badgeClass = 'finished badge-ready-to-pay';
                badgeIcon = 'fa-circle-check';
                statusText = I18nManager.t('readyToPay') || 'Ready to pay';
            } else if (status === 'paid') {
                badgeClass = 'paid';
                badgeIcon = 'fa-receipt';
                statusText = I18nManager.t('statusPaid') || 'PAID';
            } else if (status === 'cancelled') {
                badgeClass = 'cancelled';
                badgeIcon = 'fa-ban';
                statusText = I18nManager.t('statusCancelled') || 'CANCELLED';
            } else {
                statusText = I18nManager.t('statusWaiting') || 'WAITING';
                if (qItem) {
                    if (qItem.position === 1 || qItem.is_next) {
                        positionBadgeHtml = `<span class="queue-pos-badge next"><i class="fa-solid fa-bolt"></i> 1st (${I18nManager.t('nextInLine') || 'Next'})</span>`;
                    } else {
                        positionBadgeHtml = `<span class="queue-pos-badge pos-sub">${qItem.position_label || (qItem.position + 'th')}</span>`;
                    }
                } else if (!ord.arrived_at) {
                    positionBadgeHtml = `<span class="queue-pos-badge not-arrived"><i class="fa-regular fa-clock"></i> Not Arrived</span>`;
                }
            }

            let nextActionBtn = '';
            if (status === 'waiting') {
                if (!ord.arrived_at && !qItem) {
                    // Requirement 1: Customer arrived button
                    nextActionBtn = `
                        <button type="button" class="btn btn-outline btn-sm" style="color: #16a34a; border-color: #86efac; font-weight: 700;" data-action="customer-arrived" data-order-number="${this.escapeHtml(ord.order_number)}" data-order-id="${ord.id || 0}" data-plate="${this.escapeHtml(ord.plate_number)}">
                            <i class="fa-solid fa-car-side"></i> ${I18nManager.t('customerArrived') || 'Customer Arrived'}
                        </button>
                    `;
                } else {
                    // Requirement 2: Assign bay button
                    nextActionBtn = `
                        <button type="button" class="btn btn-primary btn-sm" ${!this.isOnline ? 'disabled title="Offline"' : ''} data-action="assign-bay-modal" data-order-number="${this.escapeHtml(ord.order_number)}" data-order-id="${ord.id || 0}" data-plate="${this.escapeHtml(ord.plate_number)}">
                            <i class="fa-solid fa-warehouse"></i> ${I18nManager.t('assignBay') || 'Assign Bay'}
                        </button>
                    `;
                }
            } else if (status === 'washing') {
                nextActionBtn = `<button type="button" class="btn btn-outline btn-sm" style="border-color: var(--success); color: var(--success);" data-action="finish-job" data-order-number="${this.escapeHtml(ord.order_number)}"><i class="fa-solid fa-check"></i> ${I18nManager.t('finishWash') || 'Finish'}</button>`;
            } else if (status === 'finished') {
                nextActionBtn = `<span style="font-size: 0.82rem; font-weight: 800; color: var(--success); display: inline-flex; align-items: center; gap: 4px;"><i class="fa-solid fa-circle-check"></i> ${I18nManager.t('readyToPay') || 'Ready to pay'}</span>`;
            } else if (status === 'paid') {
                nextActionBtn = `<span style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted);"><i class="fa-solid fa-receipt"></i> ${I18nManager.t('completedPaid') || 'Completed & Paid'}</span>`;
            } else if (status === 'cancelled') {
                nextActionBtn = `<span style="font-size: 0.8rem; font-weight: 700; color: var(--danger);"><i class="fa-solid fa-ban"></i> ${I18nManager.t('cancelled') || 'Cancelled'}</span>`;
            }

            const washersLabel = ord.washer_names ? `Washers: ${this.escapeHtml(ord.washer_names)}` : 'No washers';
            const mechanicsLabel = ord.mechanic_names ? `Mechanics: ${this.escapeHtml(ord.mechanic_names)}` : '';
            const crewLine = [washersLabel, mechanicsLabel].filter(Boolean).join(' • ');
            const totalNum = parseFloat(ord.total_usd || 0);

            const pendingBadgeHtml = ord._isPendingSync ? `<span class="badge" style="background: #fef3c7; color: #b45309; font-size: 0.72rem; padding: 2px 6px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px;"><i class="fa-solid fa-cloud-arrow-up"></i> ${I18nManager.t('pendingSyncBadge') || 'Pending Sync'}</span>` : '';
            const receiptPendingBadge = (!ord.receipt_printed_at && status !== 'cancelled') ? `<span class="badge" style="background: #f59e0b; color: #fff; font-size: 0.72rem; padding: 2px 6px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px;"><i class="fa-solid fa-receipt"></i> ${I18nManager.t('receiptPendingBadge') || 'Receipt Pending'}</span>` : '';

            const isReadyToPay = (status === 'finished');

            html += `
                <div class="queue-card" style="${isCancelled ? 'opacity: 0.65; border-color: #fecaca;' : ''} ${isReadyToPay ? 'border: 2px solid #86efac; box-shadow: 0 4px 12px rgba(22, 163, 74, 0.12);' : ''}">
                    <div class="queue-card-header">
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <span class="queue-card-plate">${this.escapeHtml(ord.plate_number)}</span>
                            ${positionBadgeHtml}
                        </div>
                        <div style="display: flex; gap: 0.35rem; align-items: center;">
                            ${pendingBadgeHtml}
                            ${receiptPendingBadge}
                            <span class="queue-badge ${badgeClass}"><i class="fa-solid ${badgeIcon}"></i> ${statusText}</span>
                        </div>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.2rem; font-size: 0.88rem;">
                        <span style="font-weight: 700;">${this.formatCustomerName(ord.customer_name)}</span>
                        <span style="color: var(--text-muted); font-size: 0.78rem;">#${this.escapeHtml(ord.order_number)} • $${totalNum.toFixed(2)} ${ord.item_names ? '• ' + this.escapeHtml(ord.item_names) : ''}</span>
                        <span style="color: var(--text-muted); font-size: 0.75rem; margin-top: 0.2rem;"><i class="fa-solid fa-users" style="font-size: 0.7rem;"></i> ${crewLine}</span>
                    </div>
                    <div style="margin-top: 0.75rem; display: flex; gap: 0.4rem; align-items: center; flex-wrap: wrap;">
                        ${nextActionBtn}

                        ${(!isCancelled && isUnpaid) ? `
                            <button type="button" class="btn btn-sm" style="background: ${isReadyToPay ? '#16a34a' : 'var(--success)'}; color: white; font-weight: 800; ${isReadyToPay ? 'padding: 0.45rem 0.85rem; box-shadow: 0 2px 6px rgba(22, 163, 74, 0.35);' : ''}" data-action="pay-order" data-order-number="${this.escapeHtml(ord.order_number)}">
                                <i class="fa-solid fa-dollar-sign"></i> ${I18nManager.t('payOrder') || (isReadyToPay ? 'Pay / Checkout' : 'Pay Order')}
                            </button>
                        ` : ''}

                        ${(!isCancelled && !isCashier) ? `
                            <button type="button" class="btn btn-outline btn-sm" data-action="change-crew" data-order-number="${this.escapeHtml(ord.order_number)}" title="Change Crew">
                                <i class="fa-solid fa-users-gear"></i> ${I18nManager.t('crewBtn') || 'Crew'}
                            </button>
                            <button type="button" class="btn btn-outline btn-sm" style="color: #dc2626; border-color: #fecaca;" data-action="cancel-order" data-order-number="${this.escapeHtml(ord.order_number)}" title="Cancel Order">
                                <i class="fa-solid fa-ban"></i> ${I18nManager.t('cancelBtn') || 'Cancel'}
                            </button>
                        ` : ''}

                        <button type="button" class="btn btn-outline btn-sm" data-action="reprint-receipt" data-order-number="${this.escapeHtml(ord.order_number)}" title="Reprint Receipt">
                            <i class="fa-solid fa-print"></i>
                        </button>
                        <button type="button" class="btn btn-outline btn-sm" style="color: var(--primary);" data-action="print-ticket" data-order-number="${this.escapeHtml(ord.order_number)}" title="Print Washer Ticket">
                            <i class="fa-solid fa-ticket"></i>
                        </button>
                    </div>
                </div>
            `;
        });

        grid.innerHTML = html;

        // Requirement 8: Event delegation instead of inline onclick text
        grid.onclick = (e) => {
            const btn = e.target.closest('[data-action]');
            if (!btn) return;
            const action = btn.getAttribute('data-action');
            const num = btn.getAttribute('data-order-number');
            const ord = this.currentQueueOrdersMap[num];

            if (action === 'customer-arrived') {
                const oId = parseInt(btn.getAttribute('data-order-id')) || (ord?.id || 0);
                const plate = btn.getAttribute('data-plate') || ord?.plate_number || '';
                this.handleCustomerArrived(oId, plate);
            } else if (action === 'assign-bay-modal') {
                const oId = parseInt(btn.getAttribute('data-order-id')) || (ord?.id || 0);
                const plate = btn.getAttribute('data-plate') || ord?.plate_number || '';
                this.openChooseBayModal(oId, plate);
            } else if (action === 'start-job') {
                this.updateJobStatus(num, 'washing');
            } else if (action === 'finish-job') {
                this.updateJobStatus(num, 'finished');
            } else if (action === 'pay-order' && ord) {
                this.openPayOrderModal(ord);
            } else if (action === 'change-crew' && ord) {
                this.openChangeCrewModal(ord.id || 0, ord.order_number, ord.plate_number, ord.washer_ids || '', ord.mechanic_ids || '');
            } else if (action === 'cancel-order' && ord) {
                this.openCancelOrderModal(ord.id || 0, ord.order_number, ord.plate_number);
            } else if (action === 'reprint-receipt') {
                this.reprintReceipt(num);
            } else if (action === 'print-ticket') {
                this.printWasherTicket(num);
            }
        };
    }

    // Requirement 7: Offline status changes saved in queue, sent when connection returns, not overwritten
    static async updateJobStatus(orderNumber, newStatus) {
        let synced = false;
        if (this.isOnline) {
            try {
                const res = await PosApi.syncOrderStatus(0, orderNumber, newStatus);
                if (res && res.success) {
                    synced = true;
                    PosStorage.removeOfflineStatusChange(orderNumber);
                }
            } catch (e) {
                console.warn('Could not sync status to back office immediately, saving offline', e);
            }
        }

        if (!synced) {
            PosStorage.addOfflineStatusChange({ orderNumber, status: newStatus });
        }

        // Update local copy
        const orders = PosStorage.getSyncedOrders() || [];
        const target = orders.find(o => o.order_number === orderNumber);
        if (target) {
            target.status = newStatus;
            PosStorage.addSyncedOrder(target);
        }

        await this.renderJobQueue();
    }

    static async reprintReceipt(orderNumber) {
        const orders = PosStorage.getSyncedOrders() || [];
        let target = orders.find(o => o.order_number === orderNumber);
        if (!target && this.currentTodayOrdersList) {
            target = this.currentTodayOrdersList.find(o => o.order_number === orderNumber);
        }
        if (!target && this.lastCompletedOrder && this.lastCompletedOrder.order_number === orderNumber) {
            target = this.lastCompletedOrder;
        }
        if (!target && this.lastCreatedOrderRecord && this.lastCreatedOrderRecord.order_number === orderNumber) {
            target = this.lastCreatedOrderRecord;
        }

        // Requirement 13: If order is not saved locally, fetch it from the back office
        if (!target) {
            try {
                const res = await PosApi.getOrder(orderNumber);
                if (res && res.success && res.order) {
                    target = res.order;
                    PosStorage.addSyncedOrder(target);
                }
            } catch (e) {
                console.warn('Could not fetch order from server for reprint:', e);
            }
        }

        if (target) {
            this.currentReceiptOrder = target;
            this.renderReceipt(target);
            document.getElementById('modalReceipt')?.classList.add('open');

            const cfg = PosConfig.get();
            if (cfg.printerDirectEnabled) {
                await this.executeSilentPrint(target);
            }
        } else {
            alert(`Order #${orderNumber} not found.`);
        }
    }

    // ========================================================
    // PAY UNPAID ORDER MODAL (Requirement 4)
    // ========================================================
    static currentPayingOrder = null;
    static payingMethod = 'cash';

    static openPayOrderModal(order) {
        this.currentPayingOrder = order;
        this.payingMethod = 'cash';

        document.getElementById('payOrderHiddenId').value = order.id || 0;
        document.getElementById('payOrderHiddenNum').value = order.order_number || '';
        document.getElementById('payOrderModalPlate').textContent = order.plate_number || '--';
        document.getElementById('payOrderModalNum').textContent = `#${order.order_number || ''}`;

        const subtotal = parseFloat(order.subtotal_usd || order.total_usd || 0);
        document.getElementById('payOrderModalSubtotal').textContent = `$${subtotal.toFixed(2)}`;

        const discountInput = document.getElementById('payOrderModalDiscount');
        discountInput.value = parseFloat(order.discount_usd || 0).toFixed(2);

        // Reset inputs
        const tenderInput = document.getElementById('payOrderModalTendered');
        if (tenderInput) tenderInput.value = '';
        const refInput = document.getElementById('payOrderModalRef');
        if (refInput) refInput.value = '';

        // Check bank account availability (Requirement 1)
        const settings = this.getSettings();
        const hasBank = !!((settings.aba_account_num && settings.aba_account_num.trim()) || (settings.aba_account_name && settings.aba_account_name.trim()));
        const btnAba = document.getElementById('btnPayModalAba');
        const btnKhqr = document.getElementById('btnPayModalKhqr');
        const noBankNotice = document.getElementById('payOrderNoBankNotice');

        if (btnAba) btnAba.style.display = hasBank ? '' : 'none';
        if (btnKhqr) btnKhqr.style.display = hasBank ? '' : 'none';
        if (noBankNotice) noBankNotice.style.display = hasBank ? 'none' : 'flex';

        // Reset payment method buttons
        document.querySelectorAll('.pay-modal-method-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('btnPayModalCash')?.classList.add('active');
        const cashRow = document.getElementById('payModalCashRow');
        const refRow = document.getElementById('payModalRefRow');
        const abaBox = document.getElementById('payModalAbaBox');
        const khqrBox = document.getElementById('payModalKhqrBox');

        if (cashRow) cashRow.style.display = 'block';
        if (refRow) refRow.style.display = 'none';
        if (abaBox) abaBox.style.display = 'none';
        if (khqrBox) khqrBox.style.display = 'none';

        I18nManager.applyToDOM();
        this.updatePayOrderCalculations();
        document.getElementById('modalPayOrder')?.classList.add('open');
    }

    static updatePayOrderCalculations() {
        if (!this.currentPayingOrder) return;
        const subtotal = parseFloat(this.currentPayingOrder.subtotal_usd || this.currentPayingOrder.total_usd || 0);
        const discount = Math.max(0, parseFloat(document.getElementById('payOrderModalDiscount')?.value || 0));
        const finalTotalUsd = Math.max(0, subtotal - discount);
        const rate = this.getExchangeRate();

        document.getElementById('payOrderModalFinalUsd').textContent = `$${finalTotalUsd.toFixed(2)}`;
        document.getElementById('payOrderModalFinalKhr').textContent = `${Math.round(finalTotalUsd * rate).toLocaleString()} ៛`;

        const tendered = parseFloat(document.getElementById('payOrderModalTendered')?.value) || finalTotalUsd;
        const change = Math.max(0, tendered - finalTotalUsd);
        document.getElementById('payOrderModalChange').textContent = `$${change.toFixed(2)}`;
    }

    static async handleConfirmPayOrderSubmit() {
        if (!this.currentPayingOrder) return;
        const btn = document.getElementById('btnConfirmPayOrderSubmit');
        btn.disabled = true;
        btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Processing...`;

        const orderId = parseInt(document.getElementById('payOrderHiddenId').value) || 0;
        const orderNumber = document.getElementById('payOrderHiddenNum').value;
        const subtotal = parseFloat(this.currentPayingOrder.subtotal_usd || this.currentPayingOrder.total_usd || 0);
        // Requirement 4: Cap discounts
        const rawDiscount = parseFloat(document.getElementById('payOrderModalDiscount').value || 0);
        const discount = Math.min(subtotal, Math.max(0, isNaN(rawDiscount) ? 0 : rawDiscount));
        const finalTotal = Math.max(0, subtotal - discount);

        // Requirement 4: Cash validation
        let tendered = finalTotal;
        let change = 0;
        if (this.payingMethod === 'cash') {
            const rawTendered = document.getElementById('payOrderModalTendered').value.trim();
            if (rawTendered === '' || isNaN(parseFloat(rawTendered))) {
                btn.disabled = false;
                btn.innerHTML = `<i class="fa-solid fa-circle-check"></i> Confirm Payment`;
                alert(I18nManager.t('cashTenderEmpty') || 'Please enter cash amount tendered');
                document.getElementById('payOrderModalTendered').focus();
                return;
            }
            tendered = parseFloat(rawTendered);
            if (tendered < (finalTotal - 0.009)) {
                btn.disabled = false;
                btn.innerHTML = `<i class="fa-solid fa-circle-check"></i> Confirm Payment`;
                alert(I18nManager.t('cashTenderInsufficient') || 'Cash tendered is less than the total order amount');
                document.getElementById('payOrderModalTendered').focus();
                return;
            }
            change = Math.max(0, tendered - finalTotal);
        }
        const ref = (this.payingMethod !== 'cash') ? document.getElementById('payOrderModalRef').value.trim() : '';

        try {
            const res = await PosApi.syncOrderStatus(orderId, orderNumber, 'paid', this.payingMethod, {
                discount_usd: discount,
                amount_tendered_usd: tendered,
                change_usd: change,
                payment_ref: ref
            });

            if (res.success) {
                document.getElementById('modalPayOrder')?.classList.remove('open');
                alert(`Order #${orderNumber} marked as Paid!`);

                // Update local order storage
                const orders = PosStorage.getSyncedOrders();
                let target = orders.find(o => o.order_number === orderNumber);
                if (!target) {
                    target = { ...this.currentPayingOrder };
                }
                target.status = 'paid';
                target.payment_status = 'paid';
                target.is_paid = 1;
                target.discount_usd = discount;
                target.total_usd = finalTotal;
                target.total_khr = Math.round(finalTotal * this.getExchangeRate());
                target.payment_method = this.payingMethod;
                target.amount_tendered_usd = tendered;
                target.change_usd = change;
                target.payment_ref = ref;
                PosStorage.addSyncedOrder(target);

                this.currentReceiptOrder = target;
                this.renderReceipt(target);

                // Acknowledge electronic receipt receipt to backend
                this.sendReceiptAcknowledgement(orderId, orderNumber);

                const cfg = PosConfig.get();
                if (cfg.printerDirectEnabled) {
                    this.executeSilentPrint(target);
                } else {
                    document.getElementById('modalReceipt')?.classList.add('open');
                }

                await this.renderJobQueue();
                await this.renderDailyClose();
            } else {
                const errMsg = res.message || res.error || 'Payment failed';
                const isClosed = res.error === 'DAY_CLOSED' || errMsg.toLowerCase().includes('closed');
                this.showSaleBlockedNotice(errMsg, isClosed);
                return;
            }
        } catch (err) {
            const errMsg = err.data?.message || err.message || 'Payment not allowed';
            const isClosed = err.code === 'DAY_CLOSED' || err.data?.error === 'DAY_CLOSED' || errMsg.toLowerCase().includes('closed');
            this.showSaleBlockedNotice(errMsg, isClosed);
            return;
        } finally {
            btn.disabled = false;
            btn.innerHTML = `<i class="fa-solid fa-check"></i> <span>បញ្ចប់ការទូទាត់ (Confirm Payment)</span>`;
        }
    }

    // ========================================================
    // CANCEL ORDER MODAL (Requirement: cancel and restore stock)
    // ========================================================
    static targetCancelOrder = null;

    static openCancelOrderModal(orderId, orderNumber, plate, isPaid = false) {
        // Requirement 8: Offline block
        if (!this.isOnline || !navigator.onLine) {
            this.showAppAlert('Offline voids and refunds are strictly prohibited. Please record this in the paper logbook signed by the Manager.', 'warning', 'Offline - Use Manual Mode');
            return;
        }

        this.targetCancelOrder = { id: orderId, order_number: orderNumber, plate, isPaid };
        const prompt = document.getElementById('cancelOrderPromptText');
        if (prompt) {
            prompt.textContent = `តើអ្នកពិតជាចង់បោះបង់ការកុម្ម៉ង់ #${orderNumber} (${plate}) មែនទេ?`;
        }
        const reasonInput = document.getElementById('cancelReasonInput');
        if (reasonInput) reasonInput.value = '';
        document.getElementById('modalCancelOrder')?.classList.add('open');
    }

    static async handleConfirmCancelOrder() {
        if (!this.targetCancelOrder) return;
        const btn = document.getElementById('btnConfirmCancelOrder');

        // Requirement 13: Reason required (at least 5 characters)
        const reason = document.getElementById('cancelReasonInput')?.value.trim();
        if (!reason || reason.length < 5) {
            alert(I18nManager.t('cancelReasonRequired') || 'Cancellation reason is required and must be at least 5 characters.');
            document.getElementById('cancelReasonInput')?.focus();
            return;
        }

        // Physical Manager PIN authorization on screen
        document.getElementById('modalCancelOrder')?.classList.remove('open');
        this.requestManagerPin(
            "Manager Void Authorization",
            `Enter Manager PIN to authorize voiding order #${this.targetCancelOrder.order_number}`,
            async (mgr) => {
                try {
                    const res = await PosApi.cancelOrder(
                        this.targetCancelOrder.id,
                        this.targetCancelOrder.order_number,
                        reason,
                        mgr.username,
                        mgr.pin
                    );
                    if (res && res.success) {
                        const successMsg = I18nManager.t('orderCancelledSuccess') || `Order #${this.targetCancelOrder.order_number} cancelled and product stock returned!`;
                        alert(successMsg);
                        await this.refreshCatalogSilently(false);
                        await this.renderJobQueue();
                        await this.renderTodayOrders();
                        await this.renderDailyClose();
                    } else {
                        alert(res?.message || res?.error || 'Cancellation failed');
                    }
                } catch (err) {
                    alert(err.data?.message || err.data?.error || err.message || 'Cancellation failed');
                }
            }
        );
    }

    // ========================================================
    // CHANGE CREW MODAL (Requirement: re-assign crew)
    // ========================================================
    static openChangeCrewModal(orderId, orderNumber, plate, washerIdsStr, mechanicIdsStr) {
        document.getElementById('crewOrderId').value = orderId || 0;
        document.getElementById('crewOrderNumber').value = orderNumber || '';
        document.getElementById('crewOrderNum').textContent = `#${orderNumber}`;
        document.getElementById('crewPlate').textContent = plate;

        const currentWashers = washerIdsStr ? String(washerIdsStr).split(',').map(s => parseInt(s.trim())) : [];
        const currentMechanics = mechanicIdsStr ? String(mechanicIdsStr).split(',').map(s => parseInt(s.trim())) : [];

        const catalog = PosStorage.getCatalog();
        const washers = catalog?.washers || [];
        const mechanics = catalog?.mechanics || [];

        const wListEl = document.getElementById('crewWashersList');
        if (wListEl) {
            let wHtml = '';
            washers.forEach(w => {
                const isChecked = currentWashers.includes(w.id) ? 'checked' : '';
                wHtml += `
                    <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.88rem; cursor: pointer;">
                        <input type="checkbox" name="crewWasherCheck" value="${w.id}" ${isChecked}>
                        <span>${this.escapeHtml(w.full_name)} (${w.commission_type === 'percentage' ? w.commission_rate + '%' : '$' + w.commission_rate})</span>
                    </label>
                `;
            });
            wListEl.innerHTML = wHtml || '<div style="color: var(--text-muted); font-size: 0.82rem;">No washers registered</div>';
        }

        const mListEl = document.getElementById('crewMechanicsList');
        if (mListEl) {
            let mHtml = '';
            mechanics.forEach(m => {
                const isChecked = currentMechanics.includes(m.id) ? 'checked' : '';
                mHtml += `
                    <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.88rem; cursor: pointer;">
                        <input type="checkbox" name="crewMechanicCheck" value="${m.id}" ${isChecked}>
                        <span>${this.escapeHtml(m.full_name)} (${m.commission_type === 'percentage' ? m.commission_rate + '%' : '$' + m.commission_rate})</span>
                    </label>
                `;
            });
            mListEl.innerHTML = mHtml || '<div style="color: var(--text-muted); font-size: 0.82rem;">No mechanics registered</div>';
        }

        document.getElementById('modalChangeCrew')?.classList.add('open');
    }

    static async handleSaveChangeCrew() {
        const btn = document.getElementById('btnSaveChangeCrewModal');
        btn.disabled = true;
        btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Saving...`;

        const orderId = parseInt(document.getElementById('crewOrderId').value) || 0;
        const orderNumber = document.getElementById('crewOrderNumber').value;

        const selectedWashers = Array.from(document.querySelectorAll('input[name="crewWasherCheck"]:checked')).map(cb => parseInt(cb.value));
        const selectedMechanics = Array.from(document.querySelectorAll('input[name="crewMechanicCheck"]:checked')).map(cb => parseInt(cb.value));

        try {
            const res = await PosApi.assignCrew(orderId, orderNumber, selectedWashers, selectedMechanics);
            if (res.success) {
                document.getElementById('modalChangeCrew')?.classList.remove('open');
                alert(`Crew updated for Order #${orderNumber}!`);
                await this.renderJobQueue();
            } else {
                alert(res.message || 'Failed to update crew');
            }
        } catch (err) {
            alert(err.data?.message || err.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = `<i class="fa-solid fa-check"></i> <span>រក្សាទុក (Save Changes)</span>`;
        }
    }

    // ========================================================
    // OIL DUE SOON REMINDERS (Requirement 15, 16, 17)
    // ========================================================
    static currentOilFilter = 'all';

    static async renderOilReminders() {
        const tbody = document.getElementById('oilRemindersTableBody');
        if (!tbody) return;

        const errBanner = document.getElementById('oilErrorBanner');
        const offBanner = document.getElementById('oilOfflineBanner');
        if (errBanner) errBanner.style.display = 'none';
        if (offBanner) offBanner.style.display = 'none';

        tbody.innerHTML = `<tr><td colspan="9" style="text-align: center; padding: 2rem; color: var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Loading oil reminders...</td></tr>`;

        const search = document.getElementById('oilSearchInput')?.value?.trim() || '';
        const filter = this.currentOilFilter || 'all';

        let reminders = [];
        try {
            const res = await PosApi.getOilReminders(filter, search);
            if (res && res.success && Array.isArray(res.reminders)) {
                reminders = res.reminders;
                PosStorage.setOilRemindersCache(reminders, res.synced_at || new Date().toISOString());
                const updatedEl = document.getElementById('oilLastUpdatedText');
                if (updatedEl) {
                    const timeStr = (res.synced_at || new Date().toISOString()).substring(11, 16);
                    updatedEl.innerHTML = `<span data-i18n="oilLastUpdated">${I18nManager.t('oilLastUpdated') || 'Updated:'}</span> ${timeStr}`;
                }
            } else {
                throw new Error(res?.message || 'Failed to fetch oil reminders');
            }
        } catch (err) {
            console.warn('Oil reminders fetch failed, using cache', err);
            const cache = PosStorage.getOilRemindersCache();
            reminders = cache.reminders || [];
            if (offBanner) offBanner.style.display = 'flex';
            const updatedEl = document.getElementById('oilLastUpdatedText');
            if (updatedEl && cache.updatedAt) {
                updatedEl.innerHTML = `<span data-i18n="oilLastUpdated">${I18nManager.t('oilLastUpdated') || 'Updated:'}</span> ${cache.updatedAt.substring(11, 16)}`;
            }
        }

        if (reminders.length === 0) {
            const noRemMsg = I18nManager.t('noOilDue') || 'No vehicles currently due or overdue for oil change.';
            tbody.innerHTML = `<tr><td colspan="9" style="text-align: center; padding: 2rem; color: var(--text-muted);">${noRemMsg}</td></tr>`;
            return;
        }

        let html = '';
        reminders.forEach(r => {
            const isOverdue = r.reminder_status === 'overdue' || (r.days_remaining !== null && r.days_remaining < 0);
            const days = Math.abs(r.days_remaining || 0);
            const badge = isOverdue
                ? `<span class="status-badge overdue"><i class="fa-solid fa-triangle-exclamation"></i> ${I18nManager.t('oilFilterOverdue') || 'OVERDUE'} (${days}d)</span>`
                : `<span class="status-badge duesoon"><i class="fa-solid fa-clock"></i> ${I18nManager.t('oilFilterDueSoon') || 'DUE SOON'} (${days}d)</span>`;

            const phoneLink = r.customer_phone
                ? `<a href="tel:${this.escapeHtml(r.customer_phone)}" style="color: var(--primary); font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 0.35rem;"><i class="fa-solid fa-phone" style="font-size: 0.75rem;"></i> ${this.escapeHtml(r.customer_phone)}</a>`
                : '<span style="color: var(--text-muted);">-</span>';

            const lastMileageStr = r.last_mileage ? Number(r.last_mileage).toLocaleString() + ' km' : '-';
            const nextMileageStr = r.next_oil_mileage ? Number(r.next_oil_mileage).toLocaleString() + ' km' : '-';
            const contactedInfo = r.last_contacted_at ? `<div style="font-size: 0.72rem; color: #059669; margin-top: 2px;"><i class="fa-solid fa-circle-check"></i> ${this.escapeHtml(r.last_contacted_at.substring(5, 16))}</div>` : '';

            html += `
                <tr>
                    <td><span class="plate-pill">${this.escapeHtml(r.plate_number)}</span></td>
                    <td style="font-weight: 700; color: var(--text-main);">${this.escapeHtml(r.customer_name || 'Valued Customer')}${contactedInfo}</td>
                    <td>${phoneLink}</td>
                    <td style="color: var(--text-muted); font-weight: 500;">${this.escapeHtml(r.make_model || 'Engine Oil Service')}</td>
                    <td style="font-weight: 600;">${lastMileageStr}</td>
                    <td style="font-weight: 700; color: var(--primary);">${nextMileageStr}</td>
                    <td style="font-weight: 700; color: var(--text-main);">${this.escapeHtml(r.next_oil_date || '-')}</td>
                    <td>${badge}</td>
                    <td style="text-align: right;">
                        <div class="action-btn-group" style="justify-content: flex-end;">
                            <button type="button" class="btn btn-outline btn-sm" data-oil-action="contacted" data-vehicle-id="${r.vehicle_id}" title="Mark as Contacted">
                                <i class="fa-solid fa-headset"></i> <span data-i18n="oilActionContacted">${I18nManager.t('oilActionContacted') || 'Contacted'}</span>
                            </button>
                            <button type="button" class="btn btn-outline btn-sm" data-oil-action="snooze-7" data-vehicle-id="${r.vehicle_id}" title="Snooze 7 Days">
                                <i class="fa-solid fa-bell-slash"></i> 7d
                            </button>
                            <button type="button" class="btn btn-outline btn-sm" data-oil-action="snooze-30" data-vehicle-id="${r.vehicle_id}" title="Snooze 30 Days">
                                <i class="fa-solid fa-bell-slash"></i> 30d
                            </button>
                            <button type="button" class="btn btn-primary btn-sm" data-oil-action="create-order" data-plate="${this.escapeHtml(r.plate_number)}" data-name="${this.escapeHtml(r.customer_name)}" data-phone="${this.escapeHtml(r.customer_phone)}" data-vtype="${this.escapeHtml(r.vehicle_type)}" data-cust-id="${r.customer_id || ''}" data-veh-id="${r.vehicle_id}" title="Create Order in Register">
                                <i class="fa-solid fa-plus"></i> <span data-i18n="oilActionCreateOrder">${I18nManager.t('oilActionCreateOrder') || 'Order'}</span>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        });
        tbody.innerHTML = html;

        tbody.onclick = async (e) => {
            const btn = e.target.closest('[data-oil-action]');
            if (!btn) return;
            const action = btn.getAttribute('data-oil-action');
            const vehId = parseInt(btn.getAttribute('data-vehicle-id') || 0);

            if (action === 'contacted') {
                btn.disabled = true;
                try {
                    await PosApi.updateOilContact(vehId, 'contacted', 'Customer contacted by staff');
                    this.showToastNotice(I18nManager.t('oilContactedSuccess') || 'Contact recorded successfully', 'success');
                    await this.renderOilReminders();
                } catch (err) {
                    alert(err.message);
                    btn.disabled = false;
                }
            } else if (action === 'snooze-7') {
                btn.disabled = true;
                try {
                    await PosApi.updateOilContact(vehId, 'snooze_7', 'Snoozed 7 days');
                    this.showToastNotice(I18nManager.t('oilSnoozedSuccess') || 'Reminder snoozed 7 days', 'success');
                    await this.renderOilReminders();
                } catch (err) {
                    alert(err.message);
                    btn.disabled = false;
                }
            } else if (action === 'snooze-30') {
                btn.disabled = true;
                try {
                    await PosApi.updateOilContact(vehId, 'snooze_30', 'Snoozed 30 days');
                    this.showToastNotice(I18nManager.t('oilSnoozedSuccess') || 'Reminder snoozed 30 days', 'success');
                    await this.renderOilReminders();
                } catch (err) {
                    alert(err.message);
                    btn.disabled = false;
                }
            } else if (action === 'create-order') {
                const plate = btn.getAttribute('data-plate');
                const name = btn.getAttribute('data-name');
                const phone = btn.getAttribute('data-phone');
                const vtype = btn.getAttribute('data-vtype') || 'small_car';
                const custId = parseInt(btn.getAttribute('data-cust-id')) || null;
                const vehId = parseInt(btn.getAttribute('data-veh-id')) || null;

                this.switchView('pos');
                this.selectCustomer(plate, name, phone, vtype, custId, vehId);
            }
        };
    }

    // ========================================================
    // DAILY CLOSE & SHIFT REPORT (Requirements 18, 19, 20, 21)
    // ========================================================
    static async renderDailyClose() {
        const rate = this.getExchangeRate();
        const dateInput = document.getElementById('closeDateFilter');
        const todayStr = new Date().toISOString().substring(0, 10);
        if (dateInput && !dateInput.value) {
            dateInput.value = todayStr;
        }
        const selectedDate = dateInput ? dateInput.value : todayStr;

        const deviceSelect = document.getElementById('closeDeviceFilter');
        const selectedDeviceId = deviceSelect ? parseInt(deviceSelect.value || 0) : 0;

        const offlineBanner = document.getElementById('closeOfflineBanner');
        const statusBanner = document.getElementById('closeStatusBanner');
        const statusText = document.getElementById('closeStatusBannerText');
        const btnReopen = document.getElementById('btnReopenDay');
        const btnCloseSubmit = document.getElementById('btnCloseDaySubmit');

        let report = null;
        let isOffline = false;

        try {
            const res = await PosApi.getDailyReport(selectedDate, selectedDeviceId);
            if (res && res.success) {
                report = res;
                this.currentDailyReportData = res;
                PosStorage.setDailyCloseStatus(selectedDate, res.close_record || null);

                if (deviceSelect && deviceSelect.options.length <= 1 && Array.isArray(res.devices)) {
                    deviceSelect.innerHTML = `<option value="0">${I18nManager.t('allDevices') || 'All Devices'}</option>`;
                    res.devices.forEach(d => {
                        const opt = document.createElement('option');
                        opt.value = d.id;
                        opt.textContent = `${d.device_name} (${d.location || 'POS'})`;
                        if (d.id === res.device_id) opt.selected = true;
                        deviceSelect.appendChild(opt);
                    });
                }
            } else {
                throw new Error(res?.message || 'Failed to fetch daily report');
            }
        } catch (err) {
            console.warn('Daily report fetch failed, using local orders fallback', err);
            isOffline = true;
            if (offlineBanner) offlineBanner.style.display = 'flex';

            // Requirement 19: Offline only use local orders from selected date
            const allOrders = PosStorage.getSyncedOrders() || [];
            const dateOrders = allOrders.filter(o => (o.created_at || '').startsWith(selectedDate) || (o.paid_at || '').startsWith(selectedDate));

            let cashSales = 0, abaSales = 0, khqrSales = 0, totalSales = 0, discountTotal = 0;
            let paidCount = 0, unpaidCount = 0, cancelledCount = 0, unpaidSales = 0, cancelledSales = 0;

            dateOrders.forEach(o => {
                const isCancelled = o.status === 'cancelled';
                const isPaid = (o.status === 'paid' || o.payment_status === 'paid') && !isCancelled;
                const amt = parseFloat(o.total_usd || 0);
                const m = (o.payment_method || 'cash').toLowerCase();

                if (isCancelled) {
                    cancelledCount++;
                    cancelledSales += amt;
                } else if (isPaid) {
                    paidCount++;
                    totalSales += amt;
                    if (m === 'cash') cashSales += amt;
                    else if (m === 'aba') abaSales += amt;
                    else if (m === 'khqr') khqrSales += amt;
                } else {
                    unpaidCount++;
                    unpaidSales += amt;
                }
                if (!isCancelled) {
                    discountTotal += parseFloat(o.discount_usd || 0);
                }
            });

            report = {
                success: true,
                is_offline: true,
                report_date: selectedDate,
                total_sales_usd: totalSales,
                total_sales_khr: Math.round(totalSales * rate),
                cash_sales_usd: cashSales,
                cash_sales_khr: Math.round(cashSales * rate),
                aba_sales_usd: abaSales,
                aba_sales_khr: Math.round(abaSales * rate),
                khqr_sales_usd: khqrSales,
                khqr_sales_khr: Math.round(khqrSales * rate),
                expenses_usd: 0,
                expected_cash_usd: cashSales,
                expected_cash_khr: Math.round(cashSales * rate),
                discount_total_usd: discountTotal,
                total_orders: dateOrders.length,
                cancelled_orders: cancelledCount,
                cancelled_sales_usd: cancelledSales,
                paid_orders: paidCount,
                unpaid_orders: unpaidCount,
                unpaid_sales_usd: unpaidSales,
                orders: dateOrders,
                is_closed: !!PosStorage.getDailyCloseStatus(selectedDate),
                close_record: PosStorage.getDailyCloseStatus(selectedDate)
            };
            this.currentDailyReportData = report;
        }

        if (!isOffline && offlineBanner) {
            offlineBanner.style.display = 'none';
        }

        // Render KPI metrics (Requirements 2 & 18: Blind Cashiering for cashier, full breakdown for manager/owner)
        const totalSales = parseFloat(report.total_sales_usd || 0);
        const cashTotal = parseFloat(report.cash_sales_usd || 0);
        const abaTotal = parseFloat(report.aba_sales_usd || 0);
        const khqrTotal = parseFloat(report.khqr_sales_usd || 0);
        const cancelledCount = parseInt(report.cancelled_orders || 0);
        const unpaidCount = parseInt(report.unpaid_orders !== undefined ? report.unpaid_orders : (report.unpaid_orders_count || 0));
        const expectedCash = parseFloat(report.expected_cash_usd !== undefined ? report.expected_cash_usd : cashTotal);

        const isCashier = (this.currentUser?.role || '').toLowerCase() === 'cashier';
        const kpiGrid = document.querySelector('#viewClose .view-kpi-grid');
        let blindNotice = document.getElementById('closeBlindNoticeBanner');

        if (isCashier) {
            // Requirement 2: Blind Cashiering - hide running totals & sales breakdown
            if (kpiGrid) kpiGrid.style.display = 'none';
            if (!blindNotice && kpiGrid) {
                blindNotice = document.createElement('div');
                blindNotice.id = 'closeBlindNoticeBanner';
                blindNotice.className = 'blind-notice-banner';
                blindNotice.innerHTML = `<i class="fa-solid fa-eye-slash" style="color: var(--primary); font-size: 1.3rem;"></i> <div><strong>Blind Cashiering Active (ការរាប់សាច់ប្រាក់ដោយមិនបង្ហាញតួលេខ)</strong><br><span style="font-size: 0.82rem;">Running sales totals, payment breakdowns, and expected drawer totals are restricted to Manager and Owner logins. Please enter your actual counted cash below.</span></div>`;
                kpiGrid.parentNode.insertBefore(blindNotice, kpiGrid);
            }
            if (blindNotice) blindNotice.style.display = 'flex';

            if (document.getElementById('drawerExpectedCashUsd')) {
                document.getElementById('drawerExpectedCashUsd').textContent = '*** BLIND ***';
                document.getElementById('drawerExpectedCashKhr').textContent = '*** BLIND ***';
            }
            if (document.getElementById('drawerDiffUsd')) {
                document.getElementById('drawerDiffUsd').textContent = '*** BLIND ***';
                document.getElementById('drawerDiffKhr').textContent = '*** BLIND ***';
            }
            const statusBadge = document.getElementById('drawerStatusBadge');
            if (statusBadge) statusBadge.style.display = 'none';

            this.currentExpectedCashUsd = 0;
        } else {
            // Manager / Owner logins see all metrics
            if (blindNotice) blindNotice.style.display = 'none';
            if (kpiGrid) kpiGrid.style.display = 'grid';

            document.getElementById('closeTotalSalesUsd').textContent = `$${totalSales.toFixed(2)}`;
            document.getElementById('closeTotalSalesKhr').textContent = `${Math.round(totalSales * rate).toLocaleString()} ៛`;
            document.getElementById('closeCashUsd').textContent = `$${cashTotal.toFixed(2)}`;
            document.getElementById('closeCashKhr').textContent = `${Math.round(cashTotal * rate).toLocaleString()} ៛`;
            document.getElementById('closeAbaUsd').textContent = `$${abaTotal.toFixed(2)}`;
            document.getElementById('closeAbaKhr').textContent = `${Math.round(abaTotal * rate).toLocaleString()} ៛`;
            document.getElementById('closeKhqrUsd').textContent = `$${khqrTotal.toFixed(2)}`;
            document.getElementById('closeKhqrKhr').textContent = `${Math.round(khqrTotal * rate).toLocaleString()} ៛`;

            if (document.getElementById('closeCancelledCount')) {
                document.getElementById('closeCancelledCount').textContent = cancelledCount;
            }
            if (document.getElementById('closeCancelledUsd')) {
                const canUsd = parseFloat(report.cancelled_sales_usd || 0);
                document.getElementById('closeCancelledUsd').textContent = `$${canUsd.toFixed(2)}`;
            }
            if (document.getElementById('closeUnpaidCount')) {
                document.getElementById('closeUnpaidCount').textContent = unpaidCount;
            }
            if (document.getElementById('closeUnpaidUsd')) {
                const unpUsd = parseFloat(report.unpaid_sales_usd || 0);
                document.getElementById('closeUnpaidUsd').textContent = `$${unpUsd.toFixed(2)}`;
            }
            if (document.getElementById('drawerExpectedCashUsd')) {
                document.getElementById('drawerExpectedCashUsd').textContent = `$${expectedCash.toFixed(2)}`;
                document.getElementById('drawerExpectedCashKhr').textContent = `${Math.round(expectedCash * rate).toLocaleString()} ៛`;
            }
            const statusBadge = document.getElementById('drawerStatusBadge');
            if (statusBadge) statusBadge.style.display = 'inline-block';

            this.currentExpectedCashUsd = expectedCash;
            this.calculateDrawerVariance();
        }

        // Requirement 20: Real Close Day enforcement
        const userRole = (this.currentUser?.role || '').toLowerCase();
        if (report.is_closed) {
            this.isDayClosed = true;
            if (statusBanner) {
                statusBanner.style.display = 'flex';
                const rec = report.close_record || {};
                const closedBy = rec.closed_by_name || 'Staff';
                const closedAt = (rec.closed_at || '').substring(11, 16);
                if (statusText) {
                    statusText.innerHTML = `<i class="fa-solid fa-lock"></i> ${I18nManager.t('dayAlreadyClosed') || 'Day is closed'} (${closedBy} @ ${closedAt}). ${I18nManager.t('salesBlockedDayClosed') || 'New sales are blocked.'}`;
                }
            }
            if (btnReopen) {
                btnReopen.style.display = (userRole === 'owner' || userRole === 'manager') ? 'inline-flex' : 'none';
            }
            if (btnCloseSubmit) {
                btnCloseSubmit.disabled = true;
                btnCloseSubmit.style.opacity = '0.6';
            }
        } else {
            this.isDayClosed = false;
            if (statusBanner) statusBanner.style.display = 'none';
            if (btnReopen) btnReopen.style.display = 'none';
            if (btnCloseSubmit) {
                btnCloseSubmit.disabled = false;
                btnCloseSubmit.style.opacity = '1';
            }
        }

        // Requirement 21: Shift orders list with pagination (not only 50)
        this.renderShiftOrdersTable(report.orders || []);
    }

    static renderShiftOrdersTable(allOrders) {
        const tbody = document.getElementById('shiftOrdersTableBody');
        if (!tbody) return;

        const totalOrders = allOrders.length;
        const pageSize = this.closeOrdersPageSize || 15;
        const totalPages = Math.max(1, Math.ceil(totalOrders / pageSize));

        if (this.closeOrdersPage > totalPages) this.closeOrdersPage = totalPages;
        if (this.closeOrdersPage < 1) this.closeOrdersPage = 1;

        const startIdx = (this.closeOrdersPage - 1) * pageSize;
        const pageOrders = allOrders.slice(startIdx, startIdx + pageSize);

        const pageInfoEl = document.getElementById('closeOrdersPageInfo');
        const btnPrev = document.getElementById('btnClosePrevPage');
        const btnNext = document.getElementById('btnCloseNextPage');

        if (pageInfoEl) {
            const tmpl = I18nManager.t('pageInfo') || 'Page {current} of {total} ({count} orders)';
            pageInfoEl.textContent = tmpl.replace('{current}', this.closeOrdersPage).replace('{total}', totalPages).replace('{count}', totalOrders);
        }
        if (btnPrev) btnPrev.disabled = (this.closeOrdersPage <= 1);
        if (btnNext) btnNext.disabled = (this.closeOrdersPage >= totalPages);

        if (pageOrders.length === 0) {
            tbody.innerHTML = `<tr><td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-muted);">No sales recorded in current shift yet.</td></tr>`;
            return;
        }

        let html = '';
        pageOrders.forEach(ord => {
            const isCancelled = ord.status === 'cancelled';
            const isPaid = (ord.status === 'paid' || ord.payment_status === 'paid');
            const totalNum = parseFloat(ord.total_usd || 0);

            html += `
                <tr ${isCancelled ? 'style="opacity: 0.6; background: #fff5f5;"' : ''}>
                    <td style="font-weight: 700; color: var(--text-dark);">${this.escapeHtml(ord.order_number)}</td>
                    <td><span class="plate-pill">${this.escapeHtml(ord.plate_number)}</span></td>
                    <td style="font-weight: 600;">${this.escapeHtml(ord.customer_name || 'Valued Customer')}</td>
                    <td><span class="payment-chip ${(ord.payment_method || 'cash').toLowerCase()}">${this.escapeHtml(ord.payment_method || 'cash')}</span></td>
                    <td style="font-weight: 700; color: var(--primary);">$${totalNum.toFixed(2)}</td>
                    <td style="text-align: right; color: var(--text-muted); font-size: 0.82rem;">${this.escapeHtml((ord.created_at || '').substring(11, 16))}</td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
    }

    // Requirement 18: Print report strictly matching screen data
    static async handlePrintDailyReport() {
        if (this.isFullscreen()) {
            this.wasFullscreenPreferred = true;
        }
        let report = this.currentDailyReportData;
        const selectedDate = document.getElementById('closeDateFilter')?.value || new Date().toISOString().slice(0, 10);
        const selectedDeviceId = parseInt(document.getElementById('closeDeviceFilter')?.value || 0);

        // If report is not yet loaded or doesn't match selected date/device, load it from back office
        if (!report || report.report_date !== selectedDate || (selectedDeviceId > 0 && report.device_id !== selectedDeviceId)) {
            try {
                const res = await PosApi.getDailyReport(selectedDate, selectedDeviceId);
                if (res && res.success) {
                    report = res;
                    this.currentDailyReportData = res;
                }
            } catch (e) {
                console.warn('Could not fetch daily report for print, using offline fallback', e);
            }
        }

        if (!report) {
            // Build offline fallback for selected date
            const rate = this.getExchangeRate();
            const allOrders = PosStorage.getSyncedOrders() || [];
            const dateOrders = allOrders.filter(o => (o.created_at || '').startsWith(selectedDate) || (o.paid_at || '').startsWith(selectedDate));

            let cashSales = 0, abaSales = 0, khqrSales = 0, totalSales = 0, discountTotal = 0;
            let paidCount = 0, unpaidCount = 0, cancelledCount = 0, unpaidSales = 0, cancelledSales = 0;

            dateOrders.forEach(o => {
                const isCancelled = o.status === 'cancelled';
                const isPaid = (o.status === 'paid' || o.payment_status === 'paid') && !isCancelled;
                const amt = parseFloat(o.total_usd || 0);
                const m = (o.payment_method || 'cash').toLowerCase();

                if (isCancelled) {
                    cancelledCount++;
                    cancelledSales += amt;
                } else if (isPaid) {
                    paidCount++;
                    totalSales += amt;
                    if (m === 'cash') cashSales += amt;
                    else if (m === 'aba') abaSales += amt;
                    else if (m === 'khqr') khqrSales += amt;
                } else {
                    unpaidCount++;
                    unpaidSales += amt;
                }
                if (!isCancelled) {
                    discountTotal += parseFloat(o.discount_usd || 0);
                }
            });

            report = {
                success: true,
                is_offline: true,
                report_date: selectedDate,
                total_sales_usd: totalSales,
                total_sales_khr: Math.round(totalSales * rate),
                cash_sales_usd: cashSales,
                cash_sales_khr: Math.round(cashSales * rate),
                aba_sales_usd: abaSales,
                aba_sales_khr: Math.round(abaSales * rate),
                khqr_sales_usd: khqrSales,
                khqr_sales_khr: Math.round(khqrSales * rate),
                expenses_usd: 0,
                expected_cash_usd: cashSales,
                expected_cash_khr: Math.round(cashSales * rate),
                discount_total_usd: discountTotal,
                total_orders: dateOrders.length,
                cancelled_orders: cancelledCount,
                cancelled_sales_usd: cancelledSales,
                paid_orders: paidCount,
                unpaid_orders: unpaidCount,
                unpaid_sales_usd: unpaidSales,
                orders: dateOrders,
                device_name: 'Local Register',
                is_closed: !!PosStorage.getDailyCloseStatus(selectedDate),
                close_record: PosStorage.getDailyCloseStatus(selectedDate)
            };
            this.currentDailyReportData = report;
        }

        this.renderDailyCloseZReport(report);
        document.getElementById('modalReceipt')?.classList.add('open');
    }

    // Cash Drawer Reconciliation
    static currentExpectedCashUsd = 0;

    static calculateDrawerVariance() {
        if ((this.currentUser?.role || '').toLowerCase() === 'cashier') {
            const diffUsdEl = document.getElementById('drawerDiffUsd');
            const diffKhrEl = document.getElementById('drawerDiffKhr');
            const badgeEl = document.getElementById('drawerStatusBadge');
            if (diffUsdEl) diffUsdEl.textContent = '*** BLIND ***';
            if (diffKhrEl) diffKhrEl.textContent = '*** BLIND ***';
            if (badgeEl) badgeEl.style.display = 'none';
            return;
        }

        const rate = this.getExchangeRate();

        const countUsd = parseFloat(document.getElementById('drawerCountUsd')?.value || 0);
        const countKhr = parseFloat(document.getElementById('drawerCountKhr')?.value || 0);
        const actualTotalUsd = countUsd + (countKhr / rate);

        const expectedUsd = this.currentExpectedCashUsd || 0;
        const diffUsd = actualTotalUsd - expectedUsd;
        const diffKhr = Math.round(diffUsd * rate);

        const diffUsdEl = document.getElementById('drawerDiffUsd');
        const diffKhrEl = document.getElementById('drawerDiffKhr');
        const badgeEl = document.getElementById('drawerStatusBadge');

        if (diffUsdEl) {
            const sign = diffUsd > 0.01 ? '+' : '';
            diffUsdEl.textContent = `${sign}$${diffUsd.toFixed(2)}`;
            if (Math.abs(diffUsd) < 0.05) {
                diffUsdEl.style.color = 'var(--success)';
            } else if (diffUsd > 0) {
                diffUsdEl.style.color = '#0284c7';
            } else {
                diffUsdEl.style.color = '#dc2626';
            }
        }

        if (diffKhrEl) {
            const sign = diffKhr > 0 ? '+' : '';
            diffKhrEl.textContent = `${sign}${diffKhr.toLocaleString()} ៛`;
        }

        if (badgeEl) {
            const lang = I18nManager.getLang();
            if (Math.abs(diffUsd) < 0.05) {
                badgeEl.textContent = lang === 'km' ? 'គ្រប់ចំនួន' : 'Balanced';
                badgeEl.style.background = '#ecfdf5';
                badgeEl.style.color = '#047857';
                badgeEl.style.borderColor = '#a7f3d0';
            } else if (diffUsd > 0) {
                badgeEl.textContent = lang === 'km' ? `លើស $${diffUsd.toFixed(2)}` : `Over +$${diffUsd.toFixed(2)}`;
                badgeEl.style.background = '#e0f2fe';
                badgeEl.style.color = '#0369a1';
                badgeEl.style.borderColor = '#bae6fd';
            } else {
                badgeEl.textContent = lang === 'km' ? `ខ្វះ $${Math.abs(diffUsd).toFixed(2)}` : `Short -$${Math.abs(diffUsd).toFixed(2)}`;
                badgeEl.style.background = '#fef2f2';
                badgeEl.style.color = '#b91c1c';
                badgeEl.style.borderColor = '#fecaca';
            }
        }
    }

    // Requirement 6: Close Day with Manager PIN (Z-Report)
    static async closeDaySubmit() {
        const countUsd = parseFloat(document.getElementById('drawerCountUsd')?.value || 0);
        const countKhr = parseFloat(document.getElementById('drawerCountKhr')?.value || 0);
        const notes = document.getElementById('drawerNotes')?.value.trim() || '';

        this.requestManagerPin(
            "Close Day (Z-Report)",
            "Enter Manager PIN to authorize closing the business day. New sales will be locked.",
            async (mgr) => {
                try {
                    const res = await PosApi.closeDayZReport(mgr.username, mgr.pin, countUsd, countKhr, notes);
                    this.lastCloseDayResult = res;
                    if (res && res.success) {
                        this.isDayClosed = true;
                        this.isDayLocked = true;
                        this.updateDayLockUI(true, `Sales are locked. Day closed by Manager ${res.manager_name || mgr.username}.`);
                        this.showToastNotice('Daily Z-Report closed successfully! Register locked.', 'success');
                        await this.renderDailyClose();
                    } else {
                        this.showAppAlert(res?.error || 'Failed to close day', 'danger');
                    }
                } catch (err) {
                    this.lastCloseDayError = err.data?.message || err.message;
                    this.showAppAlert(err.data?.message || err.message, 'danger');
                }
            }
        );
    }

    // Requirement 6: Reopen day (Manager PIN required)
    static async reopenDaySubmit() {
        const dateInput = document.getElementById('closeDateFilter');
        const selectedDate = dateInput?.value || new Date().toISOString().substring(0, 10);

        this.requestManagerPin(
            "Reopen Business Day",
            "Enter Manager PIN to reopen the closed day and resume sales.",
            async (mgr) => {
                try {
                    const res = await PosApi.reopenDailyClose(selectedDate, 'manager', mgr.id);
                    if (res && res.success) {
                        this.isDayClosed = false;
                        this.isDayLocked = false;
                        this.updateDayLockUI(false);
                        this.showToastNotice('Day reopened successfully! Sales resumed.', 'success');
                        await this.renderDailyClose();
                    } else {
                        this.showAppAlert(res?.message || 'Failed to reopen day', 'danger');
                    }
                } catch (err) {
                    this.showAppAlert(err.data?.message || err.message, 'danger');
                }
            }
        );
    }

    static saveDrawerAudit() {
        this.closeDaySubmit();
    }

        static handleMileageChange(val) {
        const mileage = parseInt(val);
        const reminderBox = document.getElementById('cartOilReminderBox');
        const nextMileageInput = document.getElementById('cartNextOilMileageInput');
        const nextDateInput = document.getElementById('cartNextOilDateInput');

        if (!mileage || mileage <= 0) {
            if (reminderBox) reminderBox.style.display = 'none';
            if (nextMileageInput) nextMileageInput.value = '';
            if (nextDateInput) nextDateInput.value = '';
            return;
        }

        const settings = this.getSettings();
        const defaultKm = parseInt(settings.default_oil_mileage) || 5000;
        const defaultMonths = parseInt(settings.default_oil_months) || 3;

        const nextMileage = mileage + defaultKm;
        const d = new Date();
        d.setMonth(d.getMonth() + defaultMonths);
        const nextDateStr = d.toISOString().substring(0, 10);

        if (nextMileageInput) nextMileageInput.value = nextMileage;
        if (nextDateInput) nextDateInput.value = nextDateStr;
        if (reminderBox) reminderBox.style.display = 'block';
    }

    // ========================================================
    // TODAY'S ORDERS VIEW (Requirement 8)
    // ========================================================
    static currentTodayOrdersList = [];
    static currentOrderFilter = 'all';

    static async renderTodayOrders(search = '', filter = 'all', useCached = false) {
        const tbody = document.getElementById('todayOrdersTableBody');
        if (!tbody) return;

        const errBanner = document.getElementById('ordersErrorBanner');
        if (errBanner) errBanner.style.display = 'none';

        // Requirement 10: Date picker & timezone date
        const dateInput = document.getElementById('ordersDateFilter');
        const todayStr = new Date().toISOString().substring(0, 10);
        if (dateInput && !dateInput.value) {
            dateInput.value = todayStr;
        }
        const selectedDate = dateInput ? dateInput.value : todayStr;

        let orders = [];
        if (useCached && this.currentTodayOrdersList && this.currentTodayOrdersList.length > 0) {
            orders = this.currentTodayOrdersList;
            if (search) {
                const s = search.toLowerCase();
                orders = orders.filter(o => (o.plate_number || '').toLowerCase().includes(s) || (o.customer_name || '').toLowerCase().includes(s) || (o.order_number || '').toLowerCase().includes(s));
            }
        } else {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 2rem; color: var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Loading orders...</td></tr>`;

            try {
                const res = await PosApi.getTodayOrders(search, selectedDate);
                if (res && res.success && Array.isArray(res.orders)) {
                    orders = res.orders;
                    this.currentTodayOrdersList = orders;
                    PosStorage.setTodayOrders(orders);
                } else {
                    throw new Error(res?.message || 'Failed to fetch orders');
                }
            } catch (e) {
                console.warn('Could not fetch orders from back office, using local storage fallback', e);
                // Requirement 12: Tell user if back office returns an error instead of silently showing old data
                if (errBanner) {
                    const errText = document.getElementById('ordersErrorText');
                    if (errText) errText.textContent = e.data?.message || e.message || I18nManager.t('ordersLoadError') || 'Failed to fetch orders from Back Office';
                    errBanner.style.display = 'flex';
                }
                // Requirement 12: Offline fallback filtered to chosen date
                const localOrders = PosStorage.getSyncedOrders() || [];
                orders = localOrders.filter(o => {
                    const cDate = (o.created_at || '').substring(0, 10);
                    const pDate = (o.paid_at || '').substring(0, 10);
                    const isUnpaidOpen = (o.payment_status === 'unpaid' || o.status === 'unpaid') && o.status !== 'cancelled' && selectedDate === todayStr;
                    return cDate === selectedDate || pDate === selectedDate || isUnpaidOpen;
                });
                if (search) {
                    const s = search.toLowerCase();
                    orders = orders.filter(o => (o.plate_number || '').toLowerCase().includes(s) || (o.customer_name || '').toLowerCase().includes(s) || (o.order_number || '').toLowerCase().includes(s));
                }
                this.currentTodayOrdersList = orders;
            }
        }

        // Requirement 11: Paid/unpaid badge fix (only paid if status/payment_status is paid)
        const allCount = orders.length;
        const paidCount = orders.filter(o => (o.status === 'paid' || o.payment_status === 'paid') && o.status !== 'cancelled').length;
        const unpaidCount = orders.filter(o => (o.status !== 'paid' && o.payment_status !== 'paid') && o.status !== 'cancelled').length;
        const cancelledCount = orders.filter(o => o.status === 'cancelled').length;

        document.getElementById('countAllOrders') && (document.getElementById('countAllOrders').textContent = allCount);
        document.getElementById('countPaidOrders') && (document.getElementById('countPaidOrders').textContent = paidCount);
        document.getElementById('countUnpaidOrders') && (document.getElementById('countUnpaidOrders').textContent = unpaidCount);
        document.getElementById('countCancelledOrders') && (document.getElementById('countCancelledOrders').textContent = cancelledCount);

        let filtered = orders;
        if (filter === 'paid') {
            filtered = orders.filter(o => (o.status === 'paid' || o.payment_status === 'paid') && o.status !== 'cancelled');
        } else if (filter === 'unpaid') {
            filtered = orders.filter(o => (o.status !== 'paid' && o.payment_status !== 'paid') && o.status !== 'cancelled');
        } else if (filter === 'cancelled') {
            filtered = orders.filter(o => o.status === 'cancelled');
        }

        const totalFiltered = filtered.length;
        const pageSize = this.ordersPageSize || 25;
        const totalPages = Math.max(1, Math.ceil(totalFiltered / pageSize));

        if (this.ordersPage > totalPages) this.ordersPage = totalPages;
        if (this.ordersPage < 1) this.ordersPage = 1;

        if (totalFiltered === 0) {
            const noMsg = I18nManager.t('noOrdersFound') || 'No orders found matching this filter.';
            tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 2rem; color: var(--text-muted);">${noMsg}</td></tr>`;
            const pageInfoEl = document.getElementById('todayOrdersPageInfo');
            if (pageInfoEl) pageInfoEl.textContent = `${I18nManager.t('showingOrders') || 'Showing'} 0-0 ${I18nManager.t('ofTotal') || 'of'} 0`;
            const pageNumEl = document.getElementById('todayOrdersPageNumber');
            if (pageNumEl) pageNumEl.textContent = `${I18nManager.t('pageWord') || 'Page'} 1 / 1`;
            const prevBtn = document.getElementById('btnOrdersPrevPage');
            const nextBtn = document.getElementById('btnOrdersNextPage');
            const loadMoreBtn = document.getElementById('btnOrdersLoadMore');
            const showAllBtn = document.getElementById('btnOrdersShowAll');
            if (prevBtn) prevBtn.disabled = true;
            if (nextBtn) nextBtn.disabled = true;
            if (loadMoreBtn) loadMoreBtn.style.display = 'none';
            if (showAllBtn) showAllBtn.style.display = 'none';
            return;
        }

        const startIdx = (this.ordersPage - 1) * pageSize;
        const pageOrders = filtered.slice(startIdx, startIdx + pageSize);

        let html = '';
        const isCashier = (this.currentUser?.role || '').toLowerCase() === 'cashier';
        pageOrders.forEach(ord => {
            const isCancelled = (ord.status === 'cancelled');
            const isPaid = (ord.status === 'paid' || ord.payment_status === 'paid') && !isCancelled;
            const isPendingVerification = (ord.payment_status === 'pending_verification') && !isCancelled;
            const isUnpaid = !isPaid && !isCancelled && !isPendingVerification;

            // Merged Status Badge with payment method (Requirement 4: Pending Verification)
            let badgeHtml = '';
            if (isCancelled) {
                badgeHtml = `<span class="status-badge cancelled"><i class="fa-solid fa-ban"></i> ${I18nManager.t('statusCancelled') || 'Cancelled'}</span>`;
            } else if (isPendingVerification) {
                const rawMethod = (ord.payment_method || 'aba').toUpperCase();
                badgeHtml = `<span class="status-badge pending-verification"><i class="fa-solid fa-hourglass-half"></i> Pending Verification (${rawMethod})</span>`;
            } else if (isPaid) {
                const rawMethod = (ord.payment_method || 'cash').toLowerCase();
                let methodLabel = 'Cash';
                if (rawMethod === 'cash') methodLabel = I18nManager.t('cash') || 'Cash';
                else if (rawMethod === 'aba') methodLabel = 'ABA';
                else if (rawMethod === 'khqr') methodLabel = 'KHQR';
                else methodLabel = rawMethod.toUpperCase();

                const paidWord = I18nManager.t('statusPaid') || 'Paid';
                badgeHtml = `<span class="status-badge paid"><i class="fa-solid fa-circle-check"></i> ${paidWord} (${methodLabel})</span>`;
            } else {
                badgeHtml = `<span class="status-badge unpaid"><i class="fa-solid fa-clock"></i> ${I18nManager.t('statusUnpaid') || 'Unpaid'}</span>`;
            }

            // Plate vs Walk-in Neutral Tag
            const rawPlate = (ord.plate_number || '').trim();
            const isWalkIn = !rawPlate || /^(walk[\s\-_]?in|none|n\/a|-)$/i.test(rawPlate);
            const plateHtml = isWalkIn
                ? `<span class="plate-pill-neutral"><i class="fa-solid fa-person-walking"></i> ${this.escapeHtml(rawPlate || 'WALK-IN')}</span>`
                : `<span class="plate-pill"><i class="fa-solid fa-car-side" style="font-size: 0.72rem; opacity: 0.75;"></i> ${this.escapeHtml(rawPlate)}</span>`;

            const totalNum = parseFloat(ord.total_usd || 0);

            html += `
                <tr ${isCancelled ? 'style="opacity: 0.6; background: #fff5f5;"' : ''}>
                    <td style="font-weight: 700; color: var(--text-main); white-space: nowrap;">
                        <div style="font-size: 0.88rem; font-weight: 700; letter-spacing: -0.2px; white-space: nowrap;">${this.escapeHtml(ord.order_number)}</div>
                        <div style="font-size: 0.72rem; color: #94a3b8; font-weight: 500; margin-top: 3px; display: flex; align-items: center; gap: 0.28rem; white-space: nowrap;">
                            <i class="fa-regular fa-clock" style="font-size: 0.65rem;"></i> ${this.escapeHtml((ord.created_at || '').substring(11, 16))}
                        </div>
                    </td>
                    <td style="white-space: nowrap;">${plateHtml}</td>
                    <td style="white-space: nowrap;">
                        <div style="font-weight: 700; font-size: 0.88rem; color: var(--text-main);">${this.formatCustomerName(ord.customer_name)}</div>
                        ${ord.customer_phone ? `
                            <div style="font-size: 0.72rem; color: #94a3b8; font-weight: 500; margin-top: 3px; display: flex; align-items: center; gap: 0.28rem; white-space: nowrap;">
                                <i class="fa-solid fa-phone" style="font-size: 0.62rem;"></i> ${this.escapeHtml(ord.customer_phone)}
                            </div>
                        ` : ''}
                    </td>
                    <td style="font-size: 0.84rem; max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--text-main);" title="${this.escapeHtml(ord.item_names || 'Standard Service')}">
                        ${this.escapeHtml(ord.item_names || 'Standard Service')}
                    </td>
                    <td style="text-align: right; font-weight: 800; font-size: 0.95rem; color: var(--primary); white-space: nowrap;">
                        $${totalNum.toFixed(2)}
                    </td>
                    <td style="white-space: nowrap;">
                        ${badgeHtml}
                        ${(!ord.receipt_printed_at && !isCancelled) ? `<span class="badge" style="background: #f59e0b; color: #fff; font-size: 0.72rem; padding: 2px 6px; border-radius: 4px; display: inline-flex; align-items: center; gap: 3px; margin-left: 4px;"><i class="fa-solid fa-receipt"></i> ${I18nManager.t('receiptPendingBadge') || 'Receipt Pending'}</span>` : ''}
                    </td>
                    <td style="text-align: right; white-space: nowrap;">
                        <div class="action-btn-group" style="justify-content: flex-end;">
                            ${isUnpaid ? `
                                <button type="button" class="btn btn-action-pay" data-action="pay-order" data-order-number="${this.escapeHtml(ord.order_number)}" title="${I18nManager.t('payOrder') || 'Pay Order'}">
                                    <i class="fa-solid fa-dollar-sign"></i> <span>${I18nManager.t('payOrder') || 'Pay'}</span>
                                </button>
                            ` : ''}
                            ${(isPendingVerification && !isCashier) ? `
                                <button type="button" class="btn btn-action-pay" style="background: #d97706; color: white;" data-action="verify-transfer" data-order-id="${ord.id || 0}" data-order-number="${this.escapeHtml(ord.order_number)}" title="Verify & Approve Slip">
                                    <i class="fa-solid fa-check-double"></i> <span>Verify</span>
                                </button>
                            ` : ''}
                            <button type="button" class="btn-icon-action" data-action="reprint" data-order-number="${this.escapeHtml(ord.order_number)}" title="Reprint Receipt">
                                <i class="fa-solid fa-print"></i>
                            </button>
                            <button type="button" class="btn-icon-action" style="color: var(--primary);" data-action="ticket" data-order-number="${this.escapeHtml(ord.order_number)}" title="Print Workshop Ticket">
                                <i class="fa-solid fa-ticket"></i>
                            </button>
                            ${(!isCancelled && !isCashier) ? `
                                <button type="button" class="btn-icon-action btn-icon-danger" data-action="cancel-order" data-order-number="${this.escapeHtml(ord.order_number)}" data-order-id="${ord.id || 0}" data-order-paid="${isPaid ? '1' : '0'}" title="Cancel Order">
                                    <i class="fa-solid fa-ban"></i>
                                </button>
                            ` : ''}
                        </div>
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;

        // Update pagination controls
        const pageInfoEl = document.getElementById('todayOrdersPageInfo');
        const pageNumEl = document.getElementById('todayOrdersPageNumber');
        const prevBtn = document.getElementById('btnOrdersPrevPage');
        const nextBtn = document.getElementById('btnOrdersNextPage');
        const loadMoreBtn = document.getElementById('btnOrdersLoadMore');
        const showAllBtn = document.getElementById('btnOrdersShowAll');

        const isKm = I18nManager.getLang() === 'km';
        const showingWord = I18nManager.t('showingOrders') || (isKm ? 'បង្ហាញ' : 'Showing');
        const ofWord = I18nManager.t('ofTotal') || (isKm ? 'នៃ' : 'of');
        const ordersWord = I18nManager.t('ordersWord') || (isKm ? 'វិក្កយបត្រ' : 'orders');
        const pageWord = I18nManager.t('pageWord') || (isKm ? 'ទំព័រ' : 'Page');

        const endIdx = Math.min(startIdx + pageOrders.length, totalFiltered);

        if (pageInfoEl) {
            pageInfoEl.textContent = `${showingWord} ${totalFiltered > 0 ? (startIdx + 1) : 0}-${endIdx} ${ofWord} ${totalFiltered} ${ordersWord}`;
        }
        if (pageNumEl) {
            pageNumEl.textContent = `${pageWord} ${this.ordersPage} / ${totalPages}`;
        }
        if (prevBtn) prevBtn.disabled = (this.ordersPage <= 1);
        if (nextBtn) nextBtn.disabled = (this.ordersPage >= totalPages);
        if (loadMoreBtn) loadMoreBtn.style.display = (endIdx < totalFiltered) ? 'inline-flex' : 'none';
        if (showAllBtn) showAllBtn.style.display = (totalFiltered > pageSize) ? 'inline-flex' : 'none';

        // Requirement 14: Safe event delegation without inline onclick injection
        tbody.onclick = (e) => {
            const btn = e.target.closest('[data-action]');
            if (!btn) return;
            const action = btn.getAttribute('data-action');
            const num = btn.getAttribute('data-order-number');
            const ord = (this.currentTodayOrdersList || []).find(o => o.order_number === num);

            if (action === 'pay-order' && ord) {
                this.openPayOrderModal(ord);
            } else if (action === 'reprint') {
                this.reprintReceipt(num);
            } else if (action === 'ticket') {
                this.printWasherTicket(num);
            } else if (action === 'verify-transfer') {
                const oId = parseInt(btn.getAttribute('data-order-id')) || (ord?.id || 0);
                this.requestManagerPin(
                    "Verify Bank Transfer",
                    `Enter Manager PIN to approve bank transfer and mark order #${num} as PAID`,
                    async (mgr) => {
                        try {
                            const res = await PosApi.approveBankTransfer(oId, mgr.username, mgr.pin);
                            if (res && res.success) {
                                PosApp.showToastNotice(`Order #${num} verified and approved by Manager ${res.manager_name || mgr.username}!`, 'success');
                                await PosApp.renderTodayOrders();
                            } else {
                                alert(res?.error || 'Verification failed');
                            }
                        } catch (err) {
                            alert(err.message || 'Verification failed');
                        }
                    }
                );
            } else if (action === 'cancel-order') {
                const oId = parseInt(btn.getAttribute('data-order-id')) || 0;
                const isPaid = btn.getAttribute('data-order-paid') === '1';
                this.openCancelOrderModal(oId, num, ord?.plate_number || '', isPaid);
            }
        };
    }

        // ========================================================
    // WASHER DUTY SCREEN (Requirement 13)
    // ========================================================
    // WASHER DUTY SCREEN (Requirement 3: show 3 bays and waiting list)
    // ========================================================
    static async renderWasherScreen() {
        const grid = document.getElementById('washerJobsGrid');
        if (!grid) return;

        const user = PosStorage.getUser();
        if (!user) return;

        // Requirement 3: Fetch bays and queue to populate the 3 bays board & waiting list
        await this.fetchBaysAndQueue(true);

        try {
            const res = await PosApi.getQueue('washer', user.id);
            if (res && res.success) {
                const earned = parseFloat(res.summary?.total_earned || 0);
                const count = parseInt(res.summary?.jobs_count || 0);

                const earnedEl = document.getElementById('washerEarnedToday');
                if (earnedEl) earnedEl.textContent = `$${earned.toFixed(2)}`;

                const subEl = document.getElementById('washerProfileSubtitle');
                if (subEl) subEl.textContent = `${user.full_name} • ${count} completed jobs today`;
            }
        } catch (e) {
            console.warn('Could not load washer commission data', e);
        }

        // Render Washer Waiting List
        const washQueue = this.currentBaysData?.wash_queue || [];
        const wBadge = document.getElementById('washerWaitingBadge');
        if (wBadge) wBadge.textContent = washQueue.length;

        if (washQueue.length === 0) {
            grid.innerHTML = `
                <div style="grid-column: 1/-1; text-align: center; padding: 2.5rem; background: white; border-radius: var(--radius-lg); border: 1.5px dashed var(--border);">
                    <i class="fa-solid fa-hands-bubbles fa-2x" style="color: var(--primary); margin-bottom: 0.5rem;"></i>
                    <p style="font-weight: 700; margin: 0; color: var(--text-muted);">${I18nManager.t('noCarsWaiting') || 'No cars waiting in queue.'}</p>
                </div>
            `;
            return;
        }

        // Check if there are free wash bays
        const freeWashBays = (this.currentBaysData?.bays || []).filter(b => b.bay_type === 'wash' && b.status === 'free');
        const firstFreeBay = freeWashBays.length > 0 ? freeWashBays[0] : null;

        let html = '';
        washQueue.forEach(item => {
            const isNext = (item.position === 1 || item.is_next);
            const posBadge = isNext
                ? `<span class="queue-pos-badge next"><i class="fa-solid fa-bolt"></i> 1st (${I18nManager.t('nextInLine') || 'Next'})</span>`
                : `<span class="queue-pos-badge pos-sub">${item.position_label || (item.position + 'th')}</span>`;

            let actionBtn = '';
            if (firstFreeBay) {
                actionBtn = `
                    <button type="button" class="btn btn-primary btn-sm btn-washer-assign" ${!this.isOnline ? 'disabled title="Offline"' : ''} onclick="PosApp.handleWasherAssignBay(${firstFreeBay.id}, ${item.order_id}, '${this.escapeHtml(item.plate_number)}', '${this.escapeHtml(firstFreeBay.bay_name)}')">
                        <i class="fa-solid fa-play"></i> ${I18nManager.t('putInBay') || 'Put in'} ${this.escapeHtml(firstFreeBay.bay_name)}
                    </button>
                `;
            }

            html += `
                <div class="queue-card" style="${isNext ? 'border: 2px solid #60a5fa; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.12);' : ''}">
                    <div class="queue-card-header">
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <span class="queue-card-plate">${this.escapeHtml(item.plate_number)}</span>
                            ${posBadge}
                        </div>
                        <span class="queue-badge waiting"><i class="fa-solid fa-hourglass-half"></i> ${item.wait_minutes || 0}m wait</span>
                    </div>
                    <div style="font-size: 0.92rem; margin-top: 0.35rem; font-weight: 700;">
                        ${this.escapeHtml(item.service_names || 'Car Wash Service')}
                    </div>
                    <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.2rem;">
                        #${this.escapeHtml(item.order_number)} • Customer: ${this.escapeHtml(item.customer_name || 'Walk-in')} • ${this.escapeHtml(item.vehicle_type || 'Car')}
                    </div>
                    ${actionBtn ? `<div style="margin-top: 0.75rem;">${actionBtn}</div>` : ''}
                </div>
            `;
        });

        grid.innerHTML = html;
    }

    // ========================================================
    // TECHNICAL & MECHANIC SCREEN (Requirements 3: Handover, Tasks, Checklist, Camera, Parts, Badges)
    // ========================================================
    static currentTechData = null;
    static checklistPhotos = {};

    static async renderMechanicScreen() {
        const handoverContainer = document.getElementById('techHandoverList');
        const jobsContainer = document.getElementById('mechanicJobsGrid');
        if (!jobsContainer) return;

        const user = PosStorage.getUser();
        if (!user) return;

        try {
            const res = await PosApi.getTechnicalJobs(user.id);
            if (res && res.success) {
                this.currentTechData = res;

                // Update commission
                const earnedEl = document.getElementById('mechanicEarnedToday');
                if (earnedEl) earnedEl.textContent = `$${parseFloat(res.today_commission || 0).toFixed(2)}`;

                const subEl = document.getElementById('mechanicProfileSubtitle');
                if (subEl) subEl.textContent = `${user.full_name} (Technical & Service Screen)`;

                // 1. Render Handover from Wash Section
                const handoverCars = res.waiting_handover || [];
                const handoverBadge = document.getElementById('techHandoverBadge');
                if (handoverBadge) handoverBadge.textContent = handoverCars.length;

                if (handoverContainer) {
                    if (handoverCars.length === 0) {
                        handoverContainer.innerHTML = `
                            <div style="background: white; border: 1.5px dashed var(--border); border-radius: var(--radius-md); padding: 1.25rem; text-align: center; color: var(--text-muted); font-size: 0.9rem;">
                                <i class="fa-solid fa-soap mr-1" style="color: #0284c7;"></i> No cars currently waiting for handover from wash.
                            </div>
                        `;
                    } else {
                        let hHtml = '';
                        handoverCars.forEach(car => {
                            hHtml += `
                                <div class="tech-handover-card">
                                    <div>
                                        <div style="display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.25rem;">
                                            <span class="bay-plate-badge" style="font-size: 1.1rem; padding: 4px 10px;">${this.escapeHtml(car.plate_number)}</span>
                                            <span class="badge" style="background: #e0f2fe; color: #0369a1; font-weight: 700; border-radius: 4px; padding: 3px 8px;">Wash Finished • Ready for Tech</span>
                                        </div>
                                        <div style="font-size: 0.88rem; font-weight: 700; color: var(--text-main);">${this.escapeHtml(car.service_names || 'Technical Service')}</div>
                                        <div style="font-size: 0.8rem; color: var(--text-muted);">Customer: ${this.escapeHtml(car.customer_name || 'Walk-in')} • ${this.escapeHtml(car.vehicle_type || 'Car')}</div>
                                    </div>
                                    <button type="button" class="btn btn-primary" style="font-weight: 800; padding: 0.65rem 1.25rem; display: inline-flex; align-items: center; gap: 0.5rem;" onclick="PosApp.handleReceiveFromWash(${car.id}, '${this.escapeHtml(car.plate_number)}')">
                                        <i class="fa-solid fa-handshake"></i> Receive from Wash
                                    </button>
                                </div>
                            `;
                        });
                        handoverContainer.innerHTML = hHtml;
                    }
                }

                // 2. Render Active Technical Jobs
                const activeJobs = res.active_jobs || [];
                const activeBadge = document.getElementById('techActiveBadge');
                if (activeBadge) activeBadge.textContent = activeJobs.length;

                if (activeJobs.length === 0) {
                    jobsContainer.innerHTML = `
                        <div style="text-align: center; padding: 3rem; background: white; border-radius: var(--radius-lg); border: 1.5px dashed var(--border);">
                            <i class="fa-solid fa-wrench fa-2x" style="color: #0284c7; margin-bottom: 0.5rem;"></i>
                            <p style="font-weight: 700; margin: 0; color: var(--text-muted);">No active technical jobs in progress. Receive a car from wash or queue to start.</p>
                        </div>
                    `;
                    return;
                }

                let jHtml = '';
                activeJobs.forEach(j => {
                    const ord = j.order;
                    const tasks = j.tasks || [];
                    const checklists = j.checklists || [];
                    const badgeText = j.status_badge || 'Job in Progress';
                    let badgeClass = 'in-progress';
                    let badgeIcon = 'fa-wrench';

                    if (badgeText === 'Waiting for Parts') {
                        badgeClass = 'waiting-parts';
                        badgeIcon = 'fa-hourglass-half';
                    } else if (badgeText === 'Parts Received') {
                        badgeClass = 'parts-received';
                        badgeIcon = 'fa-circle-check';
                    }

                    // Render Tasks HTML
                    let tasksHtml = '';
                    tasks.forEach(t => {
                        const isDone = (t.status === 'completed');
                        const isWorking = (t.status === 'in_progress');
                        let taskAction = '';
                        if (!isDone) {
                            if (!isWorking) {
                                taskAction = `<button type="button" class="btn btn-primary btn-sm" onclick="PosApp.handleStartTechTask(${t.id})"><i class="fa-solid fa-play"></i> Start</button>`;
                            } else {
                                taskAction = `<button type="button" class="btn btn-sm btn-success" style="font-weight: 700;" onclick="PosApp.handleFinishTechTask(${t.id})"><i class="fa-solid fa-check"></i> Finish</button>`;
                            }
                        } else {
                            taskAction = `<span style="font-size: 0.8rem; font-weight: 700; color: var(--success);"><i class="fa-solid fa-circle-check"></i> Completed</span>`;
                        }

                        tasksHtml += `
                            <div class="tech-task-item">
                                <div>
                                    <div style="font-weight: 700; font-size: 0.9rem; color: var(--text-main);">${this.escapeHtml(t.title)}</div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Lane: ${t.lane || 'Lane 1'} • Status: <strong>${t.status}</strong></div>
                                </div>
                                <div>${taskAction}</div>
                            </div>
                        `;
                    });

                    // Render Inspection Checklist HTML
                    let chkHtml = '';
                    checklists.forEach(ci => {
                        const curStatus = ci.status || 'pending';
                        const curVal = ci.measurement_value || '';
                        const photoUrl = ci.photo_url || ci.photo_path || this.checklistPhotos[ci.id] || null;

                        chkHtml += `
                            <div class="checklist-item-card" id="chkCard-${ci.id}">
                                <div class="checklist-item-header">
                                    <div>
                                        <strong style="font-size: 0.88rem; color: var(--text-main);">${this.escapeHtml(ci.item_name)}</strong>
                                        ${ci.is_mandatory ? '<span style="color: #ef4444; font-weight: 800;"> *</span>' : ''}
                                        <div style="font-size: 0.75rem; color: var(--text-muted);">${this.escapeHtml(ci.notes || '')}</div>
                                    </div>
                                    <div class="checklist-status-buttons">
                                        <button type="button" class="btn-chk-status ${curStatus === 'pass' ? 'active-pass' : ''}" onclick="PosApp.setChecklistStatus(${ci.id}, 'pass')">Pass</button>
                                        <button type="button" class="btn-chk-status ${curStatus === 'caution' ? 'active-caution' : ''}" onclick="PosApp.setChecklistStatus(${ci.id}, 'caution')">Caution</button>
                                        <button type="button" class="btn-chk-status ${curStatus === 'fail' ? 'active-fail' : ''}" onclick="PosApp.setChecklistStatus(${ci.id}, 'fail')">Fail</button>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 0.65rem; align-items: center; flex-wrap: wrap; margin-top: 0.4rem;">
                                    <div style="flex: 1; min-width: 130px;">
                                        <input type="text" id="chkVal-${ci.id}" class="form-control" placeholder="Value (e.g. 5mm, 12.6V)..." value="${this.escapeHtml(curVal)}" style="height: 36px; font-size: 0.85rem;">
                                    </div>
                                    <label class="btn btn-outline btn-sm" style="cursor: pointer; height: 36px; display: inline-flex; align-items: center; gap: 0.35rem;">
                                        <i class="fa-solid fa-camera"></i> <span>Photo</span>
                                        <input type="file" accept="image/*" capture="environment" style="display: none;" onchange="PosApp.handleChecklistPhotoCapture(event, ${ci.id})">
                                    </label>
                                    <button type="button" class="btn btn-outline btn-sm" style="height: 36px; font-weight: 700;" onclick="PosApp.handleSaveChecklistItem(${ci.id})">
                                        <i class="fa-solid fa-floppy-disk mr-1"></i> Save
                                    </button>
                                </div>
                                <div id="chkPhotoPreview-${ci.id}" class="checklist-photo-box" style="${photoUrl ? '' : 'display: none;'}">
                                    <img src="${photoUrl || ''}" id="chkPhotoImg-${ci.id}" class="checklist-photo-thumb" alt="Checklist Photo">
                                    <span style="font-size: 0.72rem; color: #16a34a; font-weight: 700;"><i class="fa-solid fa-circle-check"></i> Photo attached</span>
                                </div>
                            </div>
                        `;
                    });

                    jHtml += `
                        <div class="tech-job-card">
                            <div class="tech-job-header">
                                <div>
                                    <div style="display: flex; align-items: center; gap: 0.65rem; margin-bottom: 0.35rem;">
                                        <span class="bay-plate-badge" style="font-size: 1.25rem; padding: 4px 12px;">${this.escapeHtml(ord.plate_number)}</span>
                                        <span class="tech-status-badge ${badgeClass}" id="jobBadge-${ord.id}">
                                            <i class="fa-solid ${badgeIcon}"></i> ${badgeText}
                                        </span>
                                    </div>
                                    <div style="font-size: 0.95rem; font-weight: 700; color: var(--text-main);">${this.escapeHtml(ord.service_names || 'Technical Service')}</div>
                                    <div style="font-size: 0.82rem; color: var(--text-muted); margin-top: 0.2rem;">
                                        Order #${this.escapeHtml(ord.order_number)} • Bay: <strong>${this.escapeHtml(ord.bay_name || 'Tech Bay')}</strong> • Customer: ${this.escapeHtml(ord.customer_name || 'Walk-in')}
                                    </div>
                                </div>
                                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                    <button type="button" class="btn btn-outline btn-sm" style="font-weight: 700; display: inline-flex; align-items: center; gap: 0.4rem;" onclick="PosApp.openRequestPartsModal(${ord.id}, '${this.escapeHtml(ord.plate_number)}')">
                                        <i class="fa-solid fa-boxes-packing text-primary"></i> Request Parts
                                    </button>
                                    <button type="button" class="btn btn-success btn-sm" style="font-weight: 800; display: inline-flex; align-items: center; gap: 0.4rem;" onclick="PosApp.handleFinishTechJob(${ord.id}, '${this.escapeHtml(ord.plate_number)}')">
                                        <i class="fa-solid fa-circle-check"></i> Finish Job
                                    </button>
                                </div>
                            </div>

                            <!-- Tasks List -->
                            <div class="tech-tasks-section">
                                <h4 style="font-size: 0.95rem; font-weight: 800; color: var(--text-main); margin-bottom: 0.5rem;">
                                    <i class="fa-solid fa-list-check text-primary mr-1"></i> Tasks in Progress
                                </h4>
                                ${tasksHtml}
                            </div>

                            <!-- Guided Safety Inspection Checklist -->
                            <div>
                                <h4 style="font-size: 0.95rem; font-weight: 800; color: var(--text-main); margin-bottom: 0.5rem;">
                                    <i class="fa-solid fa-clipboard-check text-primary mr-1"></i> Guided Safety Inspection Checklist
                                </h4>
                                <div class="checklist-grid">
                                    ${chkHtml}
                                </div>
                            </div>
                        </div>
                    `;
                });

                jobsContainer.innerHTML = jHtml;
            }
        } catch (e) {
            console.warn('Technical screen fetch error:', e);
            jobsContainer.innerHTML = `<div style="text-align: center; padding: 2rem; color: var(--danger);">Failed to load technical jobs. Please check connection.</div>`;
        }
    }

    // Technical Action Handlers
    static async handleReceiveFromWash(orderId, plate) {
        try {
            const res = await PosApi.receiveFromWash(orderId);
            if (res && res.success) {
                this.showToastNotice(`Car ${plate} received into ${res.bay_name || 'Tech Bay'}. Technical job started!`, 'success');
                this.playChimeSound();
                await this.renderMechanicScreen();
            } else {
                this.showAppAlert(res?.message || 'Failed to receive vehicle from wash');
            }
        } catch (e) {
            this.showAppAlert(e.data?.message || e.message || 'Failed to receive car');
        }
    }

    static async handleStartTechTask(taskId) {
        try {
            const res = await PosApi.startTechTask(taskId);
            if (res && res.success) {
                this.showToastNotice('Task started in lane', 'success');
                await this.renderMechanicScreen();
            } else {
                this.showAppAlert(res?.message || 'Could not start task');
            }
        } catch (e) {
            this.showAppAlert(e.data?.message || e.message || 'Error starting task');
        }
    }

    static async handleFinishTechTask(taskId) {
        try {
            const res = await PosApi.finishTechTask(taskId);
            if (res && res.success) {
                this.showToastNotice('Task completed in lane', 'success');
                await this.renderMechanicScreen();
            } else {
                this.showAppAlert(res?.message || 'Could not finish task');
            }
        } catch (e) {
            this.showAppAlert(e.data?.message || e.message || 'Error finishing task');
        }
    }

    static setChecklistStatus(itemId, status) {
        const card = document.getElementById(`chkCard-${itemId}`);
        if (!card) return;
        const btns = card.querySelectorAll('.btn-chk-status');
        btns.forEach(b => {
            b.classList.remove('active-pass', 'active-caution', 'active-fail');
            if (b.textContent.toLowerCase() === status.toLowerCase()) {
                b.classList.add(`active-${status}`);
            }
        });
        card.setAttribute('data-status', status);
    }

    static handleChecklistPhotoCapture(event, itemId) {
        const file = event.target.files?.[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = (loadEvt) => {
            const base64Data = loadEvt.target.result;
            PosApp.checklistPhotos[itemId] = base64Data;
            const previewBox = document.getElementById(`chkPhotoPreview-${itemId}`);
            const previewImg = document.getElementById(`chkPhotoImg-${itemId}`);
            if (previewImg) previewImg.src = base64Data;
            if (previewBox) previewBox.style.display = 'inline-flex';
            PosApp.showToastNotice('Photo captured and attached!', 'info');
        };
        reader.readAsDataURL(file);
    }

    static async handleSaveChecklistItem(itemId) {
        const card = document.getElementById(`chkCard-${itemId}`);
        const valInput = document.getElementById(`chkVal-${itemId}`);
        const measVal = valInput ? valInput.value.trim() : '';

        // Read active status button
        let status = card?.getAttribute('data-status') || 'pass';
        const activeBtn = card?.querySelector('.btn-chk-status.active-pass, .btn-chk-status.active-caution, .btn-chk-status.active-fail');
        if (activeBtn) {
            status = activeBtn.textContent.toLowerCase();
        }

        const photoBase64 = this.checklistPhotos[itemId] || null;

        try {
            const res = await PosApi.updateChecklistItem(itemId, status, measVal, photoBase64);
            if (res && res.success) {
                this.showToastNotice('Checklist item saved successfully!', 'success');
            } else {
                this.showAppAlert(res?.message || 'Could not save checklist item');
            }
        } catch (e) {
            this.showAppAlert(e.data?.message || e.message || 'Failed to save checklist');
        }
    }

    // Request Parts Modal
    static openRequestPartsModal(orderId, plate) {
        const modal = document.getElementById('modalRequestParts');
        if (!modal) return;

        document.getElementById('partsReqOrderId').value = orderId;
        document.getElementById('partsReqPlate').textContent = plate;
        document.getElementById('partsReqQty').value = '1';
        document.getElementById('partsReqNotes').value = '';

        const select = document.getElementById('partsReqProductSelect');
        if (select) {
            const prods = this.currentTechData?.available_parts || [];
            let optHtml = '<option value="">-- Choose Spare Part / Oil --</option>';
            prods.forEach(p => {
                optHtml += `<option value="${p.id}">${this.escapeHtml(p.name_en)} ($${parseFloat(p.price_usd).toFixed(2)}) - Stock: ${p.stock_qty}</option>`;
            });
            select.innerHTML = optHtml;
        }

        modal.classList.add('open');
    }

    static async handleSubmitRequestParts() {
        const orderId = parseInt(document.getElementById('partsReqOrderId')?.value || 0);
        const productId = parseInt(document.getElementById('partsReqProductSelect')?.value || 0);
        const qty = parseInt(document.getElementById('partsReqQty')?.value || 1);
        const notes = document.getElementById('partsReqNotes')?.value.trim() || '';

        if (!productId) {
            alert('Please select a spare part or oil product');
            return;
        }
        if (qty <= 0) {
            alert('Quantity must be at least 1');
            return;
        }

        try {
            const res = await PosApi.requestParts(orderId, productId, qty, notes);
            if (res && res.success) {
                document.getElementById('modalRequestParts')?.classList.remove('open');
                this.showToastNotice(`Requested ${qty}x ${res.product_name}. Status: Waiting for Parts`, 'info');
                this.playChimeSound();
                await this.renderMechanicScreen();
            } else {
                this.showAppAlert(res?.message || 'Could not submit parts request');
            }
        } catch (e) {
            this.showAppAlert(e.data?.message || e.message || 'Error requesting parts');
        }
    }

    static handleFinishTechJob(orderId, plate) {
        this.openWasherFinishPrompt(0, orderId, plate, '', 0, 'technical');
    }

    // ========================================================
    // EDIT REPAIR NOTES MODAL (Requirement 3, 13)
    // ========================================================
    static currentEditNotesTarget = null;

    static openEditRepairNotesModal(orderId, orderNumber, plate, currentNotes) {
        this.currentEditNotesTarget = { orderId, orderNumber, plate };
        document.getElementById('editNotesOrderNum').textContent = `#${orderNumber}`;
        document.getElementById('editNotesPlate').textContent = plate;
        document.getElementById('editNotesTextarea').value = currentNotes || '';
        document.getElementById('modalEditRepairNotes')?.classList.add('open');
    }

    static async handleSaveRepairNotesModal() {
        if (!this.currentEditNotesTarget) return;

        const btn = document.getElementById('btnSaveRepairNotesModal');
        btn.disabled = true;
        btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Saving...`;

        const notes = document.getElementById('editNotesTextarea')?.value.trim() || '';

        try {
            const res = await PosApi.saveRepairNote(
                this.currentEditNotesTarget.orderId,
                this.currentEditNotesTarget.orderNumber,
                notes
            );

            if (res.success) {
                document.getElementById('modalEditRepairNotes')?.classList.remove('open');
                alert('Repair notes saved successfully!');
                const user = PosStorage.getUser();
                if (user?.role === 'mechanic') {
                    this.renderMechanicScreen();
                } else {
                    this.renderJobQueue();
                }
            } else {
                alert(res.message || 'Failed to save notes');
            }
        } catch (e) {
            alert(e.message || 'Error saving repair notes');
        } finally {
            btn.disabled = false;
            btn.innerHTML = `<i class="fa-solid fa-check"></i> <span data-i18n="saveNotes">រក្សាទុកចំណាំ (Save)</span>`;
        }
    }

    // ========================================================
    // PENDING OFFLINE ORDERS MODAL (Requirement 12)
    // ========================================================
    static openPendingOrdersModal() {
        const modal = document.getElementById('modalPendingOrders');
        const container = document.getElementById('pendingOrdersList');
        if (!modal || !container) return;

        const queue = PosStorage.getPendingOrders();
        if (queue.length === 0) {
            container.innerHTML = `<div style="text-align: center; padding: 2rem; color: var(--text-muted);"><i class="fa-solid fa-circle-check fa-2x" style="color: var(--success); margin-bottom: 0.5rem;"></i><p style="font-weight: 700; margin: 0;">All orders are synchronized with the back office. No pending offline orders!</p></div>`;
            container.onclick = null;
        } else {
            let html = '';
            queue.forEach(ord => {
                const reason = ord.failure_reason || 'Network offline / connection timeout';
                const time = (ord.failed_at || ord.created_at || '').substring(11, 16);
                const retryCount = ord.retry_count || 0;

                html += `
                    <div style="background: white; border: 1.5px solid var(--border); border-left: 4px solid #ea580c; border-radius: var(--radius-md); padding: 0.75rem 1rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                            <div>
                                <span style="font-weight: 800; font-size: 1rem; color: var(--text-main);">${this.escapeHtml(ord.plate_number)}</span>
                                <span style="font-size: 0.8rem; color: var(--text-muted); margin-left: 6px;">#${this.escapeHtml(ord.order_number || 'PENDING')}</span>
                            </div>
                            <span style="font-weight: 900; color: var(--primary); font-size: 1.1rem;">$${parseFloat(ord.total_usd || 0).toFixed(2)}</span>
                        </div>
                        <div style="font-size: 0.82rem; color: var(--text-muted); margin-bottom: 0.4rem;">
                            Customer: <strong>${this.escapeHtml(ord.customer_name || 'Walk-in')}</strong> • ${ord.items?.length || 1} items • Time: ${time} (Retries: ${retryCount})
                        </div>
                        <div style="background: #fff7ed; border: 1px solid #fed7aa; padding: 0.4rem 0.6rem; border-radius: 4px; font-size: 0.78rem; color: #9a3412; margin-bottom: 0.5rem;">
                            <i class="fa-solid fa-triangle-exclamation"></i> <strong>Reason:</strong> ${this.escapeHtml(reason)}
                        </div>
                        <div style="text-align: right;">
                            <button type="button" class="btn btn-outline btn-sm btn-retry-pending" data-idempotency-key="${this.escapeHtml(ord.idempotency_key)}">
                                <i class="fa-solid fa-rotate"></i> Try Now
                            </button>
                        </div>
                    </div>
                `;
            });
            container.innerHTML = html;
            container.onclick = (e) => {
                const btn = e.target.closest('.btn-retry-pending');
                if (btn && btn.dataset.idempotencyKey) {
                    PosApp.retrySinglePendingOrder(btn.dataset.idempotencyKey);
                }
            };
        }

        modal.classList.add('open');
    }

    static async retrySinglePendingOrder(idempotencyKey) {
        const queue = PosStorage.getPendingOrders();
        const ord = queue.find(o => o.idempotency_key === idempotencyKey);
        if (!ord) return;

        try {
            const res = await PosApi.createOrder(ord, ord.idempotency_key);
            if (res.success || res.is_duplicate) {
                PosStorage.removePendingOrder(ord.idempotency_key);

                // Server-confirmed canonical order details
                const serverOrder = res.order || {};
                const confirmedOrderId = serverOrder.id || res.order_id || 0;
                const confirmedOrderNumber = serverOrder.order_number || res.order_number || '';
                const paymentStatus = (serverOrder.payment_status || ord.payment_status || '').toLowerCase();

                // Validate details match local order
                const orderIdMatch = (!ord.id || !confirmedOrderId || ord.id === confirmedOrderId);
                const orderNumberMatch = (!ord.order_number || !confirmedOrderNumber || ord.order_number.startsWith('ORD-OFFLINE-') || ord.order_number === confirmedOrderNumber);

                if (orderIdMatch && orderNumberMatch) {
                    const updatedOrderRecord = {
                        ...ord,
                        ...(res.order || {}),
                        id: confirmedOrderId || ord.id,
                        order_number: confirmedOrderNumber || ord.order_number,
                        synced: true
                    };
                    PosStorage.addSyncedOrder(updatedOrderRecord);

                    if (this.currentReceiptOrder && this.currentReceiptOrder.idempotency_key === ord.idempotency_key) {
                        this.currentReceiptOrder.id = confirmedOrderId || this.currentReceiptOrder.id;
                        this.currentReceiptOrder.order_number = confirmedOrderNumber || this.currentReceiptOrder.order_number;
                        this.currentReceiptOrder.synced = true;
                    }
                    if (this.lastCompletedOrder && this.lastCompletedOrder.idempotency_key === ord.idempotency_key) {
                        this.lastCompletedOrder.id = confirmedOrderId || this.lastCompletedOrder.id;
                        this.lastCompletedOrder.order_number = confirmedOrderNumber || this.lastCompletedOrder.order_number;
                        this.lastCompletedOrder.synced = true;
                    }

                    // Acknowledge receipt only for paid orders
                    if (paymentStatus === 'paid' && (confirmedOrderId || confirmedOrderNumber)) {
                        await this.sendReceiptAcknowledgement(confirmedOrderId, confirmedOrderNumber);
                    }
                } else {
                    console.warn(`Order details mismatch on retry for ${ord.idempotency_key}: local (#${ord.order_number}) vs server (#${confirmedOrderNumber})`);
                }

                this.updatePendingSyncBadge();
                this.openPendingOrdersModal();
                alert(`Order ${ord.plate_number} sent successfully to back office!`);
                this.renderJobQueue();
            } else {
                PosStorage.updatePendingOrderFailure(ord.idempotency_key, res.message || 'Server rejected order');
                this.openPendingOrdersModal();
                alert(res.message || 'Sync failed');
            }
        } catch (e) {
            PosStorage.updatePendingOrderFailure(ord.idempotency_key, e.message || 'Connection failed');
            this.openPendingOrdersModal();
            alert('Could not connect to back office: ' + e.message);
        }
    }

    // ========================================================
    // OFFLINE IDEMPOTENT SYNC ENGINE
    // ========================================================
    static _isSyncingPendingOrders = false;
    static async syncPendingOrdersNow() {
        if (this._isSyncingPendingOrders) {
            console.log('syncPendingOrdersNow already in progress, skipping concurrent run.');
            return;
        }
        this._isSyncingPendingOrders = true;
        try {
            const queue = PosStorage.getPendingOrders();
            const statusQueue = PosStorage.getOfflineStatusQueue();
            const ackQueue = PosStorage.getPendingReceiptAcks();
            if (queue.length === 0 && statusQueue.length === 0 && ackQueue.length === 0) return;

            if (queue.length > 0) {
                console.log(`Syncing ${queue.length} pending offline orders to back office...`);
                const badge = document.getElementById('syncBadge');
                if (badge) {
                    badge.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Syncing...`;
                }

                let syncedCount = 0;
                for (const order of queue) {
                    try {
                        const res = await PosApi.createOrder(order, order.idempotency_key);
                        if (res.success || res.is_duplicate) {
                            PosStorage.removePendingOrder(order.idempotency_key);
                            syncedCount++;

                            // Server-confirmed canonical order details
                            const serverOrder = res.order || {};
                            const confirmedOrderId = serverOrder.id || res.order_id || 0;
                            const confirmedOrderNumber = serverOrder.order_number || res.order_number || '';
                            const paymentStatus = (serverOrder.payment_status || order.payment_status || '').toLowerCase();

                            // Validate details match local order
                            const orderIdMatch = (!order.id || !confirmedOrderId || order.id === confirmedOrderId);
                            const orderNumberMatch = (!order.order_number || !confirmedOrderNumber || order.order_number.startsWith('ORD-OFFLINE-') || order.order_number === confirmedOrderNumber);

                            if (orderIdMatch && orderNumberMatch) {
                                const updatedOrderRecord = {
                                    ...order,
                                    ...(res.order || {}),
                                    id: confirmedOrderId || order.id,
                                    order_number: confirmedOrderNumber || order.order_number,
                                    synced: true
                                };
                                PosStorage.addSyncedOrder(updatedOrderRecord);

                                if (this.currentReceiptOrder && this.currentReceiptOrder.idempotency_key === order.idempotency_key) {
                                    this.currentReceiptOrder.id = confirmedOrderId || this.currentReceiptOrder.id;
                                    this.currentReceiptOrder.order_number = confirmedOrderNumber || this.currentReceiptOrder.order_number;
                                    this.currentReceiptOrder.synced = true;
                                }
                                if (this.lastCompletedOrder && this.lastCompletedOrder.idempotency_key === order.idempotency_key) {
                                    this.lastCompletedOrder.id = confirmedOrderId || this.lastCompletedOrder.id;
                                    this.lastCompletedOrder.order_number = confirmedOrderNumber || this.lastCompletedOrder.order_number;
                                    this.lastCompletedOrder.synced = true;
                                }

                                // Acknowledge receipt only for paid orders
                                if (paymentStatus === 'paid' && (confirmedOrderId || confirmedOrderNumber)) {
                                    await this.sendReceiptAcknowledgement(confirmedOrderId, confirmedOrderNumber);
                                }
                            } else {
                                console.warn(`Order details mismatch on sync for ${order.idempotency_key}: local (#${order.order_number}) vs server (#${confirmedOrderNumber})`);
                            }
                        } else {
                            PosStorage.updatePendingOrderFailure(order.idempotency_key, res.message || 'Server rejected');
                        }
                    } catch (err) {
                        PosStorage.updatePendingOrderFailure(order.idempotency_key, err.message || 'Network timeout');
                        console.warn(`Could not sync order ${order.idempotency_key}:`, err);
                        break; // Stop loop if server connection failed
                    }
                }

                this.updatePendingSyncBadge();
                if (syncedCount > 0) {
                    console.log(`Successfully synced ${syncedCount} orders.`);
                    this.renderJobQueue();
                }
            }

            // Requirement 7: Sync pending offline status changes
            const currentStatusQueue = PosStorage.getOfflineStatusQueue();
            if (currentStatusQueue.length > 0) {
                for (const item of currentStatusQueue) {
                    try {
                        const res = await PosApi.syncOrderStatus(0, item.orderNumber, item.status);
                        if (res && res.success) {
                            PosStorage.removeOfflineStatusChange(item.orderNumber);
                        }
                    } catch (e) {
                        console.warn(`Could not sync status change for ${item.orderNumber}:`, e);
                        break;
                    }
                }
            }

            // Sync pending offline electronic receipt acknowledgements
            const currentAckQueue = PosStorage.getPendingReceiptAcks();
            if (currentAckQueue.length > 0) {
                for (const item of currentAckQueue) {
                    try {
                        const res = await PosApi.ackReceiptIssued(item.order_id, item.order_number);
                        if (res && res.success) {
                            PosStorage.removePendingReceiptAck(item.order_number, item.order_id);
                        }
                    } catch (e) {
                        console.warn(`Could not sync receipt ack for ${item.order_number}:`, e);
                        // Permanent errors (unpaid or not found) should be unqueued to prevent endless retry
                        if (e.status === 400 || e.status === 402 || e.status === 404 || e.code === 'PAYMENT_REQUIRED' || e.code === 'ORDER_NOT_FOUND') {
                            PosStorage.removePendingReceiptAck(item.order_number, item.order_id);
                        } else {
                            break;
                        }
                    }
                }
            }

            // If pending modal is currently open, refresh its content
            if (document.getElementById('modalPendingOrders')?.classList.contains('open')) {
                this.openPendingOrdersModal();
            }
        } finally {
            this._isSyncingPendingOrders = false;
        }
    }

    // ========================================================
    // SETTINGS MODAL & THERMAL PRINTER CONFIG
    // ========================================================
    static lastFailedPrintOrder = null;
    static currentReceiptOrder = null;

    static loadSettingsForm() {
        const config = PosConfig.get();
        const urlEl = document.getElementById('cfgBackofficeUrl');
        const uidEl = document.getElementById('cfgDeviceUid');
        const keyEl = document.getElementById('cfgDeviceKey');
        const rateEl = document.getElementById('cfgExchangeRate');

        if (urlEl) urlEl.value = config.backofficeUrl || '';
        if (uidEl) uidEl.value = config.deviceUid || '';
        if (keyEl) keyEl.value = config.deviceKey || '';
        if (rateEl) rateEl.value = this.getExchangeRate();

        const base = (config.backofficeUrl || '').replace(/\/+$/, '');
        const boTarget = base ? `${base}/index.php` : '../index.php';
        const link1 = document.getElementById('btnBackofficeLink');
        if (link1) link1.href = boTarget;
        const link2 = document.getElementById('menuItemBackoffice');
        if (link2) link2.href = boTarget;
        const link3 = document.getElementById('btnHeaderBackoffice');
        if (link3) link3.href = boTarget;

        const idleEl = document.getElementById('cfgIdleTimeout');
        if (idleEl) idleEl.value = (config.idleTimeoutMinutes !== undefined) ? String(config.idleTimeoutMinutes) : '5';

        // Load Printer configuration fields
        const directEl = document.getElementById('cfgPrinterDirect');
        const typeEl = document.getElementById('cfgPrinterType');
        const ipEl = document.getElementById('cfgPrinterIp');
        const portEl = document.getElementById('cfgPrinterPort');
        const cutEl = document.getElementById('cfgPrinterAutoCut');
        const drawerEl = document.getElementById('cfgPrinterOpenDrawer');
        const washerEl = document.getElementById('cfgPrinterWasherCopy');
        const fallbackEl = document.getElementById('cfgPrinterFallback');

        if (directEl) directEl.checked = !!config.printerDirectEnabled;
        if (typeEl) typeEl.value = config.printerType || 'network';
        if (ipEl) ipEl.value = config.printerIp || '192.168.1.200';
        if (portEl) portEl.value = config.printerPort || 9100;
        if (cutEl) cutEl.checked = config.printerAutoCut !== false;
        if (drawerEl) drawerEl.checked = config.printerOpenDrawer !== false;
        if (washerEl) washerEl.checked = !!config.printerWasherCopy;
        if (fallbackEl) fallbackEl.checked = config.printerFallbackWindow !== false;

        // Load Show Pictures preference
        const picEl = document.getElementById('cfgShowPictures');
        const staffId = this.currentUser?.id || this.currentUser?.username;
        if (picEl) picEl.checked = PosStorage.getShowPictures(staffId);

        // Load Dark Theme preference
        const darkEl = document.getElementById('cfgDarkTheme');
        if (darkEl) darkEl.checked = (PosStorage.getTheme() === 'dark');

        // Update Layout preference preview UI
        this.updateLayoutSettingsUI();

        // Toggle network IP/Port group based on connection type
        const netGroup = document.getElementById('cfgPrinterNetworkGroup');
        if (netGroup) {
            netGroup.style.display = (typeEl?.value === 'usb') ? 'none' : 'block';
        }

        // Clear test print feedback
        const testResBox = document.getElementById('printerTestResult');
        if (testResBox) testResBox.style.display = 'none';
    }

    static async handleTestConnection() {
        const btn = document.getElementById('btnTestConnection');
        btn.disabled = true;
        btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Checking...`;
        this.clearAlert('settingsAlert');

        const testUrl = document.getElementById('cfgBackofficeUrl')?.value.trim();
        const testUid = document.getElementById('cfgDeviceUid')?.value.trim();
        const testKey = document.getElementById('cfgDeviceKey')?.value.trim();

        if (!testUrl || !testKey) {
            btn.disabled = false;
            btn.innerHTML = `<i class="fa-solid fa-tower-broadcast"></i> Test Link`;
            this.showAlert(
                'settingsAlert',
                {
                    type: 'danger',
                    title: 'Connection Failed:',
                    message: 'Please enter both the Back Office Address (URL) and Device API Key before testing.'
                }
            );
            return;
        }

        if (!testKey.startsWith('pos_sec_')) {
            btn.disabled = false;
            btn.innerHTML = `<i class="fa-solid fa-tower-broadcast"></i> Test Link`;
            this.showAlert(
                'settingsAlert',
                {
                    type: 'danger',
                    title: 'Connection Failed:',
                    message: 'This is not a device key. The real key is shown only once when the device is created or its key is regenerated.'
                }
            );
            return;
        }

        try {
            const res = await PosApi.testConnection({
                backofficeUrl: testUrl,
                deviceUid: testUid,
                deviceKey: testKey
            });

            if (res.success) {
                // Connection works! Clear green message
                PosStorage.setItem('carwash_pos_has_connected', 'true');
                this.setOnlineStatus(true);

                const devName = res.device?.name || testUid || 'POS Terminal';
                this.showAlert(
                    'settingsAlert',
                    {
                        type: 'success',
                        title: 'Connection Successful!',
                        message: `Successfully linked to Back Office at ${testUrl}. Approved Device: ${devName} (Status: Active).`
                    }
                );
            } else {
                // Connection failed! Clear red message with reason
                this.setOnlineStatus(false);
                this.showAlert(
                    'settingsAlert',
                    {
                        type: 'danger',
                        title: 'Connection Failed:',
                        message: res.error || 'Server rejected connection.'
                    }
                );
            }
        } catch (e) {
            this.setOnlineStatus(false);
            this.showAlert(
                'settingsAlert',
                {
                    type: 'danger',
                    title: 'Connection Failed:',
                    message: e.message || 'Unable to connect to server.'
                }
            );
        } finally {
            btn.disabled = false;
            btn.innerHTML = `<i class="fa-solid fa-tower-broadcast"></i> Test Link`;
        }
    }

    static async handleTestPrinter() {
        const btn = document.getElementById('btnTestPrinter');
        const resultBox = document.getElementById('printerTestResult');
        if (!btn || !resultBox) return;

        btn.disabled = true;
        btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Printing Test...`;
        resultBox.style.display = 'none';

        // Temporarily persist input fields for test run
        const tempPrinterType = document.getElementById('cfgPrinterType')?.value || 'network';
        const tempPrinterIp = document.getElementById('cfgPrinterIp')?.value.trim() || '192.168.1.200';
        const tempPrinterPort = parseInt(document.getElementById('cfgPrinterPort')?.value) || 9100;
        const tempAutoCut = document.getElementById('cfgPrinterAutoCut')?.checked ?? true;
        const tempOpenDrawer = document.getElementById('cfgPrinterOpenDrawer')?.checked ?? true;

        PosConfig.save({
            printerType: tempPrinterType,
            printerIp: tempPrinterIp,
            printerPort: tempPrinterPort,
            printerAutoCut: tempAutoCut,
            printerOpenDrawer: tempOpenDrawer
        });

        try {
            const res = await PosThermalPrinter.testPrint();
            resultBox.style.display = 'flex';
            resultBox.style.alignItems = 'flex-start';
            resultBox.style.gap = '8px';
            resultBox.textContent = '';
            if (res.success) {
                resultBox.className = 'alert alert-success';
                const icon = document.createElement('i');
                icon.className = 'fa-solid fa-circle-check';
                icon.style.color = '#16a34a';
                icon.style.fontSize = '1.2rem';
                icon.style.marginTop = '2px';
                resultBox.appendChild(icon);

                const div = document.createElement('div');
                const strong = document.createElement('strong');
                strong.textContent = 'តេស្តព្រីនបានជោគជ័យ (Test Print Success)!';
                div.appendChild(strong);
                div.appendChild(document.createElement('br'));
                const desc = document.createElement('span');
                desc.textContent = `បានបញ្ជូនសំណៅតេស្តទៅកាន់ម៉ាស៊ីនព្រីន ${tempPrinterIp}:${tempPrinterPort} (${tempPrinterType.toUpperCase()})។ វិក្កយបត្របានចេញ និងកាត់ក្រដាសដោយជោគជ័យ។`;
                div.appendChild(desc);
                resultBox.appendChild(div);
            } else {
                resultBox.className = 'alert alert-danger';
                const icon = document.createElement('i');
                icon.className = 'fa-solid fa-circle-xmark';
                icon.style.color = '#dc2626';
                icon.style.fontSize = '1.2rem';
                icon.style.marginTop = '2px';
                resultBox.appendChild(icon);

                const div = document.createElement('div');
                const strong = document.createElement('strong');
                strong.textContent = 'ម៉ាស៊ីនព្រីនមិនអាចទាក់ទងបាន (Printer Unreachable):';
                div.appendChild(strong);
                div.appendChild(document.createElement('br'));
                const errSpan = document.createElement('span');
                errSpan.textContent = res.error || 'Connection failed: Please check printer power cable, network cable / IP address.';
                div.appendChild(errSpan);
                resultBox.appendChild(div);
            }
        } catch (err) {
            resultBox.style.display = 'flex';
            resultBox.style.alignItems = 'flex-start';
            resultBox.style.gap = '8px';
            resultBox.textContent = '';
            resultBox.className = 'alert alert-danger';
            const icon = document.createElement('i');
            icon.className = 'fa-solid fa-circle-xmark';
            icon.style.color = '#dc2626';
            icon.style.fontSize = '1.2rem';
            icon.style.marginTop = '2px';
            resultBox.appendChild(icon);

            const div = document.createElement('div');
            const strong = document.createElement('strong');
            strong.textContent = 'បរាជ័យក្នុងការតេស្ត (Test Print Failed):';
            div.appendChild(strong);
            div.appendChild(document.createElement('br'));
            const errSpan = document.createElement('span');
            errSpan.textContent = err.message || 'Thermal printer communication error.';
            div.appendChild(errSpan);
            resultBox.appendChild(div);
        } finally {
            btn.disabled = false;
            btn.innerHTML = `<i class="fa-solid fa-print"></i> <span data-i18n="testPrintBtn">${I18nManager.t('testPrintBtn')}</span>`;
        }
    }

    static handleSaveSettings() {
        const url = document.getElementById('cfgBackofficeUrl')?.value.trim();
        const uid = document.getElementById('cfgDeviceUid')?.value.trim();
        const key = document.getElementById('cfgDeviceKey')?.value.trim();
        const rate = this.getExchangeRate();

        if (!url) {
            this.showAlert('settingsAlert', 'Please enter the Back Office Address (URL)', 'danger');
            return;
        }
        if (!uid) {
            this.showAlert('settingsAlert', 'Please enter the Device ID / UID (e.g. POS-TERMINAL-01)', 'danger');
            return;
        }
        if (!key) {
            this.showAlert('settingsAlert', 'Please enter the Device API Key', 'danger');
            return;
        }

        if (!key.startsWith('pos_sec_')) {
            this.showAlert('settingsAlert', 'This is not a device key. The real key is shown only once when the device is created or its key is regenerated.', 'danger');
            return;
        }

        const keyInput = document.getElementById('cfgDeviceKey');
        if (keyInput) keyInput.value = key;

        // Collect Printer Settings
        const printerDirectEnabled = document.getElementById('cfgPrinterDirect')?.checked || false;
        const printerType = document.getElementById('cfgPrinterType')?.value || 'network';
        const printerIp = document.getElementById('cfgPrinterIp')?.value.trim() || '192.168.1.200';
        const printerPort = parseInt(document.getElementById('cfgPrinterPort')?.value) || 9100;
        const printerAutoCut = document.getElementById('cfgPrinterAutoCut')?.checked ?? true;
        const printerOpenDrawer = document.getElementById('cfgPrinterOpenDrawer')?.checked ?? true;
        const printerWasherCopy = document.getElementById('cfgPrinterWasherCopy')?.checked || false;
        const printerFallbackWindow = document.getElementById('cfgPrinterFallback')?.checked ?? true;

        // Collect Idle Timeout Setting (Requirement 3)
        const idleTimeoutMinutes = parseInt(document.getElementById('cfgIdleTimeout')?.value ?? 5);

        PosConfig.save({
            backofficeUrl: url,
            deviceUid: uid,
            deviceKey: key,
            exchangeRate: rate,
            idleTimeoutMinutes: isNaN(idleTimeoutMinutes) ? 5 : idleTimeoutMinutes,
            printerDirectEnabled,
            printerType,
            printerIp,
            printerPort,
            printerAutoCut,
            printerOpenDrawer,
            printerWasherCopy,
            printerFallbackWindow
        });
        this.lastActivityTime = Date.now();

        // Save Show Pictures preference
        const showPictures = document.getElementById('cfgShowPictures')?.checked ?? true;
        const staffId = this.currentUser?.id || this.currentUser?.username;
        PosStorage.setShowPictures(showPictures, staffId);
        this.updatePicturesToggleUI();

        // Save Dark Theme preference
        const isDark = document.getElementById('cfgDarkTheme')?.checked || false;
        PosStorage.setTheme(isDark ? 'dark' : 'light');
        this.applyTheme();

        this.renderCatalog();

        this.showAlert(
            'settingsAlert',
            {
                type: 'success',
                title: 'Settings Saved!',
                message: 'The connection and printer settings are saved on this device.'
            }
        );

        setTimeout(async () => {
            document.getElementById('modalSettings')?.classList.remove('open');
            const res = await PosApi.testConnection();
            if (res.success) {
                PosStorage.setItem('carwash_pos_has_connected', 'true');
                this.setOnlineStatus(true);
                PosApi.syncCatalog().then(() => this.renderCatalog());
            } else {
                this.setOnlineStatus(false);
            }
        }, 800);
    }

    // ========================================================
    // DIRECT THERMAL PRINTING DISPATCHER & ALERTS
    // ========================================================
    /**
     * Send client acknowledgement that electronic receipt was successfully received and validated
     */
    static async sendReceiptAcknowledgement(orderId, orderNumber = '') {
        if (!orderId && !orderNumber) return;
        try {
            const res = await PosApi.ackReceiptIssued(orderId, orderNumber);
            if (res && res.success) {
                PosStorage.removePendingReceiptAck(orderNumber, orderId);
                const nowIso = res.receipt_issued_at || new Date().toISOString();
                if (this.currentReceiptOrder && (this.currentReceiptOrder.id === orderId || this.currentReceiptOrder.order_number === orderNumber)) {
                    this.currentReceiptOrder.receipt_issued_at = nowIso;
                }
                if (this.lastCompletedOrder && (this.lastCompletedOrder.id === orderId || this.lastCompletedOrder.order_number === orderNumber)) {
                    this.lastCompletedOrder.receipt_issued_at = nowIso;
                }
                const synced = PosStorage.getSyncedOrders() || [];
                const found = synced.find(o => (orderId && o.id === orderId) || (orderNumber && o.order_number === orderNumber));
                if (found) {
                    found.receipt_issued_at = nowIso;
                    PosStorage.setSyncedOrders(synced);
                }
                return res;
            } else {
                PosStorage.addPendingReceiptAck(orderId, orderNumber);
            }
        } catch (err) {
            console.warn(`Receipt acknowledgement network error for #${orderNumber}, queued for retry:`, err);
            PosStorage.addPendingReceiptAck(orderId, orderNumber);
        }
    }

    static async markReceiptPrinted(order) {
        if (!order) return;
        const orderId = order.id || 0;
        const orderNumber = order.order_number || '';
        if (!orderId && !orderNumber) return;

        try {
            const res = await PosApi.markReceiptPrinted(orderId, orderNumber);
            if (res && res.success) {
                const nowIso = res.receipt_printed_at || new Date().toISOString();
                order.receipt_printed_at = nowIso;
                if (this.currentReceiptOrder && (this.currentReceiptOrder.id === orderId || this.currentReceiptOrder.order_number === orderNumber)) {
                    this.currentReceiptOrder.receipt_printed_at = nowIso;
                }
                if (this.lastCompletedOrder && (this.lastCompletedOrder.id === orderId || this.lastCompletedOrder.order_number === orderNumber)) {
                    this.lastCompletedOrder.receipt_printed_at = nowIso;
                }
                // Update local storage cached copies
                const synced = PosStorage.getSyncedOrders() || [];
                const found = synced.find(o => (orderId && o.id === orderId) || (orderNumber && o.order_number === orderNumber));
                if (found) {
                    found.receipt_printed_at = nowIso;
                    PosStorage.setSyncedOrders(synced);
                }
                if (this.currentTodayOrdersList) {
                    const foundToday = this.currentTodayOrdersList.find(o => (orderId && o.id === orderId) || (orderNumber && o.order_number === orderNumber));
                    if (foundToday) foundToday.receipt_printed_at = nowIso;
                }
                // Re-render views if visible
                const queueGrid = document.getElementById('queueGrid');
                if (queueGrid) this.renderJobQueue();
                const ordersTable = document.getElementById('todayOrdersTableBody');
                if (ordersTable) this.renderTodayOrders();
            }
        } catch (e) {
            console.warn('Failed to notify server of receipt print:', e);
        }
    }

    static async handlePrintReceiptButton() {
        if (this.isFullscreen()) {
            this.wasFullscreenPreferred = true;
        }
        const isDailyReport = this.isPrintingDailyReport;
        const order = this.currentReceiptOrder || this.lastCompletedOrder;
        if (!order && !isDailyReport) {
            alert(I18nManager.t('noOrderToPrint') || 'No order selected to print');
            return;
        }

        const cfg = PosConfig.get();
        if (!isDailyReport && cfg.printerDirectEnabled) {
            const btn = document.getElementById('btnPrintReceiptDirect');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> ${I18nManager.t('printing') || 'Printing...'}`;
            }
            try {
                const isTicket = this.isPrintingWorkshopTicket;
                const res = isTicket ? await PosThermalPrinter.printWorkshopTicket(order) : await PosThermalPrinter.printReceipt(order);
                if (!res.success) {
                    this.showPrinterAlert(res.error, order);
                    if (cfg.printerFallbackWindow) {
                        this.executeBrowserPrint(order);
                    }
                } else {
                    this.hidePrinterAlert();
                    if (!isTicket && !isDailyReport) {
                        this.markReceiptPrinted(order);
                    }
                }
            } catch (e) {
                this.showPrinterAlert(e.message, order);
                if (cfg.printerFallbackWindow) {
                    this.executeBrowserPrint(order);
                }
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = `<i class="fa-solid fa-print"></i> <span data-i18n="printReceipt">${I18nManager.t('printReceipt') || 'Print 80mm'}</span>`;
                }
            }
        } else {
            this.executeBrowserPrint(order);
        }
    }

    static executeBrowserPrint(order = null) {
        const targetOrder = order || this.currentReceiptOrder || this.lastCompletedOrder;
        if (targetOrder && !this.isPrintingDailyReport && !this.isPrintingWorkshopTicket) {
            this.markReceiptPrinted(targetOrder);
        }

        if (this.isFullscreen()) {
            this.wasFullscreenPreferred = true;
        }

        const restoreFullscreen = () => {
            if (this.wasFullscreenPreferred && !this.isFullscreen()) {
                this.enterFullscreen().catch(() => {});
            }
        };

        const cleanupAndRestore = () => {
            ['pointerdown', 'click', 'keydown', 'touchstart'].forEach(evt => {
                window.removeEventListener(evt, cleanupAndRestore, true);
            });
            restoreFullscreen();
        };

        // Edge/Chrome dispatch afterprint when system print preview closes
        window.addEventListener('afterprint', () => {
            setTimeout(restoreFullscreen, 100);
            ['pointerdown', 'click', 'keydown', 'touchstart'].forEach(evt => {
                window.addEventListener(evt, cleanupAndRestore, { capture: true, once: true });
            });
        }, { once: true });

        window.print();
    }

    static async executeSilentPrint(order, isWasherTicketOnly = false, isReprint = false) {
        if (!order) return;
        if (this.isFullscreen()) {
            this.wasFullscreenPreferred = true;
        }
        const cfg = PosConfig.get();

        try {
            let res;
            if (isWasherTicketOnly) {
                res = await PosThermalPrinter.printWasherTicket(order);
            } else {
                res = await PosThermalPrinter.printReceipt(order, isReprint);
                // Print second copy for washer/workshop if enabled (Requirement 7)
                if (res.success && cfg.printerWasherCopy) {
                    await new Promise(r => setTimeout(r, 600)); // small delay for paper cut
                    await PosThermalPrinter.printWasherTicket(order);
                }
            }

            if (!res.success) {
                this.showPrinterAlert(res.error, order);
                if (cfg.printerFallbackWindow) {
                    this.executeBrowserPrint(order);
                }
            } else {
                this.hidePrinterAlert();
                if (!isWasherTicketOnly) {
                    this.markReceiptPrinted(order);
                }
            }
        } catch (e) {
            console.error('Direct print error:', e);
            this.showPrinterAlert(e.message || 'Thermal printer offline', order);
            if (cfg.printerFallbackWindow) {
                this.executeBrowserPrint(order);
            }
        }
    }

    static async printWasherTicket(orderNumberOrObj) {
        let order = orderNumberOrObj;
        if (typeof orderNumberOrObj === 'string') {
            const orders = PosStorage.getSyncedOrders();
            order = orders.find(o => o.order_number === orderNumberOrObj);
            if (!order && this.currentTodayOrdersList) {
                order = this.currentTodayOrdersList.find(o => o.order_number === orderNumberOrObj);
            }
            if (!order && this.lastCreatedOrderRecord && this.lastCreatedOrderRecord.order_number === orderNumberOrObj) {
                order = this.lastCreatedOrderRecord;
            }
            if (!order && this.lastCompletedOrder && this.lastCompletedOrder.order_number === orderNumberOrObj) {
                order = this.lastCompletedOrder;
            }
        }

        if (!order) {
            alert(I18nManager.t('noOrderToPrint') || 'Cannot find order details for washer ticket.');
            return;
        }

        const cfg = PosConfig.get();
        if (cfg.printerDirectEnabled) {
            try {
                const res = await PosThermalPrinter.printWasherTicket(order);
                if (!res.success) {
                    this.showPrinterAlert(res.error, order);
                    if (cfg.printerFallbackWindow) {
                        this.renderWorkshopTicket(order);
                        document.getElementById('modalReceipt')?.classList.add('open');
                        this.executeBrowserPrint();
                    }
                } else {
                    this.hidePrinterAlert();
                }
            } catch (e) {
                this.showPrinterAlert(e.message, order);
                if (cfg.printerFallbackWindow) {
                    this.renderWorkshopTicket(order);
                    document.getElementById('modalReceipt')?.classList.add('open');
                    this.executeBrowserPrint();
                }
            }
        } else {
            this.renderWorkshopTicket(order);
            document.getElementById('modalReceipt')?.classList.add('open');
        }
    }

    static showPrinterAlert(errorMsg, order) {
        this.lastFailedPrintOrder = order;

        // 1. Floating Banner Alert
        const banner = document.getElementById('posPrinterAlertBanner');
        const bannerMsg = document.getElementById('posPrinterAlertMsg');
        if (banner && bannerMsg) {
            const ordNum = order?.order_number ? ` (#${this.escapeHtml(order.order_number)})` : '';
            bannerMsg.innerHTML = `ម៉ាស៊ីនព្រីនមិនដំណើរការ ឬដាច់ខ្សែ៖ <strong>${this.escapeHtml(errorMsg || 'Cannot connect')}</strong><br>ការកុម្ម៉ង់${ordNum}ត្រូវបានរក្សាទុកយ៉ាងមានសុវត្ថិភាព! អ្នកអាចចុច <strong>ព្រីនម្តងទៀត</strong> នៅពេលម៉ាស៊ីនព្រីនបើកឡើងវិញ។`;
            banner.style.display = 'block';
        }

        // 2. Receipt Modal Alert (if open)
        const modalAlert = document.getElementById('rcptPrinterAlert');
        const modalMsg = document.getElementById('rcptPrinterAlertMsg');
        if (modalAlert && modalMsg) {
            modalMsg.textContent = `ម៉ាស៊ីនព្រីនដាច់ការតភ្ជាប់ (${errorMsg})។ ការកុម្ម៉ង់ត្រូវបានរក្សាទុកជោគជ័យ!`;
            modalAlert.style.display = 'flex';
        }
    }

    static hidePrinterAlert() {
        const banner = document.getElementById('posPrinterAlertBanner');
        if (banner) banner.style.display = 'none';
        const modalAlert = document.getElementById('rcptPrinterAlert');
        if (modalAlert) modalAlert.style.display = 'none';
    }

    static showAlert(elementId, message, type = 'info') {
        const el = document.getElementById(elementId);
        if (!el) return;

        let alertType = type;
        let titleText = '';
        let bodyText = '';
        let iconClass = '';

        if (message && typeof message === 'object') {
            alertType = message.type || type || 'info';
            titleText = message.title || '';
            bodyText = message.message || '';
            iconClass = message.icon || '';
        } else if (typeof message === 'string') {
            const match = message.match(/^<strong>(.*?)<\/strong>\s*(.*)$/i);
            if (match) {
                titleText = match[1].replace(/<[^>]+>/g, '').trim();
                bodyText = match[2].replace(/<[^>]+>/g, '').trim();
            } else {
                bodyText = message.replace(/<[^>]+>/g, '').trim();
            }
        }

        if (!iconClass) {
            switch (alertType) {
                case 'success':
                    iconClass = 'fa-solid fa-circle-check';
                    break;
                case 'danger':
                    iconClass = 'fa-solid fa-circle-xmark';
                    break;
                case 'warning':
                    iconClass = 'fa-solid fa-triangle-exclamation';
                    break;
                default:
                    iconClass = 'fa-solid fa-circle-info';
                    break;
            }
        }

        el.className = `alert alert-${alertType}`;
        el.style.display = 'flex';
        el.style.alignItems = 'flex-start';
        el.style.gap = '8px';

        // Clear existing children safely using DOM methods
        el.textContent = '';

        const iconEl = document.createElement('i');
        iconEl.className = iconClass;
        iconEl.style.fontSize = '1.2rem';
        iconEl.style.marginTop = '2px';
        iconEl.style.flexShrink = '0';
        el.appendChild(iconEl);

        const contentEl = document.createElement('div');
        contentEl.style.flex = '1';

        if (titleText) {
            const strongEl = document.createElement('strong');
            strongEl.textContent = titleText;
            contentEl.appendChild(strongEl);
            if (bodyText) {
                contentEl.appendChild(document.createElement('br'));
            }
        }

        if (bodyText) {
            const textSpan = document.createElement('span');
            textSpan.textContent = bodyText;
            contentEl.appendChild(textSpan);
        }

        el.appendChild(contentEl);
    }

    static clearAlert(elementId) {
        const el = document.getElementById(elementId);
        if (el) el.style.display = 'none';
    }

    // ========================================================
    // REQUIREMENT 3: APP STYLED IN-APP ALERT & CONFIRM DIALOGS
    // ========================================================
    static showAppAlert(message, type = 'info', title = null) {
        return new Promise((resolve) => {
            const modal = document.getElementById('modalAppAlert');
            const msgEl = document.getElementById('appAlertMessage');
            const titleEl = document.getElementById('appAlertTitle');
            const iconEl = document.getElementById('appAlertIcon');
            const okBtn = document.getElementById('btnAppAlertOk');

            if (!modal) {
                console.log(message);
                resolve();
                return;
            }

            const isKm = (I18nManager.getLang() === 'km');
            const defaultTitle = isKm ? 'ដំណឹង' : 'Notice';
            if (titleEl) titleEl.textContent = title || defaultTitle;
            if (msgEl) msgEl.textContent = message || '';

            if (iconEl) {
                if (type === 'danger' || type === 'error') {
                    iconEl.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i>';
                    iconEl.style.color = '#dc2626';
                } else if (type === 'warning') {
                    iconEl.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i>';
                    iconEl.style.color = '#f59e0b';
                } else if (type === 'success') {
                    iconEl.innerHTML = '<i class="fa-solid fa-circle-check"></i>';
                    iconEl.style.color = '#16a34a';
                } else {
                    iconEl.innerHTML = '<i class="fa-solid fa-circle-info"></i>';
                    iconEl.style.color = 'var(--primary)';
                }
            }

            const cleanup = () => {
                modal.classList.remove('open');
                okBtn?.removeEventListener('click', handleOk);
                document.removeEventListener('keydown', handleKey);
                resolve();
            };

            const handleOk = (e) => {
                e?.preventDefault();
                cleanup();
            };

            const handleKey = (e) => {
                if (e.key === 'Escape' || e.key === 'Enter') {
                    e.preventDefault();
                    cleanup();
                }
            };

            okBtn?.addEventListener('click', handleOk, { once: true });
            document.addEventListener('keydown', handleKey);

            I18nManager.applyToDOM();
            modal.classList.add('open');
            setTimeout(() => okBtn?.focus(), 60);
        });
    }

    static showAppConfirm(message, title = null) {
        return new Promise((resolve) => {
            const modal = document.getElementById('modalAppConfirm');
            const msgEl = document.getElementById('appConfirmMessage');
            const titleEl = document.getElementById('appConfirmTitle');
            const okBtn = document.getElementById('btnAppConfirmOk');
            const cancelBtn = document.getElementById('btnAppConfirmCancel');

            if (!modal) {
                resolve(window.confirm(message));
                return;
            }

            const isKm = (I18nManager.getLang() === 'km');
            const defaultTitle = isKm ? 'ការបញ្ជាក់' : 'Confirmation';
            if (titleEl) titleEl.textContent = title || defaultTitle;
            if (msgEl) msgEl.textContent = message || '';

            const cleanup = (result) => {
                modal.classList.remove('open');
                okBtn?.removeEventListener('click', handleOk);
                cancelBtn?.removeEventListener('click', handleCancel);
                document.removeEventListener('keydown', handleKey);
                resolve(result);
            };

            const handleOk = (e) => {
                e?.preventDefault();
                cleanup(true);
            };

            const handleCancel = (e) => {
                e?.preventDefault();
                cleanup(false);
            };

            const handleKey = (e) => {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    cleanup(false);
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    cleanup(true);
                }
            };

            okBtn?.addEventListener('click', handleOk, { once: true });
            cancelBtn?.addEventListener('click', handleCancel, { once: true });
            document.addEventListener('keydown', handleKey);

            I18nManager.applyToDOM();
            modal.classList.add('open');
            setTimeout(() => okBtn?.focus(), 60);
        });
    }

    // ========================================================
    // REQUIREMENT 2: VEHICLE PLATE & TYPE PROMPT WINDOW
    // ========================================================
    static promptVehiclePlate(defaultVtype = 'small_car') {
        return new Promise((resolve) => {
            const modal = document.getElementById('modalPromptPlate');
            const input = document.getElementById('promptPlateInput');
            const errorEl = document.getElementById('promptPlateError');
            const btnSave = document.getElementById('btnSavePromptPlate');
            const btnCancel = document.getElementById('btnCancelPromptPlate');
            const btnClose = document.getElementById('btnClosePromptPlate');
            const vtypeBtns = modal?.querySelectorAll('.prompt-vtype-btn') || [];

            if (!modal || !input) {
                const entered = window.prompt(I18nManager.t('plateRequiredPrompt') || 'Please enter vehicle plate:');
                if (entered && entered.trim()) {
                    resolve({ plate: entered.trim().toUpperCase(), vehicleType: defaultVtype });
                } else {
                    resolve(null);
                }
                return;
            }

            // Set default selected vehicle type
            let currentVtype = defaultVtype || 'small_car';
            vtypeBtns.forEach(btn => {
                btn.classList.toggle('active', btn.getAttribute('data-vtype') === currentVtype);
            });

            const onVtypeClick = (e) => {
                const btn = e.target.closest('.prompt-vtype-btn');
                if (!btn) return;
                currentVtype = btn.getAttribute('data-vtype') || 'small_car';
                vtypeBtns.forEach(b => b.classList.toggle('active', b === btn));
            };
            vtypeBtns.forEach(b => b.addEventListener('click', onVtypeClick));

            input.value = '';
            if (errorEl) errorEl.style.display = 'none';

            const cleanup = (result) => {
                modal.classList.remove('open');
                btnSave?.removeEventListener('click', handleSave);
                btnCancel?.removeEventListener('click', handleCancel);
                btnClose?.removeEventListener('click', handleCancel);
                input?.removeEventListener('keydown', handleInputKey);
                document.removeEventListener('keydown', handleModalKey);
                vtypeBtns.forEach(b => b.removeEventListener('click', onVtypeClick));
                resolve(result);
            };

            const handleSave = (e) => {
                e?.preventDefault();
                const plateVal = (input.value || '').trim().toUpperCase();
                if (!plateVal || plateVal === 'WALK-IN') {
                    if (errorEl) errorEl.style.display = 'block';
                    input.focus();
                    return;
                }
                cleanup({ plate: plateVal, vehicleType: currentVtype });
            };

            const handleCancel = (e) => {
                e?.preventDefault();
                cleanup(null);
            };

            const handleInputKey = (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    handleSave(e);
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    handleCancel(e);
                }
            };

            const handleModalKey = (e) => {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    handleCancel(e);
                }
            };

            btnSave?.addEventListener('click', handleSave);
            btnCancel?.addEventListener('click', handleCancel);
            btnClose?.addEventListener('click', handleCancel);
            input?.addEventListener('keydown', handleInputKey);
            document.addEventListener('keydown', handleModalKey);

            I18nManager.applyToDOM();
            modal.classList.add('open');
            setTimeout(() => {
                input.focus();
                input.select();
            }, 60);
        });
    }

    // ========================================================
    // CASHIER CONTROL & SECURITY METHODS (Requirements 1-8)
    // ========================================================

    static activeShift = null;
    static isDayLocked = false;
    static currentBankSlipBase64 = null;
    static pendingStockOverrideProd = null;
    static mgrPinInput = '';
    static managerPinCallback = null;

    // 1. Shift Controls
    static async checkCashierShift() {
        if (!this.currentUser || this.currentUser.role !== 'cashier') return;
        try {
            const res = await PosApi.getShiftStatus(this.currentUser.id);
            if (res && res.has_active_shift && res.shift) {
                this.activeShift = res.shift;
                PosStorage.setCurrentShift(res.shift);
                this.updateShiftRegisterBlocked(false);
            } else {
                this.activeShift = null;
                PosStorage.removeCurrentShift();
                this.showStartShiftModal();
            }
        } catch (err) {
            console.warn('Could not verify shift status from backend:', err);
        }
    }

    static showStartShiftModal() {
        const modal = document.getElementById('modalStartShift');
        if (!modal) return;
        const nameEl = document.getElementById('startShiftCashierName');
        if (nameEl) nameEl.textContent = `${this.currentUser?.full_name || 'Cashier'} (@${this.currentUser?.username || ''})`;
        const usdInp = document.getElementById('startShiftUsd');
        const khrInp = document.getElementById('startShiftKhr');
        const notesInp = document.getElementById('startShiftNotes');
        if (usdInp) usdInp.value = '';
        if (khrInp) khrInp.value = '';
        if (notesInp) notesInp.value = '';
        modal.classList.add('open');
        this.updateShiftRegisterBlocked(true);
    }

    static updateShiftRegisterBlocked(isBlocked) {
        const payBtn = document.getElementById('btnOpenCheckout') || document.getElementById('btnOpenPaymentModal');
        if (!payBtn) return;
        if (isBlocked) {
            payBtn.disabled = true;
            payBtn.style.opacity = '0.5';
            payBtn.style.pointerEvents = 'none';
            payBtn.innerHTML = `<i class="fa-solid fa-door-closed mr-1"></i> Start Shift First`;
        } else if (!this.isDayLocked && !this.isDayClosed) {
            payBtn.disabled = false;
            payBtn.style.opacity = '1';
            payBtn.style.pointerEvents = '';
            payBtn.innerHTML = `<i class="fa-solid fa-cash-register mr-1"></i> <span data-i18n="chargeBtn">គិតប្រាក់</span>`;
        }
    }

    static async handleStartShiftSubmit() {
        const usdInp = document.getElementById('startShiftUsd');
        const khrInp = document.getElementById('startShiftKhr');
        const notesInp = document.getElementById('startShiftNotes');
        const btn = document.getElementById('btnConfirmStartShift');

        const openingUsd = parseFloat(usdInp?.value || 0);
        const openingKhr = parseInt(khrInp?.value || 0);
        const notes = notesInp?.value.trim() || '';

        btn.disabled = true;
        btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Opening Shift...`;

        try {
            const res = await PosApi.startShift({
                cashierId: this.currentUser?.id,
                cashierName: this.currentUser?.full_name,
                openingCashUsd: openingUsd,
                openingCashKhr: openingKhr,
                notes
            });
            if (res && res.success) {
                this.activeShift = res.shift || {
                    id: res.shift_id,
                    cashier_id: this.currentUser?.id,
                    cashier_name: this.currentUser?.full_name,
                    opening_cash_usd: openingUsd,
                    opening_cash_khr: openingKhr,
                    started_at: new Date().toISOString()
                };
                PosStorage.setCurrentShift(this.activeShift);
                document.getElementById('modalStartShift')?.classList.remove('open');
                this.updateShiftRegisterBlocked(false);
                this.showToastNotice('Shift started successfully! Starting cash recorded.', 'success');
            } else {
                alert(res?.error || 'Failed to start shift');
            }
        } catch (err) {
            alert(err.message || 'Failed to start shift');
        } finally {
            btn.disabled = false;
            btn.innerHTML = `<i class="fa-solid fa-door-open mr-1"></i> <span data-i18n="openShiftBtn">បើកវេនការងារ (Open Shift)</span>`;
        }
    }

    static showEndShiftModal() {
        const modal = document.getElementById('modalEndShift');
        if (!modal) return;
        const curCashier = document.getElementById('endShiftCurrentCashier');
        const startTime = document.getElementById('endShiftStartTime');
        if (curCashier) curCashier.textContent = `${this.currentUser?.full_name || 'Cashier'} (@${this.currentUser?.username || ''})`;
        if (startTime) {
            const shift = this.activeShift || PosStorage.getCurrentShift();
            startTime.textContent = shift?.opened_at || shift?.started_at || 'Current Shift';
        }
        const countUsd = document.getElementById('endShiftCountUsd');
        const countKhr = document.getElementById('endShiftCountKhr');
        const outPin = document.getElementById('endShiftOutPin');
        const inUser = document.getElementById('endShiftInUser');
        const inPin = document.getElementById('endShiftInPin');
        const notes = document.getElementById('endShiftNotes');

        if (countUsd) countUsd.value = '';
        if (countKhr) countKhr.value = '';
        if (outPin) outPin.value = '';
        if (inUser) inUser.value = '';
        if (inPin) inPin.value = '';
        if (notes) notes.value = '';

        modal.classList.add('open');
    }

    static async handleEndShiftSubmit() {
        const shift = this.activeShift || PosStorage.getCurrentShift();
        const shiftId = shift?.id || 0;
        const countUsd = parseFloat(document.getElementById('endShiftCountUsd')?.value || 0);
        const countKhr = parseInt(document.getElementById('endShiftCountKhr')?.value || 0);
        const outPin = document.getElementById('endShiftOutPin')?.value.trim() || '';
        const inUser = document.getElementById('endShiftInUser')?.value.trim() || '';
        const inPin = document.getElementById('endShiftInPin')?.value.trim() || '';
        const notes = document.getElementById('endShiftNotes')?.value.trim() || '';
        const btn = document.getElementById('btnConfirmEndShift');

        if (!outPin || outPin.length !== 6) {
            alert('Please enter your 6-digit outgoing cashier PIN');
            return;
        }
        if (!inUser) {
            alert('Please enter the incoming next cashier username');
            return;
        }
        if (!inPin || inPin.length !== 6) {
            alert('Please enter the incoming next cashier 6-digit PIN');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Handing over shift...`;

        try {
            const res = await PosApi.endShift({
                shiftId,
                countedCashUsd: countUsd,
                countedCashKhr: countKhr,
                outgoingPin: outPin,
                incomingUsername: inUser,
                incomingPin: inPin,
                notes
            });
            if (res && res.success) {
                document.getElementById('modalEndShift')?.classList.remove('open');
                this.activeShift = null;
                PosStorage.removeCurrentShift();
                this.showAppAlert(`Shift ended and handed over successfully from ${res.outgoing_cashier} to ${res.incoming_cashier}. The app will now log out.`, 'success', 'Shift Handover Complete');
                this.handleLogout();
            } else {
                alert(res?.error || 'Failed to end shift and handover');
            }
        } catch (err) {
            alert(err.message || 'Failed to end shift and handover');
        } finally {
            btn.disabled = false;
            btn.innerHTML = `<i class="fa-solid fa-arrow-right-from-bracket mr-1"></i> បញ្ចប់វេន និងផ្ទេរវេន`;
        }
    }

    // 2. Manager PIN Window
    static requestManagerPin(title, subtitle, onVerifiedCallback) {
        this.managerPinCallback = onVerifiedCallback;
        this.mgrPinInput = '';

        const modal = document.getElementById('modalManagerPin');
        if (!modal) return;
        const tEl = document.getElementById('managerPinTitle');
        const sEl = document.getElementById('managerPinSubtitle');
        const uInp = document.getElementById('managerPinUsername');
        const errEl = document.getElementById('mgrPinError');

        if (tEl) tEl.textContent = title || 'Manager Authorization';
        if (sEl) sEl.textContent = subtitle || 'This action requires Manager or Owner PIN verification.';

        const curRole = (this.currentUser?.role || '').toLowerCase();
        if (curRole === 'manager' || curRole === 'owner') {
            if (uInp) uInp.value = this.currentUser.username;
        } else {
            if (uInp) uInp.value = '';
        }

        if (errEl) {
            errEl.textContent = '';
            errEl.style.display = 'none';
        }

        this.updateMgrPinDots();
        modal.classList.add('open');
    }

    static updateMgrPinDots() {
        const dots = document.querySelectorAll('#mgrPinDots .pin-dot');
        dots.forEach((dot, idx) => {
            dot.classList.toggle('filled', idx < this.mgrPinInput.length);
        });
    }

    static async handleConfirmMgrPin() {
        const uInp = document.getElementById('managerPinUsername');
        const errEl = document.getElementById('mgrPinError');
        const username = uInp?.value.trim() || '';
        const pin = this.mgrPinInput;
        const btn = document.getElementById('btnConfirmMgrPin');

        if (!username) {
            if (errEl) {
                errEl.textContent = 'Manager username is required.';
                errEl.style.display = 'block';
            }
            return;
        }
        if (!pin || pin.length !== 6) {
            if (errEl) {
                errEl.textContent = 'Please enter 6-digit manager PIN.';
                errEl.style.display = 'block';
            }
            return;
        }

        btn.disabled = true;
        btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Verifying...`;

        try {
            const res = await PosApi.verifyManagerPin(username, pin);
            if (res && res.success && res.manager) {
                document.getElementById('modalManagerPin')?.classList.remove('open');
                const mgr = {
                    id: res.manager.manager_id || res.manager.id,
                    username: res.manager.username || username,
                    full_name: res.manager.manager_name || res.manager.full_name || username,
                    role: res.manager.manager_role || res.manager.role,
                    pin: pin
                };
                const cb = this.managerPinCallback;
                this.managerPinCallback = null;
                this.mgrPinInput = '';
                if (typeof cb === 'function') {
                    await cb(mgr);
                }
            } else {
                throw new Error(res?.error || 'Verification failed');
            }
        } catch (err) {
            if (errEl) {
                errEl.textContent = err.data?.message || err.message || 'Invalid manager username or PIN.';
                errEl.style.display = 'block';
            }
            this.mgrPinInput = '';
            this.updateMgrPinDots();
        } finally {
            btn.disabled = false;
            btn.innerHTML = `អនុញ្ញាត (Authorize)`;
        }
    }

    // 3. Day Lock
    static async checkDayLockStatus() {
        try {
            const res = await PosApi.getDayLockStatus();
            this.isDayLocked = !!res?.is_locked;
            this.updateDayLockUI(this.isDayLocked, res?.message || 'Sales are locked. Day has been closed.');
        } catch (err) {
            console.warn('Could not fetch day lock status:', err);
        }
    }

    static updateDayLockUI(isLocked, message = '') {
        const banner = document.getElementById('posDayLockBanner');
        const bannerText = document.getElementById('posDayLockText');
        const payBtn = document.getElementById('btnOpenCheckout') || document.getElementById('btnOpenPaymentModal');

        if (banner) {
            banner.style.display = isLocked ? 'flex' : 'none';
            if (bannerText && message) bannerText.textContent = message;
        }

        if (payBtn) {
            if (isLocked) {
                payBtn.disabled = true;
                payBtn.style.opacity = '0.5';
                payBtn.style.pointerEvents = 'none';
                payBtn.innerHTML = `<i class="fa-solid fa-lock mr-1"></i> Sales Locked`;
            } else if (!this.activeShift && this.currentUser?.role === 'cashier') {
                this.updateShiftRegisterBlocked(true);
            } else {
                payBtn.disabled = false;
                payBtn.style.opacity = '1';
                payBtn.style.pointerEvents = '';
                payBtn.innerHTML = `<i class="fa-solid fa-cash-register mr-1"></i> <span data-i18n="chargeBtn">គិតប្រាក់</span>`;
            }
        }
    }

    // 4. Offline Manual Mode Banner (Requirement 5)
    static lastSyncSuccessTime = Date.now();

    static updateOfflineManualBanner() {
        const banner = document.getElementById('offlineManualModeBanner');
        const elapsed = Date.now() - (this.lastSyncSuccessTime || Date.now());
        const isExpired = elapsed > 60000;
        const shouldShow = (!this.isOnline || !navigator.onLine || isExpired);
        if (banner) {
            banner.style.display = shouldShow ? 'flex' : 'none';
        }

        // Requirement 5: If connection is lost (>60s or offline), hide wait times
        if (shouldShow) {
            document.querySelectorAll('.service-wait-time-badge').forEach(el => el.style.display = 'none');
            const cartWaitRow = document.getElementById('cartWaitTimeRow');
            if (cartWaitRow) cartWaitRow.style.display = 'none';
        } else {
            this.updateWaitTimesUI();
        }
    }

    // 5. Stock Override
    static addOverrideProductToCart(prod, mgr) {
        const exchangeRate = this.getExchangeRate();
        const priceUsd = parseFloat(prod.price_usd || 0);
        const priceKhr = prod.price_khr ? parseInt(prod.price_khr) : Math.round(priceUsd * exchangeRate);
        const type = (prod.category === 'spare_part' || prod.category === 'oil_filter') ? 'spare_part' : 'product';

        this.cart.push({
            type,
            service_id: null,
            product_id: prod.id,
            name_en: prod.name_en,
            name_km: prod.name_km,
            price_usd: priceUsd,
            price_khr: priceKhr,
            qty: 1,
            manager_override: true,
            manager_username: mgr.username,
            manager_pin: mgr.pin
        });

        this.renderCart();
        this.showToastNotice(`Item added via Manager Override (${mgr.full_name})`, 'success');
    }

    // 6. Slip Photo Capture in Payment Modal
    static setupSlipPhotoHandlers() {
        const fileInp = document.getElementById('bankSlipFileInput');
        const triggerBtn = document.getElementById('btnTriggerSlipCapture');
        const removeBtn = document.getElementById('btnRemoveSlipPhoto');
        const previewContainer = document.getElementById('bankSlipPreviewContainer');
        const previewImg = document.getElementById('bankSlipPreviewImg');

        triggerBtn?.addEventListener('click', () => {
            fileInp?.click();
        });

        fileInp?.addEventListener('change', (e) => {
            const file = e.target.files?.[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = (loadEvt) => {
                PosApp.currentBankSlipBase64 = loadEvt.target.result;
                if (previewImg) previewImg.src = loadEvt.target.result;
                if (previewContainer) previewContainer.style.display = 'block';
                if (triggerBtn) triggerBtn.style.display = 'none';
            };
            reader.readAsDataURL(file);
        });

        removeBtn?.addEventListener('click', () => {
            PosApp.currentBankSlipBase64 = null;
            if (fileInp) fileInp.value = '';
            if (previewImg) previewImg.src = '';
            if (previewContainer) previewContainer.style.display = 'none';
            if (triggerBtn) triggerBtn.style.display = 'inline-flex';
        });
    }

    // 7. Spot Cash Audit
    static showSpotAuditOverlay() {
        const overlay = document.getElementById('overlaySpotAudit');
        if (!overlay) return;
        const usdInp = document.getElementById('spotAuditUsd');
        const khrInp = document.getElementById('spotAuditKhr');
        const mgrUserInp = document.getElementById('spotAuditMgrUser');
        const mgrPinInp = document.getElementById('spotAuditMgrPin');
        const notesInp = document.getElementById('spotAuditNotes');

        if (usdInp) usdInp.value = '';
        if (khrInp) khrInp.value = '';
        const curRole = (this.currentUser?.role || '').toLowerCase();
        if (curRole === 'manager' || curRole === 'owner') {
            if (mgrUserInp) mgrUserInp.value = this.currentUser.username;
        } else {
            if (mgrUserInp) mgrUserInp.value = '';
        }
        if (mgrPinInp) mgrPinInp.value = '';
        if (notesInp) notesInp.value = '';

        overlay.style.display = 'flex';
    }

    static async handleSpotAuditSubmit() {
        const usdInp = document.getElementById('spotAuditUsd');
        const khrInp = document.getElementById('spotAuditKhr');
        const mgrUserInp = document.getElementById('spotAuditMgrUser');
        const mgrPinInp = document.getElementById('spotAuditMgrPin');
        const notesInp = document.getElementById('spotAuditNotes');
        const btn = document.getElementById('btnSubmitSpotAudit');

        const countedUsd = parseFloat(usdInp?.value || 0);
        const countedKhr = parseInt(khrInp?.value || 0);
        const mgrUser = mgrUserInp?.value.trim() || '';
        const mgrPin = mgrPinInp?.value.trim() || '';
        const notes = notesInp?.value.trim() || '';

        if (!mgrUser) {
            alert('Manager username is required for spot audit');
            return;
        }
        if (!mgrPin || mgrPin.length !== 6) {
            alert('Manager 6-digit PIN is required');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Submitting Audit...`;

        try {
            const res = await PosApi.conductSpotAudit({
                cashierId: this.currentUser?.id,
                countedCashUsd: countedUsd,
                countedCashKhr: countedKhr,
                managerUsername: mgrUser,
                managerPin: mgrPin,
                notes
            });
            if (res && res.success) {
                document.getElementById('overlaySpotAudit').style.display = 'none';
                this.showAppAlert(`Drawer counted and verified by Manager ${res.manager_name || mgrUser}. Recorded into audit log.`, 'success', 'Spot Audit Completed');
            } else {
                alert(res?.error || 'Spot audit failed');
            }
        } catch (err) {
            alert(err.message || 'Spot audit failed');
        } finally {
            btn.disabled = false;
            btn.innerHTML = `<i class="fa-solid fa-check mr-1"></i> <span>បញ្ជូនលទ្ធផលរាប់ (Submit Audit)</span>`;
        }
    }

    // ========================================================
    // SHOP FLOOR CONTROLS & LIVE STREAMS (Requirements 1, 4, 5)
    // ========================================================

    static renderBayStatusStrip(bays, washQueue = [], techQueue = []) {
        const stripChips = document.getElementById('stripBayChips');
        const countEl = document.getElementById('stripWaitingCount');
        if (!stripChips) return;

        const totalWaiting = (washQueue?.length || 0) + (techQueue?.length || 0);
        if (countEl) countEl.textContent = totalWaiting;

        if (!Array.isArray(bays) || bays.length === 0) {
            stripChips.innerHTML = `<span style="font-size: 0.75rem; color: var(--text-muted);">No bays available</span>`;
            return;
        }

        let html = '';
        bays.forEach(bay => {
            const isFree = (bay.status === 'free');
            const isTech = (bay.bay_type === 'oil' || bay.bay_type === 'oil_change');
            const statusClass = isFree ? 'free' : 'busy';
            const plate = isFree ? 'Free' : (bay.plate_number || 'Busy');

            html += `
                <div class="bay-chip ${statusClass} ${isTech ? 'tech' : ''}">
                    <span class="bay-chip-dot"></span>
                    <span class="bay-chip-name">${this.escapeHtml(bay.bay_name)}:</span>
                    <strong>${this.escapeHtml(plate)}</strong>
                </div>
            `;
        });

        stripChips.innerHTML = html;
    }

    static async handleReceiptCustomerArrived() {
        const ord = this.lastCreatedOrderRecord || this.currentReceiptOrder || this.lastCompletedOrder;
        if (!ord || !ord.id) return;

        const btn = document.getElementById('btnReceiptCustomerArrived');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin mr-1"></i> Marking...`;
        }

        try {
            const res = await PosApi.markCustomerArrived(ord.id, ord.plate_number || '');
            if (res && res.success) {
                if (btn) {
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-secondary');
                    btn.innerHTML = `<i class="fa-solid fa-check mr-1 text-success"></i> Customer Arrived`;
                }
                this.showToastNotice(res.message || 'Customer marked arrived. Car joined queue.', 'success');
                this.playChimeSound();
                await this.fetchBaysAndQueue(true);
            } else {
                throw new Error(res?.message || 'Could not mark arrived');
            }
        } catch (e) {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = `<i class="fa-solid fa-car-on mr-1"></i> Customer Arrived`;
            }
            this.showAppAlert(e.data?.message || e.message || 'Failed to mark customer arrived');
        }
    }

    static _audioCtx = null;
    static playChimeSound() {
        try {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (!AudioContext) return;
            if (!this._audioCtx) {
                this._audioCtx = new AudioContext();
            }
            if (this._audioCtx.state === 'suspended') {
                this._audioCtx.resume();
            }
            const ctx = this._audioCtx;
            const now = ctx.currentTime;
            const osc = ctx.createOscillator();
            const osc2 = ctx.createOscillator();
            const gain = ctx.createGain();

            osc.type = 'sine';
            osc.frequency.setValueAtTime(587.33, now); // D5
            osc.frequency.exponentialRampToValueAtTime(880, now + 0.15); // A5

            osc2.type = 'triangle';
            osc2.frequency.setValueAtTime(880, now + 0.15);

            gain.gain.setValueAtTime(0.25, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.6);

            osc.connect(gain);
            osc2.connect(gain);
            gain.connect(ctx.destination);

            osc.start(now);
            osc.stop(now + 0.6);
            osc2.start(now + 0.15);
            osc2.stop(now + 0.6);
        } catch (e) {
            console.warn('Audio chime playback error:', e);
        }
    }

    static lastUnreadCount = 0;
    static async pollNotifications() {
        try {
            const res = await PosApi.getNotifications(this.currentUser?.id);
            if (res && res.success) {
                const count = parseInt(res.unread_count || 0);
                const badge = document.getElementById('notifBadge');
                if (badge) {
                    badge.textContent = count;
                    badge.style.display = count > 0 ? 'flex' : 'none';
                }

                if (count > this.lastUnreadCount && this.lastUnreadCount !== 0) {
                    this.playChimeSound();
                }
                this.lastUnreadCount = count;

                // Requirement 1 & 2: Check for system updates (prices, users, permissions)
                if (res.system_revision && res.system_revision.hash) {
                    await this.handleSystemRevisionCheck(res.system_revision);
                }

                // Render notification items in list
                const list = document.getElementById('notificationList');
                if (list) {
                    const notifs = res.notifications || [];
                    if (notifs.length === 0) {
                        list.innerHTML = `<div class="notification-empty">No notifications</div>`;
                    } else {
                        let html = '';
                        notifs.forEach(n => {
                            const isUnread = (!n.is_read || n.is_read === '0');
                            html += `
                                <div class="notification-item ${isUnread ? 'unread' : ''}" onclick="PosApp.handleMarkNotifRead(${n.id})">
                                    <div class="notification-item-icon">
                                        <i class="fa-solid fa-bell"></i>
                                    </div>
                                    <div class="notification-item-content">
                                        <div class="notification-item-title">${this.escapeHtml(n.title_en || n.title_km || 'Notification')}</div>
                                        <div class="notification-item-message">${this.escapeHtml(n.message_en || n.message_km || '')}</div>
                                        <div class="notification-item-time">${n.created_at || ''}</div>
                                    </div>
                                </div>
                            `;
                        });
                        list.innerHTML = html;
                    }
                }
            }
        } catch (e) {
            console.warn('Notification poll error:', e);
        }
    }

    static async handleMarkAllNotifsRead() {
        try {
            await PosApi.markAllNotificationsRead();
            this.lastUnreadCount = 0;
            const badge = document.getElementById('notifBadge');
            if (badge) badge.style.display = 'none';
            document.querySelectorAll('.notification-item.unread').forEach(el => el.classList.remove('unread'));
        } catch (e) {
            console.warn('Mark all notifs read error:', e);
        }
    }

    static async handleMarkNotifRead(notificationId) {
        try {
            const res = await PosApi.markNotificationRead(notificationId);
            if (res && res.success) {
                const count = parseInt(res.unread_count || 0);
                this.lastUnreadCount = count;
                const badge = document.getElementById('notifBadge');
                if (badge) {
                    badge.textContent = count;
                    badge.style.display = count > 0 ? 'flex' : 'none';
                }
            }
        } catch (e) {
            console.warn('Mark notif read error:', e);
        }
    }

    // ========================================================
    // SYSTEM UPDATE NOTICE & CART PRICE CHANGES (REQUIREMENTS 1 & 2)
    // ========================================================
    static lastSystemRevision = null;
    static isHandlingSystemUpdate = false;
    static pendingPriceChangesQueue = [];

    static async handleSystemRevisionCheck(newRev) {
        if (!newRev || !newRev.hash) return;

        // Initialize on first poll without firing alert
        if (this.lastSystemRevision === null) {
            this.lastSystemRevision = newRev.hash;
            return;
        }

        // If revision hasn't changed, do nothing
        if (this.lastSystemRevision === newRev.hash) {
            return;
        }

        if (this.isHandlingSystemUpdate) return;
        this.isHandlingSystemUpdate = true;

        try {
            console.log('System revision updated in back office:', newRev.hash);
            this.lastSystemRevision = newRev.hash;

            // 1. Show small message for a few seconds (Requirement 1)
            const updateMsg = I18nManager.t('systemUpdateNotice') || 'System update: prices or permissions have been updated';
            this.showToastNotice(updateMsg, 'info');

            // 2. Fetch new catalog / permissions / prices from back office
            const newCatalogData = await PosApi.syncCatalog();
            if (newCatalogData && newCatalogData.success) {
                // Update local storage catalog and settings
                PosStorage.setCatalog(newCatalogData, newCatalogData.synced_at || new Date().toISOString());

                // Update current user permissions if returned
                if (newRev.user_permissions && this.currentUser) {
                    this.currentUser.permissions = newRev.user_permissions;
                    PosStorage.setUser(this.currentUser);
                }

                // Update catalog UI for next sale (without losing cart)
                this.renderCategories();
                this.renderCatalog();
                this.populateCrewDropdowns();

                // 3. Requirement 2: Check if cart ALREADY holds an item whose price changed
                this.checkCartPriceChanges(newCatalogData);
            }
        } catch (e) {
            console.warn('Error handling system update:', e);
        } finally {
            this.isHandlingSystemUpdate = false;
        }
    }

    static checkCartPriceChanges(newCatalogData) {
        if (!Array.isArray(this.cart) || this.cart.length === 0) return;

        const matrix = newCatalogData.pricing_matrix || {};
        const vtype = this.selectedVehicleType || 'small_car';
        const exchangeRate = this.getExchangeRate();

        this.pendingPriceChangesQueue = [];

        this.cart.forEach((item, index) => {
            if (item.is_member_benefit || item.is_upsell) return;
            let newPriceUsd = null;
            if (item.type === 'service') {
                const pObj = matrix[item.service_id || item.id]?.[vtype];
                if (pObj !== undefined) {
                    newPriceUsd = (typeof pObj === 'number') ? pObj : (pObj.usd || 0);
                }
            } else {
                const prod = (newCatalogData.products || []).find(p => p.id == (item.product_id || item.id));
                if (prod) {
                    newPriceUsd = parseFloat(prod.price_usd || 0);
                }
            }

            if (newPriceUsd !== null && Math.abs(item.price_usd - newPriceUsd) > 0.001) {
                this.pendingPriceChangesQueue.push({
                    cartIndex: index,
                    item: item,
                    oldPriceUsd: item.price_usd,
                    oldPriceKhr: item.price_khr,
                    newPriceUsd: newPriceUsd,
                    newPriceKhr: Math.round(newPriceUsd * exchangeRate)
                });
            }
        });

        if (this.pendingPriceChangesQueue.length > 0) {
            this.showNextCartPriceChangeModal();
        }
    }

    static showNextCartPriceChangeModal() {
        const modal = document.getElementById('modalCartPriceChange');
        if (!modal) return;

        if (!this.pendingPriceChangesQueue || this.pendingPriceChangesQueue.length === 0) {
            modal.classList.remove('open');
            return;
        }

        const change = this.pendingPriceChangesQueue[0];
        const nameEl = document.getElementById('priceChangeItemName');
        const oldPriceEl = document.getElementById('priceChangeOldPrice');
        const newPriceEl = document.getElementById('priceChangeNewPrice');

        const isKm = (I18nManager.getLang() === 'km');
        const displayName = isKm ? (change.item.name_km || change.item.name_en) : (change.item.name_en || change.item.name_km);

        if (nameEl) nameEl.textContent = displayName;
        if (oldPriceEl) oldPriceEl.textContent = `$${change.oldPriceUsd.toFixed(2)} (${change.oldPriceKhr.toLocaleString()} ៛)`;
        if (newPriceEl) newPriceEl.textContent = `$${change.newPriceUsd.toFixed(2)} (${change.newPriceKhr.toLocaleString()} ៛)`;

        modal.classList.add('open');
    }

    static handleAcceptPriceChange() {
        if (!this.pendingPriceChangesQueue || this.pendingPriceChangesQueue.length === 0) {
            const modal = document.getElementById('modalCartPriceChange');
            if (modal) modal.classList.remove('open');
            return;
        }

        const change = this.pendingPriceChangesQueue.shift();
        if (change && this.cart[change.cartIndex]) {
            this.cart[change.cartIndex].price_usd = change.newPriceUsd;
            this.cart[change.cartIndex].price_khr = change.newPriceKhr;
            this.renderCart();
            const msg = `Updated price for ${change.item.name_en || 'item'} to $${change.newPriceUsd.toFixed(2)}`;
            this.showToastNotice(msg, 'success');
        }

        this.showNextCartPriceChangeModal();
    }

    static handleRejectPriceChange() {
        if (!this.pendingPriceChangesQueue || this.pendingPriceChangesQueue.length === 0) {
            const modal = document.getElementById('modalCartPriceChange');
            if (modal) modal.classList.remove('open');
            return;
        }

        const change = this.pendingPriceChangesQueue.shift();
        if (change) {
            const msg = `Kept current price ($${change.oldPriceUsd.toFixed(2)}) for ${change.item.name_en || 'item'}`;
            this.showToastNotice(msg, 'info');
        }

        this.showNextCartPriceChangeModal();
    }

    static currentWaitData = null;
    static _dismissedOver45Key = '';
    static selectedApptSlot = null;

    static getCartFingerprint() {
        return this.cart.map(i => `${i.id || i.service_id || i.product_id}_${i.qty}`).join('|') + '_' + (this.selectedVehicleType || '');
    }

    // ========================================================
    // WAIT TIMES ENGINE (AUTHORITATIVE BACK OFFICE)
    // ========================================================
    static async fetchWaitTimes() {
        if (!this.isOnline || !navigator.onLine) return;
        const serviceIds = this.cart.filter(i => i.type === 'service').map(i => i.service_id || i.id);
        const vtype = this.selectedVehicleType || 'sedan';
        try {
            const res = await PosApi.getWaitTimes(serviceIds, vtype);
            if (res && res.success) {
                this.currentWaitData = res;
                this.updateWaitTimesUI();
            }
        } catch (e) {
            console.warn('Failed to fetch wait times from back office:', e);
        }
    }

    static updateWaitTimesUI() {
        const elapsed = Date.now() - (this.lastSyncSuccessTime || Date.now());
        const isOffline = (!this.isOnline || !navigator.onLine || elapsed > 60000);
        if (isOffline) {
            document.querySelectorAll('.service-wait-time-badge').forEach(el => el.style.display = 'none');
            const cartWaitRow = document.getElementById('cartWaitTimeRow');
            if (cartWaitRow) cartWaitRow.style.display = 'none';
            return;
        }

        const waitData = this.currentWaitData;
        if (!waitData) return;

        const isKm = (I18nManager.getLang() === 'km');

        // 1. Update service card wait badges
        const servicesMap = waitData.services_wait || waitData.services;
        if (servicesMap) {
            Object.entries(servicesMap).forEach(([svcId, sData]) => {
                const badges = document.querySelectorAll(`.service-wait-time-badge[data-service-id="${svcId}"]`);
                const txt = isKm ? (sData.wait_text_km || sData.wait_text_en) : sData.wait_text_en;
                badges.forEach(b => {
                    b.innerHTML = `<i class="fa-regular fa-clock"></i> ${PosApp.escapeHtml(txt)}`;
                    b.style.display = 'inline-flex';
                });
            });
        }

        // 2. Update cart wait time row
        const cartWaitRow = document.getElementById('cartWaitTimeRow');
        const cartWaitBadge = document.getElementById('cartWaitTimeBadge');
        const hasServicesInCart = this.cart.some(it => it.type === 'service');

        if (cartWaitRow && cartWaitBadge && waitData.cart_wait && hasServicesInCart) {
            const cWait = waitData.cart_wait;
            const txt = isKm ? (cWait.wait_text_km || cWait.wait_text_en) : cWait.wait_text_en;
            cartWaitBadge.innerHTML = `<i class="fa-regular fa-clock"></i> ${PosApp.escapeHtml(txt)}`;
            cartWaitRow.style.display = 'flex';

            // 3. Over 45 min warning prompt (Requirement 2)
            if (cWait.is_over_45) {
                const cartKey = this.getCartFingerprint();
                if (this._dismissedOver45Key !== cartKey) {
                    this.openLongWaitWarning(cWait.wait_minutes || cWait.total_wait_minutes || 45);
                }
            }
        } else if (cartWaitRow) {
            cartWaitRow.style.display = 'none';
        }
    }

    static openLongWaitWarning(waitMins) {
        const modal = document.getElementById('modalLongWaitWarning');
        const span = document.getElementById('longWaitMinsText');
        if (span) span.textContent = `${waitMins} min`;
        if (modal) modal.classList.add('open');
    }

    // ========================================================
    // VEHICLE RECOGNITION (AUTHORITATIVE BACK OFFICE)
    // ========================================================
    static async loadVehicleProfile(plate) {
        if (!plate) return;
        const cleanPlate = (plate || '').trim().toUpperCase();
        if (!cleanPlate) return;

        try {
            const res = await PosApi.recognizeVehicle(cleanPlate);
            if (res && res.success) {
                const profile = res.profile || res;
                this.renderVehicleProfileUI(profile);
            }
        } catch (e) {
            console.warn('Error recognizing vehicle:', e);
        }
    }

    static renderVehicleProfileUI(data) {
        const profWrap = document.getElementById('cartVehicleProfile');
        if (!profWrap) return;

        const plateBadge = document.getElementById('profPlateBadge');
        const tierBadge = document.getElementById('profTierBadge');
        const custName = document.getElementById('profCustName');
        const washCount = document.getElementById('profWashCount');
        const oilText = document.getElementById('profOilText');
        const flagsList = document.getElementById('profFlagsList');

        if (plateBadge) plateBadge.textContent = data.plate_number || '-';
        if (tierBadge) {
            tierBadge.textContent = data.membership_level || data.membership_tier || 'Standard';
            tierBadge.className = `profile-tier-badge ${data.tier_class || 'regular'}`;
            if (data.membership_color) {
                tierBadge.style.backgroundColor = data.membership_color;
            }
        }
        if (custName) custName.textContent = data.customer_name || 'Walk-in Guest';
        if (washCount) washCount.textContent = `${data.wash_history_count !== undefined ? data.wash_history_count : (data.wash_count || 0)} washes`;

        if (oilText) {
            const oilDate = data.last_oil_change_date || (data.last_oil_change && data.last_oil_change.date);
            const oilKm = data.last_oil_change_mileage || (data.last_oil_change && data.last_oil_change.mileage);
            if (oilDate) {
                const km = oilKm ? ` (${Number(oilKm).toLocaleString()} km)` : '';
                oilText.textContent = `${oilDate}${km}`;
            } else {
                oilText.textContent = 'No record';
            }
        }

        if (flagsList) {
            if (Array.isArray(data.flags) && data.flags.length > 0) {
                flagsList.innerHTML = data.flags.map(f => {
                    const text = typeof f === 'object' ? (f.label || f.label_km || f.text || '') : String(f);
                    const icon = (typeof f === 'object' && f.icon) ? f.icon : 'fa-star';
                    return `<span class="profile-flag-chip"><i class="fa-solid ${icon}"></i> ${this.escapeHtml(text)}</span>`;
                }).join('');
                flagsList.style.display = 'flex';
            } else {
                flagsList.innerHTML = '';
                flagsList.style.display = 'none';
            }
        }

        // Member Panel (Requirement 1: show tier, visits this month, and what is included or used up)
        const isKm = (I18nManager.getLang() === 'km');
        const memPanel = document.getElementById('profMemberPanel');
        const memTierName = document.getElementById('memberTierName');
        const memVisitsCount = document.getElementById('memberVisitsCount');
        const benefitsChips = document.getElementById('profBenefitsChips');

        const memObj = data.membership || {};
        const tierName = data.membership_level || memObj.tier || 'Standard';
        const visitsThisMonth = data.visits_this_month !== undefined ? data.visits_this_month : (memObj.visits_this_month || 0);
        const benefits = memObj.benefits || [];
        this.currentCustomerBenefits = benefits;

        if (memPanel) {
            if (memTierName) memTierName.textContent = isKm ? (memObj.tier_name_km || tierName) : tierName;
            if (memVisitsCount) memVisitsCount.textContent = visitsThisMonth;

            if (benefitsChips) {
                if (benefits.length > 0) {
                    benefitsChips.innerHTML = benefits.map(b => {
                        const isUsed = b.is_used_up;
                        const chipClass = isUsed ? 'benefit-chip used-up' : 'benefit-chip available';
                        const chipIcon = isUsed ? 'fa-ban' : 'fa-circle-check';
                        const svcTitle = isKm ? (b.service_name_km || b.service_name) : b.service_name;
                        const statusTxt = isKm ? (b.status_text_km || b.status_text) : b.status_text;
                        return `<span class="${chipClass}"><i class="fa-solid ${chipIcon}"></i> ${this.escapeHtml(svcTitle)}: ${this.escapeHtml(statusTxt)}</span>`;
                    }).join('');
                } else {
                    benefitsChips.innerHTML = `<span class="text-muted" style="font-size: 0.72rem;">${isKm ? 'គ្មានសេវាឥតគិតថ្លៃក្នុងខែនេះទេ' : 'No complimentary services included'}</span>`;
                }
            }
            memPanel.style.display = 'block';
        }

        // Apply any eligible included services at no charge automatically (Requirement 1)
        this.applyMemberBenefitsToCart();

        // Loyalty Reward Prompt Banner (Requirement 3: when a reward is earned, show prompt)
        const rewardBanner = document.getElementById('cartLoyaltyRewardBanner');
        const rewardDesc = document.getElementById('loyaltyRewardDesc');
        const rewardBadge = document.getElementById('cartLoyaltyRewardStatusBadge');

        if (data.loyalty_reward && !this.loyaltyRewardActionChosen) {
            this.activeLoyaltyReward = data.loyalty_reward;
            if (rewardBanner) {
                if (rewardDesc) {
                    rewardDesc.textContent = isKm ? (data.loyalty_reward.prompt_text_km || data.loyalty_reward.prompt_text) : data.loyalty_reward.prompt_text;
                }
                rewardBanner.style.display = 'flex';
            }
            if (rewardBadge) rewardBadge.style.display = 'none';
        } else if (!this.loyaltyRewardActionChosen) {
            this.activeLoyaltyReward = null;
            if (rewardBanner) rewardBanner.style.display = 'none';
            if (rewardBadge) rewardBadge.style.display = 'none';
        }

        profWrap.style.display = 'block';
    }

    // ========================================================
    // MEMBER, UPSELL & LOYALTY REWARD LOGIC (Requirements 1, 2, 3)
    // ========================================================
    static applyMemberBenefitsToCart() {
        if (!this.currentCustomerBenefits || !Array.isArray(this.currentCustomerBenefits)) return;
        let modified = false;
        this.currentCustomerBenefits.forEach(benefit => {
            if (benefit.auto_free && !benefit.is_used_up && benefit.service_id) {
                const matchItem = this.cart.find(it => it.type === 'service' && Number(it.service_id || it.id) === Number(benefit.service_id));
                if (matchItem && !matchItem.is_member_benefit) {
                    matchItem.is_member_benefit = true;
                    matchItem.price_usd = 0.00;
                    matchItem.price_khr = 0;
                    matchItem.note = 'Included Member Benefit (No Charge)';
                    modified = true;
                }
            }
        });
        if (modified) {
            this.renderCart();
        }
    }

    static checkCartUpsellRules() {
        if (!this.cart || this.cart.length === 0) return;
        if (this._currentUpsellModalOpen) return;

        if (this._upsellCheckTimeout) {
            clearTimeout(this._upsellCheckTimeout);
        }
        this._upsellCheckTimeout = setTimeout(async () => {
            try {
                const plate = this.currentCustomer?.plate || '';
                const custId = this.currentCustomer?.customer_id || null;
                const res = await PosApi.evaluateCartUpsell(this.cart, custId, plate, this.selectedVehicleType);
                if (res && res.success && res.has_offer && res.offer) {
                    const offer = res.offer;
                    if (!this._dismissedUpsellIds.has(offer.id) && !this._currentUpsellModalOpen) {
                        this.showUpsellOfferModal(offer);
                    }
                }
            } catch (e) {
                console.warn('Upsell rule evaluation notice:', e);
            }
        }, 400);
    }

    static showUpsellOfferModal(offer) {
        const modal = document.getElementById('modalUpsellPrompt');
        if (!modal) return;
        this.activeUpsellOffer = offer;
        this._currentUpsellModalOpen = true;

        const isKm = (I18nManager.getLang() === 'km');
        const titleEl = document.getElementById('upsellModalTitle');
        const msgEl = document.getElementById('upsellModalMessage');

        if (titleEl) titleEl.textContent = isKm ? (offer.title_km || offer.title) : offer.title;
        if (msgEl) msgEl.textContent = isKm ? (offer.message_km || offer.message) : offer.message;

        modal.classList.add('open');
    }

    static handleDeclineUpsell() {
        if (this.activeUpsellOffer) {
            this._dismissedUpsellIds.add(this.activeUpsellOffer.id);
        }
        this._currentUpsellModalOpen = false;
        document.getElementById('modalUpsellPrompt')?.classList.remove('open');
    }

    static handleAcceptUpsell() {
        if (!this.activeUpsellOffer) return;
        const offer = this.activeUpsellOffer;
        this._dismissedUpsellIds.add(offer.id);
        this._currentUpsellModalOpen = false;
        document.getElementById('modalUpsellPrompt')?.classList.remove('open');

        const catalog = PosStorage.getCatalog();
        const services = catalog?.services || [];

        if (offer.action_type === 'upgrade_service') {
            const replaceIdx = this.cart.findIndex(it => it.type === 'service' && Number(it.service_id || it.id) === Number(offer.replace_service_id));
            const targetSvc = services.find(s => Number(s.id) === Number(offer.target_service_id)) || {
                id: offer.target_service_id,
                name_en: offer.target_name,
                name_km: offer.target_name_km || offer.target_name
            };
            const upgradedItem = {
                id: targetSvc.id,
                service_id: targetSvc.id,
                product_id: null,
                type: 'service',
                name_en: targetSvc.name_en,
                name_km: targetSvc.name_km || targetSvc.name_en,
                price_usd: Number(offer.offer_price_usd || 5.00),
                price_khr: Number(offer.offer_price_khr || 20500),
                qty: 1,
                is_upsell: true,
                offer_price_usd: Number(offer.offer_price_usd || 5.00),
                note: 'Upgraded Offer'
            };
            if (replaceIdx !== -1) {
                this.cart.splice(replaceIdx, 1, upgradedItem);
            } else {
                this.cart.push(upgradedItem);
            }
        } else if (offer.action_type === 'add_service') {
            const targetSvc = services.find(s => Number(s.id) === Number(offer.target_service_id)) || {
                id: offer.target_service_id,
                name_en: offer.target_name,
                name_km: offer.target_name_km || offer.target_name
            };
            this.cart.push({
                id: targetSvc.id,
                service_id: targetSvc.id,
                product_id: null,
                type: 'service',
                name_en: targetSvc.name_en,
                name_km: targetSvc.name_km || targetSvc.name_en,
                price_usd: Number(offer.offer_price_usd || 5.00),
                price_khr: Number(offer.offer_price_khr || 20500),
                qty: 1,
                is_upsell: true,
                offer_price_usd: Number(offer.offer_price_usd || 5.00),
                note: 'Upsell Offer'
            });
        }

        this.renderCart();
        this.showToastNotice(I18nManager.t('upgradeOffer') || 'Offer accepted!', 'success');
    }

    static handleGiveLoyaltyReward() {
        this.loyaltyRewardGiven = true;
        this.loyaltyRewardActionChosen = true;
        const banner = document.getElementById('cartLoyaltyRewardBanner');
        if (banner) banner.style.display = 'none';
        const badge = document.getElementById('cartLoyaltyRewardStatusBadge');
        if (badge) {
            badge.innerHTML = `<i class="fa-solid fa-gift"></i> ${I18nManager.t('rewardGivenBadge') || 'Reward: Free air freshener (Given)'}`;
            badge.style.display = 'flex';
        }
        this.showToastNotice('Loyalty reward recorded as Given', 'success');
    }

    static handleSkipLoyaltyReward() {
        this.loyaltyRewardGiven = false;
        this.loyaltyRewardActionChosen = true;
        const banner = document.getElementById('cartLoyaltyRewardBanner');
        if (banner) banner.style.display = 'none';
        const badge = document.getElementById('cartLoyaltyRewardStatusBadge');
        if (badge) {
            badge.innerHTML = `<i class="fa-solid fa-xmark"></i> ${I18nManager.t('rewardSkippedBadge') || 'Reward: Skipped'}`;
            badge.style.display = 'flex';
        }
    }

    // ========================================================
    // APPOINTMENTS SCHEDULER (AUTHORITATIVE BACK OFFICE)
    // ========================================================
    static async renderAppointmentsScreen() {
        const todayStr = new Date().toISOString().split('T')[0];
        const dateInput = document.getElementById('apptBookingDate');
        const viewDateInput = document.getElementById('apptViewDate');
        
        if (dateInput && !dateInput.value) {
            dateInput.value = todayStr;
            dateInput.min = todayStr;
        }
        if (viewDateInput && !viewDateInput.value) {
            viewDateInput.value = todayStr;
        }

        // Populate services dropdown if empty
        const svcSelect = document.getElementById('apptServiceSelect');
        if (svcSelect && svcSelect.options.length <= 1) {
            const catalog = PosStorage.getCatalog();
            const services = catalog?.services || [];
            services.forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = `${s.name_en} (${s.name_km || s.name_en})`;
                svcSelect.appendChild(opt);
            });
        }

        await this.loadAppointmentSlots();
        await this.loadAppointmentsList();
    }

    static async loadAppointmentSlots() {
        const grid = document.getElementById('apptTimeSlotsGrid');
        if (!grid) return;
        const dateInput = document.getElementById('apptBookingDate');
        const svcSelect = document.getElementById('apptServiceSelect');
        const date = dateInput?.value || new Date().toISOString().split('T')[0];
        const serviceId = svcSelect?.value || null;

        grid.innerHTML = `<div style="grid-column: 1/-1; text-align: center; color: var(--text-muted); padding: 1rem;"><i class="fa-solid fa-spinner fa-spin"></i> Loading free slots...</div>`;

        try {
            const res = await PosApi.getAppointmentSlots(date, serviceId);
            if (!res || !res.success || !res.slots || res.slots.length === 0) {
                grid.innerHTML = `<div style="grid-column: 1/-1; text-align: center; color: var(--text-muted); padding: 1rem;">No slots available for this date.</div>`;
                this.selectedApptSlot = null;
                return;
            }

            grid.innerHTML = '';
            res.slots.forEach(slot => {
                const timeVal = slot.time || slot.time_slot || slot.label;
                const isAvail = (slot.is_free !== false && (slot.available_capacity === undefined || slot.available_capacity > 0) && (slot.available === undefined || slot.available));
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'time-slot-btn';
                btn.textContent = slot.label || timeVal;
                btn.setAttribute('data-time', timeVal);
                if (!isAvail) {
                    btn.classList.add('disabled');
                    btn.disabled = true;
                    btn.title = 'Slot full or unavailable';
                } else {
                    if (this.selectedApptSlot === timeVal) {
                        btn.classList.add('active');
                    }
                    btn.addEventListener('click', () => {
                        grid.querySelectorAll('.time-slot-btn').forEach(b => b.classList.remove('active'));
                        btn.classList.add('active');
                        this.selectedApptSlot = timeVal;
                    });
                }
                grid.appendChild(btn);
            });
        } catch (e) {
            console.warn('Error loading appointment slots:', e);
            grid.innerHTML = `<div style="grid-column: 1/-1; color: var(--danger); padding: 1rem;">Failed to load slots.</div>`;
        }
    }

    static async handleSaveAppointment(e) {
        if (e) e.preventDefault();
        const dateInput = document.getElementById('apptBookingDate');
        const svcSelect = document.getElementById('apptServiceSelect');
        const plateInput = document.getElementById('apptPlateInput');
        const nameInput = document.getElementById('apptCustomerName');
        const phoneInput = document.getElementById('apptCustomerPhone');
        const notesInput = document.getElementById('apptNotes');
        const btnSave = document.getElementById('btnSaveAppt');

        const date = dateInput?.value;
        const timeSlot = this.selectedApptSlot;
        const serviceId = svcSelect?.value;
        const plate = (plateInput?.value || '').trim().toUpperCase();
        const customerName = (nameInput?.value || '').trim();
        const customerPhone = (phoneInput?.value || '').trim();
        const notes = (notesInput?.value || '').trim();

        if (!date) {
            this.showToastNotice('Please select an appointment date.', 'warning');
            return;
        }
        if (!timeSlot) {
            this.showToastNotice('Please select a free time slot.', 'warning');
            return;
        }
        if (!serviceId) {
            this.showToastNotice('Please choose a service.', 'warning');
            return;
        }
        if (!plate) {
            this.showToastNotice('Please enter the vehicle plate number.', 'warning');
            return;
        }

        if (btnSave) btnSave.disabled = true;

        try {
            const payload = {
                plate_number: plate,
                vehicle_plate: plate,
                service_id: parseInt(serviceId),
                appointment_date: date,
                date: date,
                appointment_time: timeSlot,
                time_slot: timeSlot,
                customer_name: customerName,
                customer_phone: customerPhone,
                notes: notes,
                staff_id: this.currentUser?.id || null
            };

            const res = await PosApi.createAppointment(payload);
            if (res && res.success) {
                this.showToastNotice('Appointment booked successfully!', 'success');
                // Clear inputs
                if (plateInput) plateInput.value = '';
                if (nameInput) nameInput.value = '';
                if (phoneInput) phoneInput.value = '';
                if (notesInput) notesInput.value = '';
                this.selectedApptSlot = null;

                // Refresh slots and appointments list
                await this.loadAppointmentSlots();
                const viewDateInput = document.getElementById('apptViewDate');
                if (viewDateInput) {
                    viewDateInput.value = date;
                }
                await this.loadAppointmentsList();
            } else {
                this.showToastNotice(res?.error || 'Failed to book appointment.', 'danger');
            }
        } catch (err) {
            console.error('Save appointment error:', err);
            this.showToastNotice('Error connecting to back office.', 'danger');
        } finally {
            if (btnSave) btnSave.disabled = false;
        }
    }

    static async loadAppointmentsList() {
        const listContainer = document.getElementById('appointmentsList');
        const countBadge = document.getElementById('apptCountBadge');
        const viewDateInput = document.getElementById('apptViewDate');
        const date = viewDateInput?.value || new Date().toISOString().split('T')[0];

        if (!listContainer) return;
        listContainer.innerHTML = `<div style="text-align: center; color: var(--text-muted); padding: 2rem;"><i class="fa-solid fa-spinner fa-spin"></i> Loading appointments...</div>`;

        try {
            const res = await PosApi.getAppointments(date);
            if (!res || !res.success || !res.appointments || res.appointments.length === 0) {
                listContainer.innerHTML = `
                    <div style="text-align: center; color: var(--text-muted); padding: 3rem;">
                        <i class="fa-regular fa-calendar-xmark" style="font-size: 2.2rem; margin-bottom: 0.5rem; display: block; opacity: 0.6;"></i>
                        No appointments found for this date.
                    </div>
                `;
                if (countBadge) countBadge.textContent = '0';
                return;
            }

            if (countBadge) countBadge.textContent = res.appointments.length;

            let html = '';
            res.appointments.forEach(appt => {
                const isArrived = (appt.status === 'arrived' || appt.status === 'in_progress' || appt.status === 'completed');
                const timeStr = appt.appointment_time ? appt.appointment_time.slice(0, 5) : (appt.time_slot || '');
                const statusLabel = appt.status.charAt(0).toUpperCase() + appt.status.slice(1);
                const plateText = appt.plate_number || appt.vehicle_plate || '';
                const svcName = appt.service_name_en || appt.service_name_km || appt.service_name || 'Car Wash Service';
                
                html += `
                    <div class="appt-card-row ${isArrived ? 'arrived' : ''}">
                        <div class="appt-card-main">
                            <div class="appt-time-pill"><i class="fa-regular fa-clock"></i> ${timeStr}</div>
                            <div class="appt-plate-pill">${this.escapeHtml(plateText)}</div>
                            <div class="appt-info">
                                <div class="appt-svc-name">${this.escapeHtml(svcName)}</div>
                                <div class="appt-cust-meta">
                                    <span><i class="fa-solid fa-user"></i> ${this.escapeHtml(appt.customer_name || 'Guest')}</span>
                                    ${appt.customer_phone ? `<span><i class="fa-solid fa-phone"></i> ${this.escapeHtml(appt.customer_phone)}</span>` : ''}
                                </div>
                            </div>
                        </div>
                        <div class="appt-actions">
                            ${!isArrived ? `
                                <button type="button" class="btn-appt-arrived" onclick="PosApp.handleAppointmentArrived(${appt.id})">
                                    <i class="fa-solid fa-flag-checkered"></i> Customer Arrived
                                </button>
                            ` : `
                                <span class="appt-status-arrived"><i class="fa-solid fa-check"></i> ${statusLabel}</span>
                            `}
                        </div>
                    </div>
                `;
            });
            listContainer.innerHTML = html;
        } catch (e) {
            console.warn('Error loading appointments:', e);
            listContainer.innerHTML = `<div style="text-align: center; color: var(--danger); padding: 2rem;">Failed to load appointments.</div>`;
        }
    }

    static async handleAppointmentArrived(apptId) {
        if (!apptId) return;
        try {
            const staffId = this.currentUser?.id || 1;
            const res = await PosApi.appointmentArrived(apptId, staffId);
            if (res && res.success) {
                this.showToastNotice('Customer marked as arrived! Queue updated.', 'success');
                this.playChimeSound();
                await this.loadAppointmentsList();
                await this.fetchBaysAndQueue(true);
            } else {
                this.showToastNotice(res?.error || 'Failed to mark appointment arrived.', 'danger');
            }
        } catch (e) {
            console.error('Error in handleAppointmentArrived:', e);
            this.showToastNotice('Failed to connect to back office.', 'danger');
        }
    }

    static async pollShopFloorData() {
        if (!this.currentUser) return;
        try {
            // Poll notifications
            await this.pollNotifications();

            const role = (this.currentUser.role || '').toLowerCase();
            if (role === 'washer') {
                await this.renderWasherScreen();
            } else if (role === 'technical' || role === 'mechanic') {
                await this.renderMechanicScreen();
            } else {
                await this.fetchBaysAndQueue(true);
            }

            // Refresh wait times from back office
            await this.fetchWaitTimes();

            // Refresh appointments list if viewing appointments screen
            if (this.currentView === 'appointments') {
                await this.loadAppointmentsList();
            }

            this.lastSyncSuccessTime = Date.now();
            this.isOnline = true;
            this.updateOfflineManualBanner();
        } catch (e) {
            console.warn('Shop floor poll error:', e);
            const elapsed = Date.now() - (this.lastSyncSuccessTime || Date.now());
            if (elapsed > 60000 || !navigator.onLine) {
                this.isOnline = false;
                this.updateOfflineManualBanner();
            }
        }
    }
}

// Expose globally
window.PosApp = PosApp;

// Auto initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    PosApp.init();
});
