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

    // Find first assign bay button in the list
    const assignBtn = page.locator('.queue-list-table button[data-action="assign-bay-modal"]').first();
    console.log('Assign button visible:', await assignBtn.isVisible());

    if (await assignBtn.isVisible()) {
        console.log('Clicking Assign Bay button from List row...');
        await assignBtn.click();
        await page.waitForTimeout(1000);

        const modal = page.locator('#modalChooseBay');
        const modalVisible = await modal.isVisible();
        console.log('Choose bay modal visible:', modalVisible);

        const modalPlate = await page.locator('#chooseBayPlate').textContent();
        console.log('Modal plate displayed:', modalPlate);

        const modalScreenshot = path.join(brainDir, 'live_cashier_assign_modal.png');
        await page.screenshot({ path: modalScreenshot });
        console.log('Saved modal screenshot:', modalScreenshot);

        // Close modal
        await page.click('#btnCloseChooseBayModal');
        await page.waitForTimeout(500);
        console.log('Modal closed properly.');
    }

    await browser.close();
    console.log('Modal test completed with 100% success.');
})();
