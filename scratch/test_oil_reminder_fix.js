const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });

    console.log('1. Logging in as owner...');
    await page.goto('http://localhost/Car-WashService/index.php?view=login');
    await page.fill('input[name="username"]', 'owner');
    await page.evaluate(() => {
        document.getElementById('pinHiddenInput').value = '123456';
        document.getElementById('loginForm').submit();
    });
    await page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {});

    console.log('2. Testing Backoffice Test Receipt (?test=1)...');
    await page.goto('http://localhost/Car-WashService/index.php?view=receipt&test=1');
    await page.waitForTimeout(1000);
    const testReceiptEl = await page.$('.unified-receipt');
    if (testReceiptEl) {
        await testReceiptEl.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/test_receipt_with_oil_reminder.png' });
        console.log('Captured test_receipt_with_oil_reminder.png');
    }

    const testOilBox = await page.evaluate(() => {
        const el = document.querySelector('.receipt-oil-reminder-box');
        return el ? el.innerText.trim() : null;
    });
    console.log('Test receipt oil reminder box text:\n', testOilBox);

    console.log('3. Testing Backoffice Order 90 (ORD-261118-007, mileage was null)...');
    await page.goto('http://localhost/Car-WashService/index.php?view=receipt&id=90');
    await page.waitForTimeout(1000);
    const order90OilBox = await page.evaluate(() => {
        const el = document.querySelector('.receipt-oil-reminder-box');
        return el ? el.innerText.trim() : null;
    });
    console.log('Order 90 oil reminder box text:\n', order90OilBox);

    console.log('4. Testing POS Modal and Thermal Printer Canvas with Order 90...');
    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2000);

    const posCanvasResult = await page.evaluate(async () => {
        const sampleOrder = {
            order_number: 'ORD-261118-007',
            created_at: '2026-11-18 07:38:37',
            cashier_name: 'Shop Owner',
            customer_name: 'Walk-In Guest',
            plate_number: 'W-4587',
            items: [
                { name_en: 'Tire Change & Wheel Balance', qty: 1, price_usd: 5.00 },
                { name_en: 'Brake Pad Replacement (Labor)', qty: 1, price_usd: 8.00 }
            ],
            subtotal_usd: 13.00,
            total_usd: 13.00,
            payment_method: 'cash',
            amount_tendered_usd: 15.00,
            change_usd: 2.00,
            payment_status: 'paid',
            status: 'completed',
            current_mileage: null
        };

        // Render modal
        PosApp.renderReceipt(sampleOrder);
        document.getElementById('modalReceipt').classList.add('open');

        const modalOilBox = document.querySelector('#receiptPrintArea .receipt-oil-reminder-box');
        const modalOilText = modalOilBox ? modalOilBox.innerText.trim() : null;

        // Render thermal canvas
        const canvas = await PosThermalPrinter.renderReceiptToCanvas(sampleOrder);
        const dataUri = canvas.toDataURL('image/png');

        return {
            modalOilText,
            canvasDataUriLength: dataUri.length,
            canvasDataUri: dataUri,
            canvasW: canvas.width,
            canvasH: canvas.height
        };
    });

    console.log('POS Modal Oil Reminder text:\n', posCanvasResult.modalOilText);
    console.log('POS Thermal Canvas generated:', posCanvasResult.canvasW, 'x', posCanvasResult.canvasH);

    // Save POS thermal canvas to file
    const base64Data = posCanvasResult.canvasDataUri.replace(/^data:image\/png;base64,/, "");
    fs.writeFileSync('C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/thermal_canvas_order_with_oil_reminder.png', base64Data, 'base64');
    console.log('Saved thermal_canvas_order_with_oil_reminder.png');

    const posModal = await page.$('#receiptPrintArea');
    if (posModal) {
        await posModal.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/pos_modal_order_with_oil_reminder.png' });
        console.log('Captured pos_modal_order_with_oil_reminder.png');
    }

    await browser.close();
    console.log('All tests finished successfully!');
})();
