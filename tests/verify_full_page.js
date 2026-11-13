const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1050 } });
    const page = await context.newPage();

    console.log('1. Logging in as cashier visal...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'visal');
    await page.fill('#pin', '123456');
    await page.click('button[type="submit"]');
    await page.waitForNavigation();
    await page.waitForTimeout(1000);

    console.log('2. Navigating to Queue Screen in Light Mode...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#queue');
    await page.waitForTimeout(2000);

    // Full page screenshot
    const shotFullLight = path.join(brainDir, 'queue_modern_clean_full_light.png');
    await page.screenshot({ path: shotFullLight, fullPage: true });
    console.log('Saved queue_modern_clean_full_light.png:', shotFullLight);

    // Switch to dark mode
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'dark');
        document.body.setAttribute('data-theme', 'dark');
        localStorage.setItem('pos_theme', 'dark');
    });
    await page.waitForTimeout(1000);

    const shotFullDark = path.join(brainDir, 'queue_modern_clean_full_dark.png');
    await page.screenshot({ path: shotFullDark, fullPage: true });
    console.log('Saved queue_modern_clean_full_dark.png:', shotFullDark);

    await browser.close();
})();

