const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();

    console.log('1. Logging in as cashier visal...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'visal');
    await page.fill('#pin', '123456');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);

    console.log('2. Navigating to POS Register (#pos)...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2500);

    // Check initial customer card state
    const initialName = await page.textContent('#gotposCustNameDisplay');
    const initialTier = await page.textContent('#gotposCustTierDisplay');
    console.log(`Initial card: Name="${initialName.trim()}", Tier="${initialTier.trim()}"`);

    // Screenshot initial state
    await page.screenshot({ path: path.join(brainDir, 'pos_customer_initial.png') });

    // 3. Click customer card to open modal
    console.log('3. Clicking customer card to open customer selection modal...');
    await page.click('#gotposCustomerCard');
    await page.waitForTimeout(600);

    const isModalOpen = await page.evaluate(() => {
        const modal = document.getElementById('modalSelectCustomer');
        const items = document.querySelectorAll('#savedCustItems .saved-cust-card');
        return {
            open: modal?.classList.contains('open'),
            renderedItemsCount: items.length
        };
    });
    console.log('Modal status:', isModalOpen);

    // Screenshot open customer selector modal
    await page.screenshot({ path: path.join(brainDir, 'pos_customer_modal_open.png') });

    // 4. Test search input in modal
    console.log('4. Testing search for "TEST-01" or "Loyalty"...');
    await page.fill('#selectCustSearchInput', 'TEST');
    await page.waitForTimeout(400);

    const searchResultCount = await page.evaluate(() => {
        return document.querySelectorAll('#savedCustItems .saved-cust-card').length;
    });
    console.log(`Search for "TEST" yielded ${searchResultCount} customer(s)`);
    await page.screenshot({ path: path.join(brainDir, 'pos_customer_modal_search.png') });

    // 5. Select a vehicle chip or customer card
    console.log('5. Clicking vehicle chip to select customer...');
    const chipExists = await page.$('.cust-vehicle-chip');
    if (chipExists) {
        await page.click('.cust-vehicle-chip');
    } else {
        await page.click('.saved-cust-card');
    }
    await page.waitForTimeout(800);

    const selectedName = await page.textContent('#gotposCustNameDisplay');
    const selectedTier = await page.textContent('#gotposCustTierDisplay');
    const isResetVisible = await page.evaluate(() => {
        const btn = document.getElementById('btnGotposResetCustomer');
        return btn && btn.style.display !== 'none';
    });
    console.log(`After selection: Name="${selectedName.trim()}", Tier="${selectedTier.trim()}", ResetBtnVisible=${isResetVisible}`);

    await page.screenshot({ path: path.join(brainDir, 'pos_customer_selected.png') });

    // 6. Test reset customer
    console.log('6. Clicking reset customer button...');
    await page.click('#btnGotposResetCustomer');
    await page.waitForTimeout(500);

    const resetName = await page.textContent('#gotposCustNameDisplay');
    const resetTier = await page.textContent('#gotposCustTierDisplay');
    console.log(`After reset: Name="${resetName.trim()}", Tier="${resetTier.trim()}"`);

    await page.screenshot({ path: path.join(brainDir, 'pos_customer_after_reset.png') });

    await browser.close();
    console.log('Test completed successfully!');
})();
