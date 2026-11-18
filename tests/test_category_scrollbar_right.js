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

    // Apply exact style: direction LTR, auto/thin scrollbar
    await page.addStyleTag({
        content: `
            .category-list-scroll,
            #categoryListWrap {
                direction: ltr !important;
                scrollbar-width: thin !important;
                scrollbar-color: transparent transparent !important;
                transition: scrollbar-color 0.25s ease !important;
            }
            .category-list-scroll.is-scrolling,
            .category-list-scroll:hover,
            #categorySidebar:hover .category-list-scroll {
                scrollbar-color: #cbd5e1 transparent !important;
            }
            .category-list-scroll::-webkit-scrollbar,
            #categoryListWrap::-webkit-scrollbar {
                width: 5px !important;
                display: block !important;
            }
            .category-list-scroll::-webkit-scrollbar-track,
            #categoryListWrap::-webkit-scrollbar-track {
                background: transparent !important;
            }
            .category-list-scroll::-webkit-scrollbar-thumb,
            #categoryListWrap::-webkit-scrollbar-thumb {
                background: transparent !important;
                border-radius: 9999px !important;
                transition: background-color 0.2s ease !important;
            }
            .category-list-scroll.is-scrolling::-webkit-scrollbar-thumb,
            .category-list-scroll:hover::-webkit-scrollbar-thumb,
            #categorySidebar:hover .category-list-scroll::-webkit-scrollbar-thumb {
                background: #cbd5e1 !important;
            }
            .category-list-scroll::-webkit-scrollbar-thumb:hover,
            #categoryListWrap::-webkit-scrollbar-thumb:hover {
                background: #94a3b8 !important;
            }
        `
    });

    // 1. Idle state (no scrollbar visible)
    await page.screenshot({ 
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/category_test_idle_ltr.png',
        clip: { x: 10, y: 65, width: 120, height: 600 }
    });

    // 2. Hover over category sidebar & scroll
    const bbox = await page.evaluate(() => {
        const el = document.getElementById('categoryListWrap');
        return el.getBoundingClientRect();
    });
    await page.mouse.move(bbox.x + bbox.width / 2, bbox.y + 100);
    await page.mouse.wheel(0, 200);
    await page.waitForTimeout(100);

    // 3. Capture scrolling state
    await page.screenshot({ 
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/category_test_scrolling_ltr.png',
        clip: { x: 10, y: 65, width: 120, height: 600 }
    });

    console.log('Finished capturing category_test_scrolling_ltr.png');
    await browser.close();
})();
