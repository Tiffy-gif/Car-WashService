const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

    console.log('Logging in...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'dona');
    await page.fill('#pin', '123456');
    await Promise.all([
        page.waitForNavigation(),
        page.click('button[type="submit"]')
    ]);

    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#mechanic');
    await page.waitForTimeout(2000);

    // Switch to Safety Inspection
    await page.click('#tabBtnInspection');
    await page.waitForTimeout(500);

    // Click Pass All Inspection Points
    console.log('Clicking PASS ALL INSPECTION POINTS...');
    await page.click('.btn-pass-all');
    await page.waitForTimeout(2000);

    const badgeState = await page.$eval('#inspectBadge', el => el.textContent.trim());
    console.log('Inspection Badge after Pass All:', badgeState);

    await page.screenshot({ path: path.join(brainDir, 'live_pos_mechanic_all_passed.png') });
    console.log('Saved live_pos_mechanic_all_passed.png');

    await browser.close();
})();

