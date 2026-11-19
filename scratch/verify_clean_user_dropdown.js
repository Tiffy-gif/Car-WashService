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

    const saveScreenshot = async (filename) => {
        const scratchPath = path.join(__dirname, filename);
        await page.screenshot({ path: scratchPath });
        const artifactPath = path.join(ARTIFACT_DIR, filename);
        fs.copyFileSync(scratchPath, artifactPath);
        console.log('Saved ' + filename);
    };

    // 2. Open User Dropdown
    await page.locator('#userPill').click();
    await page.waitForTimeout(300);

    // 3. Verify elements in dropdown
    const hasCashierActionsInDropdown = await page.evaluate(() => !!document.getElementById('userMenuCashierActions'));
    const hasEndShiftInDropdown = await page.evaluate(() => !!document.getElementById('userMenuEndShift'));
    const hasSettingsInDropdown = await page.evaluate(() => {
        const btn = document.getElementById('userMenuSettings');
        if (!btn) return false;
        return window.getComputedStyle(btn).display !== 'none';
    });
    const hasLogoutInDropdown = await page.evaluate(() => {
        const btn = document.getElementById('userMenuLogout');
        if (!btn) return false;
        return window.getComputedStyle(btn).display !== 'none';
    });

    console.log('userMenuCashierActions exists in DOM:', hasCashierActionsInDropdown);
    console.log('userMenuEndShift exists in DOM:', hasEndShiftInDropdown);
    console.log('userMenuSettings is visible:', hasSettingsInDropdown);
    console.log('userMenuLogout is visible:', hasLogoutInDropdown);

    await saveScreenshot('user_dropdown_clean.png');

    // Also switch language to English to verify English render matching user's screenshot
    await page.evaluate(() => {
        if (typeof I18nManager !== 'undefined') {
            I18nManager.setLang('en');
        }
    });
    await page.waitForTimeout(300);
    // Re-open dropdown if needed
    await page.evaluate(() => {
        const dropdown = document.getElementById('userMenuDropdown');
        if (dropdown) dropdown.style.display = 'block';
    });
    await page.waitForTimeout(200);

    await saveScreenshot('user_dropdown_clean_en.png');

    await browser.close();
    console.log('Verification completed successfully!');
})();
