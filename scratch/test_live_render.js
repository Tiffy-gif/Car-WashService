const { chromium } = require('playwright');

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

    // Fill username and PIN
    await page.fill('#staffLoginUsername, input[name="username"], input[type="text"]', 'visal');
    await page.fill('#staffLoginPin, input[name="pin"], input[type="password"]', '123456');
    await page.click('button:has-text("Sign In with PIN"), #btnStaffLogin');
    await page.waitForTimeout(1500);

    // Now screenshot the top right header where userPill is located
    const pill = await page.$('#userPill');
    if (pill) {
        await pill.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/live_logged_in_pill.png' });
        console.log('Saved live_logged_in_pill.png');
    }

    const header = await page.$('.header-right, .top-bar-right, .header');
    if (header) {
        await header.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/live_header_right.png' });
        console.log('Saved live_header_right.png');
    }

    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/live_pos_screen.png' });
    console.log('Saved live_pos_screen.png');

    await browser.close();
})();
