const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 960 } });
    const page = await context.newPage();

    // 1. Login as owner
    await page.goto('http://localhost/Car-WashService/index.php?view=login');
    await page.waitForTimeout(500);

    // Fill username 'owner' and pin '123456'
    await page.fill('input[name="username"]', 'owner');
    await page.fill('input[name="pin"]', '123456');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);

    // 2. Navigate to settings & tab=upsell
    await page.goto('http://localhost/Car-WashService/index.php?view=settings&tab=upsell');
    await page.waitForTimeout(1000);

    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/backoffice_upsell_settings.png', fullPage: true });
    console.log('Backoffice upsell screenshot taken!');

    // 3. Test opening the modal
    const addBtn = page.locator('button:has-text("Add Promotion Rule")');
    if (await addBtn.count() > 0) {
        await addBtn.first().click();
        await page.waitForTimeout(500);
        await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/backoffice_upsell_modal.png', fullPage: false });
        console.log('Backoffice upsell modal screenshot taken!');
    }

    await browser.close();
})();
