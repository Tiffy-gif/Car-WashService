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

    // Add service 1 ($8.50)
    await page.evaluate(() => {
        PosApp.addServiceToCart(1);
    });
    await page.waitForTimeout(400);

    // Dismiss upsell modal properly
    await page.evaluate(() => {
        const m = document.getElementById('modalUpsellPrompt');
        if (m) {
            m.classList.remove('open');
            m.style.display = 'none';
        }
        PosApp._currentUpsellModalOpen = false;
    });
    await page.waitForTimeout(300);

    // 1. Initial State (No discount applied)
    const discBoxText = await page.$eval('#gotposDiscountBox', el => el.innerText.trim());
    console.log('1. Discount Box initially (NO discount applied):', discBoxText.replace(/\n/g, ' '));

    // 2. Click Apply Discount button to open modal
    await page.click('#btnGotposApplyDiscount');
    await page.waitForTimeout(400);

    const isModalOpen = await page.$eval('#modalDiscount', el => el.classList.contains('open'));
    console.log('2. Discount modal opened?', isModalOpen);

    // Screenshot modal
    const ssModal = path.join(__dirname, 'pos_discount_modal.png');
    const modalBox = await page.$('#modalDiscount .modal-content');
    if (modalBox) await modalBox.screenshot({ path: ssModal });

    // 3. Select 10%
    await page.click('.btn-modal-disc[data-disc="10%"]');
    await page.waitForTimeout(200);

    const previewTotal = await page.$eval('#discPreviewTotalUsd', el => el.innerText.trim());
    const previewDisc = await page.$eval('#discPreviewAmount', el => el.innerText.trim());
    console.log('3. Preview inside modal with 10%:', { previewDisc, previewTotal });

    // 4. Click Apply inside modal
    await page.click('#btnApplyDiscountModal');
    await page.waitForTimeout(400);

    // Check cart after applying 10%
    const discBoxAfter10 = await page.$eval('#gotposDiscountBox', el => el.innerText.trim());
    const finSummary10 = await page.$eval('.gotpos-financial-summary', el => el.innerText.trim());
    console.log('4. Discount Box after applying 10%:', discBoxAfter10.replace(/\n/g, ' '));
    console.log('   Financial Summary:\n' + finSummary10);

    // 5. Test applying custom fixed discount ($2.50)
    await page.click('#btnGotposApplyDiscount');
    await page.waitForTimeout(300);
    await page.click('#btnDiscountUnitFixed');
    await page.fill('#discountCustomInput', '2.50');
    await page.waitForTimeout(200);
    await page.click('#btnApplyDiscountModal');
    await page.waitForTimeout(400);

    const discBoxAfterCustom = await page.$eval('#gotposDiscountBox', el => el.innerText.trim());
    const finSummaryCustom = await page.$eval('.gotpos-financial-summary', el => el.innerText.trim());
    console.log('5. Discount Box after applying $2.50:', discBoxAfterCustom.replace(/\n/g, ' '));
    console.log('   Financial Summary:\n' + finSummaryCustom);

    // Screenshot Cart with Discount
    const cartBox = await page.$('#posCartColumn');
    const ssCartDisc = path.join(__dirname, 'pos_cart_with_discount.png');
    if (cartBox) {
        await cartBox.screenshot({ path: ssCartDisc });
        fs.copyFileSync(ssCartDisc, 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003\\pos_cart_with_discount.png');
    }

    await browser.close();
    console.log('=== DISCOUNT ANALYSIS TEST COMPLETED ===');
})();
