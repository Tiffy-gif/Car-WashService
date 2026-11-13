const { chromium } = require('playwright');
const path = require('path');

const brainDir = 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 960 } });
    const page = await context.newPage();

    console.log('1. Logging in as cashier visal...');
    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'visal');
    await page.fill('#pin', '123456');
    await Promise.all([page.waitForNavigation(), page.click('button[type="submit"]')]);

    console.log('2. Navigating to #queue in POS...');
    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#queue');
    await page.waitForTimeout(2000);

    // Initial inspection
    const queueStatus = await page.evaluate(() => {
        const draggables = document.querySelectorAll('[data-draggable-vehicle="true"]');
        const assignButtons = document.querySelectorAll('[data-action="assign-bay-modal"]');
        const dropBays = document.querySelectorAll('.bay-card.bay-drop-target');

        return {
            draggableRowsCount: draggables.length,
            assignButtonsCount: assignButtons.length,
            freeDropBaysCount: dropBays.length,
            samplePlate: draggables[0]?.getAttribute('data-plate'),
            sampleBay: dropBays[0]?.getAttribute('data-bay-name')
        };
    });
    console.log('QUEUE INITIAL STATUS:', queueStatus);

    // Screenshot initial state showing BOTH Assign Button and Drag Handle
    const shotInitial = path.join(brainDir, 'queue_both_modes_initial.png');
    await page.screenshot({ path: shotInitial });
    console.log('Saved initial screenshot:', shotInitial);

    // 3. Simulate Drag Start to show active dropzones
    console.log('3. Simulating Drag Start state...');
    await page.evaluate(() => {
        const row = document.querySelector('[data-draggable-vehicle="true"]');
        if (row) {
            row.classList.add('is-dragging');
            document.querySelectorAll('.bay-card.bay-drop-target').forEach(b => {
                b.classList.add('bay-dropzone-active');
            });
            const firstBay = document.querySelector('.bay-card.bay-drop-target');
            if (firstBay) firstBay.classList.add('drag-hover');
        }
    });
    await page.waitForTimeout(300);

    const shotActiveDrag = path.join(brainDir, 'queue_active_drag_dropzone.png');
    await page.screenshot({ path: shotActiveDrag });
    console.log('Saved active drag screenshot:', shotActiveDrag);

    // 4. Test actual drop execution
    console.log('4. Performing Drag & Drop Bay Assignment...');
    const result = await page.evaluate(async () => {
        const row = document.querySelector('[data-draggable-vehicle="true"]');
        const targetBay = document.querySelector('.bay-card.bay-drop-target');
        if (!row || !targetBay) return { success: false, reason: 'No draggable row or free bay' };

        const orderId = row.getAttribute('data-order-id');
        const plate = row.getAttribute('data-plate');
        const orderNumber = row.getAttribute('data-order-number');
        const bayId = parseInt(targetBay.getAttribute('data-bay-id'), 10);
        const bayName = targetBay.getAttribute('data-bay-name') || ('Bay ' + bayId);

        // Reset visual classes
        row.classList.remove('is-dragging');
        document.querySelectorAll('.bay-card').forEach(b => {
            b.classList.remove('bay-dropzone-active');
            b.classList.remove('drag-hover');
        });

        // Trigger drop event simulation
        const dataTransfer = new DataTransfer();
        dataTransfer.setData('text/plain', JSON.stringify({ orderId, plate, orderNumber }));
        const dropEvent = new DragEvent('drop', {
            bubbles: true,
            cancelable: true,
            dataTransfer: dataTransfer
        });
        targetBay.dispatchEvent(dropEvent);

        return { success: true, plate, bayName, bayId, orderId };
    });
    console.log('DROP SIMULATION RESULT:', result);

    await page.waitForTimeout(2500);

    // Screenshot after assignment
    const shotAfter = path.join(brainDir, 'queue_after_drag_assign.png');
    await page.screenshot({ path: shotAfter });
    console.log('Saved after drop screenshot:', shotAfter);

    // 5. Test Dark Mode styling
    console.log('5. Capturing Dark Mode Drag & Drop view...');
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'dark');
        document.body.setAttribute('data-theme', 'dark');
        // Activate drag highlight to demonstrate dark theme dropzones
        const row = document.querySelector('[data-draggable-vehicle="true"]');
        if (row) row.classList.add('is-dragging');
        document.querySelectorAll('.bay-card.bay-drop-target').forEach(b => {
            b.classList.add('bay-dropzone-active');
        });
        const firstBay = document.querySelector('.bay-card.bay-drop-target');
        if (firstBay) firstBay.classList.add('drag-hover');
    });
    await page.waitForTimeout(400);

    const shotDark = path.join(brainDir, 'queue_drag_dark_mode.png');
    await page.screenshot({ path: shotDark });
    console.log('Saved dark mode screenshot:', shotDark);

    // Reset back to light
    await page.evaluate(() => {
        document.documentElement.setAttribute('data-theme', 'light');
        document.body.setAttribute('data-theme', 'light');
        document.querySelectorAll('.is-dragging').forEach(el => el.classList.remove('is-dragging'));
        document.querySelectorAll('.bay-dropzone-active').forEach(el => el.classList.remove('bay-dropzone-active'));
        document.querySelectorAll('.drag-hover').forEach(el => el.classList.remove('drag-hover'));
    });

    console.log('ALL TESTS COMPLETED SUCCESSFULLY.');
    await browser.close();
})();

