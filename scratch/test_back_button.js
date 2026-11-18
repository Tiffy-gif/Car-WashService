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
            defaultLanguage: 'en',
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
    await page.waitForTimeout(500);

    // Click Back to Register button
    await page.click('#btnQueueBackToPos');
    await page.waitForTimeout(500);

    // Check current view
    const currentView = await page.evaluate(() => window.PosApp ? window.PosApp.currentView : null);
    const viewPosDisplay = await page.evaluate(() => {
        const vp = document.getElementById('viewPos');
        return vp ? window.getComputedStyle(vp).display : null;
    });

    console.log('Current View after Back click:', currentView, 'viewPos display:', viewPosDisplay);
    await browser.close();
})();
