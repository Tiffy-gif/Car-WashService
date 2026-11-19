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
            res.on('end', () => {
                try {
                    resolve(JSON.parse(data));
                } catch(e) {
                    reject(e);
                }
            });
        });
        req.on('error', reject);
        req.write(payload);
        req.end();
    });
}

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });

    // ----------------------------------------------------
    // 1. CASHIER SCREEN (#queue) - user 'visal'
    // ----------------------------------------------------
    console.log('--- 1. Testing Cashier Screen (#queue) ---');
    const visalLogin = await loginUser('visal', '123456');
    const ctxCashier = await browser.newContext({ viewport: { width: 1400, height: 800 } });
    await ctxCashier.addInitScript((loginData) => {
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

    const pageCashier = await ctxCashier.newPage();
    await pageCashier.goto('http://localhost/Car-WashService/pos/index.html#queue');
    await pageCashier.waitForTimeout(2500);

    const cashierBay2Text = await pageCashier.evaluate(() => document.getElementById('cashierBayCard-2')?.innerText);
    const cashierBay3Text = await pageCashier.evaluate(() => document.getElementById('cashierBayCard-3')?.innerText);
    const cashierBay4Text = await pageCashier.evaluate(() => document.getElementById('cashierBayCard-4')?.innerText);
    console.log('Cashier Bay 2 text snippet:', cashierBay2Text?.replace(/\s+/g, ' ').substring(0, 100));
    console.log('Cashier Bay 3 text snippet:', cashierBay3Text?.replace(/\s+/g, ' ').substring(0, 100));
    console.log('Cashier Bay 4 text snippet:', cashierBay4Text?.replace(/\s+/g, ' ').substring(0, 100));

    await pageCashier.screenshot({ path: path.join(ARTIFACT_DIR, 'verified_cashier_queue.png') });
    console.log('Saved verified_cashier_queue.png');
    await ctxCashier.close();

    // ----------------------------------------------------
    // 2. WASHER SCREEN (#washer) - user 'dom'
    // ----------------------------------------------------
    console.log('\n--- 2. Testing Washer Screen (#washer) ---');
    const domLogin = await loginUser('dom', '123456');
    const ctxWasher = await browser.newContext({ viewport: { width: 1400, height: 800 } });
    await ctxWasher.addInitScript((loginData) => {
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

    const pageWasher = await ctxWasher.newPage();
    await pageWasher.goto('http://localhost/Car-WashService/pos/index.html#washer');
    await pageWasher.waitForTimeout(2500);

    const washerWaitingBadge = await pageWasher.evaluate(() => document.getElementById('washerWaitingBadge')?.innerText);
    const washerQueueText = await pageWasher.evaluate(() => document.getElementById('washerJobsGrid')?.innerText);
    console.log('Washer Waiting Badge:', washerWaitingBadge);
    console.log('Washer Queue snippet:', washerQueueText?.replace(/\s+/g, ' ').substring(0, 150));

    await pageWasher.screenshot({ path: path.join(ARTIFACT_DIR, 'verified_washer_screen.png') });
    console.log('Saved verified_washer_screen.png');
    await ctxWasher.close();

    // ----------------------------------------------------
    // 3. MECHANIC SCREEN (#mechanic) - user 'Dona'
    // ----------------------------------------------------
    console.log('\n--- 3. Testing Mechanic Screen (#mechanic) ---');
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

    const pageMechanic = await ctxMechanic.newPage();
    await pageMechanic.goto('http://localhost/Car-WashService/pos/index.html#mechanic');
    await pageMechanic.waitForTimeout(2500);

    const queueBadgeText = await pageMechanic.evaluate(() => document.getElementById('queueBadge')?.innerText);
    const mechanicRightText = await pageMechanic.evaluate(() => document.getElementById('mechanicRightColumn')?.innerText);
    console.log('Mechanic Queue Badge:', queueBadgeText);
    console.log('Mechanic Right Column snippet:', mechanicRightText?.replace(/\s+/g, ' ').substring(0, 200));

    await pageMechanic.screenshot({ path: path.join(ARTIFACT_DIR, 'verified_mechanic_screen.png') });
    console.log('Saved verified_mechanic_screen.png');

    await browser.close();
    console.log('\nAll tests complete!');
})().catch(err => {
    console.error('Fatal test error:', err);
    process.exit(1);
});
