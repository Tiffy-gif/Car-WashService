const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 960 } });

    console.log('1. Logging in as cashier visal...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'visal');
    await page.fill('#pin', '123456');
    await page.click('button[type="submit"]');
    await page.waitForNavigation();
    await page.waitForTimeout(1000);

    console.log('2. Navigating to Queue Screen in English...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#queue');
    await page.waitForTimeout(2000);

    // Switch to English and click Ready to Pick Up tab
    await page.evaluate(() => {
        I18nManager.setLang('en');
        PosApp.renderJobQueue();
        const readyTab = document.querySelector('.btn-queue-tab[data-tab="ready"]');
        if (readyTab) readyTab.click();
    });
    await page.waitForTimeout(1000);

    // Verify Ready for Pickup badges
    const badges = page.locator('.queue-badge.badge-ready-pickup, .queue-badge.finished');
    const badgeCount = await badges.count();
    console.log(`Found ${badgeCount} Ready for Pickup badges.`);

    for (let i = 0; i < Math.min(badgeCount, 3); i++) {
        const b = badges.nth(i);
        const box = await b.boundingBox();
        const text = await b.innerText();
        console.log(`Badge ${i+1}: "${text.trim().replace(/\n/g, ' ')}" - width: ${box.width.toFixed(1)}px, height: ${box.height.toFixed(1)}px`);
    }

    // Capture Light Mode English Screenshot
    const shotLight = path.join(brainDir, 'queue_ready_pickup_onerow_light.png');
    await page.screenshot({ path: shotLight });
    console.log('Saved queue_ready_pickup_onerow_light.png');

    // Capture Zoomed In Table Screenshot
    const tableEl = page.locator('.queue-table-card-container');
    if (await tableEl.count() > 0) {
        const shotTable = path.join(brainDir, 'queue_ready_pickup_onerow_table_zoom.png');
        await tableEl.screenshot({ path: shotTable });
        console.log('Saved queue_ready_pickup_onerow_table_zoom.png');
    }

    // Capture Dark Mode
    console.log('3. Testing Dark Mode...');
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'dark');
        document.body.setAttribute('data-theme', 'dark');
        localStorage.setItem('pos_theme', 'dark');
        PosApp.renderJobQueue();
    });
    await page.waitForTimeout(1000);

    const shotDark = path.join(brainDir, 'queue_ready_pickup_onerow_dark.png');
    await page.screenshot({ path: shotDark });
    console.log('Saved queue_ready_pickup_onerow_dark.png');

    // Reset back to light
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'light');
        document.body.setAttribute('data-theme', 'light');
        localStorage.setItem('pos_theme', 'light');
        I18nManager.setLang('km');
    });

    console.log('Verification completed!');
    await browser.close();
})();

