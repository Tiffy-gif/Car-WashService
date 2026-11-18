const { chromium } = require('playwright');
const path = require('path');

(async () => {
    const browser = await chromium.launch({
        channel: 'chrome',
        headless: true
    });
    const context = await browser.newContext({
        viewport: { width: 1440, height: 900 }
    });
    const page = await context.newPage();

    // Set auth in localStorage
    await page.goto('http://localhost/Car-WashService/pos/index.html');
    await page.evaluate(() => {
        localStorage.setItem('pos_device_uid', 'POS-CAASIER');
        localStorage.setItem('pos_device_key', 'my_test_device_key_unique9');
        localStorage.setItem('pos_device_name', 'Main Cashier Terminal');
    });

    await page.goto('http://localhost/Car-WashService/pos/index.html#washer');
    await page.waitForTimeout(1500);

    // If lock screen is open, unlock it
    const isLocked = await page.evaluate(() => {
        const lock = document.getElementById('lockScreen');
        return lock && lock.style.display !== 'none' && !lock.classList.contains('hidden');
    });

    if (isLocked) {
        console.log('Unlocking with PIN...');
        await page.evaluate(() => {
            if (window.PosAuth) {
                window.PosAuth.activeStaff = { id: 1, name: 'visal', role: 'cashier' };
            }
            if (window.PosApp) {
                window.PosApp.currentUser = { id: 1, username: 'visal', full_name: 'Visal Cashier', role: 'cashier' };
            }
            const lock = document.getElementById('lockScreen');
            if (lock) lock.style.display = 'none';
        });
        await page.waitForTimeout(500);
    }

    // Switch to washer view
    await page.evaluate(() => {
        if (window.PosApp) {
            window.PosApp.switchView('washer');
        }
    });
    await page.waitForTimeout(1500);

    // Capture screenshot of #washer
    const shotPath = path.resolve('C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/washer_current_test.png');
    await page.screenshot({ path: shotPath });
    console.log('Screenshot saved to:', shotPath);

    // Also get bay data and queue data
    const data = await page.evaluate(() => {
        return {
            bays: window.PosApp?.currentBaysData?.bays || [],
            washQueue: window.PosApp?.currentBaysData?.wash_queue || [],
            currentView: window.PosApp?.currentView
        };
    });
    console.log('Data:', JSON.stringify(data, null, 2));

    await browser.close();
})();
