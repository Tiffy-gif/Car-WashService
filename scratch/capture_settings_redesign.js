const { chromium } = require('playwright');
const path = require('path');

async function testSettingsRedesign() {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    // Match standard desktop viewport similar to user's screenshot
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

    console.log("Navigating to System Settings (default Shop Info)...");
    await page.goto('http://localhost/Car-WashService/index.php?view=settings');
    await page.waitForTimeout(1000);

    const brainDir = 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003';

    // 1. Capture the redesigned System Settings Shop Info page
    console.log("Capturing settings_shop_info_redesign.png...");
    await page.screenshot({
        path: path.join(brainDir, 'settings_shop_info_redesign.png'),
        fullPage: false
    });

    // 2. Test typing into inputs to test live receipt preview
    console.log("Testing live preview reactivity...");
    await page.fill('#shopNameKmInput', 'សេវាកម្មលាងរថយន្តទំនើប VIP');
    await page.fill('#shopNameEnInput', 'AquaGleam Super Detailing');
    await page.fill('#shopAddressInput', 'Street 271, Mean Chey, Phnom Penh');
    await page.fill('#shopPhoneInput', '012 888 999 / 098 111 222');
    await page.waitForTimeout(500);

    await page.screenshot({
        path: path.join(brainDir, 'settings_live_receipt_reactive.png'),
        fullPage: false
    });

    // 3. Test clicking another tab from sidebar (e.g., Upsell & Offers)
    console.log("Clicking 'Upsell & Offers' in the sidebar...");
    const upsellNav = await page.$('.settings-nav-item[data-tab="upsell"]');
    if (upsellNav) {
        await upsellNav.click();
        await page.waitForTimeout(600);
        await page.screenshot({
            path: path.join(brainDir, 'settings_tab_upsell_view.png'),
            fullPage: false
        });
    }

    // 4. Return to Shop Info
    console.log("Returning to Shop Info...");
    const shopNav = await page.$('.settings-nav-item[data-tab="shop"]');
    if (shopNav) {
        await shopNav.click();
        await page.waitForTimeout(600);
    }

    console.log("All screenshots captured successfully!");
    await browser.close();
}

testSettingsRedesign().catch(err => {
    console.error("Test error:", err);
    process.exit(1);
});
