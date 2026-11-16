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

    const result = await page.evaluate(async () => {
        let custData = PosStorage.getCustomers();
        let apiData = null;
        try {
            apiData = await PosApi.syncCustomers();
        } catch (e) {
            apiData = { error: e.message };
        }
        const updatedCustData = PosStorage.getCustomers();
        return {
            initialCustomersCount: custData?.customers?.length || 0,
            apiDataSuccess: apiData?.success,
            apiCustomersCount: apiData?.customers?.length || 0,
            apiVehiclesCount: apiData?.vehicles?.length || 0,
            updatedCustomersCount: updatedCustData?.customers?.length || 0,
            sampleCustomer: updatedCustData?.customers?.[0]
        };
    });

    console.log('Result:', JSON.stringify(result, null, 2));
    await browser.close();
})();
