const { chromium } = require('playwright');
const path = require('path');
const { execSync } = require('child_process');

(async () => {
    // 1. Create active device in DB
    const newDevOutput = execSync('php tests/create_test_device.php').toString();
    const newDev = JSON.parse(newDevOutput);

    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext();
    const page = await context.newPage();

    // Login to back office first
    await page.goto('http://localhost/Car-WashService/index.php?view=login');
    await page.fill('#username', 'owner');
    await page.fill('#pin', '123456');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(2000);

    // Navigate to POS (same browser session)
    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2500);

    // Take screenshot of POS properly requiring Device Key even when logged into Back Office!
    const ssPath = path.resolve(__dirname, '../tests/pos_device_key_strictly_required.png');
    await page.screenshot({ path: ssPath, fullPage: true });
    console.log('Saved screenshot to:', ssPath);

    // Clean up
    execSync('php tests/delete_test_device.php');
    await browser.close();
})();
