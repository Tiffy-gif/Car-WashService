const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage();

    // Login as owner
    await page.goto('http://localhost/Car-WashService/index.php?view=login');
    await page.fill('#username', 'owner');
    await page.fill('#pin', '123456');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1000);

    // Go to services page with cat=test
    await page.goto('http://localhost/Car-WashService/index.php?view=services&cat=test');
    await page.waitForTimeout(1000);

    // Click + Add New Service
    await page.click('button:has-text("Add New Service")');
    await page.waitForTimeout(500);

    // Fill form
    await page.fill('#name_en', 'Premium Test Wash');
    await page.fill('#name_km', 'លាងសាកល្បងពិសេស');

    // Select category Test
    await page.click('#categoryTrigger');
    await page.waitForTimeout(300);
    await page.click('.cat-choice-item[data-value="test"]');
    await page.waitForTimeout(300);

    console.log('Values before submit:', await page.evaluate(() => ({
        category: document.getElementById('category')?.value,
        category_id: document.getElementById('category_id')?.value,
        label: document.getElementById('categoryLabelText')?.innerText
    })));

    // Submit form
    await page.click('#serviceModal button[type="submit"]');
    await page.waitForTimeout(2000);

    console.log('Current URL after submit:', page.url());

    // Check count on Test category pill
    const counts = await page.evaluate(() => {
        const testPill = document.querySelector('a[href*="cat=test"]');
        const rows = document.querySelectorAll('tbody tr');
        return {
            testPillText: testPill?.innerText,
            tableRowCount: rows.length,
            tableFirstRowName: rows[0]?.querySelector('strong')?.innerText
        };
    });
    console.log('After submit test category state:', counts);

    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/service_added_test.png' });

    await browser.close();
})();
