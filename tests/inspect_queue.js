const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage();
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'visal');
    await page.fill('#pin', '123456');
    await page.click('button[type="submit"]');
    await page.waitForNavigation();
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#queue');
    await page.waitForTimeout(1500);

    const bayCards = await page.evaluate(() => {
        return Array.from(document.querySelectorAll('.bay-card')).map(b => ({
            id: b.getAttribute('data-bay-id'),
            name: b.getAttribute('data-bay-name'),
            isFree: b.getAttribute('data-is-free'),
            classes: b.className
        }));
    });
    console.log('Bay cards on screen:', bayCards);

    const queueRows = await page.evaluate(() => {
        return Array.from(document.querySelectorAll('#queueListTableBody tr')).map(r => ({
            plate: r.getAttribute('data-plate'),
            orderId: r.getAttribute('data-order-id'),
            draggable: r.getAttribute('draggable'),
            classes: r.className
        }));
    });
    console.log('Queue rows in table:', queueRows);

    await browser.close();
})();

