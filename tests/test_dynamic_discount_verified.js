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

async function testDiscountFlow() {
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

    // Switch to English mode
    await page.evaluate(() => {
        if (window.I18nManager) window.I18nManager.setLang('en');
    });
    await page.waitForTimeout(400);

    // Add service 3 (Full Wash, $6.00) like user's screenshot
    await page.evaluate(() => {
        PosApp.addServiceToCart(3);
    });
    await page.waitForTimeout(400);

    // Dismiss upsell modal
    await page.evaluate(() => {
        const m = document.getElementById('modalUpsellPrompt');
        if (m) {
            m.classList.remove('open');
            m.style.display = 'none';
        }
        PosApp._currentUpsellModalOpen = false;
    });
    await page.waitForTimeout(300);

    // 1. Initial State (No Discount)
    const label0 = await page.$eval('#gotposDiscountLabel', el => el.innerText.trim());
    const btnApply0 = await page.$eval('#btnGotposApplyDiscount', el => el.innerText.trim());
    const isRemoveVis0 = await page.$eval('#btnGotposRemoveDiscount', el => window.getComputedStyle(el).display !== 'none');
    const isSummaryRowVis0 = await page.$eval('#discountSummaryRow', el => window.getComputedStyle(el).display !== 'none');
    const total0 = await page.$eval('#cartTotalUsd', el => el.innerText.trim());

    console.log("--- 1. Initial State (0% Discount) ---");
    console.log("Discount Label:", label0);
    console.log("Apply Button:", btnApply0);
    console.log("Remove Button Visible:", isRemoveVis0);
    console.log("Summary Row Visible:", isSummaryRowVis0);
    console.log("Total Payable:", total0);

    const cartCol = await page.$('#posCartColumn');
    const ss0 = path.join(__dirname, 'pos_discount_initial.png');
    if (cartCol) {
        await cartCol.screenshot({ path: ss0 });
        fs.copyFileSync(ss0, 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003\\pos_discount_initial.png');
    }

    // 2. Apply 10% Discount
    console.log("\n--- 2. Applying 10% Discount ---");
    await page.click('#btnGotposApplyDiscount');
    await page.waitForTimeout(300);
    await page.click('.btn-modal-disc[data-disc="10%"]');
    await page.waitForTimeout(200);
    await page.click('#btnApplyDiscountModal');
    await page.waitForTimeout(400);

    const label10 = await page.$eval('#gotposDiscountLabel', el => el.innerText.trim());
    const btnApply10 = await page.$eval('#btnGotposApplyDiscount', el => el.innerText.trim());
    const isRemoveVis10 = await page.$eval('#btnGotposRemoveDiscount', el => window.getComputedStyle(el).display !== 'none');
    const isSummaryRowVis10 = await page.$eval('#discountSummaryRow', el => window.getComputedStyle(el).display !== 'none');
    const discRowText10 = await page.$eval('#discountSummaryRow', el => el.innerText.trim().replace(/\n/g, ' '));
    const total10 = await page.$eval('#cartTotalUsd', el => el.innerText.trim());

    console.log("Discount Label:", label10);
    console.log("Apply Button:", btnApply10);
    console.log("Remove Button Visible:", isRemoveVis10);
    console.log("Summary Row Visible:", isSummaryRowVis10);
    console.log("Summary Row Content:", discRowText10);
    console.log("Total Payable:", total10);

    const ss10 = path.join(__dirname, 'pos_discount_10percent.png');
    if (cartCol) {
        await cartCol.screenshot({ path: ss10 });
        fs.copyFileSync(ss10, 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003\\pos_discount_10percent.png');
    }

    // 3. Test Quick Remove Button [x]
    console.log("\n--- 3. Testing Quick Remove Button [x] ---");
    await page.click('#btnGotposRemoveDiscount');
    await page.waitForTimeout(400);

    const labelAfterRemove = await page.$eval('#gotposDiscountLabel', el => el.innerText.trim());
    const totalAfterRemove = await page.$eval('#cartTotalUsd', el => el.innerText.trim());
    const isSummaryRowAfterRemove = await page.$eval('#discountSummaryRow', el => window.getComputedStyle(el).display !== 'none');

    console.log("Discount Label after remove:", labelAfterRemove);
    console.log("Total Payable after remove:", totalAfterRemove);
    console.log("Summary Row Visible after remove:", isSummaryRowAfterRemove);

    // 4. Test in Khmer Mode
    console.log("\n--- 4. Testing in Khmer Mode ---");
    await page.evaluate(() => {
        if (window.I18nManager) window.I18nManager.setLang('km');
    });
    await page.waitForTimeout(400);

    const labelKm0 = await page.$eval('#gotposDiscountLabel', el => el.innerText.trim());
    const btnKm0 = await page.$eval('#btnGotposApplyDiscount', el => el.innerText.trim());
    console.log("Khmer Discount Label (0%):", labelKm0);
    console.log("Khmer Apply Button:", btnKm0);

    // Apply $1.00 in Khmer
    await page.click('#btnGotposApplyDiscount');
    await page.waitForTimeout(300);
    await page.click('.btn-modal-disc[data-disc="$1"]');
    await page.waitForTimeout(200);
    await page.click('#btnApplyDiscountModal');
    await page.waitForTimeout(400);

    const labelKm1 = await page.$eval('#gotposDiscountLabel', el => el.innerText.trim());
    const btnKm1 = await page.$eval('#btnGotposApplyDiscount', el => el.innerText.trim());
    const discRowKm = await page.$eval('#discountSummaryRow', el => el.innerText.trim().replace(/\n/g, ' '));
    const totalKm = await page.$eval('#cartTotalUsd', el => el.innerText.trim());

    console.log("Khmer Discount Label ($1):", labelKm1);
    console.log("Khmer Edit Button:", btnKm1);
    console.log("Khmer Summary Row:", discRowKm);
    console.log("Khmer Total:", totalKm);

    const ssKm = path.join(__dirname, 'pos_discount_khmer.png');
    if (cartCol) {
        await cartCol.screenshot({ path: ssKm });
        fs.copyFileSync(ssKm, 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003\\pos_discount_khmer.png');
    }

    await browser.close();
    console.log("\n=== ALL DISCOUNT DYNAMIC TESTS PASSED ===");
}

testDiscountFlow().catch(err => {
    console.error("Test failed:", err);
    process.exit(1);
});
