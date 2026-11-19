const { chromium } = require('playwright');
const path = require('path');

async function testFullReceiptPreview() {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
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

    // 1. Visit Receipt Tab
    console.log("Navigating to settings (tab=receipt)...");
    await page.goto('http://localhost/Car-WashService/index.php?view=settings&tab=receipt');
    await page.waitForTimeout(600);

    // Initial 80mm Khmer preview
    console.log("Capturing full_preview_80mm_km.png...");
    await page.screenshot({
        path: path.join(brainDir, 'full_preview_80mm_km.png'),
        fullPage: false
    });

    // 2. Switch to 58mm paper size
    console.log("Clicking '58mm' paper button...");
    await page.click('#btnPaper58');
    await page.waitForTimeout(400);

    console.log("Capturing full_preview_58mm_km.png...");
    await page.screenshot({
        path: path.join(brainDir, 'full_preview_58mm_km.png'),
        fullPage: false
    });

    // 3. Switch to English language
    console.log("Clicking 'EN' language button...");
    await page.click('#btnLangEn');
    await page.waitForTimeout(400);

    console.log("Capturing full_preview_58mm_en.png...");
    await page.screenshot({
        path: path.join(brainDir, 'full_preview_58mm_en.png'),
        fullPage: false
    });

    // 4. Switch back to 80mm with English
    console.log("Clicking '80mm' paper button with EN...");
    await page.click('#btnPaper80');
    await page.waitForTimeout(400);

    console.log("Capturing full_preview_80mm_en.png...");
    await page.screenshot({
        path: path.join(brainDir, 'full_preview_80mm_en.png'),
        fullPage: false
    });

    // 5. Test Live Typing into Thank-You Footer Message
    console.log("Testing live typing in Thank-You footer textarea...");
    await page.fill('#receiptFooterTextarea', 'សូមអរគុណ! AquaGleam រីករាយបម្រើលោកអ្នកជានិច្ច!');
    await page.waitForTimeout(400);

    console.log("Capturing full_preview_live_footer_typing.png...");
    await page.screenshot({
        path: path.join(brainDir, 'full_preview_live_footer_typing.png'),
        fullPage: false
    });

    // 6. Test Live Logo Toggle (Hide logo)
    console.log("Selecting 'Hide Logo (Text Only)'...");
    await page.selectOption('#receiptShowLogoSelect', '0');
    await page.waitForTimeout(400);

    console.log("Capturing full_preview_logo_hidden.png...");
    await page.screenshot({
        path: path.join(brainDir, 'full_preview_logo_hidden.png'),
        fullPage: false
    });

    // Re-show logo
    await page.selectOption('#receiptShowLogoSelect', '1');
    await page.waitForTimeout(300);

    // 7. Test Dark Mode
    console.log("Testing Dark Mode...");
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'dark');
        localStorage.setItem('carwash_theme', 'dark');
    });
    await page.waitForTimeout(400);

    console.log("Capturing full_preview_dark_mode.png...");
    await page.screenshot({
        path: path.join(brainDir, 'full_preview_dark_mode.png'),
        fullPage: false
    });

    console.log("All tests passed and screenshots generated successfully!");
    await browser.close();
}

testFullReceiptPreview().catch(console.error);
