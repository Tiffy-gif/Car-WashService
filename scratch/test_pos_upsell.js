const { chromium } = require('playwright');
const http = require('http');

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
                'X-Device-UID': 'POS-CAASIER',
                'X-Device-Key': 'my_test_device_key_unique9',
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
    const ownerLogin = await loginUser('owner', '123456');

    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    await context.addInitScript((loginData) => {
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
        localStorage.setItem('cw_pos_current_shift', JSON.stringify({ id: 1, cashier_name: 'Shop Owner', status: 'open' }));
        localStorage.removeItem('cw_pos_is_locked');
    }, ownerLogin);

    const page = await context.newPage();
    page.on('console', msg => console.log('BROWSER LOG:', msg.text()));

    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2000);

    // Click on Exterior Wash card
    const card = page.locator('.pos-item-card:has-text("Exterior Wash")').first();
    await card.click();
    console.log('Clicked Exterior Wash card');

    // Wait for the modal to get class 'open'
    const modal = page.locator('#modalUpsellPrompt.open');
    try {
        await modal.waitFor({ state: 'visible', timeout: 5000 });
        console.log('Upsell modal is visible!');
        await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_upsell_addon_popup.png' });

        // Click Accept button
        await page.click('#btnAcceptUpsell');
        console.log('Clicked Accept button');
        await page.waitForTimeout(1000);

        await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_upsell_cart_accepted.png' });
        console.log('Cart accepted screenshot taken!');
    } catch (e) {
        console.error('Modal failed to open:', e.message);
        await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_upsell_error_debug.png' });
    }

    await browser.close();
})();
