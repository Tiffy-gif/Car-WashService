const { chromium } = require('playwright');

async function inspectColumns() {
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

    const cols = await page.evaluate(() => {
        const ths = Array.from(document.querySelectorAll('#tab_upsell table thead th'));
        const row1 = Array.from(document.querySelectorAll('#tab_upsell table tbody tr:first-child td'));
        return ths.map((th, i) => ({
            header: th.innerText,
            thWidth: th.getBoundingClientRect().width,
            tdWidth: row1[i] ? row1[i].getBoundingClientRect().width : 0,
            tdText: row1[i] ? row1[i].innerText.replace(/\n/g, ' ') : ''
        }));
    });

    console.log("Columns:", JSON.stringify(cols, null, 2));
    await browser.close();
}

inspectColumns();
