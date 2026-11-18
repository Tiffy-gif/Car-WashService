const { chromium } = require('playwright');
const http = require('http');

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
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const visalLogin = await loginUser('visal', '123456');
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
        localStorage.setItem('cw_pos_current_shift', JSON.stringify({ id: 1, cashier_name: 'visal', status: 'open' }));
    }, visalLogin);

    const page = await context.newPage();
    await page.goto('http://localhost/Car-WashService/pos/index.html#queue');
    await page.waitForTimeout(2000);

    // Switch to EN
    const enBtn = await page.$('.lang-switch-btn[data-lang="en"], #btnLangEn');
    if (enBtn) {
        await enBtn.click();
        await page.waitForTimeout(1000);
    }

    // Assign next car to Bay 1
    const assignBtn = await page.$('#cashierBayCard-1 .btn-assign-next');
    if (assignBtn) {
        await assignBtn.click();
        await page.waitForTimeout(1500);
    }

    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/cashier_queue_occupied_bay.png', fullPage: true });
    await browser.close();
    console.log('Occupied screenshot captured!');
})();
