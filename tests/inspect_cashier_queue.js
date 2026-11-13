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

    const info = await page.evaluate(() => {
        const user = window.PosStorage?.getUser();
        const grid = document.getElementById('queueGrid');
        const bays = document.getElementById('cashierBaysGrid');
        const cards = grid?.querySelectorAll('.queue-card') || [];
        return {
            user,
            baysCount: bays?.children?.length,
            queueCount: cards.length,
            cardPlates: Array.from(cards).map(c => c.querySelector('.queue-card-plate')?.textContent?.trim())
        };
    });
    console.log('CASHIER QUEUE INFO:', JSON.stringify(info, null, 2));

    await page.screenshot({ path: path.join(brainDir, 'current_cashier_queue_page.png') });
    console.log('Saved current_cashier_queue_page.png');

    await browser.close();
})();
