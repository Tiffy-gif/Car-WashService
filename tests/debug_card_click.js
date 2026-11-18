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
    try {
        const loginRes = await getRealStaffSession();
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
        await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
        await page.waitForTimeout(2000);

        // Turn off upsell
        await page.evaluate(() => {
            if (window.PosApp) window.PosApp.evaluateCartUpsellRules = () => {};
            if (window.PosApi) window.PosApi.evaluateCartUpsell = async () => ({ success: true, has_offer: false });
        });

        // Test 1: Click first card
        console.log('Testing card click...');
        const card = await page.$('#catalogGrid .pos-item-card');
        const cardHtml = await page.evaluate(el => el ? el.outerHTML.substring(0, 300) : 'none', card);
        console.log('Card HTML:', cardHtml);

        await card.click();
        await page.waitForTimeout(600);

        const cartLenAfterClick = await page.evaluate(() => window.PosApp?.cart?.length);
        console.log('Cart length after card.click():', cartLenAfterClick);

        // Test 2: If 0, why?
        if (cartLenAfterClick === 0) {
            console.log('cardPressTriggered:', await page.evaluate(() => window.PosApp?.cardPressTriggered));
            console.log('Calling PosApp.addServiceToCart(1) directly:');
            await page.evaluate(() => window.PosApp?.addServiceToCart(1));
            console.log('Cart length after direct call:', await page.evaluate(() => window.PosApp?.cart?.length));
        }

        await browser.close();
        process.exit(0);
    } catch (err) {
        console.error(err);
        process.exit(1);
    }
})();
