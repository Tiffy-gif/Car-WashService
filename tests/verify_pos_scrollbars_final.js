const { chromium } = require('playwright');
const http = require('http');

const DEVICE_KEY = 'pos_sec_5924af8669fa53d7a7941439e66926ec';
const DEVICE_UID = 'POS-CAASIER';

function getRealStaffSession() {
    return new Promise((resolve) => {
        const payload = JSON.stringify({ username: 'visal', password: '123456' });
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
            res.on('end', () => resolve(JSON.parse(data)));
        });
        req.write(payload);
        req.end();
    });
}

(async () => {
    const loginRes = await getRealStaffSession();
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });

    await context.addInitScript((loginData) => {
        localStorage.setItem('carwash_pos_config', JSON.stringify({
            backofficeUrl: 'http://localhost/Car-WashService',
            deviceUid: 'POS-CAASIER',
            deviceKey: 'pos_sec_5924af8669fa53d7a7941439e66926ec',
            exchangeRate: 4100
        }));
        localStorage.setItem('cw_pos_staff_user', JSON.stringify(loginData.user));
        localStorage.setItem('cw_pos_session_token', loginData.session_token);
        sessionStorage.setItem('cw_pos_session_token', loginData.session_token);
        localStorage.setItem('carwash_pos_has_connected', 'true');
    }, loginRes);

    const page = await context.newPage();
    await page.goto('http://localhost/Car-WashService/pos/index.html');
    await page.waitForTimeout(2000);

    // Refresh catalog
    await page.evaluate(async () => {
        if (window.PosApp && typeof PosApp.syncCatalog === 'function') {
            await PosApp.syncCatalog(true);
        }
    });
    await page.waitForTimeout(1000);

    // 1. Capture idle state (no scrollbar visible)
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_scrollbar_idle_final.png' });
    console.log('Saved pos_scrollbar_idle_final.png');

    // 2. Scroll grid with mouse wheel
    await page.hover('.catalog-grid-wrap');
    await page.mouse.wheel(0, 400);
    await page.waitForTimeout(100);

    // 3. Capture scrolling state (scrollbar thumb visibly displayed)
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_scrollbar_scrolling_final.png' });
    console.log('Saved pos_scrollbar_scrolling_final.png');

    // 4. Test Dark Theme
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'dark');
    });
    await page.waitForTimeout(500);

    // Capture idle in dark theme
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_scrollbar_dark_idle.png' });
    console.log('Saved pos_scrollbar_dark_idle.png');

    // Scroll in dark theme
    await page.mouse.wheel(0, 400);
    await page.waitForTimeout(100);
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_scrollbar_dark_scrolling.png' });
    console.log('Saved pos_scrollbar_dark_scrolling.png');

    await browser.close();
    console.log('Verification finished successfully!');
})();
