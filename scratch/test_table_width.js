const { chromium } = require('playwright');

async function testWidth() {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();

    await page.goto('http://localhost/Car-WashService/index.php?view=login');
    await page.fill('input[name="username"]', 'owner');
    await page.fill('input[name="pin"]', '123456');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}),
        page.click('button[type="submit"]')
    ]);

    await page.goto('http://localhost/Car-WashService/index.php?view=settings&tab=upsell');
    await page.waitForTimeout(500);

    const dims = await page.evaluate(() => {
        const table = document.querySelector('#tab_upsell table');
        const container = document.querySelector('#tab_upsell .table-responsive');
        const card = container ? container.closest('.card') : null;
        return {
            tableScrollWidth: table ? table.scrollWidth : 0,
            tableClientWidth: table ? table.clientWidth : 0,
            containerScrollWidth: container ? container.scrollWidth : 0,
            containerClientWidth: container ? container.clientWidth : 0,
            cardWidth: card ? card.clientWidth : 0
        };
    });

    console.log("Dimensions:", dims);
    await browser.close();
}

testWidth();
