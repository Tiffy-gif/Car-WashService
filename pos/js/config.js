/**
 * POS Connection Configuration
 * Edit the default settings here, or use the Settings Modal in the app UI.
 * When moving to the cloud, just update BACKOFFICE_URL here or in the UI.
 */
function getDefaultBackofficeUrl() {
    if (typeof window !== 'undefined' && window.location && window.location.origin && window.location.origin !== 'null') {
        return window.location.origin + window.location.pathname.replace(/\/pos(?:\/.*)?$/i, '');
    }
    return '';
}

const POS_CONFIG_DEFAULTS = {
    // Address of the Car Wash back office (Local or Cloud)
    backofficeUrl: getDefaultBackofficeUrl(),
    // POS Device API Key (Must be entered in Settings and kept exclusively on this terminal)
    deviceKey: '',
    // Default exchange rate 1 USD to KHR
    exchangeRate: 4100,
    // Device UID identifier
    deviceUid: 'POS-TERMINAL-01',
    // Device display name
    deviceName: 'Counter Terminal 1',
    // Language: 'km' or 'en'
    defaultLanguage: 'km',
    // Auto logout idle timeout in minutes (0 = disabled, 1, 2, 5, 10, 15, 30)
    idleTimeoutMinutes: 5,
    // 80mm ESC/POS Thermal Printer Settings
    printerDirectEnabled: true,       // Direct silent printing without browser print window
    printerType: 'network',            // 'network', 'usb', or 'agent'
    printerIp: '192.168.1.200',        // Network printer IP address
    printerPort: 9100,                 // Standard thermal printer raw port (usually 9100)
    printerAutoCut: true,              // Cut paper automatically after receipt
    printerOpenDrawer: true,           // Open RJ11 cash drawer on cash payments
    printerWasherCopy: false,          // Print 2nd workshop copy for washers/mechanics
    printerFallbackWindow: true,       // Fallback to normal print window if printer offline
    printerAgentUrl: 'http://127.0.0.1:1818' // Counter local print bridge URL
};

class PosConfig {
    static STORAGE_KEY = 'carwash_pos_config';

    static getDefaultBackofficeUrl() {
        return getDefaultBackofficeUrl();
    }

    static get() {
        try {
            const saved = localStorage.getItem(this.STORAGE_KEY);
            if (saved) {
                const parsed = JSON.parse(saved);
                if (parsed && parsed.backofficeUrl === 'http://localhost/Car-WashService') {
                    parsed.backofficeUrl = POS_CONFIG_DEFAULTS.backofficeUrl;
                }
                return { ...POS_CONFIG_DEFAULTS, ...parsed };
            }
        } catch (e) {
            console.warn('Failed to parse saved config, using defaults', e);
        }
        return { ...POS_CONFIG_DEFAULTS };
    }

    static save(newConfig) {
        const merged = { ...this.get(), ...newConfig };
        try {
            localStorage.setItem(this.STORAGE_KEY, JSON.stringify(merged));
        } catch (e) {
            const isQuota = (
                e.name === 'QuotaExceededError' ||
                e.name === 'NS_ERROR_DOM_QUOTA_REACHED' ||
                e.code === 22 ||
                e.code === 1014
            );
            if (isQuota && typeof PosApp !== 'undefined' && typeof PosApp.showToastNotice === 'function') {
                PosApp.showToastNotice('Storage full! Browser storage quota exceeded.', 'danger');
            }
            console.error('Failed to save config to localStorage:', e);
        }
        return merged;
    }

    static reset() {
        localStorage.removeItem(this.STORAGE_KEY);
        return { ...POS_CONFIG_DEFAULTS };
    }

    static getApiEndpoint() {
        const cfg = this.get();
        const base = cfg.backofficeUrl.replace(/\/+$/, '');
        return `${base}/api/pos/index.php`;
    }
}

window.PosConfig = PosConfig;
