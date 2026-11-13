const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 960 } });
    const page = await context.newPage();

    page.on('console', msg => {
        console.log(`[PAGE ${msg.type()}]:`, msg.text());
    });
    page.on('dialog', async dialog => {
        console.log(`[DIALOG ${dialog.type()}]:`, dialog.message());
        await dialog.accept('Playwright test override reason');
    });

    console.log('1. Logging in as cashier visal...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'visal');
    await page.fill('#pin', '123456');
    await page.click('button[type="submit"]');
    await page.waitForNavigation();
    await page.waitForTimeout(1000);

    console.log('2. Navigating to #appointments in POS to create waiting car...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#appointments');
    await page.waitForTimeout(1500);

    const createRes = await page.evaluate(async () => {
        const payload = {
            plate_number: '2X-1234',
            vehicle_plate: '2X-1234',
            service_id: 1,
            appointment_date: new Date().toISOString().split('T')[0],
            appointment_time: '10:00',
            customer_name: 'Drag Test User',
            customer_phone: '012 333 444',
            notes: 'Playwright Drag Test',
            staff_id: 1
        };
        const appt = await PosApi.createAppointment(payload);
        if (appt && appt.appointment_id) {
            const arr = await PosApi.appointmentArrived(appt.appointment_id, 1);
            return { appt, arr };
        }
        return { error: 'Failed' };
    });
    console.log('Created and arrived test order:', JSON.stringify(createRes));

    console.log('3. Navigating to #queue in POS...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#queue');
    await page.waitForTimeout(2000);

    const rowsCount = await page.locator('tr[data-draggable-vehicle="true"]').count();
    console.log('Draggable rows found:', rowsCount);

    const targetBaysCount = await page.locator('.bay-card.bay-drop-target').count();
    console.log('Target free bays found:', targetBaysCount);

    // Let's test dragging using page.dragTo()
    const dragSource = page.locator('.queue-drag-handle').first();
    const dropTarget = page.locator('.bay-card.bay-drop-target').first();

    console.log('Attempting dragTo on drag handle...');
    try {
        await dragSource.dragTo(dropTarget);
        console.log('dragTo finished');
    } catch (e) {
        console.log('dragTo error:', e.message);
    }

    await page.waitForTimeout(2000);
    const shotAfterDragTo = path.join(brainDir, 'queue_test_dragto.png');
    await page.screenshot({ path: shotAfterDragTo });
    console.log('Saved screenshot after dragTo:', shotAfterDragTo);

    // Let's also test dragging using mouse actions
    console.log('Attempting manual mouse drag...');
    const srcBox = await dragSource.boundingBox();
    const dstBox = await dropTarget.boundingBox();
    console.log('Source box:', srcBox, 'Dest box:', dstBox);

    if (srcBox && dstBox) {
        await page.mouse.move(srcBox.x + srcBox.width / 2, srcBox.y + srcBox.height / 2);
        await page.mouse.down();
        await page.waitForTimeout(200);

        // Move in steps
        for (let i = 1; i <= 10; i++) {
            const curX = srcBox.x + (dstBox.x - srcBox.x) * (i / 10);
            const curY = srcBox.y + (dstBox.y - srcBox.y) * (i / 10);
            await page.mouse.move(curX, curY);
            await page.waitForTimeout(50);
        }

        await page.mouse.up();
        await page.waitForTimeout(2000);
    }

    const shotAfterMouse = path.join(brainDir, 'queue_test_mouse_drag.png');
    await page.screenshot({ path: shotAfterMouse });
    console.log('Saved screenshot after manual mouse drag:', shotAfterMouse);

    await browser.close();
})();
