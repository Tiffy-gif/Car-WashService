const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1366, height: 768 } });

    await page.goto('http://localhost/Car-WashService/pos/index.html');
    await page.evaluate(() => {
        localStorage.setItem('pos_device_uid', 'POS-CAASIER');
        localStorage.setItem('pos_device_key', 'my_test_device_key_unique9');
        localStorage.setItem('pos_device_status', 'registered');
    });

    await page.reload();
    await page.waitForTimeout(500);

    // Enter staff PIN 123456 properly
    const keys = ['1', '2', '3', '4', '5', '6'];
    for (const k of keys) {
        const btn = await page.$(`button[data-key="${k}"]`);
        if (btn) await btn.click();
        await page.waitForTimeout(60);
    }
    await page.waitForTimeout(1000);

    // Mock Shop Owner if needed
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

    const pill = await page.$('#userPill');
    if (pill) {
        await pill.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/live_user_pill_visible.png' });
        console.log('Saved live_user_pill_visible.png');
    }

    const topbar = await page.$('.top-bar, .pos-header, header');
    if (topbar) {
        await topbar.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/live_topbar_visible.png' });
        console.log('Saved live_topbar_visible.png');
    }

    await browser.close();
})();
