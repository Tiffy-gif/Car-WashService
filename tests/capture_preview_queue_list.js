const { chromium } = require('playwright');
const path = require('path');
const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

    await page.goto('http://127.0.0.1/Car-WashService/pos/preview_queue_list.html');
    await page.waitForTimeout(500);

    await page.screenshot({ path: path.join(brainDir, 'preview_queue_list_table.png') });
    console.log('Saved preview_queue_list_table.png');
    await browser.close();
})();

