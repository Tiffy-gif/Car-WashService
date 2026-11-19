const { chromium } = require('playwright');
const path = require('path');

async function testRevivedComponent() {
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

    console.log("Navigating to settings -> tab=upsell...");
    await page.goto('http://localhost/Car-WashService/index.php?view=settings&tab=upsell');
    await page.waitForTimeout(1000);

    const brainDir = 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003';

    // 1. Capture revived component in settings
    console.log("Capturing revived component in settings...");
    await page.screenshot({
        path: path.join(brainDir, 'revived_component_settings.png'),
        fullPage: false
    });

    // 2. Open edit modal on Rule 25
    console.log("Opening edit modal...");
    const editBtn = await page.$('.upsell-rule-card button:has-text("Edit")');
    if (editBtn) {
        await editBtn.click();
    } else {
        await page.click('button:has-text("Create Promotion Campaign")');
    }
    await page.waitForTimeout(600);

    // Capture modal (Khmer preview)
    console.log("Capturing modal (Khmer preview)...");
    await page.screenshot({
        path: path.join(brainDir, 'revived_component_modal.png'),
        fullPage: false
    });

    // Switch to English preview
    console.log("Switching to English preview...");
    await page.evaluate(() => {
        if (typeof window.setPreviewLang === 'function') {
            window.setPreviewLang('en');
        }
    });
    await page.waitForTimeout(300);

    // Capture modal (English preview)
    console.log("Capturing modal (English preview)...");
    await page.screenshot({
        path: path.join(brainDir, 'revived_component_modal_en.png'),
        fullPage: false
    });

    await browser.close();
    console.log("Revived component tested and captured successfully!");
}

testRevivedComponent().catch(err => {
    console.error(err);
    process.exit(1);
});
