const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'dona');
    await page.fill('#pin', '123456');
    await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);

    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#mechanic');
    await page.waitForTimeout(1500);

    // Capture Left Column
    const liftCol = await page.$('#mechanicLiftColumn');
    if (liftCol) {
        await liftCol.screenshot({ path: path.join(brainDir, 'debug_lift_col.png') });
    }

    // Capture Right Column Queue
    const rightCol = await page.$('#mechanicRightColumn');
    if (rightCol) {
        await rightCol.screenshot({ path: path.join(brainDir, 'debug_right_queue.png') });
    }

    // Switch to Safety Inspection and capture Right Column
    await page.click('#tabBtnInspection');
    await page.waitForTimeout(500);
    if (rightCol) {
        await rightCol.screenshot({ path: path.join(brainDir, 'debug_right_inspection.png') });
    }

    await browser.close();
    console.log('Saved debug screenshots!');
})();

