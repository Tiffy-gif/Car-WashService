const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });

    await page.goto('http://localhost/Car-WashService/index.php?view=login');
    await page.fill('input[name="username"]', 'owner');
    await page.evaluate(() => {
        document.getElementById('pinHiddenInput').value = '123456';
        document.getElementById('loginForm').submit();
    });
    await page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {});

    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2000);

    const results = await page.evaluate(async () => {
        const orderWithMileage = {
            order_number: 'ORD-WITH-KM',
            created_at: '2026-11-18 15:20:00',
            plate_number: '2BD-9999',
            items: [{ name_en: 'Engine Oil Change', qty: 1, price_usd: 25.00 }],
            subtotal_usd: 25.00,
            total_usd: 25.00,
            current_mileage: 85000,
            next_oil_mileage: 95000,
            next_oil_date: '2027-02-18'
        };

        const canvasWithKm = await PosThermalPrinter.renderReceiptToCanvas(orderWithMileage);
        const dataUriWithKm = canvasWithKm.toDataURL('image/png');

        const orderNoKm = {
            order_number: 'ORD-NO-KM',
            created_at: '2026-11-18 15:20:00',
            plate_number: '2BD-9999',
            items: [{ name_en: 'Exterior Wash', qty: 1, price_usd: 8.50 }],
            subtotal_usd: 8.50,
            total_usd: 8.50,
            current_mileage: null
        };

        const canvasNoKm = await PosThermalPrinter.renderReceiptToCanvas(orderNoKm);
        const dataUriNoKm = canvasNoKm.toDataURL('image/png');

        return {
            uriWithKm: dataUriWithKm,
            uriNoKm: dataUriNoKm,
            hWithKm: canvasWithKm.height,
            hNoKm: canvasNoKm.height
        };
    });

    console.log('Canvas with Km height:', results.hWithKm);
    console.log('Canvas without Km height:', results.hNoKm);

    fs.writeFileSync('C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/thermal_receipt_with_mileage.png', results.uriWithKm.replace(/^data:image\/png;base64,/, ""), 'base64');
    fs.writeFileSync('C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/thermal_receipt_blank_mileage.png', results.uriNoKm.replace(/^data:image\/png;base64,/, ""), 'base64');

    console.log('Saved both thermal receipts!');
    await browser.close();
})();
