const { chromium } = require('playwright');
const path = require('path');

async function testOption2() {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1280, height: 980 } });
    const page = await context.newPage();

    console.log("Logging in as owner...");
    await page.goto('http://localhost/Car-WashService/index.php?view=login');
    await page.fill('input[name="username"]', 'owner');
    await page.fill('input[name="pin"]', '123456');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}),
        page.click('button[type="submit"]')
    ]);

    const brainDir = 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003';

    // 1. Visit Shop Info
    console.log("Navigating to settings (Shop Info default)...");
    await page.goto('http://localhost/Car-WashService/index.php?view=settings');
    await page.waitForTimeout(500);

    const isVisibleOnShop = await page.isVisible('#settingsReceiptPreview');
    console.log("Is receipt preview visible on Shop Info?", isVisibleOnShop);

    await page.screenshot({
        path: path.join(brainDir, 'option2_shop_info_hidden_preview.png'),
        fullPage: false
    });

    // 2. Click 'Receipt' tab in sidebar
    console.log("Clicking 'Receipt' in sidebar...");
    await page.click('.settings-nav-item[data-tab="receipt"]');
    await page.waitForTimeout(500);

    const isVisibleOnReceipt = await page.isVisible('#settingsReceiptPreview');
    console.log("Is receipt preview visible on Receipt tab?", isVisibleOnReceipt);

    await page.screenshot({
        path: path.join(brainDir, 'option2_receipt_tab_showing_preview.png'),
        fullPage: false
    });

    // 3. Click back to 'Shop Info'
    console.log("Clicking back to 'Shop Info' in sidebar...");
    await page.click('.settings-nav-item[data-tab="shop"]');
    await page.waitForTimeout(500);
    const isVisibleOnShopReturn = await page.isVisible('#settingsReceiptPreview');
    console.log("Is receipt preview visible after returning to Shop Info?", isVisibleOnShopReturn);

    console.log("Option 2 test completed successfully!");
    await browser.close();
}

testOption2().catch(console.error);
