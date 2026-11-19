const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const ARTIFACT_DIR = 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' });
    const context = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    const page = await context.newPage();

    // 1. Login as Cashier Visal
    const loginRes = await page.request.post('http://localhost/Car-WashService/api/pos/index.php?action=staff_login', {
        headers: { 'Content-Type': 'application/json', 'X-Device-Key': 'my_test_device_key_unique9' },
        data: { username: 'visal', pin: '123456' }
    });
    const loginData = await loginRes.json();
    console.log('Login success:', loginData.success, 'User role:', loginData.user.role);

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

    // 2. Verify nav tab [data-view="close"] and #viewClose are removed
    const hasNavClose = await page.evaluate(() => {
        return !!document.querySelector('.nav-tab[data-view="close"]');
    });
    console.log('Nav tab [data-view="close"] exists:', hasNavClose);

    const hasViewClose = await page.evaluate(() => {
        return !!document.getElementById('viewClose');
    });
    console.log('Section #viewClose exists in DOM:', hasViewClose);

    // 3. Open Cashier Actions
    await page.locator('#btnOpenCashierActions').click();
    await page.waitForTimeout(300);

    // 4. Click End Day button
    await page.locator('#btnActionEndDay').click();
    await page.waitForTimeout(400);

    // 5. Verify modalEndDay is open
    const isModalOpen = await page.evaluate(() => {
        const m = document.getElementById('modalEndDay');
        return m ? m.classList.contains('open') : false;
    });
    console.log('Modal #modalEndDay is open:', isModalOpen);

    const staffText = await page.evaluate(() => document.getElementById('endDayCurrentStaff')?.textContent);
    const dateText = await page.evaluate(() => document.getElementById('endDayCurrentDate')?.textContent);
    console.log('Modal staff:', staffText, 'date:', dateText);

    // Take screenshot
    const saveScreenshot = async (filename) => {
        const scratchPath = path.join(__dirname, filename);
        await page.screenshot({ path: scratchPath });
        const artifactPath = path.join(ARTIFACT_DIR, filename);
        fs.copyFileSync(scratchPath, artifactPath);
        console.log('Saved ' + filename);
    };

    await saveScreenshot('modal_end_day_popup.png');

    // Fill sample values in modal
    await page.fill('#endDayCountUsd', '75.50');
    await page.fill('#endDayCountKhr', '12000');
    await page.fill('#endDayNotes', 'End of day closing review');
    await page.fill('#endDayPin', '123456');

    await saveScreenshot('modal_end_day_popup_filled.png');

    await browser.close();
    console.log('Playwright test completed successfully!');
})();
