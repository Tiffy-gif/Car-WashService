const { chromium } = require('playwright');
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

    const posWrap = await page.evaluate(() => {
        const sampleOrder007 = {
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
            status: 'completed'
        };
        PosApp.renderReceipt(sampleOrder007);
        document.getElementById('modalReceipt').classList.add('open');
        const addrEl = document.querySelector('#receiptPrintArea .receipt-shop-address');
        return {
            clientWidth: addrEl.clientWidth,
            offsetHeight: addrEl.offsetHeight,
            lineHeight: window.getComputedStyle(addrEl).lineHeight,
            fontSize: window.getComputedStyle(addrEl).fontSize
        };
    });
    console.log('POS address element:', posWrap);

    await page.goto('http://localhost/Car-WashService/index.php?view=settings&tab=receipt');
    await page.waitForTimeout(1500);

    const prevWrap = await page.evaluate(() => {
        const addrEl = document.querySelector('#receiptPreviewAddress');
        return {
            clientWidth: addrEl.clientWidth,
            offsetHeight: addrEl.offsetHeight,
            lineHeight: window.getComputedStyle(addrEl).lineHeight,
            fontSize: window.getComputedStyle(addrEl).fontSize
        };
    });
    console.log('Settings address element:', prevWrap);

    await browser.close();
})();
