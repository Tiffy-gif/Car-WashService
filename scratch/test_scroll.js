const { chromium } = require('playwright');
const http = require('http');

const DEVICE_KEY = 'my_test_device_key_unique9';
const DEVICE_UID = 'POS-CAASIER';

function getStaffSession() {
    return new Promise((resolve) => {
        const payload = JSON.stringify({ username: 'visal', pin: '123456' });
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
    const loginRes = await getStaffSession();

    const browser = await chromium.launch({ 
        channel: 'chrome', 
        headless: true,
        ignoreDefaultArgs: ['--hide-scrollbars']
    });
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
        localStorage.setItem('pos_catalog_display_mode', 'picture');
    }, loginRes);

    const page = await context.newPage();
    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2000);

    // Refresh catalog
    await page.evaluate(async () => {
        if (window.PosApp && typeof PosApp.syncCatalog === 'function') {
            await PosApp.syncCatalog(true);
        }
    });
    await page.waitForTimeout(2000);

    // Click 'All'
    await page.evaluate(() => {
        const allBtn = Array.from(document.querySelectorAll('.cat-btn, [data-category]')).find(el => 
            el.innerText.includes('All') || el.innerText.includes('???????') || el.getAttribute('data-category') === 'all'
        );
        if (allBtn) allBtn.click();
    });
    await page.waitForTimeout(1000);

    // Scroll down on the catalog-grid-wrap
    const scrollWrap = await page.$('.catalog-grid-wrap');
    if (scrollWrap) {
        await page.evaluate((el) => {
            el.scrollTop = 260;
        }, scrollWrap);
    }
    await page.waitForTimeout(800);

    const artifactDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';
    await page.screenshot({ path: `${artifactDir}/pos_cards_scrolled.png` });
    console.log('Saved pos_cards_scrolled.png');

    await browser.close();
})();
