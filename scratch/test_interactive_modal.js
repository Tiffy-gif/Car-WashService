const { chromium } = require('playwright');
const path = require('path');

async function testInteractiveModal() {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({
        viewport: { width: 1440, height: 900 }
    });
    const page = await context.newPage();

    await page.goto('http://localhost/Car-WashService/index.php?view=login');
    await page.fill('input[name="username"]', 'owner');
    await page.fill('input[name="pin"]', '123456');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}),
        page.click('button[type="submit"]')
    ]);

    await page.goto('http://localhost/Car-WashService/index.php?view=settings&tab=upsell');
    await page.waitForTimeout(1000);

    // Open create modal
    await page.click('button:has-text("Create Promotion Campaign")');
    await page.waitForTimeout(500);

    // Type new rule values
    await page.fill('#upsell_name', 'Basic Wash to Premium Deluxe Upgrade');
    
    // Select Tier Upgrade
    await page.click('#label_upgrade');
    await page.waitForTimeout(300);

    // Set promo price to 12.00
    await page.fill('#upsell_offer_price_usd', '12.00');

    // Switch preview to English
    await page.evaluate(() => {
        if (typeof window.setPreviewLang === 'function') {
            window.setPreviewLang('en');
        }
    });
    await page.waitForTimeout(300);

    const brainDir = 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003';
    await page.screenshot({
        path: path.join(brainDir, 'backoffice_modal_upgrade_live_reactive.png'),
        fullPage: false
    });

    await browser.close();
    console.log("Interactive modal screenshot captured!");
}

testInteractiveModal().catch(err => {
    console.error(err);
    process.exit(1);
});
