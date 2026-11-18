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

    const info = await page.evaluate(() => {
        const view = document.getElementById('viewMechanic');
        return {
            innerHTML: view.innerHTML.slice(0, 1500),
            hasHeader: !!document.querySelector('#viewMechanic .view-header-bar') || !!document.querySelector('#viewMechanic .queue-cockpit-header') || !!document.querySelector('#viewMechanic .washer-command-header'),
            liftColContent: document.getElementById('mechanicLiftColumn')?.innerText,
            rightColContent: document.getElementById('mechanicRightColumn')?.innerText,
            topBarNav: document.querySelector('.pos-topbar')?.innerText
        };
    });

    console.log('Mechanic page DOM info:');
    console.log('Has header bar inside viewMechanic:', info.hasHeader);
    console.log('Lift column text:\n', info.liftColContent);
    console.log('Right column text:\n', info.rightColContent);

    await context.close();
    await browser.close();
})();
