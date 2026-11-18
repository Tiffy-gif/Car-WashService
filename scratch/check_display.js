const { chromium } = require('playwright');
const http = require('http');

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
                'X-Device-UID': 'POS-CAASIER',
                'X-Device-Key': 'my_test_device_key_unique9',
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

    await page.fill('#posGlobalSearchInput', 'xyz999');
    await page.waitForTimeout(500);

    const check = await page.$$eval('#catalogGrid .pos-item-card', cards => {
        return cards.map(c => ({
            inlineDisplay: c.style.display,
            computedDisplay: window.getComputedStyle(c).display,
            offsetParent: c.offsetParent !== null,
            rect: { width: c.getBoundingClientRect().width, height: c.getBoundingClientRect().height }
        }));
    });
    console.log('Sample card 0:', check[0]);
    const computedVisible = check.filter(c => c.computedDisplay !== 'none');
    console.log('Computed display not none:', computedVisible.length);

    await browser.close();
})();
