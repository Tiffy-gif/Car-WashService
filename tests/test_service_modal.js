]const { chromium } = require('playwright');

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

    // Check modal state
    const modalState = await page.evaluate(() => {
        return {
            catVal: document.getElementById('category')?.value,
            catIdVal: document.getElementById('category_id')?.value,
            label: document.getElementById('categoryLabelText')?.innerText,
            dropdownVisible: document.getElementById('categoryDropdownList')?.style.display,
            items: Array.from(document.querySelectorAll('.cat-choice-item')).map(el => ({
                id: el.dataset.id,
                val: el.dataset.value,
                label: el.dataset.label
            }))
        };
    });
    console.log('Modal state on open:', modalState);

    // Click trigger to open category dropdown
    await page.click('#categoryTrigger');
    await page.waitForTimeout(300);

    const dropdownOpen = await page.evaluate(() => {
        return document.getElementById('categoryDropdownList')?.style.display;
    });
    console.log('Dropdown after trigger click:', dropdownOpen);

    // Take a screenshot of the open modal
    await page.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/modal_open.png' });

    // Click on the Test item
    await page.click('.cat-choice-item[data-value="test"]');
    await page.waitForTimeout(300);

    const afterSelect = await page.evaluate(() => {
        return {
            catVal: document.getElementById('category')?.value,
            catIdVal: document.getElementById('category_id')?.value,
            label: document.getElementById('categoryLabelText')?.innerText
        };
    });
    console.log('After selecting Test:', afterSelect);

    await browser.close();
})();
