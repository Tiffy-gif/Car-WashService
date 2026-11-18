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

    // 1. WASHER TEST (dom)
    console.log('=== TEST 1: WASHER (dom) ===');
    const domLogin = await loginUser('dom', '123456');
    const contextDom = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    await contextDom.addInitScript((loginData) => {
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
    }, domLogin);

    const pageDom = await contextDom.newPage();
    await pageDom.goto('http://localhost/Car-WashService/pos/index.html');
    await pageDom.waitForTimeout(2500);

    const domActiveView = await pageDom.evaluate(() => window.PosApp?.currentView);
    console.log('Washer initial screen:', domActiveView);

    // Try navigating to #pos
    await pageDom.evaluate(() => window.PosApp?.switchView('pos'));
    const domAfterPosTry = await pageDom.evaluate(() => window.PosApp?.currentView);
    console.log('Washer after trying to open #pos:', domAfterPosTry);

    // Try navigating to #orders
    await pageDom.evaluate(() => window.PosApp?.switchView('orders'));
    const domAfterOrdersTry = await pageDom.evaluate(() => window.PosApp?.currentView);
    console.log('Washer after trying to open #orders:', domAfterOrdersTry);

    // Try navigating to #mechanic
    await pageDom.evaluate(() => window.PosApp?.switchView('mechanic'));
    const domAfterMechTry = await pageDom.evaluate(() => window.PosApp?.currentView);
    console.log('Washer after trying to open #mechanic:', domAfterMechTry);

    await contextDom.close();

    // 2. CASHIER TEST (visal)
    console.log('\n=== TEST 2: CASHIER (visal) ===');
    const visalLogin = await loginUser('visal', '123456');
    const contextVisal = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    await contextVisal.addInitScript((loginData) => {
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
    }, visalLogin);

    const pageVisal = await contextVisal.newPage();
    await pageVisal.goto('http://localhost/Car-WashService/pos/index.html');
    await pageVisal.waitForTimeout(2500);

    const visalActiveView = await pageVisal.evaluate(() => window.PosApp?.currentView);
    console.log('Cashier initial screen:', visalActiveView);

    // Can navigate to #queue
    await pageVisal.evaluate(() => window.PosApp?.switchView('queue'));
    const visalQueue = await pageVisal.evaluate(() => window.PosApp?.currentView);
    console.log('Cashier open #queue:', visalQueue);

    // Can navigate to #orders
    await pageVisal.evaluate(() => window.PosApp?.switchView('orders'));
    const visalOrders = await pageVisal.evaluate(() => window.PosApp?.currentView);
    console.log('Cashier open #orders:', visalOrders);

    // Try navigating to worker tablet #washer
    await pageVisal.evaluate(() => window.PosApp?.switchView('washer'));
    const visalWasher = await pageVisal.evaluate(() => window.PosApp?.currentView);
    console.log('Cashier trying to open worker #washer (redirects to queue):', visalWasher);

    await contextVisal.close();

    // 3. MECHANIC TEST (dona)
    console.log('\n=== TEST 3: MECHANIC (dona) ===');
    const donaLogin = await loginUser('dona', '123456');
    const contextDona = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    await contextDona.addInitScript((loginData) => {
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

    const pageDona = await contextDona.newPage();
    await pageDona.goto('http://localhost/Car-WashService/pos/index.html');
    await pageDona.waitForTimeout(2500);

    const donaActiveView = await pageDona.evaluate(() => window.PosApp?.currentView);
    console.log('Mechanic initial screen:', donaActiveView);

    // Try navigating to #pos
    await pageDona.evaluate(() => window.PosApp?.switchView('pos'));
    const donaAfterPos = await pageDona.evaluate(() => window.PosApp?.currentView);
    console.log('Mechanic after trying to open #pos:', donaAfterPos);

    // Try navigating to #washer
    await pageDona.evaluate(() => window.PosApp?.switchView('washer'));
    const donaAfterWasher = await pageDona.evaluate(() => window.PosApp?.currentView);
    console.log('Mechanic after trying to open #washer:', donaAfterWasher);

    await contextDona.close();

    await browser.close();
})();
