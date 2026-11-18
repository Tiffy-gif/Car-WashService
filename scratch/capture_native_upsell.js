const { chromium } = require('playwright');
const path = require('path');

async function run() {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({
        viewport: { width: 1440, height: 900 }
    });
    const page = await context.newPage();

    console.log("Navigating to login page...");
    await page.goto('http://localhost/Car-WashService/index.php?view=login');

    // Login as owner
    await page.fill('input[name="username"]', 'owner');
    await page.fill('input[name="pin"]', '123456');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {}),
        page.click('button[type="submit"]')
    ]);
    await page.waitForTimeout(500);

    console.log("Navigating to settings -> tab=upsell...");
    await page.goto('http://localhost/Car-WashService/index.php?view=settings&tab=upsell');
    await page.waitForTimeout(1000);

    // Ensure tab_upsell is active
    await page.evaluate(() => {
        const tab = document.getElementById('tab_upsell');
        if (tab) tab.style.display = 'block';
    });

    const brainDir = 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003';

    // 1. Capture main tab view
    console.log("Capturing redesigned native UI tab...");
    await page.screenshot({
        path: path.join(brainDir, 'backoffice_tab_upsell_native.png'),
        fullPage: false
    });

    // 2. Open Edit Modal for Rule 25
    console.log("Opening edit modal...");
    const editBtn = await page.$('table tbody tr:first-child button[title="Edit Rule"]');
    if (editBtn) {
        await editBtn.click();
    } else {
        await page.click('button:has-text("Add Promotion Rule")');
    }
    await page.waitForTimeout(600);

    // Capture modal
    console.log("Capturing native modal...");
    await page.screenshot({
        path: path.join(brainDir, 'backoffice_tab_upsell_modal.png'),
        fullPage: false
    });

    // Switch preview language to English in modal
    await page.evaluate(() => {
        if (typeof window.setPreviewLang === 'function') {
            window.setPreviewLang('en');
        }
    });
    await page.waitForTimeout(300);

    console.log("Capturing native modal with English preview...");
    await page.screenshot({
        path: path.join(brainDir, 'backoffice_tab_upsell_modal_en.png'),
        fullPage: false
    });

    await browser.close();
    console.log("Captured all native UI screenshots successfully!");
}

run().catch(err => {
    console.error("Error:", err);
    process.exit(1);
});
