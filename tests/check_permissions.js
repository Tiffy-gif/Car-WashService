const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();

    await page.goto('http://127.0.0.1/Car-WashService/index.php?view=login');
    await page.fill('#username', 'visal');
    await page.fill('#pin', '123456');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);

    await page.goto('http://127.0.0.1/Car-WashService/pos/index.html#pos');
    await page.waitForTimeout(2000);

    const accessInfo = await page.evaluate(() => {
        return {
            currentUser: PosApp.currentUser,
            canAccess: PosApp.canAccessCustomerList(),
            hasPosAccess: PosApp.hasPermission('pos.access'),
            hasCustList: PosApp.hasPermission('customers.list'),
            hasCustView: PosApp.hasPermission('customers.view')
        };
    });

    console.log('Access Info:', JSON.stringify(accessInfo, null, 2));

    // Try opening modal directly
    await page.evaluate(() => {
        PosApp.openCustomerSelectModal();
    });

    const isModalOpen = await page.evaluate(() => {
        const modal = document.getElementById('modalSelectCustomer');
        return {
            classes: modal?.className,
            display: window.getComputedStyle(modal).display
        };
    });

    console.log('Modal after open:', isModalOpen);

    await browser.close();
})();
