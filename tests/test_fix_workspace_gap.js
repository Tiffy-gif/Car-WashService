const { chromium } = require('playwright');
const http = require('http');
const fs = require('fs');
const path = require('path');

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
    const loginRes = await getRealStaffSession();
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    // Match standard 1920x960 desktop window
    const context = await browser.newContext({ viewport: { width: 1920, height: 960 } });
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
    await page.waitForTimeout(1500);

    // Apply the fix in page
    await page.evaluate(() => {
        const style = document.createElement('style');
        style.id = 'fixWorkspaceHeight';
        style.textContent = `
            .view-section#viewPos {
                height: calc(100vh - 58px) !important;
                max-height: calc(100vh - 58px) !important;
                min-height: 0 !important;
            }
            .pos-workspace {
                height: 100% !important;
                max-height: 100% !important;
                flex: 1 1 auto !important;
                min-height: 0 !important;
                padding: 10px 16px 12px 16px !important;
            }
            .pos-catalog-column {
                height: 100% !important;
                max-height: 100% !important;
                min-height: 0 !important;
            }
            .pos-catalog-body {
                height: calc(100% - 54px) !important;
                max-height: calc(100% - 54px) !important;
                min-height: 0 !important;
            }
            #posCartColumn {
                height: 100% !important;
                max-height: 100% !important;
                min-height: 0 !important;
            }
            #categorySidebar {
                height: 100% !important;
                max-height: 100% !important;
                min-height: 0 !important;
            }
        `;
        document.head.appendChild(style);

        // Switch language to English
        if (window.I18nManager) window.I18nManager.setLang('en');

        // Select "All" category pill
        const allPill = document.querySelector('.category-pill[data-category="all"]');
        if (allPill) allPill.click();

        // Add item to cart like user's screenshot
        PosApp.addServiceToCart(3);
    });

    await page.waitForTimeout(400);

    // Dismiss upsell modal
    await page.evaluate(() => {
        const m = document.getElementById('modalUpsellPrompt');
        if (m) {
            m.classList.remove('open');
            m.style.display = 'none';
        }
        const b = document.querySelector('.modal-backdrop');
        if (b) b.remove();
    });

    await page.waitForTimeout(400);

    const checkGap = await page.evaluate(() => {
        const winH = window.innerHeight;
        const ws = document.querySelector('.pos-workspace');
        const wsRect = ws.getBoundingClientRect();
        const sbRect = document.getElementById('categorySidebar').getBoundingClientRect();
        const catRect = document.querySelector('.pos-catalog-column').getBoundingClientRect();
        const cartRect = document.getElementById('posCartColumn').getBoundingClientRect();

        return {
            winH,
            wsBottom: wsRect.bottom,
            sbBottom: sbRect.bottom,
            catBottom: catRect.bottom,
            cartBottom: cartRect.bottom,
            gapFromWsToWindowBottom: winH - wsRect.bottom,
            gapFromCartToWindowBottom: winH - cartRect.bottom
        };
    });

    console.log("Check gap with fix:", JSON.stringify(checkGap, null, 2));

    const ss = path.join(__dirname, 'debug_screen_fixed.png');
    await page.screenshot({ path: ss });
    fs.copyFileSync(ss, 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003\\debug_screen_fixed.png');
    console.log('Saved debug_screen_fixed.png');
    await browser.close();
})();
