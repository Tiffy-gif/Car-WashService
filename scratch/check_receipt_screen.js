const { chromium } = require('playwright');
const path = require('path');

async function checkReceiptScreen() {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1024, height: 900 } });
    const page = await context.newPage();

    await page.goto('http://localhost/Car-WashService/index.php?view=login');
    await page.fill('input[name="username"]', 'owner');
    await page.fill('input[name="pin"]', '123456');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}),
        page.click('button[type="submit"]')
    ]);

    await page.goto('http://localhost/Car-WashService/index.php?view=receipt&test=1');
    await page.waitForTimeout(600);

    const brainDir = 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003';
    await page.screenshot({
        path: path.join(brainDir, 'actual_test_receipt_print_screen.png'),
        fullPage: false
    });

    console.log("Captured actual_test_receipt_print_screen.png");
    await browser.close();
}

checkReceiptScreen().catch(console.error);
