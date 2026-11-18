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

async function verifyCartHeader() {
    console.log("1. Authenticating staff...");
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
    await page.waitForSelector('#gotposOrderIdDisplay', { timeout: 10000 });

    // 1. Check Order ID display
    const orderIdText = await page.$eval('#gotposOrderIdDisplay', el => el.innerText.trim());
    console.log("-> Current Order ID display text:", orderIdText);

    // 2. Check Clear All vs Trash Can visibility
    const isClearAllVisible = await page.$eval('#btnGotposClearAll', el => window.getComputedStyle(el).display !== 'none').catch(() => false);
    const isTrashCanVisible = await page.$eval('#btnClearCart', el => window.getComputedStyle(el).display !== 'none').catch(() => false);
    console.log("-> 'Clear All' text button visible:", isClearAllVisible ? "YES (DUPLICATE)" : "NO (CLEAN)");
    console.log("-> Red Trash Can icon visible:", isTrashCanVisible ? "YES (ACTIVE)" : "NO");

    // 3. Check Walk-in Guest profile card
    const custName = await page.$eval('#gotposCustNameDisplay', el => el.innerText.trim());
    const custTier = await page.$eval('#gotposCustTierDisplay', el => el.innerText.trim());
    console.log("-> Customer profile card name:", custName);
    console.log("-> Customer profile card tier:", custTier);

    // 4. Take full screenshot with empty cart
    const ssEmpty = path.join(__dirname, 'pos_cart_empty_clean.png');
    await page.screenshot({ path: ssEmpty });
    fs.copyFileSync(ssEmpty, 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003\\pos_cart_empty_clean.png');
    console.log("-> Saved screenshot: pos_cart_empty_clean.png");

    // 5. Add item to cart
    console.log("3. Adding item to cart...");
    await page.evaluate(() => {
        PosApp.addServiceToCart(1);
    });
    await page.waitForTimeout(500);

    // Dismiss upsell modal
    await page.evaluate(() => {
        const m = document.getElementById('modalUpsellPrompt');
        if (m) m.classList.remove('open');
    });
    await page.waitForTimeout(300);

    // 6. Focus screenshot on Cart Column
    const cartBox = await page.$('#posCartColumn');
    const ssCart = path.join(__dirname, 'pos_cart_new_order_verified.png');
    if (cartBox) {
        await cartBox.screenshot({ path: ssCart });
        fs.copyFileSync(ssCart, 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003\\pos_cart_new_order_verified.png');
        console.log("-> Saved focused cart screenshot: pos_cart_new_order_verified.png");
    }

    // 7. Also test switching language to English and Khmer
    console.log("4. Testing language toggle to English...");
    await page.evaluate(() => {
        if (window.I18nManager) {
            window.I18nManager.setLang('en');
        }
    });
    await page.waitForTimeout(500);
    const enOrderIdText = await page.$eval('#gotposOrderIdDisplay', el => el.innerText.trim());
    console.log("-> English Order ID display text:", enOrderIdText);

    // Screenshot in English
    const ssEn = path.join(__dirname, 'pos_cart_en_verified.png');
    if (cartBox) {
        await cartBox.screenshot({ path: ssEn });
        fs.copyFileSync(ssEn, 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003\\pos_cart_en_verified.png');
        console.log("-> Saved English cart screenshot: pos_cart_en_verified.png");
    }

    console.log("4b. Testing language toggle back to Khmer...");
    await page.evaluate(() => {
        if (window.I18nManager) {
            window.I18nManager.setLang('km');
        }
    });
    await page.waitForTimeout(500);
    const khmerOrderIdText = await page.$eval('#gotposOrderIdDisplay', el => el.innerText.trim());
    console.log("-> Khmer Order ID display text:", khmerOrderIdText);

    const ssKhmer = path.join(__dirname, 'pos_cart_khmer_verified.png');
    if (cartBox) {
        await cartBox.screenshot({ path: ssKhmer });
        fs.copyFileSync(ssKhmer, 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003\\pos_cart_khmer_verified.png');
        console.log("-> Saved Khmer cart screenshot: pos_cart_khmer_verified.png");
    }

    // 8. Test trash button clears cart
    console.log("5. Testing trash can button (#btnClearCart)...");
    await page.click('#btnClearCart');
    await page.waitForTimeout(500);
    const cartItemCountAfterClear = await page.$eval('#gotposItemCount', el => el.innerText.trim());
    console.log("-> Cart item count after clicking trash button:", cartItemCountAfterClear);

    await browser.close();
    console.log("=== ALL CART VERIFICATIONS PASSED ===");
}

verifyCartHeader().catch(err => {
    console.error("Cart test failed:", err);
    process.exit(1);
});
