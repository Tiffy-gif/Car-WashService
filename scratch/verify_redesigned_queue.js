const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1366, height: 768 } });

    await page.goto('http://localhost/Car-WashService/pos/index.html#queue');
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

    // Login staff
    await page.fill('#staffLoginUsername, input[name="username"], input[type="text"]', 'visal');
    await page.fill('#staffLoginPin, input[name="pin"], input[type="password"]', '123456');
    await page.click('button:has-text("Sign In with PIN"), #btnStaffLogin');
    await page.waitForTimeout(1500);

    // Switch to queue
    await page.evaluate(() => {
        if (window.PosApp) window.PosApp.switchView('queue');
    });
    await page.waitForTimeout(800);

    // Capture redesigned Queue screen (Khmer)
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/redesigned_queue_km.png' });
    console.log('Saved redesigned_queue_km.png');

    // Switch to English and capture
    await page.evaluate(() => {
        if (window.I18nManager) window.I18nManager.setLang('en');
        if (window.PosApp) {
            window.PosApp.switchView('queue');
            window.PosApp.updateUserHeaderInfo();
        }
    });
    await page.waitForTimeout(500);

    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/redesigned_queue_en.png' });
    console.log('Saved redesigned_queue_en.png');

    // Test real-time search in queue
    await page.fill('#queueSearchInput', '2F-4433');
    await page.waitForTimeout(300);
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/redesigned_queue_search.png' });
    console.log('Saved redesigned_queue_search.png');

    await browser.close();
})();
