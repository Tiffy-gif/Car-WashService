const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();

    // 1. Login to Back Office
    console.log('Logging in to Back Office...');
    await page.goto('http://localhost/Car-WashService/index.php?view=login');
    await page.fill('#username', 'owner');
    await page.fill('#pin', '123456');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);

    // 2. Go to Services with Test category filter
    console.log('Navigating to Services cat=test...');
    await page.goto('http://localhost/Car-WashService/index.php?view=services&cat=test');
    await page.waitForTimeout(1500);

    // Screenshot of the services table under Test
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/backoffice_test_category_table.png', fullPage: true });
    console.log('Saved backoffice_test_category_table.png');

    // 3. Click Add New Service button to check modal pre-selection
    console.log('Opening Add New Service modal...');
    await page.click('button:has-text("Add New Service")');
    await page.waitForSelector('#serviceModal.open, #serviceModal:visible');
    await page.waitForTimeout(500);

    const selectedCategoryText = await page.innerText('#categoryLabelText');
    const selectedCategoryVal = await page.inputValue('#category');
    const selectedCategoryId = await page.inputValue('#category_id');
    console.log(`Preselected Category: text="${selectedCategoryText}", code="${selectedCategoryVal}", id="${selectedCategoryId}"`);

    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/backoffice_add_service_modal_preselected.png' });
    console.log('Saved backoffice_add_service_modal_preselected.png');

    // 4. Now open POS in another tab to check category display
    console.log('Opening POS...');
    const posPage = await context.newPage();
    await posPage.goto('http://localhost/Car-WashService/pos/index.html');
    await posPage.waitForLoadState('networkidle');
    await posPage.waitForTimeout(2000);

    // If login needed on POS
    const pinInput = await posPage.$('#posLoginPin');
    if (pinInput && await pinInput.isVisible()) {
        console.log('Logging in to POS with cashier PIN...');
        await posPage.fill('#posLoginPin', '123456');
        await posPage.click('#btnPosLoginSubmit');
        await posPage.waitForTimeout(2000);
    }

    await posPage.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_test_category_display.png' });
    console.log('Saved pos_test_category_display.png');

    await browser.close();
    console.log('Verification finished successfully!');
})();
