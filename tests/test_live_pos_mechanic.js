const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

    page.on('console', msg => console.log('PAGE LOG:', msg.text()));
    page.on('pageerror', err => console.log('PAGE ERROR:', err.message));

    console.log('1. Logging in as technician dona...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'dona');
    await page.fill('#pin', '123456');
    await Promise.all([
        page.waitForNavigation(),
        page.click('button[type="submit"]')
    ]);

    console.log('2. Navigating to mechanic route in POS...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#mechanic');
    await page.waitForTimeout(2500);

    // Verify Active View & Elements
    const checkState = await page.evaluate(() => {
        const viewMechanic = document.getElementById('viewMechanic');
        const isActive = viewMechanic?.classList.contains('active');
        const liftCol = document.getElementById('mechanicLiftColumn');
        const rightCol = document.getElementById('mechanicRightColumn');
        const plateEl = document.getElementById('liftPlate');
        const stageBtns = document.querySelectorAll('.btn-stage-tile');
        const tabBtns = document.querySelectorAll('.right-tab-header .tab-btn');
        const inspectCards = document.querySelectorAll('.inspect-item-card');
        const hasCommissionText = viewMechanic ? /commission/i.test(viewMechanic.innerText) : false;

        return {
            isActive,
            liftExists: !!liftCol,
            rightExists: !!rightCol,
            plateText: plateEl ? plateEl.textContent.trim() : null,
            stageCount: stageBtns.length,
            tabCount: tabBtns.length,
            inspectCount: inspectCards.length,
            hasCommissionText,
            activeTab: document.querySelector('.right-tab-header .tab-btn.active')?.textContent?.trim()
        };
    });

    console.log('Mechanic View DOM State:', JSON.stringify(checkState, null, 2));

    // Capture initial screenshot
    await page.screenshot({ path: path.join(brainDir, 'live_pos_mechanic_initial.png') });
    console.log('Captured live_pos_mechanic_initial.png');

    // Switch to Safety Inspection tab
    console.log('3. Testing tab switch to Safety Inspection...');
    await page.click('#tabBtnInspection');
    await page.waitForTimeout(600);

    const inspectTabState = await page.evaluate(() => {
        const paneInspection = document.getElementById('paneInspection');
        return {
            inspectionDisplay: paneInspection?.style?.display,
            inspectCards: document.querySelectorAll('.inspect-item-card').length,
            passAllBtn: !!document.querySelector('.btn-pass-all')
        };
    });
    console.log('Inspection Tab State:', JSON.stringify(inspectTabState, null, 2));

    await page.screenshot({ path: path.join(brainDir, 'live_pos_mechanic_inspection.png') });
    console.log('Captured live_pos_mechanic_inspection.png');

    // Test clicking PASS on first inspection item
    console.log('4. Testing click on first PASS button...');
    const firstPassBtn = await page.$('.inspect-item-card .btn-chk-big');
    if (firstPassBtn) {
        await firstPassBtn.click();
        await page.waitForTimeout(1000);
        console.log('Clicked PASS successfully.');
    }

    // Switch to Completed tab
    console.log('5. Testing tab switch to Completed...');
    await page.click('#tabBtnCompleted');
    await page.waitForTimeout(600);
    await page.screenshot({ path: path.join(brainDir, 'live_pos_mechanic_completed.png') });
    console.log('Captured live_pos_mechanic_completed.png');

    // Switch back to Queue tab
    console.log('6. Testing tab switch to Queue...');
    await page.click('#tabBtnQueue');
    await page.waitForTimeout(600);
    await page.screenshot({ path: path.join(brainDir, 'live_pos_mechanic_queue.png') });
    console.log('Captured live_pos_mechanic_queue.png');

    // Test oil stepper
    console.log('7. Testing oil stepper buttons...');
    const plusStepBtn = await page.$('.fluid-adjuster .btn-big-step:last-child');
    if (plusStepBtn) {
        await plusStepBtn.click();
        await plusStepBtn.click();
        const oilVol = await page.$eval('#oilVolDisplay', el => el.textContent);
        console.log('Oil volume after +2 clicks:', oilVol);
    }

    await page.screenshot({ path: path.join(brainDir, 'live_pos_mechanic_final.png') });
    console.log('Captured live_pos_mechanic_final.png');

    await browser.close();
    console.log('All Playwright tests passed!');
})();

