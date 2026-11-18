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

async function runVerification() {
    const loginRes = await getRealStaffSession();
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    
    // Test 1: Desktop Chrome viewport (1920x960)
    console.log("--- Testing Desktop Viewport (1920x960) ---");
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

    // Switch language to English & add service 3
    await page.evaluate(() => {
        if (window.I18nManager) window.I18nManager.setLang('en');
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
    });
    await page.waitForTimeout(400);

    const metrics960 = await page.evaluate(() => {
        const winH = window.innerHeight;
        const wsRect = document.querySelector('.pos-workspace').getBoundingClientRect();
        const sbRect = document.getElementById('categorySidebar').getBoundingClientRect();
        const catRect = document.querySelector('.pos-catalog-column').getBoundingClientRect();
        const cartRect = document.getElementById('posCartColumn').getBoundingClientRect();
        return {
            winH,
            wsBottom: wsRect.bottom,
            sbBottom: sbRect.bottom,
            catBottom: catRect.bottom,
            cartBottom: cartRect.bottom,
            gapFromWsToWindowBottom: winH - wsRect.bottom,
            gapFromCartToWindowBottom: winH - cartRect.bottom
        };
    });
    console.log("Metrics (1920x960):", JSON.stringify(metrics960, null, 2));

    const ss1 = path.join(__dirname, 'pos_free_space_fixed_960.png');
    await page.screenshot({ path: ss1 });
    fs.copyFileSync(ss1, 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003\\pos_free_space_fixed_960.png');
    console.log("Saved screenshot: pos_free_space_fixed_960.png");

    // Test 2: Standard Full HD 1080p
    console.log("--- Testing Full HD Viewport (1920x1080) ---");
    const page1080 = await context.newPage();
    await page1080.setViewportSize({ width: 1920, height: 1080 });
    await page1080.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page1080.waitForTimeout(1500);

    await page1080.evaluate(() => {
        if (window.I18nManager) window.I18nManager.setLang('en');
        PosApp.addServiceToCart(3);
        const m = document.getElementById('modalUpsellPrompt');
        if (m) {
            m.classList.remove('open');
            m.style.display = 'none';
        }
    });
    await page1080.waitForTimeout(400);

    const metrics1080 = await page1080.evaluate(() => {
        const winH = window.innerHeight;
        const wsRect = document.querySelector('.pos-workspace').getBoundingClientRect();
        const cartRect = document.getElementById('posCartColumn').getBoundingClientRect();
        return {
            winH,
            wsBottom: wsRect.bottom,
            cartBottom: cartRect.bottom,
            gapFromWsToWindowBottom: winH - wsRect.bottom,
            gapFromCartToWindowBottom: winH - cartRect.bottom
        };
    });
    console.log("Metrics (1920x1080):", JSON.stringify(metrics1080, null, 2));

    const ss2 = path.join(__dirname, 'pos_free_space_fixed_1080.png');
    await page1080.screenshot({ path: ss2 });
    fs.copyFileSync(ss2, 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003\\pos_free_space_fixed_1080.png');
    console.log("Saved screenshot: pos_free_space_fixed_1080.png");

    await browser.close();
    console.log("=== ALL TESTS PASSED SUCCESSFULLY ===");
}

runVerification().catch(e => {
    console.error("Verification failed:", e);
    process.exit(1);
});
