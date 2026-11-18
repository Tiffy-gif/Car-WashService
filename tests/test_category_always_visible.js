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

    // Apply the always visible scrollbar styling
    await page.addStyleTag({
        content: `
            .category-vertical-sidebar,
            #categorySidebar {
                flex: 0 0 108px !important;
                width: 108px !important;
                min-width: 108px !important;
                max-width: 108px !important;
                height: 100% !important;
                overflow: hidden !important;
                padding: 2px 0 16px 0 !important;
                display: flex !important;
                flex-direction: column !important;
                border-right: 1px solid var(--gotpos-border) !important;
                box-sizing: border-box !important;
                background: #ffffff !important;
            }

            .category-list-scroll,
            #categoryListWrap {
                flex: 1 1 0% !important;
                min-height: 0 !important;
                height: 100% !important;
                display: flex !important;
                flex-direction: column !important;
                width: 100% !important;
                gap: 8px !important;
                align-items: center !important;
                overflow-y: auto !important;
                overflow-x: hidden !important;
                direction: ltr !important;
                padding: 4px 1px 16px 5px !important;
                box-sizing: border-box !important;
                scroll-behavior: smooth !important;
                -webkit-overflow-scrolling: touch !important;
                overscroll-behavior-y: contain !important;
                scrollbar-width: thin !important;
                scrollbar-color: #cbd5e1 rgba(0, 0, 0, 0.03) !important;
            }

            .category-vertical-sidebar .category-pill,
            #categorySidebar .category-pill {
                width: 90px !important;
                min-width: 90px !important;
                max-width: 90px !important;
                height: 88px !important;
                min-height: 88px !important;
                max-height: 88px !important;
                margin: 0 5px 0 3px !important;
                flex-shrink: 0 !important;
            }

            /* Permanent visible light-gray scrollbar */
            .category-list-scroll::-webkit-scrollbar,
            #categoryListWrap::-webkit-scrollbar {
                width: 5px !important;
                display: block !important;
            }

            .category-list-scroll::-webkit-scrollbar-track,
            #categoryListWrap::-webkit-scrollbar-track {
                background: rgba(0, 0, 0, 0.03) !important;
                border-radius: 9999px !important;
                margin: 4px 0 !important;
            }

            .category-list-scroll::-webkit-scrollbar-thumb,
            #categoryListWrap::-webkit-scrollbar-thumb {
                background: #cbd5e1 !important;
                border-radius: 9999px !important;
                transition: background-color 0.2s ease !important;
            }

            .category-list-scroll::-webkit-scrollbar-thumb:hover,
            #categoryListWrap::-webkit-scrollbar-thumb:hover,
            .category-list-scroll:hover::-webkit-scrollbar-thumb,
            #categoryListWrap:hover::-webkit-scrollbar-thumb,
            #categorySidebar:hover .category-list-scroll::-webkit-scrollbar-thumb {
                background: #94a3b8 !important;
            }

            .category-list-scroll::-webkit-scrollbar-thumb:active,
            #categoryListWrap::-webkit-scrollbar-thumb:active {
                background: #64748b !important;
            }

            /* Dark mode */
            [data-theme="dark"] .category-list-scroll,
            [data-theme="dark"] #categoryListWrap {
                scrollbar-color: #334155 rgba(255, 255, 255, 0.04) !important;
            }

            [data-theme="dark"] .category-list-scroll::-webkit-scrollbar-track,
            [data-theme="dark"] #categoryListWrap::-webkit-scrollbar-track {
                background: rgba(255, 255, 255, 0.04) !important;
            }

            [data-theme="dark"] .category-list-scroll::-webkit-scrollbar-thumb,
            [data-theme="dark"] #categoryListWrap::-webkit-scrollbar-thumb {
                background: #334155 !important;
            }

            [data-theme="dark"] .category-list-scroll::-webkit-scrollbar-thumb:hover,
            [data-theme="dark"] #categoryListWrap::-webkit-scrollbar-thumb:hover,
            [data-theme="dark"] .category-list-scroll:hover::-webkit-scrollbar-thumb,
            [data-theme="dark"] #categoryListWrap:hover::-webkit-scrollbar-thumb,
            [data-theme="dark"] #categorySidebar:hover .category-list-scroll::-webkit-scrollbar-thumb {
                background: #475569 !important;
            }
        `
    });

    // 1. Capture at rest (no mouse movement, no hover, no scroll)
    await page.screenshot({ 
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/category_always_visible_idle.png',
        clip: { x: 0, y: 50, width: 140, height: 750 }
    });
    console.log('Saved category_always_visible_idle.png');

    // 2. Add extra categories so scrollbar is shorter and very distinct
    await page.evaluate(() => {
        const lw = document.getElementById('categoryListWrap');
        for (let i = 1; i <= 6; i++) {
            const btn = document.createElement('button');
            btn.className = 'category-pill';
            btn.innerHTML = `<img src="icons/cat-all.png" class="category-icon-img"><span class="pill-text">Extra ${i}</span>`;
            lw.appendChild(btn);
        }
    });

    // Capture with extra categories at rest (no scroll, no hover)
    await page.screenshot({ 
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/category_always_visible_with_items.png',
        clip: { x: 0, y: 50, width: 140, height: 750 }
    });
    console.log('Saved category_always_visible_with_items.png');

    // 3. Hover and scroll
    const btn = await page.$('#categorySidebar .category-pill:nth-child(2)');
    const box = await btn.boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.wheel(0, 150);
    await page.waitForTimeout(50);

    await page.screenshot({ 
        path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/category_always_visible_scrolled.png',
        clip: { x: 0, y: 50, width: 140, height: 750 }
    });
    console.log('Saved category_always_visible_scrolled.png');

    await browser.close();
})();
