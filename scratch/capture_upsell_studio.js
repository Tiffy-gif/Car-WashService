const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

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

    // 1. Capture the redesigned Upsell Studio main screen
    console.log("Capturing redesigned Upsell Studio main screen...");
    await page.screenshot({
        path: path.join(brainDir, 'backoffice_upsell_studio_redesign.png'),
        fullPage: false
    });

    // 2. Open the modal by clicking "Edit" on Rule 25 or "Create Promotion Campaign"
    console.log("Opening edit modal for live preview inspection...");
    const editBtn = await page.$('.upsell-rule-card button:has-text("Edit")');
    if (editBtn) {
        await editBtn.click();
    } else {
        await page.click('button:has-text("Create Promotion Campaign")');
    }
    await page.waitForTimeout(600);

    // Capture modal with English preview
    console.log("Capturing modal with English preview...");
    await page.evaluate(() => {
        if (typeof window.setPreviewLang === 'function') {
            window.setPreviewLang('en');
        }
    });
    await page.waitForTimeout(400);
    await page.screenshot({
        path: path.join(brainDir, 'backoffice_upsell_modal_preview_en.png'),
        fullPage: false
    });

    // Capture modal with Khmer preview
    console.log("Capturing modal with Khmer preview...");
    await page.evaluate(() => {
        if (typeof window.setPreviewLang === 'function') {
            window.setPreviewLang('km');
        }
    });
    await page.waitForTimeout(400);
    await page.screenshot({
        path: path.join(brainDir, 'backoffice_upsell_modal_preview_km.png'),
        fullPage: false
    });

    await browser.close();
    console.log("Completed capturing all Backoffice UI screenshots successfully!");
}

run().catch(err => {
    console.error("Error running capture script:", err);
    process.exit(1);
});
