const { chromium } = require('playwright');
const http = require('http');

const DEVICE_KEY = 'pos_sec_5924af8669fa53d7a7941439e66926ec';
const DEVICE_UID = 'POS-CAASIER';

function getRealStaffSession() {
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
    const loginRes = await getRealStaffSession();
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

    // Add extra categories so it's scrollable
    await page.evaluate(() => {
        const lw = document.getElementById('categoryListWrap');
        if (lw && lw.children.length < 12) {
            for (let i = 1; i <= 6; i++) {
                const btn = document.createElement('button');
                btn.className = 'category-pill';
                btn.innerHTML = `<img src="icons/cat-all.png" class="category-icon-img"><span class="pill-text">Extra ${i}</span>`;
                lw.appendChild(btn);
            }
        }
    });
    await page.waitForTimeout(500);

    // 1. Initial idle state screenshot
    console.log('1. Checking Idle state (nearly invisible)...');
    const idleCheck = await page.evaluate(() => {
        const lw = document.getElementById('categoryListWrap');
        return {
            isScrolling: lw.classList.contains('is-scrolling'),
            scrollTop: lw.scrollTop,
            scrollHeight: lw.scrollHeight,
            clientHeight: lw.clientHeight
        };
    });
    console.log('Idle check:', idleCheck);

    await page.screenshot({ 
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/category_subtle_idle.png',
        clip: { x: 0, y: 50, width: 140, height: 750 }
    });
    console.log('Saved category_subtle_idle.png');

    // 2. Active scroll (subtle #E5E7EB 4px scrollbar)
    console.log('2. Triggering scroll...');
    const btn = await page.$('#categorySidebar .category-pill:nth-child(2)');
    const box = await btn.boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.wheel(0, 150);
    await page.waitForTimeout(80);

    const scrollCheck = await page.evaluate(() => {
        const lw = document.getElementById('categoryListWrap');
        return {
            isScrolling: lw.classList.contains('is-scrolling'),
            scrollTop: lw.scrollTop
        };
    });
    console.log('Scrolling check:', scrollCheck);

    await page.screenshot({ 
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/category_subtle_scrolling.png',
        clip: { x: 0, y: 50, width: 140, height: 750 }
    });
    console.log('Saved category_subtle_scrolling.png');

    // Also take a zoomed-in edge screenshot during scrolling to inspect the 4px #E5E7EB thumb
    await page.screenshot({ 
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/category_subtle_edge_zoom.png',
        clip: { x: 85, y: 150, width: 45, height: 250 }
    });
    console.log('Saved category_subtle_edge_zoom.png');

    // 3. Wait 1200ms after scrolling stops (fade out smoothly)
    console.log('3. Waiting 1200ms for auto-hide timeout...');
    await page.waitForTimeout(1200);

    const stoppedCheck = await page.evaluate(() => {
        const lw = document.getElementById('categoryListWrap');
        return {
            isScrolling: lw.classList.contains('is-scrolling'),
            scrollTop: lw.scrollTop
        };
    });
    console.log('Stopped check (after 1200ms):', stoppedCheck);

    await page.screenshot({ 
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/category_subtle_after_stopped.png',
        clip: { x: 0, y: 50, width: 140, height: 750 }
    });
    console.log('Saved category_subtle_after_stopped.png');

    // 4. Dark Mode Testing
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'dark');
    });
    await page.waitForTimeout(300);

    // Dark Idle
    await page.screenshot({ 
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/category_subtle_dark_idle.png',
        clip: { x: 0, y: 50, width: 140, height: 750 }
    });
    console.log('Saved category_subtle_dark_idle.png');

    // Dark Scrolling
    await page.mouse.wheel(0, -100);
    await page.waitForTimeout(80);

    await page.screenshot({ 
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/category_subtle_dark_scrolling.png',
        clip: { x: 0, y: 50, width: 140, height: 750 }
    });
    console.log('Saved category_subtle_dark_scrolling.png');

    // Wait for dark mode to hide after stopping
    await page.waitForTimeout(1200);
    await page.screenshot({ 
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/category_subtle_dark_after_stopped.png',
        clip: { x: 0, y: 50, width: 140, height: 750 }
    });
    console.log('Saved category_subtle_dark_after_stopped.png');

    // 5. Full Screen POS Screenshot
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'light');
    });
    await page.waitForTimeout(300);
    await page.screenshot({ 
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_full_subtle_scrollbar.png'
    });
    console.log('Saved pos_full_subtle_scrollbar.png');

    await browser.close();
    console.log('Verification completed successfully!');
})();
