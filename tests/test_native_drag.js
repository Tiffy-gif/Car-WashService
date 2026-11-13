const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 960 } });

    console.log('1. Logging in as cashier visal...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'visal');
    await page.fill('#pin', '123456');
    await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);

    console.log('2. Navigating to #queue in POS...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#queue');
    await page.waitForTimeout(2000);

    // Check if there are any waiting cars
    let waitingRows = page.locator('tr[data-draggable-vehicle="true"]');
    let count = await waitingRows.count();
    console.log('Waiting draggable rows count:', count);

    // If no waiting cars, let's create a quick order from pos or appointments
    if (count === 0) {
        console.log('No waiting cars! Creating a test order...');
        await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#pos');
        await page.waitForTimeout(1000);
        await page.fill('#custSearchInput', '2D-9988');
        await page.press('#custSearchInput', 'Enter');
        await page.waitForTimeout(500);

        // Click first service card in catalog
        const svcCard = page.locator('.pos-item-card').first();
        if (await svcCard.count() > 0) {
            await svcCard.click();
            await page.waitForTimeout(300);
        }
        // Click Quick Pay / Pay Cash
        const btnPay = page.locator('#btnPayNow, .btn-pay-cash, #btnCheckout');
        if (await btnPay.count() > 0) {
            await btnPay.first().click();
            await page.waitForTimeout(1000);
            const btnConfirm = page.locator('#btnConfirmPayment, .btn-confirm-pay');
            if (await btnConfirm.count() > 0) {
                await btnConfirm.first().click();
                await page.waitForTimeout(1500);
            }
        }

        await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#queue');
        await page.waitForTimeout(2000);
        waitingRows = page.locator('tr[data-draggable-vehicle="true"]');
        count = await waitingRows.count();
        console.log('Waiting draggable rows after creating order:', count);
    }

    // Try real Playwright dragAndDrop
    const source = page.locator('tr[data-draggable-vehicle="true"]').first();
    const target = page.locator('.bay-card.bay-drop-target').first();

    console.log('Source count:', await source.count(), 'Target count:', await target.count());

    if (await source.count() > 0 && await target.count() > 0) {
        console.log('Attempting native Playwright page.dragAndDrop()...');
        try {
            await source.dragTo(target);
            console.log('dragTo() completed.');
        } catch (e) {
            console.log('dragTo() error:', e.message);
        }
    }

    await page.waitForTimeout(2000);
    const shotResult = path.join(brainDir, 'queue_native_drag_result.png');
    await page.screenshot({ path: shotResult });
    console.log('Saved screenshot:', shotResult);

    await browser.close();
})();
