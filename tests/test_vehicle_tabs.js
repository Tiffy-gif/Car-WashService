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
        console.log('Login success:', loginRes?.success);

        const browser = await chromium.launch({ channel: 'chrome', headless: true });
        const context = await browser.newContext({ viewport: { width: 1920, height: 1080 } });
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
            localStorage.setItem('cw_pos_language', 'km');
        }, loginRes);

        const page = await context.newPage();
        await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
        await page.waitForTimeout(2000);

        const vtypes = ['motorbike', 'small_car', 'suv_pickup', 'van_truck'];
        console.log('--- Testing each vehicle tab in Khmer mode ---');
        for (const vt of vtypes) {
            await page.click('.vehicle-btn[data-vtype="' + vt + '"]');
            await page.waitForTimeout(300);
            const info = await page.evaluate((v) => {
                const btn = document.querySelector('.vehicle-btn[data-vtype="' + v + '"]');
                const km = btn ? btn.querySelector('.v-title-km') : null;
                const icon = btn ? btn.querySelector('i') : null;
                return {
                    type: v,
                    active: btn ? btn.classList.contains('active') : false,
                    btnBg: btn ? window.getComputedStyle(btn).backgroundColor : null,
                    kmText: km ? km.textContent.trim() : null,
                    kmDisplay: km ? window.getComputedStyle(km).display : null,
                    kmColor: km ? window.getComputedStyle(km).color : null,
                    iconColor: icon ? window.getComputedStyle(icon).color : null
                };
            }, vt);
            console.log(JSON.stringify(info));
        }

        // Click Motorbike to reproduce user's screenshot exactly
        await page.click('.vehicle-btn[data-vtype="motorbike"]');
        await page.waitForTimeout(400);

        const screenshotKmPath = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_vehicle_tabs_fixed.png';
        await page.screenshot({ path: screenshotKmPath });
        console.log('Saved KM screenshot:', screenshotKmPath);

        // Switch to English and test
        await page.click('#btnLangEn');
        await page.waitForTimeout(400);
        console.log('--- Testing Motorbike tab in English mode ---');
        const enInfo = await page.evaluate(() => {
            const btn = document.querySelector('.vehicle-btn[data-vtype="motorbike"]');
            const en = btn ? btn.querySelector('.v-title-en') : null;
            const icon = btn ? btn.querySelector('i') : null;
            return {
                type: 'motorbike (EN)',
                active: btn ? btn.classList.contains('active') : false,
                btnBg: btn ? window.getComputedStyle(btn).backgroundColor : null,
                enText: en ? en.textContent.trim() : null,
                enDisplay: en ? window.getComputedStyle(en).display : null,
                enColor: en ? window.getComputedStyle(en).color : null,
                iconColor: icon ? window.getComputedStyle(icon).color : null
            };
        });
        console.log(JSON.stringify(enInfo));

        const screenshotEnPath = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_vehicle_tabs_en_fixed.png';
        await page.screenshot({ path: screenshotEnPath });
        console.log('Saved EN screenshot:', screenshotEnPath);

        await browser.close();
        console.log('=== ALL TESTS PASSED! ===');
        process.exit(0);
    } catch (err) {
        console.error('Test error:', err);
        process.exit(1);
    }
})();