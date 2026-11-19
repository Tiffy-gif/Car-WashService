const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const ARTIFACT_DIR = 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' });
    const context = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    const page = await context.newPage();

    // Login as Cashier Visal
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

    await page.evaluate(() => {
        PosApp.shiftBadgeState = 'active';
        PosApp.activeShift = { id: 14, shift_uid: 'SFT-20261119-5CAD6985', opening_cash_usd: 70, opening_cash_khr: 0, started_at: '2026-11-19 11:32:32', status: 'open' };
        PosApp.updateShiftStatusBadge('active', PosApp.activeShift);
    });

    const saveScreenshot = async (filename) => {
        const scratchPath = path.join(__dirname, filename);
        await page.screenshot({ path: scratchPath });
        const artifactPath = path.join(ARTIFACT_DIR, filename);
        fs.copyFileSync(scratchPath, artifactPath);
        console.log('Saved ' + filename);
    };

    // Dismiss any modal that opened automatically (like start shift)
    await page.evaluate(() => {
        document.querySelectorAll('.modal-backdrop.open').forEach(m => m.classList.remove('open'));
    });
    await page.waitForTimeout(300);

    // 1. Check Cashier Actions popup: End Day button is accessible to cashier without MGR badge
    await page.locator('#btnOpenCashierActions').click();
    await page.waitForTimeout(300);
    const hasMgrBadge = await page.evaluate(() => {
        const btn = document.getElementById('btnActionEndDay');
        return btn ? !!btn.querySelector('.action-tile-badge-mgr') : false;
    });
    console.log('End Day has MGR badge:', hasMgrBadge);
    await saveScreenshot('cashier_actions_end_day_unrestricted.png');

    // 2. Click End Day button directly
    await page.locator('#btnActionEndDay').click();
    await page.waitForTimeout(600);

    // Verify view is now 'close' and viewClose is active
    const isCloseViewActive = await page.evaluate(() => {
        const v = document.getElementById('viewClose');
        return v ? v.classList.contains('active') : false;
    });
    console.log('Close Day view is active for Cashier:', isCloseViewActive);
    await saveScreenshot('cashier_daily_close_page.png');

    await browser.close();
    console.log('Playwright UI verification finished successfully!');
})();
