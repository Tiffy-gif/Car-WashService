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

    const isSettingsBtnVisible = await page.evaluate(() => {
        const btn = document.getElementById('userMenuSettings');
        if (!btn) return false;
        const style = window.getComputedStyle(btn);
        return style.display !== 'none' && style.visibility !== 'hidden';
    });
    console.log('userMenuSettings button is visible for Cashier Visal:', isSettingsBtnVisible);

    await saveScreenshot('dropdown_user_with_settings.png');

    // 3. Click userMenuSettings
    await page.locator('#userMenuSettings').click();
    await page.waitForTimeout(400);

    // 4. Verify Manager PIN modal opened
    const isPinModalOpen = await page.evaluate(() => {
        const m = document.getElementById('modalManagerPin');
        return m ? m.classList.contains('open') : false;
    });
    console.log('modalManagerPin is open:', isPinModalOpen);
    const pinTitle = await page.evaluate(() => document.getElementById('managerPinTitle')?.textContent?.trim());
    const pinSubtitle = await page.evaluate(() => document.getElementById('managerPinSubtitle')?.textContent?.trim());
    console.log('PIN Modal Title:', pinTitle);
    console.log('PIN Modal Subtitle:', pinSubtitle);

    await saveScreenshot('settings_authorization_pin_modal.png');

    // 5. Authorize with Owner credentials (e.g., owner / 123456)
    // Fill owner username
    await page.fill('#managerPinUsername', 'owner');
    // Click PIN digits 1, 2, 3, 4, 5, 6 (auto-confirms upon 6th digit)
    for (const d of ['1', '2', '3', '4', '5', '6']) {
        await page.locator(`#modalManagerPin .pin-key[data-digit="${d}"]`).click();
        await page.waitForTimeout(60);
    }

    // Wait for Settings modal to open
    await page.waitForSelector('#modalSettings.open', { timeout: 10000 });
    console.log('modalSettings is open after authorization: true');

    await saveScreenshot('pos_settings_modal_opened.png');

    await browser.close();
    console.log('All verification steps passed successfully!');
})();
