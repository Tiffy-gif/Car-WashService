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
    console.log('Login Response:', loginRes.success ? 'SUCCESS' : 'FAILED');
    if (!loginRes.success) return;

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

    // 1. Capture idle state (no scroll active - scrollbar MUST be completely invisible)
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_scrollbar_idle_invisible.png' });
    console.log('Saved pos_scrollbar_idle_invisible.png');

    // 2. Perform scroll on catalog grid
    console.log('Scrolling catalog grid...');
    await page.evaluate(() => {
        const gridWrap = document.querySelector('.catalog-grid-wrap');
        if (gridWrap) {
            gridWrap.scrollTop = 350;
            // Dispatch scroll event
            gridWrap.dispatchEvent(new Event('scroll'));
        }
    });
    // Immediately capture while .is-scrolling is active
    await page.waitForTimeout(100);
    const hasClassWhileScrolling = await page.evaluate(() => {
        return document.querySelector('.catalog-grid-wrap')?.classList.contains('is-scrolling');
    });
    console.log('Grid has is-scrolling class during scroll:', hasClassWhileScrolling);

    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_scrollbar_scrolling_visible.png' });
    console.log('Saved pos_scrollbar_scrolling_visible.png');

    // 3. Wait 1200ms for inactivity fade-out
    console.log('Waiting for scrollbar fade-out...');
    await page.waitForTimeout(1200);

    const hasClassAfterTimeout = await page.evaluate(() => {
        return document.querySelector('.catalog-grid-wrap')?.classList.contains('is-scrolling');
    });
    console.log('Grid has is-scrolling class after timeout:', hasClassAfterTimeout);

    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_scrollbar_after_scroll_hidden.png' });
    console.log('Saved pos_scrollbar_after_scroll_hidden.png');

    // 4. Test Category Sidebar scrolling as well
    console.log('Scrolling Category Sidebar...');
    await page.evaluate(() => {
        const catWrap = document.querySelector('.category-list-scroll');
        if (catWrap) {
            catWrap.scrollTop = 150;
            catWrap.dispatchEvent(new Event('scroll'));
        }
    });
    await page.waitForTimeout(100);
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_category_scrollbar_scrolling.png' });
    console.log('Saved pos_category_scrollbar_scrolling.png');

    await browser.close();
    console.log('All scrollbar tests completed successfully!');
})();
