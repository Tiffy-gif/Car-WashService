const { chromium } = require('playwright');
const http = require('http');
const path = require('path');

const DEVICE_KEY = 'my_test_device_key_unique9';
const DEVICE_UID = 'POS-CAASIER';
const ARTIFACT_DIR = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

function loginUser(username, pin) {
    return new Promise((resolve, reject) => {
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
        req.on('error', reject);
        req.write(payload);
        req.end();
    });
}

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const donaLogin = await loginUser('dona', '123456');
    const ctxMechanic = await browser.newContext({ viewport: { width: 1400, height: 800 } });
    await ctxMechanic.addInitScript((loginData) => {
        localStorage.setItem('carwash_pos_config', JSON.stringify({
            backofficeUrl: 'http://localhost/Car-WashService',
            deviceUid: 'POS-CAASIER',
            deviceKey: 'my_test_device_key_unique9',
            exchangeRate: 4100,
            defaultLanguage: 'en'
        }));
        localStorage.setItem('cw_pos_staff_user', JSON.stringify(loginData.user));
        localStorage.setItem('cw_pos_session_token', loginData.session_token);
        sessionStorage.setItem('cw_pos_session_token', loginData.session_token);
        localStorage.setItem('carwash_pos_has_connected', 'true');
    }, donaLogin);

    const page = await ctxMechanic.newPage();
    await page.goto('http://localhost/Car-WashService/pos/index.html#mechanic');
    await page.waitForTimeout(2500);

    const liftPlate = await page.evaluate(() => document.getElementById('liftPlate')?.innerText);
    const liftCar = await page.evaluate(() => document.getElementById('liftCar')?.innerText);
    const liftSvc = await page.evaluate(() => document.getElementById('liftSvc')?.innerText);
    console.log('Active Lift Plate:', liftPlate);
    console.log('Active Lift Car:', liftCar);
    console.log('Active Lift Svc:', liftSvc);

    await page.screenshot({ path: path.join(ARTIFACT_DIR, 'verified_mechanic_active_hoist.png') });
    console.log('Saved verified_mechanic_active_hoist.png');

    await browser.close();
})();
