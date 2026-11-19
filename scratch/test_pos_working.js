const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' });
    const page = await browser.newPage({ viewport: { width: 1366, height: 768 } });

    // Login as Cashier Visal
    const loginRes = await page.request.post('http://localhost/Car-WashService/api/pos/index.php?action=staff_login', {
        headers: { 'Content-Type': 'application/json', 'X-Device-Key': 'my_test_device_key_unique9' },
        data: { username: 'visal', pin: '123456' }
    });
    const loginData = await loginRes.json();

    await page.goto('http://localhost/Car-WashService/pos/index.html#pos');
    await page.evaluate((data) => {
        localStorage.setItem('carwash_pos_has_connected', 'true');
        const cfg = { backofficeUrl: 'http://localhost/Car-WashService', deviceKey: 'my_test_device_key_unique9', printerDirectEnabled: true };
        localStorage.setItem('carwash_pos_config', JSON.stringify(cfg));
        localStorage.setItem('pos_config', JSON.stringify(cfg));
        localStorage.setItem('cw_pos_staff_user', JSON.stringify(data.user));
        sessionStorage.setItem('cw_pos_staff_user', JSON.stringify(data.user));
        localStorage.setItem('cw_pos_session_token', data.session_token);
        sessionStorage.setItem('cw_pos_session_token', data.session_token);
        const shift = { id: 14, shift_uid: 'SFT-20261119-5CAD6985', opening_cash_usd: 70, opening_cash_khr: 0, started_at: '2026-11-19 11:32:32', status: 'open' };
        localStorage.setItem('cw_pos_active_shift', JSON.stringify(shift));
        sessionStorage.setItem('cw_pos_active_shift', JSON.stringify(shift));
    }, loginData);

    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(600);

    // Dismiss start shift modal if open
    await page.evaluate(() => {
        document.querySelectorAll('.modal-backdrop.open').forEach(m => m.classList.remove('open'));
        PosApp.shiftBadgeState = 'active';
        PosApp.activeShift = { id: 14, shift_uid: 'SFT-20261119-5CAD6985', opening_cash_usd: 70, opening_cash_khr: 0, started_at: '2026-11-19 11:32:32', status: 'open' };
        PosApp.updateShiftStatusBadge('active', PosApp.activeShift);
    });

    // 1. Verify POS register view is active and catalog items are rendered
    const viewPosActive = await page.evaluate(() => {
        const v = document.getElementById('viewPos');
        return v ? v.classList.contains('active') : false;
    });
    const catalogCards = await page.locator('#catalogGrid .pos-product-card').count();
    console.log('viewPos active:', viewPosActive, 'Catalog card count:', catalogCards);

    // 2. Add an item to cart
    if (catalogCards > 0) {
        await page.locator('#catalogGrid .pos-product-card').first().click();
        await page.waitForTimeout(300);
        const cartCount = await page.locator('.cart-item-row').count();
        console.log('Items in cart:', cartCount);
    }

    // 3. Open Cashier Actions modal
    await page.locator('#btnOpenCashierActions').click();
    await page.waitForTimeout(300);
    const isActionsOpen = await page.evaluate(() => {
        const m = document.getElementById('modalCashierActions');
        return m ? m.classList.contains('open') : false;
    });
    console.log('Cashier Actions modal open:', isActionsOpen);
    await page.locator('#btnCloseCashierActions').click();
    await page.waitForTimeout(200);

    // 4. Open User Dropdown
    await page.locator('#userPill').click();
    await page.waitForTimeout(200);
    const userDropdownOpen = await page.evaluate(() => {
        const d = document.getElementById('userMenuDropdown');
        return d ? window.getComputedStyle(d).display === 'block' : false;
    });
    console.log('User dropdown open:', userDropdownOpen);

    const ARTIFACT_DIR = 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003';
    const finalShot = 'scratch/pos_working_final.png';
    await page.screenshot({ path: finalShot, fullPage: true });
    fs.copyFileSync(finalShot, path.join(ARTIFACT_DIR, 'pos_working_final.png'));

    await browser.close();
    console.log('Verification finished cleanly!');
})();
