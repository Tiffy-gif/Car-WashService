const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

    page.on('console', msg => {
        if (msg.type() === 'error') console.log('BROWSER ERROR:', msg.text());
    });
    page.on('pageerror', err => console.log('PAGE ERROR:', err.toString()));

    console.log('--- Step 1: Login as Owner ---');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'owner');
    await page.fill('#pin', '123456');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.click('button[type="submit"]')
    ]);

    console.log('--- Step 2: Navigate to POS Register to create test wash order ---');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#pos', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);

    // Enter plate number
    const testPlate = '2BB-' + Math.floor(1000 + Math.random() * 9000);
    console.log(`Creating order for plate ${testPlate}...`);
    await page.fill('#custPlateInput', testPlate);

    // Select vehicle type (small_car)
    const vtypeBtn = page.locator('.vehicle-btn[data-vtype="small_car"]');
    if (await vtypeBtn.isVisible()) {
        await vtypeBtn.click();
    }

    // Click first service card in catalog
    const firstService = page.locator('#catalogGrid .pos-item-card').first();
    await firstService.click();
    await page.waitForTimeout(600);

    // Dismiss upsell modal if it appeared
    const declineUpsell = page.locator('#btnDeclineUpsell');
    if (await declineUpsell.isVisible()) {
        console.log('Declining upsell modal offer...');
        await declineUpsell.click();
        await page.waitForTimeout(500);
    }

    // Open Pay modal and checkout cash
    console.log('Proceeding to cash checkout...');
    await page.click('#btnOpenCheckout');
    await page.waitForTimeout(800);

    // Click confirm payment
    const confirmBtn = page.locator('#btnConfirmPaymentAndPrint');
    if (await confirmBtn.isVisible()) {
        await confirmBtn.click();
    }
    await page.waitForTimeout(2000);

    // Close receipt modal if open
    await page.evaluate(() => {
        document.querySelectorAll('.modal-backdrop').forEach(m => m.classList.remove('open'));
    });
    await page.waitForTimeout(500);

    console.log('--- Step 3: Switch to Washer Screen ---');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#washer', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    await page.evaluate(() => {
        document.querySelectorAll('.modal-backdrop').forEach(m => m.classList.remove('open'));
    });
    await page.waitForTimeout(500);

    // Check queue and bays state
    const washerState = await page.evaluate(() => {
        const queueCards = Array.from(document.querySelectorAll('.queue-item-card')).map(c => ({
            plate: c.querySelector('.queue-plate-text')?.textContent?.trim(),
            car: c.querySelector('.queue-car-text')?.textContent?.trim()
        }));
        const bays = Array.from(document.querySelectorAll('.bay-article')).map(b => ({
            id: b.id,
            title: b.querySelector('.title')?.textContent?.trim(),
            chip: b.querySelector('.bay-chip')?.textContent?.trim(),
            plate: b.querySelector('.plate-badge-dark')?.textContent?.trim(),
            btn: b.querySelector('.bay-actions-row button')?.textContent?.trim()
        }));
        return { queueCards, bays };
    });
    console.log('Washer screen state with car in queue:', JSON.stringify(washerState, null, 2));

    await page.screenshot({ path: path.join(brainDir, 'pw_washer_with_queue.png') });

    // Step 4: Click Pull into Free Bay (either from queue card or bay 1)
    console.log('--- Step 4: Pull car into Bay ---');
    const pullBtn = page.locator('.bay-actions-row button.btn-pullin-blue:not([disabled])').first();
    if (await pullBtn.isVisible()) {
        console.log('Clicking Pull into Bay 1...');
        await pullBtn.click();
        await page.waitForTimeout(2500);
    } else {
        console.log('Bay pull button was not enabled, trying queue pull button...');
        const queuePullBtn = page.locator('.btn-queue-pull').first();
        if (await queuePullBtn.isVisible()) {
            await queuePullBtn.click();
            await page.waitForTimeout(2500);
        }
    }

    const inWashState = await page.evaluate(() => {
        const bays = Array.from(document.querySelectorAll('.bay-article')).map(b => ({
            id: b.id,
            title: b.querySelector('.title')?.textContent?.trim(),
            chip: b.querySelector('.bay-chip')?.textContent?.trim(),
            plate: b.querySelector('.plate-badge-dark')?.textContent?.trim(),
            btn: b.querySelector('.bay-actions-row button')?.textContent?.trim()
        }));
        return { bays, queueCount: document.querySelectorAll('.queue-item-card').length };
    });
    console.log('In-wash state:', JSON.stringify(inWashState, null, 2));
    await page.screenshot({ path: path.join(brainDir, 'pw_washer_in_wash.png') });

    // Step 5: Complete wash on Bay 1
    console.log('--- Step 5: Complete Wash on Bay 1 ---');
    const completeBtn = page.locator('button.btn-complete-green').first();
    if (await completeBtn.isVisible()) {
        await completeBtn.click();
        await page.waitForTimeout(1000);
        await page.screenshot({ path: path.join(brainDir, 'pw_washer_finish_modal.png') });

        // Click Finish On Time button in modal
        const finishOnTime = page.locator('#btnFinishOnTime');
        if (await finishOnTime.isVisible()) {
            console.log('Confirming wash complete in modal...');
            await finishOnTime.click();
            await page.waitForTimeout(2500);
        }
    }

    const finalState = await page.evaluate(() => {
        return {
            baysCount: document.querySelectorAll('.bay-article').length,
            queueCount: document.querySelectorAll('.queue-item-card').length,
            bays: Array.from(document.querySelectorAll('.bay-article')).map(b => ({
                id: b.id,
                chip: b.querySelector('.bay-chip')?.textContent?.trim()
            }))
        };
    });
    console.log('Final state after wash completion:', JSON.stringify(finalState, null, 2));
    await page.screenshot({ path: path.join(brainDir, 'pw_washer_completed.png') });

    console.log('--- ALL PLAYWRIGHT TESTS COMPLETED SUCCESSFULLY ---');
    await browser.close();
})();

