const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();

    console.log('Logging in as owner...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'owner');
    await page.fill('#pin', '123456');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);

    console.log('Navigating to index.php?view=pos_devices...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=pos_devices');
    await page.waitForTimeout(1500);

    const title = await page.title();
    console.log('Title:', title);
    await page.screenshot({ path: path.join(brainDir, 'backoffice_pos_devices_owner.png') });

    // Open add device modal
    console.log('Opening add device modal...');
    const btn = await page.$('button[onclick*="addDeviceModal"]');
    if (btn) {
        await btn.click();
        await page.waitForTimeout(600);
        await page.screenshot({ path: path.join(brainDir, 'backoffice_add_device_modal_open.png') });
    }

    await browser.close();
    console.log('Done!');
})();
