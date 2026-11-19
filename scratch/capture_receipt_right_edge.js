const { chromium } = require('playwright');
const path = require('path');

async function testReceiptRightEdge() {
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

    // 1. Visit Receipt Tab directly
    console.log("Navigating to settings (tab=receipt)...");
    await page.goto('http://localhost/Car-WashService/index.php?view=settings&tab=receipt');
    await page.waitForTimeout(600);

    console.log("Capturing tab_receipt_right_edge_preview.png...");
    await page.screenshot({
        path: path.join(brainDir, 'tab_receipt_right_edge_preview.png'),
        fullPage: false
    });

    // 2. Test live typing into footer
    console.log("Typing custom footer message...");
    await page.fill('textarea[name="receipt_footer"]', 'សូមអរគុណដែលបានជ្រើសរើស AquaGleam! ជួបគ្នាលើកក្រោយ!');
    await page.waitForTimeout(400);

    await page.screenshot({
        path: path.join(brainDir, 'tab_receipt_right_edge_footer_typed.png'),
        fullPage: false
    });

    // 3. Test Dark Mode
    console.log("Testing dark mode...");
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'dark');
        localStorage.setItem('carwash_theme', 'dark');
    });
    await page.waitForTimeout(400);

    await page.screenshot({
        path: path.join(brainDir, 'tab_receipt_right_edge_dark.png'),
        fullPage: false
    });

    console.log("All screenshots captured successfully!");
    await browser.close();
}

testReceiptRightEdge().catch(console.error);
