const { chromium } = require('playwright');
const http = require('http');

const DEVICE_KEY = 'my_test_device_key_unique9';
const DEVICE_UID = 'POS-CAASIER';

function loginUser(username, pin) {
    return new Promise((resolve) => {
        const payload = JSON.stringify({ username, pin });
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
    const browser = await chromium.launch({ channel: 'chrome', headless: true });

    // 1. dona (mechanic)
    const donaLogin = await loginUser('dona', '123456');
    const context = await browser.newContext({ viewport: { width: 1366, height: 768 } });
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
    }, donaLogin);

    const page = await context.newPage();
    await page.goto('http://localhost/Car-WashService/pos/index.html#mechanic');
    await page.waitForTimeout(2500);

    // Capture Khmer
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/current_mechanic_km.png', fullPage: true });

    // Switch to English
    await page.evaluate(() => {
        if (window.PosApp?.setLanguage) window.PosApp.setLanguage('en');
        else if (window.I18nManager?.setLang) window.I18nManager.setLang('en');
    });
    await page.waitForTimeout(1000);
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/current_mechanic_en.png', fullPage: true });

    // Also capture when logged in as Owner viewing #mechanic
    await context.close();

    const ownerLogin = await loginUser('owner', '123456');
    const contextOwner = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    await contextOwner.addInitScript((loginData) => {
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
    }, ownerLogin);

    const pageOwner = await contextOwner.newPage();
    await pageOwner.goto('http://localhost/Car-WashService/pos/index.html#mechanic');
    await pageOwner.waitForTimeout(2500);
    await pageOwner.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/current_mechanic_owner.png', fullPage: true });

    await contextOwner.close();
    await browser.close();
    console.log('Screenshots captured!');
})();
