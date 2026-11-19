const { chromium } = require('playwright');

async function testSubmitShop() {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext();
    const page = await context.newPage();

    await page.goto('http://localhost/Car-WashService/index.php?view=login');
    await page.fill('input[name="username"]', 'owner');
    await page.fill('input[name="pin"]', '123456');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}),
        page.click('button[type="submit"]')
    ]);

    await page.goto('http://localhost/Car-WashService/index.php?view=settings&tab=shop');
    await page.waitForTimeout(500);

    // Save changes
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}),
        page.click('button[type="submit"]:has-text("Save changes")')
    ]);

    const content = await page.content();
    console.log("Has success alert?", content.includes('alert-success') || content.includes('saved') || content.includes('ជោគជ័យ'));

    await browser.close();
}

testSubmitShop().catch(console.error);
