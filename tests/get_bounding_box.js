const { chromium } = require('playwright');
const http = require('http');

const DEVICE_KEY = 'pos_sec_5924af8669fa53d7a7941439e66926ec';
const DEVICE_UID = 'POS-CAASIER';

function getRealStaffSession() {
    return new Promise((resolve, reject) => {
        const payload = JSON.stringify({ username: 'visal', password: '123456' });
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
        req.on('error', reject);
        req.write(payload);
        req.end();
    });
}

(async () => {
    const loginRes = await getRealStaffSession();
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });

    await context.addInitScript((loginData) => {
        localStorage.setItem('carwash_pos_config', JSON.stringify({
            backofficeUrl: 'http://localhost/Car-WashService',
            deviceUid: 'POS-CAASIER',
            deviceKey: 'pos_sec_5924af8669fa53d7a7941439e66926ec',
            exchangeRate: 4100
        }));
        localStorage.setItem('cw_pos_staff_user', JSON.stringify(loginData.user));
        localStorage.setItem('cw_pos_session_token', loginData.session_token);
        sessionStorage.setItem('cw_pos_session_token', loginData.session_token);
        localStorage.setItem('carwash_pos_has_connected', 'true');
    }, loginRes);

    const page = await context.newPage();
    await page.goto('http://localhost/Car-WashService/pos/index.html');
    await page.waitForTimeout(2000);

    const box = await page.locator('.catalog-grid-wrap').boundingBox();
    console.log('catalog-grid-wrap boundingBox:', box);

    const catBox = await page.locator('.category-list-scroll').boundingBox();
    console.log('category-list-scroll boundingBox:', catBox);

    await browser.close();
})();
