const { chromium } = require('playwright');
const path = require('path');

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

    // Navigate to POS
    console.log('Navigating to POS...');
    await page.goto('http://localhost/Car-WashService/pos/index.html#pos', { waitUntil: 'domcontentloaded' });

    // Set configuration and session
    await page.evaluate(() => {
        localStorage.setItem('carwash_pos_has_connected', 'true');
        const cfg = {
            deviceApiKey: 'my_test_device_key_unique9',
            apiBaseUrl: 'http://localhost/Car-WashService/api/pos/index.php',
            printerDirectEnabled: true
        };
        localStorage.setItem('pos_config', JSON.stringify(cfg));
        localStorage.setItem('carwash_pos_config', JSON.stringify(cfg));

        const user = { id: 2, username: 'visal', full_name: 'visal', role: 'cashier' };
        localStorage.setItem('pos_current_user', JSON.stringify(user));
        localStorage.setItem('pos_staff_token', 'test_token');
        localStorage.setItem('pos_active_shift', JSON.stringify({
            id: 13,
            shift_uid: 'SFT-20261119-F90B8973',
            opening_cash_usd: 70,
            opening_cash_khr: 0,
            started_at: '2026-11-19 10:06:21'
        }));
    });

    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(1000);

    // Make sure background modals like settings are closed
    await page.evaluate(() => {
        document.querySelectorAll('.modal-backdrop').forEach(m => m.classList.remove('open'));
    });
    await page.waitForTimeout(300);

    // 1. Screenshot POS main screen with Cashier Actions button visible
    await page.screenshot({ path: path.join(__dirname, '../scratch/pos_header_button.png') });
    console.log('Captured pos_header_button.png');

    // 2. Click Cashier Actions button
    const btnActions = page.locator('#btnOpenCashierActions');
    await btnActions.click();
    await page.waitForTimeout(500);
    await page.screenshot({ path: path.join(__dirname, '../scratch/pos_cashier_actions_popup.png') });
    console.log('Captured pos_cashier_actions_popup.png');

    // 3. Click Cash In
    const btnCashIn = page.locator('#btnActionCashIn');
    await btnCashIn.click();
    await page.waitForTimeout(500);
    await page.screenshot({ path: path.join(__dirname, '../scratch/modal_cash_in.png') });
    console.log('Captured modal_cash_in.png');

    // Close Cash Movement modal using Cancel button
    await page.locator('#btnCancelCashMovement').click();
    await page.waitForTimeout(300);

    // 4. Open Cashier Actions -> Click Cash Out
    await btnActions.click();
    await page.waitForTimeout(300);
    await page.locator('#btnActionCashOut').click();
    await page.waitForTimeout(300);
    // Enter $25 into USD input to trigger Manager PIN box
    await page.locator('#cashMovementUsdInput').fill('25');
    await page.waitForTimeout(400);
    await page.screenshot({ path: path.join(__dirname, '../scratch/modal_cash_out_manager_auth.png') });
    console.log('Captured modal_cash_out_manager_auth.png');

    // Close Cash Movement modal
    await page.locator('#btnCancelCashMovement').click();
    await page.waitForTimeout(300);

    // 5. Open Cashier Actions -> Open Drawer
    await btnActions.click();
    await page.waitForTimeout(300);
    await page.locator('#btnActionOpenDrawer').click();
    await page.waitForTimeout(300);
    await page.screenshot({ path: path.join(__dirname, '../scratch/modal_open_drawer.png') });
    console.log('Captured modal_open_drawer.png');

    // Close Open Drawer
    await page.locator('#btnCancelOpenDrawer').click();
    await page.waitForTimeout(300);

    // 6. Open Cashier Actions -> Void Receipt
    await btnActions.click();
    await page.waitForTimeout(300);
    await page.locator('#btnActionVoidReceipt').click();
    await page.waitForTimeout(1000);
    await page.screenshot({ path: path.join(__dirname, '../scratch/modal_void_receipt.png') });
    console.log('Captured modal_void_receipt.png');

    // Close Void Receipt
    await page.locator('#btnCancelVoidModal').click();
    await page.waitForTimeout(300);

    // 7. Open Cashier Actions -> Reprint Receipt
    await btnActions.click();
    await page.waitForTimeout(300);
    await page.locator('#btnActionReprintReceipt').click();
    await page.waitForTimeout(1000);
    await page.screenshot({ path: path.join(__dirname, '../scratch/modal_reprint_receipt.png') });
    console.log('Captured modal_reprint_receipt.png');

    // 8. Open Cashier Actions -> Edit Initial Cash
    await page.locator('#btnDismissReprintModal').click();
    await page.waitForTimeout(300);
    await btnActions.click();
    await page.waitForTimeout(300);
    await page.locator('#btnActionEditFloat').click();
    await page.waitForTimeout(500);
    await page.screenshot({ path: path.join(__dirname, '../scratch/modal_edit_float.png') });
    console.log('Captured modal_edit_float.png');

    await browser.close();
    console.log('ALL PLAYWRIGHT SCREENSHOTS CAPTURED SUCCESSFULLY!');
})();
