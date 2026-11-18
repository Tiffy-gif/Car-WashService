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

    // 4. OWNER TEST (owner)
    console.log('=== TEST 4: OWNER (owner) ===');
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
    const errors = [];
    pageOwner.on('pageerror', err => errors.push(err.message));

    await pageOwner.goto('http://localhost/Car-WashService/pos/index.html');
    await pageOwner.waitForTimeout(2000);

    const ownerInit = await pageOwner.evaluate(() => window.PosApp?.currentView);
    console.log('Owner initial screen:', ownerInit);

    await pageOwner.evaluate(() => window.PosApp?.switchView('washer'));
    const ownerWasher = await pageOwner.evaluate(() => window.PosApp?.currentView);
    console.log('Owner open #washer:', ownerWasher);

    await pageOwner.evaluate(() => window.PosApp?.switchView('mechanic'));
    const ownerMech = await pageOwner.evaluate(() => window.PosApp?.currentView);
    console.log('Owner open #mechanic:', ownerMech);

    await pageOwner.evaluate(() => window.PosApp?.switchView('queue'));
    const ownerQueue = await pageOwner.evaluate(() => window.PosApp?.currentView);
    console.log('Owner open #queue:', ownerQueue);

    await pageOwner.evaluate(() => window.PosApp?.switchView('pos'));
    const ownerPos = await pageOwner.evaluate(() => window.PosApp?.currentView);
    console.log('Owner open #pos:', ownerPos);

    console.log('Owner page errors:', errors.length > 0 ? errors : 'None');

    await contextOwner.close();
    await browser.close();
})();
