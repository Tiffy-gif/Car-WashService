const { chromium } = require('playwright');
const http = require('http');

const DEVICE_KEY = 'my_test_device_key_unique9';
const DEVICE_UID = 'POS-CAASIER';

function getStaffSession() {
    return new Promise((resolve) => {
        const payload = JSON.stringify({ username: 'visal', pin: '123456' });
        const req = http.request({
            hostname: 'localhost',
            port: 80,
            path: '/Car-WashService/api/pos/index.php?action=staff_login',
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Device-UID': DEVICE_UID,
                'X-Device-Key': DEVICE_KEY,
                'Content-Length': Buffer.byteLength(payload)
            }
        }, res => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => resolve(JSON.parse(data)));
        });
        req.write(payload);
        req.end();
    });
}

(async () => {
    const loginRes = await getStaffSession();
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });

    await context.addInitScript((loginData) => {
        localStorage.setItem('carwash_pos_config', JSON.stringify({
            backofficeUrl: 'http://localhost/Car-WashService',
            deviceUid: 'POS-CAASIER',
            deviceKey: 'my_test_device_key_unique9',
            exchangeRate: 4100
        }));
        localStorage.setItem('cw_pos_staff_user', JSON.stringify(loginData.user));
        localStorage.setItem('cw_pos_session_token', loginData.session_token);
        sessionStorage.setItem('cw_pos_session_token', loginData.session_token);
        localStorage.setItem('carwash_pos_has_connected', 'true');
        localStorage.setItem('cw_pos_language', 'km');
    }, loginRes);

    const page = await context.newPage();
    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2000);

    // Click two service cards to add to cart matching screenshot:
    // 1. Brake Inspection & Adjustment (or Brake service)
    // 2. Engine Bay Wash
    await page.evaluate(() => {
        const catalog = window.PosStorage?.getCatalog()?.services || [];
        const s1 = catalog.find(s => (s.name_en || '').toLowerCase().includes('brake inspection') || (s.name_km || '').includes('ពិនិត្យ & សារ៉េ')) 
                || catalog.find(s => (s.name_en || '').toLowerCase().includes('brake'))
                || catalog[0];
        const s2 = catalog.find(s => (s.name_en || '').toLowerCase().includes('engine') || (s.name_km || '').includes('បន្ទប់ម៉ាស៊ីន')) 
                || catalog[1];

        if (s1 && window.PosApp) PosApp.addServiceToCart(s1.id);
        if (s2 && window.PosApp) PosApp.addServiceToCart(s2.id);
    });
    await page.waitForTimeout(1000);

    const artifactDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';
    const cartEl = await page.$('#posCartColumn');
    if (cartEl) {
        await cartEl.screenshot({ path: `${artifactDir}/updated_cart_panel_km.png` });
        console.log('Saved updated_cart_panel_km.png');
    }

    await page.screenshot({ path: `${artifactDir}/updated_pos_full_km.png` });
    console.log('Saved updated_pos_full_km.png');

    // Also test English
    await page.evaluate(() => {
        if (window.I18nManager) window.I18nManager.setLang('en');
    });
    await page.waitForTimeout(800);

    if (cartEl) {
        await cartEl.screenshot({ path: `${artifactDir}/updated_cart_panel_en.png` });
        console.log('Saved updated_cart_panel_en.png');
    }

    // Also test Empty Cart state
    await page.evaluate(() => {
        if (window.PosApp) PosApp.clearCart();
    });
    await page.waitForTimeout(800);

    if (cartEl) {
        await cartEl.screenshot({ path: `${artifactDir}/updated_cart_panel_empty.png` });
        console.log('Saved updated_cart_panel_empty.png');
    }

    await browser.close();
})();
