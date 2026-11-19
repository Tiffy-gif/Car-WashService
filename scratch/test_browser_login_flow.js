const { chromium } = require('playwright');
const path = require('path');

(async () => {
    console.log('Launching Chrome...');
    const browser = await chromium.launch({
        headless: true,
        executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'
    });
    const context = await browser.newContext({
        viewport: { width: 1366, height: 768 }
    });
    const page = await context.newPage();

    console.log('Navigating to login page...');
    await page.goto('http://localhost/Car-WashService/index.php?view=login', { waitUntil: 'networkidle' });

    // Fill in visal login
    console.log('Logging in as visal...');
    await page.locator('input[name="username"], #username').fill('visal');
    await page.locator('input[name="pin"], input[name="password"], #pin, #password').fill('123456');
    await page.locator('button[type="submit"], #btnLoginSubmit').click();
    await page.waitForTimeout(2000);

    console.log('Current URL after login:', page.url());

    // Navigate to POS
    await page.goto('http://localhost/Car-WashService/pos/index.html#pos', { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);

    // Check if Cashier Actions button is visible
    const btnActions = page.locator('#btnOpenCashierActions');
    const isVisible = await btnActions.isVisible();
    console.log('#btnOpenCashierActions isVisible:', isVisible);

    // Capture main POS header
    await page.screenshot({ path: path.join(__dirname, '../scratch/pos_header_button.png') });
    console.log('1. Captured pos_header_button.png');

    // Click Cashier Actions
    await btnActions.click();
    await page.waitForTimeout(600);
    await page.screenshot({ path: path.join(__dirname, '../scratch/pos_cashier_actions_popup.png') });
    console.log('2. Captured pos_cashier_actions_popup.png');

    // Click Cash In
    await page.locator('#btnActionCashIn').click();
    await page.waitForTimeout(400);
    await page.screenshot({ path: path.join(__dirname, '../scratch/modal_cash_in.png') });
    console.log('3. Captured modal_cash_in.png');

    // Close Cash Movement modal
    await page.locator('#btnCancelCashMovement').click();
    await page.waitForTimeout(300);

    // Open Cashier Actions -> Click Cash Out
    await btnActions.click();
    await page.waitForTimeout(300);
    await page.locator('#btnActionCashOut').click();
    await page.waitForTimeout(300);
    await page.locator('#cashMovementUsdInput').fill('25');
    await page.waitForTimeout(400);
    await page.screenshot({ path: path.join(__dirname, '../scratch/modal_cash_out_manager_auth.png') });
    console.log('4. Captured modal_cash_out_manager_auth.png');

    // Close Cash Movement modal
    await page.locator('#btnCancelCashMovement').click();
    await page.waitForTimeout(300);

    // Open Cashier Actions -> Open Drawer
    await btnActions.click();
    await page.waitForTimeout(300);
    await page.locator('#btnActionOpenDrawer').click();
    await page.waitForTimeout(300);
    await page.screenshot({ path: path.join(__dirname, '../scratch/modal_open_drawer.png') });
    console.log('5. Captured modal_open_drawer.png');

    // Close Open Drawer
    await page.locator('#btnCancelOpenDrawer').click();
    await page.waitForTimeout(300);

    // Open Cashier Actions -> Void Receipt
    await btnActions.click();
    await page.waitForTimeout(300);
    await page.locator('#btnActionVoidReceipt').click();
    await page.waitForTimeout(1000);
    await page.screenshot({ path: path.join(__dirname, '../scratch/modal_void_receipt.png') });
    console.log('6. Captured modal_void_receipt.png');

    // Close Void Receipt
    await page.locator('#btnCancelVoidModal').click();
    await page.waitForTimeout(300);

    // Open Cashier Actions -> Reprint Receipt
    await btnActions.click();
    await page.waitForTimeout(300);
    await page.locator('#btnActionReprintReceipt').click();
    await page.waitForTimeout(1000);
    await page.screenshot({ path: path.join(__dirname, '../scratch/modal_reprint_receipt.png') });
    console.log('7. Captured modal_reprint_receipt.png');

    // Close Reprint Receipt
    await page.locator('#btnDismissReprintModal').click();
    await page.waitForTimeout(300);

    // Open Cashier Actions -> Edit Float
    await btnActions.click();
    await page.waitForTimeout(300);
    await page.locator('#btnActionEditFloat').click();
    await page.waitForTimeout(500);
    await page.screenshot({ path: path.join(__dirname, '../scratch/modal_edit_float.png') });
    console.log('8. Captured modal_edit_float.png');

    await browser.close();
    console.log('SUCCESS: All 8 screenshots captured successfully!');
})();
