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

    // 1. TEST DOM (WASHER)
    console.log('Testing DOM (WASHER)...');
    const domLogin = await loginUser('dom', '123456');
    const contextDom = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    await contextDom.addInitScript((loginData) => {
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
    }, domLogin);

    const pageDom = await contextDom.newPage();
    await pageDom.goto('http://localhost/Car-WashService/pos/index.html');
    await pageDom.waitForTimeout(2000);

    const domBtnBackDisplay = await pageDom.evaluate(() => {
        const btn = document.getElementById('btnWasherBackToPos');
        return btn ? window.getComputedStyle(btn).display : 'null';
    });
    const domTitle = await pageDom.evaluate(() => {
        return document.querySelector('#viewWasher .washer-section-title-wrap h3')?.textContent;
    });

    console.log('DOM (Washer): Back to Register display =', domBtnBackDisplay);
    console.log('DOM (Washer): Title =', domTitle);

    await pageDom.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/verified_dom_washer_no_back_btn.png' });
    await contextDom.close();

    // 2. TEST VISAL (CASHIER)
    console.log('\nTesting VISAL (CASHIER)...');
    const visalLogin = await loginUser('visal', '123456');
    const contextVisal = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    await contextVisal.addInitScript((loginData) => {
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
    }, visalLogin);

    const pageVisal = await contextVisal.newPage();
    await pageVisal.goto('http://localhost/Car-WashService/pos/index.html#washer');
    await pageVisal.waitForTimeout(2000);

    const visalBtnBackDisplay = await pageVisal.evaluate(() => {
        const btn = document.getElementById('btnWasherBackToPos');
        return btn ? window.getComputedStyle(btn).display : 'null';
    });
    const visalTitle = await pageVisal.evaluate(() => {
        return document.querySelector('#viewWasher .washer-section-title-wrap h3')?.textContent;
    });

    console.log('VISAL (Cashier): Back to Register display =', visalBtnBackDisplay);
    console.log('VISAL (Cashier): Title =', visalTitle);

    await pageVisal.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/verified_visal_cashier_has_back_btn.png' });
    await contextVisal.close();

    await browser.close();
})();
