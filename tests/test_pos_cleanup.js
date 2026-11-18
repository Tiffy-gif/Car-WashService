const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
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
            res.on('end', () => {
                try {
                    const json = JSON.parse(data);
                    resolve(json);
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

async function verifyCleanup() {
    console.log("1. Authenticating cashier session...");
    const loginRes = await getRealStaffSession();
    if (!loginRes.success) throw new Error("Staff auth failed");

    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });

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
    console.log("2. Navigating to POS...");
    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(1000);
    await page.waitForSelector('.category-pill:nth-child(2)', { timeout: 10000 });
    await page.waitForTimeout(1000);

    // 3. Verify Categories (Ensure NO Test or Test1)
    const categoryLabels = await page.$$eval('#categorySidebar .category-pill .pill-text', els => els.map(e => e.textContent.trim()));
    console.log("-> Rendered Categories in Sidebar:", categoryLabels);
    const hasTestCat = categoryLabels.some(l => l.toLowerCase().includes('test') || l === 'សា' || l === 'សដថ');
    console.log("-> Are test categories present?", hasTestCat ? "YES (FAIL)" : "NO (CLEAN)");

    // 4. Verify Header Elements
    const hasOpenOrder = await page.$eval('.gotpos-open-order-chip', el => window.getComputedStyle(el).display !== 'none').catch(() => false);
    const hasHeaderSettings = await page.$eval('#btnHeaderSettings', el => window.getComputedStyle(el).display !== 'none').catch(() => false);
    const hasHeaderBackoffice = await page.$eval('#btnHeaderBackoffice', el => window.getComputedStyle(el).display !== 'none').catch(() => false);
    const hasHeaderFullscreen = await page.$eval('#btnToggleFullscreen', el => window.getComputedStyle(el).display !== 'none').catch(() => false);

    console.log("-> Header Open Order Chip Visible?", hasOpenOrder);
    console.log("-> Header Standalone Settings Gear Visible?", hasHeaderSettings);
    console.log("-> Header Backoffice Link Visible?", hasHeaderBackoffice);
    console.log("-> Header Fullscreen Icon Visible?", hasHeaderFullscreen);

    // 5. Verify Cart Header
    const hasCartMore = await page.$eval('#btnGotposMore', el => window.getComputedStyle(el).display !== 'none').catch(() => false);
    const hasCartFullscreen = await page.$eval('#btnGotposFullscreen', el => window.getComputedStyle(el).display !== 'none').catch(() => false);
    const hasCartTrash = await page.$eval('#btnClearCart', el => window.getComputedStyle(el).display !== 'none').catch(() => false);

    console.log("-> Cart 3-Dots Button Visible?", hasCartMore);
    console.log("-> Cart Duplicate Fullscreen Visible?", hasCartFullscreen);
    console.log("-> Cart Trash Button Visible?", hasCartTrash);

    // 6. Screenshot 1: Clean Overview (Empty Cart)
    const ss1 = path.join(__dirname, 'pos_cleaned_up_overview.png');
    await page.screenshot({ path: ss1 });
    fs.copyFileSync(ss1, 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003\\pos_cleaned_up_overview.png');
    console.log("-> Saved Screenshot 1: pos_cleaned_up_overview.png");

    // 7. Add an item to cart to inspect Financial Breakdown & Payment Methods
    console.log("7. Adding item to cart via PosApp.addServiceToCart(1)...");
    await page.evaluate(() => {
        PosApp.addServiceToCart(1);
    });
    await page.waitForTimeout(500);

    // Dismiss upsell modal if shown
    const upsellDismiss = await page.$('#modalUpsellPrompt .btn-outline, #modalUpsellPrompt button[onclick*="close"], #modalUpsellPrompt [data-dismiss]');
    if (upsellDismiss && await upsellDismiss.isVisible()) {
        await upsellDismiss.click();
        await page.waitForTimeout(400);
    } else {
        await page.evaluate(() => {
            const m = document.getElementById('modalUpsellPrompt');
            if (m) m.classList.remove('open');
        });
    }

    // Check financial rows
    const summaryText = await page.$eval('.gotpos-financial-summary', el => el.innerText).catch(() => '');
    console.log("-> Cart Financial Breakdown Content:\n" + summaryText);
    const hasGst = summaryText.includes('GST');
    const hasServiceCharge = summaryText.includes('Service Charge');
    console.log("-> Has GST row?", hasGst ? "YES (FAIL)" : "NO (CLEAN)");
    console.log("-> Has Service Charge row?", hasServiceCharge ? "YES (FAIL)" : "NO (CLEAN)");

    // Check payment methods
    const methodsText = await page.$eval('.gotpos-methods-grid', el => el.innerText).catch(() => '');
    console.log("-> Payment Methods Grid Content:\n" + methodsText.replace(/\n/g, ' '));
    const hasUpi = methodsText.includes('UPI');
    const hasKhqr = methodsText.includes('KHQR');
    console.log("-> Has foreign UPI label?", hasUpi ? "YES (FAIL)" : "NO (CLEAN)");
    console.log("-> Has Cambodian KHQR label?", hasKhqr ? "YES (CLEAN)" : "NO");

    // Check pastel buttons
    const pastelLabels = await page.$$eval('.gotpos-advance-features .pastel-label', els => els.map(e => e.textContent.trim()));
    console.log("-> Bottom Pastel Action Buttons:", pastelLabels);

    // 8. Screenshot 2: Clean Overview with Items in Cart
    const ss2 = path.join(__dirname, 'pos_cleaned_up_cart_with_items.png');
    await page.screenshot({ path: ss2 });
    fs.copyFileSync(ss2, 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003\\pos_cleaned_up_cart_with_items.png');
    console.log("-> Saved Screenshot 2: pos_cleaned_up_cart_with_items.png");

    await browser.close();
    console.log("=== ALL CLEANUP CHECKS COMPLETED ===");
}

verifyCleanup().catch(e => {
    console.error("Test error:", e);
    process.exit(1);
});
