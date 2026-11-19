const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const ARTIFACT_DIR = 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    const browser = await chromium.launch({ headless: true, executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe' });
    const context = await browser.newContext({ viewport: { width: 1366, height: 768 } });
    const page = await context.newPage();

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
        const shift = { id: 13, shift_uid: 'SFT-20261119-F90B8973', opening_cash_usd: 70, opening_cash_khr: 0, started_at: '2026-11-19 10:06:21', status: 'open' };
        localStorage.setItem('cw_pos_active_shift', JSON.stringify(shift));
        sessionStorage.setItem('cw_pos_active_shift', JSON.stringify(shift));
    }, loginData);

    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(600);

    await page.evaluate(() => {
        PosApp.shiftBadgeState = 'active';
        PosApp.activeShift = { id: 13, shift_uid: 'SFT-20261119-F90B8973', opening_cash_usd: 70, opening_cash_khr: 0, started_at: '2026-11-19 10:06:21', status: 'open' };
        PosApp.updateShiftStatusBadge('active', PosApp.activeShift);
    });

    const saveScreenshot = async (filename) => {
        const scratchPath = path.join(__dirname, filename);
        await page.screenshot({ path: scratchPath });
        const artifactPath = path.join(ARTIFACT_DIR, filename);
        fs.copyFileSync(scratchPath, artifactPath);
        console.log('Saved ' + filename);
    };

    // 1. Test clicking End Shift from Cashier Actions
    await page.locator('#btnOpenCashierActions').click();
    await page.waitForTimeout(200);
    await page.locator('#btnActionEndShift').click();
    await page.waitForTimeout(400);
    await saveScreenshot('modal_end_shift_clean.png');

    // Close End Shift
    await page.locator('#btnCancelEndShift').click();
    await page.waitForTimeout(200);

    // 2. Test clicking Switch User (Handover) from Cashier Actions
    await page.locator('#btnOpenCashierActions').click();
    await page.waitForTimeout(200);
    await page.locator('#btnActionSwitchUser').click();
    await page.waitForTimeout(400);
    await saveScreenshot('modal_switch_user_handover_clean.png');

    await browser.close();
    console.log('Done verifying both clean modals!');
})();
