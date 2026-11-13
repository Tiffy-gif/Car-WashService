const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 960 } });
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

    const shotLight = path.join(brainDir, 'queue_modern_clean_light.png');
    await page.screenshot({ path: shotLight });
    console.log('Saved queue_modern_clean_light.png:', shotLight);

    console.log('3. Testing English View...');
    await page.evaluate(() => {
        I18nManager.setLang('en');
        PosApp.renderJobQueue();
        if (PosApp.currentBaysData) {
            PosApp.renderCashierBays(
                PosApp.currentBaysData.bays || [],
                PosApp.currentBaysData.wash_queue || [],
                PosApp.currentBaysData.technical_queue || []
            );
        }
    });
    await page.waitForTimeout(1000);

    const shotEnglish = path.join(brainDir, 'queue_modern_clean_english.png');
    await page.screenshot({ path: shotEnglish });
    console.log('Saved queue_modern_clean_english.png:', shotEnglish);

    console.log('4. Testing Dark Mode...');
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'dark');
        document.body.setAttribute('data-theme', 'dark');
        localStorage.setItem('pos_theme', 'dark');
        I18nManager.setLang('km'); // Return to Khmer default
        PosApp.renderJobQueue();
        if (PosApp.currentBaysData) {
            PosApp.renderCashierBays(
                PosApp.currentBaysData.bays || [],
                PosApp.currentBaysData.wash_queue || [],
                PosApp.currentBaysData.technical_queue || []
            );
        }
    });
    await page.waitForTimeout(1000);

    const shotDark = path.join(brainDir, 'queue_modern_clean_dark.png');
    await page.screenshot({ path: shotDark });
    console.log('Saved queue_modern_clean_dark.png:', shotDark);

    // Switch back to light theme for default
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'light');
        document.body.setAttribute('data-theme', 'light');
        localStorage.setItem('pos_theme', 'light');
    });

    console.log('Verification finished successfully!');
    await browser.close();
})();
