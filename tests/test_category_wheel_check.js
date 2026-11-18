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

    // Apply direction: ltr on category list scroll
    await page.addStyleTag({
        content: `
            .category-list-scroll,
            #categoryListWrap {
                direction: ltr !important;
            }
        `
    });

    // Add 10 more mock categories
    await page.evaluate(() => {
        const lw = document.getElementById('categoryListWrap');
        for (let i = 1; i <= 10; i++) {
            const btn = document.createElement('button');
            btn.className = 'category-pill';
            btn.innerHTML = `<img src="icons/cat-all.png" class="category-icon-img"><span class="pill-text">Extra ${i}</span>`;
            lw.appendChild(btn);
        }
    });

    // Hover over category and wheel a little bit
    const btn = await page.$('#categorySidebar .category-pill:nth-child(2)');
    const box = await btn.boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.wheel(0, 150);
    await page.waitForTimeout(50);

    await page.screenshot({ 
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/extra_categories_scrolling_on_right.png',
        clip: { x: 0, y: 50, width: 140, height: 750 }
    });
    console.log('Saved extra_categories_scrolling_on_right.png');

    await browser.close();
})();
