const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1366, height: 768 } });

    await page.goto('http://localhost/Car-WashService/pos/index.html');
    await page.evaluate(() => {
        const cfg = {
            backofficeUrl: 'http://localhost/Car-WashService',
            deviceKey: 'my_test_device_key_unique9',
            exchangeRate: 4100,
            deviceUid: 'POS-CAASIER',
            deviceName: 'Cashier',
            defaultLanguage: 'km',
            idleTimeoutMinutes: 0
        };
        localStorage.setItem('carwash_pos_config', JSON.stringify(cfg));
    });

    await page.reload();
    await page.waitForTimeout(600);

    // Fill login
    await page.fill('#staffLoginUsername, input[name="username"], input[type="text"]', 'visal');
    await page.fill('#staffLoginPin, input[name="pin"], input[type="password"]', '123456');
    await page.click('button:has-text("Sign In with PIN"), #btnStaffLogin');
    await page.waitForTimeout(1500);

    // Mock currentUser as Shop Owner to match user screenshot
    await page.evaluate(() => {
        if (window.PosApp) {
            window.PosApp.currentUser = {
                id: 1,
                username: 'admin',
                full_name: 'Shop Owner',
                role: 'admin',
                role_name_en: 'Shop Owner',
                role_name_km: 'ម្ចាស់ហាង'
            };
            window.PosApp.updateUserHeaderInfo();
        }
    });
    await page.waitForTimeout(300);

    // Capture User Pill in Khmer
    const userPill = await page.$('#userPill');
    if (userPill) {
        await userPill.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/verified_user_pill_km.png' });
        console.log('Saved verified_user_pill_km.png');
    }

    // Inspect computed style of caret
    const caretComputed = await page.evaluate(() => {
        const caret = document.querySelector('#userPill .user-pill-caret');
        if (!caret) return null;
        const cs = window.getComputedStyle(caret);
        return {
            fontSize: cs.fontSize,
            color: cs.color,
            marginLeft: cs.marginLeft,
            display: cs.display,
            verticalAlign: cs.verticalAlign,
            transform: cs.transform
        };
    });
    console.log('Caret computed styles:', caretComputed);

    // Switch to English and capture
    await page.evaluate(() => {
        if (window.I18nManager) window.I18nManager.setLang('en');
        if (window.PosApp) window.PosApp.updateUserHeaderInfo();
    });
    await page.waitForTimeout(300);

    if (userPill) {
        await userPill.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/verified_user_pill_en.png' });
        console.log('Saved verified_user_pill_en.png');
    }

    // Click to open dropdown and verify rotation
    await page.click('#userPill');
    await page.waitForTimeout(300);

    if (userPill) {
        await userPill.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/verified_user_pill_open.png' });
        console.log('Saved verified_user_pill_open.png');
    }

    // Switch back to Khmer and screenshot entire topbar right
    await page.evaluate(() => {
        if (window.I18nManager) window.I18nManager.setLang('km');
        if (window.PosApp) window.PosApp.updateUserHeaderInfo();
    });
    await page.click('#userPill'); // close dropdown
    await page.waitForTimeout(300);

    // Capture the entire header right
    const headerRight = await page.$('.header-right');
    if (headerRight) {
        await headerRight.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/verified_header_right.png' });
        console.log('Saved verified_header_right.png');
    }

    await browser.close();
})();
