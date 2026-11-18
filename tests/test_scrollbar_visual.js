const { chromium } = require('playwright');
const http = require('http');

const DEVICE_KEY = 'pos_sec_5924af8669fa53d7a7941439e66926ec';
const DEVICE_UID = 'POS-CAASIER';

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
                'X-Device-UID': DEVICE_UID,
                'X-Device-Key': DEVICE_KEY,
                'Content-Length': Buffer.byteLength(payload)
            }
        }, res => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => resolve(JSON.parse(data)));
        });
        req.on('error', reject);
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

    // Inject test CSS without scrollbar-width overriding ::-webkit-scrollbar
    await page.addStyleTag({
        content: `
            .catalog-grid-wrap,
            .category-list-scroll,
            .cart-items-list {
                scrollbar-width: auto !important;
            }
            .catalog-grid-wrap::-webkit-scrollbar,
            .category-list-scroll::-webkit-scrollbar,
            .cart-items-list::-webkit-scrollbar {
                width: 7px !important;
                height: 7px !important;
                display: block !important;
            }
            .catalog-grid-wrap::-webkit-scrollbar-track,
            .category-list-scroll::-webkit-scrollbar-track,
            .cart-items-list::-webkit-scrollbar-track {
                background: transparent !important;
                border: none !important;
            }
            .catalog-grid-wrap::-webkit-scrollbar-thumb,
            .category-list-scroll::-webkit-scrollbar-thumb,
            .cart-items-list::-webkit-scrollbar-thumb {
                background: transparent !important;
                border-radius: 9999px !important;
                transition: background-color 0.2s ease !important;
            }
            .catalog-grid-wrap.is-scrolling::-webkit-scrollbar-thumb,
            .category-list-scroll.is-scrolling::-webkit-scrollbar-thumb,
            .cart-items-list.is-scrolling::-webkit-scrollbar-thumb {
                background: #94a3b8 !important;
            }
        `
    });

    // 1. Idle state
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/test_idle_exact.png', clip: { x: 750, y: 100, width: 250, height: 400 } });

    // 2. Scrolling state
    await page.evaluate(() => {
        const grid = document.querySelector('.catalog-grid-wrap');
        grid.scrollTop = 250;
        grid.classList.add('is-scrolling');
    });

    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/test_scrolling_exact.png', clip: { x: 750, y: 100, width: 250, height: 400 } });
    console.log('Saved test_idle_exact.png and test_scrolling_exact.png');

    await browser.close();
})();
