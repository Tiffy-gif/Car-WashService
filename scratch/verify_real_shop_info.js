const { chromium } = require('playwright');
const path = require('path');

async function verifyRealShopInfo() {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1360, height: 960 } });
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

    // 1. Visit Receipt Tab in Settings
    console.log("Navigating to settings (tab=receipt)...");
    await page.goto('http://localhost/Car-WashService/index.php?view=settings&tab=receipt');
    await page.waitForTimeout(600);

    const addrText = await page.textContent('#receiptPreviewAddress');
    const phoneText = await page.textContent('#receiptPreviewPhone');
    console.log("Preview Address:", addrText.trim());
    console.log("Preview Phone:", phoneText.trim());

    await page.screenshot({
        path: path.join(brainDir, 'verified_receipt_tab_real_info.png'),
        fullPage: false
    });

    // 2. Visit Test Receipt (80mm) Print screen
    console.log("Navigating to actual 80mm test receipt screen...");
    await page.goto('http://localhost/Car-WashService/index.php?view=receipt&test=1');
    await page.waitForTimeout(600);

    await page.screenshot({
        path: path.join(brainDir, 'verified_actual_80mm_receipt_screen.png'),
        fullPage: false
    });

    console.log("Both screenshots captured successfully!");
    await browser.close();
}

verifyRealShopInfo().catch(console.error);
