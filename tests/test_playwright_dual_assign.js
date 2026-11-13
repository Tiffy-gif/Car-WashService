const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 960 } });
    const page = await context.newPage();

    page.on('console', msg => {
        const text = msg.text();
        if (!text.includes('404')) {
            console.log(`[PAGE ${msg.type()}]:`, text);
        }
    });

    console.log('1. Logging in as cashier visal...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'visal');
    await page.fill('#pin', '123456');
    await page.click('button[type="submit"]');
    await page.waitForNavigation();
    await page.waitForTimeout(1000);

    console.log('2. Navigating to Queue Screen...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#queue');
    await page.waitForTimeout(2000);

    // Initial state screenshot
    const shotInitial = path.join(brainDir, 'queue_dual_initial.png');
    await page.screenshot({ path: shotInitial });
    console.log('Saved queue_dual_initial.png');

    // Count draggable rows
    const draggables = page.locator('tr[data-draggable-vehicle="true"]');
    const dragCount = await draggables.count();
    console.log(`Found ${dragCount} draggable vehicle rows in Waiting Queue.`);

    const freeBays = page.locator('.bay-card.bay-free.bay-drop-target');
    const freeBayCount = await freeBays.count();
    console.log(`Found ${freeBayCount} free drop-target bays.`);

    if (dragCount === 0 || freeBayCount === 0) {
        console.error('Not enough vehicles or free bays to test dual assign.');
        await browser.close();
        return;
    }

    // ----------------------------------------------------
    // TEST 1: DRAG & DROP FIRST WAITING CAR INTO FIRST FREE BAY
    // ----------------------------------------------------
    const car1 = draggables.first();
    const car1Plate = await car1.getAttribute('data-plate');
    const dragHandle = car1.locator('.queue-drag-handle').first();
    const targetBay1 = freeBays.first();
    const targetBay1Name = await targetBay1.getAttribute('data-bay-name');

    console.log(`3. Dragging vehicle ${car1Plate} into ${targetBay1Name}...`);
    await dragHandle.dragTo(targetBay1);
    await page.waitForTimeout(1000);

    // Handle override modal if opened
    const overrideModal = page.locator('#modalBayOverride');
    if (await overrideModal.evaluate(el => el.classList.contains('open'))) {
        console.log('Override modal appeared during drag. Selecting quick reason chip...');
        await page.screenshot({ path: path.join(brainDir, 'queue_drag_override_modal.png') });
        await page.locator('#modalBayOverride .btn-chip').first().click();
        await page.waitForTimeout(200);
        await page.click('#btnConfirmBayOverride');
        await page.waitForTimeout(1500);
        console.log(`Confirmed override assignment for ${car1Plate} into ${targetBay1Name}.`);
    } else {
        console.log(`Direct drag assignment completed for ${car1Plate} into ${targetBay1Name}.`);
    }

    const shotAfterDrag = path.join(brainDir, 'queue_after_drag_success.png');
    await page.screenshot({ path: shotAfterDrag });
    console.log('Saved queue_after_drag_success.png');

    // ----------------------------------------------------
    // TEST 2: ONE-TAP "ASSIGN BAY" BUTTON FOR SECOND WAITING CAR
    // ----------------------------------------------------
    const remainingDraggables = page.locator('tr[data-draggable-vehicle="true"]');
    const remainingCount = await remainingDraggables.count();
    console.log(`Remaining draggable vehicles in queue: ${remainingCount}`);

    if (remainingCount > 0) {
        const car2 = remainingDraggables.first();
        const car2Plate = await car2.getAttribute('data-plate');
        console.log(`4. Clicking "Assign Bay" button for vehicle ${car2Plate}...`);

        const assignBtn = car2.locator('[data-action="assign-bay-modal"]').first();
        await assignBtn.click();
        await page.waitForTimeout(800);

        const chooseModal = page.locator('#modalChooseBay');
        console.log('Modal Choose Bay is open:', await chooseModal.evaluate(el => el.classList.contains('open')));
        await page.screenshot({ path: path.join(brainDir, 'queue_button_choose_modal.png') });

        // Select the first free bay option
        const bayOpt = page.locator('#chooseBayList .choose-bay-option').first();
        if (await bayOpt.count() > 0) {
            await bayOpt.click();
            await page.waitForTimeout(1000);

            if (await overrideModal.evaluate(el => el.classList.contains('open'))) {
                console.log('Override modal appeared for car 2. Confirming...');
                await page.locator('#modalBayOverride .btn-chip').first().click();
                await page.waitForTimeout(200);
                await page.click('#btnConfirmBayOverride');
                await page.waitForTimeout(1500);
                console.log(`Confirmed override assignment for ${car2Plate}.`);
            }
        }
    }

    const shotBothAssignedLight = path.join(brainDir, 'queue_both_assigned_light.png');
    await page.screenshot({ path: shotBothAssignedLight });
    console.log('Saved queue_both_assigned_light.png');

    // ----------------------------------------------------
    // TEST 3: DARK MODE VERIFICATION
    // ----------------------------------------------------
    console.log('5. Switching to Dark Mode and verifying UI...');
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'dark');
        document.body.setAttribute('data-theme', 'dark');
        localStorage.setItem('pos_theme', 'dark');
    });
    await page.waitForTimeout(1000);

    const shotBothAssignedDark = path.join(brainDir, 'queue_both_assigned_dark.png');
    await page.screenshot({ path: shotBothAssignedDark });
    console.log('Saved queue_both_assigned_dark.png');

    // Also switch back to list view and check badge styling
    console.log('6. Verifying Next in Line display formatting...');
    const badges = await page.evaluate(() => {
        return Array.from(document.querySelectorAll('.queue-pos-badge')).map(b => ({
            text: b.innerText,
            classes: b.className,
            width: b.offsetWidth,
            height: b.offsetHeight
        }));
    });
    console.log('Position badges on screen:', badges);

    console.log('Playwright Dual Assign verification complete!');
    await browser.close();
})();

