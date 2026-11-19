const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

const ARTIFACT_DIR = 'C:\\Users\\PANHA\\.gemini\\antigravity\\brain\\e6c79286-b5e2-4292-9f67-cfecf09eb003';

(async () => {
    console.log('Launching browser...');
    const browser = await chromium.launch({
        headless: true,
        executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
    });
    const context = await browser.newContext({
        viewport: { width: 1366, height: 768 }
    });
    const page = await context.newPage();

    // 1. Staff login via API to get genuine session_token
    const loginRes = await page.request.post('http://localhost/Car-WashService/api/pos/index.php?action=staff_login', {
        headers: { 'Content-Type': 'application/json', 'X-Device-Key': 'my_test_device_key_unique9' },
        data: { username: 'visal', pin: '123456' }
    });
    const loginData = await loginRes.json();
    console.log('Login success:', loginData.success, 'Token prefix:', loginData.session_token ? loginData.session_token.substring(0, 8) : 'none');

    // 2. Navigate to POS
    await page.goto('http://localhost/Car-WashService/pos/index.html#pos', { waitUntil: 'domcontentloaded' });

    // 3. Inject configuration, valid session, and active shift into storage
    await page.evaluate((data) => {
        localStorage.setItem('carwash_pos_has_connected', 'true');
        const cfg = {
            backofficeUrl: 'http://localhost/Car-WashService',
            deviceKey: 'my_test_device_key_unique9',
            printerDirectEnabled: true
        };
        localStorage.setItem('carwash_pos_config', JSON.stringify(cfg));
        localStorage.setItem('pos_config', JSON.stringify(cfg));

        localStorage.setItem('cw_pos_staff_user', JSON.stringify(data.user));
        sessionStorage.setItem('cw_pos_staff_user', JSON.stringify(data.user));
        localStorage.setItem('cw_pos_session_token', data.session_token);
        sessionStorage.setItem('cw_pos_session_token', data.session_token);

        const shift = {
            id: 13,
            shift_uid: 'SFT-20261119-F90B8973',
            opening_cash_usd: 70,
            opening_cash_khr: 0,
            started_at: '2026-11-19 10:06:21',
            status: 'open'
        };
        localStorage.setItem('cw_pos_active_shift', JSON.stringify(shift));
        sessionStorage.setItem('cw_pos_active_shift', JSON.stringify(shift));
    }, loginData);

    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);

    // Make sure background modals like settings or alert are closed and shift badge is set
    await page.evaluate(() => {
        document.querySelectorAll('.modal-backdrop').forEach(m => m.classList.remove('open'));
        if (typeof PosApp !== 'undefined') {
            PosApp.shiftBadgeState = 'active';
            PosApp.activeShift = {
                id: 13,
                shift_uid: 'SFT-20261119-F90B8973',
                opening_cash_usd: 70,
                opening_cash_khr: 0,
                started_at: '2026-11-19 10:06:21',
                status: 'open'
            };
            PosApp.updateShiftStatusBadge('active', PosApp.activeShift);
        }
    });
    await page.waitForTimeout(500);

    const saveScreenshot = async (filename) => {
        const scratchPath = path.join(__dirname, '../scratch', filename);
        await page.screenshot({ path: scratchPath });
        const artifactPath = path.join(ARTIFACT_DIR, filename);
        fs.copyFileSync(scratchPath, artifactPath);
        console.log(`Saved ${filename} to scratch and artifact directory`);
    };

    // 1. POS header & main view with Cashier Actions button visible
    await saveScreenshot('pos_header_button.png');

    // 2. Click Cashier Actions button
    await page.locator('#btnOpenCashierActions').click();
    await page.waitForTimeout(400);
    await saveScreenshot('pos_cashier_actions_popup.png');

    // 3. Click Cash In
    await page.locator('#btnActionCashIn').click();
    await page.waitForTimeout(400);
    await saveScreenshot('modal_cash_in.png');

    // Close Cash Movement modal via Cancel
    await page.locator('#btnCancelCashMovement').click();
    await page.waitForTimeout(300);

    // 4. Open Cashier Actions -> Click Cash Out
    await page.locator('#btnOpenCashierActions').click();
    await page.waitForTimeout(300);
    await page.locator('#btnActionCashOut').click();
    await page.waitForTimeout(300);
    // Enter $25 into USD input to trigger Manager PIN box
    await page.locator('#cashMovementUsdInput').fill('25');
    await page.waitForTimeout(300);
    await saveScreenshot('modal_cash_out_manager_auth.png');

    // Close Cash Movement modal
    await page.locator('#btnCancelCashMovement').click();
    await page.waitForTimeout(300);

    // 5. Open Cashier Actions -> Open Drawer
    await page.locator('#btnOpenCashierActions').click();
    await page.waitForTimeout(300);
    await page.locator('#btnActionOpenDrawer').click();
    await page.waitForTimeout(300);
    await saveScreenshot('modal_open_drawer.png');

    // Close Open Drawer
    await page.locator('#btnCancelOpenDrawer').click();
    await page.waitForTimeout(300);

    // 6. Open Cashier Actions -> Void Receipt
    await page.locator('#btnOpenCashierActions').click();
    await page.waitForTimeout(300);
    await page.locator('#btnActionVoidReceipt').click();
    await page.waitForTimeout(800);
    await saveScreenshot('modal_void_receipt.png');

    // Close Void Receipt
    await page.locator('#btnCancelVoidModal').click();
    await page.waitForTimeout(300);

    // 7. Open Cashier Actions -> Reprint Receipt
    await page.locator('#btnOpenCashierActions').click();
    await page.waitForTimeout(300);
    await page.locator('#btnActionReprintReceipt').click();
    await page.waitForTimeout(800);
    await saveScreenshot('modal_reprint_receipt.png');

    // 8. Open Cashier Actions -> Edit Initial Cash
    await page.locator('#btnDismissReprintModal').click();
    await page.waitForTimeout(300);
    await page.locator('#btnOpenCashierActions').click();
    await page.waitForTimeout(300);
    await page.locator('#btnActionEditFloat').click();
    await page.waitForTimeout(400);
    await saveScreenshot('modal_edit_float.png');

    await browser.close();
    console.log('ALL PLAYWRIGHT SCREENSHOTS CAPTURED AND COPIED SUCCESSFULLY!');
})();
