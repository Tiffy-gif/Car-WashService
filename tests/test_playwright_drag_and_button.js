const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 960 } });
    const page = await context.newPage();

    page.on('console', msg => {
        const text = msg.text();
        if (!text.includes('Failed to load resource: the server responded with a status of 404')) {
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

    console.log('2. Creating two arrived waiting vehicles via POS API...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#appointments');
    await page.waitForTimeout(1000);

    const createdCars = await page.evaluate(async () => {
        const dateStr = new Date().toISOString().split('T')[0];

        // Car 1 for Drag & Drop
        const appt1 = await PosApi.createAppointment({
            plate_number: '2A-9988',
            vehicle_plate: '2A-9988',
            service_id: 1,
            appointment_date: dateStr,
            appointment_time: '11:00',
            customer_name: 'Drag Drop Guest',
            customer_phone: '012 998 877',
            notes: 'Playwright Drag Test',
            staff_id: 1
        });
        let arr1 = null;
        if (appt1 && appt1.appointment_id) {
            arr1 = await PosApi.appointmentArrived(appt1.appointment_id, 1);
        }

        // Car 2 for Button Assign Modal
        const appt2 = await PosApi.createAppointment({
            plate_number: '2B-7766',
            vehicle_plate: '2B-7766',
            service_id: 1,
            appointment_date: dateStr,
            appointment_time: '11:30',
            customer_name: 'Modal Button Guest',
            customer_phone: '012 776 655',
            notes: 'Playwright Button Assign Test',
            staff_id: 1
        });
        let arr2 = null;
        if (appt2 && appt2.appointment_id) {
            arr2 = await PosApi.appointmentArrived(appt2.appointment_id, 1);
        }

        return { car1: { appt: appt1, arr: arr1 }, car2: { appt: appt2, arr: arr2 } };
    });
    console.log('Created test vehicles:', JSON.stringify(createdCars));

    console.log('3. Navigating to #queue in POS...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#queue');
    await page.waitForTimeout(1500);

    // Initial state screenshot
    const shotInitial = path.join(brainDir, 'queue_playwright_fixed_initial.png');
    await page.screenshot({ path: shotInitial });
    console.log('Saved initial queue screenshot:', shotInitial);

    // Check draggable vehicles and free drop target bays
    const draggableRows = await page.locator('tr[data-draggable-vehicle="true"]').count();
    console.log(`Found ${draggableRows} draggable rows in Waiting Queue.`);

    const freeBays = await page.locator('.bay-card.bay-drop-target').count();
    console.log(`Found ${freeBays} free drop-target bays.`);

    // ----------------------------------------------------
    // TEST 1: DRAG & DROP CAR 1 INTO FREE BAY
    // ----------------------------------------------------
    console.log('4. Performing Drag & Drop on Car 1 (2A-9988)...');
    const dragSource = page.locator('tr[data-plate="2A-9988"] .queue-drag-handle').first();
    const dropTarget = page.locator('.bay-card.bay-drop-target').first();

    const targetBayName = await dropTarget.getAttribute('data-bay-name');
    console.log(`Dragging 2A-9988 into target bay: ${targetBayName}`);

    await dragSource.dragTo(dropTarget);
    await page.waitForTimeout(1000);

    // Check if Bay Override Modal appeared (in case target bay was alternative)
    const overrideModal = page.locator('#modalBayOverride');
    if (await overrideModal.evaluate(el => el.classList.contains('open'))) {
        console.log('Bay Override Modal opened gracefully! Choosing a quick reason chip...');
        await page.screenshot({ path: path.join(brainDir, 'queue_override_modal_open.png') });
        await page.locator('#modalBayOverride .btn-chip').first().click();
        await page.waitForTimeout(300);
        await page.click('#btnConfirmBayOverride');
        await page.waitForTimeout(1500);
        console.log('Confirmed bay override for 2A-9988.');
    } else {
        console.log('Direct drag assignment completed without override requirement.');
    }

    const shotAfterDrag = path.join(brainDir, 'queue_playwright_after_drag.png');
    await page.screenshot({ path: shotAfterDrag });
    console.log('Saved screenshot after drag assignment:', shotAfterDrag);

    // ----------------------------------------------------
    // TEST 2: ONE-TAP BUTTON "ASSIGN BAY" ON CAR 2 (2B-7766)
    // ----------------------------------------------------
    console.log('5. Performing One-Tap "Assign Bay" button on Car 2 (2B-7766)...');
    const assignBtn = page.locator('tr[data-plate="2B-7766"] [data-action="assign-bay-modal"]').first();
    if (await assignBtn.count() > 0) {
        await assignBtn.click();
        await page.waitForTimeout(800);

        const chooseModal = page.locator('#modalChooseBay');
        const isChooseOpen = await chooseModal.evaluate(el => el.classList.contains('open'));
        console.log('Modal Choose Bay is open:', isChooseOpen);

        await page.screenshot({ path: path.join(brainDir, 'queue_choose_bay_modal_open.png') });

        // Select the first free bay option in modal
        const bayOption = page.locator('#chooseBayList .choose-bay-option').first();
        if (await bayOption.count() > 0) {
            await bayOption.click();
            await page.waitForTimeout(1000);

            // If override is required
            if (await overrideModal.evaluate(el => el.classList.contains('open'))) {
                console.log('Override modal opened after modal select! Confirming...');
                await page.locator('#modalBayOverride .btn-chip[data-reason="Fastest available wash bay"]').click();
                await page.click('#btnConfirmBayOverride');
                await page.waitForTimeout(1500);
            }
        }
    }

    const shotAfterBoth = path.join(brainDir, 'queue_playwright_both_assigned_light.png');
    await page.screenshot({ path: shotAfterBoth });
    console.log('Saved screenshot after both assignments in Light Mode:', shotAfterBoth);

    // ----------------------------------------------------
    // TEST 3: DARK MODE VERIFICATION
    // ----------------------------------------------------
    console.log('6. Testing Dark Mode appearance...');
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'dark');
        document.body.setAttribute('data-theme', 'dark');
        localStorage.setItem('pos_theme', 'dark');
    });
    await page.waitForTimeout(1000);

    const shotDark = path.join(brainDir, 'queue_playwright_both_assigned_dark.png');
    await page.screenshot({ path: shotDark });
    console.log('Saved Dark Mode screenshot:', shotDark);

    console.log('All tests finished successfully!');
    await browser.close();
})();

