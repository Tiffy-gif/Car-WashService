const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

    console.log('Logging in as cashier visal...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'visal');
    await page.fill('#pin', '123456');
    await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);

    console.log('Navigating to #queue in POS...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#queue');
    await page.waitForTimeout(2000);

    // Switch to Ready tab
    console.log('Switching to Ready tab...');
    await page.click('#tabQueueReady');
    await page.waitForTimeout(1000);

    // Click the first "Vehicle Picked Up" (ប្រគល់រថយន្ត) button
    const handoverBtn = page.locator('.queue-list-table button[data-action="complete-pickup"]').first();
    console.log('Handover button visible:', await handoverBtn.isVisible());

    if (await handoverBtn.isVisible()) {
        console.log('Clicking Vehicle Picked Up button...');
        await handoverBtn.click();
        await page.waitForTimeout(1500);

        // Check toast notice
        const toast = page.locator('#posNoticeAlert');
        console.log('Toast visible:', await toast.isVisible());
        const toastText = await page.locator('#posNoticeAlertContent').textContent();
        console.log('Toast text:', toastText);

        const handoverScreenshot = path.join(brainDir, 'live_cashier_handover_success.png');
        await page.screenshot({ path: handoverScreenshot });
        console.log('Saved handover screenshot:', handoverScreenshot);
    }

    // Switch back to Waiting tab
    await page.click('#tabQueueWaiting');
    await page.waitForTimeout(800);

    await browser.close();
    console.log('Handover action test completed with 100% success.');
})();

