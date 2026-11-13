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

    console.log('2. Freeing up wash bays to start test with clean available bays...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#queue');
    await page.waitForTimeout(1500);

    await page.evaluate(async () => {
        // Free Bay 1 and Bay 2 if busy
        const baysData = await PosApi.getBaysAndQueue();
        const busyBays = (baysData?.bays || []).filter(b => b.status === 'busy' && b.bay_type === 'wash');
        for (const b of busyBays) {
            try {
                await PosApi.finishCar(b.id, b.current_order_id, 1);
            } catch (e) {
                console.log('Error freeing bay', b.id, e);
            }
        }
    });
    await page.waitForTimeout(1000);

    console.log('3. Creating 2 waiting arrived cars for the dual assign test...');
    const createdRes = await page.evaluate(async () => {
        const dateStr = new Date().toISOString().split('T')[0];

        // Car 1 (for Drag & Drop)
        const appt1 = await PosApi.createAppointment({
            plate_number: '2C-1122',
            vehicle_plate: '2C-1122',
            service_id: 1,
            appointment_date: dateStr,
            appointment_time: '12:00',
            customer_name: 'Alex Drag',
            customer_phone: '011 223 344',
            notes: 'Drag Test Vehicle',
            staff_id: 1
        });
        if (appt1?.appointment_id) {
            await PosApi.appointmentArrived(appt1.appointment_id, 1);
        }

        // Car 2 (for One-Tap Assign Button)
        const appt2 = await PosApi.createAppointment({
            plate_number: '2D-3344',
            vehicle_plate: '2D-3344',
            service_id: 1,
            appointment_date: dateStr,
            appointment_time: '12:30',
            customer_name: 'Dara Button',
            customer_phone: '011 556 677',
            notes: 'Button Test Vehicle',
            staff_id: 1
        });
        if (appt2?.appointment_id) {
            await PosApi.appointmentArrived(appt2.appointment_id, 1);
        }

        return { appt1, appt2 };
    });
    console.log('Created vehicles:', JSON.stringify(createdRes));

    console.log('4. Navigating to Queue Screen...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#queue');
    await page.waitForTimeout(2000);

    const shotQueueReady = path.join(brainDir, 'queue_dual_ready.png');
    await page.screenshot({ path: shotQueueReady });
    console.log('Saved queue_dual_ready.png');

    // ----------------------------------------------------
    // TEST STEP 1: DRAG & DROP 2C-1122
    // ----------------------------------------------------
    console.log('5. Dragging 2C-1122 onto first free wash bay...');
    const car1DragHandle = page.locator('tr[data-plate="2C-1122"] .queue-drag-handle').first();
    const washBay1 = page.locator('.bay-card.bay-free.bay-drop-target').first();

    const bayName1 = await washBay1.getAttribute('data-bay-name');
    console.log(`Dragging 2C-1122 into: ${bayName1}`);

    await car1DragHandle.dragTo(washBay1);
    await page.waitForTimeout(1000);

    // If override modal opened
    const overrideModal = page.locator('#modalBayOverride');
    if (await overrideModal.evaluate(el => el.classList.contains('open'))) {
        console.log('Override modal appeared for 2C-1122. Confirming with quick chip...');
        await page.locator('#modalBayOverride .btn-chip').first().click();
        await page.click('#btnConfirmBayOverride');
        await page.waitForTimeout(1500);
    }

    const shotAfterCar1 = path.join(brainDir, 'queue_dual_car1_assigned.png');
    await page.screenshot({ path: shotAfterCar1 });
    console.log('Saved queue_dual_car1_assigned.png');

    // ----------------------------------------------------
    // TEST STEP 2: BUTTON ASSIGN MODAL FOR 2D-3344
    // ----------------------------------------------------
    console.log('6. Clicking "Assign Bay" button for 2D-3344...');
    const car2AssignBtn = page.locator('tr[data-plate="2D-3344"] [data-action="assign-bay-modal"]').first();
    await car2AssignBtn.click();
    await page.waitForTimeout(800);

    const chooseModal = page.locator('#modalChooseBay');
    console.log('Modal Choose Bay open:', await chooseModal.evaluate(el => el.classList.contains('open')));

    const shotModalOpen = path.join(brainDir, 'queue_dual_choose_modal.png');
    await page.screenshot({ path: shotModalOpen });
    console.log('Saved queue_dual_choose_modal.png');

    // Select the recommended or first wash bay in the modal
    const bayOpt = page.locator('#chooseBayList .choose-bay-option:not([style*="not-allowed"])').first();
    await bayOpt.click();
    await page.waitForTimeout(1000);

    if (await overrideModal.evaluate(el => el.classList.contains('open'))) {
        console.log('Override modal appeared for 2D-3344. Confirming...');
        await page.locator('#modalBayOverride .btn-chip').first().click();
        await page.click('#btnConfirmBayOverride');
        await page.waitForTimeout(1500);
    }

    const shotAfterCar2 = path.join(brainDir, 'queue_dual_both_in_bays_light.png');
    await page.screenshot({ path: shotAfterCar2 });
    console.log('Saved queue_dual_both_in_bays_light.png');

    // ----------------------------------------------------
    // TEST STEP 3: DARK MODE VERIFICATION
    // ----------------------------------------------------
    console.log('7. Switching to Dark Mode...');
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'dark');
        document.body.setAttribute('data-theme', 'dark');
        localStorage.setItem('pos_theme', 'dark');
    });
    await page.waitForTimeout(1000);

    const shotDark = path.join(brainDir, 'queue_dual_both_in_bays_dark.png');
    await page.screenshot({ path: shotDark });
    console.log('Saved queue_dual_both_in_bays_dark.png');

    console.log('All verification complete!');
    await browser.close();
})();

