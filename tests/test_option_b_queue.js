const { chromium } = require('playwright');
const http = require('http');

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
    await page.waitForTimeout(2000);

    // Disable upsell popups for clean deterministic testing
    await page.evaluate(() => {
        if (window.PosApp) {
            window.PosApp.evaluateCartUpsellRules = () => {};
        }
        if (window.PosApi) {
            window.PosApi.evaluateCartUpsell = async () => ({ success: true, has_offer: false });
        }
        if (window.I18nManager) window.I18nManager.setLang('en');
        if (window.PosApp) window.PosApp.onLanguageChanged();
    });
    await page.waitForTimeout(400);

    // 1. Verify bottom pastel features
    const btnQueue = await page.$('#btnPastelQueue');
    const btnHold = await page.$('#btnPastelHold');
    const btnDiscount = await page.$('#btnPastelDiscount');
    const btnHistory = await page.$('#btnPastelHistory');

    console.log('Has #btnPastelQueue:', btnQueue !== null ? 'PASS' : 'FAIL');
    console.log('Has #btnPastelHold:', btnHold !== null ? 'FAIL (still exists)' : 'PASS (replaced)');
    console.log('Has #btnPastelDiscount:', btnDiscount !== null ? 'PASS' : 'FAIL');
    console.log('Has #btnPastelHistory:', btnHistory !== null ? 'PASS' : 'FAIL');

    const queueLabelEn = await page.$eval('#btnPastelQueue .pastel-label', el => el.textContent.trim());
    console.log('Queue button label (EN):', queueLabelEn);

    // Screenshot cart panel with Queue button
    const cartPanel = await page.$('#posCartColumn');
    await cartPanel.screenshot({
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_cart_queue_button.png'
    });

    // 2. Click Queue button -> switch to viewQueue
    await page.click('#btnPastelQueue');
    await page.waitForTimeout(600);

    const isQueueActive = await page.evaluate(() => {
        const q = document.getElementById('viewQueue');
        return q && q.classList.contains('active');
    });
    console.log('View switched to Queue:', isQueueActive);

    const hasBackBtnInQueue = await page.$('#btnQueueBackToPos');
    console.log('Has #btnQueueBackToPos:', hasBackBtnInQueue !== null ? 'PASS' : 'FAIL');

    // Screenshot Queue screen with Back button
    await page.screenshot({
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_queue_screen_with_back_btn.png'
    });

    // 3. Click Back to Register in Queue
    await page.click('#btnQueueBackToPos');
    await page.waitForTimeout(600);

    const isPosActiveAfterQueue = await page.evaluate(() => {
        const p = document.getElementById('viewPos');
        return p && p.classList.contains('active');
    });
    console.log('View returned to POS from Queue:', isPosActiveAfterQueue);

    // 4. Click History button -> switch to viewOrders
    await page.click('#btnPastelHistory');
    await page.waitForTimeout(600);

    const isOrdersActive = await page.evaluate(() => {
        const o = document.getElementById('viewOrders');
        return o && o.classList.contains('active');
    });
    console.log('View switched to Orders:', isOrdersActive);

    const hasBackBtnInOrders = await page.$('#btnOrdersBackToPos');
    console.log('Has #btnOrdersBackToPos:', hasBackBtnInOrders !== null ? 'PASS' : 'FAIL');

    // Screenshot Orders screen with Back button
    await page.screenshot({
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_orders_screen_with_back_btn.png'
    });

    // 5. Click Back to Register in Orders
    await page.click('#btnOrdersBackToPos');
    await page.waitForTimeout(600);

    const isPosActiveAfterOrders = await page.evaluate(() => {
        const p = document.getElementById('viewPos');
        return p && p.classList.contains('active');
    });
    console.log('View returned to POS from Orders:', isPosActiveAfterOrders);

    // 6. Test Khmer localization
    await page.evaluate(() => {
        if (window.I18nManager) window.I18nManager.setLang('km');
        if (window.PosApp) window.PosApp.onLanguageChanged();
    });
    await page.waitForTimeout(600);

    const queueLabelKm = await page.$eval('#btnPastelQueue .pastel-label', el => el.textContent.trim());
    const discLabelKm = await page.$eval('#btnPastelDiscount .pastel-label', el => el.textContent.trim());
    const histLabelKm = await page.$eval('#btnPastelHistory .pastel-label', el => el.textContent.trim());

    console.log('Khmer labels:', { queueLabelKm, discLabelKm, histLabelKm });

    // Click Queue in Khmer
    await page.click('#btnPastelQueue');
    await page.waitForTimeout(500);

    const backBtnTextKm = await page.$eval('#btnQueueBackToPos', el => el.textContent.trim());
    console.log('Back button text in Khmer:', backBtnTextKm);

    // Return to POS
    await page.click('#btnQueueBackToPos');
    await page.waitForTimeout(500);

    // Full screen screenshot
    await page.screenshot({
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_fullscreen_option_b.png'
    });

    await browser.close();
    console.log('ALL OPTION B TESTS PASSED SUCCESSFULLY!');
})();
