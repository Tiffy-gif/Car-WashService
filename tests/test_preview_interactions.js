const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

    const errors = [];
    page.on('console', msg => {
        if (msg.type() === 'error') errors.push(msg.text());
    });
    page.on('pageerror', err => errors.push(err.toString()));

    console.log('--- Loading preview_washer_idea1.html ---');
    await page.goto('http://127.0.0.1/Car-WashService/pos/preview_washer_idea1.html');
    await page.waitForTimeout(500);

    // Initial screenshot
    await page.screenshot({ path: path.join(brainDir, 'preview_step1_initial.png') });

    // Step 2: Click "PULL IN NEXT" on Bay 2
    console.log('--- Clicking PULL IN NEXT on Bay 2 ---');
    const pullInBtn = page.locator('article:has-text("Bay 2") button.btn-pullin-blue');
    await pullInBtn.click();
    await page.waitForTimeout(500);
    await page.screenshot({ path: path.join(brainDir, 'preview_step2_pulled_in.png') });

    // Step 3: Click "COMPLETE WASH & QC" on Bay 1
    console.log('--- Clicking COMPLETE WASH on Bay 1 ---');
    const completeBtn = page.locator('article:has-text("Bay 1") button.btn-complete-green');
    await completeBtn.click();
    await page.waitForTimeout(500);
    await page.screenshot({ path: path.join(brainDir, 'preview_step3_completed_bay1.png') });

    // Step 4: Click "HANDOVER TO TECH BAY 4" on Bay 3
    console.log('--- Clicking HANDOVER on Bay 3 ---');
    const handoverBtn = page.locator('article:has-text("Bay 3") button.btn-handover-purple');
    await handoverBtn.click();
    await page.waitForTimeout(500);
    await page.screenshot({ path: path.join(brainDir, 'preview_step4_handover_bay3.png') });

    // Step 5: Add a walk-in vehicle
    console.log('--- Clicking Add Walk-in Vehicle ---');
    await page.locator('button.btn-add-walkin').click();
    await page.waitForTimeout(500);
    await page.screenshot({ path: path.join(brainDir, 'preview_step5_walkin_added.png') });

    console.log('Errors caught:', errors);
    await browser.close();
})();

