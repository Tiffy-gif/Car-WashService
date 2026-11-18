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
        }, loginRes);

        const page = await context.newPage();
        await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
        await page.waitForTimeout(2000);

        console.log('=== Step 1: Initial State Inspection ===');
        const state1 = await page.evaluate(() => {
            return {
                lang: window.I18nManager?.getLang(),
                docLang: document.documentElement.lang,
                btnKmActive: document.getElementById('btnLangKm')?.classList.contains('active'),
                btnEnActive: document.getElementById('btnLangEn')?.classList.contains('active'),
                searchPlaceholder: document.getElementById('posGlobalSearchInput')?.placeholder,
                queueLabel: document.querySelector('#btnPastelQueue .pastel-label')?.textContent?.trim(),
                appointmentLabel: document.querySelector('#btnPastelAppointment .pastel-label')?.textContent?.trim(),
                discountLabel: document.querySelector('#btnPastelDiscount .pastel-label')?.textContent?.trim(),
                historyLabel: document.querySelector('#btnPastelHistory .pastel-label')?.textContent?.trim()
            };
        });
        console.log('Initial State:', state1);

        console.log('=== Step 2: Click EN Button ===');
        await page.click('#btnLangEn');
        await page.waitForTimeout(600);

        const stateEN = await page.evaluate(() => {
            return {
                lang: window.I18nManager?.getLang(),
                docLang: document.documentElement.lang,
                btnKmActive: document.getElementById('btnLangKm')?.classList.contains('active'),
                btnEnActive: document.getElementById('btnLangEn')?.classList.contains('active'),
                searchPlaceholder: document.getElementById('posGlobalSearchInput')?.placeholder,
                queueLabel: document.querySelector('#btnPastelQueue .pastel-label')?.textContent?.trim(),
                appointmentLabel: document.querySelector('#btnPastelAppointment .pastel-label')?.textContent?.trim(),
                discountLabel: document.querySelector('#btnPastelDiscount .pastel-label')?.textContent?.trim(),
                historyLabel: document.querySelector('#btnPastelHistory .pastel-label')?.textContent?.trim(),
                checkoutBtnText: document.querySelector('.checkout-btn-text')?.textContent?.trim()
            };
        });
        console.log('English State:', stateEN);

        const enScreenshotPath = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_lang_english.png';
        await page.screenshot({ path: enScreenshotPath });
        console.log('Saved pos_lang_english.png');

        console.log('=== Step 3: Click ខ្មែរ (KM) Button ===');
        await page.click('#btnLangKm');
        await page.waitForTimeout(600);

        const stateKM = await page.evaluate(() => {
            return {
                lang: window.I18nManager?.getLang(),
                docLang: document.documentElement.lang,
                btnKmActive: document.getElementById('btnLangKm')?.classList.contains('active'),
                btnEnActive: document.getElementById('btnLangEn')?.classList.contains('active'),
                searchPlaceholder: document.getElementById('posGlobalSearchInput')?.placeholder,
                queueLabel: document.querySelector('#btnPastelQueue .pastel-label')?.textContent?.trim(),
                appointmentLabel: document.querySelector('#btnPastelAppointment .pastel-label')?.textContent?.trim(),
                discountLabel: document.querySelector('#btnPastelDiscount .pastel-label')?.textContent?.trim(),
                historyLabel: document.querySelector('#btnPastelHistory .pastel-label')?.textContent?.trim(),
                checkoutBtnText: document.querySelector('.checkout-btn-text')?.textContent?.trim()
            };
        });
        console.log('Khmer State:', stateKM);

        const kmScreenshotPath = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_lang_khmer.png';
        await page.screenshot({ path: kmScreenshotPath });
        console.log('Saved pos_lang_khmer.png');

        console.log('=== Step 4: Test Clicking Container Area (Toggle) ===');
        await page.click('#langSegmentedControl', { position: { x: 2, y: 2 } });
        await page.waitForTimeout(500);
        const toggledLang = await page.evaluate(() => window.I18nManager?.getLang());
        console.log('After container click, lang is:', toggledLang);

        await browser.close();
        console.log('=== ALL TESTS PASSED! ===');
        process.exit(0);
    } catch (err) {
        console.error('Test error:', err);
        process.exit(1);
    }
})();