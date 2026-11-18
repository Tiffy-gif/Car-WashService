const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();

    await page.goto('http://localhost/Car-WashService/pos/index.html');

    // Configure localStorage for POS device
    await page.evaluate(() => {
        localStorage.setItem('carwash_pos_config', JSON.stringify({
            backofficeUrl: 'http://localhost/Car-WashService',
            deviceUid: 'POS-CAASIER',
            deviceKey: 'my_test_device_key_unique9',
            exchangeRate: 4100
        }));
    });

    // Reload page with config
    await page.reload();
    await page.waitForTimeout(1500);

    // If modalSettings is open, close it
    await page.evaluate(() => {
        document.getElementById('modalSettings')?.classList.remove('open');
    });

    // If login needed
    const pinInput = await page.$('#posLoginPin');
    if (pinInput && await pinInput.isVisible()) {
        const userInput = await page.$('#posLoginUsername');
        if (userInput && await userInput.isVisible()) {
            await page.fill('#posLoginUsername', 'visal');
        }
        await page.fill('#posLoginPin', '123456');
        await page.click('#btnPosLoginSubmit');
        await page.waitForTimeout(2000);
    }

    // Refresh catalog from back office
    await page.evaluate(async () => {
        if (window.PosApp && typeof PosApp.syncCatalog === 'function') {
            await PosApp.syncCatalog(true);
        }
    });
    await page.waitForTimeout(1500);

    // Take screenshot showing categories on sidebar
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_test_category_verified.png' });
    console.log('Saved pos_test_category_verified.png');

    // Click on Test category on sidebar
    await page.evaluate(() => {
        const testBtn = Array.from(document.querySelectorAll('.cat-btn, [data-category]')).find(el => 
            el.innerText.includes('Test') || el.innerText.includes('សាកល្បង') || el.getAttribute('data-category') === 'test' || el.getAttribute('data-category-id') === '639'
        );
        if (testBtn) testBtn.click();
    });
    await page.waitForTimeout(1000);

    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_test_category_items_grid.png' });
    console.log('Saved pos_test_category_items_grid.png');

    await browser.close();
})();
