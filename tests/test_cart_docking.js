const { chromium } = require('playwright');
const http = require('http');
const fs = require('fs');
const path = require('path');

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
                'X-Device-UID': 'POS-CAASIER',
                'X-Device-Key': 'pos_sec_18f64d8820c828bcc02be2ce50532f03',
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
    const loginRes = await getRealStaffSession();
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1920, height: 960 } });
    await context.addInitScript((loginData) => {
        localStorage.setItem('carwash_pos_config', JSON.stringify({
            backofficeUrl: 'http://localhost/Car-WashService',
            deviceUid: 'POS-CAASIER',
            deviceKey: 'pos_sec_18f64d8820c828bcc02be2ce50532f03',
            exchangeRate: 4100
        }));
        localStorage.setItem('cw_pos_staff_user', JSON.stringify(loginData.user));
        localStorage.setItem('cw_pos_session_token', loginData.session_token);
        sessionStorage.setItem('cw_pos_session_token', loginData.session_token);
        localStorage.setItem('carwash_pos_has_connected', 'true');
    }, loginRes);

    const page = await context.newPage();
    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(1500);

    // Ensure English language
    await page.evaluate(() => {
        if (window.I18nManager) window.I18nManager.setLang('en');
    });

    // 1. Test Empty Cart Position
    const emptyMetrics = await page.evaluate(() => {
        const btnPayment = document.getElementById('btnOpenCheckout');
        const advance = document.querySelector('.gotpos-advance-features');
        return {
            paymentBottom: btnPayment.getBoundingClientRect().bottom,
            advanceTop: advance.getBoundingClientRect().top,
            gap: advance.getBoundingClientRect().top - btnPayment.getBoundingClientRect().bottom
        };
    });
    console.log("Empty Cart Gap:", emptyMetrics);

    // 2. Add 1 item (Interior Cleaning $3.00)
    await page.evaluate(() => {
        PosApp.addServiceToCart(2);
        const m = document.getElementById('modalUpsellPrompt');
        if (m) m.classList.remove('open');
    });
    await page.waitForTimeout(400);

    const oneItemMetrics = await page.evaluate(() => {
        const btnPayment = document.getElementById('btnOpenCheckout');
        const advance = document.querySelector('.gotpos-advance-features');
        return {
            paymentBottom: btnPayment.getBoundingClientRect().bottom,
            advanceTop: advance.getBoundingClientRect().top,
            gap: advance.getBoundingClientRect().top - btnPayment.getBoundingClientRect().bottom
        };
    });
    console.log("1 Item Gap:", oneItemMetrics);

    const cartCol = await page.$('#posCartColumn');
    const ss1Item = path.join(__dirname, 'cart_1item_docked_verified.png');
    if (cartCol) {
        await cartCol.screenshot({ path: ss1Item });
        fs.copyFileSync(ss1Item, 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003\\cart_1item_docked_verified.png');
        console.log("Saved screenshot: cart_1item_docked_verified.png");
    }

    const ssFull = path.join(__dirname, 'pos_full_screen_1item_docked.png');
    await page.screenshot({ path: ssFull });
    fs.copyFileSync(ssFull, 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003\\pos_full_screen_1item_docked.png');
    console.log("Saved full screen screenshot: pos_full_screen_1item_docked.png");

    // 3. Add 4 more items (5 items total)
    await page.evaluate(() => {
        PosApp.addServiceToCart(1);
        PosApp.addServiceToCart(3);
        PosApp.addServiceToCart(4);
        PosApp.addServiceToCart(5);
        const m = document.getElementById('modalUpsellPrompt');
        if (m) m.classList.remove('open');
    });
    await page.waitForTimeout(400);

    const fiveItemsMetrics = await page.evaluate(() => {
        const btnPayment = document.getElementById('btnOpenCheckout');
        const advance = document.querySelector('.gotpos-advance-features');
        return {
            paymentBottom: btnPayment.getBoundingClientRect().bottom,
            advanceTop: advance.getBoundingClientRect().top,
            gap: advance.getBoundingClientRect().top - btnPayment.getBoundingClientRect().bottom
        };
    });
    console.log("5 Items Gap:", fiveItemsMetrics);

    const ss5Items = path.join(__dirname, 'cart_5items_docked_verified.png');
    if (cartCol) {
        await cartCol.screenshot({ path: ss5Items });
        fs.copyFileSync(ss5Items, 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003\\cart_5items_docked_verified.png');
        console.log("Saved screenshot: cart_5items_docked_verified.png");
    }

    await browser.close();
    console.log("=== CART DOCKING TEST COMPLETE ===");
})();
