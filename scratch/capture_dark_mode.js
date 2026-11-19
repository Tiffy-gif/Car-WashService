const { chromium } = require('playwright');
const path = require('path');

async function testDarkMode() {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1280, height: 980 } });
    const page = await context.newPage();

    await page.goto('http://localhost/Car-WashService/index.php?view=login');
    await page.fill('input[name="username"]', 'owner');
    await page.fill('input[name="pin"]', '123456');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}),
        page.click('button[type="submit"]')
    ]);

    await page.goto('http://localhost/Car-WashService/index.php?view=settings');
    await page.waitForTimeout(500);

    // Toggle dark mode
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'dark');
        localStorage.setItem('carwash_theme', 'dark');
    });
    await page.waitForTimeout(500);

    const brainDir = 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003';
    await page.screenshot({
        path: path.join(brainDir, 'settings_shop_info_dark.png'),
        fullPage: false
    });

    console.log("Dark mode screenshot captured!");
    await browser.close();
}

testDarkMode().catch(console.error);
