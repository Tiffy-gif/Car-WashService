const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1400, height: 900 } });
    const page = await context.newPage();

    console.log('1. Logging in as owner...');
    await page.goto('http://localhost/Car-WashService/index.php?view=login');
    await page.fill('input[name="username"]', 'owner');
    await page.evaluate(() => {
        document.getElementById('pinHiddenInput').value = '123456';
        document.getElementById('loginForm').submit();
    });
    await page.waitForNavigation({ waitUntil: 'networkidle' }).catch(() => {});
    await page.waitForTimeout(1000);

    console.log('2. Opening POS modal...');
    page.on('console', msg => console.log('PAGE LOG:', msg.text()));
    page.on('pageerror', err => console.log('PAGE ERROR:', err.message));
    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2000);

    // Open receipt modal for order ORD-261118-007
    const result = await page.evaluate(() => {
        const sampleOrder007 = {
            order_number: 'ORD-261118-007',
            created_at: '2026-11-18 07:38:37',
            cashier_name: 'Shop Owner',
            customer_name: 'Walk-In Guest',
            plate_number: 'W-4587',
            vehicle_type: null,
            items: [
                { name_en: 'Tire Change & Wheel Balance', name_km: 'ប្តូរសំបកកង់ & ថ្លឹងកង់', qty: 1, price_usd: 5.00 },
                { name_en: 'Brake Pad Replacement (Labor)', name_km: 'ដូរស្បែកហ្វ្រាំង (ថ្លៃឈ្នួល)', qty: 1, price_usd: 8.00 }
            ],
            subtotal_usd: 13.00,
            discount_usd: 0.00,
            total_usd: 13.00,
            total_khr: 53300,
            payment_method: 'cash',
            amount_tendered_usd: 15.00,
            change_usd: 2.00,
            payment_status: 'paid',
            status: 'completed',
            current_mileage: null
        };

        if (typeof PosApp !== 'undefined') {
            PosApp.renderReceipt(sampleOrder007);
            const m = document.getElementById('modalReceipt');
            if (m) {
                m.classList.add('open');
                console.log('modalReceipt opened!');
                return true;
            }
        }
        return false;
    });
    console.log('Evaluate result:', result);
    await page.waitForTimeout(1000);

    const posModalReceipt = await page.$('#receiptPrintArea');
    if (posModalReceipt) {
        await posModalReceipt.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/verified_pos_modal_receipt.png' });
        console.log('Captured verified_pos_modal_receipt.png');
    }

    console.log('3. Opening Settings Live Preview...');
    await page.goto('http://localhost/Car-WashService/index.php?view=settings&tab=receipt');
    await page.waitForTimeout(1500);

    const settingsPreview = await page.$('#receiptPaperContainer');
    if (settingsPreview) {
        await settingsPreview.screenshot({ path: 'C:/Users/PANHA/.gemini/antigravity/brain/e6c79286-b5e2-4292-9f67-cfecf09eb003/verified_settings_preview.png' });
        console.log('Captured verified_settings_preview.png');
    }

    await browser.close();
    console.log('Done!');
})();
