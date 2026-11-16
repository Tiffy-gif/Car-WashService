const { chromium } = require('playwright');
const path = require('path');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext();

    // Seed local storage with the user session and an old deactivated device key
    await context.addInitScript(() => {
        localStorage.setItem('carwash_pos_user', JSON.stringify({
            id: 1,
            username: 'visal',
            full_name: 'Visal (Shop Owner)',
            role: 'owner',
            permissions: ['*']
        }));
        localStorage.setItem('carwash_pos_session_token', 'st_stale_token_test');
        localStorage.setItem('carwash_pos_config', JSON.stringify({
            backofficeUrl: 'http://localhost/Car-WashService',
            deviceUid: 'CASHIER',
            deviceName: 'POS-Cashier',
            deviceKey: 'pos_sec_deactivated_key'
        }));
        localStorage.setItem('carwash_pos_has_connected', 'true');
        localStorage.setItem('carwash_pos_catalog', JSON.stringify({
            products: [{ id: 1, name: 'Exterior Wash', price: 8.5, category_id: 1 }],
            categories: [{ id: 1, name: 'Wash' }],
            services: []
        }));
    });

    const page = await context.newPage();
    page.on('console', msg => console.log('BROWSER CONSOLE:', msg.type(), msg.text()));

    console.log('Navigating to POS with all devices deleted in backoffice...');
    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(3000);

    // Check if modalDeviceLocked is open
    const modalState = await page.evaluate(() => {
        const modal = document.getElementById('modalDeviceLocked');
        const banner = document.getElementById('posDeviceLockedBanner');
        const title = document.getElementById('deviceLockedTitle');
        const desc = document.getElementById('deviceLockedDesc');
        const code = document.getElementById('deviceLockedCode');
        return {
            modalExists: !!modal,
            modalIsOpen: modal ? modal.classList.contains('open') : false,
            bannerExists: !!banner,
            bannerDisplay: banner ? window.getComputedStyle(banner).display : 'none',
            titleText: title ? title.textContent.trim() : '',
            descText: desc ? desc.textContent.trim() : '',
            codeText: code ? code.textContent.trim() : '',
            isAuthorized: typeof PosApp !== 'undefined' ? PosApp.isDeviceAuthorized : null
        };
    });

    console.log('MODAL STATE:', JSON.stringify(modalState, null, 2));

    // Take screenshot of the locked screen
    const screenshotPath = path.resolve(__dirname, '../tests/pos_terminal_locked.png');
    await page.screenshot({ path: screenshotPath, fullPage: true });
    console.log('Screenshot saved to:', screenshotPath);

    // Click "Open Settings" on the locked modal
    console.log('Clicking Open Settings button...');
    await page.click('#btnDeviceLockedSettings');
    await page.waitForTimeout(1000);

    const settingsState = await page.evaluate(() => {
        const settingsModal = document.getElementById('modalSettings');
        const keyInput = document.getElementById('cfgDeviceKey');
        return {
            settingsIsOpen: settingsModal ? settingsModal.classList.contains('open') : false,
            settingsZIndex: settingsModal ? window.getComputedStyle(settingsModal).zIndex : 'none',
            isKeyFocused: document.activeElement === keyInput
        };
    });
    console.log('SETTINGS MODAL STATE:', JSON.stringify(settingsState, null, 2));

    const screenshotSettingsPath = path.resolve(__dirname, '../tests/pos_settings_opened_from_lock.png');
    await page.screenshot({ path: screenshotSettingsPath, fullPage: true });
    console.log('Screenshot settings saved to:', screenshotSettingsPath);

    await browser.close();
})();
